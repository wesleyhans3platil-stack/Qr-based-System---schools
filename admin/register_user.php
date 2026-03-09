<?php
session_start();
require_once '../config/database.php';

// Get database connection
$conn = getDBConnection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register User - QR Attendance System</title>
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

        /* Sidebar */
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

        .sidebar-header h2 {
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1.3;
            white-space: nowrap;
        }

        .sidebar-header span {
            font-size: 0.75rem;
            color: var(--text-muted);
            line-height: 1.3;
        }

        .nav-menu {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 4px;
        }

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

        .nav-link i {
            width: 18px;
            min-width: 18px;
            text-align: center;
            font-size: 1rem;
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
        }

        .header {
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .header p {
            color: var(--text-muted);
        }

        /* Form Container */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 30px;
        }

        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
        }

        .form-card-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            font-size: 16px;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }

        select.form-control {
            cursor: pointer;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .btn {
            padding: 14px 30px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        /* QR Preview Card */
        .qr-preview-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            position: sticky;
            top: 30px;
        }

        .qr-preview-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 20px;
        }

        /* ID Card Preview Styles */
        /* Card Label */
        .card-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        /* Front ID Card Styles */
        .id-card-front-preview {
            background: white;
            border-radius: 0;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 300px;
            height: 480px;
            margin: 0 auto 15px;
            border: 2px solid #000;
            display: flex;
            flex-direction: column;
        }

        .front-header {
            padding: 8px 10px 6px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            border-bottom: 2px solid #ddd;
        }

        .front-header-content {
            line-height: 1.2;
            flex: 1;
            text-align: center;
        }

        .front-republic {
            font-family: 'Old English Text MT', 'Times New Roman', serif;
            font-size: 8px;
            font-style: italic;
            color: #000;
        }

        .front-deped {
            font-family: 'Old English Text MT', 'Times New Roman', serif;
            font-size: 14px;
            font-weight: bold;
            color: #000;
        }

        .front-region {
            font-family: 'Cambria Math', Cambria, serif;
            font-size: 7px;
            color: #000;
            font-style: italic;
        }

        .front-division {
            font-family: Tahoma, Arial, sans-serif;
            font-size: 8px;
            font-weight: bold;
            color: #000;
            letter-spacing: 0.3px;
        }

        .front-logo {
            width: 55px;
            height: 55px;
            flex-shrink: 0;
        }

        .front-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .front-title {
            background: #1a5c1a;
            color: white;
            text-align: center;
            padding: 12px;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 4px;
        }

        .front-body {
            display: flex;
            padding: 20px;
            gap: 15px;
        }

        .front-photo {
            width: 90px;
            height: 110px;
            border: 4px solid #c9a227;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
            flex-shrink: 0;
        }

        .front-photo i {
            font-size: 45px;
            color: #999;
        }

        .front-info {
            flex: 1;
            padding-top: 0;
        }

        .front-field {
            margin-bottom: 12px;
        }

        .front-label {
            font-weight: bold;
            color: #000;
            font-size: 14px;
            display: block;
        }

        .front-value {
            color: #333;
            border-bottom: 2px solid #000;
            margin-top: 2px;
            padding-bottom: 4px;
            min-height: 20px;
            font-size: 15px;
            text-transform: uppercase;
        }

        .front-signature {
            text-align: center;
            padding: 40px 15px 15px;
        }

        .signature-line {
            border-top: 1px solid #000;
            width: 180px;
            margin: 0 auto 8px;
        }

        .signature-text {
            font-size: 12px;
            text-transform: uppercase;
            color: #000;
            font-weight: bold;
        }

        .front-footer {
            text-align: center;
            padding: 30px 15px 10px;
            margin-top: auto;
        }

        .footer-line {
            border-top: 1px solid #000;
            width: 140px;
            margin: 0 auto 5px;
        }

        .official-name {
            font-size: 10px;
            font-weight: bold;
            color: #000;
        }

        .official-title {
            font-size: 7px;
            color: #333;
            line-height: 1.3;
        }

        /* Back QR Card Styles */
        .id-card-back-preview {
            background: white;
            border-radius: 0;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 300px;
            height: 480px;
            margin: 0 auto;
            border: 2px solid #000;
            display: flex;
            flex-direction: column;
        }

        .back-header {
            background: #1a5c1a;
            color: white;
            text-align: center;
            padding: 12px;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 4px;
        }

        .back-body {
            padding: 25px;
            text-align: center;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .back-qr {
            width: 200px;
            height: 200px;
            margin: 0 auto 20px;
            border: 5px solid #c9a227;
            padding: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }

        .back-qr img {
            max-width: 100%;
            max-height: 100%;
        }

        .back-name {
            font-size: 18px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .back-details {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .back-role {
            font-size: 13px;
            color: #1a5c1a;
            font-weight: bold;
            text-transform: uppercase;
        }

        .back-footer {
            background: #f5f5f5;
            padding: 12px;
            font-size: 10px;
            color: #666;
            text-align: center;
            font-style: italic;
            border-top: 2px solid #ccc;
            margin-top: auto;
        }
            text-align: center;
            font-style: italic;
        }

        .id-card-preview {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            display: inline-block;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            margin-bottom: 20px;
        }

        .id-card-header {
            background: #22c55e;
            color: white;
            padding: 12px 20px;
            font-weight: 700;
            font-size: 0.9rem;
            letter-spacing: 1px;
        }

        .id-card-body {
            padding: 15px 20px 20px;
        }

        .id-card-qr {
            background: white;
            padding: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #qrcode {
            width: 180px;
            height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #qrcode img {
            max-width: 100%;
        }

        .id-card-info {
            padding-top: 15px;
            text-align: center;
        }

        .id-card-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 5px;
        }

        .id-card-details {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 5px;
        }

        .id-card-role {
            font-size: 0.9rem;
            font-weight: 600;
            color: #22c55e;
        }

        .id-card-footer {
            background: #22c55e;
            color: white;
            padding: 8px 20px;
            font-size: 0.7rem;
            text-align: center;
        }

        .qr-placeholder-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            display: inline-block;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
        }

        .qr-placeholder-card .placeholder-header {
            background: #ccc;
            padding: 12px 20px;
            color: #888;
            font-size: 0.9rem;
        }

        .qr-placeholder-card .placeholder-body {
            padding: 30px 40px;
            text-align: center;
        }

        .qr-placeholder-card .placeholder-body i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 15px;
        }

        .qr-placeholder-card .placeholder-body p {
            color: #999;
            font-size: 0.85rem;
        }

        .qr-placeholder-card .placeholder-footer {
            background: #ccc;
            padding: 8px 20px;
        }

        .qr-data-preview {
            text-align: left;
            background: var(--bg);
            border-radius: 10px;
            padding: 15px;
            font-size: 0.85rem;
            margin-top: 20px;
        }

        .qr-data-preview .label {
            color: var(--text-muted);
            font-size: 0.75rem;
            margin-bottom: 3px;
        }

        .qr-data-preview .value {
            color: var(--text);
            margin-bottom: 10px;
        }

        .qr-data-preview .value:last-child {
            margin-bottom: 0;
        }

        .btn-download {
            margin-top: 15px;
            background: var(--success);
        }

        .btn-download:hover {
            background: #16a34a;
        }

        /* Alerts */
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

        @media (max-width: 1100px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            .qr-preview-card {
                position: static;
            }
        }

        @media (max-width: 900px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<?php
$success = '';
$error = '';
$registered_user = null;

// Define sports, roles, and levels
$sports = ['Basketball', 'Volleyball', 'Football', 'Swimming', 'Tennis', 'Badminton', 'Table Tennis', 'Athletics', 'Boxing', 'Taekwondo', 'Other'];
$roles = ['Athlete', 'Coach', 'Assistant Coach', 'Manager', 'Staff', 'Trainer', 'Medical Staff', 'Other'];
$levels = [
    'Elementary Boys',
    'Elementary Girls',
    'Secondary Boys',
    'Secondary Girls'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $level = sanitize($_POST['level'] ?? '');
    $role = sanitize($_POST['role'] ?? '');
    $sport = sanitize($_POST['sport'] ?? '');
    $coach = sanitize($_POST['coach'] ?? '');
    $assistant_coach = sanitize($_POST['assistant_coach'] ?? '');
    $chaperon = sanitize($_POST['chaperon'] ?? '');

    if (empty($name) || empty($role)) {
        $error = 'Please fill in Name and Category fields.';
    } else {
        // Generate unique QR code data
        $qr_data = json_encode([
            'name' => $name,
            'level' => $level,
            'role' => $role,
            'sport' => $sport,
            'coach' => $coach,
            'assistant_coach' => $assistant_coach,
            'chaperon' => $chaperon
        ]);
        
        // Insert into database
        $stmt = $conn->prepare("INSERT INTO users (name, level, role, sport, coach, assistant_coach, chaperon, qr_code, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param("ssssssss", $name, $level, $role, $sport, $coach, $assistant_coach, $chaperon, $qr_data);
        
        if ($stmt->execute()) {
            $user_id = $conn->insert_id;

            // Generate a display_id matching Python file-style timestamp + zero-padded user id
            // Example: QR_2026-01-22_14-30-05_000123
            $display_id = 'QR_' . date('Y-m-d_H-i-s') . '_' . str_pad($user_id, 6, '0', STR_PAD_LEFT);

            // Update QR data with user ID and display_id
            $qr_data = json_encode([
                'id' => $user_id,
                'display_id' => $display_id,
                'name' => $name,
                'level' => $level,
                'role' => $role,
                'sport' => $sport,
                'coach' => $coach,
                'assistant_coach' => $assistant_coach,
                'chaperon' => $chaperon
            ]);

            // Update the QR code with the ID and display_id included
            $update_stmt = $conn->prepare("UPDATE users SET qr_code = ? WHERE id = ?");
            $update_stmt->bind_param("si", $qr_data, $user_id);
            $update_stmt->execute();

            $success = 'User registered successfully!';
            $registered_user = [
                'id' => $user_id,
                'display_id' => $display_id,
                'name' => $name,
                'level' => $level,
                'role' => $role,
                'sport' => $sport,
                'coach' => $coach,
                'assistant_coach' => $assistant_coach,
                'chaperon' => $chaperon,
                'qr_data' => $qr_data
            ];
        } else {
            $error = 'Failed to register user. Please try again.';
        }
    }
}
?>

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
                    <i class="fas fa-home"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="register_user.php" class="nav-link active">
                    <i class="fas fa-user-plus"></i>
                    Register User
                </a>
            </li>
            <li class="nav-item">
                <a href="bulk_import.php" class="nav-link">
                    <i class="fas fa-file-import"></i>
                    Bulk Import
                </a>
            </li>
            <li class="nav-item">
                <a href="users.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    Manage Users
                </a>
            </li>
            <li class="nav-item">
                <a href="print_qr.php" class="nav-link">
                    <i class="fas fa-print"></i>
                    Bulk ID & QR
                </a>
            </li>
            <li class="nav-item">
                <a href="attendance.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i>
                    Attendance Records
                </a>
            </li>
            <li class="nav-item">
                <a href="settings.php" class="nav-link">
                    <i class="fas fa-clock"></i>
                    Time Settings
                </a>
            </li>
            <li class="nav-item">
                <a href="../Qrscanattendance.php" class="nav-link" target="_blank">
                    <i class="fas fa-qrcode"></i>
                    QR Scanner
                </a>
            </li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="header">
            <h1>Register New User</h1>
            <p>Add a new member to the attendance system</p>
        </div>

        <div style="display: flex; gap: 10px; margin-bottom: 25px;">
            <a href="register_user.php" style="padding: 12px 25px; background: var(--primary); color: white; border-radius: 10px; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fas fa-user-plus"></i> Single Registration
            </a>
            <a href="bulk_import.php" style="padding: 12px 25px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text-muted); border-radius: 10px; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fas fa-file-import"></i> Bulk Import
            </a>
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

        <div class="content-grid">
            <!-- Registration Form -->
            <div class="form-card">
                <h2 class="form-card-title">
                    <i class="fas fa-user-plus" style="color: var(--primary)"></i>
                    User Information
                </h2>

                <form method="POST" action="" id="registerForm">
                    <div class="form-group">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" class="form-control" 
                               placeholder="Enter full name"
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="level">Level</label>
                            <select id="level" name="level" class="form-control">
                                <option value="">Select level</option>
                                <?php foreach ($levels as $l): ?>
                                    <option value="<?= $l ?>" <?= (($_POST['level'] ?? '') === $l) ? 'selected' : '' ?>>
                                        <?= $l ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="role">Category *</label>
                            <input type="text" id="role" name="role" class="form-control" 
                                   placeholder="Enter category (e.g., Athlete, Coach)"
                                   list="role-list"
                                   value="<?= htmlspecialchars($_POST['role'] ?? '') ?>">
                            <datalist id="role-list">
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group">
                            <label for="sport">Event</label>
                            <input type="text" id="sport" name="sport" class="form-control" 
                                   placeholder="Enter event (e.g., Basketball)"
                                   list="sport-list"
                                   value="<?= htmlspecialchars($_POST['sport'] ?? '') ?>">
                            <datalist id="sport-list">
                                <?php foreach ($sports as $s): ?>
                                    <option value="<?= $s ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="coach">Coach</label>
                            <input type="text" id="coach" name="coach" class="form-control" 
                                   placeholder="Enter coach name"
                                   value="<?= htmlspecialchars($_POST['coach'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="assistant_coach">Assistant Coach</label>
                            <input type="text" id="assistant_coach" name="assistant_coach" class="form-control" 
                                   placeholder="Enter assistant coach name"
                                   value="<?= htmlspecialchars($_POST['assistant_coach'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="chaperon">Chaperon</label>
                            <input type="text" id="chaperon" name="chaperon" class="form-control" 
                                   placeholder="Enter chaperon name"
                                   value="<?= htmlspecialchars($_POST['chaperon'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <!-- Empty for layout balance -->
                        </div>
                    </div>

                    <button type="submit" class="btn btn-block">
                        <i class="fas fa-save"></i>
                        Register User & Generate QR
                    </button>
                </form>
            </div>

            <!-- ID and QR Code Preview -->
            <div class="qr-preview-card">
                <h3 class="qr-preview-title">ID and QR Code Preview</h3>
                
                <?php if ($registered_user): ?>
                <!-- Front ID Card -->
                <div class="card-label">FRONT</div>
                <div class="id-card-front-preview">
                    <div class="front-header">
                        <div class="front-header-content">
                            <div class="front-republic">Republic of the Philippines</div>
                            <div class="front-deped">Department of Education</div>
                            <div class="front-region">Negros Island Region</div>
                            <div class="front-division">SCHOOLS DIVISION OF SIPALAY CITY</div>
                        </div>
                        <div class="front-logo">
                            <img src="../assets/images/deped-logo.png" alt="DepEd Logo" onerror="this.style.display='none'">
                        </div>
                    </div>
                    <div class="front-title">ATHLETES</div>
                    <div class="front-body">
                        <div class="front-photo">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="front-info">
                            <div class="front-field">
                                <span class="front-label">NAME:</span>
                                <div class="front-value"><?= htmlspecialchars($registered_user['name']) ?></div>
                            </div>
                            <div class="front-field">
                                <span class="front-label">EVENT:</span>
                                <div class="front-value"><?= htmlspecialchars($registered_user['sport']) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="front-signature">
                        <div class="signature-line"></div>
                        <div class="signature-text">ATHLETE SIGNATURE</div>
                    </div>
                    <div class="front-footer">
                        <div class="footer-line"></div>
                        <div class="official-name">BERNIE L. LIBO-ON, PhD</div>
                        <div class="official-title">OIC - SCHOOLS DIVISION<br>SUPERINTENDENT</div>
                    </div>
                </div>

                <!-- Back QR Card -->
                <div class="card-label" style="margin-top: 20px;">BACK</div>
                <div class="id-card-back-preview">
                    <div class="back-header">QR CODE</div>
                    <div class="back-body">
                        <div class="back-qr" id="qrcode"></div>
                        <div class="back-name"><?= htmlspecialchars($registered_user['name']) ?></div>
                        <div class="back-details"><?= htmlspecialchars($registered_user['level']) ?></div>
                    </div>
                    <div class="back-footer">Scan QR Code for Attendance</div>
                </div>

                <button class="btn btn-download btn-block" onclick="downloadIDCard()">
                    <i class="fas fa-download"></i>
                    Download ID Card
                </button>
                <?php else: ?>
                <!-- Placeholder Card -->
                <div class="card-label">FRONT</div>
                <div class="qr-placeholder-card">
                    <div class="placeholder-header">ID CARD PREVIEW</div>
                    <div class="placeholder-body">
                        <i class="fas fa-id-card"></i>
                        <p>Fill the form to<br>generate ID card</p>
                    </div>
                </div>
                <div class="card-label" style="margin-top: 15px;">BACK</div>
                <div class="qr-placeholder-card">
                    <div class="placeholder-header">QR CODE</div>
                    <div class="placeholder-body">
                        <i class="fas fa-qrcode"></i>
                        <p>QR code will<br>appear here</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        <?php if ($registered_user): ?>
        // Generate QR Code
        const qrData = <?= $registered_user['qr_data'] ?>;
        const qrDataString = JSON.stringify(qrData);
        
        const qr = qrcode(0, 'M');
        qr.addData(qrDataString);
        qr.make();
        
        document.getElementById('qrcode').innerHTML = qr.createImgTag(5, 4);
        
        // Download ID Card (Front and Back)
        function downloadIDCard() {
            const img = document.querySelector('#qrcode img');
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // Two cards side by side - matching preview size
            const cardWidth = 300;
            const cardHeight = 480;
            const gap = 20;
            
            canvas.width = cardWidth * 2 + gap;
            canvas.height = cardHeight;
            
            // === FRONT CARD ===
            let x = 0;
            
            // White background with border
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(x, 0, cardWidth, cardHeight);
            ctx.strokeStyle = '#000';
            ctx.lineWidth = 2;
            ctx.strokeRect(x, 0, cardWidth, cardHeight);
            
            // Header section - left text, right logo
            ctx.textAlign = 'left';
            ctx.fillStyle = '#000';
            ctx.font = 'italic 8px Old English Text MT, Times New Roman';
            ctx.fillText('Republic of the Philippines', x + 15, 20);

            ctx.font = 'bold 14px Old English Text MT, Times New Roman';
            ctx.fillText('Department of Education', x + 15, 38);

            ctx.font = 'italic 7px Cambria Math, Cambria';
            ctx.fillText('Negros Island Region', x + 15, 50);

            ctx.font = 'bold 8px Tahoma, Arial';
            ctx.fillText('SCHOOLS DIVISION OF SIPALAY CITY', x + 15, 62);

            // Logo (right side)
            const logoImg = new window.Image();
            logoImg.crossOrigin = 'anonymous';
            logoImg.src = '../assets/images/deped-logo.png';
            
            const drawRestOfCard = function() {
                // Header bottom border
                ctx.strokeStyle = '#ddd';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(x, 75);
                ctx.lineTo(x + cardWidth, 75);
                ctx.stroke();
                
                // Green title bar - ATHLETES
                ctx.fillStyle = '#1a5c1a';
                ctx.fillRect(x, 75, cardWidth, 40);
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 20px Arial';
                ctx.textAlign = 'center';
                ctx.letterSpacing = '4px';
                ctx.fillText('A T H L E T E S', x + cardWidth/2, 102);
                
                // Photo placeholder with gold border
                ctx.fillStyle = '#f5f5f5';
                ctx.fillRect(x + 20, 130, 90, 110);
                ctx.strokeStyle = '#c9a227';
                ctx.lineWidth = 4;
                ctx.strokeRect(x + 20, 130, 90, 110);
                
                // Photo icon
                ctx.fillStyle = '#999';
                ctx.font = '50px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('👤', x + 65, 200);
                
                // Info labels and values - right side
                ctx.textAlign = 'left';
                ctx.fillStyle = '#000';
                ctx.font = 'bold 14px Arial';
                ctx.fillText('NAME:', x + 125, 155);
                
                ctx.font = '15px Arial';
                ctx.fillStyle = '#333';
                const userName = '<?= addslashes(strtoupper($registered_user['name'])) ?>';
                ctx.fillText(userName, x + 125, 175);
                
                // Line under name
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(x + 125, 180);
                ctx.lineTo(x + cardWidth - 15, 180);
                ctx.stroke();
                
                ctx.fillStyle = '#000';
                ctx.font = 'bold 14px Arial';
                ctx.fillText('EVENT:', x + 125, 210);
                
                ctx.font = '15px Arial';
                ctx.fillStyle = '#333';
                ctx.fillText('<?= addslashes(strtoupper($registered_user['sport'])) ?>', x + 125, 230);
                
                // Line under event
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(x + 125, 235);
                ctx.lineTo(x + cardWidth - 15, 235);
                ctx.stroke();
                
                // Signature area
                ctx.textAlign = 'center';
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(x + 60, 320);
                ctx.lineTo(x + cardWidth - 60, 320);
                ctx.stroke();
                
                ctx.fillStyle = '#000';
                ctx.font = 'bold 12px Arial';
                ctx.fillText('ATHLETE SIGNATURE', x + cardWidth/2, 340);
                
                // Footer - Official
                ctx.strokeStyle = '#000';
                ctx.beginPath();
                ctx.moveTo(x + 80, 420);
                ctx.lineTo(x + cardWidth - 80, 420);
                ctx.stroke();
                
                ctx.fillStyle = '#000';
                ctx.font = 'bold 10px Arial';
                ctx.fillText('BERNIE L. LIBO-ON, PhD', x + cardWidth/2, 438);
                
                ctx.font = '7px Arial';
                ctx.fillStyle = '#333';
                ctx.fillText('OIC - SCHOOLS DIVISION', x + cardWidth/2, 452);
                ctx.fillText('SUPERINTENDENT', x + cardWidth/2, 462);
                
                // === BACK CARD ===
                x = cardWidth + gap;
                
                // White background with border
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(x, 0, cardWidth, cardHeight);
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 2;
                ctx.strokeRect(x, 0, cardWidth, cardHeight);
                
                // Green header
                ctx.fillStyle = '#1a5c1a';
                ctx.fillRect(x, 0, cardWidth, 45);
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 20px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('Q R   C O D E', x + cardWidth/2, 30);
                
                // QR Code border
                ctx.strokeStyle = '#c9a227';
                ctx.lineWidth = 5;
                ctx.strokeRect(x + 50, 60, 200, 200);
                
                // Draw QR code
                if (img) {
                    ctx.drawImage(img, x + 55, 65, 190, 190);
                }
                
                // Name
                ctx.fillStyle = '#000';
                ctx.font = 'bold 18px Arial';
                ctx.fillText('<?= addslashes(strtoupper($registered_user['name'])) ?>', x + cardWidth/2, 295);
                
                // Details
                ctx.fillStyle = '#666';
                ctx.font = '14px Arial';
                ctx.fillText('<?= addslashes($registered_user['level']) ?>', x + cardWidth/2, 320);
                
                // Footer background
                ctx.fillStyle = '#f5f5f5';
                ctx.fillRect(x, cardHeight - 35, cardWidth, 35);
                ctx.strokeStyle = '#ccc';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(x, cardHeight - 35);
                ctx.lineTo(x + cardWidth, cardHeight - 35);
                ctx.stroke();
                
                // Footer text
                ctx.fillStyle = '#666';
                ctx.font = 'italic 10px Arial';
                ctx.fillText('Scan QR Code for Attendance', x + cardWidth/2, cardHeight - 12);
                
                // Download
                const link = document.createElement('a');
                link.download = '<?= preg_replace("/[^a-zA-Z0-9]/", "_", $registered_user['name']) ?>_ID_Card.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            };
            
            logoImg.onload = function() {
                // Draw logo
                const logoSize = 55;
                const logoX = x + cardWidth - logoSize - 10;
                const logoY = 8;
                ctx.drawImage(logoImg, logoX, logoY, logoSize, logoSize);
                drawRestOfCard();
            };
            
            logoImg.onerror = function() {
                drawRestOfCard();
            };
            
            // Fallback if logo doesn't trigger events
            setTimeout(function() {
                if (!logoImg.complete) {
                    drawRestOfCard();
                }
            }, 500);
        }
        <?php endif; ?>

        // Live QR Preview (optional - generates as user types)
        let previewTimeout;
        const formInputs = document.querySelectorAll('#registerForm input, #registerForm select');
        
        formInputs.forEach(input => {
            input.addEventListener('input', () => {
                clearTimeout(previewTimeout);
                previewTimeout = setTimeout(updatePreview, 300);
            });
        });

        function updatePreview() {
            const name = document.getElementById('name').value.trim();
            const level = document.getElementById('level').value;
            const role = document.getElementById('role').value;
            const sport = document.getElementById('sport').value;

            const previewCard = document.querySelector('.qr-preview-card');
            
            if (name && level && role && sport) {
                // Generate QR code
                const qrData = JSON.stringify({ name, level, role, sport });
                const qr = qrcode(0, 'M');
                qr.addData(qrData);
                qr.make();
                
                // Create ID card preview HTML with FRONT and BACK
                previewCard.innerHTML = `
                    <h3 class="qr-preview-title">ID and QR Code Preview</h3>
                    
                    <!-- Front ID Card -->
                    <div class="card-label">FRONT</div>
                    <div class="id-card-front-preview">
                        <div class="front-header">
                            <div class="front-header-content">
                                <div class="front-republic">Republic of the Philippines</div>
                                <div class="front-deped">Department of Education</div>
                                <div class="front-region">Negros Island Region</div>
                                <div class="front-division">SCHOOLS DIVISION OF SIPALAY CITY</div>
                            </div>
                            <div class="front-logo">
                                <img src="../assets/images/deped-logo.png" alt="DepEd Logo" onerror="this.style.display='none'">
                            </div>
                        </div>
                        <div class="front-title">ATHLETES</div>
                        <div class="front-body">
                            <div class="front-photo">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="front-info">
                                <div class="front-field">
                                    <span class="front-label">NAME:</span>
                                    <div class="front-value">${escapeHtml(name)}</div>
                                </div>
                                <div class="front-field">
                                    <span class="front-label">EVENT:</span>
                                    <div class="front-value">${escapeHtml(sport)}</div>
                                </div>
                            </div>
                        </div>
                        <div class="front-signature">
                            <div class="signature-line"></div>
                            <div class="signature-text">ATHLETE SIGNATURE</div>
                        </div>
                        <div class="front-footer">
                            <div class="footer-line"></div>
                            <div class="official-name">BERNIE L. LIBO-ON, PhD</div>
                            <div class="official-title">OIC - SCHOOLS DIVISION<br>SUPERINTENDENT</div>
                        </div>
                    </div>

                    <!-- Back QR Card -->
                    <div class="card-label" style="margin-top: 20px;">BACK</div>
                    <div class="id-card-back-preview">
                        <div class="back-header">QR CODE</div>
                        <div class="back-body">
                            <div class="back-qr" id="preview-qrcode">${qr.createImgTag(3, 4)}</div>
                            <div class="back-name">${escapeHtml(name)}</div>
                            <div class="back-details">${escapeHtml(level)}</div>
                            <div class="back-role">${escapeHtml(role)} - ${escapeHtml(sport)}</div>
                        </div>
                        <div class="back-footer">Scan QR Code for Attendance</div>
                    </div>
                    
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 15px; text-align: center;">
                        <i class="fas fa-info-circle"></i> Submit the form to download ID card
                    </p>
                `;
            } else {
                // Show placeholder for both cards
                previewCard.innerHTML = `
                    <h3 class="qr-preview-title">ID and QR Code Preview</h3>
                    <div class="card-label">FRONT</div>
                    <div class="qr-placeholder-card">
                        <div class="placeholder-header">ID CARD PREVIEW</div>
                        <div class="placeholder-body">
                            <i class="fas fa-id-card"></i>
                            <p>Fill the form to<br>generate ID card</p>
                        </div>
                    </div>
                    <div class="card-label" style="margin-top: 15px;">BACK</div>
                    <div class="qr-placeholder-card">
                        <div class="placeholder-header">QR CODE</div>
                        <div class="placeholder-body">
                            <i class="fas fa-qrcode"></i>
                            <p>QR code will<br>appear here</p>
                        </div>
                    </div>
                `;
            }
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>

</body>
</html>
