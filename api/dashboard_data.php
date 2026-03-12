<?php
/**
 * Real-time Dashboard Data API
 * Returns all dashboard stats as JSON for live polling.
 */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/school_days.php';

$conn = getDBConnection();
$today = date('Y-m-d');
$filter_date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) $filter_date = $today;
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;

$is_division = in_array($admin_role, ['super_admin', 'superintendent', 'asst_superintendent']);

$school_filter_sql = '';
if ($admin_role === 'principal' && $admin_school_id) {
    $school_filter_sql = " AND school_id = " . (int)$admin_school_id . " ";
}

$filter_school = (int)($_GET['school'] ?? 0);
$extra_filter = '';
if ($filter_school) $extra_filter .= " AND school_id = $filter_school ";

// Previous school day
$prev_school_day = date('Y-m-d', strtotime($filter_date . ' -1 day'));
for ($try = 0; $try < 10; $try++) {
    if (isSchoolDay($prev_school_day, $conn)) break;
    $prev_school_day = date('Y-m-d', strtotime($prev_school_day . ' -1 day'));
}
$yesterday = $prev_school_day;

// Summary stats
$total_schools = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM schools WHERE status='active' $school_filter_sql");
if ($r) $total_schools = $r->fetch_assoc()['cnt'];

$total_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND (DATE(created_at) < '$filter_date' OR id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)) " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = " . (int)$admin_school_id : "") . ($filter_school ? " AND school_id = $filter_school" : ""));
if ($r) $total_students = $r->fetch_assoc()['cnt'];

$total_teachers = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = " . (int)$admin_school_id : "") . ($filter_school ? " AND school_id = $filter_school" : ""));
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

$timed_in_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_in_today = $r->fetch_assoc()['cnt'];
$timed_in_today = min($timed_in_today, $total_students);
$absent_today = max(0, $total_students - $timed_in_today);
$attendance_rate = $total_students > 0 ? min(100, round(($timed_in_today / $total_students) * 100, 1)) : 0;

$timed_out_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_out IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_out_today = $r->fetch_assoc()['cnt'];

$teachers_in = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $teachers_in = $r->fetch_assoc()['cnt'];
$teachers_in = min($teachers_in, $total_teachers);
$teachers_absent = max(0, $total_teachers - $teachers_in);
$teacher_att_pct = $total_teachers > 0 ? min(100, round(($teachers_in / $total_teachers) * 100, 1)) : 0;

// 2-Day Flag count
$school_days_30 = 0;
$d = new DateTime($filter_date);
for ($i = 0; $i < 30; $i++) {
    $dd = (clone $d)->modify("-$i days");
    if ((int)$dd->format('N') < 6) $school_days_30++;
}
$h30 = $conn->query("SELECT COUNT(*) as cnt FROM holidays WHERE holiday_date BETWEEN DATE_SUB('$filter_date', INTERVAL 30 DAY) AND '$filter_date' AND DAYOFWEEK(holiday_date) NOT IN (1,7)");
if ($h30) $school_days_30 -= (int)($h30->fetch_assoc()['cnt'] ?? 0);
if ($school_days_30 < 1) $school_days_30 = 1;

$flagged_students = [];
$flag_sql = "SELECT s.id, s.lrn, s.name, sch.name as school_name, sch.code as school_code, gl.name as grade_name, sec.name as section_name,
    ($school_days_30 - (SELECT COUNT(DISTINCT a2.date) FROM attendance a2 WHERE a2.person_id = s.id AND a2.person_type='student' AND a2.time_in IS NOT NULL AND a2.date BETWEEN DATE_SUB('$filter_date', INTERVAL 30 DAY) AND '$filter_date')) as total_absent
    FROM students s
    LEFT JOIN schools sch ON s.school_id = sch.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.status = 'active'
    AND DATE(s.created_at) < '$filter_date'
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday' AND time_in IS NOT NULL)
    " . ($admin_role === 'principal' && $admin_school_id ? "AND s.school_id = " . (int)$admin_school_id : "") . "
    " . ($filter_school ? "AND s.school_id = $filter_school" : "") . "
    ORDER BY sch.name, gl.id, s.name
    LIMIT 100";
$r = $conn->query($flag_sql);
if ($r) { while ($row = $r->fetch_assoc()) $flagged_students[] = $row; }

// Per-School Breakdown
$school_breakdown = [];
$school_sql = "SELECT s.id, s.name, s.code,
    (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active' AND (DATE(st.created_at) < '$filter_date' OR st.id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL))) as enrolled,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as teachers_present,
    (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as total_teachers
    FROM schools s WHERE s.status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND s.id = " . (int)$admin_school_id : "") . "
    ORDER BY s.name";
$r = $conn->query($school_sql);
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $row['present'] = min($row['present'], $row['enrolled']);
        $row['absent'] = max(0, $row['enrolled'] - $row['present']);
        $row['rate'] = $row['enrolled'] > 0 ? min(100, round(($row['present'] / $row['enrolled']) * 100, 1)) : 0;
        $school_breakdown[] = $row;
    }
}

// Schools ranked
$schools_ranked = $school_breakdown;
usort($schools_ranked, fn($a, $b) => $b['rate'] <=> $a['rate']);

// Weekly Trend
$div_trend = [];
$td = $filter_date;
for ($count = 0; $count < 7; $count++) {
    if ($count > 0) $td = date('Y-m-d', strtotime($td . ' -1 day'));
    while (!isSchoolDay($td, $conn) && $td > date('Y-m-d', strtotime('-60 days'))) {
        $td = date('Y-m-d', strtotime($td . ' -1 day'));
    }
    $cnt = 0;
    $sf = ($admin_role === 'principal' && $admin_school_id ? " AND school_id=" . (int)$admin_school_id : "") . ($filter_school ? " AND school_id=$filter_school" : "");
    $r2 = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$td' AND a.time_in IS NOT NULL $sf");
    if ($r2) $cnt = $r2->fetch_assoc()['cnt'];
    $day_total = 0;
    $r2 = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND DATE(created_at) <= '$td'" . ($admin_role === 'principal' && $admin_school_id ? " AND school_id=" . (int)$admin_school_id : "") . ($filter_school ? " AND school_id=$filter_school" : ""));
    if ($r2) $day_total = $r2->fetch_assoc()['cnt'];
    array_unshift($div_trend, ['date' => date('M d', strtotime($td)), 'present' => $cnt, 'absent' => max(0, $day_total - $cnt)]);
    $td = date('Y-m-d', strtotime($td . ' -1 day'));
}

echo json_encode([
    'ts' => time(),
    'stats' => [
        'total_schools' => (int)$total_schools,
        'total_students' => (int)$total_students,
        'total_teachers' => (int)$total_teachers,
        'timed_in_today' => (int)$timed_in_today,
        'absent_today' => (int)$absent_today,
        'attendance_rate' => (float)$attendance_rate,
        'timed_out_today' => (int)$timed_out_today,
        'teachers_in' => (int)$teachers_in,
        'teachers_absent' => (int)$teachers_absent,
        'teacher_att_pct' => (float)$teacher_att_pct,
        'flag_count' => count($flagged_students),
    ],
    'flagged_students' => array_slice($flagged_students, 0, 100),
    'school_breakdown' => $school_breakdown,
    'schools_ranked' => array_slice($schools_ranked, 0, 10),
    'trend' => $div_trend,
]);
