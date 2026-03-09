<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * DATABASE CONNECTION — Optimized for 800 concurrent scanner laptops
 * ══════════════════════════════════════════════════════════════════
 *
 * Schema setup (CREATE TABLE, CREATE INDEX, seed data) only runs ONCE
 * when the lock file doesn't exist. After first run, every request
 * just gets a fast DB connection — no DDL overhead.
 *
 * To force schema re-initialization (e.g., after DB changes):
 *   Delete the file: config/.db_initialized
 */

// Set timezone to Philippines (Asia/Manila)
date_default_timezone_set('Asia/Manila');

// ══════════════════════════════════════════════════════════════════
// DATABASE CONFIGURATION — auto-detects Railway.app vs local XAMPP
// ══════════════════════════════════════════════════════════════════
if (getenv('MYSQL_URL') || getenv('DATABASE_URL')) {
    // ── RAILWAY.APP ENVIRONMENT ──
    // Railway provides MySQL connection via environment variables
    $db_url = getenv('MYSQL_URL') ?: getenv('DATABASE_URL');
    $parsed = parse_url($db_url);
    define('DB_HOST', $parsed['host'] . ':' . ($parsed['port'] ?? 3306));
    define('DB_USER', $parsed['user']);
    define('DB_PASS', $parsed['pass']);
    define('DB_NAME', ltrim($parsed['path'], '/'));
} elseif (getenv('MYSQLHOST')) {
    // ── RAILWAY.APP (individual env vars format) ──
    $railway_host = getenv('MYSQLHOST');
    $railway_port = getenv('MYSQLPORT') ?: '3306';
    define('DB_HOST', $railway_host . ':' . $railway_port);
    define('DB_USER', getenv('MYSQLUSER') ?: 'root');
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
    define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');
} else {
    // ── LOCAL XAMPP ENVIRONMENT ──
    // 'p:' prefix enables persistent connections for local high concurrency testing
    define('DB_HOST', 'p:localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'qr_attendance_db');
}

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists (lightweight check)
$conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
$conn->select_db(DB_NAME);
$conn->set_charset("utf8mb4");

// ══════════════════════════════════════════════════════════════════
// SCHEMA INITIALIZATION — only runs when lock file is missing
// This prevents 22+ DDL queries from running on every single request
// from all 800 scanner laptops (saves ~17,600 queries per page refresh)
// ══════════════════════════════════════════════════════════════════
$lockFile = __DIR__ . '/.db_initialized';
if (!file_exists($lockFile)) {
    initializeSchema($conn);
    // Create lock file so DDL won't run again
    @file_put_contents($lockFile, date('Y-m-d H:i:s') . ' - Schema initialized');
}

// Store connection globally
$GLOBALS['db_conn'] = $conn;

function getDBConnection() {
    return $GLOBALS['db_conn'];
}

function sanitize($data) {
    global $conn;
    return $conn->real_escape_string(trim($data));
}

function formatTime($time) {
    if (empty($time)) return '--:--';
    return date('h:i A', strtotime($time));
}

function formatDate($date) {
    if (empty($date)) return '--';
    return date('F j, Y', strtotime($date));
}

/**
 * Get a single system setting by key (cached after first call).
 */
function getSetting($key) {
    static $cache = null;
    global $conn;
    if ($cache === null) {
        $cache = [];
        $r = $conn->query("SELECT setting_key, setting_value FROM system_settings");
        if ($r) { while ($row = $r->fetch_assoc()) $cache[$row['setting_key']] = $row['setting_value']; }
    }
    return $cache[$key] ?? '';
}

/**
 * Get all time settings (cached per request — avoids querying on every scan).
 */
function getTimeSettings() {
    static $ts_cache = null;
    global $conn;
    if ($ts_cache === null) {
        $ts_cache = [];
        $r = $conn->query("SELECT setting_name, setting_value FROM time_settings");
        if ($r) { while ($row = $r->fetch_assoc()) $ts_cache[$row['setting_name']] = $row['setting_value']; }
    }
    return $ts_cache;
}

/**
 * ══════════════════════════════════════════════════════════════════
 * SCHEMA INITIALIZATION FUNCTION
 * Only called once when config/.db_initialized lock file is missing.
 * Creates all tables, indexes, and seed data.
 * To re-initialize: delete config/.db_initialized
 * ══════════════════════════════════════════════════════════════════
 */
function initializeSchema($conn) {

    // ─── SCHOOLS ───
    $conn->query("CREATE TABLE IF NOT EXISTS schools (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        code VARCHAR(20) NOT NULL UNIQUE,
        address TEXT,
        contact_number VARCHAR(20),
        logo VARCHAR(255) DEFAULT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─── GRADE LEVELS ───
    $conn->query("CREATE TABLE IF NOT EXISTS grade_levels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed grade levels if empty
    $gl_check = $conn->query("SELECT COUNT(*) as cnt FROM grade_levels");
    if ($gl_check && $gl_check->fetch_assoc()['cnt'] == 0) {
        $conn->query("INSERT INTO grade_levels (name) VALUES
            ('Kindergarten'),('Grade 1'),('Grade 2'),('Grade 3'),('Grade 4'),('Grade 5'),('Grade 6'),
            ('Grade 7'),('Grade 8'),('Grade 9'),('Grade 10'),('Grade 11'),('Grade 12')");
    }

    // ─── TEACHERS ───
    $conn->query("CREATE TABLE IF NOT EXISTS teachers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        school_id INT NOT NULL,
        contact_number VARCHAR(20),
        qr_code VARCHAR(255) NOT NULL UNIQUE,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─── SECTIONS ───
    $conn->query("CREATE TABLE IF NOT EXISTS sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL,
        grade_level_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        track VARCHAR(50) DEFAULT NULL,
        adviser_id INT DEFAULT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
        FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE CASCADE,
        FOREIGN KEY (adviser_id) REFERENCES teachers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add track column if missing (for existing installs)
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("ALTER TABLE sections ADD COLUMN track VARCHAR(50) DEFAULT NULL AFTER name");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // ─── STUDENTS ───
    $conn->query("CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lrn VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        school_id INT NOT NULL,
        grade_level_id INT NOT NULL,
        section_id INT NOT NULL,
        qr_code VARCHAR(255) NOT NULL UNIQUE,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
        FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE CASCADE,
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─── ATTENDANCE ───
    $conn->query("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        person_type ENUM('student','teacher') NOT NULL,
        person_id INT NOT NULL,
        school_id INT NOT NULL,
        date DATE NOT NULL,
        time_in TIME DEFAULT NULL,
        time_out TIME DEFAULT NULL,
        status ENUM('present','late','absent') DEFAULT 'present',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_attendance (person_type, person_id, date),
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─── ADMINS ───
    $conn->query("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(100) DEFAULT NULL,
        role ENUM('super_admin','superintendent','asst_superintendent','principal') NOT NULL DEFAULT 'super_admin',
        school_id INT DEFAULT NULL,
        contact_number VARCHAR(20),
        temp_password TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add temp_password column if missing (for existing installs)
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("ALTER TABLE admins ADD COLUMN IF NOT EXISTS temp_password TINYINT(1) NOT NULL DEFAULT 0");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // ─── SYSTEM SETTINGS ───
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed system settings if empty
    $ss_check = $conn->query("SELECT COUNT(*) as cnt FROM system_settings");
    if ($ss_check && $ss_check->fetch_assoc()['cnt'] == 0) {
        $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES
            ('sds_mobile', ''),
            ('asds_mobile', ''),
            ('sds_name', ''),
            ('asds_name', ''),
            ('sms_api_key', ''),
            ('division_name', 'Division of Sipalay City'),
            ('system_name', 'SDO-Sipalay City Attendance Monitoring System')");
    }

    // ─── TIME SETTINGS ───
    $conn->query("CREATE TABLE IF NOT EXISTS time_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_name VARCHAR(50) NOT NULL UNIQUE,
        setting_value TIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed time settings if empty
    $ts_check = $conn->query("SELECT COUNT(*) as cnt FROM time_settings");
    if ($ts_check && $ts_check->fetch_assoc()['cnt'] == 0) {
        $conn->query("INSERT INTO time_settings (setting_name, setting_value) VALUES
            ('time_in_start', '06:00:00'),
            ('time_in_end', '09:00:00'),
            ('time_out_start', '16:00:00'),
            ('time_out_end', '18:00:00')");
    }

    // ─── SMS LOGS ───
    $conn->query("CREATE TABLE IF NOT EXISTS sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipient_type VARCHAR(50),
        recipient_name VARCHAR(150),
        phone_number VARCHAR(20),
        message TEXT,
        student_id INT DEFAULT NULL,
        status ENUM('sent','failed','pending') DEFAULT 'pending',
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─── ABSENCE FLAGS ───
    $conn->query("CREATE TABLE IF NOT EXISTS absence_flags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        consecutive_days INT NOT NULL DEFAULT 2,
        flag_date DATE NOT NULL,
        notified TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─── HOLIDAYS ───
    $conn->query("CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL,
        name VARCHAR(200) NOT NULL,
        school_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─── PUSH SUBSCRIPTIONS (Web Push Notifications) ───
    $conn->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─── PUSH NOTIFICATION LOG ───
    $conn->query("CREATE TABLE IF NOT EXISTS push_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT DEFAULT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT,
        status ENUM('sent','failed') DEFAULT 'sent',
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ═══ PERFORMANCE INDEXES for 20,000+ students / 800 concurrent scanners ═══
    // Temporarily disable exception mode so duplicate index errors are silently ignored
    $prev_report = mysqli_report(MYSQLI_REPORT_OFF);
    $indexes = [
        "CREATE INDEX idx_students_qr_status ON students (qr_code, status)",
        "CREATE INDEX idx_teachers_qr_status ON teachers (qr_code, status)",
        "CREATE INDEX idx_attendance_lookup ON attendance (person_type, person_id, date)",
        "CREATE INDEX idx_attendance_date ON attendance (date)",
        "CREATE INDEX idx_attendance_school_date ON attendance (school_id, date)",
        "CREATE INDEX idx_holidays_date ON holidays (holiday_date, school_id)"
    ];
    foreach ($indexes as $sql) {
        $conn->query($sql); // Silently skips if index already exists
    }
    mysqli_report($prev_report ?? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // ─── Seed default super admin if empty (password: admin123) ───
    $admin_check = $conn->query("SELECT COUNT(*) as cnt FROM admins");
    if ($admin_check && $admin_check->fetch_assoc()['cnt'] == 0) {
        $defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $conn->query("INSERT INTO admins (username, password, full_name, email, role) VALUES
            ('admin', '$defaultPassword', 'System Administrator', 'admin@sipalay.edu.ph', 'super_admin')");
    }
}
?>
