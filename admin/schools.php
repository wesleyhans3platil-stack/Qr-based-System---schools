<?php
require_once '../config/database.php';
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    header('Location: ../admin_login.php'); exit;
}
$conn = getDBConnection();

$current_page = 'schools';
$page_title = 'Schools';
$success = '';
$error = '';

// Logo upload helper
function handleLogoUpload($file, $old_logo = null) {
    $upload_dir = __DIR__ . '/../assets/uploads/logos/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($file['type'], $allowed)) return ['error' => 'Invalid file type. Use JPG, PNG, GIF, WEBP, or SVG.'];
    if ($file['size'] > 2 * 1024 * 1024) return ['error' => 'Logo must be under 2MB.'];

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'school_' . time() . '_' . uniqid() . '.' . $ext;
    $dest = $upload_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        // Persist in DB for Railway redeploys
        storeFileInDB('assets/uploads/logos/' . $filename, $dest);
        // Delete old logo if exists
        if ($old_logo) {
            if (file_exists($upload_dir . $old_logo)) unlink($upload_dir . $old_logo);
            removeFileFromDB('assets/uploads/logos/' . $old_logo);
        }
        return ['filename' => $filename];
    }
    return ['error' => 'Failed to upload logo.'];
}

// Handle Add School
if (isset($_POST['add_school'])) {
    $name = sanitize($_POST['name'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $contact = sanitize($_POST['contact_number'] ?? '');
    $logo = null;

    if (empty($name)) {
        $error = 'School name is required.';
    } else {
        // Handle logo upload
        if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload = handleLogoUpload($_FILES['logo']);
            if (isset($upload['error'])) {
                $error = $upload['error'];
            } else {
                $logo = $upload['filename'];
            }
        }

        if (!$error) {
            // Auto-generate school code
            $code_r = $conn->query("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_num FROM schools WHERE code LIKE 'SCH-%'");
            $max_num = ($code_r && $row = $code_r->fetch_assoc()) ? (int)$row['max_num'] : 0;
            $code = 'SCH-' . str_pad($max_num + 1, 3, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("INSERT INTO schools (name, code, address, contact_number, logo) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $name, $code, $address, $contact, $logo);
            if ($stmt->execute()) {
                $success = 'School added successfully!';
            } else {
                $error = 'Failed to add school. Code may already exist.';
            }
        }
    }
}

// Handle Edit School
if (isset($_POST['edit_school'])) {
    $id = (int)$_POST['school_id'];
    $name = sanitize($_POST['name'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $contact = sanitize($_POST['contact_number'] ?? '');
    $status = sanitize($_POST['status'] ?? 'active');

    // Handle logo upload
    $logo_sql = '';
    $logo_val = null;
    if (!empty($_FILES['edit_logo']['name']) && $_FILES['edit_logo']['error'] === UPLOAD_ERR_OK) {
        // Get old logo
        $old = $conn->query("SELECT logo FROM schools WHERE id = $id")->fetch_assoc();
        $upload = handleLogoUpload($_FILES['edit_logo'], $old['logo'] ?? null);
        if (isset($upload['error'])) {
            $error = $upload['error'];
        } else {
            $logo_val = $upload['filename'];
        }
    }

    // Handle logo removal
    if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
        $old = $conn->query("SELECT logo FROM schools WHERE id = $id")->fetch_assoc();
        if ($old['logo']) {
            $logo_path = __DIR__ . '/../assets/uploads/logos/' . $old['logo'];
            if (file_exists($logo_path)) unlink($logo_path);
            removeFileFromDB('assets/uploads/logos/' . $old['logo']);
        }
        $logo_val = '';
    }

    if (!$error) {
        if ($logo_val !== null) {
            $stmt = $conn->prepare("UPDATE schools SET name=?, address=?, contact_number=?, status=?, logo=? WHERE id=?");
            $logo_db = $logo_val ?: null;
            $stmt->bind_param("sssssi", $name, $address, $contact, $status, $logo_db, $id);
        } else {
            $stmt = $conn->prepare("UPDATE schools SET name=?, address=?, contact_number=?, status=? WHERE id=?");
            $stmt->bind_param("ssssi", $name, $address, $contact, $status, $id);
        }
        if ($stmt->execute()) {
            $success = 'School updated successfully!';
        } else {
            $error = 'Failed to update school.';
        }
    }
}

// Handle Delete School
if (isset($_POST['delete_school'])) {
    $id = (int)$_POST['school_id'];
    // Remove logo from DB storage
    $old = $conn->query("SELECT logo FROM schools WHERE id = $id")->fetch_assoc();
    if ($old && $old['logo']) {
        removeFileFromDB('assets/uploads/logos/' . $old['logo']);
    }
    $conn->query("DELETE FROM schools WHERE id = $id");
    $success = 'School deleted.';
}

// Fetch schools with counts
$schools = [];
$result = $conn->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active') as student_count,
           (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as teacher_count,
           (SELECT COUNT(*) FROM sections sec WHERE sec.school_id = s.id AND sec.status='active') as section_count
    FROM schools s ORDER BY s.name
");
if ($result) {
    while ($row = $result->fetch_assoc()) $schools[] = $row;
}
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
            <h1><i class="fas fa-school" style="color:var(--primary);margin-right:8px;"></i> Schools Management</h1>
            <p>Manage all schools under the Division of Sipalay City</p>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= $error ?></div><?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-icon primary"><i class="fas fa-school"></i></div>
                <div class="stat-info">
                    <h3><?= count($schools) ?></h3>
                    <span>Total Schools</span>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon success"><i class="fas fa-user-graduate"></i></div>
                <div class="stat-info">
                    <h3><?= array_sum(array_column($schools, 'student_count')) ?></h3>
                    <span>Total Students</span>
                </div>
            </div>
            <div class="stat-card info">
                <div class="stat-icon info"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-info">
                    <h3><?= array_sum(array_column($schools, 'teacher_count')) ?></h3>
                    <span>Total Teachers</span>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <h3 style="font-weight:700;">All Schools</h3>
            </div>
            <div class="toolbar-right">
                <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('active')">
                    <i class="fas fa-plus"></i> Add School
                </button>
            </div>
        </div>

        <!-- Schools Table -->
        <div class="card" style="padding:0;">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width:60px;">Logo</th>
                            <th>School Name</th>
                            <th>Students</th>
                            <th>Teachers</th>
                            <th>Sections</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($schools)): ?>
                            <tr><td colspan="7"><div class="empty-state"><i class="fas fa-school"></i><h3>No schools yet</h3><p>Add your first school to get started.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($schools as $s): ?>
                                <tr>
                                    <td>
                                        <?php if ($s['logo']): ?>
                                            <img src="../assets/uploads/logos/<?= htmlspecialchars($s['logo']) ?>" alt="Logo" class="school-logo-cell">
                                        <?php else: ?>
                                            <div class="school-logo-placeholder"><i class="fas fa-school"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                                    <td><strong><?= $s['student_count'] ?></strong></td>
                                    <td><strong><?= $s['teacher_count'] ?></strong></td>
                                    <td><?= $s['section_count'] ?></td>
                                    <td><span class="badge <?= $s['status']==='active' ? 'badge-success' : 'badge-error' ?>"><?= ucfirst($s['status']) ?></span></td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="action-btn action-btn-edit" onclick='editSchool(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i></button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this school and all associated data?')">
                                                <input type="hidden" name="school_id" value="<?= $s['id'] ?>">
                                                <button type="submit" name="delete_school" class="action-btn action-btn-delete"><i class="fas fa-trash"></i></button>
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

    <!-- Add School Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle" style="color:var(--primary);margin-right:8px;"></i> Add New School</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <!-- Logo Upload -->
                    <div class="form-group" style="margin-bottom:20px;">
                        <label>School Logo</label>
                        <div class="logo-upload-area" id="addLogoArea" onclick="document.getElementById('addLogoInput').click()">
                            <div class="logo-preview" id="addLogoPreview" style="display:none;">
                                <img id="addLogoImg" src="" alt="Logo">
                                <button type="button" class="logo-remove-btn" onclick="event.stopPropagation(); removeAddLogo()">&times;</button>
                            </div>
                            <div class="logo-placeholder" id="addLogoPlaceholder">
                                <i class="fas fa-cloud-upload-alt" style="font-size:2rem; color:var(--primary); margin-bottom:8px;"></i>
                                <p style="font-weight:600; color:var(--text); margin-bottom:2px;">Click to upload logo</p>
                                <p style="font-size:0.72rem; color:var(--text-muted);">JPG, PNG, GIF, WEBP, SVG &middot; Max 2MB</p>
                            </div>
                        </div>
                        <input type="file" name="logo" id="addLogoInput" accept="image/*" style="display:none;" onchange="previewAddLogo(this)">
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>School Name *</label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Sipalay City National High School">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="add_school" class="btn btn-primary"><i class="fas fa-save"></i> Save School</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit School Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="color:var(--primary);margin-right:8px;"></i> Edit School</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="school_id" id="edit_school_id">
                <input type="hidden" name="remove_logo" id="edit_remove_logo" value="0">
                <div class="modal-body">
                    <!-- Logo Upload -->
                    <div class="form-group" style="margin-bottom:20px;">
                        <label>School Logo</label>
                        <div class="logo-upload-area" id="editLogoArea" onclick="document.getElementById('editLogoInput').click()">
                            <div class="logo-preview" id="editLogoPreview" style="display:none;">
                                <img id="editLogoImg" src="" alt="Logo">
                                <button type="button" class="logo-remove-btn" onclick="event.stopPropagation(); removeEditLogo()">&times;</button>
                            </div>
                            <div class="logo-placeholder" id="editLogoPlaceholder">
                                <i class="fas fa-cloud-upload-alt" style="font-size:2rem; color:var(--primary); margin-bottom:8px;"></i>
                                <p style="font-weight:600; color:var(--text); margin-bottom:2px;">Click to upload logo</p>
                                <p style="font-size:0.72rem; color:var(--text-muted);">JPG, PNG, GIF, WEBP, SVG &middot; Max 2MB</p>
                            </div>
                        </div>
                        <input type="file" name="edit_logo" id="editLogoInput" accept="image/*" style="display:none;" onchange="previewEditLogo(this)">
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>School Name *</label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
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
                    <button type="submit" name="edit_school" class="btn btn-primary"><i class="fas fa-save"></i> Update School</button>
                </div>
            </form>
        </div>
    </div>

    <style>
    .logo-upload-area {
        border: 2px dashed var(--border);
        border-radius: var(--radius-sm);
        padding: 24px;
        text-align: center;
        cursor: pointer;
        transition: var(--transition);
        position: relative;
        min-height: 120px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .logo-upload-area:hover {
        border-color: var(--primary-light);
        background: var(--primary-bg);
    }
    .logo-upload-area.has-logo {
        border-style: solid;
        border-color: var(--primary-light);
        padding: 12px;
    }
    .logo-preview {
        position: relative;
        display: inline-block;
    }
    .logo-preview img {
        max-width: 160px;
        max-height: 100px;
        border-radius: 8px;
        object-fit: contain;
        display: block;
    }
    .logo-remove-btn {
        position: absolute;
        top: -8px;
        right: -8px;
        width: 24px;
        height: 24px;
        background: var(--error);
        color: #fff;
        border: 2px solid #fff;
        border-radius: 50%;
        font-size: 0.85rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    }
    .logo-remove-btn:hover {
        background: #b91c1c;
    }
    .logo-placeholder {
        pointer-events: none;
    }
    .school-logo-cell {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        object-fit: contain;
        border: 1px solid var(--border);
        background: #fff;
        padding: 2px;
    }
    .school-logo-placeholder {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background: var(--primary-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--primary);
        font-size: 1.1rem;
    }
    </style>

    <script>
    // ─── Add Modal Logo ───
    function previewAddLogo(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('addLogoImg').src = e.target.result;
                document.getElementById('addLogoPreview').style.display = 'inline-block';
                document.getElementById('addLogoPlaceholder').style.display = 'none';
                document.getElementById('addLogoArea').classList.add('has-logo');
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    function removeAddLogo() {
        document.getElementById('addLogoInput').value = '';
        document.getElementById('addLogoPreview').style.display = 'none';
        document.getElementById('addLogoPlaceholder').style.display = 'block';
        document.getElementById('addLogoArea').classList.remove('has-logo');
    }

    // ─── Edit Modal Logo ───
    function previewEditLogo(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('editLogoImg').src = e.target.result;
                document.getElementById('editLogoPreview').style.display = 'inline-block';
                document.getElementById('editLogoPlaceholder').style.display = 'none';
                document.getElementById('editLogoArea').classList.add('has-logo');
                document.getElementById('edit_remove_logo').value = '0';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    function removeEditLogo() {
        document.getElementById('editLogoInput').value = '';
        document.getElementById('editLogoPreview').style.display = 'none';
        document.getElementById('editLogoPlaceholder').style.display = 'block';
        document.getElementById('editLogoArea').classList.remove('has-logo');
        document.getElementById('edit_remove_logo').value = '1';
    }

    function editSchool(s) {
        document.getElementById('edit_school_id').value = s.id;
        document.getElementById('edit_name').value = s.name;
        document.getElementById('edit_status').value = s.status;
        document.getElementById('edit_remove_logo').value = '0';
        document.getElementById('editLogoInput').value = '';

        // Show existing logo or placeholder
        if (s.logo) {
            document.getElementById('editLogoImg').src = '../assets/uploads/logos/' + s.logo;
            document.getElementById('editLogoPreview').style.display = 'inline-block';
            document.getElementById('editLogoPlaceholder').style.display = 'none';
            document.getElementById('editLogoArea').classList.add('has-logo');
        } else {
            document.getElementById('editLogoPreview').style.display = 'none';
            document.getElementById('editLogoPlaceholder').style.display = 'block';
            document.getElementById('editLogoArea').classList.remove('has-logo');
        }

        document.getElementById('editModal').classList.add('active');
    }
    </script>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
