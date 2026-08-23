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
  $stmt = $conn->prepare("
    SELECT main_location FROM test_room_overrides
    WHERE test_name = ?
    AND session_date = ?
    AND session_time = ?
    LIMIT 1
");
  $stmt->bind_param("sss", $test_name, $test_date, $test_time);
  $stmt->execute();
  $override = $stmt->get_result()->fetch_assoc();

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

    $stmt = $conn->prepare("DELETE FROM proctor_assignments WHERE school_year = ?");
    $stmt->bind_param("s", $school_year);
    $stmt->execute();

    // Line 105 - testing period query
    $stmt = $conn->prepare("
    SELECT * FROM testing_period 
    WHERE school_year = ? 
    LIMIT 1
");
    $stmt->bind_param("s", $school_year);
    $stmt->execute();
    $period = $stmt->get_result()->fetch_assoc();

    if (!$period) {
      $error_message = "Please set up the testing period first.";
    } else {
      $period_start = $period['period_start'];
      $period_end = $period['period_end'];
      $testing_dates = getTestingDates($period_start, $period_end);

      // Line 121 - ap_tests query (no user input - safe as is)
      $tests_result = $conn->query("
        SELECT * FROM ap_tests 
        WHERE test_date IS NOT NULL 
        AND test_time IS NOT NULL
        ORDER BY test_date ASC, 
        CASE test_time WHEN '8AM' THEN 1 WHEN '12PM' THEN 2 END ASC
    ");
      $tests = $tests_result->fetch_all(MYSQLI_ASSOC);


      // Line 130 - teachers query (no user input - safe as is)
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

      // Line 141 - student counts query
      $stmt = $conn->prepare("
        SELECT course_enrolled, COUNT(*) as student_count
        FROM students
        WHERE school_year = ?
        GROUP BY course_enrolled
    ");
      $stmt->bind_param("s", $school_year);
      $stmt->execute();
      $counts_result = $stmt->get_result();
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
      $slot_rooms_used = [];

      // ----------------------------------------
      // SINGLE PASS: Assign proctors using
      // 4-day buffer rule
      // Teacher eligible if test_date >=
      // their_test_date minus 4 days
      // Cannot proctor own subject
      // One assignment per teacher per day
      // ----------------------------------------
      foreach ($tests as $test) {
        $test_name = $test['test_name'];
        $test_date = $test['test_date'];
        $test_time = $test['test_time'];

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
        $slot_rooms_used[$slot_key][] = $resolved_room;

        $student_count = $student_counts[$test_name] ?? 0;
        $proctors_needed = $student_count > 100 ? 2 : 1;
        $assigned_count = 0;

        // Check how many already assigned for this session
        $already_assigned = count(array_filter(
          $assignments,
          function ($a) use ($test_name, $test_date) {
            return $a['test_name'] === $test_name &&
              $a['test_date'] === $test_date;
          }
        ));

        if ($already_assigned >= $proctors_needed) continue;
        $assigned_count = $already_assigned;

        $eligible_teachers = [];

        foreach ($teachers as $teacher) {
          $teacher_name = $teacher['teacher_name'];
          $their_test_date = $teacher['their_test_date'];

          // Rule 1: Cannot proctor any subject they teach
          $teacher_subjects = array_filter(
            $teachers,
            fn($t) => $t['teacher_name'] === $teacher_name
          );
          $teacher_test_names = array_column(
            array_values($teacher_subjects),
            'test_name'
          );
          if (in_array($test_name, $teacher_test_names)) continue;

          // Rule 2: One assignment per teacher per day
          if (in_array($test_date, $teacher_assigned_dates[$teacher_name])) continue;

          // Rule 2b: Teacher cannot be assigned to same test twice
          $already_in_session = array_filter(
            $assignments,
            function ($a) use ($teacher_name, $test_name, $test_date) {
              return $a['teacher_name'] === $teacher_name &&
                $a['test_name'] === $test_name &&
                $a['test_date'] === $test_date;
            }
          );
          if (!empty($already_in_session)) continue;

          // Rule 3: 4-day buffer — teacher eligible if
          // test_date >= their_test_date - 4 days
          $buffer_date = subtractSchoolDays($their_test_date, 4);

          if ($test_date < $buffer_date) continue;

          $eligible_teachers[] = [
            'teacher' => $teacher,
            'assignment_count' => $teacher_assignment_counts[$teacher_name]
          ];
        }

        // Sort by assignment count (fewest first)
        usort($eligible_teachers, function ($a, $b) {
          return $a['assignment_count'] - $b['assignment_count'];
        });

        foreach ($eligible_teachers as $eligible) {
          if ($assigned_count >= $proctors_needed) break;

          $teacher = $eligible['teacher'];
          $teacher_name = $teacher['teacher_name'];
          $day_number = !empty($test['testing_day_number'])
            ? (int)$test['testing_day_number'] : null;

          $stmt = $conn->prepare("
            INSERT INTO proctor_assignments
                (teacher_name, test_name, test_date, testing_day_number,
                 location, school_year)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
          $stmt->bind_param(
            "sssiss",
            $teacher_name,
            $test_name,
            $test_date,
            $day_number,
            $resolved_room,
            $school_year
          );

          if ($stmt->execute()) {
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
      // end single pass


      // ----------------------------------------
      // PASS 4: Auto-assign proctors to
      // accommodations rooms, Guidance,
      // and overflow (Media Center)
      // Priority: unassigned teachers first
      // ----------------------------------------

      // Build list of sessions needing accommodations proctors
      $acc_sessions = [];

      foreach ($tests as $test) {
        $test_name = $test['test_name'];
        $test_date = $test['test_date'];
        $acc_location = $test['accommodations_location'];
        $overflow_location = $test['overflow_location'] ?? 'Media Center';

        $stmt = $conn->prepare("
        SELECT COUNT(*) as cnt FROM students
        WHERE course_enrolled = ?
        AND school_year = ?
        AND accommodation_type IN ('extended_50', 'other')
    ");
        $stmt->bind_param("ss", $test_name, $school_year);
        $stmt->execute();
        $acc_count = $stmt->get_result()->fetch_assoc()['cnt'];

        $stmt = $conn->prepare("
        SELECT COUNT(*) as cnt FROM students
        WHERE course_enrolled = ?
        AND school_year = ?
        AND accommodation_type = 'extended_100'
    ");
        $stmt->bind_param("ss", $test_name, $school_year);
        $stmt->execute();
        $guidance_count = $stmt->get_result()->fetch_assoc()['cnt'];

        $stmt = $conn->prepare("
        SELECT COUNT(*) as cnt FROM students
        WHERE course_enrolled = ?
        AND school_year = ?
        AND accommodation_type IN ('none', 'preferential_only')
    ");
        $stmt->bind_param("ss", $test_name, $school_year);
        $stmt->execute();
        $main_count = $stmt->get_result()->fetch_assoc()['cnt'];
        $overflow_count = max(0, $main_count - 200);

        if ($acc_count > 0) {
          $acc_sessions[] = [
            'test_name' => $test_name,
            'test_date' => $test_date,
            'location' => $acc_location,
            'student_count' => $acc_count,
            'type' => 'accommodations'
          ];
        }

        // if ($guidance_count > 0) {
        //   $acc_sessions[] = [
        //     'test_name' => $test_name,
        //     'test_date' => $test_date,
        //     'location' => 'Guidance',
        //     'student_count' => $guidance_count,
        //     'type' => 'guidance'
        //   ];
        // }

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
        $stmt = $conn->prepare("
        SELECT id FROM proctor_assignments
        WHERE test_name = ?
        AND test_date = ?
        AND location = ?
        AND school_year = ?
        LIMIT 1
    ");
        $stmt->bind_param("ssss", $test_name, $test_date, $location, $school_year);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) continue;

        // Find an eligible unassigned teacher
        // Try 0-assignment teachers first, then 1-assignment teachers
        foreach ([0, 1] as $max_assignments) {
          foreach ($teachers as $teacher) {
            $teacher_name = $teacher['teacher_name'];
            $their_test_date = $teacher['their_test_date'];

            // Only consider teachers at this assignment level
            if ($teacher_assignment_counts[$teacher_name] !== $max_assignments) continue;

            // Cannot proctor own subject (check all subjects they teach)
            $teacher_subjects = array_filter(
              $teachers,
              fn($t) => $t['teacher_name'] === $teacher_name
            );
            $teacher_test_names = array_column(
              array_values($teacher_subjects),
              'test_name'
            );
            if (in_array($test_name, $teacher_test_names)) continue;

            // Cannot be assigned same day
            if (in_array($test_date, $teacher_assigned_dates[$teacher_name])) continue;

            // Check school day buffer
            $buffer_date = subtractSchoolDays($their_test_date, 4);
            if ($test_date < $buffer_date) continue;

            // Assign this teacher
            $day_number = null;
            foreach ($tests as $t) {
              if ($t['test_name'] === $test_name) {
                $day_number = !empty($t['testing_day_number'])
                  ? (int)$t['testing_day_number'] : null;
                break;
              }
            }

            $stmt = $conn->prepare("
            INSERT INTO proctor_assignments
                (teacher_name, test_name, test_date, testing_day_number,
                 location, school_year)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
            $stmt->bind_param(
              "sssiss",
              $teacher_name,
              $test_name,
              $test_date,
              $day_number,
              $location,
              $school_year
            );

            if ($stmt->execute()) {
              $teacher_assignment_counts[$teacher_name]++;
              $teacher_assigned_dates[$teacher_name][] = $test_date;
              break 2; // Break out of both foreach loops
            }
          }
        }
      }
      // end pass 4


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
    $stmt = $conn->prepare("
    SELECT test_date, test_name, testing_day_number,
           GROUP_CONCAT(teacher_name ORDER BY teacher_name SEPARATOR ', ') 
           as proctors, location
    FROM proctor_assignments
    WHERE school_year = ?
    GROUP BY test_date, test_name, testing_day_number, location
    ORDER BY test_date ASC, test_name ASC
");
    $stmt->bind_param("s", $school_year);
    $stmt->execute();
    $result = $stmt->get_result();

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
    $stmt = $conn->prepare("
    SELECT teacher_name,
           GROUP_CONCAT(
               CONCAT(test_name, ' (', test_date, ')') 
               ORDER BY test_date SEPARATOR '; '
           ) as assignments,
           COUNT(*) as total_assignments
    FROM proctor_assignments
    WHERE school_year = ?
    GROUP BY teacher_name
    ORDER BY teacher_name ASC
");
    $stmt->bind_param("s", $school_year);
    $stmt->execute();
    $result = $stmt->get_result();

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
    $stmt = $conn->prepare("DELETE FROM proctor_assignments WHERE school_year = ?");
    $stmt->bind_param("s", $school_year);
    $stmt->execute();
    $success_message = "Schedule cleared successfully.";
  }


  // ----------------------------------------
  // Override / Assign Proctor
  // ----------------------------------------
  if ($_POST['action'] === 'override_proctor') {
    $test_name = sanitize_string($_POST['test_name']);
    $test_date = sanitize_date($_POST['test_date']);
    $new_teacher = sanitize_string($_POST['new_teacher']);
    $override_type = $_POST['override_type'] ?? 'replace';

    // Get testing day number for this test
    $stmt = $conn->prepare("
    SELECT testing_day_number, main_location 
    FROM ap_tests 
    WHERE test_name = ? 
    LIMIT 1
");
    $stmt->bind_param("s", $test_name);
    $stmt->execute();
    $day_result = $stmt->get_result()->fetch_assoc();

    $day_number = $day_result['testing_day_number'] ?? null;
    $location = $day_result['main_location'] ?? 'Gym';

    // Use override_location if provided (for accommodations/guidance/overflow)
    if (!empty($_POST['override_location'])) {
      $location = sanitize_string($_POST['override_location']);
    } else {
      // Get current location from existing assignment
      $stmt = $conn->prepare("
    SELECT location FROM proctor_assignments
    WHERE test_name = ?
    AND test_date = ?
    AND school_year = ?
    LIMIT 1
");
      $stmt->bind_param("sss", $test_name, $test_date, $school_year);
      $stmt->execute();
      $current = $stmt->get_result()->fetch_assoc();

      if ($current) {
        $location = $current['location'];
      }
    }

    if ($override_type === 'replace') {
      // Only remove assignment for this specific location
      $stmt = $conn->prepare("
        DELETE FROM proctor_assignments
        WHERE test_name = ?
        AND test_date = ?
        AND school_year = ?
        AND location = ?
    ");
      $stmt->bind_param("ssss", $test_name, $test_date, $school_year, $location);
      $stmt->execute();
    }

    // Insert new assignment
    // Check for duplicate before inserting
    $stmt = $conn->prepare("
    SELECT id FROM proctor_assignments
    WHERE teacher_name = ?
    AND test_name = ?
    AND test_date = ?
    AND school_year = ?
    AND location != ?
    LIMIT 1
");
    $stmt->bind_param("sssss", $new_teacher, $test_name, $test_date, $school_year, $location);
    $stmt->execute();
    $dup_check = $stmt->get_result();

    if ($dup_check->num_rows > 0) {
      $error_message = "This teacher is already assigned to this session in a different location.";
    } else {
      $stmt = $conn->prepare("
    INSERT INTO proctor_assignments
        (teacher_name, test_name, test_date, testing_day_number,
         location, school_year)
    VALUES (?, ?, ?, ?, ?, ?)
");
      $stmt->bind_param(
        "sssiss",
        $new_teacher,
        $test_name,
        $test_date,
        $day_number,
        $location,
        $school_year
      );
      if ($stmt->execute()) {
        $success_message = "Proctor assignment updated successfully!";
      } else {
        $error_message = "Error updating assignment: " . $stmt->error;
      }
    }
  }

  // ----------------------------------------
  // Remove a specific proctor assignment
  // ----------------------------------------
  if ($_POST['action'] === 'remove_proctor') {
    $assignment_id = (int)$_POST['assignment_id'];
    $stmt = $conn->prepare("DELETE FROM proctor_assignments WHERE id = ?");
    $stmt->bind_param("i", $assignment_id);
    if ($stmt->execute()) {
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
$stmt = $conn->prepare("
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
         AND s.school_year = ?) as total_students,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = a.test_name 
         AND s.school_year = ?
         AND s.accommodation_type IN ('none','preferential_only')) as main_students,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = a.test_name 
         AND s.school_year = ?
         AND s.accommodation_type IN ('extended_50','other')) as acc_students,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = a.test_name 
         AND s.school_year = ?
         AND s.accommodation_type = 'extended_100') as guidance_students
    FROM ap_tests a
    WHERE a.test_date IS NOT NULL
    AND a.test_time IS NOT NULL
    ORDER BY a.test_date ASC,
    CASE a.test_time WHEN '8AM' THEN 1 WHEN '12PM' THEN 2 END ASC,
    a.test_name ASC
");
$stmt->bind_param("ssss", $school_year, $school_year, $school_year, $school_year);
$stmt->execute();
$all_tests_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// For each test build location rows with proctor info
$schedule_by_date = [];
foreach ($all_tests_result as $test) {

  $main_students = (int)$test['main_students'];
  $overflow_count = max(0, $main_students - 200);
  $main_count = min($main_students, 200);

  // Fetch all proctor assignments for this test
  $test_name = $test['test_name'];
  $stmt = $conn->prepare("
    SELECT teacher_name, location
    FROM proctor_assignments
    WHERE test_name = ?
    AND school_year = ?
    ORDER BY id ASC
");
  $stmt->bind_param("ss", $test_name, $school_year);
  $stmt->execute();
  $proctors_result = $stmt->get_result();

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



// Schedule by teacher
$stmt = $conn->prepare("
    SELECT teacher_name,
           GROUP_CONCAT(
               CONCAT(test_name, ' on ', DATE_FORMAT(test_date, '%b %e')) 
               ORDER BY test_date SEPARATOR '; '
           ) as assignments,
           COUNT(*) as total_assignments
    FROM proctor_assignments
    WHERE school_year = ?
    GROUP BY teacher_name
    ORDER BY teacher_name ASC
");
$stmt->bind_param("s", $school_year);
$stmt->execute();
$schedule_by_teacher = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Multi proctor teachers
$stmt = $conn->prepare("
    SELECT teacher_name, COUNT(*) as assignment_count
    FROM proctor_assignments
    WHERE school_year = ?
    GROUP BY teacher_name
    HAVING COUNT(*) > 1
    ORDER BY COUNT(*) DESC
");
$stmt->bind_param("s", $school_year);
$stmt->execute();
$multi_proctor_teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Unassigned check
$stmt = $conn->prepare("
    SELECT a.test_name, a.test_date, a.test_time,
           COUNT(p.id) as assigned_count
    FROM ap_tests a
    LEFT JOIN proctor_assignments p ON a.test_name = p.test_name 
        AND p.school_year = ?
    WHERE a.test_date IS NOT NULL
    GROUP BY a.test_name, a.test_date, a.test_time
    HAVING assigned_count = 0
    ORDER BY a.test_date ASC
");
$stmt->bind_param("s", $school_year);
$stmt->execute();
$unassigned_check = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Teachers with no assignments
$stmt = $conn->prepare("
    SELECT t.teacher_name, t.test_name
    FROM ap_teachers t
    WHERE t.active = 1
    AND t.teacher_name NOT IN (
        SELECT DISTINCT teacher_name 
        FROM proctor_assignments 
        WHERE school_year = ?
    )
    ORDER BY t.teacher_name ASC
");
$stmt->bind_param("s", $school_year);
$stmt->execute();
$no_assignment_teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// All teachers for override dropdowns
$stmt = $conn->prepare("
    SELECT t.teacher_name, t.test_name,
        COUNT(p.id) as assignment_count,
        CASE WHEN COUNT(p.id) = 0 THEN 0 ELSE 1 END as is_assigned
    FROM ap_teachers t
    LEFT JOIN proctor_assignments p ON t.teacher_name = p.teacher_name
        AND p.school_year = ?
    WHERE t.active = 1
    GROUP BY t.teacher_name, t.test_name
    ORDER BY assignment_count ASC, t.teacher_name ASC
");
$stmt->bind_param("s", $school_year);
$stmt->execute();
$all_teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ------------------------------------------------
// Fetch accommodations/overflow/guidance sessions
// with their proctor status
// ------------------------------------------------
$stmt = $conn->prepare("
    SELECT 
        t.test_name,
        t.test_date,
        t.test_time,
        t.accommodations_location,
        t.overflow_location,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = t.test_name 
         AND s.school_year = ?
         AND s.accommodation_type IN ('extended_50','other')) as acc_count,
        (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = t.test_name 
         AND s.school_year = ?
         AND s.accommodation_type = 'extended_100') as guidance_count,
        GREATEST(0, (SELECT COUNT(*) FROM students s 
         WHERE s.course_enrolled = t.test_name 
         AND s.school_year = ?
         AND s.accommodation_type IN ('none','preferential_only')) - 200) as overflow_count,
        (SELECT GROUP_CONCAT(p.teacher_name SEPARATOR ', ')
         FROM proctor_assignments p
         WHERE p.test_name = t.test_name
         AND p.school_year = ?
         AND p.location = t.accommodations_location
         LIMIT 1) as acc_proctor,
        (SELECT GROUP_CONCAT(p.teacher_name SEPARATOR ', ')
         FROM proctor_assignments p
         WHERE p.test_name = t.test_name
         AND p.school_year = ?
         AND p.location = 'Guidance'
         LIMIT 1) as guidance_proctor,
        (SELECT GROUP_CONCAT(p.teacher_name SEPARATOR ', ')
         FROM proctor_assignments p
         WHERE p.test_name = t.test_name
         AND p.school_year = ?
         AND p.location = t.overflow_location
         LIMIT 1) as overflow_proctor
    FROM ap_tests t
    WHERE t.test_date IS NOT NULL
    HAVING acc_count > 0 OR guidance_count > 0 OR overflow_count > 0
    ORDER BY t.test_date ASC, t.test_name ASC
");
$stmt->bind_param(
  "ssssss",
  $school_year,
  $school_year,
  $school_year,
  $school_year,
  $school_year,
  $school_year
);
$stmt->execute();
$acc_proctor_sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("
    SELECT t.teacher_name, t.test_name,
        COUNT(p.id) as assignment_count
    FROM ap_teachers t
    LEFT JOIN proctor_assignments p ON t.teacher_name = p.teacher_name
        AND p.school_year = ?
    WHERE t.active = 1
    GROUP BY t.teacher_name, t.test_name
    ORDER BY assignment_count ASC, t.teacher_name ASC
");
$stmt->bind_param("s", $school_year);
$stmt->execute();
$acc_teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


// ------------------------------------------------
// Helper: Subtract N school days from a date
// skipping weekends
// ------------------------------------------------
function subtractSchoolDays($date, $days)
{
  $current = strtotime($date);
  $subtracted = 0;
  while ($subtracted < $days) {
    $current = strtotime('-1 day', $current);
    $day_of_week = date('N', $current);
    // Skip Saturday (6) and Sunday (7)
    if ($day_of_week < 6) {
      $subtracted++;
    }
  }
  return date('Y-m-d', $current);
}


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
                      <form method="POST" id="form_<?php echo $override_id; ?>" style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                        <?php echo csrf_input(); ?>
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
                          <div style="display:flex; flex-direction:column; gap:0.4rem;">
                            <select
                              id="select_<?php echo $override_id; ?>"
                              style="min-width:250px; padding:0.35rem 0.5rem; border-radius:4px; border:1px solid #ccc; font-size:0.85rem;"
                              onchange="document.getElementById('teacher_<?php echo $override_id; ?>').value = this.value">
                              <option value="">— Select from AP teachers —</option>
                              <?php
                              $max_assignments = !empty($acc_teachers)
                                ? max(array_column($acc_teachers, 'assignment_count'))
                                : 0;
                              for ($i = 0; $i <= $max_assignments; $i++):
                                $group_teachers = array_filter(
                                  $acc_teachers,
                                  fn($t) => $t['assignment_count'] == $i
                                );
                                if (empty($group_teachers)) continue;
                              ?>
                                <optgroup label="— <?php echo $i; ?> assignment<?php echo $i !== 1 ? 's' : ''; ?> —">
                                  <?php foreach ($group_teachers as $t): ?>
                                    <option value="<?php echo h($t['teacher_name']); ?>"
                                      <?php echo ($loc_row['proctor'] ?? '') === $t['teacher_name'] ? 'selected' : ''; ?>>
                                      <?php echo h($t['teacher_name']); ?>
                                      (teaches <?php echo h($t['test_name']); ?>)
                                    </option>
                                  <?php endforeach; ?>
                                </optgroup>
                              <?php endfor; ?>
                            </select>
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                              <small style="color:#666;">Or type any name (e.g. guidance staff, substitute):</small>
                              <input type="text"
                                placeholder="Type custom name..."
                                style="min-width:200px; padding:0.35rem 0.5rem; border-radius:4px; border:1px solid #ccc; font-size:0.85rem;"
                                oninput="document.getElementById('teacher_<?php echo $override_id; ?>').value = this.value" />
                            </div>
                            <!-- Hidden input that actually gets submitted -->
                            <input type="hidden"
                              id="teacher_<?php echo $override_id; ?>"
                              name="new_teacher"
                              value="<?php echo h($loc_row['proctor'] ?? ''); ?>" />
                          </div>
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
  <?php include __DIR__ . '/../spinner.php'; ?>
</body>

</html>
<?php $conn->close(); ?>