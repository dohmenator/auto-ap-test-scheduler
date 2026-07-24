<?php
require_once __DIR__ . '/../db.php';

$school_year = get_school_year();

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$success_message = '';
$error_message = '';
$upload_results = [];
$school_year = date('Y') . '-' . (date('Y') + 1);

// ------------------------------------------------
// Accommodation Type Detection Function
// ------------------------------------------------
function detectAccommodationType($accommodations)
{
  if (empty(trim($accommodations))) {
    return 'none';
  }

  // 100% extra time always goes to Guidance
  if (stripos($accommodations, 'Double Time') !== false) {
    return 'extended_100';
  }

  // Preferential seating only — stays in main location
  $cleaned = strtolower(trim($accommodations));
  $non_pref = preg_replace('/preferential seating,?\s*/i', '', $cleaned);
  $non_pref = preg_replace('/breaks:\s*extra,?\s*/i', '', $non_pref);
  $non_pref = preg_replace('/food\/drink\/medication,?\s*/i', '', $non_pref);
  $non_pref = trim($non_pref, " ,\t\n\r");

  if (empty($non_pref)) {
    return 'preferential_only';
  }

  // 50% extended time or any other accommodation
  if (stripos($accommodations, 'Time and One-Half') !== false) {
    return 'extended_50';
  }

  // Any other accommodation
  return 'other';
}

// ------------------------------------------------
// Assign Location Based on Accommodation Type
// ------------------------------------------------
function assignLocation($accommodation_type, $test_name, $conn)
{
  $stmt = $conn->prepare("
    SELECT main_location, accommodations_location 
    FROM ap_tests 
    WHERE test_name = ?
    LIMIT 1
");
  $stmt->bind_param("s", $test_name);
  $stmt->execute();
  $test = $stmt->get_result()->fetch_assoc();

  if (!$test) return 'Unknown';

  switch ($accommodation_type) {
    case 'none':
    case 'preferential_only':
      return $test['main_location'];
    case 'extended_100':
      return 'Guidance';
    case 'extended_50':
    case 'other':
      return $test['accommodations_location'];
    default:
      return $test['main_location'];
  }
}

// ------------------------------------------------
// Handle CSV Upload
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

  if ($_POST['action'] === 'upload_roster') {
    if (isset($_FILES['roster_csv']) && $_FILES['roster_csv']['error'] === 0) {
      $file = $_FILES['roster_csv']['tmp_name'];
      $handle = fopen($file, 'r');

      $added = 0;
      $skipped = 0;
      $errors = [];
      $row_number = 0;
      $headers = [];
      $course_cleared = false;
      $replaced_notice = '';

      // Expected column headers from College Board CSV
      $expected_headers = [
        'Student First Name',
        'Student Last Name',
        'School Code',
        'Grade',
        'Email Address',
        'AP ID',
        'Student ID',
        'Course Enrolled In',
        'Class Section Name',
        'Class Section Type',
        'Teacher Name(s)',
        'Exam Date',
        'Approved SSD Accommodations'
      ];

      $added = 0;
      $skipped = 0;
      $errors = [];
      $row_number = 0;
      $headers = [];
      $course_cleared = false;
      $replaced_notice = '';

      while (($row = fgetcsv($handle)) !== false) {
        $row_number++;

        // Auto-detect header row by looking for 'Student First Name'
        if (empty($headers)) {
          if (in_array('Student First Name', array_map('trim', $row))) {
            $headers = array_map('trim', $row);
          }
          continue;
        }

        // Skip empty rows
        if (empty(array_filter($row))) continue;

        // Map headers to values
        $data = array_combine($headers, array_pad($row, count($headers), ''));

        // Skip late testers
        $class_section_type = sanitize_string($data['Class Section Type'] ?? '');
        if (stripos($class_section_type, 'late') !== false) {
          $skipped++;
          continue;
        }

        // On first student row detect course and clear existing roster
        if (!$course_cleared) {
          $detected_course = sanitize_string($data['Course Enrolled In'] ?? '');
          if (!empty($detected_course)) {
            $stmt = $conn->prepare("
                DELETE FROM students 
                WHERE course_enrolled = ? 
                AND school_year = ?
            ");
            $stmt->bind_param("ss", $detected_course, $school_year);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            if ($deleted > 0) {
              $replaced_notice = "Replaced existing roster of $deleted student(s) for $detected_course. ";
            }
          }
          $course_cleared = true;
        }

        // Extract fields
        $first_name = sanitize_string($data['Student First Name'] ?? '');
        $last_name = sanitize_string($data['Student Last Name'] ?? '');
        $school_code = sanitize_string($data['School Code'] ?? '');
        $grade = sanitize_string($data['Grade'] ?? '');
        $email = sanitize_email($data['Email Address'] ?? '');
        $ap_id = sanitize_string($data['AP ID'] ?? '');
        $student_id = sanitize_string($data['Student ID'] ?? '');
        $course_enrolled = sanitize_string($data['Course Enrolled In'] ?? '');
        $class_section_name = sanitize_string($data['Class Section Name'] ?? '');
        $teacher_name = sanitize_string($data['Teacher Name(s)'] ?? '');
        $exam_date = sanitize_string($data['Exam Date'] ?? '');
        $accommodations = trim($data['Approved SSD Accommodations'] ?? '');

        // Treat bare "SSD" placeholder as no accommodations
        if (strtoupper(trim($accommodations)) === 'SSD') {
          $accommodations = '';
        }

        // Skip if no name
        if (empty($first_name) && empty($last_name)) {
          $skipped++;
          continue;
        }

        // Detect accommodation type
        $accommodation_type = detectAccommodationType($accommodations);

        // Assign location
        $assigned_location = assignLocation($accommodation_type, $course_enrolled, $conn);

        // Insert student using prepared statement
        $stmt = $conn->prepare("
        INSERT INTO students (
            first_name, last_name, school_code, grade, email,
            ap_id, student_id, course_enrolled, class_section_name,
            class_section_type, teacher_name, exam_date,
            accommodations, accommodation_type, assigned_location,
            school_year
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?
        )
    ");

        $stmt->bind_param(
          "ssssssssssssssss",
          $first_name,
          $last_name,
          $school_code,
          $grade,
          $email,
          $ap_id,
          $student_id,
          $course_enrolled,
          $class_section_name,
          $class_section_type,
          $teacher_name,
          $exam_date,
          $accommodations,
          $accommodation_type,
          $assigned_location,
          $school_year
        );

        if ($stmt->execute()) {
          $added++;
        } else {
          $errors[] = "Row $row_number: " . $stmt->error;
          $skipped++;
        }
      }

      fclose($handle);

      if ($added > 0) {
        $success_message = $replaced_notice .
          "Upload complete: $added student(s) added, $skipped skipped.";
      } else {
        $error_message = "No students were added. Please check the file format.";
      }



      $upload_results = [
        'added' => $added,
        'skipped' => $skipped,
        'errors' => $errors
      ];

      if ($added > 0) {
        $success_message = "Upload complete: $added student(s) added, $skipped skipped.";
      } else {
        $error_message = "No students were added. Please check the file format.";
      }
    } else {
      $error_message = "Please select a valid CSV file.";
    }
  }

  // Clear all students for this school year
  if ($_POST['action'] === 'clear_roster') {
    $stmt = $conn->prepare("DELETE FROM students WHERE school_year = ?");
    $stmt->bind_param("s", $school_year);
    $stmt->execute();
    $success_message = "All student rosters cleared for $school_year.";
  }
}

// ------------------------------------------------
// Fetch upload summary
// ------------------------------------------------
$summary_result = $conn->query("
    SELECT 
        course_enrolled,
        COUNT(*) as total_students,
        SUM(CASE WHEN accommodation_type = 'none' THEN 1 ELSE 0 END) as no_accommodations,
        SUM(CASE WHEN accommodation_type = 'preferential_only' THEN 1 ELSE 0 END) as preferential_only,
        SUM(CASE WHEN accommodation_type = 'extended_50' THEN 1 ELSE 0 END) as extended_50,
        SUM(CASE WHEN accommodation_type = 'extended_100' THEN 1 ELSE 0 END) as extended_100,
        SUM(CASE WHEN accommodation_type = 'other' THEN 1 ELSE 0 END) as other_accommodations
    FROM students
    WHERE school_year = '$school_year'
    GROUP BY course_enrolled
    ORDER BY course_enrolled ASC
");
$summary = $summary_result->fetch_all(MYSQLI_ASSOC);
$total_students = array_sum(array_column($summary, 'total_students'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Upload Student Rosters | Auto AP Test Scheduler</title>
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

      <h2 class="page-title">📤 Upload Student Rosters</h2>
      <p class="page-subtitle">
        Upload College Board CSV files for each AP test. Each file will be parsed and
        students will be automatically sorted into their testing locations based on
        their accommodations.
      </p>

      <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
      <?php endif; ?>
      <?php if ($error_message): ?>
        <div class="alert alert-error"><?php echo $error_message; ?></div>
      <?php endif; ?>

      <?php if (!empty($upload_results['errors'])): ?>
        <div class="alert alert-error">
          <strong>Some rows had errors:</strong><br>
          <?php echo implode('<br>', $upload_results['errors']); ?>
        </div>
      <?php endif; ?>

      <!-- ----------------------------------------
           Upload Section
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">Upload CSV File</h3>
        <p class="section-subtitle">
          Upload one CSV file at a time. Each file should be the College Board student
          roster for a single AP test. You can upload multiple files — one per test.
        </p>
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_roster" />
          <div class="form-row">
            <div class="form-group">
              <label>Select College Board CSV File</label>
              <input type="file" name="roster_csv" accept=".csv" />
            </div>
            <div class="form-group form-group-btn">
              <button type="submit" class="btn btn-primary">📤 Upload Roster</button>
            </div>
          </div>
        </form>
      </div>

      <!-- ----------------------------------------
           Upload Summary
      ---------------------------------------- -->
      <?php if (!empty($summary)): ?>
        <div class="card-section">
          <h3 class="section-title">
            Uploaded Rosters — <?php echo $school_year; ?>
            <span class="badge badge-green"><?php echo $total_students; ?> Total Students</span>
          </h3>
          <p class="section-subtitle">
            Students have been automatically sorted into testing locations
            based on their accommodations.
          </p>
          <table class="data-table">
            <thead>
              <tr>
                <th>AP Test</th>
                <th>Total Students</th>
                <th>No Accommodations</th>
                <th>Preferential Only</th>
                <th>50% Extended Time</th>
                <th>100% Extended Time</th>
                <th>Other</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($summary as $row): ?>
                <tr>
                  <td><strong><?php echo h($row['course_enrolled']); ?></strong></td>
                  <td><span class="badge badge-green"><?php echo $row['total_students']; ?></span></td>
                  <td><?php echo $row['no_accommodations']; ?></td>
                  <td><?php echo $row['preferential_only']; ?></td>
                  <td><?php echo $row['extended_50']; ?></td>
                  <td><?php echo $row['extended_100']; ?></td>
                  <td><?php echo $row['other_accommodations']; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <!-- Clear Roster Button -->
          <div class="form-actions" style="margin-top: 1.5rem; justify-content: flex-start;">
            <form method="POST" onsubmit="return confirm('Are you sure you want to clear ALL student rosters for <?php echo $school_year; ?>? This cannot be undone.')">
              <input type="hidden" name="action" value="clear_roster" />
              <button type="submit" class="btn btn-danger">
                🗑️ Clear All Rosters for <?php echo $school_year; ?>
              </button>
            </form>
          </div>
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