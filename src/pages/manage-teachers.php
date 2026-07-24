<?php
require_once __DIR__ . '/../db.php';

$school_year = get_school_year();

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$success_message = '';
$error_message = '';
$upload_message = '';
$upload_status = '';

// ------------------------------------------------
// Handle Form Submissions
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  // Validate CSRF token
  if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    die("Invalid request. Please go back and try again.");
  }

  // Bulk upload teachers from CSV
  if ($_POST['action'] === 'bulk_upload') {
    if (isset($_FILES['teachers_csv']) && $_FILES['teachers_csv']['error'] === 0) {
      $file = $_FILES['teachers_csv']['tmp_name'];
      $handle = fopen($file, 'r');

      $added = 0;
      $skipped = 0;
      $errors = [];
      $row_number = 0;

      while (($row = fgetcsv($handle)) !== false) {
        $row_number++;

        // Skip header row
        if ($row_number === 1) continue;

        // Skip empty rows
        if (empty(array_filter($row))) continue;
        $teacher_name = sanitize_string($row[0] ?? '');
        $test_name = sanitize_string($row[1] ?? '');

        if (empty($teacher_name) || empty($test_name)) {
          $skipped++;
          continue;
        }

        // Check if test exists
        $stmt = $conn->prepare("SELECT id FROM ap_tests WHERE test_name = ?");
        $stmt->bind_param("s", $test_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
          $errors[] = "Row $row_number: Test '$test_name' not found in AP Tests.";
          $skipped++;
          continue;
        }

        // Check for duplicate
        $stmt = $conn->prepare("
    SELECT id FROM ap_teachers 
    WHERE teacher_name = ? AND test_name = ?
");
        $stmt->bind_param("ss", $teacher_name, $test_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
          $skipped++;
          continue;
        }

        // Insert teacher
        $stmt = $conn->prepare("
    INSERT INTO ap_teachers (teacher_name, test_name, active)
    VALUES (?, ?, 1)
");
        $stmt->bind_param("ss", $teacher_name, $test_name);
        if ($stmt->execute()) {
          $added++;
        } else {
          $errors[] = "Row $row_number: " . $stmt->error;
          $skipped++;
        }
      }

      fclose($handle);

      $upload_status = 'success';
      $upload_message = "Upload complete: $added teacher(s) added, $skipped skipped.";
      if (!empty($errors)) {
        $upload_message .= "<br>Issues:<br>" . implode("<br>", $errors);
        $upload_status = $added > 0 ? 'success' : 'error';
      }
    } else {
      $upload_status = 'error';
      $upload_message = "Please select a valid CSV file.";
    }
  }

  // Add new teacher
  if ($_POST['action'] === 'add_teacher') {
    $teacher_name = sanitize_string($_POST['teacher_name']);
    $test_name = sanitize_string($_POST['test_name']);

    if (empty($teacher_name) || empty($test_name)) {
      $error_message = "Teacher name and AP test are required.";
    } else {
      $stmt = $conn->prepare("
        INSERT INTO ap_teachers (teacher_name, test_name, active)
        VALUES (?, ?, 1)
    ");
      $stmt->bind_param("ss", $teacher_name, $test_name);
      if ($stmt->execute()) {
        $success_message = "Teacher added successfully!";
      } else {
        $error_message = "Error adding teacher: " . $stmt->error;
      }
    }
  }

  // Update teacher
  if ($_POST['action'] === 'update_teacher') {
    $teacher_id = (int)$_POST['teacher_id'];
    $teacher_name = sanitize_string($_POST['teacher_name']);
    $test_name = sanitize_string($_POST['test_name']);

    $stmt = $conn->prepare("
    UPDATE ap_teachers SET 
        teacher_name = ?,
        test_name = ?
    WHERE id = ?
");
    $stmt->bind_param("ssi", $teacher_name, $test_name, $teacher_id);
    if ($stmt->execute()) {
      $success_message = "Teacher updated successfully!";
    } else {
      $error_message = "Error updating teacher: " . $stmt->error;
    }
  }

  // Toggle active/inactive
  if ($_POST['action'] === 'toggle_active') {
    $teacher_id = (int)$_POST['teacher_id'];
    $current_active = (int)$_POST['current_active'];
    $new_active = $current_active ? 0 : 1;

    $sql = "UPDATE ap_teachers SET active = $new_active WHERE id = $teacher_id";
    $stmt = $conn->prepare("UPDATE ap_teachers SET active = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_active, $teacher_id);
    if ($stmt->execute()) {
      $success_message = $new_active
        ? "Teacher reactivated successfully!"
        : "Teacher deactivated successfully!";
    } else {
      $error_message = "Error updating teacher status.";
    }
  }

  // Delete teacher
  if ($_POST['action'] === 'delete_teacher') {
    $teacher_id = (int)$_POST['teacher_id'];
    $stmt = $conn->prepare("DELETE FROM ap_teachers WHERE id = ?");
    $stmt->bind_param("i", $teacher_id);
    if ($stmt->execute()) {
      $success_message = "Teacher deleted successfully!";
    } else {
      $error_message = "Error deleting teacher.";
    }
  }
}

// ------------------------------------------------
// Fetch all teachers grouped by active status
// ------------------------------------------------
$active_result = $conn->query("
    SELECT t.*, a.test_name as assigned_test 
    FROM ap_teachers t
    LEFT JOIN ap_tests a ON t.test_name = a.test_name
    WHERE t.active = 1
    ORDER BY t.teacher_name ASC
");
$active_teachers = $active_result->fetch_all(MYSQLI_ASSOC);

$inactive_result = $conn->query("
    SELECT t.*, a.test_name as assigned_test 
    FROM ap_teachers t
    LEFT JOIN ap_tests a ON t.test_name = a.test_name
    WHERE t.active = 0
    ORDER BY t.teacher_name ASC
");
$inactive_teachers = $inactive_result->fetch_all(MYSQLI_ASSOC);

// Fetch all AP tests for dropdown
$tests_result = $conn->query("SELECT test_name FROM ap_tests ORDER BY test_name ASC");
$tests = $tests_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Manage Teachers | Auto AP Test Scheduler</title>
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

      <h2 class="page-title">👩‍🏫 Manage Teachers</h2>
      <p class="page-subtitle">
        Add, edit, or deactivate AP teachers. Deactivated teachers are kept on record
        but excluded from proctor scheduling. A teacher can teach more than one AP course.
      </p>

      <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
      <?php endif; ?>
      <?php if ($error_message): ?>
        <div class="alert alert-error"><?php echo $error_message; ?></div>
      <?php endif; ?>

      <!-- ----------------------------------------
           Bulk Upload Teachers
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">Bulk Upload Teachers (CSV)</h3>
        <p class="section-subtitle">
          Upload a CSV file with the following columns:
          <strong>Teacher Name, Test Name</strong>.
          The first row should be the column headings.
          Existing teachers will not be duplicated.
        </p>

        <?php if ($upload_message): ?>
          <div class="alert alert-<?php echo $upload_status; ?>">
            <?php echo $upload_message; ?>
          </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="bulk_upload" />
          <div class="form-row">
            <div class="form-group">
              <label>Select CSV File</label>
              <input type="file" name="teachers_csv" accept=".csv" />
            </div>
            <div class="form-group form-group-btn">
              <button type="submit" class="btn btn-secondary">📤 Upload Teachers</button>
            </div>
          </div>
        </form>
      </div>

      <!-- ----------------------------------------
           Add New Teacher
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">Add New Teacher</h3>
        <form method="POST">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="add_teacher" />
          <div class="form-row">
            <div class="form-group">
              <label>Teacher Name</label>
              <input
                type="text"
                name="teacher_name"
                placeholder="e.g. Jane Smith"
                style="min-width: 220px" />
            </div>
            <div class="form-group">
              <label>AP Course They Teach</label>
              <select name="test_name" style="min-width: 220px">
                <option value="">— Select AP Test —</option>
                <?php foreach ($tests as $test): ?>
                  <option value="<?php echo h($test['test_name']); ?>">
                    <?php echo h($test['test_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group form-group-btn">
              <button type="submit" class="btn btn-secondary">+ Add Teacher</button>
            </div>
          </div>
        </form>
      </div>

      <!-- ----------------------------------------
           Active Teachers
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">
          Active Teachers
          <span class="badge badge-green"><?php echo count($active_teachers); ?></span>
        </h3>

        <?php if (empty($active_teachers)): ?>
          <p class="empty-state">No active teachers yet. Add teachers above.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Teacher Name</th>
                <th>AP Course They Teach</th>
                <th>Cannot Proctor</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($active_teachers as $teacher): ?>
                <tr>
                  <td><strong><?php echo h($teacher['teacher_name']); ?></strong></td>
                  <td><?php echo h($teacher['test_name']); ?></td>
                  <td>
                    <span class="badge badge-gold">
                      ⛔ <?php echo h($teacher['test_name']); ?>
                    </span>
                  </td>
                  <td>
                    <div class="action-btns">
                      <button
                        class="btn btn-small btn-secondary"
                        onclick="toggleEdit(<?php echo $teacher['id']; ?>)">
                        ✏️ Edit
                      </button>
                      <form method="POST" style="display:inline">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="toggle_active" />
                        <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>" />
                        <input type="hidden" name="current_active" value="1" />
                        <button
                          type="submit"
                          class="btn btn-small btn-danger"
                          onclick="return confirm('Deactivate <?php echo h($teacher['teacher_name']); ?>?')">
                          Deactivate
                        </button>
                      </form>
                    </div>

                    <div id="edit-<?php echo $teacher['id']; ?>" class="edit-form" style="display:none">
                      <form method="POST">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="update_teacher" />
                        <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>" />
                        <div class="form-row" style="margin-top:0.75rem">
                          <div class="form-group">
                            <label>Teacher Name</label>
                            <input
                              type="text"
                              name="teacher_name"
                              value="<?php echo h($teacher['teacher_name']); ?>" />
                          </div>
                          <div class="form-group">
                            <label>AP Course</label>
                            <select name="test_name">
                              <?php foreach ($tests as $test): ?>
                                <option
                                  value="<?php echo h($test['test_name']); ?>"
                                  <?php echo $test['test_name'] === $teacher['test_name'] ? 'selected' : ''; ?>>
                                  <?php echo h($test['test_name']); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="form-group form-group-btn">
                            <button type="submit" class="btn btn-small btn-primary">Save</button>
                          </div>
                        </div>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- ----------------------------------------
           Inactive Teachers
      ---------------------------------------- -->
      <?php if (!empty($inactive_teachers)): ?>
        <div class="card-section">
          <h3 class="section-title">
            Inactive Teachers
            <span class="badge badge-gray"><?php echo count($inactive_teachers); ?></span>
          </h3>
          <p class="section-subtitle">
            These teachers are excluded from proctor scheduling but kept on record.
          </p>
          <table class="data-table">
            <thead>
              <tr>
                <th>Teacher Name</th>
                <th>AP Course</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inactive_teachers as $teacher): ?>
                <tr class="inactive-row">
                  <td><?php echo h($teacher['teacher_name']); ?></td>
                  <td><?php echo h($teacher['test_name']); ?></td>
                  <td>
                    <div class="action-btns">
                      <form method="POST" style="display:inline">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="toggle_active" />
                        <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>" />
                        <input type="hidden" name="current_active" value="0" />
                        <button type="submit" class="btn btn-small btn-primary">
                          Reactivate
                        </button>
                      </form>
                      <form method="POST" style="display:inline">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="delete_teacher" />
                        <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>" />
                        <button
                          type="submit"
                          class="btn btn-small btn-danger"
                          onclick="return confirm('Permanently delete this teacher?')">
                          Delete
                        </button>
                      </form>
                    </div>
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

  <script>
    function toggleEdit(id) {
      const editForm = document.getElementById('edit-' + id);
      editForm.style.display = editForm.style.display === 'none' ? 'block' : 'none';
    }
  </script>

</body>

</html>
<?php $conn->close(); ?>