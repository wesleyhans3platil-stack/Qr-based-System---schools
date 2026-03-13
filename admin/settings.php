<?php
session_start();
require_once '../config/database.php';
$conn = getDBConnection();

// Trigger a deploy hook (POST JSON). Returns true on 2xx response.
function triggerDeployHook($url) {
    if (empty($url)) return false;
    $payload = json_encode(['event' => 'redeploy', 'ts' => date('c')]);
    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $http >= 200 && $http < 300;
    }
    $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 10]];
    $ctx = stream_context_create($opts);
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) return false;
    // best-effort: consider success if we got some response
    return true;
}

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    header('Location: ../admin_login.php');
    exit;
}

$current_page = 'settings';
$page_title = 'Settings';
$success = '';
$error = '';

// Handle time settings update
if (isset($_POST['update_time'])) {
    $fields = ['time_in_start', 'time_in_end', 'time_out_start', 'time_out_end'];
    foreach ($fields as $f) {
        $val = sanitize($_POST[$f] ?? '');
        if ($val) {
            $stmt = $conn->prepare("INSERT INTO time_settings (setting_name, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("sss", $f, $val, $val);
            $stmt->execute();
        }
    }
    $success = 'Time settings updated!';
}

// Handle admin add
if (isset($_POST['add_admin'])) {
    $username = sanitize($_POST['username'] ?? '');
    $full_name = sanitize($_POST['full_name'] ?? '');
    $password = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
    $role = sanitize($_POST['role'] ?? 'principal');
    $school_id = !empty($_POST['school_id']) ? (int)$_POST['school_id'] : null;

    // Check duplicate username first
    $chk = $conn->prepare("SELECT id FROM admins WHERE username = ?");
    $chk->bind_param("s", $username); $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $error = 'Username already exists.';
    } else {
        if ($school_id) {
            $stmt = $conn->prepare("INSERT INTO admins (username, password, full_name, role, school_id, temp_password) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->bind_param("ssssi", $username, $password, $full_name, $role, $school_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO admins (username, password, full_name, role, temp_password) VALUES (?, ?, ?, ?, 1)");
            $stmt->bind_param("ssss", $username, $password, $full_name, $role);
        }
        try {
            $stmt->execute();
            $success = 'Admin added!';
        } catch (Exception $e) {
            $error = 'Failed to create admin: ' . $e->getMessage();
        }
    }
}

// Handle admin delete
if (isset($_POST['delete_admin'])) {
    $id = (int)$_POST['admin_id'];
    if ($id != ($_SESSION['admin_id'] ?? 0)) {
        $conn->query("DELETE FROM admins WHERE id = $id");
        $success = 'Admin deleted.';
    } else { $error = "Can't delete your own account."; }
}

// Handle admin password change
if (isset($_POST['change_password'])) {
    $id = (int)$_POST['admin_id'];
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    
    if (strlen($new_pass) < 4) {
        $error = 'Password must be at least 4 characters.';
    } elseif ($new_pass !== $confirm_pass) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE admins SET password = ?, temp_password = 0 WHERE id = ?");
        $stmt->bind_param("si", $hashed, $id);
        if ($stmt->execute()) {
            $success = 'Password changed successfully!';
        } else {
            $error = 'Failed to change password.';
        }
    }
}

// Handle add holiday
if (isset($_POST['add_holiday'])) {
    $hdate = trim($_POST['holiday_date'] ?? '');
    $hname = trim($_POST['holiday_name'] ?? '');
    $htype = trim($_POST['holiday_type'] ?? 'regular');
    $hschool = !empty($_POST['holiday_school']) ? (int)$_POST['holiday_school'] : null;
    if ($hdate && $hname) {
        $stmt = $conn->prepare("INSERT INTO holidays (holiday_date, name, type, school_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)");
        $stmt->bind_param("sssi", $hdate, $hname, $htype, $hschool);
        if ($stmt->execute()) {
            $success = 'Holiday added!';
        } else {
            $error = 'Failed to add holiday.';
        }
    } else {
        $error = 'Please fill in both date and holiday name.';
    }
}

// Handle delete holiday
if (isset($_POST['delete_holiday'])) {
    $hid = (int)$_POST['holiday_id'];
    $conn->query("DELETE FROM holidays WHERE id = $hid");
    $success = 'Holiday deleted.';
}

// Handle system logo upload
if (isset($_POST['upload_logo'])) {
    if (!empty($_FILES['system_logo']['name'])) {
        $uploadDir = '../assets/uploads/logos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $ext = strtolower(pathinfo($_FILES['system_logo']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            // Delete old logo if exists
            $oldLogo = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'")->fetch_assoc();
            if ($oldLogo && $oldLogo['setting_value'] && file_exists($uploadDir . $oldLogo['setting_value'])) {
                unlink($uploadDir . $oldLogo['setting_value']);
            }
            
            $filename = 'system_logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['system_logo']['tmp_name'], $uploadDir . $filename)) {
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('system_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->bind_param("ss", $filename, $filename);
                $stmt->execute();
                // Persist in DB for Railway redeploys
                storeFileInDB('assets/uploads/logos/' . $filename, $uploadDir . $filename);
                // Remove old file from DB
                if ($oldLogo && $oldLogo['setting_value']) {
                    removeFileFromDB('assets/uploads/logos/' . $oldLogo['setting_value']);
                }
                $success = 'System logo updated!';
            } else {
                $error = 'Failed to upload logo.';
            }
        } else {
            $error = 'Invalid file type. Allowed: ' . implode(', ', $allowed);
        }
    } else {
        $error = 'Please select a logo file.';
    }
}

// Handle system logo removal
if (isset($_POST['remove_logo'])) {
    $uploadDir = '../assets/uploads/logos/';
    $oldLogo = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'")->fetch_assoc();
    if ($oldLogo && $oldLogo['setting_value']) {
        if (file_exists($uploadDir . $oldLogo['setting_value'])) {
            unlink($uploadDir . $oldLogo['setting_value']);
        }
        removeFileFromDB('assets/uploads/logos/' . $oldLogo['setting_value']);
    }
    $conn->query("DELETE FROM system_settings WHERE setting_key='system_logo'");
    $success = 'Logo removed.';
}

// Handle system settings update
if (isset($_POST['update_system'])) {
    $fields = ['division_name', 'system_name', 'sds_name', 'sds_mobile', 'asds_name', 'asds_mobile', 'sms_api_key', 'notification_numbers', 'google_client_id'];
    foreach ($fields as $f) {
        $val = trim($_POST[$f] ?? '');
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $f, $val, $val);
        $stmt->execute();
    }
    // Launch start date (optional)
    $launch_start = trim($_POST['launch_start_date'] ?? '');
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('launch_start_date', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("ss", $launch_start, $launch_start);
    $stmt->execute();
    // SMS enabled toggle
    $sms_enabled = isset($_POST['sms_enabled']) ? '1' : '0';
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('sms_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("ss", $sms_enabled, $sms_enabled);
    $stmt->execute();
    // Deploy webhook and auto-redeploy toggle
    $deploy_webhook = trim($_POST['deploy_webhook_url'] ?? '');
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('deploy_webhook_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("ss", $deploy_webhook, $deploy_webhook);
    $stmt->execute();
    $auto_redeploy = isset($_POST['auto_redeploy']) ? '1' : '0';
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('auto_redeploy', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("ss", $auto_redeploy, $auto_redeploy);
    $stmt->execute();
    $success = 'System settings updated!';

    // If auto redeploy enabled, trigger webhook (best-effort)
    if ($auto_redeploy === '1' && !empty($deploy_webhook)) {
        $ok = triggerDeployHook($deploy_webhook);
        if ($ok) $success .= ' Redeploy triggered.'; else $success .= ' Redeploy webhook failed.';
    }
}

// Manual redeploy trigger
if (isset($_POST['trigger_deploy'])) {
    $webhook = trim($_POST['deploy_webhook_url'] ?? ($sys['deploy_webhook_url'] ?? ''));
    if (empty($webhook)) { $error = 'No deploy webhook configured.'; }
    else {
        $ok = triggerDeployHook($webhook);
        if ($ok) $success = 'Redeploy triggered successfully.'; else $error = 'Failed to call deploy webhook.';
    }
}

// Fetch time settings
$time_settings = [];
$r = $conn->query("SELECT setting_name, setting_value FROM time_settings");
if ($r) { while ($row = $r->fetch_assoc()) $time_settings[$row['setting_name']] = $row['setting_value']; }

// Fetch system settings
$sys = [];
$r = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($r) { while ($row = $r->fetch_assoc()) $sys[$row['setting_key']] = $row['setting_value']; }

// Fetch admins
$admins = [];
$r = $conn->query("SELECT a.*, sch.name as school_name FROM admins a LEFT JOIN schools sch ON a.school_id = sch.id ORDER BY a.role, a.username");
if ($r) { while ($row = $r->fetch_assoc()) $admins[] = $row; }

$schools = [];
$r = $conn->query("SELECT id, name FROM schools WHERE status='active' ORDER BY name");
if ($r) { while ($row = $r->fetch_assoc()) $schools[] = $row; }

// Fetch holidays with school name
$holidays_list = [];
$r = $conn->query("SELECT h.*, s.name as school_name FROM holidays h LEFT JOIN schools s ON h.school_id = s.id ORDER BY h.holiday_date ASC");
if ($r) { while ($row = $r->fetch_assoc()) $holidays_list[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/header.php'; ?></head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-cog" style="color:var(--primary);margin-right:8px;"></i> System Settings</h1>
            <p>Configure time windows and manage admin accounts</p>
        </div>

        <?php // Show current Launch Start Date prominently for super-admins ?>
        <div style="margin-bottom:12px;">
            <span style="display:inline-block;background:#eef2ff;border:1px solid #e0e7ff;padding:8px 12px;border-radius:8px;font-weight:600;color:#3730a3;">
                <i class="fas fa-flag" style="margin-right:8px;color:#4338ca;"></i> Launch Start Date:
                <span style="margin-left:8px;color:inherit;"><?= !empty($sys['launch_start_date']) ? htmlspecialchars($sys['launch_start_date']) : '<span style="color:#dc2626;font-weight:700;">Not set</span>' ?></span>
            </span>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= $error ?></div><?php endif; ?>

        <!-- System Logo Upload -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-title"><i class="fas fa-image"></i> System Logo</div>
            <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:16px;">Upload a logo that will be displayed on the scanner kiosk page. Recommended size: 200×200px, square format.</p>
            <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
                <div style="width:80px;height:80px;border-radius:16px;overflow:hidden;border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;background:var(--bg);flex-shrink:0;">
                    <?php if (!empty($sys['system_logo']) && file_exists('../assets/uploads/logos/' . $sys['system_logo'])): ?>
                        <img src="../assets/uploads/logos/<?= htmlspecialchars($sys['system_logo']) ?>" alt="Logo" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <i class="fas fa-building-columns" style="font-size:1.5rem;color:var(--text-muted);"></i>
                    <?php endif; ?>
                </div>
                <div style="flex:1;display:flex;flex-direction:column;gap:10px;">
                    <form method="POST" enctype="multipart/form-data" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <input type="file" name="system_logo" accept="image/*" class="form-control" style="max-width:280px;padding:8px;" required>
                        <button type="submit" name="upload_logo" class="btn btn-primary"><i class="fas fa-upload"></i> Upload Logo</button>
                    </form>
                    <?php if (!empty($sys['system_logo']) && file_exists('../assets/uploads/logos/' . $sys['system_logo'])): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove the system logo?')">
                            <button type="submit" name="remove_logo" class="btn" style="background:#fee2e2;color:#dc2626;font-size:0.78rem;padding:6px 14px;border:none;border-radius:8px;cursor:pointer;">
                                <i class="fas fa-trash"></i> Remove Logo
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
            <!-- Time Settings -->
            <div class="card">
                <div class="card-title"><i class="fas fa-clock"></i> Attendance Time Windows</div>
                <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:14px;">
                    <b>Morning:</b> Time In anytime up to Time In End &bull; Time Out from Time In End to PM Time In<br>
                    <b>Afternoon:</b> Late Time In (if no record) or Time Out until Time Out End &bull; Blocked after Time Out End
                </p>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group"><label>AM Time In End (Late after)</label><input type="time" name="time_in_end" class="form-control" value="<?= $time_settings['time_in_end'] ?? '11:30' ?>"></div>
                        <div class="form-group"><label>AM Time Out Until</label><input type="time" name="time_out_start" class="form-control" value="<?= $time_settings['time_out_start'] ?? '13:00' ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>PM Time In Start</label><input type="time" name="time_in_start" class="form-control" value="<?= $time_settings['time_in_start'] ?? '13:00' ?>"></div>
                        <div class="form-group"><label>PM Time Out End</label><input type="time" name="time_out_end" class="form-control" value="<?= $time_settings['time_out_end'] ?? '16:00' ?>"></div>
                    </div>
                    <button type="submit" name="update_time" class="btn btn-primary" style="width:100%;"><i class="fas fa-save"></i> Save Time Settings</button>
                </form>
            </div>

            <!-- SMS & System Settings -->
            <div class="card">
                <div class="card-title"><i class="fas fa-cogs"></i> Division & SMS Settings</div>
                <form method="POST">
                    <div class="form-group">
                        <label>Division Name</label>
                        <input type="text" name="division_name" class="form-control" value="<?= htmlspecialchars($sys['division_name'] ?? 'Division of Sipalay City') ?>" placeholder="e.g. Division of Sipalay City">
                    </div>
                    <div class="form-group">
                        <label>System Name</label>
                        <input type="text" name="system_name" class="form-control" value="<?= htmlspecialchars($sys['system_name'] ?? 'QR Attendance System') ?>" placeholder="e.g. QR Attendance System">
                    </div>
                    <div class="form-group">
                        <label>Launch Start Date (optional)</label>
                        <input type="date" name="launch_start_date" class="form-control" value="<?= htmlspecialchars($sys['launch_start_date'] ?? '') ?>" placeholder="2026-06-01">
                        <small style="color:var(--text-muted);display:block;margin-top:6px;">If set, new imports without an "Active from" date will default to this launch date.</small>
                    </div>
                    <div class="form-group">
                        <label>Deploy Webhook (optional)</label>
                        <input type="url" name="deploy_webhook_url" class="form-control" value="<?= htmlspecialchars($sys['deploy_webhook_url'] ?? '') ?>" placeholder="https://example.com/deploy-hook">
                        <small style="color:var(--text-muted);display:block;margin-top:6px;">Optional HTTP webhook (e.g., Railway/GitHub Actions) called when you trigger redeploy or when auto-redeploy is enabled.</small>
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" id="auto_redeploy" name="auto_redeploy" value="1" <?= (!empty($sys['auto_redeploy']) && $sys['auto_redeploy'] === '1') ? 'checked' : '' ?> />
                        <label for="auto_redeploy" style="margin:0;font-weight:600;">Auto redeploy on settings save</label>
                    </div>
                    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0;">
                    <div class="form-row">
                        <div class="form-group"><label>SDS Name</label><input type="text" name="sds_name" class="form-control" value="<?= htmlspecialchars($sys['sds_name'] ?? '') ?>" placeholder="Full name of SDS"></div>
                        <div class="form-group"><label>SDS Mobile</label><input type="text" name="sds_mobile" class="form-control" value="<?= htmlspecialchars($sys['sds_mobile'] ?? '') ?>" placeholder="09171234567"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>ASDS Name</label><input type="text" name="asds_name" class="form-control" value="<?= htmlspecialchars($sys['asds_name'] ?? '') ?>" placeholder="Full name of ASDS"></div>
                        <div class="form-group"><label>ASDS Mobile</label><input type="text" name="asds_mobile" class="form-control" value="<?= htmlspecialchars($sys['asds_mobile'] ?? '') ?>" placeholder="09171234567"></div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" name="update_system" class="btn btn-primary" style="flex:1;"><i class="fas fa-save"></i> Save System Settings</button>
                        <button type="submit" name="trigger_deploy" class="btn" style="flex:0 0 200px;background:#eef2ff;color:#3730a3;border:1px solid #e0e7ff;"><i class="fas fa-rocket"></i> Trigger Redeploy Now</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Admin Accounts -->
        <!-- Holiday Management -->
        <div class="card" style="margin-top:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <div>
                    <div class="card-title" style="margin-bottom:4px;"><i class="fas fa-calendar-xmark"></i> Holiday Management</div>
                    <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">Manage holidays and non-school days. Attendance will not be required on weekends (Sat/Sun) and listed holidays.</p>
                </div>
            </div>
            <!-- Add Holiday Form -->
            <form method="POST" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:20px;padding:16px;background:var(--bg);border-radius:12px;border:1px solid var(--border);">
                <div class="form-group" style="margin-bottom:0;flex:0 0 170px;">
                    <label style="font-size:0.78rem;font-weight:600;margin-bottom:4px;display:block;">Date</label>
                    <input type="date" name="holiday_date" class="form-control" required style="padding:8px 12px;">
                </div>
                <div class="form-group" style="margin-bottom:0;flex:1;min-width:180px;">
                    <label style="font-size:0.78rem;font-weight:600;margin-bottom:4px;display:block;">Holiday Name</label>
                    <input type="text" name="holiday_name" class="form-control" placeholder="e.g. Rizal Day" required style="padding:8px 12px;">
                </div>
                <div class="form-group" style="margin-bottom:0;flex:0 0 180px;">
                    <label style="font-size:0.78rem;font-weight:600;margin-bottom:4px;display:block;">Type</label>
                    <select name="holiday_type" class="form-control" style="padding:8px 12px;">
                        <option value="regular">Regular Holiday</option>
                        <option value="special">Special Non-Working</option>
                        <option value="suspension">Class Suspension</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;flex:0 0 200px;">
                    <label style="font-size:0.78rem;font-weight:600;margin-bottom:4px;display:block;">Scope</label>
                    <select name="holiday_school" class="form-control" style="padding:8px 12px;">
                        <option value="">All Schools (Division)</option>
                        <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_holiday" class="btn btn-primary" style="padding:9px 20px;white-space:nowrap;"><i class="fas fa-plus"></i> Add Holiday</button>
            </form>
            <!-- Holiday List -->
            <?php if (empty($holidays_list)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted);font-size:0.85rem;">
                    <i class="fas fa-calendar-check" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                    No holidays configured yet. Weekends (Saturday & Sunday) are automatically treated as non-school days.
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Date</th><th>Holiday Name</th><th>Type</th><th>Scope</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($holidays_list as $h): 
                                $type_colors = ['regular' => '#dc2626', 'special' => '#f59e0b', 'suspension' => '#6366f1'];
                                $type_bg = ['regular' => '#fee2e2', 'special' => '#fef3c7', 'suspension' => '#e0e7ff'];
                                $htype = $h['type'] ?? 'regular';
                            ?>
                                <tr>
                                    <td style="font-weight:600;white-space:nowrap;"><?= date('M j, Y (D)', strtotime($h['holiday_date'])) ?></td>
                                    <td><?= htmlspecialchars($h['name']) ?></td>
                                    <td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;background:<?= $type_bg[$htype] ?? '#f3f4f6' ?>;color:<?= $type_colors[$htype] ?? '#666' ?>;"><?= ucfirst($htype) ?><?= $htype === 'suspension' ? '' : ' Holiday' ?></span></td>
                                    <td>
                                        <?php if ($h['school_id']): ?>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;background:#dbeafe;color:#1d4ed8;"><i class="fas fa-school" style="margin-right:3px;"></i><?= htmlspecialchars($h['school_name'] ?? 'School #'.$h['school_id']) ?></span>
                                        <?php else: ?>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;background:#f0fdf4;color:#16a34a;"><i class="fas fa-globe" style="margin-right:3px;"></i>All Schools</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this holiday?')">
                                            <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                                            <button type="submit" name="delete_holiday" class="action-btn action-btn-delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:10px;">
                    <i class="fas fa-info-circle"></i> <?= count($holidays_list) ?> holiday<?= count($holidays_list) !== 1 ? 's' : '' ?> configured. Weekends are automatically excluded.
                </div>
            <?php endif; ?>
        </div>

        <!-- Admin Accounts -->
        <div class="card" style="margin-top:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <div class="card-title" style="margin-bottom:0;"><i class="fas fa-users-cog"></i> Admin Accounts</div>
                <button class="btn btn-primary" onclick="document.getElementById('addAdminModal').classList.add('active')"><i class="fas fa-plus"></i> Add Admin</button>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Username</th><th>Full Name</th><th>Role</th><th>School</th><th>Last Login</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($admins as $a): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($a['username']) ?></strong></td>
                                <td><?= htmlspecialchars($a['full_name']) ?></td>
                                <td><span class="badge badge-primary"><?= ucwords(str_replace('_', ' ', $a['role'])) ?></span></td>
                                <td><?= htmlspecialchars($a['school_name'] ?? 'All Schools') ?></td>
                                <td style="font-size:0.82rem;color:var(--text-muted);"><?= $a['last_login'] ? date('M j, Y h:i A', strtotime($a['last_login'])) : 'Never' ?></td>
                                <td>
                                    <div style="display:flex;gap:6px;">
                                    <button class="action-btn" style="background:#e0e7ff;color:#4338ca;" onclick="openChangePassword(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['username'])) ?>')" title="Change Password"><i class="fas fa-key"></i></button>
                                    <?php if ($a['id'] != ($_SESSION['admin_id'] ?? 0)): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this admin?')">
                                        <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                                        <button type="submit" name="delete_admin" class="action-btn action-btn-delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sample Data Generator -->
        <div class="card" style="margin-top:24px;border:2px dashed var(--border);">
            <div class="card-title"><i class="fas fa-flask" style="color:#8b5cf6;"></i> Sample Data Generator</div>
            <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:16px;">Generate sample/demo students with Filipino names across all active schools. Sections and QR codes are created automatically.</p>
            <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;padding:16px;background:var(--bg);border-radius:12px;border:1px solid var(--border);">
                <div class="form-group" style="margin-bottom:0;flex:0 0 200px;">
                    <label style="font-size:0.78rem;font-weight:600;margin-bottom:4px;display:block;">Students per School</label>
                    <input type="number" id="seedCount" class="form-control" value="10" min="1" max="50" style="padding:8px 12px;">
                </div>
                <button type="button" class="btn btn-primary" id="seedBtn" onclick="seedStudents()" style="padding:9px 20px;white-space:nowrap;background:#8b5cf6;">
                    <i class="fas fa-wand-magic-sparkles"></i> Generate Sample Students
                </button>
                <button type="button" class="btn" id="clearSeedBtn" onclick="clearSeedData()" style="padding:9px 20px;white-space:nowrap;background:#fee2e2;color:#dc2626;border:none;border-radius:8px;cursor:pointer;">
                    <i class="fas fa-trash"></i> Clear Sample Data
                </button>
            </div>
            <div id="seedResult" style="display:none;margin-top:16px;"></div>
        </div>
    </div>

    <!-- Add Admin Modal -->
    <div class="modal-overlay" id="addAdminModal">
        <div class="modal">
            <div class="modal-header"><h3><i class="fas fa-user-plus" style="color:var(--primary);margin-right:8px;"></i> Add Admin</h3><button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button></div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label>Username *</label><input type="text" name="username" class="form-control" required></div>
                        <div class="form-group"><label>Password *</label><input type="password" name="password" class="form-control" required></div>
                    </div>
                    <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Role *</label>
                            <select name="role" class="form-control" required id="roleSelect" onchange="toggleSchool()">
                                <option value="super_admin">Super Admin</option>
                                <option value="superintendent">Superintendent</option>
                                <option value="asst_superintendent">Asst. Superintendent</option>
                                <option value="principal">Principal</option>
                            </select>
                        </div>
                        <div class="form-group" id="schoolGroup" style="display:none;">
                            <label>Assigned School</label>
                            <select name="school_id" class="form-control">
                                <option value="">All Schools</option>
                                <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="add_admin" class="btn btn-primary"><i class="fas fa-save"></i> Create</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal-overlay" id="changePasswordModal">
        <div class="modal">
            <div class="modal-header"><h3><i class="fas fa-key" style="color:var(--primary);margin-right:8px;"></i> Change Password</h3><button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="admin_id" id="cpAdminId">
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:0.85rem;">
                        <i class="fas fa-user" style="color:var(--primary);margin-right:6px;"></i> Changing password for: <strong id="cpUsername"></strong>
                    </div>
                    <div class="form-group">
                        <label>New Password *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="4" placeholder="Enter new password">
                    </div>
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="4" placeholder="Re-enter new password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                    <button type="submit" name="change_password" class="btn btn-primary"><i class="fas fa-save"></i> Change Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function toggleSchool() {
        const role = document.getElementById('roleSelect').value;
        document.getElementById('schoolGroup').style.display = role === 'principal' ? '' : 'none';
    }

    function openChangePassword(id, username) {
        document.getElementById('cpAdminId').value = id;
        document.getElementById('cpUsername').textContent = username;
        document.getElementById('changePasswordModal').classList.add('active');
    }

    function clearSeedData() {
        if (!confirm('Delete ALL sample/demo students (QR starting with STU-)? This cannot be undone.')) return;
        const btn = document.getElementById('clearSeedBtn');
        const resultDiv = document.getElementById('seedResult');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clearing...';
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Removing sample data...</div>';
        fetch('../api/seed_students.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=clear'
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Clear Sample Data';
            if (data.success) {
                resultDiv.innerHTML = `<div class="alert alert-success" style="margin:0;"><i class="fas fa-check-circle"></i> <strong>${data.deleted}</strong> sample student(s) and <strong>${data.sections_removed}</strong> empty section(s) removed.</div>`;
            } else {
                resultDiv.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> ${data.error || 'Failed to clear.'}</div>`;
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Clear Sample Data';
            resultDiv.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> Error: ${err.message}</div>`;
        });
    }

    function seedStudents() {
        const btn = document.getElementById('seedBtn');
        const resultDiv = document.getElementById('seedResult');
        const count = document.getElementById('seedCount').value;

        if (!confirm('Generate ' + count + ' sample students per school?')) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Creating sample students...</div>';

        fetch('../api/seed_students.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'count=' + encodeURIComponent(count)
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate Sample Students';
            if (data.success) {
                resultDiv.innerHTML = `
                    <div class="alert alert-success" style="margin:0;">
                        <i class="fas fa-check-circle"></i> 
                        <strong>${data.students_added}</strong> students added across <strong>${data.schools_count}</strong> school(s). 
                        <strong>${data.sections_created}</strong> new section(s) created.
                    </div>`;
            } else {
                resultDiv.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> ${data.error || 'Failed to generate students.'}</div>`;
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate Sample Students';
            resultDiv.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> Error: ${err.message}</div>`;
        });
    }

    function checkAbsenceSMS() {
        const btn = document.getElementById('smsCheckBtn');
        const resultDiv = document.getElementById('smsResult');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Scanning attendance records...</div>';

        fetch('../api/sms_absence_check.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'} })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Check & Send SMS';

                if (!data.success) {
                    resultDiv.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> ${data.error || 'Failed to check absences.'}</div>`;
                    return;
                }

                let html = '';
                
                if (data.flagged === 0) {
                    html = `<div class="alert alert-success" style="margin:0;"><i class="fas fa-check-circle"></i> No 2-day consecutive absentees found today. All clear!</div>`;
                } else {
                    const smsStatus = data.sms_sent > 0 
                        ? `<span style="color:var(--success);font-weight:600;"><i class="fas fa-check-circle"></i> ${data.sms_sent} SMS sent successfully</span>`
                        : `<span style="color:var(--warning);"><i class="fas fa-exclamation-triangle"></i> SMS not sent — check settings</span>`;

                    html = `
                        <div style="background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px;">
                            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px;">
                                <div><span style="color:var(--text-muted);font-size:0.8rem;">Students Absent 2 Days</span><br><strong style="font-size:1.3rem;color:var(--danger);">${data.absent_students}</strong></div>
                                <div><span style="color:var(--text-muted);font-size:0.8rem;">Teachers Absent 2 Days</span><br><strong style="font-size:1.3rem;color:var(--warning);">${data.absent_teachers}</strong></div>
                                <div><span style="color:var(--text-muted);font-size:0.8rem;">SMS Status</span><br>${smsStatus}</div>
                            </div>
                            ${data.notification_numbers && data.notification_numbers.length > 0 
                                ? `<div style="font-size:0.78rem;color:var(--text-muted);">Sent to: ${data.notification_numbers.join(', ')}</div>` 
                                : ''}
                        </div>
                        <details style="font-size:0.82rem;">
                            <summary style="cursor:pointer;color:var(--primary);font-weight:600;margin-bottom:8px;">View SMS Message Preview</summary>
                            <pre style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px;font-size:0.78rem;white-space:pre-wrap;color:var(--text-muted);max-height:300px;overflow-y:auto;">${data.full_message || 'No message'}</pre>
                        </details>
                    `;

                    if (data.sms_failed > 0) {
                        html += `<div class="alert alert-error" style="margin-top:10px;"><i class="fas fa-times-circle"></i> ${data.sms_failed} SMS failed to send. Check your API key and phone numbers.</div>`;
                    }
                }
                
                resultDiv.innerHTML = html;
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Check & Send SMS';
                resultDiv.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> Error: ${err.message}</div>`;
            });
    }
    </script>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
