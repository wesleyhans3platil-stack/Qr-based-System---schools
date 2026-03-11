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
$time_in_start  = $time_settings['time_in_start']  ?? '07:00:00';
$time_in_end    = $time_settings['time_in_end']    ?? '11:30:00';
$time_out_start = $time_settings['time_out_start'] ?? '13:00:00';
$time_out_end   = $time_settings['time_out_end']   ?? '16:00:00';

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

// ── After 4:00 PM: scanning closed ──
if ($current_time > $time_out_end) {
    echo json_encode([
        'success' => false,
        'error' => 'Scanning has ended for today. Time Out closed at ' . date('h:i A', strtotime($time_out_end)) . '.',
        'person' => buildPersonResponse($person, $person_type)
    ]);
    ob_end_flush(); exit;
}

// Time windows:
// Before 11:30 AM    → Time In (morning, early arrivals allowed)
// 11:30 AM - 1:00 PM → Time Out (morning dismissal / lunch)
// 1:00 PM - 4:00 PM  → Time In if no record yet (PM late arrival), otherwise Time Out
$is_morning_time_in  = ($current_time <= $time_in_end);
$is_midday_time_out  = ($current_time > $time_in_end && $current_time < $time_out_start);
$is_afternoon        = ($current_time >= $time_out_start && $current_time <= $time_out_end);

// ══════════════════════════════════════════════
// 3b. ANTI-CHEAT: 1-minute cooldown + prevent duplicate scans
// ══════════════════════════════════════════════
$existing = null;
$stmt = $conn->prepare("SELECT id, time_in, time_out, last_scan FROM attendance WHERE person_type = ? AND person_id = ? AND date = ?");
$stmt->bind_param("sis", $person_type, $person_id, $today);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    // ── 1-minute cooldown between scans ──
    if (!empty($existing['last_scan'])) {
        $last_scan_ts = strtotime($existing['last_scan']);
        $now_ts = time();
        $seconds_since = $now_ts - $last_scan_ts;
        if ($seconds_since < 60) {
            $wait = 60 - $seconds_since;
            echo json_encode([
                'success' => false,
                'error' => 'Please wait ' . $wait . ' second' . ($wait !== 1 ? 's' : '') . ' before scanning again.',
                'person' => buildPersonResponse($person, $person_type)
            ]);
            ob_end_flush(); exit;
        }
    }

    // ── Minimum 5-minute gap between Time In and Time Out ──
    // Prevents accidental double-scan from completing attendance instantly
    if (!empty($existing['time_in']) && empty($existing['time_out'])) {
        $time_in_ts = strtotime($today . ' ' . $existing['time_in']);
        $now_ts = time();
        $minutes_since_in = ($now_ts - $time_in_ts) / 60;
        if ($minutes_since_in < 5) {
            $wait_min = ceil(5 - $minutes_since_in);
            echo json_encode([
                'success' => false,
                'error' => 'Time In recorded at ' . date('h:i A', strtotime($existing['time_in'])) . '. Please wait ' . $wait_min . ' minute' . ($wait_min !== 1 ? 's' : '') . ' before scanning Time Out.',
                'person' => buildPersonResponse($person, $person_type)
            ]);
            ob_end_flush(); exit;
        }
    }

    // ── Block duplicate Time In (already scanned in this morning) ──
    if ($is_morning_time_in && !empty($existing['time_in'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Already timed in at ' . date('h:i A', strtotime($existing['time_in'])) . '. Cannot scan Time In again.',
            'person' => buildPersonResponse($person, $person_type)
        ]);
        ob_end_flush(); exit;
    }

    // ── Block duplicate Time Out (already scanned out at midday) ──
    if ($is_midday_time_out && !empty($existing['time_out'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Already timed out at ' . date('h:i A', strtotime($existing['time_out'])) . '. Cannot scan Time Out again.',
            'person' => buildPersonResponse($person, $person_type)
        ]);
        ob_end_flush(); exit;
    }

    // ── Afternoon: block if already has both Time In and Time Out ──
    if ($is_afternoon && !empty($existing['time_in']) && !empty($existing['time_out'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Already completed attendance today (In: ' . date('h:i A', strtotime($existing['time_in'])) . ', Out: ' . date('h:i A', strtotime($existing['time_out'])) . ').',
            'person' => buildPersonResponse($person, $person_type)
        ]);
        ob_end_flush(); exit;
    }
}

// ══════════════════════════════════════════════
// 4. RECORD ATTENDANCE — uses INSERT … ON DUPLICATE KEY UPDATE
//    The UNIQUE KEY (person_type, person_id, date) handles race conditions
//    and eliminates the need for a separate SELECT check
// ══════════════════════════════════════════════
$action = '';
$message = '';
$status_value = 'present';

if ($is_morning_time_in) {
    // ═══ 7:00 AM - 11:30 AM: TIME IN ═══
    $stmt = $conn->prepare(
        "INSERT INTO attendance (person_type, person_id, school_id, date, time_in, status, last_scan)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), status = VALUES(status), last_scan = NOW()"
    );
    $stmt->bind_param("siisss", $person_type, $person_id, $school_id, $today, $current_time, $status_value);
    if ($stmt->execute()) {
        $action = 'TIME_IN';
        $message = 'Time In recorded successfully!';
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to record attendance.']);
        ob_end_flush(); exit;
    }
    $stmt->close();

} elseif ($is_midday_time_out) {
    // ═══ 11:30 AM - 1:00 PM: TIME OUT (morning dismissal / lunch) ═══
    $stmt = $conn->prepare("SELECT id, time_in FROM attendance WHERE person_type = ? AND person_id = ? AND date = ?");
    $stmt->bind_param("sis", $person_type, $person_id, $today);
    $stmt->execute();
    $attendance = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$attendance) {
        $late_status = 'late';
        $stmt = $conn->prepare(
            "INSERT INTO attendance (person_type, person_id, school_id, date, time_in, time_out, status, last_scan)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE time_out = VALUES(time_out), last_scan = NOW()"
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
        $stmt = $conn->prepare("UPDATE attendance SET time_out = ?, last_scan = NOW() WHERE id = ?");
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

} elseif ($is_afternoon) {
    // ═══ 1:00 PM - 4:00 PM: TIME IN if no record, TIME OUT if already timed in ═══
    $stmt = $conn->prepare("SELECT id, time_in, time_out FROM attendance WHERE person_type = ? AND person_id = ? AND date = ?");
    $stmt->bind_param("sis", $person_type, $person_id, $today);
    $stmt->execute();
    $attendance = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$attendance) {
        // No record yet — PM late arrival, record as Time In
        $late_status = 'late';
        $stmt = $conn->prepare(
            "INSERT INTO attendance (person_type, person_id, school_id, date, time_in, status, last_scan)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), last_scan = NOW()"
        );
        $stmt->bind_param("siisss", $person_type, $person_id, $school_id, $today, $current_time, $late_status);
        if ($stmt->execute()) {
            $action = 'TIME_IN';
            $message = 'Afternoon Time In recorded (late arrival).';
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to record attendance.']);
            ob_end_flush(); exit;
        }
        $stmt->close();
    } else {
        // Already has record — Time Out
        $stmt = $conn->prepare("UPDATE attendance SET time_out = ?, last_scan = NOW() WHERE id = ?");
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
