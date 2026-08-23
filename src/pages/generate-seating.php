<?php
require_once __DIR__ . '/../db.php';

$school_year = get_school_year();
// TEMP DEBUG
error_log("School year: " . $school_year);

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$success_message = '';
$error_message = '';
$school_year = date('Y') . '-' . (date('Y') + 1);

// ------------------------------------------------
// Handle Generate Seating Charts
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

  if ($_POST['action'] === 'generate_seating') {

    // Clear existing seating assignments for this year
    $stmt = $conn->prepare("
    UPDATE students 
    SET seat_number = NULL, assigned_location = NULL
    WHERE school_year = ?
");
    $stmt->bind_param("s", $school_year);
    $stmt->execute();

    // Fetch all AP tests that have students
    $stmt = $conn->prepare("
    SELECT DISTINCT s.course_enrolled, 
                   t.main_location,
                   t.accommodations_location,
                   t.overflow_location,
                   t.test_date,
                   t.test_time
    FROM students s
    JOIN ap_tests t ON s.course_enrolled = t.test_name
    WHERE s.school_year = ?
    ORDER BY t.test_date ASC, s.course_enrolled ASC
");
    $stmt->bind_param("s", $school_year);
    $stmt->execute();
    $tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $charts_generated = 0;

    foreach ($tests as $test) {
      $main_location = $test['main_location'];
      $acc_location = $test['accommodations_location'];
      $overflow_location = $test['overflow_location'] ?? 'Media Center';
      $course = $test['course_enrolled'];

      $stmt = $conn->prepare("
    SELECT * FROM students
    WHERE course_enrolled = ?
    AND school_year = ?
    AND accommodation_type = 'preferential_only'
    ORDER BY last_name ASC, first_name ASC
");
      $stmt->bind_param("ss", $course, $school_year);
      $stmt->execute();
      $pref_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

      $stmt = $conn->prepare("
    SELECT * FROM students
    WHERE course_enrolled = ?
    AND school_year = ?
    AND accommodation_type = 'none'
    ORDER BY last_name ASC, first_name ASC
");
      $stmt->bind_param("ss", $course, $school_year);
      $stmt->execute();
      $none_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

      $stmt = $conn->prepare("
    SELECT * FROM students
    WHERE course_enrolled = ?
    AND school_year = ?
    AND accommodation_type IN ('extended_50', 'other')
    ORDER BY last_name ASC, first_name ASC
");
      $stmt->bind_param("ss", $course, $school_year);
      $stmt->execute();
      $acc_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

      $stmt = $conn->prepare("
    SELECT * FROM students
    WHERE course_enrolled = ?
    AND school_year = ?
    AND accommodation_type = 'extended_100'
    ORDER BY last_name ASC, first_name ASC
");
      $stmt->bind_param("ss", $course, $school_year);
      $stmt->execute();
      $dbl_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      // ----------------------------------------
      // Combine main location students
      // Preferential first, then no accommodations
      // ----------------------------------------
      $main_students = array_merge($pref_students, $none_students);
      $overflow_students = [];

      // Check if main location exceeds 200
      $capacity = 200;
      if (count($main_students) > $capacity) {
        $overflow_students = array_slice($main_students, $capacity);
        $main_students = array_slice($main_students, 0, $capacity);
      }

      // ----------------------------------------
      // Assign seat numbers
      // ----------------------------------------

      // Main location seats — random assignment
      // Preferential seating students get first seats
      $pref_ids = array_column($pref_students, 'id');
      $none_ids = array_column($none_students, 'id');

      // Shuffle non-preferential students
      shuffle($none_ids);

      // Assign seats: preferential first (1, 2, 3...) then random for rest

      // Main location seats
      $stmt = $conn->prepare("
    UPDATE students 
    SET seat_number = ?, assigned_location = ?
    WHERE id = ?
");

      $seat = 1;
      foreach ($pref_ids as $id) {
        $stmt->bind_param("isi", $seat, $main_location, $id);
        $stmt->execute();
        $seat++;
      }
      foreach ($none_ids as $id) {
        $stmt->bind_param("isi", $seat, $main_location, $id);
        $stmt->execute();
        $seat++;
      }

      // Overflow seats — random
      $overflow_ids = array_column($overflow_students, 'id');
      shuffle($overflow_ids);
      $seat = 1;
      foreach ($overflow_ids as $id) {
        $stmt->bind_param("isi", $seat, $overflow_location, $id);
        $stmt->execute();
        $seat++;
      }

      // Accommodations room seats — random
      $acc_ids = array_column($acc_students, 'id');
      shuffle($acc_ids);
      $seat = 1;
      foreach ($acc_ids as $id) {
        $stmt->bind_param("isi", $seat, $acc_location, $id);
        $stmt->execute();
        $seat++;
      }

      // Guidance seats — random
      $dbl_ids = array_column($dbl_students, 'id');
      shuffle($dbl_ids);
      $guidance = 'Guidance';
      $seat = 1;
      foreach ($dbl_ids as $id) {
        $stmt->bind_param("isi", $seat, $guidance, $id);
        $stmt->execute();
        $seat++;
      }
      $charts_generated++;
    }

    $success_message = "Seating charts generated for $charts_generated AP test(s)!";
  }

  // ----------------------------------------
  // Download seating chart for a specific test
  // ----------------------------------------
  if ($_POST['action'] === 'download_seating') {
    $test_name = sanitize_string($_POST['test_name']);

    $stmt = $conn->prepare("
    SELECT * FROM ap_tests 
    WHERE test_name = ? 
    LIMIT 1
");
    $stmt->bind_param("s", $test_name);
    $stmt->execute();
    $test_info = $stmt->get_result()->fetch_assoc();

    if (!$test_info) {
      $error_message = "Test not found.";
    } else {
      $main_location = $test_info['main_location'];
      $acc_location = $test_info['accommodations_location'];
      $overflow_location = $test_info['overflow_location'] ?? 'Media Center';

      // Fetch proctors per location
      $proctor_result = $conn->query("
                SELECT teacher_name, location
                FROM proctor_assignments
                WHERE test_name = '$test_name'
                AND school_year = '$school_year'
                ORDER BY id ASC
            ");
      $proctors_by_location = [];
      while ($p = $proctor_result->fetch_assoc()) {
        $loc = $p['location'];
        if (!isset($proctors_by_location[$loc])) {
          $proctors_by_location[$loc] = [];
        }
        $proctors_by_location[$loc][] = $p['teacher_name'];
      }

      // Helper to get proctor name for a location
      $getProctor = function ($location) use ($proctors_by_location) {
        if (
          isset($proctors_by_location[$location]) &&
          !empty($proctors_by_location[$location])
        ) {
          return implode(' / ', $proctors_by_location[$location]);
        }
        return 'TBD';
      };

      // Fetch students by location ordered by seat number
      $fetchStudents = function ($location) use ($conn, $test_name, $school_year) {
        $stmt = $conn->prepare("
        SELECT * FROM students
        WHERE course_enrolled = ?
        AND school_year = ?
        AND assigned_location = ?
        ORDER BY seat_number ASC
    ");
        $stmt->bind_param("sss", $test_name, $school_year, $location);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      };

      $main_students = $fetchStudents($main_location);
      $overflow_students = $fetchStudents($overflow_location);
      $acc_students = $fetchStudents($acc_location);
      $guidance_students = $fetchStudents('Guidance');

      // Build safe filename
      $safe_name = str_replace([' ', '/'], '_', $test_name);
      $filename = $safe_name . '_seating_chart_' . $school_year . '.csv';

      header('Content-Type: text/csv');
      header('Content-Disposition: attachment; filename="' . $filename . '"');

      $output = fopen('php://output', 'w');

      // ----------------------------------------
      // Write each section
      // ----------------------------------------
      $sections = [];

      if (!empty($main_students)) {
        $sections[] = [
          'header' => $test_name . ' - ' . $getProctor($main_location),
          'location' => $main_location,
          'students' => $main_students
        ];
      }

      if (!empty($overflow_students)) {
        $sections[] = [
          'header' => $test_name . ' - ' . $getProctor($overflow_location),
          'location' => $overflow_location,
          'students' => $overflow_students
        ];
      }

      if (!empty($acc_students)) {
        $sections[] = [
          'header' => $test_name . ' - ' . $getProctor($acc_location),
          'location' => $acc_location,
          'students' => $acc_students
        ];
      }

      if (!empty($guidance_students)) {
        $sections[] = [
          'header' => $test_name . ' - ' . $getProctor('Guidance'),
          'location' => 'Guidance',
          'students' => $guidance_students
        ];
      }

      foreach ($sections as $section) {
        // Section header
        fputcsv($output, [$section['header'], '', '', '', '']);
        fputcsv($output, [
          'Seating #',
          'First Name',
          'Last Name',
          'Location',
          'Accommodations'
        ]);

        foreach ($section['students'] as $student) {
          fputcsv($output, [
            $student['seat_number'] ?? '',
            $student['first_name'],
            $student['last_name'],
            $section['location'],
            $student['accommodations'] ?? ''
          ]);
        }

        // Blank row between sections
        fputcsv($output, ['', '', '', '', '']);
        fputcsv($output, ['', '', '', '', '']);
      }

      // Late testers (no seat number, class_section_type = late)
      $stmt = $conn->prepare("
    SELECT * FROM students
    WHERE course_enrolled = ?
    AND school_year = ?
    AND (class_section_type LIKE '%late%' 
         OR class_section_type LIKE '%Late%')
    ORDER BY last_name ASC
");
      $stmt->bind_param("ss", $test_name, $school_year);
      $stmt->execute();
      $late_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

      if (!empty($late_students)) {
        fputcsv($output, ['Late Testers', '', '', '', '']);
        fputcsv($output, ['', 'First Name', 'Last Name', '', 'Accommodations']);
        foreach ($late_students as $student) {
          fputcsv($output, [
            'Late Tester',
            $student['first_name'],
            $student['last_name'],
            '',
            $student['accommodations'] ?? ''
          ]);
        }
      }

      fclose($output);
      exit;
    }
  }
}

// ------------------------------------------------
// Fetch summary of tests with student rosters
// ------------------------------------------------
$tests_summary = $conn->query("
    SELECT 
        s.course_enrolled,
        t.test_date,
        t.test_time,
        t.main_location,
        t.accommodations_location,
        COUNT(s.id) as total_students,
        SUM(CASE WHEN s.accommodation_type = 'none' THEN 1 ELSE 0 END) as no_acc,
        SUM(CASE WHEN s.accommodation_type = 'preferential_only' THEN 1 ELSE 0 END) as pref,
        SUM(CASE WHEN s.accommodation_type IN ('extended_50','other') THEN 1 ELSE 0 END) as acc,
        SUM(CASE WHEN s.accommodation_type = 'extended_100' THEN 1 ELSE 0 END) as guidance,
        MAX(s.seat_number) as max_seat
    FROM students s
    JOIN ap_tests t ON s.course_enrolled = t.test_name
    WHERE s.school_year = '$school_year'
    GROUP BY s.course_enrolled, t.test_date, t.test_time, 
             t.main_location, t.accommodations_location
    ORDER BY t.test_date ASC, s.course_enrolled ASC
")->fetch_all(MYSQLI_ASSOC);

$charts_ready = !empty($tests_summary) &&
  count(array_filter($tests_summary, fn($t) => $t['max_seat'] > 0)) > 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Generate Seating Charts | Auto AP Test Scheduler</title>
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

      <h2 class="page-title">💺 Generate Seating Charts</h2>
      <p class="page-subtitle">
        Generate seating charts for each AP test. Students are automatically
        sorted into their testing locations based on accommodations.
        Preferential seating students are assigned first seats.
      </p>

      <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
      <?php endif; ?>
      <?php if ($error_message): ?>
        <div class="alert alert-error"><?php echo $error_message; ?></div>
      <?php endif; ?>

      <!-- ----------------------------------------
           Generate Controls
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">Controls</h3>
        <div class="action-btns">
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="generate_seating" />
            <button type="submit" class="btn btn-primary"
              onclick="return confirm('This will regenerate all seating charts. Continue?')">
              💺 Generate All Seating Charts
            </button>
          </form>
        </div>
        <p class="section-subtitle" style="margin-top:0.75rem;">
          Run this after uploading all student rosters and generating the
          proctor schedule. Each AP test gets its own downloadable CSV.
        </p>
      </div>

      <!-- ----------------------------------------
           Tests Summary Table
      ---------------------------------------- -->
      <?php if (!empty($tests_summary)): ?>
        <div class="card-section">
          <h3 class="section-title">
            AP Tests with Student Rosters
            <span class="badge badge-green"><?php echo count($tests_summary); ?> tests</span>
          </h3>
          <table class="data-table">
            <thead>
              <tr>
                <th>AP Test</th>
                <th>Date</th>
                <th>Time</th>
                <th>Total Students</th>
                <th>Main Location</th>
                <th>No Acc.</th>
                <th>Preferential</th>
                <th>Acc. Room</th>
                <th>Guidance</th>
                <th>Overflow?</th>
                <th>Download</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tests_summary as $test): ?>
                <?php
                $total_main = $test['no_acc'] + $test['pref'];
                $needs_overflow = $total_main > 200;
                ?>
                <tr>
                  <td><strong><?php echo h($test['course_enrolled']); ?></strong></td>
                  <td>
                    <?php echo $test['test_date']
                      ? date('M j, Y', strtotime($test['test_date']))
                      : '<span class="badge badge-gray">Not set</span>'; ?>
                  </td>
                  <td>
                    <?php if ($test['test_time']): ?>
                      <span class="badge <?php echo $test['test_time'] === '8AM'
                                            ? 'badge-green' : 'badge-gold'; ?>">
                        <?php echo $test['test_time']; ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge badge-green"><?php echo $test['total_students']; ?></span>
                  </td>
                  <td><?php echo h($test['main_location']); ?></td>
                  <td><?php echo $test['no_acc']; ?></td>
                  <td><?php echo $test['pref']; ?></td>
                  <td><?php echo $test['acc']; ?></td>
                  <td><?php echo $test['guidance']; ?></td>
                  <td>
                    <?php if ($needs_overflow): ?>
                      <span class="badge badge-gold">⚠️ Yes (<?php echo $total_main - 200; ?> overflow)</span>
                    <?php else: ?>
                      <span class="badge badge-gray">No</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($test['max_seat'] > 0): ?>
                      <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="download_seating" />
                        <input type="hidden" name="test_name"
                          value="<?php echo h($test['course_enrolled']); ?>" />
                        <button type="submit" class="btn btn-small btn-secondary">
                          ⬇️ CSV
                        </button>
                      </form>
                    <?php else: ?>
                      <span class="badge badge-gray">Not generated</span>
                    <?php endif; ?>
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
  <?php include __DIR__ . '/../spinner.php'; ?>
</body>

</html>
<?php $conn->close(); ?>