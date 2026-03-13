<?php
session_start();
require_once '../config/database.php';
require_once '../config/school_days.php';
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../admin_login.php');
    exit;
}
// Redirect role-specific users to their dashboards
$role = $_SESSION['admin_role'] ?? 'super_admin';
if ($role === 'principal') { header('Location: principal_dashboard.php'); exit; }
if ($role === 'superintendent') { header('Location: sds_dashboard.php'); exit; }
if ($role === 'asst_superintendent') { header('Location: asds_dashboard.php'); exit; }

$conn = getDBConnection();

$current_page = 'dashboard';
$page_title = 'Dashboard';

$today = date('Y-m-d');
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;

// Role-based school filter
$school_filter_sql = '';
if ($admin_role === 'principal' && $admin_school_id) {
    $school_filter_sql = " AND school_id = $admin_school_id ";
}

$filter_school = (int)($_GET['school'] ?? 0);
$filter_date = sanitize($_GET['date'] ?? $today);

$extra_filter = '';
if ($filter_school) $extra_filter .= " AND school_id = $filter_school ";

// ─── Summary Stats ───
$total_schools = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM schools WHERE status='active' $school_filter_sql"); 
if ($r) $total_schools = $r->fetch_assoc()['cnt'];

$total_students = 0;
// Total students (active + inactive)
$r = $conn->query("SELECT COUNT(*) as cnt FROM students " . ($admin_role === 'principal' && $admin_school_id ? "WHERE school_id = $admin_school_id" : ""));
if ($filter_school) {
    $r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE school_id = $filter_school");
}
if ($r) $total_students = $r->fetch_assoc()['cnt'];

$total_teachers = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = $admin_school_id" : ""));
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

// Students timed in today (only count active students)
$timed_in_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_in_today = $r->fetch_assoc()['cnt'];

// Students timed out today (only count active students)
$timed_out_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_out IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_out_today = $r->fetch_assoc()['cnt'];

// Absent today (not scanned — exclude students created/activated today unless they attended)
$relevant_students = 0;
$student_effective_date = "DATE(COALESCE(active_from, created_at))";
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND ($student_effective_date < '$filter_date' OR id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)) " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = $admin_school_id " : "") . ($filter_school ? "AND school_id = $filter_school" : ""));
if ($r) $relevant_students = $r->fetch_assoc()['cnt'];
$absent_today = max(0, $relevant_students - $timed_in_today);

// Teachers timed in today (only count active teachers)
$teachers_in = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $teachers_in = $r->fetch_assoc()['cnt'];

// ─── Per-School Breakdown ───
$school_breakdown = [];
$school_sql = "SELECT s.id, s.name, s.code,
    (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active' AND (DATE(st.created_at) < '$filter_date' OR st.id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL))) as enrolled,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as teachers_present,
    (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as total_teachers
    FROM schools s WHERE s.status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND s.id = $admin_school_id" : "") . "
    ORDER BY s.name";
$r = $conn->query($school_sql);
if ($r) { while ($row = $r->fetch_assoc()) { $row['present'] = min($row['present'], $row['enrolled']); $row['absent'] = max(0, $row['enrolled'] - $row['present']); $row['rate'] = $row['enrolled'] > 0 ? min(100, round(($row['present'] / $row['enrolled']) * 100, 1)) : 0; $school_breakdown[] = $row; } }

// ─── 2-Day Consecutive Absence Flag ───
$flagged_students = [];
$yesterday = date('Y-m-d', strtotime('-1 day', strtotime($filter_date)));
$flag_sql = "SELECT s.id, s.lrn, s.name, sch.name as school_name, sch.code as school_code, gl.name as grade_name, sec.name as section_name
    FROM students s
    LEFT JOIN schools sch ON s.school_id = sch.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.status = 'active'
    AND DATE(COALESCE(s.active_from, s.created_at)) < '$filter_date'
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date')
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday')
    " . ($admin_role === 'principal' && $admin_school_id ? "AND s.school_id = $admin_school_id" : "") . "
    " . ($filter_school ? "AND s.school_id = $filter_school" : "") . "
    ORDER BY sch.name, gl.id, s.name
    LIMIT 100";
$r = $conn->query($flag_sql);
if ($r) { while ($row = $r->fetch_assoc()) $flagged_students[] = $row; }

// ─── Fetch schools for filter dropdown ───
$schools_list = [];
$r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name");
if ($r) { while ($row = $r->fetch_assoc()) $schools_list[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/header.php'; ?>
    <style>
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .dashboard-grid .card.full { grid-column: span 2; }
        .school-row { display: flex; align-items: center; justify-content: space-between; padding: 14px 0; border-bottom: 1px solid var(--border); }
        .school-row:last-child { border-bottom: none; }
        .school-info { display: flex; align-items: center; gap: 14px; }
        .school-info .sch-code { background: rgba(67,56,202,0.1); color: var(--primary); padding: 6px 12px; border-radius: 8px; font-size: 0.72rem; font-weight: 700; min-width: 60px; text-align: center; }
        .school-stats { display: flex; gap: 20px; align-items: center; }
        .school-stats .ss { text-align: center; }
        .school-stats .ss .val { font-size: 1.1rem; font-weight: 800; }
        .school-stats .ss .lbl { font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase; }
        .progress-bar { width: 120px; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; display: flex; }
        .progress-bar .fill { height: 100%; transition: width 0.5s ease; }
        .flag-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .flag-item:last-child { border-bottom: none; }
        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } .dashboard-grid .card.full { grid-column: span 1; } }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">
            <div>
                <h1><i class="fas fa-chart-pie" style="color:var(--primary);margin-right:8px;"></i> Division Dashboard</h1>
                <p>Real-time attendance monitoring — <?= date('l, F j, Y', strtotime($filter_date)) ?></p>
            </div>
            <form method="GET" style="display:flex;gap:10px;align-items:center;">
                <?php if ($admin_role !== 'principal'): ?>
                <select name="school" class="form-control" style="width:auto;min-width:200px;" onchange="this.form.submit()">
                    <option value="">All Schools</option>
                    <?php foreach ($schools_list as $sch): ?>
                        <option value="<?= $sch['id'] ?>" <?= $filter_school == $sch['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <input type="date" name="date" class="form-control" style="width:auto;" value="<?= $filter_date ?>" onchange="this.form.submit()">
            </form>
        </div>

        <?php if (!isSchoolDay($filter_date, $conn)):
            $non_school_reason = getNonSchoolDayReason($filter_date, $conn);
        ?>
        <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #f59e0b;border-radius:14px;padding:18px 24px;margin-bottom:20px;display:flex;align-items:center;gap:14px;">
            <i class="fas fa-calendar-xmark" style="font-size:1.8rem;color:#d97706;"></i>
            <div>
                <strong style="font-size:1rem;color:#92400e;">No Classes Today</strong>
                <p style="margin:2px 0 0;font-size:0.85rem;color:#a16207;"><?= htmlspecialchars($non_school_reason ?? 'Non-school day') ?> — Attendance data shown is for reference only.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Stats -->
        <div class="stats-grid" style="grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));">
            <div class="stat-card primary">
                <div class="stat-icon primary"><i class="fas fa-school"></i></div>
                <div class="stat-info"><h3><?= $total_schools ?></h3><span>Schools</span></div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon success"><i class="fas fa-user-check"></i></div>
                <div class="stat-info"><h3><?= $timed_in_today ?></h3><span>Students Present</span></div>
            </div>
            <div class="stat-card error">
                <div class="stat-icon error"><i class="fas fa-user-times"></i></div>
                <div class="stat-info"><h3><?= $absent_today ?></h3><span>Students Absent</span></div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon warning"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-info"><h3><?= count($flagged_students) ?></h3><span>2-Day Flagged</span></div>
            </div>
            <div class="stat-card info">
                <div class="stat-icon info"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-info"><h3><?= $teachers_in ?>/<?= $total_teachers ?></h3><span>Teachers Present</span></div>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- Per-School Breakdown -->
            <div class="card full">
                <div class="card-title"><i class="fas fa-school"></i> School Attendance Breakdown</div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>School</th><th>Enrolled</th><th>Present</th><th>Absent</th><th>Rate</th><th>Teachers</th><th>Progress</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($school_breakdown)): ?>
                                <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">No schools found.</td></tr>
                            <?php else: foreach ($school_breakdown as $sb): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($sb['name']) ?></strong></td>
                                <td><?= $sb['enrolled'] ?></td>
                                <td><span class="text-success fw-700"><?= $sb['present'] ?></span></td>
                                <td><span class="text-error fw-700"><?= $sb['absent'] ?></span></td>
                                <td><span class="fw-700" style="color:<?= $sb['rate'] >= 90 ? '#16a34a' : ($sb['rate'] >= 75 ? '#d97706' : '#dc2626') ?>;"><?= $sb['rate'] ?>%</span></td>
                                <td><span class="text-primary"><?= $sb['teachers_present'] ?></span>/<?= $sb['total_teachers'] ?></td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="fill" style="width:<?= $sb['rate'] ?>%; background:var(--success); border-radius:3px 0 0 3px;"></div>
                                        <div class="fill" style="width:<?= 100 - $sb['rate'] ?>%; background:var(--error); border-radius:0 3px 3px 0;"></div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 2-Day Flagged Students -->
            <div class="card">
                <div class="card-title"><i class="fas fa-exclamation-triangle" style="color:var(--warning);"></i> 2-Day Consecutive Absences <span class="badge badge-warning" style="margin-left:8px;"><?= count($flagged_students) ?></span></div>
                <div style="max-height:400px;overflow-y:auto;">
                    <?php if (empty($flagged_students)): ?>
                        <div class="empty-state" style="padding:30px;"><i class="fas fa-check-circle" style="color:var(--success);opacity:0.3;"></i><h3 style="color:var(--success);">No flags!</h3><p>All students have been attending.</p></div>
                    <?php else: foreach ($flagged_students as $fs): ?>
                        <div class="flag-item">
                            <div>
                                <div style="font-weight:700;font-size:0.9rem;"><?= htmlspecialchars($fs['name']) ?></div>
                                <div style="font-size:0.75rem;color:var(--text-muted);">LRN: <?= htmlspecialchars($fs['lrn']) ?> · <?= htmlspecialchars($fs['grade_name'] ?? '') ?> — <?= htmlspecialchars($fs['section_name'] ?? '') ?></div>
                            </div>
                            <div style="text-align:right;">
                                <span class="badge badge-info" style="font-size:0.65rem;"><?= htmlspecialchars($fs['school_code'] ?? '') ?></span>
                                <div style="font-size:0.7rem;color:var(--warning);font-weight:600;margin-top:4px;">2+ days</div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Teacher Attendance Summary -->
            <div class="card">
                <div class="card-title"><i class="fas fa-chalkboard-teacher" style="color:var(--info);"></i> Teacher Attendance</div>
                <div style="max-height:400px;overflow-y:auto;">
                    <?php foreach ($school_breakdown as $sb): ?>
                        <div class="school-row">
                            <div class="school-info">
                                <div>
                                    <div style="font-weight:600;font-size:0.85rem;"><?= htmlspecialchars($sb['name']) ?></div>
                                    <div style="font-size:0.72rem;color:var(--text-muted);"><?= $sb['teachers_present'] ?> of <?= $sb['total_teachers'] ?> present</div>
                                </div>
                            </div>
                            <div style="font-size:1.1rem;font-weight:800;color:<?= $sb['total_teachers'] > 0 && $sb['teachers_present'] == $sb['total_teachers'] ? 'var(--success)' : 'var(--warning)' ?>;">
                                <?= $sb['total_teachers'] > 0 ? round(($sb['teachers_present'] / $sb['total_teachers']) * 100) . '%' : '—' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($school_breakdown)): ?>
                        <div class="empty-state" style="padding:30px;"><p>No data.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>



    <script>
    // Auto-refresh every 60 seconds
    setTimeout(() => location.reload(), 60000);
    </script>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
