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
        background: #fffffe;
        border-top: 1px solid rgba(0,0,0,0.06);
        padding: 0 4px calc(0px + env(safe-area-inset-bottom, 0px));
        height: calc(72px + env(safe-area-inset-bottom, 0px));
        display: flex; justify-content: space-around; align-items: stretch;
        font-family: 'Inter', -apple-system, sans-serif;
    }
    .app-bottom-nav a {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 2px; font-size: 0.55rem; font-weight: 600; color: #49454f;
        text-decoration: none; padding: 0 8px; position: relative; flex: 1;
    }
    .app-bottom-nav a i { font-size: 1.05rem; z-index: 1; transition: transform 0.15s; }
    .app-bottom-nav a span { z-index: 1; }
    .app-bottom-nav a .pill {
        position: absolute; top: 10px; width: 48px; height: 24px;
        border-radius: 12px; background: #c4eed0; opacity: 0; transition: opacity 0.15s;
    }
    .app-bottom-nav a.active { color: #022c22; }
    .app-bottom-nav a.active .pill { opacity: 1; }
    .app-bottom-nav a:active i { transform: scale(0.85); }
    body { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)) !important; }
    .sidebar, .sidebar-overlay, .mobile-menu-toggle { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 18px 14px !important; }
    @media (min-width: 1025px) {
        .app-bottom-nav { display: none !important; }
        body { padding-bottom: 0 !important; }
        .sidebar { display: flex !important; }
        .main-content { margin-left: var(--sidebar-width) !important; padding: 32px 36px !important; }
    }
</style>
<nav class="app-bottom-nav">
    <a href="<?= htmlspecialchars($dashboardHref) ?>" class="<?= $current === 'dashboard' || $current === 'app_dashboard' || $current === 'sds_dashboard' || $current === 'asds_dashboard' || $current === 'principal_dashboard' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="/admin/attendance.php" class="<?= $current === 'attendance' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-clipboard-check"></i><span>Attendance</span></a>
    <a href="/admin/school_browser.php" class="<?= $current === 'school_browser' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-building-columns"></i><span>Schools</span></a>
    <a href="/admin/reports.php" class="<?= $current === 'reports' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-file-lines"></i><span>Reports</span></a>
    <a href="/Qrscanattendance.php" class="<?= $current === 'Qrscanattendance' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-qrcode"></i><span>Scanner</span></a>
</nav>
<?php endif; ?>
