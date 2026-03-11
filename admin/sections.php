<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'], ['super_admin','principal'])) {
    header('Location: ../admin_login.php'); exit;
}
$conn = getDBConnection();

$current_page = 'sections';
$page_title = 'Sections';
$success = '';
$error = '';

// SHS track options
$shs_tracks = ['STEM','ABM','HUMSS','GAS','TVL-HE','TVL-ICT','TVL-IA','TVL-AFA','Sports','Arts & Design'];

// Handle Add
if (isset($_POST['add_section'])) {
    $school_id = (int)$_POST['school_id'];
    $grade_level_id = (int)$_POST['grade_level_id'];
    $name = sanitize($_POST['name'] ?? '');
    $track = !empty($_POST['track']) ? sanitize($_POST['track']) : null;
    $adviser_id = !empty($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : null;

    if (!$school_id || !$grade_level_id || empty($name)) {
        $error = 'School, Grade Level, and Section Name are required.';
    } else {
        $stmt = $conn->prepare("INSERT INTO sections (school_id, grade_level_id, name, track, adviser_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iissi", $school_id, $grade_level_id, $name, $track, $adviser_id);
        if ($stmt->execute()) { $success = 'Section added!'; } else { $error = 'Failed to add section.'; }
    }
}

// Handle Edit
if (isset($_POST['edit_section'])) {
    $id = (int)$_POST['section_id'];
    $school_id = (int)$_POST['school_id'];
    $grade_level_id = (int)$_POST['grade_level_id'];
    $name = sanitize($_POST['name'] ?? '');
    $track = !empty($_POST['track']) ? sanitize($_POST['track']) : null;
    $adviser_id = !empty($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : null;
    $status = sanitize($_POST['status'] ?? 'active');

    $stmt = $conn->prepare("UPDATE sections SET school_id=?, grade_level_id=?, name=?, track=?, adviser_id=?, status=? WHERE id=?");
    $stmt->bind_param("iissisi", $school_id, $grade_level_id, $name, $track, $adviser_id, $status, $id);
    if ($stmt->execute()) { $success = 'Section updated!'; } else { $error = 'Failed.'; }
}

// Handle Delete
if (isset($_POST['delete_section'])) {
    $id = (int)$_POST['section_id'];
    $conn->query("DELETE FROM sections WHERE id = $id");
    $success = 'Section deleted.';
}

// Handle Bulk Delete
if (isset($_POST['bulk_delete_sections']) && !empty($_POST['section_ids'])) {
    $ids = array_map('intval', $_POST['section_ids']);
    $ids = array_filter($ids, fn($id) => $id > 0);
    if (!empty($ids)) {
        $placeholders = implode(',', $ids);
        $conn->query("DELETE FROM sections WHERE id IN ($placeholders)");
        $deleted_count = $conn->affected_rows;
        $success = "$deleted_count section(s) deleted.";
    }
}

// Filters
$filter_school = (int)($_GET['school'] ?? 0);
$filter_grade = (int)($_GET['grade'] ?? 0);

$where = ["1=1"];
if ($filter_school) $where[] = "sec.school_id = $filter_school";
if ($filter_grade) $where[] = "sec.grade_level_id = $filter_grade";

$sql = "SELECT sec.*, sch.name as school_name, sch.code as school_code, gl.name as grade_name,
               t.name as adviser_name, t.employee_id as adviser_eid,
               (SELECT COUNT(*) FROM students s WHERE s.section_id = sec.id AND s.status='active') as student_count
        FROM sections sec
        LEFT JOIN schools sch ON sec.school_id = sch.id
        LEFT JOIN grade_levels gl ON sec.grade_level_id = gl.id
        LEFT JOIN teachers t ON sec.adviser_id = t.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sch.name, gl.id, sec.name";

$sections = [];
$result = $conn->query($sql);
if ($result) { while ($row = $result->fetch_assoc()) $sections[] = $row; }

$schools = []; $r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $schools[] = $row;
$grades = []; $r = $conn->query("SELECT id, name FROM grade_levels ORDER BY id"); if ($r) while ($row = $r->fetch_assoc()) $grades[] = $row;
$teachers_list = []; $r = $conn->query("SELECT id, name, employee_id, school_id FROM teachers WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $teachers_list[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/header.php'; ?></head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-layer-group" style="color:var(--primary);margin-right:8px;"></i> Sections</h1>
            <p>Manage sections per school and grade level, assign advisers</p>
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
                <label>Grade Level</label>
                <select name="grade" class="form-control" onchange="this.form.submit()">
                    <option value="">All Grades</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $filter_grade == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filter_school || $filter_grade): ?>
                <div class="filter-group"><label>&nbsp;</label><a href="sections.php" class="btn btn-outline btn-sm">Clear</a></div>
            <?php endif; ?>
        </form>

        <div class="toolbar">
            <span style="color:var(--text-muted);font-size:0.85rem;"><?= count($sections) ?> section(s)</span>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="btn" id="bulkDeleteBtn" style="display:none;background:#fee2e2;color:#dc2626;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-weight:600;font-size:0.82rem;" onclick="bulkDelete()">
                    <i class="fas fa-trash"></i> Delete Selected (<span id="selectedCount">0</span>)
                </button>
                <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('active')"><i class="fas fa-plus"></i> Add Section</button>
            </div>
        </div>

        <div class="card" style="padding:0;">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th style="width:40px;"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" title="Select All"></th><th>School</th><th>Grade Level</th><th>Track/Strand</th><th>Section</th><th>Adviser</th><th>Students</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sections)): ?>
                            <tr><td colspan="9"><div class="empty-state"><i class="fas fa-layer-group"></i><h3>No sections found</h3></div></td></tr>
                        <?php else: foreach ($sections as $sec): ?>
                            <tr>
                                <td><input type="checkbox" class="section-cb" value="<?= $sec['id'] ?>" onclick="updateBulkBtn()"></td>
                                <td><span class="badge badge-info"><?= htmlspecialchars($sec['school_code'] ?? '') ?></span> <?= htmlspecialchars($sec['school_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($sec['grade_name'] ?? '') ?></td>
                                <td><?= !empty($sec['track']) ? '<span class="badge badge-info">' . htmlspecialchars($sec['track']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                <td><strong><?= htmlspecialchars($sec['name']) ?></strong></td>
                                <td><?= $sec['adviser_name'] ? htmlspecialchars($sec['adviser_name']) . ' <span class="text-muted" style="font-size:0.75rem;">(' . htmlspecialchars($sec['adviser_eid']) . ')</span>' : '<span class="text-muted">Unassigned</span>' ?></td>
                                <td><strong><?= $sec['student_count'] ?></strong></td>
                                <td><span class="badge <?= $sec['status']==='active' ? 'badge-success' : 'badge-error' ?>"><?= ucfirst($sec['status']) ?></span></td>
                                <td>
                                    <div class="action-btns">
                                        <button class="action-btn action-btn-edit" onclick='editSection(<?= json_encode($sec) ?>)'><i class="fas fa-edit"></i></button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this section?')">
                                            <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                            <button type="submit" name="delete_section" class="action-btn action-btn-delete"><i class="fas fa-trash"></i></button>
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
            <div class="modal-header"><h3><i class="fas fa-plus-circle" style="color:var(--primary);margin-right:8px;"></i> Add Section</h3><button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button></div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>School *</label>
                            <select name="school_id" class="form-control" required id="add_school" onchange="filterAdviserAdd()">
                                <option value="">Select School</option>
                                <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Grade Level *</label>
                            <select name="grade_level_id" class="form-control" required id="add_grade" onchange="toggleTrack('add')">
                                <option value="">Select Grade</option>
                                <?php foreach ($grades as $g): ?><option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row" id="add_track_row" style="display:none;">
                        <div class="form-group">
                            <label>Track/Strand *</label>
                            <select name="track" class="form-control" id="add_track">
                                <option value="">Select Track</option>
                                <?php foreach ($shs_tracks as $tr): ?><option value="<?= $tr ?>"><?= $tr ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Section Name *</label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Section A">
                        </div>
                        <div class="form-group">
                            <label>Adviser (Teacher)</label>
                            <select name="adviser_id" class="form-control" id="add_adviser">
                                <option value="">No Adviser</option>
                                <?php foreach ($teachers_list as $t): ?><option value="<?= $t['id'] ?>" data-school="<?= $t['school_id'] ?>"><?= htmlspecialchars($t['name']) ?> (<?= $t['employee_id'] ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="add_section" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header"><h3><i class="fas fa-edit" style="color:var(--primary);margin-right:8px;"></i> Edit Section</h3><button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button></div>
            <form method="POST">
                <input type="hidden" name="section_id" id="edit_id">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>School *</label>
                            <select name="school_id" id="edit_school" class="form-control" required>
                                <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Grade Level *</label>
                            <select name="grade_level_id" id="edit_grade" class="form-control" required onchange="toggleTrack('edit')">
                                <?php foreach ($grades as $g): ?><option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row" id="edit_track_row" style="display:none;">
                        <div class="form-group">
                            <label>Track/Strand *</label>
                            <select name="track" class="form-control" id="edit_track">
                                <option value="">Select Track</option>
                                <?php foreach ($shs_tracks as $tr): ?><option value="<?= $tr ?>"><?= $tr ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Section Name *</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
                        <div class="form-group">
                            <label>Adviser</label>
                            <select name="adviser_id" id="edit_adviser" class="form-control">
                                <option value="">No Adviser</option>
                                <?php foreach ($teachers_list as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?> (<?= $t['employee_id'] ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="edit_section" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const SHS_GRADE_NAMES = ['Grade 11', 'Grade 12'];

    function isSHSGrade(selectEl) {
        const opt = selectEl.options[selectEl.selectedIndex];
        return opt && SHS_GRADE_NAMES.some(g => opt.textContent.trim() === g);
    }

    function toggleTrack(prefix) {
        const gradeSelect = document.getElementById(prefix + '_grade');
        const trackRow = document.getElementById(prefix + '_track_row');
        const trackSelect = document.getElementById(prefix + '_track');
        if (isSHSGrade(gradeSelect)) {
            trackRow.style.display = '';
            trackSelect.required = true;
        } else {
            trackRow.style.display = 'none';
            trackSelect.required = false;
            trackSelect.value = '';
        }
    }

    function editSection(s) {
        document.getElementById('edit_id').value = s.id;
        document.getElementById('edit_school').value = s.school_id;
        document.getElementById('edit_grade').value = s.grade_level_id;
        document.getElementById('edit_name').value = s.name;
        document.getElementById('edit_track').value = s.track || '';
        document.getElementById('edit_adviser').value = s.adviser_id || '';
        document.getElementById('edit_status').value = s.status;
        toggleTrack('edit');
        document.getElementById('editModal').classList.add('active');
    }

    function filterAdviserAdd() {
        const schoolId = document.getElementById('add_school').value;
        const opts = document.getElementById('add_adviser').options;
        for (let i = 1; i < opts.length; i++) {
            opts[i].style.display = (!schoolId || opts[i].dataset.school === schoolId) ? '' : 'none';
        }
    }

    function toggleSelectAll(master) {
        document.querySelectorAll('.section-cb').forEach(cb => cb.checked = master.checked);
        updateBulkBtn();
    }

    function updateBulkBtn() {
        const checked = document.querySelectorAll('.section-cb:checked');
        const btn = document.getElementById('bulkDeleteBtn');
        const count = document.getElementById('selectedCount');
        const selectAll = document.getElementById('selectAll');
        const total = document.querySelectorAll('.section-cb');
        count.textContent = checked.length;
        btn.style.display = checked.length > 0 ? '' : 'none';
        selectAll.checked = total.length > 0 && checked.length === total.length;
    }

    function bulkDelete() {
        const checked = document.querySelectorAll('.section-cb:checked');
        if (checked.length === 0) return;
        if (!confirm('Delete ' + checked.length + ' selected section(s)? This cannot be undone.')) return;
        const form = document.createElement('form');
        form.method = 'POST';
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'section_ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'bulk_delete_sections';
        action.value = '1';
        form.appendChild(action);
        document.body.appendChild(form);
        form.submit();
    }
    </script>
</body>
</html>
