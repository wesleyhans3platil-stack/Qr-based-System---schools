<?php
/**
 * Mobile Bottom Navigation — shown in Android app WebView
 * Detects app via user agent and injects a fixed bottom nav bar.
 */
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isApp = (stripos($ua, 'QRAttendanceApp') !== false) || (stripos($ua, 'wv') !== false && stripos($ua, 'Android') !== false);
$isMobileDevice = (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile/i', $ua);

$adminRole = $_SESSION['admin_role'] ?? '';
$current = $current_page ?? basename($_SERVER['PHP_SELF'], '.php');

$dashboardHref = '/app_dashboard.php';
if ($adminRole === 'superintendent') {
    $dashboardHref = '/admin/sds_dashboard.php';
} elseif ($adminRole === 'asst_superintendent') {
    $dashboardHref = '/admin/asds_dashboard.php';
} elseif ($adminRole === 'principal') {
    $dashboardHref = '/admin/principal_dashboard.php';
} elseif ($adminRole === 'super_admin') {
    $dashboardHref = '/admin/dashboard.php';
}

if ($isApp || $isMobileDevice):
?>
<style>
    .app-bottom-nav {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 9999;
        background: rgba(255,255,255,0.96);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        border-top: 1px solid rgba(226,232,240,0.95);
        padding: 8px 12px calc(8px + env(safe-area-inset-bottom, 0px));
        display: flex; justify-content: space-around; align-items: center;
        font-family: 'Inter', -apple-system, sans-serif;
        box-shadow: 0 -8px 28px rgba(15,23,42,0.08);
    }
    .app-bottom-nav a {
        display: flex; flex-direction: column; align-items: center; gap: 5px;
        font-size: 0.72rem; font-weight: 700; color: #475569; text-decoration: none;
        padding: 10px 12px; border-radius: 18px; transition: all 0.25s ease;
        position: relative;
        min-width: 64px;
    }
    .app-bottom-nav a.active {
        color: #0f172a;
        background: #d8f3df;
    }
    .app-bottom-nav a i { font-size: 1.4rem; transition: transform 0.2s; }
    .app-bottom-nav a:active i { transform: scale(0.9); }
    body { padding-bottom: 94px !important; }
    .sidebar, .sidebar-overlay, .mobile-menu-toggle { display: none !important; }
    .main-content { margin-left: 0 !important; }
    @media (min-width: 1025px) {
        .app-bottom-nav { display: none; }
        body { padding-bottom: 0 !important; }
    }
</style>
<nav class="app-bottom-nav">
    <a href="<?= htmlspecialchars($dashboardHref) ?>" class="<?= $current === 'dashboard' || $current === 'app_dashboard' || $current === 'sds_dashboard' || $current === 'asds_dashboard' || $current === 'principal_dashboard' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> Dashboard</a>
    <a href="/admin/attendance.php" class="<?= $current === 'attendance' ? 'active' : '' ?>"><i class="fas fa-clipboard-check"></i> Attendance</a>
    <a href="/admin/school_browser.php" class="<?= $current === 'school_browser' ? 'active' : '' ?>"><i class="fas fa-building-columns"></i> Schools</a>
    <a href="/admin/reports.php" class="<?= $current === 'reports' ? 'active' : '' ?>"><i class="fas fa-file-lines"></i> Reports</a>
    <a href="/Qrscanattendance.php" class="<?= $current === 'Qrscanattendance' ? 'active' : '' ?>"><i class="fas fa-qrcode"></i> Scanner</a>
</nav>
<?php endif; ?>
