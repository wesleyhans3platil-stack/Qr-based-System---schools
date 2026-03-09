<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * ABSENCE NOTIFICATION CHECKER — Run via cron or manual trigger
 * ══════════════════════════════════════════════════════════════════
 * Checks for students absent 2+ consecutive school days and sends
 * push notifications to all subscribed admins.
 *
 * CRON SETUP (run daily at 10:00 AM, after morning scanning):
 *   0 10 * * 1-5 php /path/to/api/check_absences_notify.php
 *
 * MANUAL TRIGGER:
 *   GET /api/check_absences_notify.php?key=YOUR_SECRET_KEY
 *   (Set the key in system_settings: 'cron_secret_key')
 *
 * Can also be triggered from admin dashboard button.
 */

// Allow both CLI and web access
if (php_sapi_name() !== 'cli') {
    session_start();
    // Web access requires either admin login or secret key
    require_once '../config/database.php';
    require_once '../config/school_days.php';
    require_once '../config/web_push.php';

    $conn = getDBConnection();

    $is_admin = isset($_SESSION['admin_id']);
    $key_match = false;

    if (!empty($_GET['key'])) {
        $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='cron_secret_key'");
        if ($r && $row = $r->fetch_assoc()) {
            $key_match = ($_GET['key'] === $row['setting_value']);
        }
    }

    if (!$is_admin && !$key_match) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    header('Content-Type: application/json');
} else {
    // CLI mode
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/school_days.php';
    require_once __DIR__ . '/../config/web_push.php';
    $conn = getDBConnection();
}

$today = date('Y-m-d');

// ── Check if today is a school day ──
if (!isSchoolDay($today, $conn)) {
    $msg = "Not a school day — skipping absence check.";
    if (php_sapi_name() === 'cli') { echo $msg . "\n"; } else { echo json_encode(['skipped' => true, 'message' => $msg]); }
    exit;
}

// ── Find the previous school day (skip weekends/holidays) ──
$yesterday = date('Y-m-d', strtotime('-1 day'));
$max_lookback = 7;
while (!isSchoolDay($yesterday, $conn) && $max_lookback > 0) {
    $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($yesterday)));
    $max_lookback--;
}

// ── Current time check — only notify after 9:30 AM (give time for late arrivals) ──
$current_time = date('H:i:s');
if (php_sapi_name() !== 'cli' && $current_time < '09:30:00') {
    echo json_encode(['skipped' => true, 'message' => 'Too early — wait until after 9:30 AM for accurate absence data.']);
    exit;
}

// ── Find students absent BOTH today AND previous school day ──
$sql = "SELECT s.id, s.lrn, s.name, sch.name as school_name, sch.code as school_code,
        gl.name as grade_name, sec.name as section_name
    FROM students s
    LEFT JOIN schools sch ON s.school_id = sch.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.status = 'active'
    AND s.id NOT IN (
        SELECT DISTINCT person_id FROM attendance
        WHERE person_type='student' AND date='$today' AND time_in IS NOT NULL
    )
    AND s.id NOT IN (
        SELECT DISTINCT person_id FROM attendance
        WHERE person_type='student' AND date='$yesterday' AND time_in IS NOT NULL
    )
    ORDER BY sch.name, gl.id, s.name";

$r = $conn->query($sql);
$flagged = [];
if ($r) {
    while ($row = $r->fetch_assoc()) $flagged[] = $row;
}

$count = count($flagged);

if ($count === 0) {
    $msg = "No students flagged for 2-day consecutive absence.";
    if (php_sapi_name() === 'cli') { echo $msg . "\n"; } else { echo json_encode(['success' => true, 'flagged' => 0, 'message' => $msg]); }
    exit;
}

// ── Build notification payload ──
// Group by school for summary
$bySchool = [];
foreach ($flagged as $f) {
    $school = $f['school_code'] ?? 'Unknown';
    if (!isset($bySchool[$school])) $bySchool[$school] = 0;
    $bySchool[$school]++;
}

$schoolSummary = [];
foreach ($bySchool as $code => $num) {
    $schoolSummary[] = "$code: $num";
}

$title = "⚠️ $count Students Absent 2+ Days";
$body = "Flagged: " . implode(', ', $schoolSummary) . ". Tap to view dashboard.";

if ($count <= 5) {
    // If few students, show names
    $names = array_map(fn($f) => $f['name'] . ' (' . ($f['school_code'] ?? '') . ')', array_slice($flagged, 0, 5));
    $body = implode(', ', $names);
}

$payload = [
    'title' => $title,
    'body'  => $body,
    'icon'  => 'assets/icons/icon-192.svg',
    'badge' => 'assets/icons/icon-192.svg',
    'url'   => 'app_dashboard.php',
    'tag'   => 'absence-flag-' . $today,
    'data'  => [
        'type'   => 'absence_flag',
        'date'   => $today,
        'count'  => $count,
        'url'    => 'app_dashboard.php'
    ]
];

// ── Send push notifications ──
$result = sendPushToAll($payload);

// ── Log the check ──
$log_msg = "Checked $today: $count flagged, {$result['sent']} notifications sent, {$result['failed']} failed, {$result['cleaned']} stale removed.";

if (php_sapi_name() === 'cli') {
    echo $log_msg . "\n";
    foreach (array_slice($flagged, 0, 10) as $f) {
        echo "  - {$f['name']} ({$f['school_code']} / {$f['grade_name']})\n";
    }
    if ($count > 10) echo "  ... and " . ($count - 10) . " more\n";
} else {
    echo json_encode([
        'success' => true,
        'flagged' => $count,
        'notifications' => $result,
        'message' => $log_msg,
        'sample'  => array_slice($flagged, 0, 10)
    ]);
}
