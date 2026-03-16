<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * APP ABSENCE CHECK API — For Android push notifications
 * ══════════════════════════════════════════════════════════════════
 * Called periodically by the Android app's AbsenceCheckWorker.
 * Returns the count of students and teachers absent 2+ consecutive
 * school days, with summaries for notification display.
 *
 * Authentication: Uses the session cookie from the app.
 * Method: GET
 * Response: JSON
 * ══════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once '../config/database.php';
require_once '../config/school_days.php';

$conn = getDBConnection();
$today = date('Y-m-d');

// Check if today is a school day
if (!isSchoolDay($today, $conn)) {
    $reason = '';
    if (function_exists('getNonSchoolDayReason')) {
        $reason = getNonSchoolDayReason($today, $conn) ?? 'Non-school day';
    }
    echo json_encode([
        'success' => false,
        'skipped' => true,
        'message' => "Not a school day" . ($reason ? " — $reason" : "")
    ]);
    exit;
}

// Only after 9:30 AM for accurate data
$current_time = date('H:i:s');
if ($current_time < '09:30:00') {
    echo json_encode([
        'success' => false,
        'skipped' => true,
        'message' => 'Too early — wait until after 9:30 AM.'
    ]);
    exit;
}

// Find previous school day
$yesterday = date('Y-m-d', strtotime('-1 day'));
$max_lookback = 7;
while (!isSchoolDay($yesterday, $conn) && $max_lookback > 0) {
    $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($yesterday)));
    $max_lookback--;
}

// ── Students absent 2 consecutive school days ──
$sql_students = "SELECT s.id, s.name, sch.name as school_name, sch.code as school_code,
        gl.name as grade_name, sec.name as section_name
    FROM students s
    LEFT JOIN schools sch ON s.school_id = sch.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.status = 'active'
    AND s.school_id NOT IN (
        SELECT COALESCE(school_id, 0) FROM holidays 
        WHERE holiday_date = ? AND school_id IS NOT NULL
    )
    AND s.id NOT IN (
        SELECT DISTINCT person_id FROM attendance
        WHERE person_type='student' AND date=? AND time_in IS NOT NULL
    )
    AND s.id NOT IN (
        SELECT DISTINCT person_id FROM attendance
        WHERE person_type='student' AND date=? AND time_in IS NOT NULL
    )
    ORDER BY sch.name, s.name";

$stmt = $conn->prepare($sql_students);
$stmt->bind_param("sss", $today, $today, $yesterday);
$stmt->execute();
$result = $stmt->get_result();

$absent_students = [];
while ($row = $result->fetch_assoc()) {
    $absent_students[] = $row;
}

// ── Teachers absent 2 consecutive school days ──
$sql_teachers = "SELECT t.id, t.name, t.employee_id, sch.name as school_name, sch.code as school_code
    FROM teachers t
    LEFT JOIN schools sch ON t.school_id = sch.id
    WHERE t.status = 'active'
    AND t.school_id NOT IN (
        SELECT COALESCE(school_id, 0) FROM holidays 
        WHERE holiday_date = ? AND school_id IS NOT NULL
    )
    AND t.id NOT IN (
        SELECT DISTINCT person_id FROM attendance
        WHERE person_type='teacher' AND date=? AND time_in IS NOT NULL
    )
    AND t.id NOT IN (
        SELECT DISTINCT person_id FROM attendance
        WHERE person_type='teacher' AND date=? AND time_in IS NOT NULL
    )
    ORDER BY sch.name, t.name";

$stmt_t = $conn->prepare($sql_teachers);
$stmt_t->bind_param("sss", $today, $today, $yesterday);
$stmt_t->execute();
$result_t = $stmt_t->get_result();

$absent_teachers = [];
while ($row = $result_t->fetch_assoc()) {
    $absent_teachers[] = $row;
}

// ── Build summaries ──
$student_count = count($absent_students);
$teacher_count = count($absent_teachers);

// Student summary by school
$student_by_school = [];
foreach ($absent_students as $s) {
    $code = $s['school_code'] ?? 'Unknown';
    if (!isset($student_by_school[$code])) $student_by_school[$code] = 0;
    $student_by_school[$code]++;
}
$student_summary_parts = [];
foreach ($student_by_school as $code => $num) {
    $student_summary_parts[] = "$code: $num";
}
$student_summary = implode(', ', $student_summary_parts);

// If few students, show names instead
if ($student_count > 0 && $student_count <= 5) {
    $names = array_map(function($s) {
        return $s['name'] . ' (' . ($s['school_code'] ?? '') . ')';
    }, $absent_students);
    $student_summary = implode(', ', $names);
}

// Teacher summary by school
$teacher_by_school = [];
foreach ($absent_teachers as $t) {
    $code = $t['school_code'] ?? 'Unknown';
    if (!isset($teacher_by_school[$code])) $teacher_by_school[$code] = 0;
    $teacher_by_school[$code]++;
}
$teacher_summary_parts = [];
foreach ($teacher_by_school as $code => $num) {
    $teacher_summary_parts[] = "$code: $num";
}
$teacher_summary = implode(', ', $teacher_summary_parts);

// If few teachers, show names
if ($teacher_count > 0 && $teacher_count <= 5) {
    $names = array_map(function($t) {
        return $t['name'] . ' (' . ($t['school_code'] ?? '') . ')';
    }, $absent_teachers);
    $teacher_summary = implode(', ', $names);
}

// ── Response ──
echo json_encode([
    'success' => true,
    'date' => $today,
    'previous_school_day' => $yesterday,
    'absent_students' => $student_count,
    'absent_teachers' => $teacher_count,
    'student_summary' => $student_summary,
    'teacher_summary' => $teacher_summary,
    'student_details' => array_slice($absent_students, 0, 20), // max 20 for payload size
    'teacher_details' => array_slice($absent_teachers, 0, 20)
]);
