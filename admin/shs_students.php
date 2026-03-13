<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'], ['super_admin','principal'])) {
    header('Location: ../admin_login.php'); exit;
}
$conn = getDBConnection();

$current_page = 'shs_students';
$page_title = 'SHS Students';
$success = '';
$error = '';

// SHS track options
$shs_tracks = ['STEM','ABM','HUMSS','GAS','TVL-HE','TVL-ICT','TVL-IA','TVL-AFA','Sports','Arts & Design'];

// Handle Add Student
if (isset($_POST['add_student'])) {
    $lrn = sanitize($_POST['lrn'] ?? '');
    $name = sanitize($_POST['name'] ?? '');
    $school_id = (int)$_POST['school_id'];
    $grade_level_id = (int)$_POST['grade_level_id'];
    $section_id = (int)$_POST['section_id'];
    $guardian_contact = sanitize($_POST['guardian_contact'] ?? '');
    if (empty($lrn) || empty($name) || !$school_id || !$grade_level_id || !$section_id) {
        $error = 'LRN, Name, School, Grade Level, and Section are required.';
    } else {
        $qr_code = 'STU-' . $lrn;
        // Newly imported SHS students start inactive until first scan.
        $stmt = $conn->prepare("INSERT INTO students (lrn, name, school_id, grade_level_id, section_id, guardian_contact, qr_code, status, active_from) VALUES (?, ?, ?, ?, ?, ?, ?, 'inactive', ?) ");
        $today = date('Y-m-d');
        $stmt->bind_param("ssiissss", $lrn, $name, $school_id, $grade_level_id, $section_id, $guardian_contact, $qr_code, $today);
        if ($stmt->execute()) {
            $success = 'SHS Student registered successfully!';
        } else {
            $error = 'Failed. LRN may already exist.';
        }
    }
}

// Handle Edit Student
if (isset($_POST['edit_student'])) {
    $id = (int)$_POST['student_id'];
    $lrn = sanitize($_POST['lrn'] ?? '');
    $name = sanitize($_POST['name'] ?? '');
    $school_id = (int)$_POST['school_id'];
    $grade_level_id = (int)$_POST['grade_level_id'];
    $section_id = (int)$_POST['section_id'];
    $guardian_contact = sanitize($_POST['guardian_contact'] ?? '');
    $status = sanitize($_POST['status'] ?? 'active');
    $qr_code = 'STU-' . $lrn;

    $stmt = $conn->prepare("UPDATE students SET lrn=?, name=?, school_id=?, grade_level_id=?, section_id=?, guardian_contact=?, qr_code=?, status=? WHERE id=?");
    $stmt->bind_param("ssiissssi", $lrn, $name, $school_id, $grade_level_id, $section_id, $guardian_contact, $qr_code, $status, $id);
    if ($stmt->execute()) {
        $success = 'SHS Student updated successfully!';
    } else {
        $error = 'Failed to update student.';
    }
}

// Handle Delete Student
if (isset($_POST['delete_student'])) {
    $id = (int)$_POST['student_id'];
    $conn->query("DELETE FROM students WHERE id = $id");
    $success = 'Student deleted.';
}

// Filters
$filter_school = (int)($_GET['school'] ?? 0);
$filter_grade = (int)($_GET['grade'] ?? 0);
$filter_track = sanitize($_GET['track'] ?? '');
$search = sanitize($_GET['search'] ?? '');

// Build query — SHS only (Grade 11 & 12)
$where = ["gl.name IN ('Grade 11','Grade 12')"];
$params = [];
$types = '';

if ($filter_school) { $where[] = "s.school_id = ?"; $params[] = $filter_school; $types .= 'i'; }
if ($filter_grade) { $where[] = "s.grade_level_id = ?"; $params[] = $filter_grade; $types .= 'i'; }
if ($filter_track) { $where[] = "sec.track = ?"; $params[] = $filter_track; $types .= 's'; }
if ($search) { $where[] = "(s.name LIKE ? OR s.lrn LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $types .= 'ss'; }

// Role-based: principals see only their school
if (($_SESSION['admin_role'] ?? '') === 'principal' && ($_SESSION['admin_school_id'] ?? 0)) {
    $where[] = "s.school_id = ?";
    $params[] = $_SESSION['admin_school_id'];
    $types .= 'i';
}

$sql = "SELECT s.*, sch.name as school_name, sch.code as school_code, gl.name as grade_name, sec.name as section_name, sec.track as track
        FROM students s
        LEFT JOIN schools sch ON s.school_id = sch.id
        LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sch.name, gl.id, sec.track, sec.name, s.name";

$students = [];
if ($types) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}
if ($result) { while ($row = $result->fetch_assoc()) $students[] = $row; }

// Fetch dropdowns
$schools = []; $r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $schools[] = $row;
// Only SHS grades
$grades = []; $r = $conn->query("SELECT id, name FROM grade_levels WHERE name IN ('Grade 11','Grade 12') ORDER BY id"); if ($r) while ($row = $r->fetch_assoc()) $grades[] = $row;
// All grades (for add modal)
$all_grades = []; $r = $conn->query("SELECT id, name FROM grade_levels WHERE name IN ('Grade 11','Grade 12') ORDER BY id"); if ($r) while ($row = $r->fetch_assoc()) $all_grades[] = $row;
$sections = []; $r = $conn->query("SELECT sec.id, sec.name, sec.school_id, sec.grade_level_id, sec.track, sch.code as school_code FROM sections sec LEFT JOIN schools sch ON sec.school_id = sch.id LEFT JOIN grade_levels gl ON sec.grade_level_id = gl.id WHERE sec.status='active' AND gl.name IN ('Grade 11','Grade 12') ORDER BY sec.name"); if ($r) while ($row = $r->fetch_assoc()) $sections[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/header.php'; ?>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-graduation-cap" style="color:var(--primary);margin-right:8px;"></i> SHS Students <span style="font-size:0.55em;color:var(--text-muted);font-weight:400;">(Grade 11 — 12)</span></h1>
            <p>Manage Senior High School student records with Track/Strand</p>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= $error ?></div><?php endif; ?>

        <!-- Filters -->
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
                <label>Grade Level</label>
                <select name="grade" class="form-control" onchange="this.form.submit()">
                    <option value="">All SHS Grades</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $filter_grade == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Track/Strand</label>
                <select name="track" class="form-control" onchange="this.form.submit()">
                    <option value="">All Tracks</option>
                    <?php foreach ($shs_tracks as $tr): ?>
                        <option value="<?= $tr ?>" <?= $filter_track === $tr ? 'selected' : '' ?>><?= $tr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Name or LRN...">
            </div>
            <div class="filter-group" style="justify-content:flex-end;">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
            </div>
            <?php if ($filter_school || $filter_grade || $filter_track || $search): ?>
                <div class="filter-group" style="justify-content:flex-end;">
                    <label>&nbsp;</label>
                    <a href="shs_students.php" class="btn btn-outline btn-sm">Clear</a>
                </div>
            <?php endif; ?>
        </form>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <span style="color:var(--text-muted);font-size:0.85rem;"><?= count($students) ?> SHS student(s) found</span>
            </div>
            <div class="toolbar-right">
                <a href="bulk_import.php" class="btn btn-outline"><i class="fas fa-file-import"></i> Bulk Import</a>
                <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('active')">
                    <i class="fas fa-plus"></i> Add SHS Student
                </button>
            </div>
        </div>

        <!-- Students Table -->
        <div class="card" style="padding:0;">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>School</th>
                            <th>Grade</th>
                            <th>Track/Strand</th>
                            <th>Section</th>
                            <th>Guardian Contact</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="9"><div class="empty-state"><i class="fas fa-graduation-cap"></i><h3>No SHS students found</h3><p>Register SHS students or adjust filters.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $st): ?>
                                <tr>
                                    <td><code style="color:var(--primary);font-weight:600;"><?= htmlspecialchars($st['lrn']) ?></code></td>
                                    <td><strong><?= htmlspecialchars($st['name']) ?></strong></td>
                                    <td><span class="badge badge-info"><?= htmlspecialchars($st['school_code'] ?? '') ?></span> <?= htmlspecialchars($st['school_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($st['grade_name'] ?? '') ?></td>
                                    <td><?= !empty($st['track']) ? '<span class="badge" style="background:rgba(225,29,72,0.1);color:#e11d48;font-weight:600;">' . htmlspecialchars($st['track']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                    <td><?= htmlspecialchars($st['section_name'] ?? '') ?></td>
                                    <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($st['guardian_contact'] ?? '') ?: '—' ?></td>
                                    <td><span class="badge <?= $st['status']==='active' ? 'badge-success' : 'badge-error' ?>"><?= ucfirst($st['status']) ?></span></td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="action-btn action-btn-view" onclick='showQR(<?= json_encode($st) ?>)'><i class="fas fa-qrcode"></i></button>
                                            <button class="action-btn action-btn-edit" onclick='editStudent(<?= json_encode($st) ?>)'><i class="fas fa-edit"></i></button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this student?')">
                                                <input type="hidden" name="student_id" value="<?= $st['id'] ?>">
                                                <button type="submit" name="delete_student" class="action-btn action-btn-delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus" style="color:var(--primary);margin-right:8px;"></i> Register SHS Student</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>LRN *</label>
                            <input type="text" name="lrn" class="form-control" required placeholder="Learner Reference Number">
                        </div>
                        <div class="form-group">
                            <label>Student Name *</label>
                            <input type="text" name="name" class="form-control" required placeholder="Full Name">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>School *</label>
                        <select name="school_id" class="form-control" required id="add_school_select" onchange="filterAddSections()">
                            <option value="">Select School</option>
                            <?php foreach ($schools as $sch): ?>
                                <option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Grade Level *</label>
                            <select name="grade_level_id" class="form-control" required id="add_grade_select" onchange="filterAddSections()">
                                <option value="">Select Grade</option>
                                <?php foreach ($all_grades as $g): ?>
                                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Section *</label>
                            <select name="section_id" class="form-control" required id="add_section_select">
                                <option value="">Select Section</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Guardian Contact <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                        <input type="text" name="guardian_contact" class="form-control" placeholder="09XXXXXXXXX">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="add_student" class="btn btn-primary"><i class="fas fa-save"></i> Register</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="color:var(--primary);margin-right:8px;"></i> Edit SHS Student</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="student_id" id="edit_student_id">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>LRN *</label>
                            <input type="text" name="lrn" id="edit_lrn" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Student Name *</label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>School *</label>
                        <select name="school_id" class="form-control" required id="edit_school_select" onchange="filterEditSections()">
                            <option value="">Select School</option>
                            <?php foreach ($schools as $sch): ?>
                                <option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Grade Level *</label>
                            <select name="grade_level_id" class="form-control" required id="edit_grade_select" onchange="filterEditSections()">
                                <option value="">Select Grade</option>
                                <?php foreach ($all_grades as $g): ?>
                                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Section *</label>
                            <select name="section_id" class="form-control" required id="edit_section_select">
                                <option value="">Select Section</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Guardian Contact <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                            <input type="text" name="guardian_contact" id="edit_guardian" class="form-control" placeholder="09XXXXXXXXX">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="edit_status" class="form-control">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="edit_student" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div class="modal-overlay" id="qrModal">
        <div class="modal" style="max-width:360px;text-align:center;">
            <div class="modal-header">
                <h3>Student QR Code</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="qr_display" style="margin:20px auto;"></div>
                <p id="qr_student_name" style="font-weight:700;font-size:1.1rem;margin-top:12px;"></p>
                <p id="qr_student_lrn" style="color:var(--text-muted);font-size:0.85rem;"></p>
                <p id="qr_student_track" style="color:#e11d48;font-size:0.82rem;font-weight:600;"></p>
            </div>
        </div>
    </div>

    <script>
    const allSections = <?= json_encode($sections) ?>;

    function filterSections(schoolSelect, gradeSelect, sectionSelect, preselect) {
        const schoolId = schoolSelect.value;
        const gradeId = gradeSelect.value;
        sectionSelect.innerHTML = '<option value="">Select Section</option>';
        allSections.forEach(s => {
            if ((!schoolId || s.school_id == schoolId) && (!gradeId || s.grade_level_id == gradeId)) {
                const label = s.track ? s.name + ' (' + s.track + ')' : s.name;
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = label;
                if (preselect && s.id == preselect) opt.selected = true;
                sectionSelect.appendChild(opt);
            }
        });
    }

    function filterAddSections() {
        filterSections(
            document.getElementById('add_school_select'),
            document.getElementById('add_grade_select'),
            document.getElementById('add_section_select')
        );
    }

    function filterEditSections(preselect) {
        filterSections(
            document.getElementById('edit_school_select'),
            document.getElementById('edit_grade_select'),
            document.getElementById('edit_section_select'),
            preselect
        );
    }

    function editStudent(s) {
        document.getElementById('edit_student_id').value = s.id;
        document.getElementById('edit_lrn').value = s.lrn;
        document.getElementById('edit_name').value = s.name;
        document.getElementById('edit_school_select').value = s.school_id;
        document.getElementById('edit_grade_select').value = s.grade_level_id;
        document.getElementById('edit_status').value = s.status;
        document.getElementById('edit_guardian').value = s.guardian_contact || '';
        filterEditSections(s.section_id);
        document.getElementById('editModal').classList.add('active');
    }

    function showQR(s) {
        const qr = qrcode(0, 'M');
        qr.addData(s.qr_code);
        qr.make();
        document.getElementById('qr_display').innerHTML = qr.createSvgTag(6, 0);
        document.getElementById('qr_student_name').textContent = s.name;
        document.getElementById('qr_student_lrn').textContent = 'LRN: ' + s.lrn;
        document.getElementById('qr_student_track').textContent = s.track ? s.track : '';
        document.getElementById('qrModal').classList.add('active');
    }
    </script>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
