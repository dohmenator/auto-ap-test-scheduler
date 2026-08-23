<?php
require_once __DIR__ . '/../db.php';

$school_year = get_school_year();

// ------------------------------------------------
// Handle Downloads
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Download proctor schedule by date
    if ($_POST['action'] === 'download_by_date') {
        $result = $conn->query("
            SELECT p.test_date, a.test_time, p.test_name, 
                   p.testing_day_number, p.location,
                   GROUP_CONCAT(p.teacher_name ORDER BY p.teacher_name SEPARATOR ', ') as proctors
            FROM proctor_assignments p
            JOIN ap_tests a ON p.test_name = a.test_name
            WHERE p.school_year = '$school_year'
            GROUP BY p.test_date, a.test_time, p.test_name, p.testing_day_number, p.location
            ORDER BY p.test_date ASC,
            CASE a.test_time WHEN '8AM' THEN 1 WHEN '12PM' THEN 2 END ASC,
            p.test_name ASC
        ");

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="proctor_schedule_by_date_' .
            $school_year . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Time', 'Day #', 'AP Test', 'Location', 'Proctor(s)']);

        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                date('M j, Y', strtotime($row['test_date'])),
                $row['test_time'],
                'Day ' . $row['testing_day_number'],
                $row['test_name'],
                $row['location'],
                $row['proctors']
            ]);
        }
        fclose($output);
        exit;
    }

    // Download proctor schedule by teacher
    if ($_POST['action'] === 'download_by_teacher') {
        $result = $conn->query("
            SELECT teacher_name,
                   GROUP_CONCAT(
                       CONCAT(test_name, ' - ', location, ' (', 
                       DATE_FORMAT(test_date, '%b %e'), ')')
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

    // Download individual seating chart
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

        if ($test_info) {
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

            $getProctor = function ($location) use ($proctors_by_location) {
                if (
                    isset($proctors_by_location[$location]) &&
                    !empty($proctors_by_location[$location])
                ) {
                    return implode(' / ', $proctors_by_location[$location]);
                }
                return 'TBD';
            };
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

            $main_location = $test_info['main_location'];
            $acc_location = $test_info['accommodations_location'];
            $overflow_location = $test_info['overflow_location'] ?? 'Media Center';

            $main_students = $fetchStudents($main_location);
            $overflow_students = $fetchStudents($overflow_location);
            $acc_students = $fetchStudents($acc_location);
            $guidance_students = $fetchStudents('Guidance');

            $safe_name = str_replace([' ', '/'], '_', $test_name);
            $filename = $safe_name . '_seating_chart_' . $school_year . '.csv';

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');

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

                fputcsv($output, ['', '', '', '', '']);
                fputcsv($output, ['', '', '', '', '']);
            }

            // Late testers
            $late_result = $conn->query("
                SELECT * FROM students
                WHERE course_enrolled = '$test_name'
                AND school_year = '$school_year'
                AND (class_section_type LIKE '%late%'
                     OR class_section_type LIKE '%Late%')
                ORDER BY last_name ASC
            ");
            $late_students = $late_result->fetch_all(MYSQLI_ASSOC);

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
// Fetch summary stats
// ------------------------------------------------
$total_students = $conn->query("
    SELECT COUNT(*) as cnt FROM students 
    WHERE school_year = '$school_year'
")->fetch_assoc()['cnt'];

$total_tests_with_rosters = $conn->query("
    SELECT COUNT(DISTINCT course_enrolled) as cnt 
    FROM students 
    WHERE school_year = '$school_year'
")->fetch_assoc()['cnt'];

$total_proctor_assignments = $conn->query("
    SELECT COUNT(*) as cnt FROM proctor_assignments 
    WHERE school_year = '$school_year'
")->fetch_assoc()['cnt'];

$total_tests_scheduled = $conn->query("
    SELECT COUNT(DISTINCT test_name) as cnt 
    FROM proctor_assignments 
    WHERE school_year = '$school_year'
")->fetch_assoc()['cnt'];

// ------------------------------------------------
// Fetch proctor schedule by date (read only)
// ------------------------------------------------
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

// Build schedule view
$schedule_view = [];
foreach ($all_tests_result as $test) {
    $test_name = $test['test_name'];
    $main_students = (int)$test['main_students'];
    $overflow_count = max(0, $main_students - 200);
    $main_count = min($main_students, 200);

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

    // Main location
    $main_proctor = isset($proctors_by_location[$test['main_location']])
        ? implode(', ', $proctors_by_location[$test['main_location']])
        : 'TBD';
    $locations[] = [
        'location' => $test['main_location'],
        'student_count' => $main_count > 0 ? $main_count : null,
        'proctor' => $main_proctor,
        'type' => 'main'
    ];

    // Overflow
    if ($overflow_count > 0) {
        $overflow_loc = $test['overflow_location'] ?? 'Media Center';
        $overflow_proctor = isset($proctors_by_location[$overflow_loc])
            ? implode(', ', $proctors_by_location[$overflow_loc])
            : 'TBD';
        $locations[] = [
            'location' => $overflow_loc,
            'student_count' => $overflow_count,
            'proctor' => $overflow_proctor,
            'type' => 'overflow'
        ];
    }

    // Accommodations
    if ($test['acc_students'] > 0) {
        $acc_loc = $test['accommodations_location'];
        $acc_proctor = isset($proctors_by_location[$acc_loc])
            ? implode(', ', $proctors_by_location[$acc_loc])
            : 'TBD';
        $locations[] = [
            'location' => $acc_loc,
            'student_count' => $test['acc_students'],
            'proctor' => $acc_proctor,
            'type' => 'accommodations'
        ];
    }

    // Guidance
    if ($test['guidance_students'] > 0) {
        $guid_proctor = isset($proctors_by_location['Guidance'])
            ? implode(', ', $proctors_by_location['Guidance'])
            : 'TBD';
        $locations[] = [
            'location' => 'Guidance',
            'student_count' => $test['guidance_students'],
            'proctor' => $guid_proctor,
            'type' => 'guidance'
        ];
    }

    $schedule_view[] = [
        'test_date' => $test['test_date'],
        'test_time' => $test['test_time'],
        'test_name' => $test['test_name'],
        'testing_day_number' => $test['testing_day_number'],
        'locations' => $locations
    ];
}

// ------------------------------------------------
// Fetch seating chart summary
// ------------------------------------------------
$seating_summary = $conn->query("
    SELECT 
        s.course_enrolled,
        t.test_date,
        t.test_time,
        COUNT(s.id) as total_students,
        MAX(s.seat_number) as max_seat,
        SUM(CASE WHEN s.accommodation_type = 'none' THEN 1 ELSE 0 END) as no_acc,
        SUM(CASE WHEN s.accommodation_type = 'preferential_only' THEN 1 ELSE 0 END) as pref,
        SUM(CASE WHEN s.accommodation_type IN ('extended_50','other') THEN 1 ELSE 0 END) as acc,
        SUM(CASE WHEN s.accommodation_type = 'extended_100' THEN 1 ELSE 0 END) as guidance
    FROM students s
    JOIN ap_tests t ON s.course_enrolled = t.test_name
    WHERE s.school_year = '$school_year'
    AND s.seat_number IS NOT NULL
    GROUP BY s.course_enrolled, t.test_date, t.test_time
    ORDER BY t.test_date ASC, s.course_enrolled ASC
")->fetch_all(MYSQLI_ASSOC);

// Fetch schedule by teacher
$schedule_by_teacher = $conn->query("
    SELECT teacher_name,
           GROUP_CONCAT(
               CONCAT(test_name, ' - ', location, ' (',
               DATE_FORMAT(test_date, '%b %e'), ')')
               ORDER BY test_date SEPARATOR '; '
           ) as assignments,
           COUNT(*) as total_assignments
    FROM proctor_assignments
    WHERE school_year = '$school_year'
    GROUP BY teacher_name
    ORDER BY teacher_name ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>View & Download | Auto AP Test Scheduler</title>
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

            <h2 class="page-title">⬇️ View & Download</h2>
            <p class="page-subtitle">
                Review the generated proctor schedule and seating charts for
                <strong><?php echo $school_year; ?></strong>.
                Download CSV files to upload as Google Sheets.
            </p>

            <!-- ----------------------------------------
           Summary Stats
      ---------------------------------------- -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_tests_with_rosters; ?></div>
                    <div class="stat-label">Tests with Rosters</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_proctor_assignments; ?></div>
                    <div class="stat-label">Proctor Assignments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_tests_scheduled; ?></div>
                    <div class="stat-label">Tests Scheduled</div>
                </div>
            </div>

            <!-- ----------------------------------------
           Proctor Schedule Downloads
      ---------------------------------------- -->
            <div class="card-section">
                <h3 class="section-title">🗓️ Proctor Schedule Downloads</h3>
                <div class="action-btns">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="download_by_date" />
                        <button type="submit" class="btn btn-secondary">
                            ⬇️ Download Schedule by Date (CSV)
                        </button>
                    </form>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="download_by_teacher" />
                        <button type="submit" class="btn btn-secondary">
                            ⬇️ Download Schedule by Teacher (CSV)
                        </button>
                    </form>
                </div>
            </div>


            <!-- ----------------------------------------
           Seating Charts
      ---------------------------------------- -->
            <?php if (!empty($seating_summary)): ?>
                <div class="card-section">
                    <h3 class="section-title">💺 Seating Charts</h3>
                    <p class="section-subtitle">
                        Download individual seating chart CSV files.
                        Each file contains all testing locations for that AP test.
                    </p>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>AP Test</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Total Students</th>
                                <th>Main Room</th>
                                <th>Acc. Room</th>
                                <th>Guidance</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seating_summary as $test): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h($test['course_enrolled']); ?></strong>
                                    </td>
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
                                        <span class="badge badge-green">
                                            <?php echo $test['total_students']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $test['no_acc'] + $test['pref']; ?></td>
                                    <td><?php echo $test['acc']; ?></td>
                                    <td><?php echo $test['guidance']; ?></td>
                                    <td>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="action" value="download_seating" />
                                            <input type="hidden" name="test_name"
                                                value="<?php echo h($test['course_enrolled']); ?>" />
                                            <button type="submit" class="btn btn-small btn-secondary">
                                                ⬇️ CSV
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- ----------------------------------------
           Proctor Schedule — Read Only View
      ---------------------------------------- -->
            <?php if (!empty($schedule_view)): ?>
                <div class="card-section">
                    <h3 class="section-title">📅 Proctor Schedule by Date</h3>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $current_date = '';
                            foreach ($schedule_view as $test):
                                $is_new_date = $test['test_date'] !== $current_date;
                                $current_date = $test['test_date'];
                                $first_location = true;
                            ?>
                                <?php foreach ($test['locations'] as $loc_row):
                                    $row_style = '';
                                    if ($first_location && $is_new_date) {
                                        $row_style = 'border-top: 2px solid #1a5c1a;';
                                    } elseif ($first_location) {
                                        $row_style = 'border-top: 2px solid #ddd;';
                                    }
                                    $loc_badge = 'badge-gray';
                                    if ($loc_row['type'] === 'overflow') $loc_badge = 'badge-gold';
                                    if ($loc_row['type'] === 'guidance') $loc_badge = 'badge-green';
                                    $is_tbd = $loc_row['proctor'] === 'TBD';
                                ?>
                                    <tr style="<?php echo $row_style; ?>">
                                        <td>
                                            <?php if ($first_location && $is_new_date): ?>
                                                <strong><?php echo date('M j, Y', strtotime($test['test_date'])); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($first_location): ?>
                                                Day <?php echo $test['testing_day_number']; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($first_location): ?>
                                                <span class="badge <?php echo $test['test_time'] === '8AM'
                                                                        ? 'badge-green' : 'badge-gold'; ?>">
                                                    <?php echo $test['test_time']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($first_location): ?>
                                                <strong><?php echo h($test['test_name'] ?? ''); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $loc_badge; ?>">
                                                <?php echo h($loc_row['location'] ?? ''); ?>
                                                <?php if ($loc_row['type'] === 'overflow'): ?>
                                                    <small>(overflow)</small>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($loc_row['student_count'] > 0): ?>
                                                <span class="badge badge-green">
                                                    <?php echo $loc_row['student_count']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-gray">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_tbd): ?>
                                                <span class="badge badge-red">TBD</span>
                                            <?php else: ?>
                                                <span class="badge badge-green">
                                                    <?php echo h($loc_row['proctor'] ?? ''); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php $first_location = false; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- ----------------------------------------
           Schedule by Teacher — Read Only
      ---------------------------------------- -->
            <?php if (!empty($schedule_by_teacher)): ?>
                <div class="card-section">
                    <h3 class="section-title">👩‍🏫 Proctor Schedule by Teacher</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>Assignments</th>
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
            <?php endif; ?>


            <?php if (empty($schedule_view) && empty($seating_summary)): ?>
                <div class="card-section">
                    <p class="empty-state">
                        No schedule or seating charts generated yet.
                        Go to <a href="generate-schedule.php">Generate Proctor Schedule</a>
                        and <a href="generate-seating.php">Generate Seating Charts</a> first.
                    </p>
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