<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'], ['super_admin','principal'])) {
    header('Location: ../admin_login.php'); exit;
}
$conn = getDBConnection();

$current_page = 'teachers';
$page_title = 'Teachers';
$success = '';
$error = '';

// Handle Add Teacher
if (isset($_POST['add_teacher'])) {
    $employee_id = sanitize($_POST['employee_id'] ?? '');
    $name = sanitize($_POST['name'] ?? '');
    $school_id = (int)$_POST['school_id'];
    $contact = sanitize($_POST['contact_number'] ?? '');

    if (empty($employee_id) || empty($name) || !$school_id) {
        $error = 'Employee ID, Name, and School are required.';
    } else {
        $qr_code = 'TCH-' . $employee_id;
        $stmt = $conn->prepare("INSERT INTO teachers (employee_id, name, school_id, contact_number, qr_code) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiss", $employee_id, $name, $school_id, $contact, $qr_code);
        if ($stmt->execute()) {
            $success = 'Teacher registered successfully!';
        } else {
            $error = 'Failed. Employee ID may already exist.';
        }
    }
}

// Handle Edit
if (isset($_POST['edit_teacher'])) {
    $id = (int)$_POST['teacher_id'];
    $employee_id = sanitize($_POST['employee_id'] ?? '');
    $name = sanitize($_POST['name'] ?? '');
    $school_id = (int)$_POST['school_id'];
    $contact = sanitize($_POST['contact_number'] ?? '');
    $status = sanitize($_POST['status'] ?? 'active');
    $qr_code = 'TCH-' . $employee_id;

    $stmt = $conn->prepare("UPDATE teachers SET employee_id=?, name=?, school_id=?, contact_number=?, qr_code=?, status=? WHERE id=?");
    $stmt->bind_param("ssisssi", $employee_id, $name, $school_id, $contact, $qr_code, $status, $id);
    if ($stmt->execute()) { $success = 'Teacher updated!'; } else { $error = 'Failed to update.'; }
}

// Handle Delete
if (isset($_POST['delete_teacher'])) {
    $id = (int)$_POST['teacher_id'];
    $conn->query("DELETE FROM teachers WHERE id = $id");
    $success = 'Teacher deleted.';
}

// Filters
$filter_school = (int)($_GET['school'] ?? 0);
$search = sanitize($_GET['search'] ?? '');

$where = ["1=1"];
$params = []; $types = '';
if ($filter_school) { $where[] = "t.school_id = ?"; $params[] = $filter_school; $types .= 'i'; }
if ($search) { $where[] = "(t.name LIKE ? OR t.employee_id LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $types .= 'ss'; }

if (($_SESSION['admin_role'] ?? '') === 'principal' && ($_SESSION['admin_school_id'] ?? 0)) {
    $where[] = "t.school_id = ?"; $params[] = $_SESSION['admin_school_id']; $types .= 'i';
}

$sql = "SELECT t.*, sch.name as school_name, sch.code as school_code
        FROM teachers t LEFT JOIN schools sch ON t.school_id = sch.id
        WHERE " . implode(' AND ', $where) . " ORDER BY sch.name, t.name";

$teachers = [];
if ($types) { $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute(); $result = $stmt->get_result(); }
else { $result = $conn->query($sql); }
if ($result) { while ($row = $result->fetch_assoc()) $teachers[] = $row; }

$schools = []; $r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $schools[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/header.php'; ?></head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-chalkboard-teacher" style="color:var(--primary);margin-right:8px;"></i> Teachers</h1>
            <p>Manage teacher profiles and QR codes</p>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= $error ?></div><?php endif; ?>

        <form method="GET" class="filters-bar">
            <div class="filter-group">
                <label>School</label>
                <select name="school" class="form-control" onchange="this.form.submit()">
                    <option value="">All Schools</option>
                    <?php foreach ($schools as $sch): ?>
                        <option value="<?= $sch['id'] ?>" <?= $filter_school == $sch['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Name or Employee ID...">
            </div>
            <div class="filter-group" style="justify-content:flex-end;">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
            </div>
        </form>

        <div class="toolbar">
            <span style="color:var(--text-muted);font-size:0.85rem;"><?= count($teachers) ?> teacher(s)</span>
            <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('active')">
                <i class="fas fa-plus"></i> Add Teacher
            </button>
        </div>

        <div class="card" style="padding:0;">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>School</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teachers)): ?>
                            <tr><td colspan="6"><div class="empty-state"><i class="fas fa-chalkboard-teacher"></i><h3>No teachers found</h3></div></td></tr>
                        <?php else: foreach ($teachers as $t): ?>
                            <tr>
                                <td><code style="color:var(--info);font-weight:600;"><?= htmlspecialchars($t['employee_id']) ?></code></td>
                                <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                                <td><span class="badge badge-info"><?= htmlspecialchars($t['school_code'] ?? '') ?></span> <?= htmlspecialchars($t['school_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($t['contact_number'] ?: '—') ?></td>
                                <td><span class="badge <?= $t['status']==='active' ? 'badge-success' : 'badge-error' ?>"><?= ucfirst($t['status']) ?></span></td>
                                <td>
                                    <div class="action-btns">
                                        <button class="action-btn action-btn-view" onclick='showQR(<?= json_encode($t) ?>)'><i class="fas fa-qrcode"></i></button>
                                        <button class="action-btn action-btn-edit" onclick='editTeacher(<?= json_encode($t) ?>)'><i class="fas fa-edit"></i></button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?')">
                                            <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                                            <button type="submit" name="delete_teacher" class="action-btn action-btn-delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal">
            <div class="modal-header"><h3><i class="fas fa-user-plus" style="color:var(--primary);margin-right:8px;"></i> Add Teacher</h3><button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button></div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label>Employee ID *</label><input type="text" name="employee_id" class="form-control" required placeholder="e.g. T-0001"></div>
                        <div class="form-group"><label>Full Name *</label><input type="text" name="name" class="form-control" required placeholder="Full Name"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>School *</label>
                            <select name="school_id" class="form-control" required>
                                <option value="">Select School</option>
                                <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" class="form-control" placeholder="09XXXXXXXXX"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="add_teacher" class="btn btn-primary"><i class="fas fa-save"></i> Register</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header"><h3><i class="fas fa-edit" style="color:var(--primary);margin-right:8px;"></i> Edit Teacher</h3><button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button></div>
            <form method="POST">
                <input type="hidden" name="teacher_id" id="edit_id">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label>Employee ID *</label><input type="text" name="employee_id" id="edit_eid" class="form-control" required></div>
                        <div class="form-group"><label>Full Name *</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>School *</label>
                            <select name="school_id" id="edit_school" class="form-control" required>
                                <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Contact</label><input type="text" name="contact_number" id="edit_contact" class="form-control"></div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="edit_teacher" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- QR Modal -->
    <div class="modal-overlay" id="qrModal">
        <div class="modal" style="max-width:360px;text-align:center;">
            <div class="modal-header"><h3>Teacher QR Code</h3><button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button></div>
            <div class="modal-body">
                <div id="qr_display" style="margin:20px auto;"></div>
                <p id="qr_name" style="font-weight:700;font-size:1.1rem;margin-top:12px;"></p>
                <p id="qr_eid" style="color:var(--text-muted);font-size:0.85rem;"></p>
            </div>
        </div>
    </div>

    <script>
    function editTeacher(t) {
        document.getElementById('edit_id').value = t.id;
        document.getElementById('edit_eid').value = t.employee_id;
        document.getElementById('edit_name').value = t.name;
        document.getElementById('edit_school').value = t.school_id;
        document.getElementById('edit_contact').value = t.contact_number || '';
        document.getElementById('edit_status').value = t.status;
        document.getElementById('editModal').classList.add('active');
    }
    function showQR(t) {
        const qr = qrcode(0, 'M'); qr.addData(t.qr_code); qr.make();
        document.getElementById('qr_display').innerHTML = qr.createSvgTag(6, 0);
        document.getElementById('qr_name').textContent = t.name;
        document.getElementById('qr_eid').textContent = 'Employee ID: ' + t.employee_id;
        document.getElementById('qrModal').classList.add('active');
    }
    </script>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
