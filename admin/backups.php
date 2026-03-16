<?php
require_once '../config/database.php';
$conn = getDBConnection();

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    header('Location: ../admin_login.php');
    exit;
}

$current_page = 'backups';
$page_title = 'Database Backups';
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';

// Ensure db_backups table exists
$conn->query("CREATE TABLE IF NOT EXISTS db_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_name VARCHAR(255) NOT NULL,
    backup_date DATE NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    table_count INT NOT NULL DEFAULT 0,
    row_count INT NOT NULL DEFAULT 0,
    backup_data LONGBLOB NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_backup_date (backup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Check if today's auto-backup already exists
$today = date('Y-m-d');
$todayCheck = $conn->prepare("SELECT id FROM db_backups WHERE backup_date = ? LIMIT 1");
$todayCheck->bind_param("s", $today);
$todayCheck->execute();
$hasTodayBackup = $todayCheck->get_result()->num_rows > 0;

// Auto-create daily backup if none exists for today
if (!$hasTodayBackup) {
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    $backup = "-- QR Attendance System Daily Auto-Backup\n";
    $backup .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $backup .= "-- Database: " . DB_NAME . "\n\n";
    $backup .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    $totalRows = 0; $tableCount = 0;
    foreach ($tables as $table) {
        if ($table === 'file_storage' || $table === 'db_backups') continue;
        $tableCount++;
        $create = $conn->query("SHOW CREATE TABLE `$table`");
        if ($create) { $r = $create->fetch_row(); $backup .= "DROP TABLE IF EXISTS `$table`;\n" . $r[1] . ";\n\n"; }
        $data = $conn->query("SELECT * FROM `$table`");
        if ($data && $data->num_rows > 0) {
            $totalRows += $data->num_rows;
            while ($r = $data->fetch_assoc()) {
                $values = array_map(function($v) use ($conn) {
                    if ($v === null) return 'NULL';
                    return "'" . $conn->real_escape_string($v) . "'";
                }, array_values($r));
                $backup .= "INSERT INTO `$table` VALUES(" . implode(',', $values) . ");\n";
            }
            $backup .= "\n";
        }
    }
    $backup .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $backupName = 'auto_backup_' . $today;
    $fileSize = strlen($backup);
    $stmt = $conn->prepare("INSERT INTO db_backups (backup_name, backup_date, file_size, table_count, row_count, backup_data) VALUES (?, ?, ?, ?, ?, ?)");
    $null = null;
    $stmt->bind_param("ssiisb", $backupName, $today, $fileSize, $tableCount, $totalRows, $null);
    $stmt->send_long_data(5, $backup);
    $stmt->execute();
    $hasTodayBackup = true;
}

// Get all backups
$backups = [];
$result = $conn->query("SELECT id, backup_name, backup_date, file_size, table_count, row_count, created_at FROM db_backups ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $backups[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include 'includes/header.php'; ?>
<style>
.backup-page { padding: 24px; max-width: 1200px; margin: 0 auto; }
.backup-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.backup-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--text); margin: 0; }
.backup-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--card-bg, #fff); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.stat-card .stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 12px; }
.stat-card .stat-value { font-size: 1.6rem; font-weight: 700; color: var(--text); }
.stat-card .stat-label { font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; }
.backup-table-wrap { background: var(--card-bg, #fff); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); overflow: hidden; }
.backup-table { width: 100%; border-collapse: collapse; }
.backup-table th { padding: 14px 16px; text-align: left; font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); border-bottom: 2px solid var(--border, #e5e7eb); background: var(--card-bg, #fff); }
.backup-table td { padding: 14px 16px; border-bottom: 1px solid var(--border, #f0f0f0); font-size: 0.88rem; color: var(--text); }
.backup-table tr:hover { background: var(--hover-bg, rgba(99,102,241,0.04)); }
.backup-table .name { font-weight: 600; }
.backup-table .date { color: var(--text-muted); }
.backup-table .size { font-family: monospace; font-size: 0.82rem; }
.badge-auto { background: #e0e7ff; color: #4338ca; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
.badge-manual { background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
.badge-today { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
.action-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-sm { padding: 6px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 5px; transition: all 0.15s; }
.btn-restore { background: #4f46e5; color: #fff; }
.btn-restore:hover { background: #4338ca; }
.btn-download { background: #059669; color: #fff; }
.btn-download:hover { background: #047857; }
.btn-delete { background: #fee2e2; color: #dc2626; }
.btn-delete:hover { background: #fecaca; }
.btn-create { padding: 10px 20px; border-radius: 10px; font-size: 0.88rem; font-weight: 600; cursor: pointer; border: none; background: #4f46e5; color: #fff; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s; }
.btn-create:hover { background: #4338ca; transform: translateY(-1px); }
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state i { font-size: 3rem; opacity: 0.3; margin-bottom: 16px; }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.modal-overlay.active { display: flex; }
.modal-box { background: var(--card-bg, #fff); border-radius: 16px; padding: 28px; max-width: 460px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
.modal-box h3 { margin: 0 0 12px; font-size: 1.1rem; }
.modal-box p { margin: 0 0 20px; font-size: 0.88rem; color: var(--text-muted); }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.modal-actions .btn-cancel { padding: 8px 20px; border-radius: 8px; border: 1px solid var(--border, #d1d5db); background: transparent; color: var(--text); cursor: pointer; font-weight: 600; }
.modal-actions .btn-confirm { padding: 8px 20px; border-radius: 8px; border: none; background: #dc2626; color: #fff; cursor: pointer; font-weight: 600; }
.modal-actions .btn-confirm.restore { background: #4f46e5; }
.toast { position: fixed; top: 20px; right: 20px; padding: 14px 24px; border-radius: 12px; color: #fff; font-weight: 600; font-size: 0.88rem; z-index: 2000; transform: translateX(120%); transition: transform 0.3s ease; display: flex; align-items: center; gap: 8px; }
.toast.show { transform: translateX(0); }
.toast.success { background: #059669; }
.toast.error { background: #dc2626; }
.toast.info { background: #4f46e5; }
.spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 768px) {
    .backup-page { padding: 16px; }
    .backup-table th:nth-child(4), .backup-table td:nth-child(4),
    .backup-table th:nth-child(5), .backup-table td:nth-child(5) { display: none; }
    .action-btns { flex-direction: column; }
}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<main class="main-content">
<div class="backup-page">
    <div class="backup-header">
        <div>
            <h1><i class="fas fa-database" style="color:#4f46e5;margin-right:8px;"></i>Database Backups</h1>
            <p style="color:var(--text-muted);font-size:0.85rem;margin:4px 0 0;">Daily automatic backups of your attendance system data</p>
        </div>
        <button class="btn-create" onclick="createBackup()">
            <i class="fas fa-plus"></i> Create Backup Now
        </button>
    </div>

    <div class="backup-stats">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e0e7ff;color:#4f46e5;"><i class="fas fa-database"></i></div>
            <div class="stat-value"><?= count($backups) ?></div>
            <div class="stat-label">Total Backups</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#d1fae5;color:#059669;"><i class="fas fa-check-circle"></i></div>
            <div class="stat-value"><?= $hasTodayBackup ? 'Yes' : 'No' ?></div>
            <div class="stat-label">Today's Backup</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-hdd"></i></div>
            <div class="stat-value"><?php
                $totalSize = array_sum(array_column($backups, 'file_size'));
                if ($totalSize >= 1048576) echo round($totalSize / 1048576, 1) . ' MB';
                elseif ($totalSize >= 1024) echo round($totalSize / 1024, 1) . ' KB';
                else echo $totalSize . ' B';
            ?></div>
            <div class="stat-label">Total Storage Used</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fce7f3;color:#db2777;"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-value"><?= !empty($backups) ? date('M j', strtotime($backups[0]['created_at'])) : '--' ?></div>
            <div class="stat-label">Latest Backup</div>
        </div>
    </div>

    <div class="backup-table-wrap">
        <?php if (empty($backups)): ?>
        <div class="empty-state">
            <i class="fas fa-database"></i>
            <p style="font-size:1rem;font-weight:600;">No backups yet</p>
            <p>Click "Create Backup Now" to create your first backup.</p>
        </div>
        <?php else: ?>
        <table class="backup-table">
            <thead>
                <tr>
                    <th>Backup Name</th>
                    <th>Date</th>
                    <th>Size</th>
                    <th>Tables</th>
                    <th>Rows</th>
                    <th>Type</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($backups as $b): 
                $isAuto = strpos($b['backup_name'], 'auto_') === 0;
                $isToday = $b['backup_date'] === $today;
                $size = $b['file_size'];
                if ($size >= 1048576) $sizeStr = round($size / 1048576, 1) . ' MB';
                elseif ($size >= 1024) $sizeStr = round($size / 1024, 1) . ' KB';
                else $sizeStr = $size . ' B';
            ?>
                <tr>
                    <td class="name">
                        <i class="fas fa-file-code" style="color:#4f46e5;margin-right:6px;"></i>
                        <?= htmlspecialchars($b['backup_name']) ?>
                    </td>
                    <td class="date">
                        <?= date('M j, Y', strtotime($b['backup_date'])) ?>
                        <?php if ($isToday): ?><span class="badge-today">Today</span><?php endif; ?>
                    </td>
                    <td class="size"><?= $sizeStr ?></td>
                    <td><?= (int)$b['table_count'] ?></td>
                    <td><?= number_format((int)$b['row_count']) ?></td>
                    <td>
                        <?php if ($isAuto): ?>
                            <span class="badge-auto">Auto</span>
                        <?php else: ?>
                            <span class="badge-manual">Manual</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-btns">
                            <button class="btn-sm btn-restore" onclick="confirmRestore(<?= $b['id'] ?>, '<?= htmlspecialchars($b['backup_name'], ENT_QUOTES) ?>')" title="Restore this backup">
                                <i class="fas fa-undo"></i> Restore
                            </button>
                            <a href="../api/backup_manage.php?action=download&backup_id=<?= $b['id'] ?>" class="btn-sm btn-download" title="Download SQL file">
                                <i class="fas fa-download"></i>
                            </a>
                            <button class="btn-sm btn-delete" onclick="confirmDelete(<?= $b['id'] ?>, '<?= htmlspecialchars($b['backup_name'], ENT_QUOTES) ?>')" title="Delete this backup">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</main>

<!-- Confirm Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <h3 id="modalTitle">Confirm Action</h3>
        <p id="modalMessage">Are you sure?</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-confirm" id="modalConfirmBtn" onclick="executeAction()">Confirm</button>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div class="toast" id="toast"></div>

<script>
let pendingAction = null;

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.className = 'toast ' + type;
    t.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle') + '"></i> ' + msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 4000);
}

function openModal(title, message, btnClass, btnText) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMessage').textContent = message;
    const btn = document.getElementById('modalConfirmBtn');
    btn.className = 'btn-confirm' + (btnClass ? ' ' + btnClass : '');
    btn.textContent = btnText || 'Confirm';
    document.getElementById('confirmModal').classList.add('active');
}

function closeModal() {
    document.getElementById('confirmModal').classList.remove('active');
    pendingAction = null;
}

function executeAction() {
    if (pendingAction) pendingAction();
    closeModal();
}

function createBackup() {
    const btn = event.target.closest('.btn-create');
    const origHTML = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Creating...';
    btn.disabled = true;

    fetch('../api/backup_manage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=create'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message);
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to create backup', 'error');
            btn.innerHTML = origHTML;
            btn.disabled = false;
        }
    })
    .catch(() => {
        showToast('Network error', 'error');
        btn.innerHTML = origHTML;
        btn.disabled = false;
    });
}

function confirmRestore(id, name) {
    pendingAction = () => {
        showToast('Restoring backup... This may take a moment.', 'info');
        fetch('../api/backup_manage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=restore&backup_id=' + id
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message);
                setTimeout(() => location.reload(), 2000);
            } else {
                showToast(data.error || 'Restore failed', 'error');
            }
        })
        .catch(() => showToast('Network error during restore', 'error'));
    };
    openModal(
        'Restore Database',
        'This will REPLACE all current data with the backup "' + name + '". This action cannot be undone. Are you sure?',
        'restore',
        'Restore Now'
    );
}

function confirmDelete(id, name) {
    pendingAction = () => {
        fetch('../api/backup_manage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete&backup_id=' + id
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Backup deleted');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.error || 'Delete failed', 'error');
            }
        })
        .catch(() => showToast('Network error', 'error'));
    };
    openModal(
        'Delete Backup',
        'Delete "' + name + '"? This cannot be undone.',
        '',
        'Delete'
    );
}
</script>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
