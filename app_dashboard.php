<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * MOBILE DASHBOARD — Native Phone Design
 * ══════════════════════════════════════════════════════════════════
 * Full dashboard matching SDS/ASDS/Super Admin web features.
 * Role-aware: super_admin, superintendent, asst_superintendent, principal
 * Smooth AJAX refresh (no page flicker).
 */
session_start();
// Prevent WebView caching so updates show immediately
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require_once 'config/database.php';
require_once 'config/school_days.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: app_login.php');
    exit;
}

$conn = getDBConnection();
$today = date('Y-m-d');
$filter_date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) $filter_date = $today;
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;
$admin_name = $_SESSION['admin_name'] ?? 'Admin';


$is_division = in_array($admin_role, ['super_admin', 'superintendent', 'asst_superintendent']);
$role_label = match($admin_role) {
    'superintendent' => 'Schools Division Superintendent',
    'asst_superintendent' => 'Asst. Schools Division Superintendent',
    'principal' => 'School Principal',
    default => 'Super Admin'
};

$school_filter_sql = '';
if ($admin_role === 'principal' && $admin_school_id) {
    $school_filter_sql = " AND school_id = " . (int)$admin_school_id . " ";
}

$filter_school = (int)($_GET['school'] ?? 0);
$extra_filter = '';
if ($filter_school) $extra_filter .= " AND school_id = $filter_school ";

// Find previous school day
$prev_school_day = date('Y-m-d', strtotime($filter_date . ' -1 day'));
for ($try = 0; $try < 10; $try++) {
    if (isSchoolDay($prev_school_day, $conn)) break;
    $prev_school_day = date('Y-m-d', strtotime($prev_school_day . ' -1 day'));
}
$yesterday = $prev_school_day;

// ─── Summary Stats ───
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
// Teachers are only counted for attendance/absence if created before the filter date.
$teacher_effective_date = "DATE(created_at)";
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active' AND $teacher_effective_date < '$filter_date' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = " . (int)$admin_school_id : "") . ($filter_school ? " AND school_id = $filter_school" : ""));
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

$timed_in_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_in_today = $r->fetch_assoc()['cnt'];

// Count only active students for attendance/absent calculations
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

// ─── 2-Day Flag (full details with total_absent like SDS) ───
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
$effective_student_date_expr = "DATE(COALESCE(s.active_from, s.created_at))";
$flag_sql = "SELECT s.id, s.lrn, s.name, s.created_at, s.active_from, s.school_id, sch.name as school_name, sch.code as school_code, gl.name as grade_name, sec.name as section_name
    FROM students s
    LEFT JOIN schools sch ON s.school_id = sch.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.status = 'active'
    AND s.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12'))
    AND $effective_student_date_expr < '$filter_date'
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday' AND time_in IS NOT NULL)
    " . ($admin_role === 'principal' && $admin_school_id ? "AND s.school_id = " . (int)$admin_school_id : "") . "
    " . ($filter_school ? "AND s.school_id = $filter_school" : "");

$r = $conn->query($flag_sql . " ORDER BY sch.name, gl.id, s.name LIMIT 100");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        // Compute accurate total_absent for this student between their enrollment date (or 30 days back)
        $enroll_date = null;
        if (!empty($row['active_from'])) $enroll_date = date('Y-m-d', strtotime($row['active_from']));
        elseif (!empty($row['created_at'])) $enroll_date = date('Y-m-d', strtotime($row['created_at']));
        $range_start = date('Y-m-d', strtotime("-30 days", strtotime($filter_date)));
        if ($enroll_date && $enroll_date > $range_start) $range_start = $enroll_date;

        // Count school days between range_start and filter_date (inclusive)
        $sd_count = 0;
        $d = $range_start;
        while ($d <= $filter_date) {
            if (isSchoolDay($d, $conn, $row['school_id'] ?? null)) $sd_count++;
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }

        // Count attended days in that range
        $pid = (int)$row['id'];
        $safe_start = $conn->real_escape_string($range_start);
        $safe_end = $conn->real_escape_string($filter_date);
        $att_r = $conn->query("SELECT COUNT(DISTINCT date) as cnt FROM attendance WHERE person_type='student' AND person_id = $pid AND time_in IS NOT NULL AND date BETWEEN '$safe_start' AND '$safe_end'");
        $att_cnt = 0;
        if ($att_r) $att_cnt = (int)($att_r->fetch_assoc()['cnt'] ?? 0);

        $row['total_absent'] = max(0, $sd_count - $att_cnt);
        $flagged_students[] = $row;
    }
}
$flag_count = count($flagged_students);

// ─── Per-School Breakdown ───
$school_breakdown = [];
$school_sql = "SELECT s.id, s.name, s.code,
    (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) AND (DATE(st.created_at) < '$filter_date' OR st.id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL))) as enrolled,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' AND st.grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) WHERE a.person_type='student' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as teachers_present,
    (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as total_teachers
    FROM schools s WHERE s.status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND s.id = " . (int)$admin_school_id : "") . "
    ORDER BY s.name";
$r = $conn->query($school_sql);
if ($r) { while ($row = $r->fetch_assoc()) { $row['present'] = min($row['present'], $row['enrolled']); $row['absent'] = max(0, $row['enrolled'] - $row['present']); $row['rate'] = $row['enrolled'] > 0 ? min(100, round(($row['present'] / $row['enrolled']) * 100, 1)) : 0; $school_breakdown[] = $row; } }

// Schools ranked by attendance
$schools_ranked = $school_breakdown;
usort($schools_ranked, fn($a, $b) => $b['rate'] <=> $a['rate']);

// ─── Weekly Trend (school days only) ───
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
    $r2 = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND grade_level_id IN (SELECT id FROM grade_levels WHERE name NOT IN ('Grade 11','Grade 12')) AND DATE(created_at) <= '$td'" . ($admin_role === 'principal' && $admin_school_id ? " AND school_id=" . (int)$admin_school_id : "") . ($filter_school ? " AND school_id=$filter_school" : ""));
    if ($r2) $day_total = $r2->fetch_assoc()['cnt'];
    array_unshift($div_trend, ['date' => date('M d', strtotime($td)), 'present' => $cnt, 'absent' => max(0, $day_total - $cnt)]);
    $td = date('Y-m-d', strtotime($td . ' -1 day'));
}

// Schools list for filter
$schools_list = [];
$r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name");
if ($r) { while ($row = $r->fetch_assoc()) $schools_list[] = $row; }

// System logo
$systemLogo = '';
$lr = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'");
if ($lr && $lrow = $lr->fetch_assoc()) {
    $lf = $lrow['setting_value'] ?? '';
    if ($lf && file_exists(__DIR__ . '/assets/uploads/logos/' . basename($lf))) $systemLogo = 'assets/uploads/logos/' . basename($lf);
}

$is_today = ($filter_date === $today);
$non_school = !isSchoolDay($filter_date, $conn);
$non_school_reason = $non_school ? getNonSchoolDayReason($filter_date, $conn) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#059669">
    <title>Dashboard — EduTrack</title>
    <?php if ($systemLogo): ?><link rel="icon" type="image/png" href="<?= $systemLogo ?>"><?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        :root{
            --pri:#059669;--pri-l:#34d399;--pri-d:#047857;
            --green:#059669;--red:#dc2626;--amber:#d97706;--blue:#059669;--teal:#0d9488;
            --bg:#f8faf9;--surface:#fff;--surface-dim:#f0f2f0;--on-surface:#1a1c1a;--on-surface-v:#49504a;
            --outline:#c1c9bf;--outline-v:#dde3db;
            --green-c:#d1fae5;--red-c:#fee2e2;--amber-c:#fef3c7;--blue-c:#d1fae5;--teal-c:#ccfbf1;
            --el1:0 1px 2px rgba(0,0,0,.06),0 1px 3px rgba(0,0,0,.1);
            --el2:0 2px 4px rgba(0,0,0,.06),0 4px 6px rgba(0,0,0,.08);
            --safe-t:env(safe-area-inset-top,0px);--safe-b:env(safe-area-inset-bottom,0px);
        }
        html,body{font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:var(--bg);color:var(--on-surface);min-height:100vh;overflow-x:hidden;-webkit-font-smoothing:antialiased}

        /* ═══ App Bar ═══ */
        .app-bar{position:sticky;top:0;z-index:100;background:var(--pri);color:#fff;padding:calc(10px + var(--safe-t)) 16px 14px}
        .bar-row{display:flex;align-items:center;justify-content:space-between}
        .bar-brand{display:flex;align-items:center;gap:10px}
        .bar-logo{width:36px;height:36px;border-radius:10px;overflow:hidden;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .bar-logo img{width:100%;height:100%;object-fit:cover}
        .bar-logo i{font-size:1rem;color:#fff}
        .bar-title{font-size:.95rem;font-weight:700;letter-spacing:-.01em}
        .bar-sub{font-size:.65rem;opacity:.8;font-weight:500;margin-top:1px}
        .bar-actions{display:flex;gap:6px}
        .bar-btn{width:36px;height:36px;border-radius:18px;border:none;background:rgba(255,255,255,.12);color:#fff;font-size:.88rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s;text-decoration:none}
        .bar-btn:active{background:rgba(255,255,255,.24)}
        .bar-btn .fa-spin{animation:spin .8s linear infinite}

        .date-row{display:flex;align-items:center;gap:6px;margin-top:10px}
        .live-dot{width:7px;height:7px;border-radius:50%;background:#dc2626;box-shadow:0 0 6px rgba(220,38,38,.5);animation:pulse 1.5s infinite}
        .date-chip{font-size:.72rem;font-weight:600;background:rgba(255,255,255,.12);padding:6px 12px;border-radius:8px;display:flex;align-items:center;gap:5px}
        .date-chip i{font-size:.65rem;opacity:.7}
        .date-input{background:rgba(255,255,255,.12);border:none;color:#fff;padding:6px 10px;border-radius:8px;font-size:.72rem;font-family:inherit;font-weight:600;outline:none;color-scheme:dark}

        /* Banner */
        .banner{margin:10px 14px 0;padding:12px 14px;border-radius:12px;display:flex;align-items:center;gap:10px;box-shadow:var(--el1)}
        .banner-warn{background:var(--amber-c)}
        .banner-warn i{color:var(--amber);font-size:1.1rem;flex-shrink:0}
        .banner-warn strong{color:#92400e;font-size:.8rem;display:block}
        .banner-warn p{color:#a16207;font-size:.7rem;margin-top:1px}

        /* Content */
        .content{padding:14px 14px calc(78px + var(--safe-b))}

        /* Greeting */
        .greet{margin-bottom:14px}
        .greet-sub{font-size:.74rem;color:var(--on-surface-v);font-weight:500}
        .greet-name{font-size:1.1rem;font-weight:800;letter-spacing:-.02em}
        .greet-role{font-size:.68rem;color:var(--on-surface-v);font-weight:500;margin-top:1px}

        /* ═══ Ring ═══ */
        .ring-card{background:var(--surface);border-radius:16px;padding:20px 16px;margin-bottom:10px;text-align:center;box-shadow:var(--el1);position:relative;overflow:hidden}
        .ring-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--pri),var(--pri-l),var(--pri))}
        .ring-wrap{position:relative;width:140px;height:140px;margin:0 auto 12px}
        .ring-wrap svg{width:100%;height:100%;transform:rotate(-90deg)}
        .ring-bg{fill:none;stroke:var(--green-c);stroke-width:10}
        .ring-fg{fill:none;stroke-width:10;stroke-linecap:round;transition:stroke-dashoffset .8s ease}
        .ring-ctr{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center}
        .ring-pct{font-size:2.2rem;font-weight:900;letter-spacing:-2px}
        .ring-lbl{font-size:.6rem;color:var(--on-surface-v);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:1px}
        .ring-info{font-size:.74rem;color:var(--on-surface-v);font-weight:500}
        .ring-info b{color:var(--on-surface)}

        /* ═══ Stat Grid (2-col) ═══ */
        .sg{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}
        .sc{background:var(--surface);border-radius:14px;padding:14px 12px;display:flex;align-items:center;gap:10px;box-shadow:var(--el1);transition:transform .12s}
        .sc:active{transform:scale(.97)}
        .si{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
        .si-g{background:#ecfdf5;color:var(--green)}.si-r{background:#fef2f2;color:var(--red)}.si-a{background:#fffbeb;color:var(--amber)}
        .si-b{background:#eff6ff;color:var(--blue)}.si-t{background:#f0fdfa;color:var(--teal)}.si-p{background:#f5f3ff;color:#7c3aed}
        .sv{font-size:1.25rem;font-weight:800;letter-spacing:-.5px;line-height:1;transition:color .3s}
        .sv small{font-size:.65rem;font-weight:600;color:var(--on-surface-v)}
        .sl{font-size:.58rem;color:var(--on-surface-v);font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-top:1px}

        /* ═══ Section ═══ */
        .sec-h{font-size:.68rem;font-weight:700;color:var(--on-surface-v);text-transform:uppercase;letter-spacing:.7px;margin:16px 0 8px;display:flex;align-items:center;gap:6px}
        .sec-h i{font-size:.62rem;color:var(--pri)}
        .sec-h::after{content:'';flex:1;height:1px;background:var(--outline-v)}
        .sec-h .cnt{font-size:.55rem;font-weight:700;padding:2px 7px;border-radius:8px;background:var(--green-c);color:var(--green)}

        /* ═══ Filter ═══ */
        .filter-btn{width:100%;background:var(--surface);border:1px solid var(--outline);padding:11px 14px;border-radius:12px;font-size:.8rem;font-family:inherit;font-weight:600;color:var(--on-surface);cursor:pointer;box-shadow:var(--el1);display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
        .filter-btn i{color:var(--on-surface-v);font-size:.65rem}
        .filter-btn:active{background:var(--surface-dim)}

        /* ═══ School Card ═══ */
        .sch-c{background:var(--surface);border-radius:14px;padding:14px;margin-bottom:8px;box-shadow:var(--el1);border-left:3px solid var(--pri)}
        .sch-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}
        .sch-n{font-size:.82rem;font-weight:700;line-height:1.2}
        .sch-code{font-size:.55rem;font-weight:700;background:var(--green-c);color:var(--green);padding:3px 8px;border-radius:6px;white-space:nowrap}
        .sch-bar{height:5px;background:var(--green-c);border-radius:3px;overflow:hidden;margin-bottom:10px}
        .sch-bar .fill{height:100%;border-radius:3px;transition:width .6s ease}
        .sch-row{display:flex}
        .sch-st{text-align:center;flex:1}
        .sch-st .v{font-size:.9rem;font-weight:800}
        .sch-st .l{font-size:.52rem;color:var(--on-surface-v);font-weight:600;text-transform:uppercase;letter-spacing:.2px;margin-top:1px}

        /* ═══ Chart Card ═══ */
        .chart-card{background:var(--surface);border-radius:14px;padding:14px;margin-bottom:10px;box-shadow:var(--el1)}
        .chart-title{font-size:.8rem;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:6px}
        .chart-title i{color:var(--pri);font-size:.7rem}
        .chart-wrap{height:180px;position:relative}

        /* ═══ Ranking ═══ */
        .rank-item{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--outline-v)}
        .rank-item:last-child{border-bottom:none}
        .rank-num{width:24px;height:24px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;flex-shrink:0}
        .rank-num.top{background:var(--green-c);color:var(--green)}
        .rank-num.reg{background:var(--surface-dim);color:var(--on-surface-v)}
        .rank-name{font-size:.8rem;font-weight:600;flex:1}
        .rank-sub{font-size:.65rem;color:var(--on-surface-v)}
        .rank-pct{font-size:.82rem;font-weight:800}

        /* ═══ Expand Card ═══ */
        .exp-card{background:var(--surface);border-radius:14px;padding:14px;margin-bottom:10px;box-shadow:var(--el1)}
        .exp-toggle{display:flex;align-items:center;justify-content:space-between;width:100%;background:none;border:none;cursor:pointer;padding:0;font-family:inherit;color:var(--on-surface)}
        .exp-toggle-text{font-size:.8rem;font-weight:700}
        .exp-arrow{width:24px;height:24px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--surface-dim);font-size:.6rem;color:var(--on-surface-v);transition:transform .3s}
        .exp-toggle.open .exp-arrow{transform:rotate(180deg)}
        .exp-list{max-height:0;overflow:hidden;transition:max-height .35s cubic-bezier(.4,0,.2,1)}
        .exp-list.open{max-height:3000px}

        .flag-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--outline-v)}
        .flag-row:last-child{border-bottom:none}
        .flag-name{font-size:.78rem;font-weight:600}
        .flag-meta{font-size:.65rem;color:var(--on-surface-v);margin-top:1px}
        .flag-code{font-size:.52rem;font-weight:700;padding:2px 6px;border-radius:4px;background:var(--surface-dim);color:var(--on-surface-v)}
        .flag-days{font-size:.62rem;font-weight:700;margin-top:2px}
        .flag-empty{text-align:center;padding:20px;font-size:.8rem;color:var(--on-surface-v)}
        .flag-empty i{display:block;font-size:1.3rem;color:var(--green);opacity:.4;margin-bottom:6px}

        .tch-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--outline-v)}
        .tch-row:last-child{border-bottom:none}
        .tch-name{font-size:.78rem;font-weight:600}
        .tch-sub{font-size:.65rem;color:var(--on-surface-v);margin-top:1px}
        .tch-pct{font-size:.88rem;font-weight:800}

        /* ═══ Alert Button ═══ */
        .alert-btn{width:100%;padding:12px;border:none;border-radius:12px;font-size:.82rem;font-weight:700;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;background:var(--red);color:#fff;box-shadow:var(--el2);transition:transform .12s}
        .alert-btn:active{transform:scale(.97)}
        .alert-btn:disabled{opacity:.6}
        #checkResult{margin-top:6px;font-size:.72rem;color:var(--on-surface-v);text-align:center;font-weight:500}

        /* ═══ Nav Bar ═══ */
        .nav-bar{position:fixed;bottom:0;left:0;right:0;z-index:100;background:var(--surface);border-top:1px solid rgba(0,0,0,.06);padding:0 4px var(--safe-b);height:calc(72px + var(--safe-b));display:flex;justify-content:space-around;align-items:stretch}
        .nav-item{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font-size:.55rem;font-weight:600;color:var(--on-surface-v);text-decoration:none;padding:0 8px;border:none;background:none;cursor:pointer;position:relative;flex:1;transition:color .2s}
        .nav-item i{font-size:1.05rem;z-index:1;transition:transform .15s, color .2s}
        .nav-item span{z-index:1}
        .nav-item.active{color:#022c22}
        .nav-item .pill{position:absolute;top:10px;left:50%;width:28px;height:6px;border-radius:999px;background:var(--green-c);opacity:0;transform:translateX(-50%) scaleX(0.8);transition:opacity .25s ease, transform .25s ease}
        .nav-item.active .pill{opacity:1;transform:translateX(-50%) scaleX(1)}
        .nav-item:active i{transform:scale(.85)}

        /* ═══ Filter Panel ═══ */
        .f-backdrop{position:fixed;top:0;left:0;right:0;bottom:0;z-index:199;background:rgba(0,0,0,.3);opacity:0;pointer-events:none;transition:opacity .25s}
        .f-backdrop.open{opacity:1;pointer-events:auto}
        .f-panel{position:fixed;bottom:0;left:0;right:0;z-index:200;background:var(--surface);border-radius:20px 20px 0 0;padding:0 20px calc(16px + var(--safe-b));box-shadow:0 -6px 30px rgba(0,0,0,.1);transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1)}
        .f-panel.open{transform:translateY(0)}
        .f-handle{width:28px;height:4px;border-radius:2px;margin:10px auto 16px;background:var(--outline)}
        .f-title{font-size:.9rem;font-weight:700;margin-bottom:12px}
        .f-opt{padding:12px 14px;border-radius:10px;font-size:.8rem;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;transition:background .12s}
        .f-opt:active{background:var(--surface-dim)}
        .f-opt.sel{background:var(--green-c);color:var(--green)}
        .f-opt .chk{display:none}.f-opt.sel .chk{display:inline}
        .f-list{max-height:50vh;overflow-y:auto}

        /* Toast */
        .toast{position:fixed;top:-60px;left:50%;transform:translateX(-50%);background:#2f312f;color:#f0f1ec;padding:10px 18px;border-radius:10px;font-size:.78rem;font-weight:600;z-index:300;display:flex;align-items:center;gap:6px;transition:top .35s cubic-bezier(.32,.72,0,1);box-shadow:var(--el2)}
        .toast.show{top:calc(12px + var(--safe-t))}
        .toast .fa-check-circle{color:#4ade80}.toast .fa-times-circle{color:#f87171}

        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
        @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
        @keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
        .fade-in{animation:fadeIn .3s ease both}
    </style>
</head>
<body>

    <!-- ═══ APP BAR ═══ -->
    <header class="app-bar">
        <div class="bar-row">
            <div class="bar-brand">
                <div class="bar-logo">
                    <?php if ($systemLogo): ?><img src="<?= htmlspecialchars($systemLogo) ?>" alt=""><?php else: ?><i class="fas fa-chart-pie"></i><?php endif; ?>
                </div>
                <div>
                    <div class="bar-title">EduTrack</div>
                    <div class="bar-sub"><?= htmlspecialchars($admin_name) ?></div>
                </div>
            </div>
            <div class="bar-actions">
                <a href="admin/logout.php" class="bar-btn" title="Sign out"><i class="fas fa-right-from-bracket"></i></a>
            </div>
        </div>
        <div class="date-row">
            <?php if ($is_today): ?><div class="live-dot"></div><div class="date-chip"><i class="fas fa-bolt"></i>Real-time — <?= date('D, M j') ?></div>
            <?php else: ?><div class="date-chip"><i class="fas fa-calendar"></i><?= date('D, M j, Y', strtotime($filter_date)) ?></div><?php endif; ?>
            <div class="date-chip" id="lastUpdated" style="font-size:0.8rem;color:var(--text-muted);">Last updated: --:--:--</div>
            <input type="date" class="date-input" value="<?= htmlspecialchars($filter_date) ?>" onchange="applyDate(this.value)">
        </div>
    </header>

    <?php if ($non_school): ?>
    <div class="banner banner-warn">
        <i class="fas fa-calendar-xmark"></i>
        <div><strong>No Classes Today</strong><p><?= htmlspecialchars($non_school_reason ?? 'Non-school day') ?> — data for reference.</p></div>
    </div>
    <?php endif; ?>

    <!-- ═══ MAIN CONTENT ═══ -->
    <div class="content" id="mainContent">

        <div class="greet fade-in">
            <div class="greet-sub"><?php $h=(int)date('G'); echo $h<12?'Good Morning':($h<17?'Good Afternoon':'Good Evening'); ?> 👋</div>
            <div class="greet-name"><?= htmlspecialchars($admin_name) ?></div>
            <div class="greet-role"><?= $role_label ?> — Division-Level Monitoring</div>
        </div>

        <!-- Ring -->
        <div class="ring-card fade-in">
            <div class="ring-wrap">
                <svg viewBox="0 0 120 120">
                    <circle class="ring-bg" cx="60" cy="60" r="52"/>
                    <?php $circ=2*M_PI*52; $off=$circ-($attendance_rate/100)*$circ; $rc=$attendance_rate>=80?'#059669':($attendance_rate>=50?'#d97706':'#dc2626'); ?>
                    <circle class="ring-fg" id="ringFg" cx="60" cy="60" r="52" stroke="<?=$rc?>" stroke-dasharray="<?=$circ?>" stroke-dashoffset="<?=$off?>"/>
                </svg>
                <div class="ring-ctr">
                    <div class="ring-pct" id="ringPct"><?=$attendance_rate?>%</div>
                    <div class="ring-lbl">Attendance</div>
                </div>
            </div>
            <div class="ring-info"><b id="ringPresent"><?=$timed_in_today?></b> of <b id="ringTotal"><?=$total_students?></b> students present</div>
        </div>

        <!-- Stats Row 1: Students -->
        <div class="sg fade-in">
            <div class="sc"><div class="si si-b"><i class="fas fa-users"></i></div><div><div class="sv" data-stat="total_students"><?=$total_students?></div><div class="sl">Total Students</div></div></div>
            <div class="sc"><div class="si si-g"><i class="fas fa-user-check"></i></div><div><div class="sv" data-stat="timed_in_today"><?=$timed_in_today?></div><div class="sl">Present</div></div></div>
            <div class="sc"><div class="si si-r"><i class="fas fa-user-xmark"></i></div><div><div class="sv" data-stat="absent_today"><?=$absent_today?></div><div class="sl">Absent</div></div></div>
            <div class="sc"><div class="si si-a"><i class="fas fa-triangle-exclamation"></i></div><div><div class="sv" data-stat="flag_count"><?=$flag_count?></div><div class="sl">2-Day Flagged</div></div></div>
        </div>

        <!-- Stats Row 2: Teachers -->
        <div class="sg fade-in">
            <div class="sc"><div class="si si-b"><i class="fas fa-chalkboard-teacher"></i></div><div><div class="sv" data-stat="total_teachers"><?=$total_teachers?></div><div class="sl">Total Teachers</div></div></div>
            <div class="sc"><div class="si si-g"><i class="fas fa-user-check"></i></div><div><div class="sv" data-stat="teachers_in"><?=$teachers_in?></div><div class="sl">Teachers Present</div></div></div>
            <div class="sc"><div class="si si-r"><i class="fas fa-user-times"></i></div><div><div class="sv" data-stat="teachers_absent" style="color:var(--red)"><?=$teachers_absent?></div><div class="sl">Teachers Absent</div></div></div>
            <div class="sc"><div class="si si-t"><i class="fas fa-chart-pie"></i></div><div><div class="sv" data-stat="teacher_att_pct"><?=$teacher_att_pct?>%</div><div class="sl">Teacher Rate</div></div></div>
        </div>

        <!-- Extra: Schools + Timed Out -->
        <div class="sg fade-in">
            <div class="sc"><div class="si si-p"><i class="fas fa-school"></i></div><div><div class="sv" data-stat="total_schools"><?=$total_schools?></div><div class="sl">Schools</div></div></div>
            <div class="sc"><div class="si si-t"><i class="fas fa-arrow-right-from-bracket"></i></div><div><div class="sv" data-stat="timed_out_today"><?=$timed_out_today?></div><div class="sl">Timed Out</div></div></div>
        </div>

        <!-- ═══ WEEKLY TREND CHART ═══ -->
        <div class="chart-card fade-in">
            <div class="chart-title"><i class="fas fa-chart-bar"></i> Weekly Attendance Trend</div>
            <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
        </div>

        <!-- ═══ SCHOOLS RANKING ═══ -->
        <div class="sec-h"><i class="fas fa-ranking-star"></i> Schools by Attendance Rate</div>
        <div class="exp-card fade-in">
            <button class="exp-toggle" id="rankToggle" onclick="toggleExp('rank')">
                <span class="exp-toggle-text" id="rankTitle">Top <?= min(count($schools_ranked), 10) ?> Schools</span>
                <div class="exp-arrow"><i class="fas fa-chevron-down"></i></div>
            </button>
            <div class="exp-list" id="rankList">
                <?php foreach (array_slice($schools_ranked, 0, 10) as $i => $sr):
                    $pct_color = $sr['rate'] >= 80 ? 'var(--green)' : ($sr['rate'] >= 50 ? 'var(--amber)' : 'var(--red)');
                ?>
                <div class="rank-item">
                    <div class="rank-num <?= $i < 3 ? 'top' : 'reg' ?>"><?= $i + 1 ?></div>
                    <div style="flex:1;min-width:0">
                        <div class="rank-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($sr['name']) ?></div>
                        <div class="rank-sub"><?= $sr['present'] ?> of <?= $sr['enrolled'] ?></div>
                    </div>
                    <div class="rank-pct" style="color:<?= $pct_color ?>"><?= $sr['rate'] ?>%</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- School Filter -->
        <?php if ($admin_role !== 'principal' && count($schools_list) > 1): ?>
        <button class="filter-btn" onclick="openFilter()">
            <span><?= $filter_school ? htmlspecialchars(array_values(array_filter($schools_list, fn($s) => $s['id'] == $filter_school))[0]['name'] ?? 'All Schools') : 'All Schools' ?></span>
            <i class="fas fa-chevron-down"></i>
        </button>
        <?php endif; ?>

        <!-- ═══ SCHOOL BREAKDOWN ═══ -->
        <div class="sec-h"><i class="fas fa-school"></i> School Breakdown <span class="cnt" id="schoolCount"><?= count($school_breakdown) ?></span></div>
        <div id="schoolBreakdown">
        <?php if (empty($school_breakdown)): ?>
            <div class="sch-c" style="text-align:center;color:var(--on-surface-v);padding:24px;">No schools found.</div>
        <?php else: foreach ($school_breakdown as $i => $sb): ?>
        <div class="sch-c fade-in" style="animation-delay:<?= $i*.02 ?>s">
            <div class="sch-top">
                <div class="sch-n"><?= htmlspecialchars($sb['name']) ?></div>
                <span class="sch-code"><?= htmlspecialchars($sb['code']) ?></span>
            </div>
            <div class="sch-bar"><div class="fill" style="width:<?=$sb['rate']?>%;background:<?=$sb['rate']>=80?'var(--green)':($sb['rate']>=50?'var(--amber)':'var(--red)')?>"></div></div>
            <div class="sch-row">
                <div class="sch-st"><div class="v" style="color:var(--green)"><?=$sb['present']?></div><div class="l">Present</div></div>
                <div class="sch-st"><div class="v" style="color:var(--red)"><?=$sb['absent']?></div><div class="l">Absent</div></div>
                <div class="sch-st"><div class="v" style="color:<?=$sb['rate']>=80?'var(--green)':($sb['rate']>=50?'var(--amber)':'var(--red)')?>"><?=$sb['rate']?>%</div><div class="l">Rate</div></div>
                <div class="sch-st"><div class="v" style="color:var(--blue)"><?=$sb['teachers_present']?>/<?=$sb['total_teachers']?></div><div class="l">Teachers</div></div>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>

        <!-- ═══ 2-DAY FLAGGED ═══ -->
        <div class="sec-h"><i class="fas fa-exclamation-triangle" style="color:var(--amber)"></i> 2-Day Consecutive Absences <span class="cnt" id="flagBadge" style="background:var(--amber-c);color:var(--amber)"><?=$flag_count?></span></div>
        <div class="exp-card fade-in">
            <button class="exp-toggle" id="flagToggle" onclick="toggleExp('flag')">
                <span class="exp-toggle-text" id="flagTitle"><?= $flag_count > 0 ? $flag_count . ' student' . ($flag_count > 1 ? 's' : '') . ' flagged' : 'No flags — all good!' ?></span>
                <?php if ($flag_count > 0): ?><div class="exp-arrow"><i class="fas fa-chevron-down"></i></div><?php endif; ?>
            </button>
            <?php if ($flag_count > 0): ?>
            <div class="exp-list" id="flagList">
                <?php foreach ($flagged_students as $fs): ?>
                <div class="flag-row">
                    <div style="min-width:0;flex:1">
                        <div class="flag-name"><?= htmlspecialchars($fs['name']) ?></div>
                        <div class="flag-meta">LRN: <?= htmlspecialchars($fs['lrn']) ?> · <?= htmlspecialchars($fs['grade_name'] ?? '') ?> — <?= htmlspecialchars($fs['section_name'] ?? '') ?></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0">
                        <span class="flag-code"><?= htmlspecialchars($fs['school_code'] ?? '') ?></span>
                        <div class="flag-days" style="color:<?= ($fs['total_absent'] ?? 2) >= 5 ? 'var(--red)' : 'var(--amber)' ?>"><?= $fs['total_absent'] ?? '2+' ?> day<?= ($fs['total_absent'] ?? 2) != 1 ? 's' : '' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="flag-empty"><i class="fas fa-check-circle"></i>All students have been attending.</div>
            <?php endif; ?>
        </div>

        <!-- ═══ TEACHER ATTENDANCE ═══ -->
        <div class="sec-h"><i class="fas fa-chalkboard-teacher" style="color:var(--blue)"></i> Teacher Attendance</div>
        <div class="exp-card fade-in">
            <?php if (empty($school_breakdown)): ?>
                <div style="text-align:center;padding:16px;color:var(--on-surface-v);font-size:.78rem;">No data.</div>
            <?php else: ?>
            <button class="exp-toggle" id="teacherToggle" onclick="toggleExp('teacher')">
                <span class="exp-toggle-text" id="teacherTitle"><?= $teachers_in ?> of <?= $total_teachers ?> present (<?= $teacher_att_pct ?>%)</span>
                <div class="exp-arrow"><i class="fas fa-chevron-down"></i></div>
            </button>
            <div class="exp-list" id="teacherList">
                <?php foreach ($school_breakdown as $sb): ?>
                <div class="tch-row">
                    <div style="min-width:0;flex:1">
                        <div class="tch-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($sb['name']) ?></div>
                        <div class="tch-sub"><?= $sb['teachers_present'] ?> of <?= $sb['total_teachers'] ?> present</div>
                    </div>
                    <div class="tch-pct" style="color:<?= $sb['total_teachers'] > 0 && $sb['teachers_present'] == $sb['total_teachers'] ? 'var(--green)' : 'var(--amber)' ?>">
                        <?= $sb['total_teachers'] > 0 ? round(($sb['teachers_present'] / $sb['total_teachers']) * 100) . '%' : '—' ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Alert Button -->
        <div style="margin-top:12px;">
            <button onclick="checkAbsences()" id="checkAbsBtn" class="alert-btn"><i class="fas fa-bell"></i> Check & Send Absence Alerts</button>
            <div id="checkResult"></div>
        </div>
    </div>

    <!-- ═══ FILTER PANEL ═══ -->
    <div class="f-backdrop" id="fBackdrop" onclick="closeFilter()"></div>
    <div class="f-panel" id="fPanel">
        <div class="f-handle"></div>
        <div class="f-title">Select School</div>
        <div class="f-list">
            <div class="f-opt <?= !$filter_school ? 'sel' : '' ?>" onclick="applySchool(0)">All Schools <i class="fas fa-check chk"></i></div>
            <?php foreach ($schools_list as $sch): ?>
            <div class="f-opt <?= $filter_school == $sch['id'] ? 'sel' : '' ?>" onclick="applySchool(<?= (int)$sch['id'] ?>)"><?= htmlspecialchars($sch['name']) ?> <i class="fas fa-check chk"></i></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="toast" id="toast"><i class="fas fa-check-circle" id="toastIcon"></i><span id="toastMsg"></span></div>

    <!-- ═══ NAV BAR ═══ -->
    <nav class="nav-bar">
        <a href="app_dashboard.php" class="nav-item active"><div class="pill"></div><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
        <a href="admin/attendance.php" class="nav-item"><div class="pill"></div><i class="fas fa-clipboard-list"></i><span>Attendance</span></a>
        <a href="admin/school_browser.php" class="nav-item"><div class="pill"></div><i class="fas fa-school"></i><span>Schools</span></a>
        <a href="admin/reports.php" class="nav-item"><div class="pill"></div><i class="fas fa-file-alt"></i><span>Reports</span></a>
    </nav>

    <script>
    // Unregister leftover service workers
    if('serviceWorker' in navigator){navigator.serviceWorker.getRegistrations().then(r=>r.forEach(reg=>reg.unregister()))}

    // ═══ Toast ═══
    function showToast(msg, ok) {
        const t = document.getElementById('toast');
        document.getElementById('toastIcon').className = ok ? 'fas fa-check-circle' : 'fas fa-times-circle';
        document.getElementById('toastMsg').textContent = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2500);
    }

    // ═══ Expand/Collapse ═══
    function toggleExp(id) {
        const t = document.getElementById(id + 'Toggle');
        const l = document.getElementById(id + 'List');
        if (!t || !l) return;
        l.classList.toggle('open');
        t.classList.toggle('open');
    }

    // ═══ REAL-TIME ENGINE ═══
    const POLL_INTERVAL = 5000; // 5 seconds
    let isPolling = false;
    let pollTimer = null;
    const CIRC = <?= json_encode(2 * M_PI * 52) ?>;

    function getApiUrl() {
        const p = new URLSearchParams(location.search);
        let url = 'api/dashboard_data.php';
        const params = [];
        if (p.get('date')) params.push('date=' + encodeURIComponent(p.get('date')));
        if (p.get('school')) params.push('school=' + encodeURIComponent(p.get('school')));
        return params.length ? url + '?' + params.join('&') : url;
    }

    // Animate number change
    function animateValue(el, newVal) {
        if (!el) return;
        const text = el.textContent.trim();
        const hasPct = text.endsWith('%');
        const oldNum = parseFloat(text) || 0;
        const newNum = parseFloat(newVal) || 0;
        if (oldNum === newNum) return;

        // Brief highlight
        el.style.transition = 'color .2s';
        el.style.color = newNum > oldNum ? 'var(--green)' : (newNum < oldNum ? 'var(--red)' : '');

        const steps = 12;
        const inc = (newNum - oldNum) / steps;
        let step = 0;
        const timer = setInterval(() => {
            step++;
            const v = step >= steps ? newNum : oldNum + inc * step;
            el.textContent = (Number.isInteger(newNum) ? Math.round(v) : v.toFixed(1)) + (hasPct ? '%' : '');
            if (step >= steps) {
                clearInterval(timer);
                // Restore color after animation
                setTimeout(() => { el.style.color = ''; }, 600);
            }
        }, 25);
    }

    function rateColor(rate) {
        return rate >= 80 ? 'var(--green)' : (rate >= 50 ? 'var(--amber)' : 'var(--red)');
    }

    function rateHex(rate) {
        return rate >= 80 ? '#059669' : (rate >= 50 ? '#d97706' : '#dc2626');
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // Update ring chart
    function updateRing(rate, present, total) {
        const fg = document.getElementById('ringFg');
        const pctEl = document.getElementById('ringPct');
        const presEl = document.getElementById('ringPresent');
        const totEl = document.getElementById('ringTotal');
        if (fg) {
            const off = CIRC - (rate / 100) * CIRC;
            fg.style.transition = 'stroke-dashoffset .8s ease, stroke .4s';
            fg.setAttribute('stroke-dashoffset', off);
            fg.setAttribute('stroke', rateHex(rate));
        }
        if (pctEl) animateValue(pctEl, rate);
        if (presEl) presEl.textContent = present;
        if (totEl) totEl.textContent = total;
    }

    // Update stat cards
    function updateStats(stats) {
        document.querySelectorAll('[data-stat]').forEach(el => {
            const key = el.dataset.stat;
            if (stats[key] !== undefined) {
                const hasPct = key === 'teacher_att_pct';
                animateValue(el, stats[key]);
            }
        });
    }

    // Update ranking list
    function updateRanking(ranked) {
        const list = document.getElementById('rankList');
        const title = document.getElementById('rankTitle');
        if (!list) return;
        if (title) title.textContent = 'Top ' + Math.min(ranked.length, 10) + ' Schools';
        let html = '';
        ranked.forEach((s, i) => {
            html += '<div class="rank-item">'
                + '<div class="rank-num ' + (i < 3 ? 'top' : 'reg') + '">' + (i + 1) + '</div>'
                + '<div style="flex:1;min-width:0">'
                + '<div class="rank-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escHtml(s.name) + '</div>'
                + '<div class="rank-sub">' + s.present + ' of ' + s.enrolled + '</div></div>'
                + '<div class="rank-pct" style="color:' + rateColor(s.rate) + '">' + s.rate + '%</div></div>';
        });
        list.innerHTML = html;
    }

    // Update school breakdown
    function updateSchools(schools) {
        const container = document.getElementById('schoolBreakdown');
        const countEl = document.getElementById('schoolCount');
        if (!container) return;
        if (countEl) countEl.textContent = schools.length;
        if (!schools.length) {
            container.innerHTML = '<div class="sch-c" style="text-align:center;color:var(--on-surface-v);padding:24px;">No schools found.</div>';
            return;
        }
        let html = '';
        schools.forEach(sb => {
            html += '<div class="sch-c">'
                + '<div class="sch-top"><div class="sch-n">' + escHtml(sb.name) + '</div><span class="sch-code">' + escHtml(sb.code) + '</span></div>'
                + '<div class="sch-bar"><div class="fill" style="width:' + sb.rate + '%;background:' + rateColor(sb.rate) + '"></div></div>'
                + '<div class="sch-row">'
                + '<div class="sch-st"><div class="v" style="color:var(--green)">' + sb.present + '</div><div class="l">Present</div></div>'
                + '<div class="sch-st"><div class="v" style="color:var(--red)">' + sb.absent + '</div><div class="l">Absent</div></div>'
                + '<div class="sch-st"><div class="v" style="color:' + rateColor(sb.rate) + '">' + sb.rate + '%</div><div class="l">Rate</div></div>'
                + '<div class="sch-st"><div class="v" style="color:var(--blue)">' + sb.teachers_present + '/' + sb.total_teachers + '</div><div class="l">Teachers</div></div>'
                + '</div></div>';
        });
        container.innerHTML = html;
    }

    // Update flagged students
    function updateFlagged(flagged, count) {
        const badge = document.getElementById('flagBadge');
        const title = document.getElementById('flagTitle');
        const list = document.getElementById('flagList');
        if (badge) badge.textContent = count;
        if (title) title.textContent = count > 0 ? count + ' student' + (count > 1 ? 's' : '') + ' flagged' : 'No flags — all good!';
        if (!list) return;
        if (!count) {
            list.innerHTML = '<div class="flag-empty"><i class="fas fa-check-circle"></i>All students have been attending.</div>';
            return;
        }
        let html = '';
        flagged.forEach(fs => {
            const days = fs.total_absent || 2;
            html += '<div class="flag-row"><div style="min-width:0;flex:1">'
                + '<div class="flag-name">' + escHtml(fs.name) + '</div>'
                + '<div class="flag-meta">LRN: ' + escHtml(fs.lrn) + ' · ' + escHtml(fs.grade_name || '') + ' — ' + escHtml(fs.section_name || '') + '</div></div>'
                + '<div style="text-align:right;flex-shrink:0"><span class="flag-code">' + escHtml(fs.school_code || '') + '</span>'
                + '<div class="flag-days" style="color:' + (days >= 5 ? 'var(--red)' : 'var(--amber)') + '">' + days + ' day' + (days != 1 ? 's' : '') + '</div></div></div>';
        });
        list.innerHTML = html;
    }

    // Update teacher attendance
    function updateTeachers(schools, teachersIn, totalTeachers, pct) {
        const title = document.getElementById('teacherTitle');
        const list = document.getElementById('teacherList');
        if (title) title.textContent = teachersIn + ' of ' + totalTeachers + ' present (' + pct + '%)';
        if (!list) return;
        let html = '';
        schools.forEach(sb => {
            const tp = sb.total_teachers > 0 ? Math.round((sb.teachers_present / sb.total_teachers) * 100) + '%' : '—';
            const color = sb.total_teachers > 0 && sb.teachers_present == sb.total_teachers ? 'var(--green)' : 'var(--amber)';
            html += '<div class="tch-row"><div style="min-width:0;flex:1">'
                + '<div class="tch-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escHtml(sb.name) + '</div>'
                + '<div class="tch-sub">' + sb.teachers_present + ' of ' + sb.total_teachers + ' present</div></div>'
                + '<div class="tch-pct" style="color:' + color + '">' + tp + '</div></div>';
        });
        list.innerHTML = html;
    }

    // Update chart
    function updateChart(trend) {
        if (!trendChart) return;
        trendChart.data.labels = trend.map(d => d.date);
        trendChart.data.datasets[0].data = trend.map(d => d.present);
        trendChart.data.datasets[1].data = trend.map(d => d.absent);
        trendChart.update('none'); // no animation for smooth feel
    }

    // ═══ Main Poll ═══
    function formatTime(date) {
        const d = new Date(date);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function updateLastUpdated() {
        const el = document.getElementById('lastUpdated');
        if (!el) return;
        el.textContent = 'Last updated: ' + formatTime(new Date());
    }

    async function pollData() {
        if (isPolling) return;
        isPolling = true;
        try {
            const resp = await fetch(getApiUrl() + (getApiUrl().includes('?') ? '&' : '?') + '_=' + Date.now(), { cache: 'no-store' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            const s = data.stats;

            // Update all sections
            updateRing(s.attendance_rate, s.timed_in_today, s.total_students);
            updateStats(s);
            updateRanking(data.schools_ranked);
            updateSchools(data.school_breakdown);
            updateFlagged(data.flagged_students, s.flag_count);
            updateTeachers(data.school_breakdown, s.teachers_in, s.total_teachers, s.teacher_att_pct);
            updateChart(data.trend);

            updateLastUpdated();
        } catch (e) {
            // Silent fail — next poll will retry
            console.warn('Poll error:', e);
        }
        isPolling = false;
    }

    // Manual refresh with visual feedback
    async function smoothRefresh() {
        const icon = document.getElementById('refreshIcon');
        icon.classList.add('fa-spin');
        await pollData();
        icon.classList.remove('fa-spin');
        showToast('Updated', true);
    }

    // Start real-time polling (initial fetch immediately)
    pollData();
    pollTimer = setInterval(pollData, POLL_INTERVAL);

    // Pause when tab hidden, resume when visible
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(pollTimer);
            pollTimer = null;
        } else {
            pollData(); // immediate refresh on return
            pollTimer = setInterval(pollData, POLL_INTERVAL);
        }
    });

    // ═══ Check Absences ═══
    async function checkAbsences() {
        const btn = document.getElementById('checkAbsBtn');
        const result = document.getElementById('checkResult');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
        result.textContent = '';
        try {
            const resp = await fetch('api/check_absences_notify.php');
            const data = await resp.json();
            if (data.skipped) {
                result.textContent = data.message;
            } else if (data.success) {
                result.innerHTML = '<b style="color:var(--red)">' + data.flagged + ' flagged.</b> ' + data.notifications.sent + ' alerts sent.';
                showToast(data.flagged > 0 ? data.flagged + ' students flagged' : 'All good!', true);
            } else {
                result.textContent = data.error || 'Error';
            }
        } catch (err) {
            result.textContent = 'Network error';
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-bell"></i> Check & Send Absence Alerts';
    }

    // ═══ Navigation ═══
    function applyDate(v) { const p = new URLSearchParams(location.search); p.set('date', v); location.search = p.toString(); }
    function applySchool(id) { const p = new URLSearchParams(location.search); if (id) p.set('school', id); else p.delete('school'); location.search = p.toString(); }
    function openFilter() { document.getElementById('fPanel').classList.add('open'); document.getElementById('fBackdrop').classList.add('open'); }
    function closeFilter() { document.getElementById('fPanel').classList.remove('open'); document.getElementById('fBackdrop').classList.remove('open'); }

    // ═══ Weekly Trend Chart ═══
    const trendData = <?= json_encode($div_trend) ?>;
    let trendChart = null;
    function initChart() {
        const canvas = document.getElementById('trendChart');
        if (!canvas) return;
        if (trendChart) { trendChart.destroy(); trendChart = null; }
        trendChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: trendData.map(d => d.date),
                datasets: [
                    { label: 'Present', data: trendData.map(d => d.present), backgroundColor: 'rgba(5,150,105,.7)', borderRadius: 4, barPercentage: .6, categoryPercentage: 0.65 },
                    { label: 'Absent', data: trendData.map(d => d.absent), backgroundColor: 'rgba(220,38,38,.6)', borderRadius: 4, barPercentage: .6, categoryPercentage: 0.65 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10, family: 'Inter', weight: '600' } } } },
                scales: {
                    x: { stacked: false, grid: { display: false }, ticks: { font: { size: 9, family: 'Inter' } } },
                    y: { stacked: false, beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { font: { size: 9 }, stepSize: 1 } }
                }
            }
        });
    }
    initChart();
    </script>
</body>
</html>
