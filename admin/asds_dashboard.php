<?php
session_start();
require_once '../config/database.php';
require_once '../config/school_days.php';
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'asst_superintendent') {
    header('Location: ../admin_login.php');
    exit;
}

$conn = getDBConnection();
$current_page = 'dashboard';
$page_title = 'ASDS Dashboard';
$filter_date = $_GET['date'] ?? date('Y-m-d');
$is_school_day = isSchoolDay($filter_date, $conn);
$non_school_reason = getNonSchoolDayReason($filter_date, $conn);

// Find previous school day (for 2-day consecutive logic)
$prev_school_day = date('Y-m-d', strtotime($filter_date . ' -1 day'));
for ($try = 0; $try < 10; $try++) {
    if (isSchoolDay($prev_school_day, $conn)) break;
    $prev_school_day = date('Y-m-d', strtotime($prev_school_day . ' -1 day'));
}
$yesterday = $prev_school_day;
$view_school = $_GET['school'] ?? '';

// Division-wide stats
$total_schools = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM schools WHERE status='active'");
if ($r) $total_schools = $r->fetch_assoc()['cnt'];

$total_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active'");
if ($r) $total_students = $r->fetch_assoc()['cnt'];

$total_teachers = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active'");
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

$total_present = 0;
$r = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL");
if ($r) $total_present = $r->fetch_assoc()['cnt'];

$total_absent = $total_students - $total_present;
$div_att_pct = $total_students > 0 ? round(($total_present / $total_students) * 100, 1) : 0;

$teachers_present = 0;
$r = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='teacher' AND date='$filter_date' AND time_in IS NOT NULL");
if ($r) $teachers_present = $r->fetch_assoc()['cnt'];
$teachers_absent = $total_teachers - $teachers_present;

// Get all absent teachers today (division-wide)
$absent_teachers_list = [];
$at_sql = "SELECT t.id, t.employee_id, t.name, t.contact_number, s.name as school_name, s.id as school_id
    FROM teachers t
    JOIN schools s ON t.school_id = s.id
    WHERE t.status='active'
    AND t.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='teacher' AND date='$filter_date' AND time_in IS NOT NULL)
    ORDER BY s.name, t.name";
$r = $conn->query($at_sql);
if ($r) while ($row = $r->fetch_assoc()) $absent_teachers_list[] = $row;

// Get all present teachers today (division-wide)
$present_teachers_list = [];
$pt_sql = "SELECT t.id, t.employee_id, t.name, t.contact_number, s.name as school_name, s.id as school_id,
    a.time_in, a.time_out, a.status as att_status
    FROM teachers t
    JOIN schools s ON t.school_id = s.id
    JOIN attendance a ON a.person_id = t.id AND a.person_type='teacher' AND a.date='$filter_date'
    WHERE t.status='active' AND a.time_in IS NOT NULL
    ORDER BY s.name, t.name";
$r = $conn->query($pt_sql);
if ($r) while ($row = $r->fetch_assoc()) $present_teachers_list[] = $row;

// Per-school data
$schools_data = [];
$sql = "SELECT s.id, s.name, s.code, s.logo,
        (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active') as total_students,
        (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as total_teachers,
        (SELECT COUNT(DISTINCT a.person_id) FROM attendance a WHERE a.person_type='student' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
        (SELECT COUNT(DISTINCT a.person_id) FROM attendance a WHERE a.person_type='teacher' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as teachers_present
        FROM schools s WHERE s.status='active' ORDER BY s.name";
$r = $conn->query($sql);
if ($r) while ($row = $r->fetch_assoc()) $schools_data[] = $row;

// Division-wide 2-day consecutive absentees
$all_absent_2day = [];
// Count school days in last 30 days for absent calculation
$school_days_30 = 0;
$d = new DateTime($filter_date);
for ($i = 0; $i < 30; $i++) {
    $dd = (clone $d)->modify("-$i days");
    $dow = (int)$dd->format('N'); // 1=Mon, 7=Sun
    if ($dow < 6) $school_days_30++; // weekdays only
}
// Subtract holidays
$h30 = $conn->query("SELECT COUNT(*) as cnt FROM holidays WHERE holiday_date BETWEEN DATE_SUB('$filter_date', INTERVAL 30 DAY) AND '$filter_date' AND DAYOFWEEK(holiday_date) NOT IN (1,7)");
if ($h30) $school_days_30 -= (int)($h30->fetch_assoc()['cnt'] ?? 0);
if ($school_days_30 < 1) $school_days_30 = 1;

$consecutive_sql = "SELECT s.lrn, s.name, s.id as student_id, gl.name as grade, sec.name as section, sch.name as school_name,
        ($school_days_30 - (SELECT COUNT(DISTINCT a2.date) FROM attendance a2 WHERE a2.person_id = s.id AND a2.person_type='student' AND a2.time_in IS NOT NULL AND a2.date BETWEEN DATE_SUB('$filter_date', INTERVAL 30 DAY) AND '$filter_date')) as total_absent
        FROM students s
        JOIN grade_levels gl ON s.grade_level_id = gl.id
        JOIN sections sec ON s.section_id = sec.id
        JOIN schools sch ON s.school_id = sch.id
        WHERE s.status='active'
        AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)
        AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday' AND time_in IS NOT NULL)
        ORDER BY sch.name, gl.name, sec.name, s.name";
$r = $conn->query($consecutive_sql);
if ($r) while ($row = $r->fetch_assoc()) $all_absent_2day[] = $row;

// Schools sorted by attendance rate (highest first) for ranking
$schools_ranked = $schools_data;
usort($schools_ranked, function($a, $b) {
    $pct_a = $a['total_students'] > 0 ? ($a['present'] / $a['total_students']) * 100 : 100;
    $pct_b = $b['total_students'] > 0 ? ($b['present'] / $b['total_students']) * 100 : 100;
    return $pct_b <=> $pct_a; // descending = best attendance first
});

// Weekly division trend (only school days)
$div_trend = [];
$trend_date = $filter_date;
for ($count = 0; $count < 7; $count++) {
    if ($count === 0) {
        $d = $filter_date;
    } else {
        $d = date('Y-m-d', strtotime($d . ' -1 day'));
    }
    while (!isSchoolDay($d, $conn) && $d > date('Y-m-d', strtotime('-60 days'))) {
        $d = date('Y-m-d', strtotime($d . ' -1 day'));
    }
    $cnt = 0;
    $r2 = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='student' AND date='$d' AND time_in IS NOT NULL");
    if ($r2) $cnt = $r2->fetch_assoc()['cnt'];
    array_unshift($div_trend, ['date' => date('M d', strtotime($d)), 'present' => $cnt, 'absent' => $total_students - $cnt]);
    $d = date('Y-m-d', strtotime($d . ' -1 day'));
}

// Drill-down
$drill_school = null;
$drill_sections = [];
$drill_absent_2day = [];
$drill_trend = [];
if ($view_school) {
    $vs = intval($view_school);
    $r = $conn->query("SELECT * FROM schools WHERE id = $vs");
    if ($r) $drill_school = $r->fetch_assoc();

    $sql = "SELECT sec.id, sec.name as section_name, gl.name as grade_name,
            (SELECT COUNT(*) FROM students st WHERE st.section_id = sec.id AND st.status='active') as total,
            (SELECT COUNT(DISTINCT a.person_id) FROM attendance a JOIN students st ON a.person_id = st.id WHERE st.section_id = sec.id AND a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
            (SELECT COUNT(DISTINCT a.person_id) FROM attendance a JOIN students st ON a.person_id = st.id WHERE st.section_id = sec.id AND a.person_type='student' AND a.date='$filter_date' AND a.status='late') as late_count
            FROM sections sec JOIN grade_levels gl ON sec.grade_level_id = gl.id
            WHERE sec.school_id = $vs AND sec.status='active' ORDER BY gl.id, sec.name";
    $r = $conn->query($sql);
    if ($r) while ($row = $r->fetch_assoc()) $drill_sections[] = $row;

    $sql = "SELECT s.lrn, s.name, gl.name as grade, sec.name as section,
            ($school_days_30 - (SELECT COUNT(DISTINCT a2.date) FROM attendance a2 WHERE a2.person_id = s.id AND a2.person_type='student' AND a2.time_in IS NOT NULL AND a2.date BETWEEN DATE_SUB('$filter_date', INTERVAL 30 DAY) AND '$filter_date')) as total_absent
            FROM students s JOIN grade_levels gl ON s.grade_level_id = gl.id JOIN sections sec ON s.section_id = sec.id
            WHERE s.status='active' AND s.school_id = $vs
            AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)
            AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday' AND time_in IS NOT NULL)
            ORDER BY gl.name, sec.name, s.name";
    $r = $conn->query($sql);
    if ($r) while ($row = $r->fetch_assoc()) $drill_absent_2day[] = $row;

    $school_total = 0;
    $r2 = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND school_id = $vs");
    if ($r2) $school_total = $r2->fetch_assoc()['cnt'];
    $d2 = $filter_date;
    for ($count = 0; $count < 7; $count++) {
        if ($count > 0) $d2 = date('Y-m-d', strtotime($d2 . ' -1 day'));
        while (!isSchoolDay($d2, $conn, $vs) && $d2 > date('Y-m-d', strtotime('-60 days'))) {
            $d2 = date('Y-m-d', strtotime($d2 . ' -1 day'));
        }
        $cnt = 0;
        $r2 = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='student' AND date='$d2' AND time_in IS NOT NULL AND school_id = $vs");
        if ($r2) $cnt = $r2->fetch_assoc()['cnt'];
        array_unshift($drill_trend, ['date' => date('M d', strtotime($d2)), 'present' => $cnt, 'absent' => $school_total - $cnt]);
        $d2 = date('Y-m-d', strtotime($d2 . ' -1 day'));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/header.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php if ($drill_school): ?>
        <!-- ─── DRILL-DOWN VIEW ─── -->
        <div class="top-bar">
            <div class="page-header">
                <a href="asds_dashboard.php?date=<?= $filter_date ?>" class="btn btn-outline btn-sm" style="margin-bottom:12px;">
                    <i class="fas fa-arrow-left"></i> Back to Division Overview
                </a>
                <h1><i class="fas fa-school" style="color:var(--primary); margin-right:8px;"></i> <?= htmlspecialchars($drill_school['name']) ?></h1>
                <p>Detailed School Monitoring — <?= formatDate($filter_date) ?></p>
            </div>
            <div class="top-bar-right">
                <form method="GET" style="display:flex;gap:8px;">
                    <input type="hidden" name="school" value="<?= $view_school ?>">
                    <input type="date" name="date" value="<?= $filter_date ?>" class="form-control" style="width:180px;" onchange="this.form.submit()">
                </form>
            </div>
        </div>

        <?php if (!$is_school_day): ?>
        <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #f59e0b;border-radius:14px;padding:18px 24px;margin-bottom:20px;display:flex;align-items:center;gap:14px;">
            <i class="fas fa-calendar-xmark" style="font-size:1.8rem;color:#d97706;"></i>
            <div>
                <strong style="font-size:1rem;color:#92400e;">No Classes Today</strong>
                <p style="margin:2px 0 0;font-size:0.85rem;color:#a16207;"><?= htmlspecialchars($non_school_reason ?? 'Non-school day') ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $s = null;
        foreach ($schools_data as $sc) { if ($sc['id'] == $view_school) { $s = $sc; break; } }
        $s_absent = ($s['total_students'] ?? 0) - ($s['present'] ?? 0);
        $s_pct = ($s['total_students'] ?? 0) > 0 ? round(($s['present'] / $s['total_students']) * 100, 1) : 0;
        ?>
        <div class="stats-grid">
            <div class="stat-card primary"><div class="stat-icon primary"><i class="fas fa-user-graduate"></i></div><div class="stat-info"><h3><?= $s['total_students'] ?? 0 ?></h3><span>Total Students</span></div></div>
            <div class="stat-card success"><div class="stat-icon success"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?= $s['present'] ?? 0 ?></h3><span>Present Today</span></div></div>
            <div class="stat-card error"><div class="stat-icon error"><i class="fas fa-times-circle"></i></div><div class="stat-info"><h3><?= $s_absent ?></h3><span>Absent Today</span></div></div>
            <div class="stat-card info"><div class="stat-icon info"><i class="fas fa-percentage"></i></div><div class="stat-info"><h3><?= $s_pct ?>%</h3><span>Attendance Rate</span></div></div>
        </div>

        <div class="grid-2" style="margin-bottom:24px;">
            <div class="card">
                <div class="card-title"><i class="fas fa-chart-bar"></i> Weekly Trend</div>
                <div class="chart-container" style="height:250px;"><canvas id="drillChart"></canvas></div>
            </div>
            <div class="card">
                <div class="card-title"><i class="fas fa-exclamation-triangle" style="color:var(--error);"></i> 2-Day Absentees <span class="badge badge-error" style="margin-left:auto;"><?= count($drill_absent_2day) ?></span></div>
                <div style="max-height:300px; overflow-y:auto;">
                    <?php if (empty($drill_absent_2day)): ?>
                    <div class="empty-state" style="padding:20px;"><i class="fas fa-check-circle" style="color:var(--success);"></i><p>No consecutive absentees</p></div>
                    <?php else: foreach ($drill_absent_2day as $abs): ?>
                    <div class="absence-flag">
                        <div class="flag-icon"><i class="fas fa-exclamation-circle"></i></div>
                        <div class="flag-info">
                            <strong><?= htmlspecialchars($abs['name']) ?></strong>
                            <span>LRN: <?= htmlspecialchars($abs['lrn']) ?> &bull; <?= htmlspecialchars($abs['grade']) ?> — <?= htmlspecialchars($abs['section']) ?> &bull; <strong style="color:#dc2626;"><?= $abs['total_absent'] ?> day<?= $abs['total_absent'] != 1 ? 's' : '' ?> absent</strong></span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-layer-group"></i> Grade & Section Breakdown</div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Grade</th><th>Section</th><th>Total</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr></thead>
                    <tbody>
                    <?php foreach ($drill_sections as $sec):
                        $sa = $sec['total'] - $sec['present'];
                        $sr = $sec['total'] > 0 ? round(($sec['present']/$sec['total'])*100,1) : 0;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($sec['grade_name']) ?></strong></td>
                        <td><?= htmlspecialchars($sec['section_name']) ?></td>
                        <td><?= $sec['total'] ?></td>
                        <td class="text-success fw-600"><?= $sec['present'] ?></td>
                        <td class="text-error fw-600"><?= $sa ?></td>
                        <td class="text-warning fw-600"><?= $sec['late_count'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="progress-bar" style="width:80px;"><div class="progress-bar-fill <?= $sr >= 90 ? 'high' : ($sr >= 75 ? 'medium' : 'low') ?>" style="width:<?= $sr ?>%;"></div></div>
                                <span class="fw-600" style="font-size:0.8rem;"><?= $sr ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        new Chart(document.getElementById('drillChart').getContext('2d'), {
            type: 'bar',
            data: { labels: <?= json_encode(array_column($drill_trend, 'date')) ?>, datasets: [
                { label: 'Present', data: <?= json_encode(array_column($drill_trend, 'present')) ?>, backgroundColor: 'rgba(22,163,74,0.7)', borderRadius: 6, barPercentage: 0.6 },
                { label: 'Absent', data: <?= json_encode(array_column($drill_trend, 'absent')) ?>, backgroundColor: 'rgba(220,38,38,0.7)', borderRadius: 6, barPercentage: 0.6 }
            ]},
            options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{x:{stacked:true,grid:{display:false}},y:{stacked:true,beginAtZero:true,grid:{color:'#e2e8f0'}}} }
        });
        </script>

        <?php else: ?>
        <!-- ─── MAIN: Division Overview ─── -->
        <div class="top-bar">
            <div class="page-header">
                <h1><i class="fas fa-building" style="color:var(--primary); margin-right:8px;"></i> Welcome, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></h1>
                <p>Assistant Schools Division Superintendent — Division-Level Monitoring</p>
            </div>
            <div class="top-bar-right">
                <div class="date-display"><i class="fas fa-calendar-alt"></i> <?= formatDate($filter_date) ?></div>
                <form method="GET"><input type="date" name="date" value="<?= $filter_date ?>" class="form-control" style="width:180px;" onchange="this.form.submit()"></form>
            </div>
        </div>

        <?php if (!$is_school_day): ?>
        <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #f59e0b;border-radius:14px;padding:18px 24px;margin-bottom:20px;display:flex;align-items:center;gap:14px;">
            <i class="fas fa-calendar-xmark" style="font-size:1.8rem;color:#d97706;"></i>
            <div>
                <strong style="font-size:1rem;color:#92400e;">No Classes Today</strong>
                <p style="margin:2px 0 0;font-size:0.85rem;color:#a16207;"><?= htmlspecialchars($non_school_reason ?? 'Non-school day') ?> — Attendance data shown is for reference only.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Division Summary -->
        <div class="stats-grid">
            <div class="stat-card primary"><div class="stat-icon primary"><i class="fas fa-school"></i></div><div class="stat-info"><h3><?= $total_schools ?></h3><span>Total Schools</span></div></div>
            <div class="stat-card info"><div class="stat-icon info"><i class="fas fa-users"></i></div><div class="stat-info"><h3><?= number_format($total_students) ?></h3><span>Total Students</span></div></div>
            <div class="stat-card success"><div class="stat-icon success"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?= number_format($total_present) ?></h3><span>Present Today</span></div></div>
            <div class="stat-card error"><div class="stat-icon error"><i class="fas fa-times-circle"></i></div><div class="stat-info"><h3><?= number_format($total_absent) ?></h3><span>Absent Today</span></div></div>
            <div class="stat-card warning" style="cursor:pointer;" onclick="document.getElementById('absentee2dayList').scrollIntoView({behavior:'smooth',block:'start'})"><div class="stat-icon warning"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-info"><h3><?= count($all_absent_2day) ?></h3><span>2-Day Absentees</span></div></div>
        </div>

        <!-- Teacher Stats Row -->
        <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">
            <div class="stat-card" style="flex:1;min-width:200px;border-left:4px solid var(--info);cursor:pointer;" onclick="openTeacherModal('present')">
                <div class="stat-icon info"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-info"><h3><?= $total_teachers ?></h3><span>Total Teachers</span></div>
            </div>
            <div class="stat-card" style="flex:1;min-width:200px;border-left:4px solid var(--success);cursor:pointer;" onclick="openTeacherModal('present')">
                <div class="stat-icon success"><i class="fas fa-user-check"></i></div>
                <div class="stat-info"><h3><?= $teachers_present ?></h3><span>Teachers Present</span></div>
            </div>
            <div class="stat-card" style="flex:1;min-width:200px;border-left:4px solid var(--error);cursor:pointer;" onclick="openTeacherModal('absent')">
                <div class="stat-icon error"><i class="fas fa-user-times"></i></div>
                <div class="stat-info"><h3 style="color:var(--error);"><?= $teachers_absent ?></h3><span>Teachers Absent</span></div>
            </div>
            <div class="stat-card" style="flex:1;min-width:200px;border-left:4px solid <?= $total_teachers > 0 && ($teachers_present/$total_teachers*100) >= 90 ? 'var(--success)' : 'var(--warning)' ?>;">
                <div class="stat-icon <?= $total_teachers > 0 && ($teachers_present/$total_teachers*100) >= 90 ? 'success' : 'warning' ?>"><i class="fas fa-chart-pie"></i></div>
                <div class="stat-info"><h3><?= $total_teachers > 0 ? round(($teachers_present/$total_teachers)*100,1) : 0 ?>%</h3><span>Teacher Attendance</span></div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid-2" style="margin-bottom:24px;">
            <div class="card">
                <div class="card-title"><i class="fas fa-chart-line"></i> Division Weekly Attendance Trend</div>
                <div class="chart-container" style="height:260px;"><canvas id="divTrendChart"></canvas></div>
            </div>
            <div class="card">
                <div class="card-title"><i class="fas fa-ranking-star" style="color:var(--success);"></i> Schools by Attendance Rate</div>
                <div style="max-height:300px; overflow-y:auto;">
                    <?php foreach (array_slice($schools_ranked, 0, 10) as $i => $sr):
                        $sr_pct = $sr['total_students'] > 0 ? round(($sr['present'] / $sr['total_students']) * 100, 1) : 100;
                    ?>
                    <div style="display:flex; align-items:center; gap:12px; padding:10px 12px; border-bottom:1px solid var(--border);">
                        <span style="width:28px; height:28px; border-radius:8px; background:<?= $i < 3 ? 'var(--success-bg)' : 'var(--card-bg-alt)' ?>; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:700; color:<?= $i < 3 ? 'var(--success)' : 'var(--text-muted)' ?>;"><?= $i + 1 ?></span>
                        <div style="flex:1;">
                            <div style="font-size:0.85rem; font-weight:600;"><?= htmlspecialchars($sr['name']) ?></div>
                            <div style="font-size:0.72rem; color:var(--text-muted);"><?= $sr['present'] ?> present of <?= $sr['total_students'] ?></div>
                        </div>
                        <span class="attendance-pct" style="font-size:0.85rem; font-weight:800;"><?= $sr_pct ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- School Cards -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="font-size:1.1rem; font-weight:700;"><i class="fas fa-th-large" style="color:var(--primary); margin-right:8px;"></i> All Schools</h3>
            <span class="text-muted" style="font-size:0.8rem;">Click a school card for detailed view</span>
        </div>
        <div class="school-cards-grid">
            <?php foreach ($schools_data as $s):
                $s_absent = $s['total_students'] - $s['present'];
                $s_pct = $s['total_students'] > 0 ? round(($s['present'] / $s['total_students']) * 100, 1) : 0;
                $pct_class = $s_pct >= 90 ? 'high' : ($s_pct >= 75 ? 'medium' : 'low');
            ?>
            <a href="asds_dashboard.php?school=<?= $s['id'] ?>&date=<?= $filter_date ?>" style="text-decoration:none; color:inherit;">
                <div class="school-card">
                    <div class="school-card-header">
                        <?php if (!empty($s['logo']) && file_exists('../assets/uploads/logos/' . $s['logo'])): ?>
                            <img src="../assets/uploads/logos/<?= htmlspecialchars($s['logo']) ?>" alt="Logo" style="width:48px;height:48px;object-fit:contain;border-radius:12px;border:1px solid var(--border);background:#fff;padding:2px;">
                        <?php else: ?>
                            <div class="school-card-icon"><i class="fas fa-school"></i></div>
                        <?php endif; ?>
                        <div class="school-card-name"><?= htmlspecialchars($s['name']) ?></div>
                    </div>
                    <div class="school-card-stats">
                        <div class="school-card-stat"><div class="stat-value"><?= $s['total_students'] ?></div><div class="stat-label">Students</div></div>
                        <div class="school-card-stat"><div class="stat-value text-success"><?= $s['present'] ?></div><div class="stat-label">Present</div></div>
                        <div class="school-card-stat"><div class="stat-value text-error"><?= $s_absent ?></div><div class="stat-label">Absent</div></div>
                        <div class="school-card-stat"><div class="stat-value"><?= $s['teachers_present'] ?>/<?= $s['total_teachers'] ?></div><div class="stat-label">Teachers</div></div>
                    </div>
                    <div class="school-card-footer">
                        <span class="attendance-pct <?= $pct_class ?>"><?= $s_pct ?>%</span>
                        <span class="btn btn-sm btn-outline"><i class="fas fa-arrow-right"></i></span>
                    </div>
                    <div class="progress-bar"><div class="progress-bar-fill <?= $pct_class ?>" style="width:<?= $s_pct ?>%;"></div></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- 2-Day Consecutive Master List -->
        <div class="card" style="margin-top:24px;" id="absentee2dayList">
            <div class="card-title"><i class="fas fa-exclamation-triangle" style="color:var(--error);"></i> Division-Wide 2-Day Consecutive Absentee Master List <span class="badge badge-error" style="margin-left:auto;"><?= count($all_absent_2day) ?></span></div>
            <div class="table-wrapper" style="max-height:400px; overflow-y:auto;">
                <table>
                    <thead><tr><th>School</th><th>LRN</th><th>Student Name</th><th>Grade</th><th>Section</th><th>Days Absent</th></tr></thead>
                    <tbody>
                    <?php if (empty($all_absent_2day)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px;" class="text-muted">No students with 2 consecutive days absent</td></tr>
                    <?php else: foreach ($all_absent_2day as $abs): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($abs['school_name']) ?></strong></td>
                        <td><?= htmlspecialchars($abs['lrn']) ?></td>
                        <td><?= htmlspecialchars($abs['name']) ?></td>
                        <td><?= htmlspecialchars($abs['grade']) ?></td>
                        <td><?= htmlspecialchars($abs['section']) ?></td>
                        <td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.78rem;font-weight:700;background:<?= $abs['total_absent'] >= 5 ? '#fee2e2' : '#fef3c7' ?>;color:<?= $abs['total_absent'] >= 5 ? '#dc2626' : '#d97706' ?>;"><?= $abs['total_absent'] ?> day<?= $abs['total_absent'] != 1 ? 's' : '' ?></span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        new Chart(document.getElementById('divTrendChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($div_trend, 'date')) ?>,
                datasets: [
                    { label: 'Present', data: <?= json_encode(array_column($div_trend, 'present')) ?>, backgroundColor: 'rgba(22,163,74,0.7)', borderColor: '#16a34a', borderWidth: 1, borderRadius: 6 },
                    { label: 'Absent', data: <?= json_encode(array_column($div_trend, 'absent')) ?>, backgroundColor: 'rgba(220,38,38,0.7)', borderColor: '#dc2626', borderWidth: 1, borderRadius: 6 }
                ]
            },
            options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{x:{grid:{display:false}},y:{beginAtZero:true, grid:{color:'#e2e8f0'}, ticks:{stepSize:1}}} }
        });
        </script>

        <!-- Teacher Modal -->
        <div class="modal-overlay" id="teacherModal">
            <div class="modal" style="max-width:800px;">
                <div class="modal-header">
                    <h3 id="teacherModalTitle"><i class="fas fa-chalkboard-teacher" style="color:var(--primary);margin-right:8px;"></i> Teachers</h3>
                    <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
                </div>
                <div class="modal-body" style="padding:0;">
                    <!-- Tabs -->
                    <div style="display:flex;border-bottom:2px solid var(--border);">
                        <button class="teacher-tab active" id="tabAbsent" onclick="switchTeacherTab('absent')"
                            style="flex:1;padding:14px;font-size:0.85rem;font-weight:600;border:none;cursor:pointer;background:transparent;color:var(--error);border-bottom:3px solid var(--error);transition:all 0.2s;">
                            <i class="fas fa-user-times"></i> Absent <span style="background:var(--error);color:#fff;padding:2px 8px;border-radius:999px;font-size:0.72rem;margin-left:4px;"><?= $teachers_absent ?></span>
                        </button>
                        <button class="teacher-tab" id="tabPresent" onclick="switchTeacherTab('present')"
                            style="flex:1;padding:14px;font-size:0.85rem;font-weight:600;border:none;cursor:pointer;background:transparent;color:var(--text-muted);border-bottom:3px solid transparent;transition:all 0.2s;">
                            <i class="fas fa-user-check"></i> Present <span style="background:var(--success);color:#fff;padding:2px 8px;border-radius:999px;font-size:0.72rem;margin-left:4px;"><?= $teachers_present ?></span>
                        </button>
                    </div>

                    <!-- Absent Teachers List -->
                    <div id="absentTeachersPanel" style="max-height:420px;overflow-y:auto;">
                        <?php if (empty($absent_teachers_list)): ?>
                        <div style="text-align:center;padding:40px;color:var(--text-muted);">
                            <i class="fas fa-check-circle" style="font-size:2.5rem;color:var(--success);margin-bottom:12px;display:block;"></i>
                            <p style="font-weight:600;">All teachers are present today!</p>
                        </div>
                        <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr style="background:var(--error-bg);">
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--error);text-align:left;font-weight:700;">#</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--error);text-align:left;font-weight:700;">Teacher</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--error);text-align:left;font-weight:700;">Employee ID</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--error);text-align:left;font-weight:700;">School</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--error);text-align:left;font-weight:700;">Contact</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--error);text-align:left;font-weight:700;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($absent_teachers_list as $ti => $at): ?>
                                <tr style="border-bottom:1px solid var(--border);background:<?= $ti % 2 === 0 ? 'rgba(220,38,38,0.03)' : 'transparent' ?>;">
                                    <td style="padding:10px 16px;font-size:0.82rem;color:var(--text-muted);font-weight:600;"><?= $ti + 1 ?></td>
                                    <td style="padding:10px 16px;">
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div style="width:34px;height:34px;border-radius:50%;background:var(--error-bg);display:flex;align-items:center;justify-content:center;color:var(--error);font-weight:700;font-size:0.8rem;"><?= strtoupper(substr($at['name'], 0, 1)) ?></div>
                                            <span style="font-weight:600;font-size:0.85rem;"><?= htmlspecialchars($at['name']) ?></span>
                                        </div>
                                    </td>
                                    <td style="padding:10px 16px;"><span style="background:var(--primary-bg);color:var(--primary);padding:2px 8px;border-radius:6px;font-family:monospace;font-size:0.78rem;font-weight:600;"><?= htmlspecialchars($at['employee_id']) ?></span></td>
                                    <td style="padding:10px 16px;font-size:0.82rem;"><?= htmlspecialchars($at['school_name']) ?></td>
                                    <td style="padding:10px 16px;font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($at['contact_number'] ?: '—') ?></td>
                                    <td style="padding:10px 16px;"><span style="background:var(--error-bg);color:var(--error);font-size:0.7rem;font-weight:700;padding:3px 10px;border-radius:999px;"><i class="fas fa-times-circle" style="margin-right:3px;"></i>Absent</span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <!-- Present Teachers List -->
                    <div id="presentTeachersPanel" style="max-height:420px;overflow-y:auto;display:none;">
                        <?php if (empty($present_teachers_list)): ?>
                        <div style="text-align:center;padding:40px;color:var(--text-muted);">
                            <i class="fas fa-times-circle" style="font-size:2.5rem;color:var(--error);margin-bottom:12px;display:block;"></i>
                            <p style="font-weight:600;">No teachers have scanned in today.</p>
                        </div>
                        <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr style="background:var(--success-bg);">
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--success);text-align:left;font-weight:700;">#</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--success);text-align:left;font-weight:700;">Teacher</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--success);text-align:left;font-weight:700;">Employee ID</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--success);text-align:left;font-weight:700;">School</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--success);text-align:left;font-weight:700;">Time In</th>
                                    <th style="padding:10px 16px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--success);text-align:left;font-weight:700;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($present_teachers_list as $ti => $pt): ?>
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td style="padding:10px 16px;font-size:0.82rem;color:var(--text-muted);font-weight:600;"><?= $ti + 1 ?></td>
                                    <td style="padding:10px 16px;">
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div style="width:34px;height:34px;border-radius:50%;background:var(--success-bg);display:flex;align-items:center;justify-content:center;color:var(--success);font-weight:700;font-size:0.8rem;"><?= strtoupper(substr($pt['name'], 0, 1)) ?></div>
                                            <span style="font-weight:600;font-size:0.85rem;"><?= htmlspecialchars($pt['name']) ?></span>
                                        </div>
                                    </td>
                                    <td style="padding:10px 16px;"><span style="background:var(--primary-bg);color:var(--primary);padding:2px 8px;border-radius:6px;font-family:monospace;font-size:0.78rem;font-weight:600;"><?= htmlspecialchars($pt['employee_id']) ?></span></td>
                                    <td style="padding:10px 16px;font-size:0.82rem;"><?= htmlspecialchars($pt['school_name']) ?></td>
                                    <td style="padding:10px 16px;"><span style="color:var(--success);font-weight:600;"><?= date('h:i A', strtotime($pt['time_in'])) ?></span></td>
                                    <td style="padding:10px 16px;"><span style="background:var(--success-bg);color:var(--success);font-size:0.7rem;font-weight:700;padding:3px 10px;border-radius:999px;"><?= ucfirst($pt['att_status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function openTeacherModal(tab) {
            document.getElementById('teacherModal').classList.add('active');
            switchTeacherTab(tab);
        }
        function switchTeacherTab(tab) {
            document.getElementById('absentTeachersPanel').style.display = tab === 'absent' ? 'block' : 'none';
            document.getElementById('presentTeachersPanel').style.display = tab === 'present' ? 'block' : 'none';
            const tabAbsent = document.getElementById('tabAbsent');
            const tabPresent = document.getElementById('tabPresent');
            if (tab === 'absent') {
                tabAbsent.style.color = 'var(--error)';
                tabAbsent.style.borderBottom = '3px solid var(--error)';
                tabPresent.style.color = 'var(--text-muted)';
                tabPresent.style.borderBottom = '3px solid transparent';
                document.getElementById('teacherModalTitle').innerHTML = '<i class="fas fa-user-times" style="color:var(--error);margin-right:8px;"></i> Absent Teachers';
            } else {
                tabPresent.style.color = 'var(--success)';
                tabPresent.style.borderBottom = '3px solid var(--success)';
                tabAbsent.style.color = 'var(--text-muted)';
                tabAbsent.style.borderBottom = '3px solid transparent';
                document.getElementById('teacherModalTitle').innerHTML = '<i class="fas fa-user-check" style="color:var(--success);margin-right:8px;"></i> Present Teachers';
            }
        }
        </script>
        <?php endif; ?>
    </div>

    <script>setTimeout(() => location.reload(), 60000);</script>
</body>
</html>
