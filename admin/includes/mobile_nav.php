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
        background: #fff; border-top: 1px solid #e2e8f0;
        padding: 6px 8px calc(6px + env(safe-area-inset-bottom, 0px));
        display: flex; justify-content: space-around; align-items: center;
        font-family: 'Inter', -apple-system, sans-serif;
    }
    .app-bottom-nav a {
        display: flex; flex-direction: column; align-items: center; gap: 2px;
        font-size: 0.58rem; font-weight: 600; color: #64748b; text-decoration: none;
        padding: 4px 8px; border-radius: 8px; transition: color 0.2s;
    }
    .app-bottom-nav a.active { color: #4338ca; }
    .app-bottom-nav a i { font-size: 1.05rem; }
    /* Add padding to page bottom so content isn't hidden behind nav */
    body { padding-bottom: 70px !important; }
    /* Hide the desktop sidebar when in app */
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
