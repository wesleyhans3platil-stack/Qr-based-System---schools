<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * SCAN ATTENDANCE API — Optimized for 20,000+ students / 800+ scanners
 * ══════════════════════════════════════════════════════════════════
 * Key optimizations:
 *  • Cached time_settings (1 query shared across all requests via getTimeSettings())
 *  • Indexed lookups on qr_code+status, attendance(person_type,person_id,date)
 *  • INSERT … ON DUPLICATE KEY UPDATE eliminates separate SELECT+INSERT/UPDATE
 *  • Minimal JOINs — only fetch columns needed for the response
 *  • Output buffering for faster response delivery
 */

ob_start(); // Buffer output for faster flush

require_once '../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store'); // Prevent caching of attendance results

$conn = getDBConnection();

// ── Validate request ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    ob_end_flush(); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    ob_end_flush(); exit;
}

$qr_code = trim($input['qr_code'] ?? '');
if (empty($qr_code)) {
    echo json_encode(['success' => false, 'error' => 'QR code is empty']);
    ob_end_flush(); exit;
}

$today = date('Y-m-d');
$current_time = date('H:i:s');

// ══════════════════════════════════════════════
// 1. LOOKUP PERSON — single indexed query
// ══════════════════════════════════════════════
$person_type = '';
$person = null;

if (strpos($qr_code, 'STU-') === 0) {
    $person_type = 'student';
    $person = lookupStudent($conn, $qr_code);
} elseif (strpos($qr_code, 'TCH-') === 0) {
    $person_type = 'teacher';
    $person = lookupTeacher($conn, $qr_code);
} else {
    // Unknown prefix — try student first (20,000 vs 885)
    $person = lookupStudent($conn, $qr_code);
    if ($person) {
        $person_type = 'student';
    } else {
        $person = lookupTeacher($conn, $qr_code);
        if ($person) $person_type = 'teacher';
    }
}

if (!$person) {
    echo json_encode(['success' => false, 'error' => 'QR code not recognized. Person not found.']);
    ob_end_flush(); exit;
}

$person_id = $person['id'];
$school_id = $person['school_id'];

// ══════════════════════════════════════════════
// 2. TIME SETTINGS — cached, no extra query per scan
// ══════════════════════════════════════════════
$time_settings = getTimeSettings();
$time_in_start  = $time_settings['time_in_start']  ?? '06:00:00';
$time_in_end    = $time_settings['time_in_end']    ?? '11:30:00';
$time_out_start = $time_settings['time_out_start'] ?? '13:00:00';
$time_out_end   = $time_settings['time_out_end']   ?? '16:30:00';

// ══════════════════════════════════════════════
// 3. DETERMINE SCAN WINDOW & SCHOOL DAY
// ══════════════════════════════════════════════
require_once '../config/school_days.php';
if (!isSchoolDay($today, $conn)) {
    $reason = getNonSchoolDayReason($today, $conn);
    echo json_encode([
        'success' => false,
        'error' => 'No classes today (' . $reason . '). Attendance recording is disabled.',
        'person' => buildPersonResponse($person, $person_type)
    ]);
    ob_end_flush(); exit;
}

$is_time_in_window  = ($current_time <= $time_in_end);
$is_time_out_window = ($current_time > $time_in_end && $current_time <= $time_out_end);

if (!$is_time_in_window && !$is_time_out_window) {
    // After time_out_end
    echo json_encode([
        'success' => false,
        'error' => 'Scanning has ended for today. Time Out closed at ' . date('h:i A', strtotime($time_out_end)) . '.',
        'person' => buildPersonResponse($person, $person_type)
    ]);
    ob_end_flush(); exit;
}

// ══════════════════════════════════════════════
// 4. RECORD ATTENDANCE — uses INSERT … ON DUPLICATE KEY UPDATE
//    The UNIQUE KEY (person_type, person_id, date) handles race conditions
//    and eliminates the need for a separate SELECT check
// ══════════════════════════════════════════════
$action = '';
$message = '';
$status_value = 'present';

if ($is_time_in_window) {
    // ═══ TIME IN: Use UPSERT — insert or update time_in in one atomic query ═══
    $stmt = $conn->prepare(
        "INSERT INTO attendance (person_type, person_id, school_id, date, time_in, status)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), status = VALUES(status)"
    );
    $stmt->bind_param("siisss", $person_type, $person_id, $school_id, $today, $current_time, $status_value);
    if ($stmt->execute()) {
        $action = 'TIME_IN';
        $message = ($stmt->affected_rows === 1) ? 'Time In recorded successfully!' : 'Time In updated successfully!';
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to record attendance.']);
        ob_end_flush(); exit;
    }
    $stmt->close();

} else {
    // ═══ TIME OUT: Need to check if attendance exists to decide insert vs update ═══
    // Use SELECT with only the columns we need (id, time_in) — indexed lookup
    $stmt = $conn->prepare("SELECT id, time_in FROM attendance WHERE person_type = ? AND person_id = ? AND date = ?");
    $stmt->bind_param("sis", $person_type, $person_id, $today);
    $stmt->execute();
    $attendance = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$attendance) {
        // No Time In record — create with time_out directly
        $late_status = 'late';
        $stmt = $conn->prepare(
            "INSERT INTO attendance (person_type, person_id, school_id, date, time_in, time_out, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE time_out = VALUES(time_out)"
        );
        $stmt->bind_param("siissss", $person_type, $person_id, $school_id, $today, $current_time, $current_time, $late_status);
        if ($stmt->execute()) {
            $action = 'TIME_OUT';
            $message = 'Time Out recorded (no Time In today).';
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to record attendance.']);
            ob_end_flush(); exit;
        }
        $stmt->close();
    } else {
        // Has record — UPDATE time_out only
        $stmt = $conn->prepare("UPDATE attendance SET time_out = ? WHERE id = ?");
        $stmt->bind_param("si", $current_time, $attendance['id']);
        if ($stmt->execute()) {
            $action = 'TIME_OUT';
            $message = 'Time Out recorded successfully!';
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to record Time Out.']);
            ob_end_flush(); exit;
        }
        $stmt->close();
    }
}

// ══════════════════════════════════════════════
// 5. BUILD RESPONSE
// ══════════════════════════════════════════════
$response = [
    'success' => true,
    'action'  => $action,
    'message' => $message,
    'time'    => date('h:i A', strtotime($current_time)),
    'person'  => buildPersonResponse($person, $person_type),
    'status'  => $status_value
];

if ($action === 'TIME_OUT') {
    // Re-fetch time_in for the response (only id + time_in needed)
    $stmt = $conn->prepare("SELECT time_in FROM attendance WHERE person_type = ? AND person_id = ? AND date = ?");
    $stmt->bind_param("sis", $person_type, $person_id, $today);
    $stmt->execute();
    $latest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $response['time_in']  = date('h:i A', strtotime($latest['time_in']));
    $response['time_out'] = date('h:i A', strtotime($current_time));
}

echo json_encode($response);
ob_end_flush();

// ══════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════

/**
 * Lookup student by QR code — only fetches columns needed for the response.
 * Uses idx_students_qr_status index.
 */
function lookupStudent($conn, $qr_code) {
    $stmt = $conn->prepare(
        "SELECT s.id, s.name, s.lrn, s.school_id, sch.name AS school_name,
                gl.name AS grade_name, sec.name AS section_name
         FROM students s
         LEFT JOIN schools sch ON s.school_id = sch.id
         LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
         LEFT JOIN sections sec ON s.section_id = sec.id
         WHERE s.qr_code = ? AND s.status = 'active'
         LIMIT 1"
    );
    $stmt->bind_param("s", $qr_code);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result;
}

/**
 * Lookup teacher by QR code — only fetches columns needed for the response.
 * Uses idx_teachers_qr_status index.
 */
function lookupTeacher($conn, $qr_code) {
    $stmt = $conn->prepare(
        "SELECT t.id, t.name, t.employee_id, t.school_id, sch.name AS school_name
         FROM teachers t
         LEFT JOIN schools sch ON t.school_id = sch.id
         WHERE t.qr_code = ? AND t.status = 'active'
         LIMIT 1"
    );
    $stmt->bind_param("s", $qr_code);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result;
}

/**
 * Build the person data for the JSON response.
 */
function buildPersonResponse($person, $type) {
    $data = [
        'type'   => $type,
        'name'   => $person['name'],
        'school' => $person['school_name'] ?? ''
    ];
    if ($type === 'student') {
        $data['lrn']     = $person['lrn'] ?? '';
        $data['grade']   = $person['grade_name'] ?? '';
        $data['section'] = $person['section_name'] ?? '';
    } else {
        $data['employee_id'] = $person['employee_id'] ?? '';
    }
    return $data;
}
?>
