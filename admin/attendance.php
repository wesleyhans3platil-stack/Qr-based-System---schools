<?php
session_start();
require_once '../config/database.php';
require_once '../config/school_days.php';
$conn = getDBConnection();

if (!isset($_SESSION['admin_id'])) { header('Location: ../admin_login.php'); exit; }

$current_page = 'attendance';
$page_title = 'Attendance Records';

$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;

$filter_school = (int)($_GET['school'] ?? 0);
$filter_grade = (int)($_GET['grade'] ?? 0);
$filter_date = sanitize($_GET['date'] ?? date('Y-m-d'));
$filter_type = sanitize($_GET['type'] ?? 'student');
$search = sanitize($_GET['search'] ?? '');

$where = ["a.date = '$filter_date'", "a.person_type = '$filter_type'"];
$params = []; $types = '';

if ($filter_school) { $where[] = "(a.school_id = ? OR s.school_id = ?)"; $params[] = $filter_school; $params[] = $filter_school; $types .= 'ii'; }
if ($admin_role === 'principal' && $admin_school_id) { $where[] = "(a.school_id = ? OR s.school_id = ?)"; $params[] = $admin_school_id; $params[] = $admin_school_id; $types .= 'ii'; }

if ($filter_type === 'student') {
    if ($filter_grade) { $where[] = "s.grade_level_id = ?"; $params[] = $filter_grade; $types .= 'i'; }
    if ($search) { $where[] = "(s.name LIKE ? OR s.lrn LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $types .= 'ss'; }

    $sql = "SELECT a.*,
                   s.name as person_name,
                   COALESCE(s.lrn, '') as lrn,
                   sch.name as school_name, sch.code as school_code,
                   gl.name as grade_name, sec.name as section_name
            FROM attendance a
            LEFT JOIN students s ON a.person_id = s.id
            LEFT JOIN schools sch ON COALESCE(NULLIF(a.school_id, 0), s.school_id) = sch.id
            LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
            LEFT JOIN sections sec ON s.section_id = sec.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.time_in DESC";
} else {
    if ($search) { $where[] = "(t.name LIKE ? OR t.employee_id LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $types .= 'ss'; }

    $sql = "SELECT a.*,
                   t.name as person_name,
                   COALESCE(t.employee_id, '') as employee_id,
                   sch.name as school_name, sch.code as school_code
            FROM attendance a
            LEFT JOIN teachers t ON a.person_id = t.id
            LEFT JOIN schools sch ON COALESCE(NULLIF(a.school_id, 0), t.school_id) = sch.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.time_in DESC";
}

$records = [];
if ($types) { $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute(); $result = $stmt->get_result(); }
else { $result = $conn->query($sql); }
if ($result) { while ($row = $result->fetch_assoc()) $records[] = $row; }

$schools = []; $r = $conn->query("SELECT id, name FROM schools WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $schools[] = $row;
$grades = []; $r = $conn->query("SELECT id, name FROM grade_levels ORDER BY id"); if ($r) while ($row = $r->fetch_assoc()) $grades[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/header.php'; ?></head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-clipboard-list" style="color:var(--primary);margin-right:8px;"></i> Attendance Records</h1>
            <p>View daily attendance — <?= date('l, F j, Y', strtotime($filter_date)) ?></p>
        </div>

        <?php if (!isSchoolDay($filter_date, $conn)):
            $reason = getNonSchoolDayReason($filter_date, $conn);
        ?>
        <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #f59e0b;border-radius:14px;padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
            <i class="fas fa-calendar-xmark" style="font-size:1.3rem;color:#d97706;"></i>
            <div>
                <strong style="color:#92400e;">Non-School Day</strong>
                <span style="font-size:0.82rem;color:#a16207;margin-left:6px;"><?= htmlspecialchars($reason ?? 'No classes on this date') ?></span>
            </div>
        </div>
        <?php endif; ?>

        <form method="GET" class="filters-bar">
            <div class="filter-group">
                <label>Type</label>
                <select name="type" class="form-control" onchange="this.form.submit()">
                    <option value="student" <?= $filter_type==='student' ? 'selected' : '' ?>>Students</option>
                    <option value="teacher" <?= $filter_type==='teacher' ? 'selected' : '' ?>>Teachers</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Date</label>
                <input type="date" name="date" class="form-control" value="<?= $filter_date ?>" onchange="this.form.submit()">
            </div>
            <div class="filter-group">
                <label>School</label>
                <select name="school" class="form-control" onchange="this.form.submit()">
                    <option value="">All Schools</option>
                    <?php foreach ($schools as $sch): ?>
                        <option value="<?= $sch['id'] ?>" <?= $filter_school == $sch['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filter_type === 'student'): ?>
            <div class="filter-group">
                <label>Grade</label>
                <select name="grade" class="form-control" onchange="this.form.submit()">
                    <option value="">All Grades</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $filter_grade == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Name...">
            </div>
            <div class="filter-group" style="justify-content:flex-end;">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </div>
        </form>

        <div class="toolbar">
            <span style="color:var(--text-muted);font-size:0.85rem;"><?= count($records) ?> record(s)</span>
        </div>

        <div class="card" style="padding:0;">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th><?= $filter_type === 'student' ? 'LRN' : 'Employee ID' ?></th>
                            <th>School</th>
                            <?php if ($filter_type === 'student'): ?><th>Grade/Section</th><?php endif; ?>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr><td colspan="<?= $filter_type === 'student' ? 7 : 6 ?>"><div class="empty-state"><i class="fas fa-clipboard-list"></i><h3>No records for this date</h3></div></td></tr>
                        <?php else: foreach ($records as $rec): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($rec['person_name'] ?? 'Unknown') ?></strong></td>
                                <td><code style="color:var(--primary);"><?= htmlspecialchars($rec['lrn'] ?? $rec['employee_id'] ?? '') ?></code></td>
                                <td><span class="badge badge-info"><?= htmlspecialchars($rec['school_name'] ?? '') ?></span></td>
                                <?php if ($filter_type === 'student'): ?><td><?= htmlspecialchars(($rec['grade_name'] ?? '') . ' — ' . ($rec['section_name'] ?? '')) ?></td><?php endif; ?>
                                <td>
                                    <?php if ($rec['time_in']): ?>
                                        <span style="color:var(--success);font-weight:600;"><i class="fas fa-sign-in-alt"></i> <?= date('h:i A', strtotime($rec['time_in'])) ?></span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rec['time_out']): ?>
                                        <span style="color:var(--info);font-weight:600;"><i class="fas fa-sign-out-alt"></i> <?= date('h:i A', strtotime($rec['time_out'])) ?></span>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status_class = ['present' => 'badge-success', 'late' => 'badge-warning', 'absent' => 'badge-error'];
                                    $st = strtolower($rec['status']);
                                    ?>
                                    <span class="badge <?= $status_class[$st] ?? 'badge-info' ?>"><?= ucfirst($st) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
