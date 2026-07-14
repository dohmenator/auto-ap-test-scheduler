<?php
require_once __DIR__ . '/../db.php';

$school_year = get_school_year();

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
             '$test_date_escaped', " . ($day_number !== NULL ? $day_number : 'NULL') . ",
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
             '$test_date_escaped', " . ($day_number !== NULL ? $day_number : 'NULL') . ",
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
          $day_number = !empty($test['testing_day_number']) ? (int)$test['testing_day_number'] : NULL;

          $sql = "INSERT INTO proctor_assignments 
            (teacher_name, test_name, test_date, testing_day_number, 
             location, school_year)
            VALUES 
            ('$teacher_name_escaped', '$test_name_escaped', 
             '$test_date_escaped', " . ($day_number !== NULL ? $day_number : 'NULL') . ",
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

      // ----------------------------------------
      // PASS 4: Auto-assign proctors to
      // accommodations rooms, Guidance,
      // and overflow (Media Center)
      // Priority: unassigned teachers first,
      // then teachers with 2 assignments (flagged)
      // ----------------------------------------

      // Build list of sessions needing accommodations proctors
      $acc_sessions = [];

      foreach ($tests as $test) {
        $test_name = $test['test_name'];
        $test_date = $test['test_date'];
        $acc_location = $test['accommodations_location'];
        $overflow_location = $test['overflow_location'] ?? 'Media Center';

        // Check if this test has students in accommodations room
        $test_name_escaped = $conn->real_escape_string($test_name);

        $acc_count = $conn->query("
        SELECT COUNT(*) as cnt FROM students
        WHERE course_enrolled = '$test_name_escaped'
        AND school_year = '$school_year'
        AND accommodation_type IN ('extended_50', 'other')
    ")->fetch_assoc()['cnt'];

        $guidance_count = $conn->query("
        SELECT COUNT(*) as cnt FROM students
        WHERE course_enrolled = '$test_name_escaped'
        AND school_year = '$school_year'
        AND accommodation_type = 'extended_100'
    ")->fetch_assoc()['cnt'];

        $main_count = $conn->query("
        SELECT COUNT(*) as cnt FROM students
        WHERE course_enrolled = '$test_name_escaped'
        AND school_year = '$school_year'
        AND accommodation_type IN ('none', 'preferential_only')
    ")->fetch_assoc()['cnt'];

        $overflow_count = max(0, $main_count - 175);

        if ($acc_count > 0) {
          $acc_sessions[] = [
            'test_name' => $test_name,
            'test_date' => $test_date,
            'location' => $acc_location,
            'student_count' => $acc_count,
            'type' => 'accommodations'
          ];
        }

        if ($guidance_count > 0) {
          $acc_sessions[] = [
            'test_name' => $test_name,
            'test_date' => $test_date,
            'location' => 'Guidance',
            'student_count' => $guidance_count,
            'type' => 'guidance'
          ];
        }

        if ($overflow_count > 0) {
          $acc_sessions[] = [
            'test_name' => $test_name,
            'test_date' => $test_date,
            'location' => $overflow_location,
            'student_count' => $overflow_count,
            'type' => 'overflow'
          ];
        }
      }

      // Try to assign unassigned teachers first
      foreach ($acc_sessions as $session) {
        $test_name = $session['test_name'];
        $test_date = $session['test_date'];
        $location = $session['location'];

        // Check if already has a proctor
        $test_name_escaped = $conn->real_escape_string($test_name);
        $location_escaped = $conn->real_escape_string($location);
        $test_date_escaped = $conn->real_escape_string($test_date);

        $existing = $conn->query("
        SELECT id FROM proctor_assignments
        WHERE test_name = '$test_name_escaped'
        AND test_date = '$test_date_escaped'
        AND location = '$location_escaped'
        AND school_year = '$school_year'
        LIMIT 1
    ")->fetch_assoc();

        if ($existing) continue;

        // Find an unassigned teacher eligible for this slot
        $assigned = false;
        foreach ($teachers as $teacher) {
          $teacher_name = $teacher['teacher_name'];
          $their_test_date = $teacher['their_test_date'];
          $is_last_4_days = $teacher['is_last_3_days'];

          // Must have 0 assignments
          if ($teacher_assignment_counts[$teacher_name] > 0) continue;

          // Cannot proctor own subject
          if ($teacher['test_name'] === $test_name) continue;

          // Cannot be assigned same day
          if (in_array($test_date, $teacher_assigned_dates[$teacher_name])) continue;

          // Check timing eligibility
          $eligible = false;
          if ($is_last_4_days) {
            if (in_array($test_date, $first_4_days)) {
              $eligible = true;
            }
          } else {
            if ($test_date > $their_test_date) {
              $eligible = true;
            }
          }

          if (!$eligible) continue;

          // Assign this teacher
          $teacher_name_escaped = $conn->real_escape_string($teacher_name);
          $day_number = null;
          foreach ($tests as $t) {
            if ($t['test_name'] === $test_name) {
              $day_number = $t['testing_day_number'];
              break;
            }
          }

          $sql = "INSERT INTO proctor_assignments
            (teacher_name, test_name, test_date, testing_day_number,
             location, school_year)
            VALUES
            ('$teacher_name_escaped', '$test_name_escaped',
             '$test_date_escaped', " . ($day_number !== NULL ? $day_number : 'NULL') . ",
             '$location_escaped', '$school_year')";

          if ($conn->query($sql)) {
            $teacher_assignment_counts[$teacher_name]++;
            $teacher_assigned_dates[$teacher_name][] = $test_date;
            $assigned = true;
            break;
          }
        }
      } //end pass 4


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


  // ----------------------------------------
  // Override / Assign Proctor
  // ----------------------------------------
  if ($_POST['action'] === 'override_proctor') {
    $test_name = $conn->real_escape_string($_POST['test_name']);
    $test_date = $conn->real_escape_string($_POST['test_date']);
    $new_teacher = $conn->real_escape_string($_POST['new_teacher']);
    $override_type = $_POST['override_type'] ?? 'replace';

    // Get testing day number for this test
    $day_result = $conn->query("
        SELECT testing_day_number, main_location 
        FROM ap_tests 
        WHERE test_name = '$test_name' 
        LIMIT 1
    ")->fetch_assoc();

    $day_number = $day_result['testing_day_number'] ?? null;
    $location = $day_result['main_location'] ?? 'Gym';

    // Use override_location if provided (for accommodations/guidance/overflow)
    if (!empty($_POST['override_location'])) {
      $location = $conn->real_escape_string($_POST['override_location']);
    } else {
      // Get current location from existing assignment
      $current = $conn->query("
        SELECT location FROM proctor_assignments
        WHERE test_name = '$test_name'
        AND test_date = '$test_date'
        AND school_year = '$school_year'
        LIMIT 1
    ")->fetch_assoc();

      if ($current) {
        $location = $current['location'];
      }
    }

    if ($override_type === 'replace') {
      if (!empty($_POST['override_location'])) {
        // Only remove assignment for this specific location
        $conn->query("
            DELETE FROM proctor_assignments
            WHERE test_name = '$test_name'
            AND test_date = '$test_date'
            AND school_year = '$school_year'
            AND location = '$location'
        ");
      } else {
        // Remove all main location assignments for this test/date
        $conn->query("
            DELETE FROM proctor_assignments
            WHERE test_name = '$test_name'
            AND test_date = '$test_date'
            AND school_year = '$school_year'
            AND location = '$location'
        ");
      }
    }

    // Insert new assignment
    // Check for duplicate before inserting
    $dup_check = $conn->query("
    SELECT id FROM proctor_assignments
    WHERE teacher_name = '$new_teacher'
    AND test_name = '$test_name'
    AND test_date = '$test_date'
    AND school_year = '$school_year'
    LIMIT 1
");

    if ($dup_check->num_rows > 0) {
      $error_message = "This teacher is already assigned to this session.";
    } else {
      $location_escaped = $conn->real_escape_string($location);
      $sql = "INSERT INTO proctor_assignments
                (teacher_name, test_name, test_date, testing_day_number,
                 location, school_year)
            VALUES
                ('$new_teacher', '$test_name', '$test_date', '$day_number',
                 '$location_escaped', '$school_year')";

      if ($conn->query($sql)) {
        $success_message = "Proctor assignment updated successfully!";
      } else {
        $error_message = "Error updating assignment: " . $conn->error;
      }
    }
  }

  // ----------------------------------------
  // Remove a specific proctor assignment
  // ----------------------------------------
  if ($_POST['action'] === 'remove_proctor') {
    $assignment_id = (int)$_POST['assignment_id'];
    if ($conn->query("DELETE FROM proctor_assignments WHERE id = $assignment_id")) {
      $success_message = "Proctor removed successfully.";
    } else {
      $error_message = "Error removing proctor.";
    }
  }
}


//*******************  FETCH QUERIES *********************************/
// ------------------------------------------------
// Fetch existing schedule for display
// ------------------------------------------------
// ------------------------------------------------
// Fetch schedule grouped by test then location
// ------------------------------------------------

// First get all tests with their main session info
$all_tests_result = $conn->query("
    SELECT 
        a.test_date,
        a.test_time,
        a.test_name,
        a.testing_day_number,
        a.main_location,
        a.accommodations_location,
        a.overflow_location,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = a.test_name 
         AND s.school_year = '$school_year') as total_students,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = a.test_name 
         AND s.school_year = '$school_year'
         AND s.accommodation_type IN ('none','preferential_only')) as main_students,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = a.test_name 
         AND s.school_year = '$school_year'
         AND s.accommodation_type IN ('extended_50','other')) as acc_students,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = a.test_name 
         AND s.school_year = '$school_year'
         AND s.accommodation_type = 'extended_100') as guidance_students
    FROM ap_tests a
    WHERE a.test_date IS NOT NULL
    AND a.test_time IS NOT NULL
    ORDER BY a.test_date ASC,
    CASE a.test_time WHEN '8AM' THEN 1 WHEN '12PM' THEN 2 END ASC,
    a.test_name ASC
")->fetch_all(MYSQLI_ASSOC);

// For each test build location rows with proctor info
$schedule_by_date = [];
foreach ($all_tests_result as $test) {
  $test_name = $conn->real_escape_string($test['test_name']);
  $main_students = (int)$test['main_students'];
  $overflow_count = max(0, $main_students - 175);
  $main_count = min($main_students, 175);

  // Fetch all proctor assignments for this test
  $proctors_result = $conn->query("
        SELECT teacher_name, location
        FROM proctor_assignments
        WHERE test_name = '$test_name'
        AND school_year = '$school_year'
        ORDER BY id ASC
    ");
  $proctors_by_location = [];
  while ($p = $proctors_result->fetch_assoc()) {
    $loc = $p['location'];
    if (!isset($proctors_by_location[$loc])) {
      $proctors_by_location[$loc] = [];
    }
    $proctors_by_location[$loc][] = $p['teacher_name'];
  }

  $locations = [];

  // Main location row
  if ($test['total_students'] > 0 || true) {
    $main_proctor = isset($proctors_by_location[$test['main_location']])
      ? implode(', ', $proctors_by_location[$test['main_location']])
      : null;
    $locations[] = [
      'location' => $test['main_location'],
      'student_count' => $main_count > 0 ? $main_count : null,
      'proctor' => $main_proctor,
      'type' => 'main'
    ];
  }

  // Overflow row
  if ($overflow_count > 0) {
    $overflow_loc = $test['overflow_location'] ?? 'Media Center';
    $overflow_proctor = isset($proctors_by_location[$overflow_loc])
      ? implode(', ', $proctors_by_location[$overflow_loc])
      : null;
    $locations[] = [
      'location' => $overflow_loc,
      'student_count' => $overflow_count,
      'proctor' => $overflow_proctor,
      'type' => 'overflow'
    ];
  }

  // Accommodations row
  if ($test['acc_students'] > 0) {
    $acc_loc = $test['accommodations_location'];
    $acc_proctor = isset($proctors_by_location[$acc_loc])
      ? implode(', ', $proctors_by_location[$acc_loc])
      : null;
    $locations[] = [
      'location' => $acc_loc,
      'student_count' => $test['acc_students'],
      'proctor' => $acc_proctor,
      'type' => 'accommodations'
    ];
  }

  // Guidance row
  if ($test['guidance_students'] > 0) {
    $guid_proctor = isset($proctors_by_location['Guidance'])
      ? implode(', ', $proctors_by_location['Guidance'])
      : null;
    $locations[] = [
      'location' => 'Guidance',
      'student_count' => $test['guidance_students'],
      'proctor' => $guid_proctor,
      'type' => 'guidance'
    ];
  }

  $schedule_by_date[] = [
    'test_date' => $test['test_date'],
    'test_time' => $test['test_time'],
    'test_name' => $test['test_name'],
    'testing_day_number' => $test['testing_day_number'],
    'total_students' => $test['total_students'],
    'locations' => $locations
  ];
}



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

// Fetch all active teachers for override dropdowns
// Unassigned teachers first, then everyone else
$all_teachers_result = $conn->query("
    SELECT t.teacher_name, t.test_name,
        COUNT(p.id) as assignment_count,
        CASE WHEN COUNT(p.id) = 0 THEN 0 ELSE 1 END as is_assigned
    FROM ap_teachers t
    LEFT JOIN proctor_assignments p ON t.teacher_name = p.teacher_name
        AND p.school_year = '$school_year'
    WHERE t.active = 1
    GROUP BY t.teacher_name, t.test_name
    ORDER BY assignment_count ASC, t.teacher_name ASC
");
$all_teachers = $all_teachers_result->fetch_all(MYSQLI_ASSOC);


// ------------------------------------------------
// Fetch accommodations/overflow/guidance sessions
// with their proctor status
// ------------------------------------------------
$acc_proctor_sessions = $conn->query("
    SELECT 
        t.test_name,
        t.test_date,
        t.test_time,
        t.accommodations_location,
        t.overflow_location,
        -- Accommodations room student count
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = t.test_name 
         AND s.school_year = '$school_year'
         AND s.accommodation_type IN ('extended_50','other')) as acc_count,
        -- Guidance student count
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = t.test_name 
         AND s.school_year = '$school_year'
         AND s.accommodation_type = 'extended_100') as guidance_count,
        -- Overflow student count
        GREATEST(0, (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = t.test_name 
         AND s.school_year = '$school_year'
         AND s.accommodation_type IN ('none','preferential_only')) - 175) as overflow_count,
        -- Accommodations room proctor
        (SELECT GROUP_CONCAT(p.teacher_name SEPARATOR ', ')
         FROM proctor_assignments p
         WHERE p.test_name = t.test_name
         AND p.school_year = '$school_year'
         AND p.location = t.accommodations_location
         LIMIT 1) as acc_proctor,
        -- Guidance proctor
        (SELECT GROUP_CONCAT(p.teacher_name SEPARATOR ', ')
         FROM proctor_assignments p
         WHERE p.test_name = t.test_name
         AND p.school_year = '$school_year'
         AND p.location = 'Guidance'
         LIMIT 1) as guidance_proctor,
        -- Overflow proctor
        (SELECT GROUP_CONCAT(p.teacher_name SEPARATOR ', ')
         FROM proctor_assignments p
         WHERE p.test_name = t.test_name
         AND p.school_year = '$school_year'
         AND p.location = t.overflow_location
         LIMIT 1) as overflow_proctor
    FROM ap_tests t
    WHERE t.test_date IS NOT NULL
    HAVING acc_count > 0 OR guidance_count > 0 OR overflow_count > 0
    ORDER BY t.test_date ASC, t.test_name ASC
")->fetch_all(MYSQLI_ASSOC);

// Fetch teachers grouped for accommodations dropdown
$acc_teachers_result = $conn->query("
    SELECT t.teacher_name, t.test_name,
        COUNT(p.id) as assignment_count
    FROM ap_teachers t
    LEFT JOIN proctor_assignments p ON t.teacher_name = p.teacher_name
        AND p.school_year = '$school_year'
    WHERE t.active = 1
    GROUP BY t.teacher_name, t.test_name
    ORDER BY assignment_count ASC, t.teacher_name ASC
");
$acc_teachers = $acc_teachers_result->fetch_all(MYSQLI_ASSOC);

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

      <?php if (!empty($schedule_by_date)): ?>

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
                    <td><?php echo h($t['teacher_name']); ?></td>
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
                    <td><?php echo h($t['teacher_name']); ?></td>
                    <td><?php echo h($t['test_name']); ?></td>
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
                    <td><?php echo h($session['test_name']); ?></td>
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
        <div class="card-section">
          <h3 class="section-title">📅 Schedule by Date</h3>
          <table class="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Day #</th>
                <th>Time</th>
                <th>AP Test</th>
                <th>Location</th>
                <th>Students</th>
                <th>Proctor(s)</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php
              $current_date = '';
              foreach ($schedule_by_date as $test):
                $is_new_date = $test['test_date'] !== $current_date;
                $current_date = $test['test_date'];
                $first_location = true;
                $test_row_count = count($test['locations']);
              ?>

                <?php foreach ($test['locations'] as $loc_row):
                  $override_id = 'override_' . md5($test['test_name'] . $test['test_date'] . $loc_row['location']);
                  $is_unassigned = empty($loc_row['proctor']);

                  // Row styling
                  $row_style = '';
                  if ($first_location && $is_new_date) {
                    $row_style = 'border-top: 2px solid #1a5c1a;';
                  } elseif ($first_location) {
                    $row_style = 'border-top: 2px solid #ddd;';
                  }

                  // Location badge color
                  $loc_badge = 'badge-gray';
                  if ($loc_row['type'] === 'overflow') $loc_badge = 'badge-gold';
                  if ($loc_row['type'] === 'guidance') $loc_badge = 'badge-green';
                  if ($loc_row['type'] === 'accommodations') $loc_badge = 'badge-gray';
                ?>

                  <tr style="<?php echo $row_style; ?>">
                    <!-- Date — only on first location row of each test -->
                    <td>
                      <?php if ($first_location && $is_new_date): ?>
                        <strong><?php echo date('M j, Y', strtotime($test['test_date'])); ?></strong>
                      <?php endif; ?>
                    </td>

                    <!-- Day # — only on first location row -->
                    <td>
                      <?php if ($first_location): ?>
                        Day <?php echo $test['testing_day_number']; ?>
                      <?php endif; ?>
                    </td>

                    <!-- Time — only on first location row -->
                    <td>
                      <?php if ($first_location): ?>
                        <span class="badge <?php echo $test['test_time'] === '8AM'
                                              ? 'badge-green' : 'badge-gold'; ?>">
                          <?php echo $test['test_time']; ?>
                        </span>
                      <?php endif; ?>
                    </td>

                    <!-- Test name — only on first location row -->
                    <td>
                      <?php if ($first_location): ?>
                        <strong><?php echo h($test['test_name'] ?? ''); ?></strong>
                        <?php if ($test['total_students'] > 0): ?>
                          <br><small style="color:#888;">
                            <?php echo $test['total_students']; ?> total students
                          </small>
                        <?php else: ?>
                          <br><span class="badge badge-gray" style="font-size:0.7rem;">No roster</span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>

                    <!-- Location -->
                    <td>
                      <span class="badge <?php echo $loc_badge; ?>">
                        <?php echo h($loc_row['location'] ?? ''); ?>
                        <?php if ($loc_row['type'] === 'overflow'): ?>
                          <small>(overflow)</small>
                        <?php endif; ?>
                      </span>
                    </td>

                    <!-- Student count for this location -->
                    <td>
                      <?php if ($loc_row['student_count'] !== null && $loc_row['student_count'] > 0): ?>
                        <span class="badge badge-green"><?php echo $loc_row['student_count']; ?></span>
                      <?php else: ?>
                        <span class="badge badge-gray">—</span>
                      <?php endif; ?>
                    </td>

                    <!-- Proctor -->
                    <td>
                      <?php if ($is_unassigned): ?>
                        <span class="badge badge-red">❌ TBD</span>
                      <?php else: ?>
                        <span class="badge badge-green">
                          <?php echo h($loc_row['proctor'] ?? ''); ?>
                        </span>
                      <?php endif; ?>
                    </td>

                    <!-- Action button -->
                    <td>
                      <button
                        class="btn btn-small <?php echo $is_unassigned ? 'btn-primary' : 'btn-secondary'; ?>"
                        onclick="toggleOverride('<?php echo $override_id; ?>')">
                        <?php echo $is_unassigned ? '+ Assign' : '✏️ Override'; ?>
                      </button>
                    </td>
                  </tr>

                  <!-- Override Form Row -->
                  <tr id="<?php echo $override_id; ?>" class="override-form-row" style="display:none">
                    <td colspan="8" style="padding:0.75rem 1rem; background:#f0f7f0;">
                      <form method="POST" style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                        <input type="hidden" name="action" value="override_proctor" />
                        <input type="hidden" name="test_name"
                          value="<?php echo h($test['test_name'] ?? ''); ?>" />
                        <input type="hidden" name="test_date"
                          value="<?php echo $test['test_date']; ?>" />
                        <input type="hidden" name="override_location"
                          value="<?php echo h($loc_row['location'] ?? ''); ?>" />
                        <input type="hidden" name="override_type" value="replace" />

                        <span style="font-size:0.85rem; font-weight:600; color:#1a5c1a;">
                          <?php echo $is_unassigned ? 'Assign' : 'Override'; ?> proctor —
                          <?php echo h($test['test_name'] ?? ''); ?>
                          (<?php echo h($loc_row['location'] ?? ''); ?>)
                        </span>

                        <?php if ($loc_row['type'] === 'main'): ?>
                          <!-- Main location — use select dropdown -->
                          <select name="new_teacher" required
                            style="font-size:0.85rem; padding:0.35rem 0.5rem; border-radius:4px; border:1px solid #ccc; min-width:220px;">
                            <option value="">— Select proctor —</option>
                            <optgroup label="0 assignments (suggested)">
                              <?php foreach ($all_teachers as $t): ?>
                                <?php if ($t['assignment_count'] == 0): ?>
                                  <option value="<?php echo h($t['teacher_name']); ?>">
                                    <?php echo h($t['teacher_name']); ?>
                                    (teaches <?php echo h($t['test_name']); ?>) — 0 assignments
                                  </option>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="1 assignment">
                              <?php foreach ($all_teachers as $t): ?>
                                <?php if ($t['assignment_count'] == 1): ?>
                                  <option value="<?php echo h($t['teacher_name']); ?>">
                                    <?php echo h($t['teacher_name']); ?>
                                    (teaches <?php echo h($t['test_name']); ?>) — 1 assignment
                                  </option>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="2 assignments">
                              <?php foreach ($all_teachers as $t): ?>
                                <?php if ($t['assignment_count'] == 2): ?>
                                  <option value="<?php echo h($t['teacher_name']); ?>">
                                    <?php echo h($t['teacher_name']); ?>
                                    (teaches <?php echo h($t['test_name']); ?>) — 2 assignments
                                  </option>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="3+ assignments">
                              <?php foreach ($all_teachers as $t): ?>
                                <?php if ($t['assignment_count'] >= 3): ?>
                                  <option value="<?php echo h($t['teacher_name']); ?>">
                                    <?php echo h($t['teacher_name']); ?>
                                    (teaches <?php echo h($t['test_name']); ?>) — <?php echo $t['assignment_count']; ?> assignments
                                  </option>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            </optgroup>
                          </select>

                          <select name="override_type"
                            style="font-size:0.85rem; padding:0.35rem 0.5rem; border-radius:4px; border:1px solid #ccc;">
                            <option value="replace">Replace current proctor</option>
                            <option value="add">Add as additional proctor</option>
                          </select>

                        <?php else: ?>
                          <!-- Accommodations/Guidance/Overflow — free text input with datalist -->
                          <input type="text"
                            name="new_teacher"
                            list="teachers_list_<?php echo $override_id; ?>"
                            placeholder="Type or select a name..."
                            style="min-width:250px; padding:0.35rem 0.5rem; border-radius:4px; border:1px solid #ccc; font-size:0.85rem;"
                            value="<?php echo h($loc_row['proctor'] ?? ''); ?>" />
                          <datalist id="teachers_list_<?php echo $override_id; ?>">

                            <?php
                            $max_assignments = max(array_column($acc_teachers, 'assignment_count'));
                            for ($i = 0; $i <= $max_assignments; $i++): ?>
                              <option disabled>— <?php echo $i; ?> assignment<?php echo $i !== 1 ? 's' : ''; ?> —</option>
                              <?php foreach ($acc_teachers as $t): ?>
                                <?php if ($t['assignment_count'] == $i): ?>
                                  <option value="<?php echo h($t['teacher_name']); ?>">
                                    <?php echo h($t['teacher_name']); ?>
                                    (teaches <?php echo h($t['test_name']); ?>) — <?php echo $i; ?> assignment<?php echo $i !== 1 ? 's' : ''; ?>
                                  </option>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            <?php endfor; ?>

                          </datalist>
                          <small style="color:#666; font-style:italic;">
                            You can type any name — not restricted to the list
                          </small>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-small btn-primary">Save</button>
                        <button type="button" class="btn btn-small btn-secondary"
                          onclick="toggleOverride('<?php echo $override_id; ?>')">
                          Cancel
                        </button>
                      </form>
                    </td>
                  </tr>

                  <?php $first_location = false; ?>
                <?php endforeach; // end locations loop 
                ?>

              <?php endforeach; // end tests loop 
              ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <!-- </div> -->






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
                  <strong><?php echo h($row['teacher_name'] ?? ''); ?></strong>
                </td>
                <td><?php echo h($row['assignments'] ?? ''); ?></td>
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


    </div>
  </main>

  <footer>
    <p>Viera High School &copy; <?php echo date('Y'); ?> — AP Testing Coordinator Portal</p>
  </footer>

  <script>
    function toggleOverride(id) {
      const row = document.getElementById(id);
      if (!row) return;
      const isHidden = row.style.display === 'none' || row.style.display === '';

      // Close all open override forms first
      document.querySelectorAll('.override-form-row').forEach(r => {
        r.style.display = 'none';
      });

      // Open this one if it was closed
      if (isHidden) {
        row.style.display = 'table-row';
        // Scroll into view
        row.scrollIntoView({
          behavior: 'smooth',
          block: 'nearest'
        });
      }
    }
  </script>

</body>

</html>
<?php $conn->close(); ?>