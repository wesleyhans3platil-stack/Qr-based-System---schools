<?php
// Shared sidebar for all admin pages
// Requires: $current_page variable set before including
$current_page = $current_page ?? '';
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// Force password change redirect
if (!empty($_SESSION['force_password_change'])) {
    header('Location: ../change_password.php');
    exit;
}

// Determine dashboard link based on role
$dashboard_link = 'dashboard.php';
if ($admin_role === 'superintendent') $dashboard_link = 'sds_dashboard.php';
elseif ($admin_role === 'asst_superintendent') $dashboard_link = 'asds_dashboard.php';
elseif ($admin_role === 'principal') $dashboard_link = 'principal_dashboard.php';

// Get system logo for sidebar
$__sidebarLogo = '';
$__sl_r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'");
if ($__sl_r && $__sl_row = $__sl_r->fetch_assoc()) {
    $__sl_file = $__sl_row['setting_value'] ?? '';
    if ($__sl_file && file_exists(__DIR__ . '/../../assets/uploads/logos/' . $__sl_file)) {
        $__sidebarLogo = '../assets/uploads/logos/' . $__sl_file;
    }
}
?>
<aside class="sidebar sidebar-border" id="sidebar">
    <div class="sidebar-header">
        <?php if ($__sidebarLogo): ?>
        <div class="logo" style="padding:0;overflow:hidden;background:none;box-shadow:none;"><img src="<?= $__sidebarLogo ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;border-radius:inherit;"></div>
        <?php else: ?>
        <div class="logo"><i class="fas fa-qrcode"></i></div>
        <?php endif; ?>
        <div>
            <h2>School Attendance System</h2>
            <span>School Division of Sipalay City</span>
        </div>
    </div>

    <div class="nav-section-label">Main</div>
    <ul class="nav-menu">
        <li class="nav-item">
            <a href="<?= $dashboard_link ?>" class="nav-link <?= $current_page === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a href="school_browser.php" class="nav-link <?= $current_page === 'school_browser' ? 'active' : '' ?>">
                <i class="fas fa-building-columns"></i> Schools
            </a>
        </li>

        <?php if (in_array($admin_role, ['super_admin', 'principal'])): ?>
        <div class="nav-section-label">Management</div>
        <?php if ($admin_role === 'super_admin'): ?>
        <li class="nav-item">
            <a href="schools.php" class="nav-link <?= $current_page === 'schools' ? 'active' : '' ?>">
                <i class="fas fa-school"></i> Manage Schools
            </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a href="students.php" class="nav-link <?= $current_page === 'students' ? 'active' : '' ?>">
                <i class="fas fa-user-graduate"></i> Students
            </a>
        </li>
        <li class="nav-item">
            <a href="shs_students.php" class="nav-link <?= $current_page === 'shs_students' ? 'active' : '' ?>">
                <i class="fas fa-graduation-cap"></i> SHS Students
            </a>
        </li>
        <li class="nav-item">
            <a href="teachers.php" class="nav-link <?= $current_page === 'teachers' ? 'active' : '' ?>">
                <i class="fas fa-chalkboard-teacher"></i> Teachers
            </a>
        </li>
        <li class="nav-item">
            <a href="sections.php" class="nav-link <?= $current_page === 'sections' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i> Sections
            </a>
        </li>
        <li class="nav-item">
            <a href="bulk_import.php" class="nav-link <?= $current_page === 'bulk_import' ? 'active' : '' ?>">
                <i class="fas fa-file-import"></i> Bulk Import
            </a>
        </li>
        <li class="nav-item">
            <a href="print_qr.php" class="nav-link <?= $current_page === 'print_qr' ? 'active' : '' ?>">
                <i class="fas fa-print"></i> Print QR Codes
            </a>
        </li>

        <div class="nav-section-label">Attendance</div>
        <li class="nav-item">
            <a href="attendance.php" class="nav-link <?= $current_page === 'attendance' ? 'active' : '' ?>">
                <i class="fas fa-clipboard-check"></i> Attendance
            </a>
        </li>
        <li class="nav-item">
            <a href="reports.php" class="nav-link <?= $current_page === 'reports' ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> Reports
            </a>
        </li>
        <?php endif; ?>

        <?php if (in_array($admin_role, ['superintendent', 'asst_superintendent'])): ?>
        <div class="nav-section-label">Monitoring</div>
        <li class="nav-item">
            <a href="attendance.php" class="nav-link <?= $current_page === 'attendance' ? 'active' : '' ?>">
                <i class="fas fa-clipboard-check"></i> Attendance
            </a>
        </li>
        <li class="nav-item">
            <a href="reports.php" class="nav-link <?= $current_page === 'reports' ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> Reports
            </a>
        </li>
        <li class="nav-item">
            <a href="sms_logs.php" class="nav-link <?= $current_page === 'sms_logs' ? 'active' : '' ?>">
                <i class="fas fa-sms"></i> SMS Logs
            </a>
        </li>
        <?php endif; ?>

        <?php if (in_array($admin_role, ['super_admin', 'principal'])): ?>
        <div class="nav-section-label">Notifications</div>
        <li class="nav-item">
            <a href="sms_logs.php" class="nav-link <?= $current_page === 'sms_logs' ? 'active' : '' ?>">
                <i class="fas fa-sms"></i> SMS Logs
            </a>
        </li>
        <?php endif; ?>

        <?php if ($admin_role === 'super_admin'): ?>
        <div class="nav-section-label">System</div>
        <li class="nav-item">
            <a href="settings.php" class="nav-link <?= $current_page === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
        </li>
        <?php endif; ?>

        <?php if ($admin_role === 'super_admin'): ?>
        <div class="nav-section-label">Quick Access</div>
        <li class="nav-item">
            <a href="../Qrscanattendance.php" class="nav-link" target="_blank">
                <i class="fas fa-qrcode"></i> QR Scanner
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-footer">
        <div style="padding: 8px 16px; margin-bottom: 8px;">
            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text);"><?= htmlspecialchars($admin_name) ?></div>
            <div style="font-size: 0.68rem; color: var(--text-muted); text-transform: capitalize;"><?= str_replace('_', ' ', $admin_role) ?></div>
        </div>
        <a href="logout.php" class="nav-link">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</aside>

<!-- Mobile menu toggle -->
<button class="mobile-menu-toggle" id="menuToggle" onclick="document.getElementById('sidebar').classList.toggle('open')" style="display:none; position:fixed; top:16px; left:16px; z-index:101; background:var(--primary); color:#fff; border:none; border-radius:10px; padding:10px 14px; cursor:pointer; font-size:1.1rem;">
    <i class="fas fa-bars"></i>
</button>
<style>
@media (max-width: 1024px) {
    .mobile-menu-toggle { display: block !important; }
}
@media print {
    .mobile-menu-toggle { display: none !important; }
}
</style>
