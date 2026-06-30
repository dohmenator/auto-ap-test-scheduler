<?php
// Database connection
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
$school_year = date('Y') . '-' . (date('Y') + 1);

// ------------------------------------------------
// Handle Testing Period Form Submission
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_period') {
        $school_year_input = $conn->real_escape_string($_POST['school_year']);
        $period_start = $conn->real_escape_string($_POST['period_start']);
        $period_end = $conn->real_escape_string($_POST['period_end']);

        // Validate period dates
        $parsed_start = DateTime::createFromFormat('Y-m-d', $period_start);
        $parsed_end = DateTime::createFromFormat('Y-m-d', $period_end);

        if (!$parsed_start || !$parsed_end) {
            $error_message = "Invalid date format. Please use the date picker.";
        } elseif ((int)$parsed_start->format('Y') < 2020 || (int)$parsed_start->format('Y') > 2040) {
            $error_message = "Invalid year detected in start date. Please check.";
        } elseif ((int)$parsed_end->format('Y') < 2020 || (int)$parsed_end->format('Y') > 2040) {
            $error_message = "Invalid year detected in end date. Please check.";
        } elseif ($parsed_start >= $parsed_end) {
            $error_message = "End date must be after start date.";
        } elseif ($parsed_start->diff($parsed_end)->days > 14) {
            $error_message = "Testing window cannot exceed 14 days.";
        } else {
            $sql = "INSERT INTO testing_period 
                        (school_year, period_start, period_end)
                    VALUES 
                        ('$school_year_input', '$period_start', '$period_end')
                    ON DUPLICATE KEY UPDATE 
                        period_start = '$period_start',
                        period_end = '$period_end'";

            if ($conn->query($sql)) {
                // Recalculate day numbers for existing test dates
                $conn->query("UPDATE ap_tests SET 
                    testing_day_number = DATEDIFF(test_date, '$period_start') + 1,
                    is_last_3_days = CASE 
                        WHEN DATEDIFF('$period_end', test_date) < 4 THEN 1 
                        ELSE 0 
                    END
                    WHERE test_date IS NOT NULL");
                $success_message = "Testing period saved successfully!";
            } else {
                $error_message = "Error saving testing period: " . $conn->error;
            }
        }
    }

    if ($_POST['action'] === 'save_dates') {
        $errors = [];

        // Fetch testing period for validation
        $period_check = $conn->query("
            SELECT period_start, period_end 
            FROM testing_period 
            WHERE school_year = '$school_year' 
            LIMIT 1
        ");
        $period_data = $period_check->fetch_assoc();
        $period_start = $period_data['period_start'] ?? null;
        $period_end = $period_data['period_end'] ?? null;

        foreach ($_POST['test_date'] as $test_id => $test_date) {
            $test_id = (int)$test_id;
            $test_time = $conn->real_escape_string($_POST['test_time'][$test_id] ?? '');

            // Skip empty dates
            if (empty($test_date)) {
                $sql = "UPDATE ap_tests SET 
                            test_date = NULL,
                            test_time = NULL
                        WHERE id = $test_id";
                $conn->query($sql);
                continue;
            }

            // Validate date format
            $parsed_date = DateTime::createFromFormat('Y-m-d', $test_date);
            if (!$parsed_date) {
                $errors[] = "Invalid date format: $test_date — please use YYYY-MM-DD.";
                continue;
            }

            // Validate year is reasonable (between 2020 and 2040)
            $year = (int)$parsed_date->format('Y');
            if ($year < 2020 || $year > 2040) {
                $test_name_result = $conn->query("SELECT test_name FROM ap_tests WHERE id = $test_id");
                $test_name_row = $test_name_result->fetch_assoc();
                $errors[] = "Invalid year '$year' detected for '{$test_name_row['test_name']}'. Please check your dates.";
                continue;
            }

            // Validate date is within the testing window
            if (!empty($period_start) && !empty($period_end)) {
                $test_timestamp = $parsed_date->getTimestamp();
                $start_timestamp = strtotime($period_start);
                $end_timestamp = strtotime($period_end);

                if ($test_timestamp < $start_timestamp || $test_timestamp > ($end_timestamp + 86400 - 1)) {
                    $test_name_result = $conn->query("SELECT test_name FROM ap_tests WHERE id = $test_id");
                    $test_name_row = $test_name_result->fetch_assoc();
                    // Save it anyway even though it's outside the window
                    $test_date_clean = $conn->real_escape_string($test_date);
                    $time_value = !empty($test_time) ? "'$test_time'" : "NULL";
                    $conn->query("UPDATE ap_tests SET 
    test_date = '$test_date_clean',
    test_time = $time_value
    WHERE id = $test_id");
                    $errors[] = "⚠️ Warning: Date '$test_date' for '{$test_name_row['test_name']}' is outside the testing window. Saved anyway.";
                    continue;
                }
            }

            $test_date_clean = $conn->real_escape_string($test_date);
            $time_value = !empty($test_time) ? "'$test_time'" : "NULL";

            $sql = "UPDATE ap_tests SET 
                        test_date = '$test_date_clean',
                        test_time = $time_value
                    WHERE id = $test_id";
            if (!$conn->query($sql)) {
                $errors[] = $conn->error;
            }
        }

        // Recalculate day numbers if testing period exists
        $period = $conn->query("SELECT * FROM testing_period WHERE school_year = '$school_year' LIMIT 1")->fetch_assoc();
        if ($period) {
            $period_start = $period['period_start'];
            $period_end = $period['period_end'];
            $conn->query("UPDATE ap_tests SET 
                testing_day_number = DATEDIFF(test_date, '$period_start') + 1,
                is_last_3_days = CASE 
                    WHEN DATEDIFF('$period_end', test_date) < 4 THEN 1 
                    ELSE 0 
                END
                WHERE test_date IS NOT NULL");
        }

        if (empty($errors)) {
            $success_message = "Test dates saved successfully!";
        } else {
            // Check if any are hard errors vs warnings
            $hard_errors = array_filter($errors, fn ($e) => strpos($e, '⚠️') === false);
            if (empty($hard_errors)) {
                $success_message = "Test dates saved successfully!";
                $error_message = implode("<br>", $errors);
            } else {
                $error_message = implode("<br>", $errors);
            }
        }
    }
}

// ------------------------------------------------
// Fetch current testing period
// ------------------------------------------------
$period_result = $conn->query("SELECT * FROM testing_period WHERE school_year = '$school_year' LIMIT 1");
$period = $period_result->fetch_assoc();

// ------------------------------------------------
// Fetch all AP tests ordered by date
// ------------------------------------------------
$tests_result = $conn->query("SELECT * FROM ap_tests ORDER BY 
    test_date ASC, 
    CASE test_time 
        WHEN '8AM' THEN 1 
        WHEN '12PM' THEN 2 
        ELSE 3 
    END ASC,
    test_name ASC");
$tests = $tests_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Testing Period | Auto AP Test Scheduler</title>
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

      <h2 class="page-title">📅 Testing Period Setup</h2>
      <p class="page-subtitle">Set the 2-week AP testing window and assign dates to each test for <strong><?php echo $school_year; ?></strong>.</p>

      <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
      <?php endif; ?>

      <?php if ($error_message): ?>
        <div class="alert alert-error"><?php echo $error_message; ?></div>
      <?php endif; ?>

      <!-- ----------------------------------------
           Testing Window Section
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">2-Week Testing Window</h3>
        <form method="POST">
          <input type="hidden" name="action" value="save_period"/>
          <input type="hidden" name="school_year" value="<?php echo $school_year; ?>"/>
          <div class="form-row">
            <div class="form-group">
              <label for="period_start">First Day of Testing</label>
              <input
                type="date"
                id="period_start"
                name="period_start"
                value="<?php echo $period ? $period['period_start'] : ''; ?>"
                required/>
            </div>
            <div class="form-group">
              <label for="period_end">Last Day of Testing</label>
              <input
                type="date"
                id="period_end"
                name="period_end"
                value="<?php echo $period ? $period['period_end'] : ''; ?>"
                required/>
            </div>
            <div class="form-group form-group-btn">
              <button type="submit" class="btn btn-primary">Save Testing Window</button>
            </div>
          </div>
        </form>

        <?php if ($period): ?>
          <div class="period-info">
            <span class="badge badge-green">
              Current Window:
              <?php echo date('M j, Y', strtotime($period['period_start'])); ?>
              →
              <?php echo date('M j, Y', strtotime($period['period_end'])); ?>
            </span>
          </div>
        <?php endif; ?>
      </div>

      <!-- ----------------------------------------
           Test Dates Section
      ---------------------------------------- -->
      <div class="card-section">
        <h3 class="section-title">AP Test Dates</h3>
        <p class="section-subtitle">
          Assign a date to each AP test and click <strong>Save Test Dates</strong>. 
          Tests will automatically sort by date and tests falling on the last 3 days 
          of the window will be flagged. 
          <em>Scroll down to see the updated list after saving.</em>
        </p>

        <form method="POST">
          <input type="hidden" name="action" value="save_dates"/>
          <table class="data-table">
            <thead>
              <tr>
                <th>AP Test</th>
                <th>Test Date</th>
                <th>Test Time</th>
                <th>Day #</th>
                <th>Last 3 Days?</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tests as $test): ?>
              <tr>
                <td><?php echo htmlspecialchars($test['test_name']); ?></td>
                <td>
                  <input
                    type="date"
                    name="test_date[<?php echo $test['id']; ?>]"
                    value="<?php echo $test['test_date'] ?? ''; ?>"/>
                </td>
                <td>
                  <select name="test_time[<?php echo $test['id']; ?>]">
                    <option value="">—</option>
                    <option value="8AM" <?php echo ($test['test_time'] ?? '') === '8AM' ? 'selected' : ''; ?>>8 AM</option>
                    <option value="12PM" <?php echo ($test['test_time'] ?? '') === '12PM' ? 'selected' : ''; ?>>12 PM</option>
                  </select>
                </td>
                <td>
                  <?php echo $test['testing_day_number'] ? 'Day ' . $test['testing_day_number'] : '—'; ?>
                </td>
                <td>
                  <?php if ($test['is_last_3_days']): ?>
                    <span class="badge badge-gold">⚠️ Last 4 Days</span>
                  <?php else: ?>
                    <span class="badge badge-gray">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Test Dates</button>
          </div>
        </form>
      </div>

    </div>
  </main>

  <footer>
    <p>Viera High School &copy; <?php echo date('Y'); ?> — AP Testing Coordinator Portal</p>
  </footer>

  <script>
// Highlight fields with invalid years
document.addEventListener('DOMContentLoaded', function() {
    const dateInputs = document.querySelectorAll('input[type="date"]');
    
    dateInputs.forEach(function(input) {
        input.addEventListener('blur', function() {
            const value = this.value;
            if (!value) return;
            
            const year = parseInt(value.split('-')[0]);
            
            if (year < 2020 || year > 2040) {
                // Highlight the field
                this.style.border = '2px solid #dc3545';
                this.style.backgroundColor = '#fff0f0';
                this.style.boxShadow = '0 0 0 3px rgba(220,53,69,0.25)';
                
                // Show inline error message
                let errorMsg = this.nextElementSibling;
                if (!errorMsg || !errorMsg.classList.contains('field-error')) {
                    errorMsg = document.createElement('div');
                    errorMsg.classList.add('field-error');
                    this.parentNode.insertBefore(errorMsg, this.nextSibling);
                }
                errorMsg.textContent = '⚠️ Invalid year: ' + year;
                
                // Focus back on the field
                this.focus();
            } else {
                // Reset styling if valid
                this.style.border = '';
                this.style.backgroundColor = '';
                this.style.boxShadow = '';
                
                const errorMsg = this.nextElementSibling;
                if (errorMsg && errorMsg.classList.contains('field-error')) {
                    errorMsg.remove();
                }
            }
        });
    });
});
</script>

</body>
</html>
<?php $conn->close(); ?>