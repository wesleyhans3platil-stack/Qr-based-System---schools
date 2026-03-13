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
$conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`");
$conn->select_db(DB_NAME);
$conn->set_charset("utf8mb4");

// ══════════════════════════════════════════════════════════════════
// SCHEMA INITIALIZATION — runs once per deploy, tracked in DB
// Uses a DB-based flag instead of filesystem lock file because
// Railway containers are ephemeral (filesystem resets on every deploy).
// The DB persists across deploys, so this flag survives.
// ══════════════════════════════════════════════════════════════════
$needsInit = true;
$tableCheck = $conn->query("SHOW TABLES LIKE 'system_settings'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $needsInit = false;
}
if ($needsInit) {
    initializeSchema($conn);
}

// Always ensure required seed data exists (lightweight checks)
seedRequiredData($conn);

// Ensure required schema upgrades (for existing installs)
// - Add students.active_from if missing (used by dashboard/absence logic)
mysqli_report(MYSQLI_REPORT_OFF);
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'active_from'");
if (!$col_check || $col_check->num_rows == 0) {
    $conn->query("ALTER TABLE students ADD COLUMN active_from DATE DEFAULT NULL AFTER created_at");
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
 * Store an uploaded file in the database for persistence across Railway deploys.
 * @param string $relativePath e.g. 'assets/uploads/logos/system_logo_123.png'
 * @param string $absolutePath Full filesystem path to the file
 */
function storeFileInDB($relativePath, $absolutePath) {
    global $conn;
    if (!file_exists($absolutePath)) return false;
    $data = file_get_contents($absolutePath);
    if ($data === false) return false;
    $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
    $size = filesize($absolutePath);
    $stmt = $conn->prepare("INSERT INTO file_storage (file_path, mime_type, file_data, file_size) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE mime_type=VALUES(mime_type), file_data=VALUES(file_data), file_size=VALUES(file_size), updated_at=NOW()");
    $null = null;
    $stmt->bind_param("ssbi", $relativePath, $mime, $null, $size);
    $stmt->send_long_data(2, $data);
    return $stmt->execute();
}

/**
 * Remove a file from the database storage.
 */
function removeFileFromDB($relativePath) {
    global $conn;
    $stmt = $conn->prepare("DELETE FROM file_storage WHERE file_path = ?");
    $stmt->bind_param("s", $relativePath);
    return $stmt->execute();
}

/**
 * Restore all files from database to filesystem (called once after each deploy).
 * Only restores files that are missing on the filesystem.
 */
function restoreFilesFromDB($conn) {
    $baseDir = __DIR__ . '/../';
    $result = $conn->query("SELECT file_path, file_data FROM file_storage");
    if (!$result) return;
    while ($row = $result->fetch_assoc()) {
        $fullPath = $baseDir . $row['file_path'];
        if (!file_exists($fullPath)) {
            $dir = dirname($fullPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($fullPath, $row['file_data']);
        }
    }
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
        guardian_contact VARCHAR(20) DEFAULT NULL,
        qr_code VARCHAR(255) NOT NULL UNIQUE,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        active_from DATE DEFAULT NULL,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
        FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE CASCADE,
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add active_from column to existing installs if missing
    mysqli_report(MYSQLI_REPORT_OFF);
    $col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'active_from'");
    if (!$col_check || $col_check->num_rows == 0) {
        $conn->query("ALTER TABLE students ADD COLUMN active_from DATE DEFAULT NULL AFTER created_at");
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // Add guardian_contact column if missing (for existing installs)
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("ALTER TABLE students ADD COLUMN guardian_contact VARCHAR(20) DEFAULT NULL AFTER section_id");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
        last_scan DATETIME DEFAULT NULL,
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
            ('time_in_start', '07:00:00'),
            ('time_in_end', '11:30:00'),
            ('time_out_start', '13:00:00'),
            ('time_out_end', '16:00:00')");
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
        type ENUM('regular','special','suspension') DEFAULT 'regular',
        school_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add 'type' column if missing (migration for existing deployments)
    $col_check = $conn->query("SHOW COLUMNS FROM holidays LIKE 'type'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE holidays ADD COLUMN type ENUM('regular','special','suspension') DEFAULT 'regular' AFTER name");
    }

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

    // ─── FILE STORAGE (persist uploads across Railway deploys) ───
    $conn->query("CREATE TABLE IF NOT EXISTS file_storage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_path VARCHAR(500) NOT NULL UNIQUE,
        mime_type VARCHAR(100) NOT NULL,
        file_data LONGBLOB NOT NULL,
        file_size INT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

/**
 * Seed required data — runs on every request but uses lightweight checks.
 * Only inserts rows that don't already exist (safe to call repeatedly).
 */
function seedRequiredData($conn) {
    // Use a filesystem flag to avoid running DB checks on every single request.
    // This flag resets on redeploy (ephemeral container), which is fine — it just
    // means the seed check runs once after each deploy.
    $seedFlag = sys_get_temp_dir() . '/qr_seed_done';
    if (file_exists($seedFlag)) return;

    // ─── Restore uploaded files from DB (logos, images) ───
    // On Railway, the filesystem resets on every deploy. Files stored in the
    // database are restored to the filesystem so existing <img> tags keep working.
    $tableExists = $conn->query("SHOW TABLES LIKE 'file_storage'");
    if ($tableExists && $tableExists->num_rows > 0) {
        restoreFilesFromDB($conn);
    }

    // ─── Run migrations for existing databases ───
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("ALTER TABLE students ADD COLUMN guardian_contact VARCHAR(20) DEFAULT NULL AFTER section_id");
    $conn->query("ALTER TABLE sections ADD COLUMN track VARCHAR(50) DEFAULT NULL AFTER name");
    $conn->query("ALTER TABLE attendance ADD COLUMN last_scan DATETIME DEFAULT NULL");

    // ─── Update time settings to correct AM/PM schedule ───
    $conn->query("UPDATE time_settings SET setting_value='07:00:00' WHERE setting_name='time_in_start'");
    $conn->query("UPDATE time_settings SET setting_value='11:30:00' WHERE setting_name='time_in_end'");
    $conn->query("UPDATE time_settings SET setting_value='13:00:00' WHERE setting_name='time_out_start'");
    $conn->query("UPDATE time_settings SET setting_value='16:00:00' WHERE setting_name='time_out_end'");
    $col_check = $conn->query("SHOW COLUMNS FROM holidays LIKE 'type'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE holidays ADD COLUMN type ENUM('regular','special','suspension') DEFAULT 'regular' AFTER name");
    }
    // file_storage table (needed if initializeSchema didn't run)
    $conn->query("CREATE TABLE IF NOT EXISTS file_storage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_path VARCHAR(500) NOT NULL UNIQUE,
        mime_type VARCHAR(100) NOT NULL,
        file_data LONGBLOB NOT NULL,
        file_size INT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // ─── Seed Sipalay City Schools (adds any missing ones) ───
    $schoolsToSeed = [
        'Agripino Alvarez Elementary School',
        'Banag Elementary School',
        'Barangay V Elementary School',
        'Barasbarasan Elementary School',
        'Bawog Elementary School',
        'Binotusan Elementary School',
        'Binulig Elementary School',
        'Bungabunga Elementary School',
        'Cabadiangan Elementary School',
        'Calangcang Elementary School',
        'Calat-an Elementary School',
        'Cambogui-ot Elementary School',
        'Camindangan Elementary School',
        'Cansauro Elementary School',
        'Cantaca Elementary School',
        'Canturay Elementary School',
        'Cartagena Elementary School',
        'Cayhagan Elementary School',
        'Crossing Tanduay Elementary School',
        'Genaro P. Alvarez Elementary School',
        'Genaro P. Alvarez Elementary School II',
        'Gil M. Montilla Elementary School',
        'Hda. Maricalum Elementary School',
        'Manlucahoc Elementary School',
        'Maricalum Elementary School',
        'Nabulao Elementary School',
        'Nauhang Primary School',
        'Patag Magbanua Elementary School',
        'Dungga Integrated School',
        'Dung-i Integrated School',
        'Macarandan Integrated School',
        'Mauboy Integrated School',
        'Omas Integrated School',
        'Tugas Integrated School',
        'Vista Alegre Integrated School',
        'Cambogui-ot National High School',
        'Camindangan National High School',
        'Cayhagan National High School',
        'Gil Montilla National High School',
        'Jacinto Montilla Memorial National High School',
        'Leodegario Ponce Gonzales National High School',
        'Mariano Gemora National High School',
        'Maricalum Farm School',
        'Nabulao National High School',
        'Sipalay City National High School',
    ];

    // Get next available code number
    $code_r = $conn->query("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_num FROM schools WHERE code LIKE 'SCH-%'");
    $nextNum = ($code_r && $row = $code_r->fetch_assoc()) ? (int)$row['max_num'] + 1 : 1;

    $stmtCheck = $conn->prepare("SELECT id FROM schools WHERE name = ?");
    $stmtIns = $conn->prepare("INSERT INTO schools (name, code, status) VALUES (?, ?, 'active')");
    foreach ($schoolsToSeed as $sName) {
        $stmtCheck->bind_param("s", $sName);
        $stmtCheck->execute();
        $stmtCheck->store_result();
        if ($stmtCheck->num_rows === 0) {
            $code = 'SCH-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
            $stmtIns->bind_param("ss", $sName, $code);
            $stmtIns->execute();
            $nextNum++;
        }
    }

    @file_put_contents($seedFlag, date('Y-m-d H:i:s'));
}
?>
