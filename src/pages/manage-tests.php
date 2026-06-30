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

// ------------------------------------------------
// Handle Form Submissions
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Save room assignment edits
    if ($_POST['action'] === 'save_rooms') {
        $errors = [];
        foreach ($_POST['test'] as $test_id => $data) {
            $test_id = (int)$test_id;
            $main_location = $conn->real_escape_string($data['main_location']);
            $accommodations_location = $conn->real_escape_string($data['accommodations_location']);
            $overflow_location = $conn->real_escape_string($data['overflow_location']);

            $sql = "UPDATE ap_tests SET 
                        main_location = '$main_location',
                        accommodations_location = '$accommodations_location',
                        overflow_location = '$overflow_location'
                    WHERE id = $test_id";

            if (!$conn->query($sql)) {
                $errors[] = $conn->error;
            }
        }
        if (empty($errors)) {
            $success_message = "Room assignments saved successfully!";
        } else {
            $error_message = "Some room assignments could not be saved.";
        }
    }

    // Add new AP test
    if ($_POST['action'] === 'add_test') {
        $test_name = $conn->real_escape_string(trim($_POST['test_name']));
        $main_location = $conn->real_escape_string(trim($_POST['main_location']));
        $accommodations_location = $conn->real_escape_string(trim($_POST['accommodations_location']));
        $overflow_location = $conn->real_escape_string(trim($_POST['overflow_location']));

        if (empty($test_name) || empty($main_location) || empty($accommodations_location)) {
            $error_message = "Test name, main location, and accommodations location are required.";
        } else {
            $sql = "INSERT INTO ap_tests 
                        (test_name, main_location, accommodations_location, overflow_location)
                    VALUES 
                        ('$test_name', '$main_location', '$accommodations_location', '$overflow_location')";
            if ($conn->query($sql)) {
                $success_message = "New AP test added successfully!";
            } else {
                $error_message = "Error adding test. It may already exist.";
            }
        }
    }
}

// ------------------------------------------------
// Fetch all AP tests
// ------------------------------------------------
$tests_result = $conn->query("SELECT * FROM ap_tests ORDER BY test_name ASC");
$tests = $tests_result->fetch_all(MYSQLI_ASSOC);

// Get unique locations for datalist suggestions
$locations_result = $conn->query("
    SELECT DISTINCT main_location as location FROM ap_tests
    UNION
    SELECT DISTINCT accommodations_location FROM ap_tests
    ORDER BY location ASC
");
$locations = $locations_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage AP Tests | Auto AP Test Scheduler</title>
  <link rel="stylesheet" href="../css/styles.css"/>
  <link rel="stylesheet" href="../css/pages.css"/>
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

      <h2 class="page-title">📋 Manage AP Tests</h2>
      <p class="page-subtitle">View and edit room assignments for each AP test. Changes persist year to year.</p>

      <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
      <?php endif; ?>
      <?php if ($error_message): ?>
        <div class="alert alert-error"><?php echo $error_message; ?></div>
      <?php endif; ?>

      <!-- Datalist for location suggestions -->
      <datalist id="locations">
        <?php foreach ($locations as $loc): ?>
          <option value="<?php echo htmlspecialchars($loc['location']); ?>">
        <?php endforeach; ?>
      </datalist>

      <!-- ----------------------------------------
           Add New Test Section
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">Add New AP Test</h3>
        <form method="POST">
          <input type="hidden" name="action" value="add_test"/>
          <div class="form-row">
            <div class="form-group">
              <label>Test Name</label>
              <input 
                type="text" 
                name="test_name" 
                placeholder="e.g. AP Economics"
                style="min-width:220px"/>
            </div>
            <div class="form-group">
              <label>Main Location</label>
              <input 
                type="text" 
                name="main_location" 
                placeholder="e.g. Gym"
                list="locations"/>
            </div>
            <div class="form-group">
              <label>Accommodations Location</label>
              <input 
                type="text" 
                name="accommodations_location" 
                placeholder="e.g. 2-108G"
                list="locations"/>
            </div>
            <div class="form-group">
              <label>Overflow Location</label>
              <input 
                type="text" 
                name="overflow_location" 
                placeholder="e.g. Media Center"
                list="locations"
                value="Media Center"/>
            </div>
            <div class="form-group form-group-btn">
              <button type="submit" class="btn btn-secondary">+ Add Test</button>
            </div>
          </div>
        </form>
      </div>

      <!-- ----------------------------------------
           Edit Room Assignments Section
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">Room Assignments</h3>
        <p class="section-subtitle">
          Edit any room assignment below and click <strong>Save Room Assignments</strong>. 
          Start typing a location to see suggestions from existing rooms.
        </p>

        <form method="POST">
          <input type="hidden" name="action" value="save_rooms"/>
          <table class="data-table">
            <thead>
              <tr>
                <th>AP Test</th>
                <th>Main Location</th>
                <th>Accommodations Location</th>
                <th>Overflow Location</th>
                <th>Test Date</th>
                <th>Test Time</th>
                <th>Day #</th>
                <th>Last 4 Days?</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tests as $test): ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($test['test_name']); ?></strong></td>
                <td>
                  <input 
                    type="text" 
                    name="test[<?php echo $test['id']; ?>][main_location]"
                    value="<?php echo htmlspecialchars($test['main_location']); ?>"
                    list="locations"
                    class="table-input"/>
                </td>
                <td>
                  <input 
                    type="text" 
                    name="test[<?php echo $test['id']; ?>][accommodations_location]"
                    value="<?php echo htmlspecialchars($test['accommodations_location']); ?>"
                    list="locations"
                    class="table-input"/>
                </td>
                <td>
                  <input 
                    type="text" 
                    name="test[<?php echo $test['id']; ?>][overflow_location]"
                    value="<?php echo htmlspecialchars($test['overflow_location']); ?>"
                    list="locations"
                    class="table-input"/>
                </td>
                <td>
                  <?php echo $test['test_date']
                    ? date('M j, Y', strtotime($test['test_date']))
                    : '<span class="badge badge-gray">Not set</span>'; ?>
                </td>
                <td>
                  <?php echo $test['test_time']
                    ? '<span class="badge badge-green">' . $test['test_time'] . '</span>'
                    : '<span class="badge badge-gray">Not set</span>'; ?>
                </td>
                <td>
                  <?php echo $test['testing_day_number']
                    ? 'Day ' . $test['testing_day_number']
                    : '—'; ?>
                </td>
                <td>
                  <?php if ($test['is_last_3_days']): ?>
                    <span class="badge badge-gold">⚠️ Yes</span>
                  <?php else: ?>
                    <span class="badge badge-gray">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Room Assignments</button>
          </div>
        </form>
      </div>

    </div>
  </main>

  <footer>
    <p>Viera High School &copy; <?php echo date('Y'); ?> — AP Testing Coordinator Portal</p>
  </footer>

</body>
</html>
<?php $conn->close(); ?>