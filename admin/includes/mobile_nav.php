<?php
/**
 * Mobile Bottom Navigation — consistent nav across all pages
 * Matches the nav-bar style from app_dashboard.php for visual unity.
 */
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isApp = (stripos($ua, 'QRAttendanceApp') !== false) || (stripos($ua, 'wv') !== false && stripos($ua, 'Android') !== false);
$isMobileDevice = (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile/i', $ua);

$current = $current_page ?? basename($_SERVER['PHP_SELF'], '.php');

// Always use app_dashboard.php on mobile — it's designed for small screens
$dashboardHref = '/app_dashboard.php';

if ($isApp || $isMobileDevice):
?>
<style>
    .app-bottom-nav {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 9999;
        background: #fff;
        border-top: 1px solid rgba(0,0,0,0.06);
        padding: 0 4px env(safe-area-inset-bottom, 0px);
        height: calc(72px + env(safe-area-inset-bottom, 0px));
        display: flex; justify-content: space-around; align-items: stretch;
        font-family: 'Inter', -apple-system, sans-serif;
    }
    .app-bottom-nav a {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 2px; font-size: 0.55rem; font-weight: 600; color: #49504a;
        text-decoration: none; padding: 0 8px; position: relative; flex: 1;
        border: none; background: none; cursor: pointer;
    }
    .app-bottom-nav a i { font-size: 1.05rem; z-index: 1; transition: transform 0.15s; }
    .app-bottom-nav a span { z-index: 1; }
    .app-bottom-nav a .pill {
        position: absolute; top: 10px; width: 48px; height: 24px;
        border-radius: 12px; background: #d1fae5; opacity: 0; transition: opacity 0.15s;
    }
    .app-bottom-nav a.active { color: #022c22; }
    .app-bottom-nav a.active .pill { opacity: 1; }
    .app-bottom-nav a:active i { transform: scale(0.85); }
    body { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)) !important; }
    .sidebar, .sidebar-overlay, .mobile-menu-toggle { display: none !important; }
    .main-content { margin-left: 0 !important; }
    @media (min-width: 1025px) {
        .app-bottom-nav { display: none; }
        body { padding-bottom: 0 !important; }
        .sidebar { display: flex !important; }
        .main-content { margin-left: var(--sidebar-width) !important; }
    }
</style>
<nav class="app-bottom-nav">
    <a href="<?= htmlspecialchars($dashboardHref) ?>" class="<?= in_array($current, ['dashboard','app_dashboard','sds_dashboard','asds_dashboard','principal_dashboard']) ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="/admin/attendance.php" class="<?= $current === 'attendance' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-clipboard-list"></i><span>Attendance</span></a>
    <a href="/admin/school_browser.php" class="<?= $current === 'school_browser' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-school"></i><span>Schools</span></a>
    <a href="/admin/reports.php" class="<?= $current === 'reports' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-file-alt"></i><span>Reports</span></a>
    <a href="/Qrscanattendance.php" class="<?= $current === 'Qrscanattendance' ? 'active' : '' ?>"><div class="pill"></div><i class="fas fa-qrcode"></i><span>Scanner</span></a>
</nav>
<?php endif; ?>
