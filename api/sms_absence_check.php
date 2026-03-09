<?php
/**
 * SMS Absence Notifier
 * 
 * Checks for students and teachers absent for 2 consecutive days
 * and sends SMS notifications to configured phone numbers.
 * 
 * Can be triggered:
 *  - Manually from Settings page ("Check & Send SMS" button)
 *  - Via cron job: php api/sms_absence_check.php
 *  - Via browser: http://localhost/.../api/sms_absence_check.php?key=YOUR_API_KEY
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/school_days.php';
$conn = getDBConnection();

// ─── Auth: Allow from CLI, session, or API key ──────────
$is_cli = (php_sapi_name() === 'cli');
$is_session = false;
$is_api = false;

if (!$is_cli) {
    session_start();
    $is_session = isset($_SESSION['admin_id']);
    
    // Also allow via API key match
    $provided_key = $_GET['key'] ?? $_POST['key'] ?? '';
    $stored_key = '';
    $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sms_api_key'");
    if ($r && $row = $r->fetch_assoc()) $stored_key = $row['setting_value'];
    $is_api = (!empty($provided_key) && !empty($stored_key) && $provided_key === $stored_key);
    
    if (!$is_session && !$is_api) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    header('Content-Type: application/json');
}

// ─── Load system settings ───────────────────────────────
$sys = [];
$r = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($r) { while ($row = $r->fetch_assoc()) $sys[$row['setting_key']] = $row['setting_value']; }

$sms_enabled = ($sys['sms_enabled'] ?? '0') === '1';
$sms_api_key = $sys['sms_api_key'] ?? '';
$notification_numbers = array_filter(array_map('trim', explode(',', $sys['notification_numbers'] ?? '')));

// Also add SDS/ASDS numbers if configured
if (!empty($sys['sds_mobile'])) $notification_numbers[] = $sys['sds_mobile'];
if (!empty($sys['asds_mobile'])) $notification_numbers[] = $sys['asds_mobile'];
$notification_numbers = array_unique(array_filter($notification_numbers));

$today = date('Y-m-d');

// Skip non-school days (weekends + holidays)
if (!isSchoolDay($today, $conn)) {
    $reason = getNonSchoolDayReason($today, $conn) ?? 'Non-school day';
    $response = ['success' => true, 'flagged' => 0, 'absent_students' => 0, 'absent_teachers' => 0, 'sms_sent' => 0, 'sms_failed' => 0, 'message' => "Skipped — $reason"];
    if ($is_cli) {
        echo "Skipped: $reason\n";
    } else {
        echo json_encode($response);
    }
    exit;
}

// Find previous school day (skipping weekends and holidays)
$yesterday = date('Y-m-d', strtotime('-1 day'));
for ($try = 0; $try < 10; $try++) {
    if (isSchoolDay($yesterday, $conn)) break;
    $yesterday = date('Y-m-d', strtotime($yesterday . ' -1 day'));
}

// ─── Find students absent 2 consecutive days ───────────
// Exclude students from schools with a school-specific holiday today
$absent_students = [];
$sql = "SELECT s.id, s.name, s.lrn, s.school_id, sch.name as school_name, gl.name as grade_name, sec.name as section_name
        FROM students s
        LEFT JOIN schools sch ON s.school_id = sch.id
        LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.status = 'active'
        AND s.school_id NOT IN (
            SELECT COALESCE(school_id, 0) FROM holidays WHERE holiday_date = ? AND school_id IS NOT NULL
        )
        AND s.id NOT IN (
            SELECT person_id FROM attendance 
            WHERE person_type = 'student' AND date = ? AND time_in IS NOT NULL
        )
        AND s.id NOT IN (
            SELECT person_id FROM attendance 
            WHERE person_type = 'student' AND date = ? AND time_in IS NOT NULL
        )";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $today, $today, $yesterday);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // Check if already notified today
    $check = $conn->prepare("SELECT id FROM absence_flags WHERE student_id = ? AND flag_date = ? AND notified = 1");
    $check->bind_param("is", $row['id'], $today);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $absent_students[] = $row;
    }
}

// ─── Find teachers absent 2 consecutive days ───────────
// Exclude teachers from schools with a school-specific holiday today
$absent_teachers = [];
$sql_t = "SELECT t.id, t.name, t.employee_id, t.school_id, sch.name as school_name
          FROM teachers t
          LEFT JOIN schools sch ON t.school_id = sch.id
          WHERE t.status = 'active'
          AND t.school_id NOT IN (
              SELECT COALESCE(school_id, 0) FROM holidays WHERE holiday_date = ? AND school_id IS NOT NULL
          )
          AND t.id NOT IN (
              SELECT person_id FROM attendance 
              WHERE person_type = 'teacher' AND date = ? AND time_in IS NOT NULL
          )
          AND t.id NOT IN (
              SELECT person_id FROM attendance 
              WHERE person_type = 'teacher' AND date = ? AND time_in IS NOT NULL
          )";

$stmt_t = $conn->prepare($sql_t);
$stmt_t->bind_param("sss", $today, $today, $yesterday);
$stmt_t->execute();
$result_t = $stmt_t->get_result();
while ($row = $result_t->fetch_assoc()) {
    $absent_teachers[] = $row;
}

$total_flagged = count($absent_students) + count($absent_teachers);
$sms_sent = 0;
$sms_failed = 0;
$sms_results = [];

if ($total_flagged === 0) {
    $response = ['success' => true, 'message' => 'No 2-day consecutive absentees found.', 'flagged' => 0, 'sms_sent' => 0];
    if ($is_cli) { echo $response['message'] . "\n"; } else { echo json_encode($response); }
    exit;
}

// ─── Flag students in absence_flags table ───────────────
foreach ($absent_students as $stu) {
    $stmt = $conn->prepare("INSERT INTO absence_flags (student_id, consecutive_days, flag_date, notified) 
                            VALUES (?, 2, ?, 0) 
                            ON DUPLICATE KEY UPDATE consecutive_days = 2");
    $stmt->bind_param("is", $stu['id'], $today);
    $stmt->execute();
}

// ─── Build SMS messages ─────────────────────────────────
$division_name = $sys['division_name'] ?? 'Division';

// Group by school for cleaner messages
$by_school = [];
foreach ($absent_students as $s) {
    $school = $s['school_name'] ?? 'Unknown School';
    $by_school[$school]['students'][] = $s['name'] . ' (' . $s['lrn'] . ', ' . ($s['grade_name'] ?? '') . '-' . ($s['section_name'] ?? '') . ')';
}
foreach ($absent_teachers as $t) {
    $school = $t['school_name'] ?? 'Unknown School';
    $by_school[$school]['teachers'][] = $t['name'] . ' (ID: ' . $t['employee_id'] . ')';
}

// Build one consolidated message
$msg_lines = [];
$msg_lines[] = "[EduTrack Alert]";
$msg_lines[] = $division_name;
$msg_lines[] = date('M j, Y');
$msg_lines[] = "";
$msg_lines[] = "2-Day Consecutive Absentees:";

foreach ($by_school as $school_name => $data) {
    $msg_lines[] = "";
    $msg_lines[] = "📍 " . $school_name;
    if (!empty($data['students'])) {
        $msg_lines[] = "Students (" . count($data['students']) . "):";
        foreach (array_slice($data['students'], 0, 10) as $name) {
            $msg_lines[] = "- " . $name;
        }
        if (count($data['students']) > 10) {
            $msg_lines[] = "  ...and " . (count($data['students']) - 10) . " more";
        }
    }
    if (!empty($data['teachers'])) {
        $msg_lines[] = "Teachers (" . count($data['teachers']) . "):";
        foreach ($data['teachers'] as $name) {
            $msg_lines[] = "- " . $name;
        }
    }
}

$msg_lines[] = "";
$msg_lines[] = "Total: " . count($absent_students) . " student(s), " . count($absent_teachers) . " teacher(s)";

$full_message = implode("\n", $msg_lines);

// ─── Send SMS if enabled ────────────────────────────────
if ($sms_enabled && !empty($sms_api_key) && !empty($sms_from_number) && !empty($notification_numbers)) {
    
    // Check if message is too long, split into summary if needed
    $message_to_send = $full_message;
    if (strlen($message_to_send) > 800) {
        // Send summary version
        $message_to_send = "[EduTrack Alert]\n";
        $message_to_send .= $division_name . "\n";
        $message_to_send .= date('M j, Y') . "\n\n";
        $message_to_send .= "2-Day Consecutive Absentees Found:\n";
        foreach ($by_school as $school_name => $data) {
            $stu_count = count($data['students'] ?? []);
            $tch_count = count($data['teachers'] ?? []);
            $message_to_send .= "- " . $school_name . ": " . $stu_count . " student(s), " . $tch_count . " teacher(s)\n";
        }
        $message_to_send .= "\nTotal: " . count($absent_students) . " student(s), " . count($absent_teachers) . " teacher(s)";
        $message_to_send .= "\n\nPlease check the system for full details.";
    }

    foreach ($notification_numbers as $number) {
        $number = preg_replace('/[^0-9+]/', '', $number);
        if (empty($number)) continue;

        // Send via Semaphore API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.semaphore.co/api/v4/messages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'apikey' => $sms_api_key,
            'number' => $number,
            'message' => $message_to_send,
            'sendername' => 'DEPED'
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $sent_ok = ($http_code >= 200 && $http_code < 300);
        $status = $sent_ok ? 'sent' : 'failed';

        // Log to sms_logs
        $log_stmt = $conn->prepare("INSERT INTO sms_logs (recipient_type, recipient_name, phone_number, message, status) VALUES ('notification', 'Configured Recipient', ?, ?, ?)");
        $log_stmt->bind_param("sss", $number, $message_to_send, $status);
        $log_stmt->execute();

        if ($sent_ok) {
            $sms_sent++;
        } else {
            $sms_failed++;
        }

        $sms_results[] = [
            'number' => $number,
            'status' => $status,
            'http_code' => $http_code,
            'error' => $curl_error ?: null
        ];
    }

    // Mark students as notified
    foreach ($absent_students as $stu) {
        $stmt = $conn->prepare("UPDATE absence_flags SET notified = 1 WHERE student_id = ? AND flag_date = ?");
        $stmt->bind_param("is", $stu['id'], $today);
        $stmt->execute();
    }

} else {
    // SMS not enabled or no numbers configured — just flag
    $sms_results[] = ['note' => 'SMS not sent — ' . 
        (!$sms_enabled ? 'SMS is disabled' : 
        (empty($sms_api_key) ? 'No API key configured' : 'No notification numbers configured'))];
}

// ─── Response ───────────────────────────────────────────
$response = [
    'success' => true,
    'message' => "$total_flagged absentee(s) found. $sms_sent SMS sent.",
    'flagged' => $total_flagged,
    'absent_students' => count($absent_students),
    'absent_teachers' => count($absent_teachers),
    'sms_sent' => $sms_sent,
    'sms_failed' => $sms_failed,
    'sms_details' => $sms_results,
    'notification_numbers' => $notification_numbers,
    'full_message' => $full_message
];

if ($is_cli) {
    echo "=== SMS Absence Check ===\n";
    echo $full_message . "\n";
    echo "\nSMS Sent: $sms_sent | Failed: $sms_failed\n";
    echo "Numbers: " . implode(', ', $notification_numbers) . "\n";
} else {
    echo json_encode($response);
}
