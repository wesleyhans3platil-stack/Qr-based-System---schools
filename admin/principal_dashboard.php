    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(function() {
    const API_URL = '../api/dashboard_data.php';
    const POLL_INTERVAL_MS = 2000;
    let lastTs = null;
        // Helper to update dashboard UI (reload page for now)
        function updateDashboard(data) {
            if (!data || typeof data !== 'object') return;
    // Fallback polling
    let pollTimeout;
    function poll() {
        // WebSocket client for real-time push
        (function setupWebSocket() {
            const WS_URL = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') ? 'ws://127.0.0.1:3001' : 'ws://' + window.location.hostname + ':3001';
<?php
session_start();
require_once '../config/database.php';
require_once '../config/school_days.php';
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'principal') {
    header('Location: ../admin_login.php');
    exit;
}

$conn = getDBConnection();
$current_page = 'dashboard';
$page_title = 'Principal Dashboard';
$admin_school_id = $_SESSION['admin_school_id'];
$filter_date = $_GET['date'] ?? date('Y-m-d');
$is_school_day = isSchoolDay($filter_date, $conn, $admin_school_id);
$non_school_reason = getNonSchoolDayReason($filter_date, $conn, $admin_school_id);

// Find previous school day (for 2-day consecutive logic)
$prev_school_day = date('Y-m-d', strtotime($filter_date . ' -1 day'));
for ($try = 0; $try < 10; $try++) {
    if (isSchoolDay($prev_school_day, $conn, $admin_school_id)) break;
    $prev_school_day = date('Y-m-d', strtotime($prev_school_day . ' -1 day'));
}
$yesterday = $prev_school_day;

// School info
$school = null;
$r = $conn->query("SELECT * FROM schools WHERE id = $admin_school_id");
if ($r) $school = $r->fetch_assoc();
$school_name = $school['name'] ?? 'My School';

// Total students (active + inactive)
$total_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE school_id = $admin_school_id");
if ($r) $total_students = $r->fetch_assoc()['cnt'];

// Total active students (for attendance/absent calculations)
$active_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND school_id = $admin_school_id");
if ($r) $active_students = $r->fetch_assoc()['cnt'];

// Total teachers
$total_teachers = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active' AND school_id = $admin_school_id");
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

// Students present today (active students only)
$students_present = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL AND a.school_id = $admin_school_id");
if ($r) $students_present = $r->fetch_assoc()['cnt'];

// Students timed out (active students only)
$students_timed_out = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_out IS NOT NULL AND a.school_id = $admin_school_id");
if ($r) $students_timed_out = $r->fetch_assoc()['cnt'];

// Students absent (active students only)
$students_present = min($students_present, $active_students);
$students_absent = max(0, $active_students - $students_present);

// Late students
$students_late = 0;
$r = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='student' AND date='$filter_date' AND status='late' AND school_id = $admin_school_id");
if ($r) $students_late = $r->fetch_assoc()['cnt'];

// Teachers present
$teachers_present = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.date='$filter_date' AND a.time_in IS NOT NULL AND a.school_id = $admin_school_id");
if ($r) $teachers_present = $r->fetch_assoc()['cnt'];

$teachers_present = min($teachers_present, $total_teachers);
$teachers_absent = max(0, $total_teachers - $teachers_present);

// Attendance percentage (capped at 100%)
$att_pct = $total_students > 0 ? min(100, round(($students_present / $total_students) * 100, 1)) : 0;

// 2-day consecutive absentees
$consecutive_absent = [];
$sql = "SELECT s.id, s.lrn, s.name, s.created_at, s.active_from, s.school_id, gl.name as grade, sec.name as section
        FROM students s
        JOIN grade_levels gl ON s.grade_level_id = gl.id
        JOIN sections sec ON s.section_id = sec.id
        WHERE s.status='active' AND s.school_id = $admin_school_id
        AND DATE(COALESCE(s.active_from, s.created_at)) < '$filter_date'
        AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)
        AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday' AND time_in IS NOT NULL)";
$r = $conn->query($sql . " ORDER BY gl.name, sec.name, s.name LIMIT 200");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $enroll_date = null;
        if (!empty($row['active_from'])) $enroll_date = date('Y-m-d', strtotime($row['active_from']));
        elseif (!empty($row['created_at'])) $enroll_date = date('Y-m-d', strtotime($row['created_at']));
        $range_start = date('Y-m-d', strtotime("-30 days", strtotime($filter_date)));
        if ($enroll_date && $enroll_date > $range_start) $range_start = $enroll_date;
        $sd_count = 0;
        $d = $range_start;
        while ($d <= $filter_date) {
            if (isSchoolDay($d, $conn, $row['school_id'] ?? $admin_school_id)) $sd_count++;
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
        $pid = (int)$row['id'];
        $safe_start = $conn->real_escape_string($range_start);
        $safe_end = $conn->real_escape_string($filter_date);
        $att_r = $conn->query("SELECT COUNT(DISTINCT date) as cnt FROM attendance WHERE person_type='student' AND person_id = $pid AND time_in IS NOT NULL AND date BETWEEN '$safe_start' AND '$safe_end'");
        $att_cnt = 0; if ($att_r) $att_cnt = (int)($att_r->fetch_assoc()['cnt'] ?? 0);
        $row['total_absent'] = max(0, $sd_count - $att_cnt);
        $consecutive_absent[] = $row;
    }
}

// Section breakdown
$section_data = [];
$sql = "SELECT sec.id, sec.name as section_name, gl.name as grade_name,
        (SELECT COUNT(*) FROM students st WHERE st.section_id = sec.id AND st.status='active') as total,
        (SELECT COUNT(DISTINCT a.person_id) FROM attendance a JOIN students st ON a.person_id = st.id WHERE st.section_id = sec.id AND a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
        (SELECT COUNT(DISTINCT a.person_id) FROM attendance a JOIN students st ON a.person_id = st.id WHERE st.section_id = sec.id AND a.person_type='student' AND a.date='$filter_date' AND a.status='late') as late_count
        FROM sections sec
        JOIN grade_levels gl ON sec.grade_level_id = gl.id
        WHERE sec.school_id = $admin_school_id AND sec.status='active'
        ORDER BY gl.id, sec.name";
$r = $conn->query($sql);
if ($r) while ($row = $r->fetch_assoc()) $section_data[] = $row;

// Recent scan logs (last 20)
$scan_logs = [];
$sql = "SELECT a.*, 
        CASE WHEN a.person_type='student' THEN (SELECT name FROM students WHERE id=a.person_id) ELSE (SELECT name FROM teachers WHERE id=a.person_id) END as person_name,
        CASE WHEN a.person_type='student' THEN (SELECT lrn FROM students WHERE id=a.person_id) ELSE (SELECT employee_id FROM teachers WHERE id=a.person_id) END as person_code
        FROM attendance a
        WHERE a.date='$filter_date' AND a.school_id = $admin_school_id
        ORDER BY a.created_at DESC LIMIT 20";
$r = $conn->query($sql);
if ($r) while ($row = $r->fetch_assoc()) $scan_logs[] = $row;

// Weekly trend (last 7 school days)
$trend_data = [];
$d = $filter_date;
for ($count = 0; $count < 7; $count++) {
    if ($count > 0) $d = date('Y-m-d', strtotime($d . ' -1 day'));
    while (!isSchoolDay($d, $conn, $admin_school_id) && $d > date('Y-m-d', strtotime('-60 days'))) {
        $d = date('Y-m-d', strtotime($d . ' -1 day'));
    }
    $cnt = 0;
    $r2 = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='student' AND date='$d' AND time_in IS NOT NULL AND school_id = $admin_school_id");
    if ($r2) $cnt = $r2->fetch_assoc()['cnt'];
    $day_total = 0;
    $r2 = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND school_id = $admin_school_id AND DATE(created_at) <= '$d'");
    if ($r2) $day_total = $r2->fetch_assoc()['cnt'];
    array_unshift($trend_data, ['date' => date('M d', strtotime($d)), 'present' => $cnt, 'absent' => max(0, $day_total - $cnt)]);
    $d = date('Y-m-d', strtotime($d . ' -1 day'));
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
        <div class="top-bar">
            <div class="page-header">
                <h1><i class="fas fa-school" style="color:var(--primary); margin-right:8px;"></i> <?= htmlspecialchars($school_name) ?></h1>
                <p>Principal Dashboard — School Attendance Overview</p>
            </div>
            <div class="top-bar-right">
                <form method="GET" style="display:flex; align-items:center; gap:8px;">
                    <input type="date" name="date" value="<?= $filter_date ?>" class="form-control" style="width:180px;" onchange="this.form.submit()">
                </form>
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

        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-icon primary"><i class="fas fa-user-graduate"></i></div>
                <div class="stat-info">
                    <h3><?= $total_students ?></h3>
                    <span>Total Students</span>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <h3><?= $students_present ?></h3>
                    <span>Present Today</span>
                </div>
            </div>
            <div class="stat-card error">
                <div class="stat-icon error"><i class="fas fa-times-circle"></i></div>
                <div class="stat-info">
                    <h3><?= $students_absent ?></h3>
                    <span>Absent Today</span>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <h3><?= $students_late ?></h3>
                    <span>Late Today</span>
                </div>
            </div>
            <div class="stat-card info">
                <div class="stat-icon info"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-info">
                    <h3><?= $teachers_present ?>/<?= $total_teachers ?></h3>
                    <span>Teachers Present</span>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon warning"><i class="fas fa-user-slash"></i></div>
                <div class="stat-info">
                    <h3><?= max(0, $total_students - $active_students) ?></h3>
                    <span>Inactive Students</span>
                </div>
            </div>
            <div class="stat-card" style="border-left:4px solid var(--primary);">
                <div class="stat-icon primary"><i class="fas fa-percentage"></i></div>
                <div class="stat-info">
                    <h3><?= $att_pct ?>%</h3>
                    <span>Attendance Rate</span>
                </div>
            </div>
        </div>

        <!-- Charts and Section Breakdown -->
        <div class="grid-2" style="margin-bottom:24px;">
            <!-- Weekly Trend Chart -->
            <div class="card">
                <div class="card-title"><i class="fas fa-chart-line"></i> Weekly Attendance Trend</div>
                <div class="chart-container" style="height:260px;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <!-- Teacher Attendance -->
            <div class="card">
                <div class="card-title"><i class="fas fa-chalkboard-teacher"></i> Teacher Attendance</div>
                <div style="display:flex; justify-content:center; margin-top:20px;">
                    <div class="chart-container" style="height:220px; max-width:220px;">
                        <canvas id="teacherChart"></canvas>
                    </div>
                </div>
                <div style="text-align:center; margin-top:16px;">
                    <span class="badge badge-success"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Present: <?= $teachers_present ?></span>
                    <span class="badge badge-error" style="margin-left:8px;"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Absent: <?= $teachers_absent ?></span>
                </div>
            </div>
        </div>

        <!-- Section Breakdown -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-title"><i class="fas fa-layer-group"></i> Section Breakdown</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Grade Level</th>
                            <th>Section</th>
                            <th>Total</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Late</th>
                            <th>Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($section_data)): ?>
                        <tr><td colspan="7" class="text-muted" style="text-align:center; padding:30px;">No sections found</td></tr>
                        <?php else: foreach ($section_data as $sec):
                            $sec_absent = max(0, $sec['total'] - $sec['present']);
                            $sec_rate = $sec['total'] > 0 ? min(100, round(($sec['present'] / $sec['total']) * 100, 1)) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sec['grade_name']) ?></strong></td>
                            <td><?= htmlspecialchars($sec['section_name']) ?></td>
                            <td><?= $sec['total'] ?></td>
                            <td><span class="text-success fw-600"><?= $sec['present'] ?></span></td>
                            <td><span class="text-error fw-600"><?= $sec_absent ?></span></td>
                            <td><span class="text-warning fw-600"><?= $sec['late_count'] ?></span></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div class="progress-bar" style="width:80px;">
                                        <div style="width:<?= $sec_rate ?>%; height:100%; background:#16a34a; border-radius:5px 0 0 5px;"></div>
                                        <div style="width:<?= 100 - $sec_rate ?>%; height:100%; background:#dc2626; border-radius:0 5px 5px 0;"></div>
                                    </div>
                                    <span class="fw-600" style="font-size:0.8rem; color:<?= $sec_rate >= 90 ? '#16a34a' : ($sec_rate >= 75 ? '#d97706' : '#dc2626') ?>;"><?= $sec_rate ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid-2">
            <!-- Consecutive Absentees -->
            <div class="card">
                <div class="card-title"><i class="fas fa-exclamation-triangle" style="color:var(--error);"></i> 2-Day Consecutive Absentees <span class="badge badge-error" style="margin-left:auto;"><?= count($consecutive_absent) ?></span></div>
                <?php if (empty($consecutive_absent)): ?>
                    <div class="empty-state" style="padding:30px;">
                        <i class="fas fa-check-circle" style="color:var(--success); font-size:2rem;"></i>
                        <h3 style="color:var(--success);">All Clear</h3>
                        <p>No students with 2 consecutive days absent.</p>
                    </div>
                <?php else: ?>
                    <div style="max-height:350px; overflow-y:auto;">
                    <?php foreach ($consecutive_absent as $abs): ?>
                        <div class="absence-flag">
                            <div class="flag-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <div class="flag-info">
                                <strong><?= htmlspecialchars($abs['name']) ?></strong>
                                <span>LRN: <?= htmlspecialchars($abs['lrn']) ?> &bull; <?= htmlspecialchars($abs['grade']) ?> — <?= htmlspecialchars($abs['section']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Scan Logs -->
            <div class="card">
                <div class="card-title"><i class="fas fa-history"></i> Recent QR Scan Logs</div>
                <div style="max-height:350px; overflow-y:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($scan_logs)): ?>
                            <tr><td colspan="5" class="text-muted" style="text-align:center;">No scans yet</td></tr>
                            <?php else: foreach ($scan_logs as $log): ?>
                            <tr>
                                <td>
                                    <strong style="font-size:0.85rem;"><?= htmlspecialchars($log['person_name'] ?? 'Unknown') ?></strong><br>
                                    <span style="font-size:0.72rem; color:var(--text-muted);"><?= htmlspecialchars($log['person_code'] ?? '') ?></span>
                                </td>
                                <td><span class="badge <?= $log['person_type'] === 'student' ? 'badge-primary' : 'badge-info' ?>"><?= ucfirst($log['person_type']) ?></span></td>
                                <td style="font-size:0.85rem;"><?= formatTime($log['time_in']) ?></td>
                                <td style="font-size:0.85rem;"><?= formatTime($log['time_out']) ?></td>
                                <td>
                                    <span class="badge <?= $log['status'] === 'present' ? 'badge-success' : ($log['status'] === 'late' ? 'badge-warning' : 'badge-error') ?>">
                                        <?= ucfirst($log['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Weekly trend chart
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($trend_data, 'date')) ?>,
            datasets: [
                {
                    label: 'Present',
                    data: <?= json_encode(array_column($trend_data, 'present')) ?>,
                    backgroundColor: 'rgba(22,163,74,0.7)',
                    borderRadius: 6,
                    barPercentage: 0.6
                },
                {
                    label: 'Absent',
                    data: <?= json_encode(array_column($trend_data, 'absent')) ?>,
                    backgroundColor: 'rgba(220,38,38,0.7)',
                    borderRadius: 6,
                    barPercentage: 0.6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, beginAtZero: true, grid: { color: '#e2e8f0' } }
            }
        }
    });

    // Teacher doughnut
    const teacherCtx = document.getElementById('teacherChart').getContext('2d');
    new Chart(teacherCtx, {
        type: 'doughnut',
        data: {
            labels: ['Present', 'Absent'],
            datasets: [{
                data: [<?= $teachers_present ?>, <?= $teachers_absent ?>],
                backgroundColor: ['#16a34a', '#dc2626'],
                borderWidth: 0,
                cutout: '70%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });

    // Auto-refresh every 60 seconds
    setTimeout(() => location.reload(), 60000);
    </script>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
            connect();
        })();

        poll();
    });
    </script>
</body>
</html>
