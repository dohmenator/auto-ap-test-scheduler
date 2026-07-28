<?php require_once __DIR__ . '/db.php'; ?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Auto AP Test Scheduler | Viera High School</title>
  <link rel="stylesheet" href="css/styles.css" />
</head>

<body>

  <header>
    <div class="header-inner">
      <div class="header-title">
        <h1>Auto AP Test Scheduler</h1>
        <p>Viera High School — AP Testing Coordinator Portal</p>
      </div>
    </div>
  </header>

  <main>
    <div class="dashboard">

      <div class="section-label">Setup & Configuration</div>
      <div class="card-grid">

        <a href="pages/testing-period.php" class="card">
          <div class="card-icon">📅</div>
          <div class="card-content">
            <h2>Testing Period</h2>
            <p>Set the 2-week AP testing window and update test dates for the current school year.</p>
          </div>
        </a>

        <a href="pages/manage-tests.php" class="card">
          <div class="card-icon">📋</div>
          <div class="card-content">
            <h2>Manage AP Tests</h2>
            <p>View and edit AP test room assignments and accommodation locations.</p>
          </div>
        </a>

        <a href="pages/manage-teachers.php" class="card">
          <div class="card-icon">👩‍🏫</div>
          <div class="card-content">
            <h2>Manage Teachers</h2>
            <p>Add, edit, or deactivate AP teachers and their assigned courses.</p>
          </div>
        </a>

      </div>

      <div class="section-label">Data Entry</div>
      <div class="card-grid">

        <a href="pages/upload-rosters.php" class="card">
          <div class="card-icon">📤</div>
          <div class="card-content">
            <h2>Upload Student Rosters</h2>
            <p>Upload College Board CSV files for each AP test.</p>
          </div>
        </a>

      </div>

      <div class="section-label">Generate & Export</div>
      <div class="card-grid">

        <a href="pages/generate-schedule.php" class="card">
          <div class="card-icon">🗓️</div>
          <div class="card-content">
            <h2>Generate Proctor Schedule</h2>
            <p>Automatically assign proctors to AP tests based on scheduling rules.</p>
          </div>
        </a>

        <a href="pages/generate-seating.php" class="card">
          <div class="card-icon">💺</div>
          <div class="card-content">
            <h2>Generate Seating Charts</h2>
            <p>Create seating charts for each AP test with accommodation sorting.</p>
          </div>
        </a>

        <a href="pages/downloads.php" class="card">
          <div class="card-icon">⬇️</div>
          <div class="card-content">
            <h2>View & Download</h2>
            <p>View generated schedules and download CSV files for Google Sheets.</p>
          </div>
        </a>

      </div>

    </div>
  </main>

  <footer>
    <p>Viera High School &copy; <?php echo date('Y'); ?> — AP Testing Coordinator Portal</p>
  </footer>
  <?php include __DIR__ . '/../spinner.php'; ?>
</body>

</html>