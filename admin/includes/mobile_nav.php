<?php
/**
 * Mobile Bottom Navigation — shown in Android app WebView
 * Detects app via user agent and injects a fixed bottom nav bar.
 */
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isApp = (stripos($ua, 'QRAttendanceApp') !== false) || (stripos($ua, 'wv') !== false && stripos($ua, 'Android') !== false);

if ($isApp):
    $current = basename($_SERVER['PHP_SELF'], '.php');
?>
<style>
    .app-bottom-nav {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 9999;
        background: rgba(255,255,255,0.82);
        backdrop-filter: blur(20px) saturate(180%);
        -webkit-backdrop-filter: blur(20px) saturate(180%);
        border-top: 1px solid rgba(226,232,240,0.6);
        padding: 6px 8px calc(6px + env(safe-area-inset-bottom, 0px));
        display: flex; justify-content: space-around; align-items: center;
        font-family: 'Inter', -apple-system, sans-serif;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.05);
    }
    .app-bottom-nav a {
        display: flex; flex-direction: column; align-items: center; gap: 3px;
        font-size: 0.58rem; font-weight: 700; color: #64748b; text-decoration: none;
        padding: 8px 12px; border-radius: 14px; transition: all 0.25s ease;
        position: relative;
    }
    .app-bottom-nav a.active {
        color: #4f46e5;
        background: rgba(79,70,229,0.08);
    }
    .app-bottom-nav a.active::before {
        content: '';
        position: absolute; top: -6px; left: 50%; transform: translateX(-50%);
        width: 20px; height: 3px; border-radius: 2px;
        background: linear-gradient(90deg, #4f46e5, #818cf8);
        box-shadow: 0 1px 6px rgba(79,70,229,0.3);
    }
    .app-bottom-nav a i { font-size: 1.1rem; transition: transform 0.2s; }
    .app-bottom-nav a:active i { transform: scale(0.9); }
    body { padding-bottom: 76px !important; }
    .sidebar, .sidebar-overlay { display: none !important; }
    .main-content { margin-left: 0 !important; }
</style>
<nav class="app-bottom-nav">
    <a href="/app_dashboard.php" class="<?= $current === 'app_dashboard' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> Dashboard</a>
    <a href="/admin/attendance.php" class="<?= $current === 'attendance' ? 'active' : '' ?>"><i class="fas fa-clipboard-list"></i> Attendance</a>
    <a href="/admin/school_browser.php" class="<?= $current === 'school_browser' ? 'active' : '' ?>"><i class="fas fa-school"></i> Schools</a>
    <a href="/admin/reports.php" class="<?= $current === 'reports' ? 'active' : '' ?>"><i class="fas fa-file-alt"></i> Reports</a>
    <a href="/Qrscanattendance.php" class="<?= $current === 'Qrscanattendance' ? 'active' : '' ?>"><i class="fas fa-qrcode"></i> Scanner</a>
</nav>
<?php endif; ?>
