<?php
session_start();
require_once '../config/database.php';
require_once '../config/school_days.php';
$conn = getDBConnection();

if (!isset($_SESSION['admin_id'])) { header('Location: ../admin_login.php'); exit; }

$current_page = 'reports';
$page_title = 'Reports';

$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;

$report_type = sanitize($_GET['report'] ?? 'daily');
$filter_school = (int)($_GET['school'] ?? 0);
$filter_date = sanitize($_GET['date'] ?? date('Y-m-d'));
$filter_from = sanitize($_GET['from'] ?? date('Y-m-01'));
$filter_to = sanitize($_GET['to'] ?? date('Y-m-d'));

$school_cond = '';
if ($admin_role === 'principal' && $admin_school_id) $school_cond = " AND a.school_id = $admin_school_id";
if ($filter_school) $school_cond .= " AND a.school_id = $filter_school";

$report_data = [];

switch ($report_type) {
    case 'daily':
        $sql = "SELECT sch.name as school_name, sch.code,
                    (SELECT COUNT(*) FROM students st WHERE st.school_id = sch.id AND st.status='active') as enrolled,
                    COUNT(DISTINCT CASE WHEN a.person_type='student' AND a.time_in IS NOT NULL THEN a.person_id END) as present,
                    COUNT(DISTINCT CASE WHEN a.person_type='student' AND a.status='late' THEN a.person_id END) as late_count,
                    COUNT(DISTINCT CASE WHEN a.person_type='teacher' AND a.time_in IS NOT NULL THEN a.person_id END) as teachers_present,
                    (SELECT COUNT(*) FROM teachers t WHERE t.school_id = sch.id AND t.status='active') as total_teachers
                FROM schools sch
                LEFT JOIN attendance a ON a.school_id = sch.id AND a.date = '$filter_date'
                WHERE sch.status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND sch.id = $admin_school_id" : "") .
                ($filter_school ? " AND sch.id = $filter_school" : "") . "
                GROUP BY sch.id ORDER BY sch.name";
        $r = $conn->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) {
            $row['present'] = min($row['present'], $row['enrolled']);
            $row['absent'] = max(0, $row['enrolled'] - $row['present']);
            $row['rate'] = $row['enrolled'] > 0 ? min(100, round(($row['present'] / $row['enrolled']) * 100, 1)) : 0;
            $report_data[] = $row;
        }
        break;

    case 'weekly':
    case 'monthly':
        // Attendance trend by day
        $sql = "SELECT a.date,
                    COUNT(DISTINCT CASE WHEN a.person_type='student' AND a.time_in IS NOT NULL THEN a.person_id END) as present,
                    COUNT(DISTINCT CASE WHEN a.person_type='student' AND a.status='late' THEN a.person_id END) as late_count
                FROM attendance a
                WHERE a.date BETWEEN '$filter_from' AND '$filter_to' $school_cond
                GROUP BY a.date ORDER BY a.date";
        $r = $conn->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) $report_data[] = $row;
        break;

    case 'absentees':
        // Students absent today
        $sql = "SELECT s.lrn, s.name, sch.name as school_name, sch.code as school_code, gl.name as grade_name, sec.name as section_name
                FROM students s
                LEFT JOIN schools sch ON s.school_id = sch.id
                LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE s.status='active'
                AND s.id NOT IN (SELECT person_id FROM attendance WHERE person_type='student' AND date='$filter_date')
                " . ($admin_role === 'principal' && $admin_school_id ? "AND s.school_id = $admin_school_id" : "") .
                ($filter_school ? " AND s.school_id = $filter_school" : "") . "
                ORDER BY sch.name, gl.id, s.name";
        $r = $conn->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) $report_data[] = $row;
        break;

    case 'comparison':
        // School comparison for date range
        $sql = "SELECT sch.name as school_name, sch.code,
                    (SELECT COUNT(*) FROM students st WHERE st.school_id = sch.id AND st.status='active') as enrolled,
                    COUNT(DISTINCT a.date) as days_with_data,
                    ROUND(AVG(daily_present.present_count), 0) as avg_present
                FROM schools sch
                LEFT JOIN attendance a ON a.school_id = sch.id AND a.date BETWEEN '$filter_from' AND '$filter_to' AND a.person_type='student'
                LEFT JOIN (
                    SELECT school_id, date, COUNT(DISTINCT person_id) as present_count
                    FROM attendance WHERE person_type='student' AND date BETWEEN '$filter_from' AND '$filter_to' AND time_in IS NOT NULL
                    GROUP BY school_id, date
                ) daily_present ON daily_present.school_id = sch.id
                WHERE sch.status='active'
                GROUP BY sch.id ORDER BY sch.name";
        $r = $conn->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) {
            $row['avg_rate'] = $row['enrolled'] > 0 && $row['avg_present'] ? round(($row['avg_present'] / $row['enrolled']) * 100, 1) : 0;
            $report_data[] = $row;
        }
        break;
}

$schools_list = []; $r = $conn->query("SELECT id, name FROM schools WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $schools_list[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/header.php'; ?></head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-chart-bar" style="color:var(--primary);margin-right:8px;"></i> Reports</h1>
            <p>Generate and export attendance reports</p>
        </div>

        <?php 
        $report_date_check = ($report_type === 'daily' || $report_type === 'absentees') ? $filter_date : null;
        if ($report_date_check && !isSchoolDay($report_date_check, $conn)):
            $reason = getNonSchoolDayReason($report_date_check, $conn);
        ?>
        <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #f59e0b;border-radius:14px;padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
            <i class="fas fa-calendar-xmark" style="font-size:1.3rem;color:#d97706;"></i>
            <div>
                <strong style="color:#92400e;">Non-School Day</strong>
                <span style="font-size:0.82rem;color:#a16207;margin-left:6px;"><?= htmlspecialchars($reason ?? 'No classes on this date') ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Report Type Tabs -->
        <div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">
            <?php
            $tabs = ['daily' => 'Daily Summary', 'weekly' => 'Trend', 'absentees' => 'Absentee List', 'comparison' => 'School Comparison'];
            foreach ($tabs as $key => $label): ?>
                <a href="?report=<?= $key ?>&school=<?= $filter_school ?>&date=<?= $filter_date ?>&from=<?= $filter_from ?>&to=<?= $filter_to ?>"
                   class="btn <?= $report_type === $key ? 'btn-primary' : 'btn-outline' ?> btn-sm" style="text-decoration:none;">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Filters -->
        <form method="GET" class="filters-bar">
            <input type="hidden" name="report" value="<?= $report_type ?>">
            <div class="filter-group">
                <label>School</label>
                <select name="school" class="form-control" onchange="this.form.submit()">
                    <option value="">All Schools</option>
                    <?php foreach ($schools_list as $sch): ?>
                        <option value="<?= $sch['id'] ?>" <?= $filter_school == $sch['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($report_type === 'daily' || $report_type === 'absentees'): ?>
                <div class="filter-group"><label>Date</label><input type="date" name="date" class="form-control" value="<?= $filter_date ?>" onchange="this.form.submit()"></div>
            <?php else: ?>
                <div class="filter-group"><label>From</label><input type="date" name="from" class="form-control" value="<?= $filter_from ?>" onchange="this.form.submit()"></div>
                <div class="filter-group"><label>To</label><input type="date" name="to" class="form-control" value="<?= $filter_to ?>" onchange="this.form.submit()"></div>
            <?php endif; ?>
            <div class="filter-group" style="justify-content:flex-end;"><label>&nbsp;</label>
                <a href="export_report.php?report=<?= $report_type ?>&school=<?= $filter_school ?>&date=<?= $filter_date ?>&from=<?= $filter_from ?>&to=<?= $filter_to ?>&format=csv" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> CSV</a>
            </div>
        </form>

        <!-- Report Content -->
        <div class="card" style="padding:0;">
            <?php if ($report_type === 'daily'): ?>
                <div class="table-wrapper">
                    <table class="responsive-stack-table">
                        <thead><tr><th>School</th><th>Enrolled</th><th>Present</th><th>Late</th><th>Absent</th><th>Rate</th><th>Teachers</th></tr></thead>
                        <tbody>
                            <?php if (empty($report_data)): ?><tr><td colspan="7"><div class="empty-state"><i class="fas fa-chart-bar"></i><h3>No data</h3></div></td></tr>
                            <?php else: foreach ($report_data as $d): ?>
                                <tr>
                                    <td data-label="School"><strong><?= htmlspecialchars($d['school_name']) ?></strong></td>
                                    <td data-label="Enrolled"><?= $d['enrolled'] ?></td>
                                    <td data-label="Present" class="text-success fw-700"><?= $d['present'] ?></td>
                                    <td data-label="Late" class="text-warning fw-700"><?= $d['late_count'] ?></td>
                                    <td data-label="Absent" class="text-error fw-700"><?= $d['absent'] ?></td>
                                    <td data-label="Rate"><strong style="color:<?= $d['rate'] >= 90 ? '#16a34a' : ($d['rate'] >= 75 ? '#d97706' : '#dc2626') ?>;"><?= $d['rate'] ?>%</strong></td>
                                    <td data-label="Teachers"><?= $d['teachers_present'] ?>/<?= $d['total_teachers'] ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($report_type === 'weekly' || $report_type === 'monthly'): ?>
                <div class="table-wrapper">
                    <table class="responsive-stack-table">
                        <thead><tr><th>Date</th><th>Day</th><th>Present</th><th>Late</th></tr></thead>
                        <tbody>
                            <?php if (empty($report_data)): ?><tr><td colspan="4"><div class="empty-state"><h3>No data for this period</h3></div></td></tr>
                            <?php else: foreach ($report_data as $d): ?>
                                <tr>
                                    <td data-label="Date"><strong><?= date('M j, Y', strtotime($d['date'])) ?></strong></td>
                                    <td data-label="Day"><?= date('l', strtotime($d['date'])) ?></td>
                                    <td data-label="Present" class="text-success fw-700"><?= $d['present'] ?></td>
                                    <td data-label="Late" class="text-warning fw-700"><?= $d['late_count'] ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($report_type === 'absentees'): ?>
                <div class="table-wrapper">
                    <table class="responsive-stack-table">
                        <thead><tr><th>LRN</th><th>Name</th><th>School</th><th>Grade</th><th>Section</th></tr></thead>
                        <tbody>
                            <?php if (empty($report_data)): ?><tr><td colspan="5"><div class="empty-state"><i class="fas fa-check-circle" style="color:var(--success);"></i><h3>No absentees!</h3></div></td></tr>
                            <?php else: foreach ($report_data as $d): ?>
                                <tr>
                                    <td data-label="LRN"><code style="color:var(--primary);"><?= htmlspecialchars($d['lrn']) ?></code></td>
                                    <td data-label="Name"><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                                    <td data-label="School"><?= htmlspecialchars($d['school_name'] ?? '') ?></td>
                                    <td data-label="Grade"><?= htmlspecialchars($d['grade_name'] ?? '') ?></td>
                                    <td data-label="Section"><?= htmlspecialchars($d['section_name'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($report_type === 'comparison'): ?>
                <div class="table-wrapper">
                    <table class="responsive-stack-table">
                        <thead><tr><th>School</th><th>Enrolled</th><th>Days Tracked</th><th>Avg Present</th><th>Avg Rate</th></tr></thead>
                        <tbody>
                            <?php if (empty($report_data)): ?><tr><td colspan="5"><div class="empty-state"><h3>No data</h3></div></td></tr>
                            <?php else: foreach ($report_data as $d): ?>
                                <tr>
                                    <td data-label="School"><strong><?= htmlspecialchars($d['school_name']) ?></strong></td>
                                    <td data-label="Enrolled"><?= $d['enrolled'] ?></td>
                                    <td data-label="Days Tracked"><?= $d['days_with_data'] ?></td>
                                    <td data-label="Avg Present" class="fw-700"><?= $d['avg_present'] ?: '—' ?></td>
                                    <td data-label="Avg Rate"><strong><?= $d['avg_rate'] ?>%</strong></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
