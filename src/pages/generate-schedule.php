<?php
$host = 'db';
$dbname = 'ap_scheduler';
$user = 'apuser';
$password = 'appassword';

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$success_message = '';
$error_message = '';
$warnings = [];
$unassigned_teachers = [];
$multi_assigned_teachers = [];
$school_year = date('Y') . '-' . (date('Y') + 1);

// ------------------------------------------------
// Helper: Get all testing dates (weekdays only)
// ------------------------------------------------
function getTestingDates($period_start, $period_end)
{
  $dates = [];
  $current = strtotime($period_start);
  $end = strtotime($period_end);

  while ($current <= $end) {
    $day_of_week = date('N', $current);
    if ($day_of_week < 6) {
      $dates[] = date('Y-m-d', $current);
    }
    $current = strtotime('+1 day', $current);
  }
  return $dates;
}

// ------------------------------------------------
// Helper: Resolve actual room for a test session
// Checks overrides first, then auto-assigns
// based on priority order if conflict exists
// ------------------------------------------------
function resolveSessionRoom(
  $test_name,
  $test_date,
  $test_time,
  $default_location,
  $used_locations,
  $conn
) {

  // Check for coordinator override first
  $test_name_escaped = $conn->real_escape_string($test_name);
  $test_date_escaped = $conn->real_escape_string($test_date);
  $test_time_escaped = $conn->real_escape_string($test_time);

  $override = $conn->query("
        SELECT main_location FROM test_room_overrides
        WHERE test_name = '$test_name_escaped'
        AND session_date = '$test_date_escaped'
        AND session_time = '$test_time_escaped'
        LIMIT 1
    ")->fetch_assoc();

  if ($override) {
    return $override['main_location'];
  }

  // No override — use default if not already taken
  if (!in_array($default_location, $used_locations)) {
    return $default_location;
  }

  // Default is taken — use priority list
  $priority_rooms = [
    'Media Center',
    '4-116',
    '2-108E',
    '2-108G',
    'Gym with Curtain'
  ];

  foreach ($priority_rooms as $room) {
    if (!in_array($room, $used_locations)) {
      return $room;
    }
  }

  // Fallback — should never happen with enough rooms
  return $default_location . ' (overflow)';
}

// ------------------------------------------------
// Handle POST actions
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

  // ----------------------------------------
  // Generate Schedule
  // ----------------------------------------
  if ($_POST['action'] === 'generate_schedule') {

    $conn->query("DELETE FROM proctor_assignments WHERE school_year = '$school_year'");

    $period = $conn->query("
            SELECT * FROM testing_period 
            WHERE school_year = '$school_year' 
            LIMIT 1
        ")->fetch_assoc();

    if (!$period) {
      $error_message = "Please set up the testing period first.";
    } else {
      $period_start = $period['period_start'];
      $period_end = $period['period_end'];
      $testing_dates = getTestingDates($period_start, $period_end);

      $first_4_days = array_slice($testing_dates, 0, 4);
      $last_4_days = array_slice($testing_dates, -4, 4);

      $tests_result = $conn->query("
                SELECT * FROM ap_tests 
                WHERE test_date IS NOT NULL 
                AND test_time IS NOT NULL
                ORDER BY test_date ASC, 
                CASE test_time WHEN '8AM' THEN 1 WHEN '12PM' THEN 2 END ASC
            ");
      $tests = $tests_result->fetch_all(MYSQLI_ASSOC);

      $teachers_result = $conn->query("
                SELECT t.*, a.test_date as their_test_date, 
                       a.test_time as their_test_time,
                       a.is_last_3_days, a.testing_day_number
                FROM ap_teachers t
                JOIN ap_tests a ON t.test_name = a.test_name
                WHERE t.active = 1
                ORDER BY t.teacher_name ASC
            ");
      $teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);

      $counts_result = $conn->query("
                SELECT course_enrolled, COUNT(*) as student_count
                FROM students
                WHERE school_year = '$school_year'
                GROUP BY course_enrolled
            ");
      $student_counts = [];
      while ($row = $counts_result->fetch_assoc()) {
        $student_counts[$row['course_enrolled']] = $row['student_count'];
      }

      $assignments = [];
      $teacher_assignment_counts = [];
      $teacher_assigned_dates = [];
      $unassigned_sessions = [];

      foreach ($teachers as $teacher) {
        $teacher_assignment_counts[$teacher['teacher_name']] = 0;
        $teacher_assigned_dates[$teacher['teacher_name']] = [];
      }

      // ----------------------------------------
      // Track rooms used per date+time slot
      // ----------------------------------------
      $slot_rooms_used = []; // ['2027-05-07_12PM' => ['Gym', 'Media Center']]

      // ----------------------------------------
      // PASS 1: Fill first 4 days with
      // last 4 days teachers ONLY
      // ----------------------------------------
      foreach ($tests as $test) {
        $test_name = $test['test_name'];
        $test_date = $test['test_date'];
        $test_time = $test['test_time'];

        if (!in_array($test_date, $first_4_days)) continue;

        $slot_key = $test_date . '_' . $test_time;
        if (!isset($slot_rooms_used[$slot_key])) {
          $slot_rooms_used[$slot_key] = [];
        }

        // Resolve actual room for this session
        $resolved_room = resolveSessionRoom(
          $test_name,
          $test_date,
          $test_time,
          $test['main_location'],
          $slot_rooms_used[$slot_key],
          $conn
        );

        // Track this room as used for this slot
        $slot_rooms_used[$slot_key][] = $resolved_room;

        $student_count = $student_counts[$test_name] ?? 0;
        $proctors_needed = $student_count > 100 ? 2 : 1;
        // $proctors_needed = 2; // Temporarily set to 2 for testing purposes, adjust as needed
        $assigned_count = 0;

        $eligible_teachers = [];

        foreach ($teachers as $teacher) {
          $teacher_name = $teacher['teacher_name'];
          $is_last_4_days = $teacher['is_last_3_days'];

          if (!$is_last_4_days) continue;
          if ($teacher['test_name'] === $test_name) continue;
          if (in_array($test_date, $teacher_assigned_dates[$teacher_name])) continue;

          $eligible_teachers[] = [
            'teacher' => $teacher,
            'assignment_count' => $teacher_assignment_counts[$teacher_name]
          ];
        }

        usort($eligible_teachers, function ($a, $b) {
          return $a['assignment_count'] - $b['assignment_count'];
        });

        foreach ($eligible_teachers as $eligible) {
          if ($assigned_count >= $proctors_needed) break;

          $teacher = $eligible['teacher'];
          $teacher_name = $teacher['teacher_name'];

          $teacher_name_escaped = $conn->real_escape_string($teacher_name);
          $test_name_escaped = $conn->real_escape_string($test_name);
          $test_date_escaped = $conn->real_escape_string($test_date);
          $resolved_room_escaped = $conn->real_escape_string($resolved_room);
          $day_number = $test['testing_day_number'];

          $sql = "INSERT INTO proctor_assignments 
            (teacher_name, test_name, test_date, testing_day_number, 
             location, school_year)
            VALUES 
            ('$teacher_name_escaped', '$test_name_escaped', 
             '$test_date_escaped', '$day_number',
             '$resolved_room_escaped', '$school_year')";

          if ($conn->query($sql)) {
            $assignments[] = [
              'teacher_name' => $teacher_name,
              'test_name' => $test_name,
              'test_date' => $test_date,
              'test_time' => $test_time,
              'location' => $resolved_room
            ];
            $teacher_assignment_counts[$teacher_name]++;
            $teacher_assigned_dates[$teacher_name][] = $test_date;
            $assigned_count++;
          }
        }

        if ($assigned_count < $proctors_needed) {
          $unassigned_sessions[] = [
            'test_name' => $test_name,
            'test_date' => $test_date,
            'test_time' => $test_time,
            'needed' => $proctors_needed,
            'assigned' => $assigned_count
          ];
        }
      }
      //end passs 1

      // ----------------------------------------
      // PASS 2: Fill ALL remaining sessions
      // with any eligible teacher
      // ----------------------------------------
      foreach ($tests as $test) {
        $test_name = $test['test_name'];
        $test_date = $test['test_date'];
        $test_time = $test['test_time'];
        $student_count = $student_counts[$test_name] ?? 0;
        $proctors_needed = $student_count > 100 ? 2 : 1;
        // $proctors_needed = 2; // Temporarily set to 2 for testing purposes, adjust as needed

        $already_assigned = count(array_filter(
          $assignments,
          function ($a) use ($test_name, $test_date) {
            return $a['test_name'] === $test_name &&
              $a['test_date'] === $test_date;
          }
        ));

        if ($already_assigned >= $proctors_needed) continue;

        $slot_key = $test_date . '_' . $test_time;
        if (!isset($slot_rooms_used[$slot_key])) {
          $slot_rooms_used[$slot_key] = [];
        }

        // Resolve room — only if not already resolved in Pass 1
        $already_resolved = false;
        $resolved_room = ''; // initialize to prevent undefined variable
        foreach ($assignments as $a) {
          if ($a['test_name'] === $test_name && $a['test_date'] === $test_date) {
            $resolved_room = $a['location'];
            $already_resolved = true;
            break;
          }
        }

        if (!$already_resolved) {
          $resolved_room = resolveSessionRoom(
            $test_name,
            $test_date,
            $test_time,
            $test['main_location'],
            $slot_rooms_used[$slot_key],
            $conn
          );
          $slot_rooms_used[$slot_key][] = $resolved_room;
        }

        $assigned_count = $already_assigned;
        $eligible_teachers = [];

        foreach ($teachers as $teacher) {
          $teacher_name = $teacher['teacher_name'];
          $their_test_date = $teacher['their_test_date'];
          $is_last_4_days = $teacher['is_last_3_days'];

          if ($teacher['test_name'] === $test_name) continue;
          if (in_array($test_date, $teacher_assigned_dates[$teacher_name])) continue;

          $eligible = false;

          if ($is_last_4_days) {
            if (in_array($test_date, $first_4_days)) {
              $eligible = true;
            }
          } else {
            // All others proctor after their own test date
            // Also exclude same day entirely regardless of time
            if ($test_date > $their_test_date) {
              $eligible = true;
            }
          }

          if (!$eligible) continue;

          $eligible_teachers[] = [
            'teacher' => $teacher,
            'assignment_count' => $teacher_assignment_counts[$teacher_name]
          ];
        }

        usort($eligible_teachers, function ($a, $b) {
          return $a['assignment_count'] - $b['assignment_count'];
        });

        foreach ($eligible_teachers as $eligible) {
          if ($assigned_count >= $proctors_needed) break;

          $teacher = $eligible['teacher'];
          $teacher_name = $teacher['teacher_name'];

          $teacher_name_escaped = $conn->real_escape_string($teacher_name);
          $test_name_escaped = $conn->real_escape_string($test_name);
          $test_date_escaped = $conn->real_escape_string($test_date);
          $resolved_room_escaped = $conn->real_escape_string($resolved_room);
          $day_number = $test['testing_day_number'];

          $sql = "INSERT INTO proctor_assignments 
            (teacher_name, test_name, test_date, testing_day_number, 
             location, school_year)
            VALUES 
            ('$teacher_name_escaped', '$test_name_escaped', 
             '$test_date_escaped', '$day_number',
             '$resolved_room_escaped', '$school_year')";

          if ($conn->query($sql)) {
            $assignments[] = [
              'teacher_name' => $teacher_name,
              'test_name' => $test_name,
              'test_date' => $test_date,
              'test_time' => $test_time,
              'location' => $resolved_room
            ];
            $teacher_assignment_counts[$teacher_name]++;
            $teacher_assigned_dates[$teacher_name][] = $test_date;
            $assigned_count++;
          }
        }

        $existing_unassigned = array_filter(
          $unassigned_sessions,
          function ($s) use ($test_name, $test_date) {
            return $s['test_name'] === $test_name &&
              $s['test_date'] === $test_date;
          }
        );

        if ($assigned_count < $proctors_needed && empty($existing_unassigned)) {
          $unassigned_sessions[] = [
            'test_name' => $test_name,
            'test_date' => $test_date,
            'test_time' => $test_time,
            'needed' => $proctors_needed,
            'assigned' => $assigned_count
          ];
        }
      }
      //end pass 2

      // ----------------------------------------
      // PASS 3: Last resort for unassigned
      // last-4-days teachers
      // ----------------------------------------
      foreach ($teachers as $teacher) {
        $teacher_name = $teacher['teacher_name'];
        $is_last_4_days = $teacher['is_last_3_days'];
        $their_test_date = $teacher['their_test_date'];

        if (!$is_last_4_days) continue;
        if ($teacher_assignment_counts[$teacher_name] > 0) continue;

        foreach ($tests as $test) {
          $test_name = $test['test_name'];
          $test_date = $test['test_date'];
          $test_time = $test['test_time'];
          $student_count = $student_counts[$test_name] ?? 0;
          $proctors_needed = $student_count > 100 ? 2 : 1;
          // $proctors_needed = 2; // Temporarily set to 2 for testing purposes, adjust as needed

          if ($teacher['test_name'] === $test_name) continue;
          if ($test_date >= $their_test_date) continue;
          if (in_array($test_date, $teacher_assigned_dates[$teacher_name])) continue;

          // Check if session needs more proctors
          $session_assigned = count(array_filter(
            $assignments,
            function ($a) use ($test_name, $test_date) {
              return $a['test_name'] === $test_name &&
                $a['test_date'] === $test_date;
            }
          ));

          if ($session_assigned >= $proctors_needed) continue;

          // Get resolved room for this session
          $resolved_room = '';
          foreach ($assignments as $a) {
            if (
              $a['test_name'] === $test_name &&
              $a['test_date'] === $test_date
            ) {
              $resolved_room = $a['location'];
              break;
            }
          }

          if (empty($resolved_room)) {
            $slot_key = $test_date . '_' . $test_time;
            if (!isset($slot_rooms_used[$slot_key])) {
              $slot_rooms_used[$slot_key] = [];
            }
            $resolved_room = resolveSessionRoom(
              $test_name,
              $test_date,
              $test_time,
              $test['main_location'],
              $slot_rooms_used[$slot_key],
              $conn
            );
            $slot_rooms_used[$slot_key][] = $resolved_room;
          }

          $teacher_name_escaped = $conn->real_escape_string($teacher_name);
          $test_name_escaped = $conn->real_escape_string($test_name);
          $test_date_escaped = $conn->real_escape_string($test_date);
          $resolved_room_escaped = $conn->real_escape_string($resolved_room);
          $day_number = $test['testing_day_number'];

          $sql = "INSERT INTO proctor_assignments 
            (teacher_name, test_name, test_date, testing_day_number, 
             location, school_year)
            VALUES 
            ('$teacher_name_escaped', '$test_name_escaped', 
             '$test_date_escaped', '$day_number',
             '$resolved_room_escaped', '$school_year')";

          if ($conn->query($sql)) {
            $assignments[] = [
              'teacher_name' => $teacher_name,
              'test_name' => $test_name,
              'test_date' => $test_date,
              'test_time' => $test_time,
              'location' => $resolved_room
            ];
            $teacher_assignment_counts[$teacher_name]++;
            $teacher_assigned_dates[$teacher_name][] = $test_date;
            break;
          }
        }
      }
      //end of pass 3

      // Flag teachers with zero assignments
      foreach ($teacher_assignment_counts as $teacher_name => $count) {
        if ($count === 0) {
          $unassigned_teachers[] = $teacher_name;
        }
      }

      // Flag teachers assigned more than once
      foreach ($teacher_assignment_counts as $teacher_name => $count) {
        if ($count > 1) {
          $multi_assigned_teachers[] = [
            'teacher_name' => $teacher_name,
            'count' => $count
          ];
        }
      }

      $assigned_total = count($assignments);
      if ($assigned_total > 0) {
        $success_message = "Schedule generated! $assigned_total proctor assignment(s) created.";
      }

      if (!empty($unassigned_sessions)) {
        $warnings[] = "⚠️ " . count($unassigned_sessions) .
          " session(s) could not be fully staffed.";
      }

      if (!empty($multi_assigned_teachers)) {
        $warnings[] = "⚠️ " . count($multi_assigned_teachers) .
          " teacher(s) assigned more than once.";
      }

      if (!empty($unassigned_teachers)) {
        $warnings[] = "⚠️ " . count($unassigned_teachers) .
          " teacher(s) could not be assigned to any session.";
      }
    }
  }

  // ----------------------------------------
  // Download by Date CSV
  // ----------------------------------------
  if ($_POST['action'] === 'download_by_date') {
    $result = $conn->query("
            SELECT test_date, test_name, testing_day_number,
                   GROUP_CONCAT(teacher_name ORDER BY teacher_name SEPARATOR ', ') 
                   as proctors, location
            FROM proctor_assignments
            WHERE school_year = '$school_year'
            GROUP BY test_date, test_name, testing_day_number, location
            ORDER BY test_date ASC, test_name ASC
        ");

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="proctor_schedule_by_date_' .
      $school_year . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Day #', 'AP Test', 'Proctor(s)', 'Location']);

    while ($row = $result->fetch_assoc()) {
      fputcsv($output, [
        date('M j, Y', strtotime($row['test_date'])),
        'Day ' . $row['testing_day_number'],
        $row['test_name'],
        $row['proctors'],
        $row['location']
      ]);
    }
    fclose($output);
    exit;
  }

  // ----------------------------------------
  // Download by Teacher CSV
  // ----------------------------------------
  if ($_POST['action'] === 'download_by_teacher') {
    $result = $conn->query("
            SELECT teacher_name,
                   GROUP_CONCAT(
                       CONCAT(test_name, ' (', test_date, ')') 
                       ORDER BY test_date SEPARATOR '; '
                   ) as assignments,
                   COUNT(*) as total_assignments
            FROM proctor_assignments
            WHERE school_year = '$school_year'
            GROUP BY teacher_name
            ORDER BY teacher_name ASC
        ");

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="proctor_schedule_by_teacher_' .
      $school_year . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Teacher', 'Assignments', 'Total Assignments']);

    while ($row = $result->fetch_assoc()) {
      fputcsv($output, [
        $row['teacher_name'],
        $row['assignments'],
        $row['total_assignments']
      ]);
    }
    fclose($output);
    exit;
  }

  // ----------------------------------------
  // Clear Schedule
  // ----------------------------------------
  if ($_POST['action'] === 'clear_schedule') {
    $conn->query("DELETE FROM proctor_assignments WHERE school_year = '$school_year'");
    $success_message = "Schedule cleared successfully.";
  }
}

// ------------------------------------------------
// Fetch existing schedule for display
// ------------------------------------------------
$schedule_by_date = $conn->query("
    SELECT p.test_date, a.test_time, p.test_name, p.testing_day_number, p.location,
           GROUP_CONCAT(p.teacher_name ORDER BY p.teacher_name SEPARATOR ', ') as proctors,
           COUNT(p.id) as proctor_count,
           (SELECT COUNT(*) FROM students s 
            WHERE s.course_enrolled = p.test_name 
            AND s.school_year = '$school_year') as student_count
    FROM proctor_assignments p
    JOIN ap_tests a ON p.test_name = a.test_name
    WHERE p.school_year = '$school_year'
    GROUP BY p.test_date, a.test_time, p.test_name, p.testing_day_number, p.location
    ORDER BY p.test_date ASC, 
    CASE a.test_time WHEN '8AM' THEN 1 WHEN '12PM' THEN 2 END ASC,
    p.test_name ASC
")->fetch_all(MYSQLI_ASSOC);

$schedule_by_teacher = $conn->query("
    SELECT teacher_name,
           GROUP_CONCAT(
               CONCAT(test_name, ' on ', DATE_FORMAT(test_date, '%b %e')) 
               ORDER BY test_date SEPARATOR '; '
           ) as assignments,
           COUNT(*) as total_assignments
    FROM proctor_assignments
    WHERE school_year = '$school_year'
    GROUP BY teacher_name
    ORDER BY teacher_name ASC
")->fetch_all(MYSQLI_ASSOC);

$multi_proctor_teachers = $conn->query("
    SELECT teacher_name, COUNT(*) as assignment_count
    FROM proctor_assignments
    WHERE school_year = '$school_year'
    GROUP BY teacher_name
    HAVING COUNT(*) > 1
    ORDER BY COUNT(*) DESC
")->fetch_all(MYSQLI_ASSOC);

$unassigned_check = $conn->query("
    SELECT a.test_name, a.test_date, a.test_time,
           COUNT(p.id) as assigned_count
    FROM ap_tests a
    LEFT JOIN proctor_assignments p ON a.test_name = p.test_name 
        AND p.school_year = '$school_year'
    WHERE a.test_date IS NOT NULL
    GROUP BY a.test_name, a.test_date, a.test_time
    HAVING assigned_count = 0
    ORDER BY a.test_date ASC
")->fetch_all(MYSQLI_ASSOC);

// Fetch teachers with no assignments for persistent display
$no_assignment_teachers = $conn->query("
    SELECT t.teacher_name, t.test_name
    FROM ap_teachers t
    WHERE t.active = 1
    AND t.teacher_name NOT IN (
        SELECT DISTINCT teacher_name 
        FROM proctor_assignments 
        WHERE school_year = '$school_year'
    )
    ORDER BY t.teacher_name ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Generate Proctor Schedule | Auto AP Test Scheduler</title>
  <link rel="stylesheet" href="../css/styles.css" />
  <link rel="stylesheet" href="../css/pages.css" />
</head>

<body>

  <header>
    <div class="header-inner">
      <div class="header-title">
        <h1>Auto AP Test Scheduler</h1>
        <p>Viera High School — AP Testing Coordinator Portal</p>
      </div>
      <a href="../index.php" class="back-btn">← Back to Dashboard</a>
    </div>
  </header>

  <main>
    <div class="page-container">

      <h2 class="page-title">🗓️ Generate Proctor Schedule</h2>
      <p class="page-subtitle">
        Automatically assign AP teachers as proctors based on scheduling rules.
        Review the generated schedule and download as CSV for Google Sheets.
      </p>

      <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
      <?php endif; ?>

      <?php if ($error_message): ?>
        <div class="alert alert-error"><?php echo $error_message; ?></div>
      <?php endif; ?>

      <?php if (!empty($warnings)): ?>
        <?php foreach ($warnings as $warning): ?>
          <div class="alert alert-warning"><?php echo $warning; ?></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- ----------------------------------------
           Schedule Controls
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">Schedule Controls</h3>
        <div class="action-btns">
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="generate_schedule" />
            <button type="submit" class="btn btn-primary"
              onclick="return confirm('This will replace the existing schedule. Continue?')">
              🗓️ Generate Proctor Schedule
            </button>
          </form>

          <?php if (!empty($schedule_by_date)): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="download_by_date" />
              <button type="submit" class="btn btn-secondary">
                ⬇️ Download by Date (CSV)
              </button>
            </form>

            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="download_by_teacher" />
              <button type="submit" class="btn btn-secondary">
                ⬇️ Download by Teacher (CSV)
              </button>
            </form>

            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="clear_schedule" />
              <button type="submit" class="btn btn-danger"
                onclick="return confirm('Clear the entire schedule?')">
                🗑️ Clear Schedule
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- ----------------------------------------
           Teachers Assigned More Than Once
      ---------------------------------------- -->
      <?php if (!empty($multi_proctor_teachers)): ?>
        <div class="card-section">
          <h3 class="section-title" style="color:#856404">
            ⚠️ Teachers Assigned More Than Once
          </h3>
          <p class="section-subtitle">
            These teachers have been assigned to proctor multiple sessions.
            Review and reassign if needed.
          </p>
          <table class="data-table">
            <thead>
              <tr>
                <th>Teacher</th>
                <th>Total Assignments</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($multi_proctor_teachers as $t): ?>
                <tr>
                  <td><?php echo htmlspecialchars($t['teacher_name']); ?></td>
                  <td>
                    <span class="badge badge-gold">
                      <?php echo $t['assignment_count']; ?> assignments
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- ----------------------------------------
           Teachers With No Assignment
      ---------------------------------------- -->
      <?php if (!empty($no_assignment_teachers)): ?>
        <div class="card-section">
          <h3 class="section-title" style="color:#721c24">
            ❌ Teachers With No Assignment
          </h3>
          <p class="section-subtitle">
            These teachers could not be assigned to any session based on the
            scheduling rules. The coordinator may need to manually assign them.
          </p>
          <table class="data-table">
            <thead>
              <tr>
                <th>Teacher</th>
                <th>Their AP Test</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($no_assignment_teachers as $t): ?>
                <tr>
                  <td><?php echo htmlspecialchars($t['teacher_name']); ?></td>
                  <td><?php echo htmlspecialchars($t['test_name']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- ----------------------------------------
           Unassigned Sessions
      ---------------------------------------- -->
      <?php if (!empty($unassigned_check)): ?>
        <div class="card-section">
          <h3 class="section-title" style="color:#721c24">
            ❌ Unassigned Sessions
          </h3>
          <p class="section-subtitle">
            These sessions could not be assigned an AP teacher proctor.
            The coordinator will need to assign manually.
          </p>
          <table class="data-table">
            <thead>
              <tr>
                <th>AP Test</th>
                <th>Date</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($unassigned_check as $session): ?>
                <tr>
                  <td><?php echo htmlspecialchars($session['test_name']); ?></td>
                  <td><?php echo date('M j, Y', strtotime($session['test_date'])); ?></td>
                  <td><?php echo $session['test_time']; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- ----------------------------------------
           Schedule by Date
      ---------------------------------------- -->
      <?php if (!empty($schedule_by_date)): ?>
        <div class="card-section">
          <h3 class="section-title">📅 Schedule by Date</h3>
          <table class="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Day #</th>
                <th>Time</th>
                <th>AP Test</th>
                <th>Students</th>
                <th>Proctor(s)</th>
                <th>Location</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $current_date = '';
              foreach ($schedule_by_date as $row):
                $is_new_date = $row['test_date'] !== $current_date;
                $current_date = $row['test_date'];
              ?>
                <tr <?php echo $is_new_date ? 'class="date-separator"' : ''; ?>>
                  <td>
                    <?php echo $is_new_date
                      ? '<strong>' . date('M j, Y', strtotime($row['test_date'])) . '</strong>'
                      : ''; ?>
                  </td>
                  <td>Day <?php echo $row['testing_day_number']; ?></td>
                  <td>
                    <span class="badge <?php echo $row['test_time'] === '8AM'
                                          ? 'badge-green' : 'badge-gold'; ?>">
                      <?php echo $row['test_time']; ?>
                    </span>
                  </td>
                  <td><?php echo htmlspecialchars($row['test_name']); ?></td>
                  <td>
                    <?php if ($row['student_count'] > 0): ?>
                      <span class="badge badge-green"><?php echo $row['student_count']; ?></span>
                      <?php if ($row['student_count'] > 100): ?>
                        <span class="badge badge-gold">2 needed</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="badge badge-gray">No roster</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge badge-green">
                      <?php echo htmlspecialchars($row['proctors']); ?>
                    </span>
                    <?php if ($row['proctor_count'] > 1): ?>
                      <span class="badge badge-gold"><?php echo $row['proctor_count']; ?> proctors</span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo htmlspecialchars($row['location']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- ----------------------------------------
           Schedule by Teacher
      ---------------------------------------- -->
        <div class="card-section">
          <h3 class="section-title">👩‍🏫 Schedule by Teacher</h3>
          <table class="data-table">
            <thead>
              <tr>
                <th>Teacher</th>
                <th>Proctor Assignments</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($schedule_by_teacher as $row): ?>
                <tr>
                  <td>
                    <strong><?php echo htmlspecialchars($row['teacher_name']); ?></strong>
                  </td>
                  <td><?php echo htmlspecialchars($row['assignments']); ?></td>
                  <td>
                    <span class="badge <?php echo $row['total_assignments'] > 1
                                          ? 'badge-gold' : 'badge-green'; ?>">
                      <?php echo $row['total_assignments']; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    </div>
  </main>

  <footer>
    <p>Viera High School &copy; <?php echo date('Y'); ?> — AP Testing Coordinator Portal</p>
  </footer>

</body>

</html>
<?php $conn->close(); ?>