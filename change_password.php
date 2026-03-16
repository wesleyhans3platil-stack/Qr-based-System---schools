<?php
require_once 'config/database.php';
$conn = getDBConnection();

// Must be logged in and have force_password_change flag
if (!isset($_SESSION['admin_id']) || empty($_SESSION['force_password_change'])) {
    header('Location: admin_login.php');
    exit;
}

$error = '';
$admin_name = $_SESSION['admin_name'] ?? 'User';
$admin_username = $_SESSION['admin_username'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (strlen($new_pass) < 4) {
        $error = 'Password must be at least 4 characters.';
    } elseif ($new_pass !== $confirm_pass) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE admins SET password = ?, temp_password = 0 WHERE id = ?");
        $admin_id = $_SESSION['admin_id'];
        $stmt->bind_param("si", $hashed, $admin_id);

        if ($stmt->execute()) {
            // Clear the force flag and redirect to dashboard
            unset($_SESSION['force_password_change']);

            switch ($_SESSION['admin_role']) {
                case 'superintendent':
                    header('Location: admin/sds_dashboard.php');
                    break;
                case 'asst_superintendent':
                    header('Location: admin/asds_dashboard.php');
                    break;
                case 'principal':
                    header('Location: admin/principal_dashboard.php');
                    break;
                default:
                    header('Location: admin/dashboard.php');
                    break;
            }
            exit;
        } else {
            $error = 'Failed to update password. Please try again.';
        }
    }
}

// Get system logo
$__fl = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Your Password — EduTrack | SDO-Sipalay City</title>
    <?php if(!empty($__fl['setting_value'])&&file_exists('assets/uploads/logos/'.$__fl['setting_value'])):?><link rel="icon" type="image/png" href="assets/uploads/logos/<?=htmlspecialchars($__fl['setting_value'])?>"><?php endif;?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(67,56,202,0.08) 0%, transparent 70%);
            top: -200px; right: -100px;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: absolute;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(22,163,74,0.05) 0%, transparent 70%);
            bottom: -150px; left: -100px;
            pointer-events: none;
        }
        .container {
            width: 440px;
            max-width: 95%;
            position: relative;
            z-index: 1;
        }
        .brand {
            text-align: center;
            margin-bottom: 32px;
        }
        .brand .icon {
            width: 80px; height: 80px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            background: linear-gradient(135deg, #4338ca, #3730a3);
            border-radius: 20px;
            box-shadow: 0 8px 24px rgba(67,56,202,0.25);
        }
        .brand .icon i { font-size: 2rem; color: #fff; }
        .brand h1 {
            font-size: 1.35rem;
            font-weight: 800;
            color: #1e293b;
        }
        .brand p {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 4px;
        }
        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        }
        .welcome-banner {
            background: linear-gradient(135deg, #eff6ff, #e0e7ff);
            border: 1px solid #c7d2fe;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 24px;
            text-align: center;
        }
        .welcome-banner .greeting {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        .welcome-banner .greeting span { color: #4338ca; }
        .welcome-banner .hint {
            font-size: 0.78rem;
            color: #64748b;
            line-height: 1.5;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 8px;
        }
        .input-wrapper {
            position: relative;
        }
        .input-wrapper i.input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }
        .input-wrapper input {
            width: 100%;
            padding: 13px 44px 13px 42px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            color: #1e293b;
            font-size: 0.9rem;
            font-family: inherit;
            transition: all 0.25s;
        }
        .input-wrapper input:focus {
            outline: none;
            border-color: #4338ca;
            box-shadow: 0 0 0 3px rgba(67,56,202,0.12);
            background: #fff;
        }
        .toggle-pass {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 4px;
        }
        .toggle-pass:hover { color: #4338ca; }
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #4338ca, #3730a3);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.25s;
            margin-top: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(67,56,202,0.35);
        }
        .error-msg {
            background: rgba(220,38,38,0.08);
            border: 1px solid rgba(220,38,38,0.2);
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .strength-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        .strength-bar .fill {
            height: 100%;
            width: 0;
            border-radius: 2px;
            transition: all 0.3s;
        }
        .strength-label {
            font-size: 0.72rem;
            margin-top: 4px;
            font-weight: 600;
        }
        .footer {
            text-align: center;
            margin-top: 24px;
            color: #94a3b8;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="brand">
            <div class="icon"><i class="fas fa-key"></i></div>
            <h1>Set Your Password</h1>
            <p>You're using a temporary password</p>
        </div>

        <div class="card">
            <div class="welcome-banner">
                <div class="greeting">Welcome, <span><?= htmlspecialchars($admin_name) ?></span>!</div>
                <div class="hint">Your account was created with a temporary password. Please create your own secure password to continue.</div>
            </div>

            <?php if ($error): ?>
                <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>NEW PASSWORD</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="new_password" id="newPass" placeholder="Enter your new password" required minlength="4" oninput="checkStrength(this.value)">
                        <button type="button" class="toggle-pass" onclick="togglePassword('newPass', this)"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="strength-bar"><div class="fill" id="strengthFill"></div></div>
                    <div class="strength-label" id="strengthLabel"></div>
                </div>

                <div class="form-group">
                    <label>CONFIRM PASSWORD</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="confirm_password" id="confirmPass" placeholder="Re-enter your new password" required minlength="4">
                        <button type="button" class="toggle-pass" onclick="togglePassword('confirmPass', this)"><i class="fas fa-eye"></i></button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-shield-halved"></i> Set Password & Continue
                </button>
            </form>
        </div>

        <div class="footer">
            <i class="fas fa-lock" style="margin-right:4px;"></i> Your password is securely encrypted
        </div>
    </div>

    <script>
    function togglePassword(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fas fa-eye';
        }
    }

    function checkStrength(pass) {
        const fill = document.getElementById('strengthFill');
        const label = document.getElementById('strengthLabel');
        let score = 0;
        if (pass.length >= 4) score++;
        if (pass.length >= 8) score++;
        if (/[A-Z]/.test(pass) && /[a-z]/.test(pass)) score++;
        if (/\d/.test(pass)) score++;
        if (/[^A-Za-z0-9]/.test(pass)) score++;

        const levels = [
            { width: '0%', color: '#e2e8f0', text: '', textColor: '#94a3b8' },
            { width: '20%', color: '#ef4444', text: 'Too short', textColor: '#ef4444' },
            { width: '40%', color: '#f97316', text: 'Weak', textColor: '#f97316' },
            { width: '60%', color: '#eab308', text: 'Fair', textColor: '#eab308' },
            { width: '80%', color: '#22c55e', text: 'Good', textColor: '#22c55e' },
            { width: '100%', color: '#16a34a', text: 'Strong', textColor: '#16a34a' },
        ];

        fill.style.width = levels[score].width;
        fill.style.background = levels[score].color;
        label.textContent = levels[score].text;
        label.style.color = levels[score].textColor;
    }
    </script>
</body>
</html>
