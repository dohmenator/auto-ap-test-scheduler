-- ================================================
-- Auto AP Test Scheduler - Database Schema
-- Viera High School
-- ================================================

CREATE DATABASE IF NOT EXISTS ap_scheduler;
USE ap_scheduler;

-- ------------------------------------------------
-- Users Table (optional login functionality)
-- Ready to activate if coordinator wants login
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('coordinator', 'admin') DEFAULT 'coordinator',
    active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------
-- Testing Period Table
-- Stores the 2-week AP testing window each year
-- Days 8, 9, 10 = last 3 days of testing period
-- Teachers whose test falls on days 8-10 get
-- assigned to proctor days 1, 2, or 3
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS testing_period (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_year VARCHAR(9) NOT NULL UNIQUE,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------
-- AP Tests Table
-- Stores each AP test, its date, and room assignments
-- Room assignments persist year to year
-- Only test_date needs updating each year
-- is_last_3_days: flags tests on last 4 days of testing window
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS ap_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_name VARCHAR(100) NOT NULL UNIQUE,
    test_date DATE,
    test_time ENUM('8AM', '12PM') DEFAULT NULL,
    main_location VARCHAR(100) NOT NULL,
    accommodations_location VARCHAR(100) NOT NULL,
    overflow_location VARCHAR(100) DEFAULT 'Media Center',
    testing_day_number TINYINT,
    is_last_3_days TINYINT(1) DEFAULT 0,
    school_year VARCHAR(9),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------
-- AP Teachers Table
-- Stores teachers and the AP course they teach
-- Teachers cannot proctor their own subject
-- active flag allows easy year-to-year updates
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS ap_teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_name VARCHAR(100) NOT NULL,
    test_name VARCHAR(100) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (test_name) REFERENCES ap_tests(test_name)
);

-- ------------------------------------------------
-- Students Table
-- Uploaded each year via CSV per AP test
-- Cleared and reloaded each school year
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    school_code VARCHAR(20),
    grade VARCHAR(20),
    email VARCHAR(150),
    ap_id VARCHAR(20),
    student_id VARCHAR(20),
    course_enrolled VARCHAR(100),
    class_section_name VARCHAR(100),
    class_section_type VARCHAR(50),
    teacher_name VARCHAR(100),
    exam_date VARCHAR(50),
    accommodations TEXT,
    accommodation_type ENUM(
        'none',
        'preferential_only',
        'extended_50',
        'extended_100',
        'other'
    ) DEFAULT 'none',
    assigned_location VARCHAR(100),
    seat_number INT,
    school_year VARCHAR(9) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------
-- Proctor Assignments Table
-- Generated schedule linking teachers to tests
-- Tracks which day of the testing period
-- Enforces: teacher cannot proctor own subject
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS proctor_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_name VARCHAR(100) NOT NULL,
    test_name VARCHAR(100) NOT NULL,
    test_date DATE NOT NULL,
    testing_day_number TINYINT,
    location VARCHAR(100),
    school_year VARCHAR(9) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (test_name) REFERENCES ap_tests(test_name)
);

-- ------------------------------------------------
-- Test Room Overrides Table
-- Stores coordinator's preferred room assignments
-- per test per session date/time
-- Persists year to year
-- Checked before auto-assignment during scheduling
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS test_room_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_name VARCHAR(100) NOT NULL,
    session_date DATE NOT NULL,
    session_time ENUM('8AM', '12PM') NOT NULL,
    main_location VARCHAR(100) NOT NULL,
    accommodations_location VARCHAR(100),
    overflow_location VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_test_session (test_name, session_date, session_time)
);

-- ------------------------------------------------
-- Pre-load AP Tests with room assignments
-- Based on Viera High School seating chart document
-- test_date and school_year updated each year
-- is_last_3_days and testing_day_number calculated
-- automatically when coordinator enters test dates
-- ------------------------------------------------
INSERT IGNORE INTO ap_tests 
    (test_name, main_location, accommodations_location) 
VALUES
    ('AP Biology', 'Gym', '2-108G'),
    ('AP Human Geography', 'Gym', '4-116'),
    ('AP Government and Politics', 'Gym', '4-115'),
    ('AP English Literature', 'Gym', '4-115'),
    ('AP Physics 1', 'Gym', 'Guidance'),
    ('AP World History', 'Gym', '2-108G'),
    ('AP Statistics', 'Gym', 'Gym with Curtain'),
    ('AP US History', 'Gym', '4-112'),
    ('AP Macroeconomics', 'Gym', '2-108G'),
    ('AP Calculus AB', 'Gym', '2-108E'),
    ('AP Calculus BC', 'Gym', '2-108E'),
    ('AP Music Theory', '4-112', 'Guidance'),
    ('AP Seminar', 'Gym', '2-108G'),
    ('AP Pre-Calculus', 'Gym', '4-115'),
    ('AP Psychology', 'Gym', '4-116'),
    ('AP English Language', 'Gym', '2-108G'),
    ('AP Physics C Mechanics', 'Gym', 'Guidance'),
    ('AP Spanish Literature', 'Gym with Curtain', '2-108G'),
    ('AP Art History', 'Gym', '2-108G'),
    ('AP Spanish Language', 'Gym with Curtain', 'Guidance'),
    ('AP Computer Science Principles', 'Gym', 'Guidance'),
    ('AP Physics C Electricity and Magnetism', 'Gym', '2-108G'),
    ('AP Environmental Science', 'Gym', '4-112'),
    ('AP Computer Science A', 'Gym', 'Guidance'),
    ('AP Cybersecurity', 'Gym', 'Guidance'),
    ('AP Networking', 'Gym', 'Guidance');

-- ------------------------------------------------
-- Locations Table
-- Stores testing locations and their capacities
-- Capacity can be updated by coordinator each year
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location_name VARCHAR(100) NOT NULL UNIQUE,
    capacity INT DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO locations (location_name) VALUES
    ('Gym'),
    ('Gym with Curtain'),
    ('Media Center'),
    ('Guidance'),
    ('2-108E'),
    ('2-108G'),
    ('4-112'),
    ('4-115'),
    ('4-116');