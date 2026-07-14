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
  $test_name_escaped = $conn->real_escape_string($test_name);
  $test = $conn->query("
        SELECT main_location, accommodations_location 
        FROM ap_tests 
        WHERE test_name = '$test_name_escaped'
        LIMIT 1
    ")->fetch_assoc();

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

      while (($row = fgetcsv($handle)) !== false) {
        $row_number++;

        // Skip the first 5 metadata rows College Board includes
        if ($row_number <= 5) continue;

        // Row 6 should be headers
        if ($row_number === 6) {
          $headers = array_map('trim', $row);
          continue;
        }

        // Skip empty rows
        if (empty(array_filter($row))) continue;

        // Map headers to values
        $data = array_combine($headers, array_pad($row, count($headers), ''));

        // Extract fields
        $first_name = $conn->real_escape_string(trim($data['Student First Name'] ?? ''));
        $last_name = $conn->real_escape_string(trim($data['Student Last Name'] ?? ''));
        $school_code = $conn->real_escape_string(trim($data['School Code'] ?? ''));
        $grade = $conn->real_escape_string(trim($data['Grade'] ?? ''));
        $email = $conn->real_escape_string(trim($data['Email Address'] ?? ''));
        $ap_id = $conn->real_escape_string(trim($data['AP ID'] ?? ''));
        $student_id = $conn->real_escape_string(trim($data['Student ID'] ?? ''));
        $course_enrolled = $conn->real_escape_string(trim($data['Course Enrolled In'] ?? ''));
        $class_section_name = $conn->real_escape_string(trim($data['Class Section Name'] ?? ''));
        $class_section_type = $conn->real_escape_string(trim($data['Class Section Type'] ?? ''));
        $teacher_name = $conn->real_escape_string(trim($data['Teacher Name(s)'] ?? ''));
        $exam_date = $conn->real_escape_string(trim($data['Exam Date'] ?? ''));
        $accommodations = trim($data['Approved SSD Accommodations'] ?? '');

        // Skip if no name
        if (empty($first_name) && empty($last_name)) {
          $skipped++;
          continue;
        }

        // Detect accommodation type
        $accommodation_type = detectAccommodationType($accommodations);

        // Assign location
        $assigned_location = assignLocation($accommodation_type, $course_enrolled, $conn);

        $accommodations_escaped = $conn->real_escape_string($accommodations);

        // Insert student
        $sql = "INSERT INTO students (
                    first_name, last_name, school_code, grade, email,
                    ap_id, student_id, course_enrolled, class_section_name,
                    class_section_type, teacher_name, exam_date,
                    accommodations, accommodation_type, assigned_location,
                    school_year
                ) VALUES (
                    '$first_name', '$last_name', '$school_code', '$grade', '$email',
                    '$ap_id', '$student_id', '$course_enrolled', '$class_section_name',
                    '$class_section_type', '$teacher_name', '$exam_date',
                    '$accommodations_escaped', '$accommodation_type', '$assigned_location',
                    '$school_year'
                )";

        if ($conn->query($sql)) {
          $added++;
        } else {
          $errors[] = "Row $row_number: " . $conn->error;
          $skipped++;
        }
      }

      fclose($handle);

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
    $conn->query("DELETE FROM students WHERE school_year = '$school_year'");
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