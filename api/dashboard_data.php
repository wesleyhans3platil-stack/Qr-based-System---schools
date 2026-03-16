<?php
/**
 * Real-time Dashboard Data API
 * Returns all dashboard stats as JSON for live polling.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// database.php handles session_start() with DB backend
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Simple short-lived cache to reduce DB load under frequent polling (e.g. mobile app).
// Cache key depends on role + school + date, so each view has separate cache.
$cacheTtl = 3; // seconds
$cacheKey = 'dashboard_' . ($_SESSION['admin_role'] ?? '') . '_' . ($_SESSION['admin_school_id'] ?? '') . '_' . ($_GET['date'] ?? date('Y-m-d')) . '_' . ($_GET['school'] ?? '');
$cacheFile = sys_get_temp_dir() . '/qr_dash_' . md5($cacheKey) . '.json';
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    readfile($cacheFile);
    exit;
}

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
// Total students (active + inactive) for overall headcount.
$studentCountSql = "SELECT COUNT(*) as cnt FROM students s " .
    ($admin_role === 'principal' && $admin_school_id ? "WHERE s.school_id = " . (int)$admin_school_id : "") .
    ($filter_school ? ($admin_role === 'principal' && $admin_school_id ? " AND" : "WHERE") . " s.school_id = $filter_school" : "");
$r = $conn->query($studentCountSql);
if ($r) $total_students = $r->fetch_assoc()['cnt'];

$total_teachers = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = " . (int)$admin_school_id : "") . ($filter_school ? " AND school_id = $filter_school" : ""));
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

$timed_in_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_in_today = $r->fetch_assoc()['cnt'];

// Only active students count towards present/absent
$active_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students st WHERE st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) " . ($admin_role === 'principal' && $admin_school_id ? " AND st.school_id = " . (int)$admin_school_id : "") . ($filter_school ? " AND st.school_id = $filter_school" : ""));
if ($r) $active_students = $r->fetch_assoc()['cnt'];

$absent_today = max(0, $active_students - $timed_in_today);
$attendance_rate = $active_students > 0 ? min(100, round(($timed_in_today / $active_students) * 100, 1)) : 0;

$timed_out_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_out IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_out_today = $r->fetch_assoc()['cnt'];

$teachers_in = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $teachers_in = $r->fetch_assoc()['cnt'];
$teachers_in = min($teachers_in, $total_teachers);
$teachers_absent = max(0, $total_teachers - $teachers_in);
$teacher_att_pct = $total_teachers > 0 ? min(100, round(($teachers_in / $total_teachers) * 100, 1)) : 0;

// 2-Day Flag count (disabled to improve response speed)
$flagged_students = [];

// Inactive students list (super admin only)
$inactive_students = [];
if ($admin_role === 'super_admin') {
    $ri = $conn->query("SELECT s.id, s.lrn, s.name, sch.name as school_name FROM students s LEFT JOIN schools sch ON s.school_id = sch.id WHERE s.status <> 'active' ORDER BY s.name LIMIT 20");
    if ($ri) while ($row = $ri->fetch_assoc()) $inactive_students[] = $row;
}

// Per-School Breakdown (optimized queries to avoid N+1 subqueries)
$school_breakdown = [];

// Base filters (role + school selection)
$schoolFilter = '';
if ($admin_role === 'principal' && $admin_school_id) {
    $schoolFilter = " WHERE s.id = " . (int)$admin_school_id;
} elseif ($filter_school) {
    $schoolFilter = " WHERE s.id = " . (int)$filter_school;
}

// 1) Get list of active schools (we want consistent ordering)
$schoolList = [];
$r = $conn->query("SELECT id, name, code FROM schools s WHERE s.status='active'" . $schoolFilter . " ORDER BY s.name");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $schoolList[$row['id']] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'code' => $row['code'],
            'enrolled' => 0,
            'present' => 0,
            'teachers_present' => 0,
            'total_teachers' => 0,
        ];
    }
}

if (!empty($schoolList)) {
    $schoolIds = implode(',', array_keys($schoolList));

    // 2) Student enrolled count per school
    $enrolledSql = "SELECT s.school_id, COUNT(*) as cnt
        FROM students s
        WHERE s.status='active'
          AND s.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12'))
          AND (DATE(s.created_at) < '$filter_date' OR s.id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL))
          AND s.school_id IN ($schoolIds)
        GROUP BY s.school_id";
    $r = $conn->query($enrolledSql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $sid = (int)$row['school_id'];
            if (isset($schoolList[$sid])) {
                $schoolList[$sid]['enrolled'] = (int)$row['cnt'];
            }
        }
    }

    // 3) Student present count per school
    $presentSql = "SELECT a.school_id, COUNT(DISTINCT a.person_id) as cnt
        FROM attendance a
        INNER JOIN students st ON a.person_type='student' AND a.person_id = st.id AND st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12'))
        WHERE a.date = '$filter_date' AND a.time_in IS NOT NULL
          AND a.school_id IN ($schoolIds)
        GROUP BY a.school_id";
    $r = $conn->query($presentSql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $sid = (int)$row['school_id'];
            if (isset($schoolList[$sid])) {
                $schoolList[$sid]['present'] = (int)$row['cnt'];
            }
        }
    }

    // 4) Teacher present count per school
    $teacherPresentSql = "SELECT a.school_id, COUNT(DISTINCT a.person_id) as cnt
        FROM attendance a
        INNER JOIN teachers t ON a.person_type='teacher' AND a.person_id = t.id AND t.status='active'
        WHERE a.date = '$filter_date' AND a.time_in IS NOT NULL
          AND a.school_id IN ($schoolIds)
        GROUP BY a.school_id";
    $r = $conn->query($teacherPresentSql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $sid = (int)$row['school_id'];
            if (isset($schoolList[$sid])) {
                $schoolList[$sid]['teachers_present'] = (int)$row['cnt'];
            }
        }
    }

    // 5) Total active teachers per school
    $teacherTotalSql = "SELECT school_id, COUNT(*) as cnt FROM teachers WHERE status='active' AND school_id IN ($schoolIds) GROUP BY school_id";
    $r = $conn->query($teacherTotalSql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $sid = (int)$row['school_id'];
            if (isset($schoolList[$sid])) {
                $schoolList[$sid]['total_teachers'] = (int)$row['cnt'];
            }
        }
    }

    // Build final breakdown array (same order as school list)
    foreach ($schoolList as $row) {
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
    $r2 = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) WHERE a.person_type='student' AND a.date='$td' AND a.time_in IS NOT NULL $sf");
    if ($r2) $cnt = $r2->fetch_assoc()['cnt'];
    $day_total = 0;
    $r2 = $conn->query("SELECT COUNT(*) as cnt FROM students st WHERE st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) AND ($student_effective_date_sub <= '$td')" . ($admin_role === 'principal' && $admin_school_id ? " AND st.school_id=" . (int)$admin_school_id : "") . ($filter_school ? " AND st.school_id=$filter_school" : ""));
    if ($r2) $day_total = $r2->fetch_assoc()['cnt'];
    array_unshift($div_trend, ['date' => date('M d', strtotime($td)), 'present' => $cnt, 'absent' => max(0, $day_total - $cnt)]);
    $td = date('Y-m-d', strtotime($td . ' -1 day'));
}

// ─── Principal-specific data ───
$principal_section_data = [];
$principal_scan_logs = [];
$principal_consecutive_absent = [];
$students_late = 0;

if ($admin_role === 'principal' && $admin_school_id) {
    $sid = (int)$admin_school_id;

    // Students late today
    $r = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='student' AND date='$filter_date' AND status='late' AND school_id = $sid");
    if ($r) $students_late = (int)$r->fetch_assoc()['cnt'];

    // Section breakdown
    $sec_sql = "SELECT sec.id, sec.name as section_name, gl.name as grade_name,
        (SELECT COUNT(*) FROM students st WHERE st.section_id = sec.id AND st.status='active') as total,
        (SELECT COUNT(DISTINCT a.person_id) FROM attendance a JOIN students st ON a.person_id = st.id WHERE st.section_id = sec.id AND a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
        (SELECT COUNT(DISTINCT a.person_id) FROM attendance a JOIN students st ON a.person_id = st.id WHERE st.section_id = sec.id AND a.person_type='student' AND a.date='$filter_date' AND a.status='late') as late_count
        FROM sections sec
        JOIN grade_levels gl ON sec.grade_level_id = gl.id
        WHERE sec.school_id = $sid AND sec.status='active'
        ORDER BY gl.id, sec.name";
    $r = $conn->query($sec_sql);
    if ($r) while ($row = $r->fetch_assoc()) $principal_section_data[] = $row;

    // Recent scan logs (last 20)
    $log_sql = "SELECT a.*, 
        CASE WHEN a.person_type='student' THEN (SELECT name FROM students WHERE id=a.person_id) ELSE (SELECT name FROM teachers WHERE id=a.person_id) END as person_name,
        CASE WHEN a.person_type='student' THEN (SELECT lrn FROM students WHERE id=a.person_id) ELSE (SELECT employee_id FROM teachers WHERE id=a.person_id) END as person_code
        FROM attendance a
        WHERE a.date='$filter_date' AND a.school_id = $sid
        ORDER BY a.created_at DESC LIMIT 20";
    $r = $conn->query($log_sql);
    if ($r) while ($row = $r->fetch_assoc()) $principal_scan_logs[] = $row;

    // 2-day consecutive absentees
    $abs_sql = "SELECT s.id, s.lrn, s.name, gl.name as grade, sec.name as section
        FROM students s
        JOIN grade_levels gl ON s.grade_level_id = gl.id
        JOIN sections sec ON s.section_id = sec.id
        WHERE s.status='active' AND s.school_id = $sid
        AND DATE(COALESCE(s.active_from, s.created_at)) < '$filter_date'
        AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)
        AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday' AND time_in IS NOT NULL)
        ORDER BY gl.name, sec.name, s.name LIMIT 100";
    $r = $conn->query($abs_sql);
    if ($r) while ($row = $r->fetch_assoc()) $principal_consecutive_absent[] = $row;
}

$payload = [
    'ts' => time(),
    'stats' => [
        'total_schools' => (int)$total_schools,
        'total_students' => (int)$total_students,
        'total_teachers' => (int)$total_teachers,
        'timed_in_today' => (int)$timed_in_today,
        'students_present' => (int)$timed_in_today,
        'absent_today' => (int)$absent_today,
        'students_absent' => (int)$absent_today,
        'attendance_rate' => (float)$attendance_rate,
        'att_pct' => (float)$attendance_rate,
        'timed_out_today' => (int)$timed_out_today,
        'teachers_in' => (int)$teachers_in,
        'teachers_present' => (int)$teachers_in,
        'teachers_absent' => (int)$teachers_absent,
        'teacher_att_pct' => (float)$teacher_att_pct,
        'flag_count' => count($flagged_students),
        'inactive_students_count' => count($inactive_students),
        'inactive_students' => count($inactive_students),
        'students_late' => (int)$students_late,
    ],
    'flagged_students' => $flagged_students,
    'inactive_students' => $inactive_students,
    'school_breakdown' => $school_breakdown,
    'schools_ranked' => array_slice($schools_ranked, 0, 10),
    'trend' => $div_trend,
];

// Add principal-specific data
if ($admin_role === 'principal' && $admin_school_id) {
    $payload['section_data'] = $principal_section_data;
    $payload['scan_logs'] = $principal_scan_logs;
    $payload['consecutive_absent'] = $principal_consecutive_absent;
}

$json = json_encode($payload);
file_put_contents($cacheFile, $json);
echo $json;
