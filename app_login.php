<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * MOBILE LOGIN — PWA App Login Page
 * ══════════════════════════════════════════════════════════════════
 * Simple, mobile-optimized login that redirects to app_dashboard.php
 */
require_once 'config/database.php';

// Already logged in? Go to dashboard
if (isset($_SESSION['admin_id'])) {
    header('Location: app_dashboard.php');
    exit;
}

$conn = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, full_name, role, school_id FROM admins WHERE username = ?");
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

                // Android app: return JSON with name so it can show a welcome notification
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                if (stripos($ua, 'QRAttendanceApp') !== false) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'full_name' => $admin['full_name']]);
                    exit;
                }

                header('Location: app_dashboard.php');
                exit;
            } else {
                $error = 'Invalid password.';
            }
        } else {
            $error = 'Account not found.';
        }
    }
}

// System logo
$systemLogo = '';
$lr = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'");
if ($lr && $lrow = $lr->fetch_assoc()) {
    $lf = $lrow['setting_value'] ?? '';
    if ($lf && file_exists(__DIR__ . '/assets/uploads/logos/' . $lf)) $systemLogo = 'assets/uploads/logos/' . $lf;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#4338ca">
    <title>Login — EduTrack | SDO-Sipalay City</title>
    <?php if ($systemLogo): ?><link rel="icon" type="image/png" href="<?= $systemLogo ?>"><?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --safe-top: env(safe-area-inset-top, 0px); --safe-bottom: env(safe-area-inset-bottom, 0px); }
        html, body {
            font-family: 'Inter', sans-serif; min-height: 100vh; background-color: #f1f5f9;
            /* Subtle Grid Texture */
            background-image: 
                linear-gradient(rgba(203, 213, 225, 0.45) 1px, transparent 1px),
                linear-gradient(90deg, rgba(203, 213, 225, 0.45) 1px, transparent 1px);
            background-size: 32px 32px;
            color: #0f172a; -webkit-tap-highlight-color: transparent;
        }
        body {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 24px 20px calc(24px + var(--safe-bottom));
            padding-top: calc(24px + var(--safe-top));
        }

        .login-card {
            width: 100%; max-width: 400px; background: #fff; border-radius: 24px;
            padding: 40px 28px 36px; box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            text-align: center;
        }
        .login-logo {
            width: 72px; height: 72px; border-radius: 20px; margin: 0 auto 20px;
            background: linear-gradient(135deg, #4338ca, #6366f1); display: flex;
            align-items: center; justify-content: center; overflow: hidden;
            box-shadow: 0 8px 24px rgba(67,56,202,0.25);
        }
        .login-logo img { width: 100%; height: 100%; object-fit: cover; }
        .login-logo i { font-size: 1.8rem; color: #fff; }
        .login-title { font-size: 1.3rem; font-weight: 900; letter-spacing: -0.03em; margin-bottom: 4px; }
        .login-sub { font-size: 0.78rem; color: #64748b; font-weight: 500; margin-bottom: 28px; }

        .input-group { position: relative; margin-bottom: 14px; text-align: left; }
        .input-group label {
            font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;
            letter-spacing: 0.5px; display: block; margin-bottom: 6px;
        }
        .input-group input {
            width: 100%; padding: 14px 16px 14px 46px; border: 2px solid #e2e8f0;
            border-radius: 14px; font-size: 0.95rem; font-family: 'Inter', sans-serif;
            font-weight: 500; color: #0f172a; outline: none; transition: border-color 0.2s;
        }
        .input-group input:focus { border-color: #6366f1; }
        .input-group .icon {
            position: absolute; left: 16px; bottom: 15px; font-size: 1rem; color: #94a3b8;
        }

        .error-msg {
            background: #fef2f2; border: 1px solid #fecaca; color: #dc2626;
            padding: 12px 16px; border-radius: 12px; font-size: 0.82rem; font-weight: 600;
            margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
        }
        .error-msg i { flex-shrink: 0; }

        .login-btn {
            width: 100%; padding: 16px; background: linear-gradient(135deg, #4338ca, #6366f1);
            color: #fff; border: none; border-radius: 14px; font-size: 1rem; font-weight: 700;
            font-family: 'Inter', sans-serif; cursor: pointer; letter-spacing: -0.01em;
            box-shadow: 0 8px 20px rgba(67,56,202,0.3); transition: transform 0.15s, box-shadow 0.15s;
        }
        .login-btn:active { transform: scale(0.98); box-shadow: 0 4px 12px rgba(67,56,202,0.25); }

        .login-footer {
            margin-top: 24px; font-size: 0.7rem; color: #94a3b8; font-weight: 500;
        }
        .login-footer a { color: #6366f1; text-decoration: none; font-weight: 600; }

        /* Install prompt */
        .install-banner {
            width: 100%; max-width: 400px; background: linear-gradient(135deg, #fffbeb, #fef3c7);
            border: 1px solid #fde68a; border-radius: 16px; padding: 16px 20px;
            margin-bottom: 20px; display: none; align-items: center; gap: 12px;
        }
        .install-banner.visible { display: flex; }
        .install-banner i { font-size: 1.4rem; color: #d97706; flex-shrink: 0; }
        .install-banner .ib-text { flex: 1; }
        .install-banner .ib-text strong { font-size: 0.82rem; color: #92400e; display: block; }
        .install-banner .ib-text span { font-size: 0.72rem; color: #a16207; }
        .install-btn {
            padding: 8px 16px; background: #d97706; color: #fff; border: none; border-radius: 10px;
            font-size: 0.75rem; font-weight: 700; cursor: pointer; white-space: nowrap;
        }
    </style>
</head>
<body>
    <!-- Install App Banner -->
    <div class="install-banner" id="installBanner">
        <i class="fas fa-mobile-screen"></i>
        <div class="ib-text">
            <strong>Install App</strong>
            <span>Add to your home screen for quick access</span>
        </div>
        <button class="install-btn" id="installBtn">Install</button>
    </div>

    <div class="login-card">
        <div class="login-logo">
            <?php if ($systemLogo): ?>
                <img src="<?= htmlspecialchars($systemLogo) ?>" alt="Logo">
            <?php else: ?>
                <i class="fas fa-chart-pie"></i>
            <?php endif; ?>
        </div>
        <div class="login-title">EduTrack</div>
        <div class="login-sub">Sign in to view the dashboard</div>

        <?php if ($error): ?>
        <div class="error-msg"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <label>Username</label>
                <i class="fas fa-user icon"></i>
                <input type="text" name="username" placeholder="Enter username" autocomplete="username" required>
            </div>
            <div class="input-group">
                <label>Password</label>
                <i class="fas fa-lock icon"></i>
                <input type="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="login-btn"><i class="fas fa-right-to-bracket"></i>&nbsp; Sign In</button>
        </form>

        <div class="login-footer">
            School Division of Sipalay City<br>
            <a href="admin_login.php">Switch to full admin panel &rarr;</a><br>
            <a href="install.php" style="color:#94a3b8;"><i class="fas fa-download" style="font-size:0.65rem;"></i> Install App on Phone</a>
        </div>
    </div>

    <script>
    // No PWA/service worker — native Android app handles everything
    </script>
</body>
</html>
