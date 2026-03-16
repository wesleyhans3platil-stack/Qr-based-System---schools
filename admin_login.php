<?php
// database.php handles session_start() with DB backend automatically
require_once 'config/database.php';

$conn = getDBConnection();
$error = '';

// Fetch Google Client ID from settings
$g_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='google_client_id'");
$google_client_id = $g_res ? ($g_res->fetch_assoc()['setting_value'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, full_name, role, school_id, temp_password FROM admins WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($admin = $result->fetch_assoc()) {
            if (password_verify($password, $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['admin_school_id'] = $admin['school_id'];

                $conn->query("UPDATE admins SET last_login = NOW() WHERE id = " . $admin['id']);

                // Force password change if temporary
                if (!empty($admin['temp_password'])) {
                    $_SESSION['force_password_change'] = true;
                    header('Location: change_password.php');
                    exit;
                }

                // Route to role-specific dashboard
                switch ($admin['role']) {
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
                $error = 'Invalid password.';
            }
        } else {
            $error = 'Account not found.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — EduTrack | SDO-Sipalay City Attendance Monitoring System</title>
    <?php $__fl=$conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'")->fetch_assoc(); if(!empty($__fl['setting_value'])&&file_exists('assets/uploads/logos/'.$__fl['setting_value'])):?><link rel="icon" type="image/png" href="assets/uploads/logos/<?=htmlspecialchars($__fl['setting_value'])?>"><?php endif;?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background-color: #f1f5f9;
            /* Subtle Grid Texture */
            background-image: 
                linear-gradient(rgba(203, 213, 225, 0.45) 1px, transparent 1px),
                linear-gradient(90deg, rgba(203, 213, 225, 0.45) 1px, transparent 1px);
            background-size: 32px 32px;
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
        .login-container {
            width: 420px;
            max-width: 95%;
            position: relative;
            z-index: 1;
        }
        .login-brand {
            text-align: center;
            margin-bottom: 36px;
        }
        .login-brand .icon {
            width: 100px; height: 100px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .login-brand .icon i { font-size: 2.5rem; color: #4338ca; }
        .login-brand .icon img { width: 100%; height: 100%; object-fit: contain; border-radius: 18px; }
        .login-brand h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: -0.02em;
        }
        .login-brand p {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 4px;
        }
        .login-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 48px 36px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
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
        .input-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }
        .input-wrapper input {
            width: 100%;
            padding: 13px 14px 13px 42px;
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
        .btn-login {
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
        }
        .btn-login:hover {
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
        .login-footer {
            text-align: center;
            margin-top: 24px;
            color: #94a3b8;
            font-size: 0.75rem;
        }
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 22px 0 18px;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        .divider span {
            font-size: 0.78rem;
            color: #94a3b8;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .google-btn-wrapper {
            display: flex;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-brand">
            <div class="icon"><?php if(!empty($__fl['setting_value'])&&file_exists('assets/uploads/logos/'.$__fl['setting_value'])): ?><img src="assets/uploads/logos/<?=htmlspecialchars($__fl['setting_value'])?>" alt="Logo"><?php else: ?><i class="fas fa-qrcode"></i><?php endif; ?></div>
            <h1>SDO-Sipalay City Attendance Monitoring System</h1>
            <p>Schools Division Office — Sipalay City</p>
        </div>

        <div class="login-card">
            <?php if ($error): ?>
                <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label>Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="username" placeholder="Enter your username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="Enter your password" required>
                    </div>
                </div>
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <?php if (!empty($google_client_id)): ?>
            <div class="divider"><span>or</span></div>
            <div id="google-signin-btn" class="google-btn-wrapper">
                <div id="g_id_onload"
                     data-client_id="<?= htmlspecialchars($google_client_id) ?>"
                     data-callback="handleGoogleLogin"
                     data-auto_prompt="false">
                </div>
                <div class="g_id_signin"
                     data-type="standard"
                     data-shape="rectangular"
                     data-theme="outline"
                     data-text="signin_with"
                     data-size="large"
                     data-logo_alignment="left"
                     data-width="348">
                </div>
            </div>
            <div id="google-error" class="error-msg" style="display:none;margin-top:16px;"><i class="fas fa-exclamation-circle"></i> <span></span></div>
            <?php endif; ?>
        </div>

        <div class="login-footer">
            &copy; <?= date('Y') ?> SDO-Sipalay City &mdash; EduTrack Attendance Monitoring System
        </div>
    </div>
<?php if (!empty($google_client_id)): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
    function handleGoogleLogin(response) {
        var errBox = document.getElementById('google-error');
        errBox.style.display = 'none';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/google_login.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    errBox.querySelector('span').textContent = data.message || 'Google Sign-In failed.';
                    errBox.style.display = 'flex';
                }
            } catch(e) {
                errBox.querySelector('span').textContent = 'Unexpected error. Please try again.';
                errBox.style.display = 'flex';
            }
        };
        xhr.onerror = function() {
            errBox.querySelector('span').textContent = 'Network error. Please try again.';
            errBox.style.display = 'flex';
        };
        xhr.send('credential=' + encodeURIComponent(response.credential));
    }
    </script>
<?php endif; ?>
</body>
</html>
