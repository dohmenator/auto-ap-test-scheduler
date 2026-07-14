<?php
// ================================================
// Database Connection
// Auto AP Test Scheduler — Viera High School
// ================================================

require_once __DIR__ . '/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set security headers on every page
set_security_headers();

// ------------------------------------------------
// Database connection
// ------------------------------------------------
$host = 'db';
$dbname = 'ap_scheduler';
$user = 'apuser';
$password = 'appassword';

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset('utf8mb4');

// ------------------------------------------------
// Prepared Statement Helper
// Makes prepared statements easier to use
// Usage:
//   $rows = db_query($conn, 
//     "SELECT * FROM ap_tests WHERE test_name = ?",
//     "s", [$test_name]
//   );
// ------------------------------------------------
function db_query($conn, $sql, $types = '', $params = []) {
    if (empty($params)) {
        $result = $conn->query($sql);
        if ($result === false) {
            error_log("DB Query Error: " . $conn->error . " SQL: " . $sql);
            return [];
        }
        if ($result === true) return true;
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("DB Prepare Error: " . $conn->error . " SQL: " . $sql);
        return [];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result === false) return true;
    return $result->fetch_all(MYSQLI_ASSOC);
}

function db_execute($conn, $sql, $types = '', $params = []) {
    if (empty($params)) {
        return $conn->query($sql);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("DB Prepare Error: " . $conn->error . " SQL: " . $sql);
        return false;
    }

    $stmt->bind_param($types, ...$params);
    return $stmt->execute();
}

// ------------------------------------------------
// School year helper
// ------------------------------------------------
function get_school_year() {
    return date('Y') . '-' . (date('Y') + 1);
}