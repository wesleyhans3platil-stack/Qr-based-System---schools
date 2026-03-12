<?php
session_start();
require_once '../config/database.php';

// Get database connection
$conn = getDBConnection();

$success = '';
$error = '';

// Handle bulk delete action
if (isset($_POST['bulk_delete']) && !empty($_POST['selected_users'])) {
    $ids = array_map('intval', $_POST['selected_users']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    
    if ($stmt->execute()) {
        $deleted_count = $stmt->affected_rows;
        $success = "$deleted_count user(s) deleted successfully!";
    } else {
        $error = 'Failed to delete users.';
    }
}

// Handle single delete action
if (isset($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $success = 'User deleted successfully!';
    } else {
        $error = 'Failed to delete user.';
    }
}

// Handle status toggle
if (isset($_GET['toggle_status'])) {
    $user_id = (int)$_GET['toggle_status'];
    $stmt = $conn->prepare("UPDATE users SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $success = 'User status updated!';
    }
}

// Handle user update
if (isset($_POST['update_user'])) {
    $user_id = (int)$_POST['user_id'];
    $name = sanitize($_POST['name'] ?? '');
    $level = sanitize($_POST['level'] ?? '');
    $role = sanitize($_POST['role'] ?? '');
    $sport = sanitize($_POST['sport'] ?? '');
    $coach = sanitize($_POST['coach'] ?? '');
    $assistant_coach = sanitize($_POST['assistant_coach'] ?? '');
    $chaperon = sanitize($_POST['chaperon'] ?? '');
    
    if (empty($name) || empty($level) || empty($role) || empty($sport)) {
        $error = 'Name, Level, Category, and Event are required!';
    } else {
        $stmt = $conn->prepare("UPDATE users SET name = ?, level = ?, role = ?, sport = ?, coach = ?, assistant_coach = ?, chaperon = ? WHERE id = ?");
        $stmt->bind_param("sssssssi", $name, $level, $role, $sport, $coach, $assistant_coach, $chaperon, $user_id);
        
        if ($stmt->execute()) {
            $success = 'User updated successfully!';
            
            // Update QR code with new data
            $qr_data = json_encode([
                'id' => $user_id,
                'name' => $name,
                'level' => $level,
                'role' => $role,
                'sport' => $sport,
                'coach' => $coach,
                'assistant_coach' => $assistant_coach,
                'chaperon' => $chaperon
            ]);
            
            $update_qr = $conn->prepare("UPDATE users SET qr_code = ? WHERE id = ?");
            $update_qr->bind_param("si", $qr_data, $user_id);
            $update_qr->execute();
        } else {
            $error = 'Failed to update user.';
        }
    }
}

// Get search parameter
$search = sanitize($_GET['search'] ?? '');

// Build query with optional search
$sql = "SELECT * FROM users";
if (!empty($search)) {
    $sql .= " WHERE name LIKE ? OR role LIKE ? OR sport LIKE ? OR level LIKE ? OR coach LIKE ? OR assistant_coach LIKE ? OR chaperon LIKE ?";
}
$sql .= " ORDER BY created_at DESC";

if (!empty($search)) {
    $stmt = $conn->prepare($sql);
    $search_param = "%$search%";
    $stmt->bind_param("sssssss", $search_param, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - QR Attendance System</title>
    <?php $__fl=$conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'")->fetch_assoc(); if(!empty($__fl['setting_value'])&&file_exists('../assets/uploads/logos/'.$__fl['setting_value'])):?><link rel="icon" type="image/png" href="../assets/uploads/logos/<?=htmlspecialchars($__fl['setting_value'])?>"><?php endif;?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --bg: #0f172a;
            --sidebar-bg: #1e293b;
            --card-bg: #1e293b;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --success: #22c55e;
            --warning: #f59e0b;
            --error: #ef4444;
            --border: #334155;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border);
            padding: 20px;
            z-index: 100;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }

        .sidebar-header .logo {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            border-radius: 12px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding: 8px 6px;
            gap: 3px;
        }

        .sidebar-header .logo .logo-bar {
            width: 8px;
            border-radius: 2px 2px 0 0;
        }

        .sidebar-header .logo .logo-bar-1 { height: 20px; background: #22c55e; }
        .sidebar-header .logo .logo-bar-2 { height: 16px; background: #f59e0b; }
        .sidebar-header .logo .logo-bar-3 { height: 12px; background: #3b82f6; }

        .sidebar-header h2 { font-size: 1.1rem; font-weight: 700; line-height: 1.3; white-space: nowrap; }
        .sidebar-header span { font-size: 0.75rem; color: var(--text-muted); line-height: 1.3; }

        .nav-menu { list-style: none; }
        .nav-item { margin-bottom: 4px; }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-weight: 500;
            letter-spacing: 0.01em;
        }

        .nav-link:hover, .nav-link.active {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
        }

        .nav-link i { width: 18px; min-width: 18px; text-align: center; font-size: 1rem; }

        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header h1 { font-size: 1.8rem; font-weight: 700; }

        .search-box {
            display: flex;
            gap: 10px;
        }

        .search-input {
            padding: 10px 15px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 0.9rem;
            width: 250px;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .btn:hover { background: var(--primary-dark); }

        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 15px 20px;
            text-align: left;
        }

        th {
            background: rgba(0, 0, 0, 0.2);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        tr {
            border-bottom: 1px solid var(--border);
        }

        tr:last-child { border-bottom: none; }
        tr:hover { background: rgba(99, 102, 241, 0.05); }

        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .badge-warning { background: rgba(245, 158, 11, 0.2); color: var(--warning); }

        .action-btn {
            padding: 8px 14px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            margin-right: 5px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .action-btn.view { background: rgba(99, 102, 241, 0.2); color: var(--primary); }
        .action-btn.edit { background: rgba(245, 158, 11, 0.2); color: var(--warning); }
        .action-btn.delete { background: rgba(239, 68, 68, 0.2); color: var(--error); }
        .action-btn:hover { transform: scale(1.05); opacity: 0.9; }

        /* Actions column */
        td:last-child {
            white-space: nowrap;
            min-width: 200px;
            display: table-cell !important;
        }

        td:last-child > * {
            display: inline-flex !important;
            vertical-align: middle;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show { display: flex; }

        .modal-content {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            text-align: center;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content h3 {
            margin-bottom: 20px;
            text-align: left;
        }

        .modal-form {
            text-align: left;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text);
            font-size: 0.9rem;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 0.9rem;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .btn-save {
            background: var(--success);
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-save:hover {
            background: #16a34a;
        }

        .qr-display {
            background: white;
            padding: 20px;
            border-radius: 12px;
            display: inline-block;
            margin-bottom: 20px;
        }

        .modal-close {
            margin-top: 15px;
            padding: 10px 30px;
            background: var(--border);
            color: var(--text);
            border: none;
            border-radius: 10px;
            cursor: pointer;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--error);
        }

        /* Bulk Action Bar */
        .bulk-action-bar {
            background: var(--card-bg);
            border: 1px solid var(--primary);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .bulk-info {
            font-weight: 600;
            color: var(--primary);
        }

        .bulk-actions {
            display: flex;
            gap: 10px;
        }

        .btn-danger {
            background: var(--error) !important;
        }

        .btn-danger:hover {
            background: #dc2626 !important;
        }

        /* Checkbox Styling */
        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        th input[type="checkbox"] {
            margin: 0;
        }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-bar logo-bar-1"></div>
                <div class="logo-bar logo-bar-2"></div>
                <div class="logo-bar logo-bar-3"></div>
            </div>
            <div>
                <h2>QR Attendance</h2>
                <span>Admin Panel</span>
            </div>
        </div>

        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="register_user.php" class="nav-link">
                    <i class="fas fa-user-plus"></i> Register User
                </a>
            </li>
            <li class="nav-item">
                <a href="bulk_import.php" class="nav-link">
                    <i class="fas fa-file-import"></i> Bulk Import
                </a>
            </li>
            <li class="nav-item">
                <a href="users.php" class="nav-link active">
                    <i class="fas fa-users"></i> Manage Users
                </a>
            </li>
            <li class="nav-item">
                <a href="print_qr.php" class="nav-link">
                    <i class="fas fa-print"></i> Bulk ID & QR
                </a>
            </li>
            <li class="nav-item">
                <a href="attendance.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i> Attendance Records
                </a>
            </li>
            <li class="nav-item">
                <a href="settings.php" class="nav-link">
                    <i class="fas fa-clock"></i> Time Settings
                </a>
            </li>
            <li class="nav-item">
                <a href="../Qrscanattendance.php" class="nav-link" target="_blank">
                    <i class="fas fa-qrcode"></i> QR Scanner
                </a>
            </li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="header">
            <h1>Manage Users</h1>
            <div class="search-box">
                <form method="GET" style="display: flex; gap: 10px;" id="searchForm">
                    <input type="text" name="search" class="search-input" id="searchInput"
                           placeholder="Search users..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                    <a href="users.php" class="btn" style="background: var(--border);"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
                <div style="display:flex; gap:8px; align-items:center;">
                    <a href="register_user.php" class="btn"><i class="fas fa-plus"></i> Add New</a>
                    <a href="export_users.php" class="btn" style="background: #047857;">
                        <i class="fas fa-file-csv"></i> Export Excel
                    </a>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Bulk Action Bar -->
        <div class="bulk-action-bar" id="bulkActionBar" style="display: none;">
            <div class="bulk-info">
                <span id="selectedCount">0</span> user(s) selected
            </div>
            <div class="bulk-actions">
                <button type="button" class="btn" style="background: var(--warning);" onclick="bulkEdit()">
                    <i class="fas fa-edit"></i> Edit Selected
                </button>
                <button type="button" class="btn btn-danger" onclick="bulkDelete()">
                    <i class="fas fa-trash"></i> Delete Selected
                </button>
                <button type="button" class="btn" style="background: var(--border);" onclick="deselectAll()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>

        <form method="POST" id="bulkForm">
        <div class="table-container">
            <?php if (!empty($users)): ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                        </th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Level</th>
                        <th>Category</th>
                        <th>Event</th>
                        <th>Coach</th>
                        <th>Asst. Coach</th>
                        <th>Chaperon</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="user-checkbox" name="selected_users[]" 
                                   value="<?= $user['id'] ?>" onclick="updateSelection()">
                        </td>
                        <td>#<?= $user['id'] ?></td>
                        <td><strong><?= htmlspecialchars($user['name']) ?></strong></td>
                        <td><?= htmlspecialchars($user['level']) ?></td>
                        <td><?= htmlspecialchars($user['role']) ?></td>
                        <td><?= htmlspecialchars($user['sport']) ?></td>
                        <td><?= htmlspecialchars($user['coach'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($user['assistant_coach'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($user['chaperon'] ?? '-') ?></td>
                        <td>
                            <span class="badge <?= $user['status'] === 'active' ? 'badge-success' : 'badge-warning' ?>">
                                <?= ucfirst($user['status']) ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" class="action-btn view" onclick="showQR(<?= htmlspecialchars(json_encode($user)) ?>)" style="display: inline-flex !important;">
                                <i class="fas fa-qrcode"></i> QR
                            </button>
                            <button type="button" class="action-btn edit" onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)" style="display: inline-flex !important;">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <a href="?delete=<?= $user['id'] ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="action-btn delete" 
                               onclick="return confirm('Are you sure you want to delete this user?')" style="display: inline-flex !important;">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <input type="hidden" name="bulk_delete" value="1">
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>No users found<?= !empty($search) ? ' for "' . htmlspecialchars($search) . '"' : '' ?></p>
                <?php if (!empty($search)): ?>
                <a href="users.php" class="btn" style="margin-top: 15px;"><i class="fas fa-arrow-left"></i> Show All Users</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        </form>
    </main>

    <!-- QR Modal -->
    <div class="modal" id="qrModal">
        <div class="modal-content">
            <h3 id="modalTitle">QR Code</h3>
            <div class="qr-display">
                <div id="modalQR"></div>
            </div>
            <p id="modalInfo"></p>
            <button class="modal-close" onclick="closeModal()">Close</button>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <h3><i class="fas fa-edit"></i> Edit User Information</h3>
            <form method="POST" class="modal-form" id="editForm">
                <input type="hidden" name="update_user" value="1">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-group">
                    <label for="edit_name">Name *</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_level">Level *</label>
                        <input type="text" id="edit_level" name="level" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_role">Category *</label>
                        <input type="text" id="edit_role" name="role" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_sport">Event *</label>
                        <input type="text" id="edit_sport" name="sport" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_coach">Coach</label>
                        <input type="text" id="edit_coach" name="coach">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_assistant_coach">Assistant Coach</label>
                        <input type="text" id="edit_assistant_coach" name="assistant_coach">
                    </div>
                    <div class="form-group">
                        <label for="edit_chaperon">Chaperon</label>
                        <input type="text" id="edit_chaperon" name="chaperon">
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="modal-close" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Select All / Deselect All
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updateSelection();
        }

        // Update selection count and show/hide bulk action bar
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            const count = checkboxes.length;
            const totalCheckboxes = document.querySelectorAll('.user-checkbox');
            const selectAllCheckbox = document.getElementById('selectAll');
            const bulkActionBar = document.getElementById('bulkActionBar');
            const selectedCount = document.getElementById('selectedCount');

            // Update count
            selectedCount.textContent = count;

            // Show/hide bulk action bar
            if (count > 0) {
                bulkActionBar.style.display = 'flex';
            } else {
                bulkActionBar.style.display = 'none';
            }

            // Update select all checkbox state
            if (selectAllCheckbox) {
                if (count === 0) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                } else if (count === totalCheckboxes.length) {
                    selectAllCheckbox.checked = true;
                    selectAllCheckbox.indeterminate = false;
                } else {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = true;
                }
            }
        }

        // Deselect all
        function deselectAll() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            const selectAllCheckbox = document.getElementById('selectAll');
            checkboxes.forEach(cb => cb.checked = false);
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
            updateSelection();
        }

        // Bulk delete
        function bulkDelete() {
            const count = document.querySelectorAll('.user-checkbox:checked').length;
            if (count === 0) {
                alert('Please select at least one user');
                return;
            }

            if (confirm(`Are you sure you want to delete ${count} user(s)? This action cannot be undone.`)) {
                document.getElementById('bulkForm').submit();
            }
        }

        // Bulk edit
        function bulkEdit() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            const count = checkboxes.length;
            
            if (count === 0) {
                alert('Please select at least one user to edit');
                return;
            }

            if (count === 1) {
                // If only one user selected, open the edit modal for that user
                const userId = checkboxes[0].value;
                const row = checkboxes[0].closest('tr');
                const cells = row.querySelectorAll('td');
                
                const getValue = (cell) => {
                    const text = cell.textContent.trim();
                    return text === '-' ? '' : text;
                };
                
                const userData = {
                    id: userId,
                    name: getValue(cells[2]),
                    level: getValue(cells[3]),
                    role: getValue(cells[4]),
                    sport: getValue(cells[5]),
                    coach: getValue(cells[6]),
                    assistant_coach: getValue(cells[7]),
                    chaperon: getValue(cells[8])
                };
                editUser(userData);
            } else {
                alert(`Bulk editing ${count} users at once is not yet supported. Please select one user at a time to edit.`);
            }
        }

        // QR Code Modal
        function showQR(user) {
            const qrData = JSON.stringify({
                name: user.name,
                level: user.level,
                role: user.role,
                sport: user.sport
            });
            
            const qr = qrcode(0, 'M');
            qr.addData(qrData);
            qr.make();
            
            document.getElementById('modalTitle').textContent = user.name;
            document.getElementById('modalQR').innerHTML = qr.createImgTag(4, 8);
            document.getElementById('modalInfo').textContent = `${user.level} | ${user.role} - ${user.sport}`;
            document.getElementById('qrModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('qrModal').classList.remove('show');
        }

        // Edit User Modal Functions
        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_level').value = user.level || '';
            document.getElementById('edit_role').value = user.role || '';
            document.getElementById('edit_sport').value = user.sport || '';
            document.getElementById('edit_coach').value = user.coach || '';
            document.getElementById('edit_assistant_coach').value = user.assistant_coach || '';
            document.getElementById('edit_chaperon').value = user.chaperon || '';
            document.getElementById('editModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
        }

        // Close modal on outside click
        document.getElementById('qrModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });

        // Live search (optional - press Enter or click search button)
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('searchForm').submit();
            }
        });
    </script>

<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
