<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * MOBILE DASHBOARD — PWA App Entry Point
 * ══════════════════════════════════════════════════════════════════
 * Standalone dashboard designed for mobile phones (installable as APK via PWA).
 * No sidebar, no desktop chrome — just the attendance data.
 */
session_start();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require_once 'config/database.php';
require_once 'config/school_days.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: app_login.php');
    exit;
}

$conn = getDBConnection();

$today = date('Y-m-d');
$filter_date = htmlspecialchars($_GET['date'] ?? $today);
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// Role-based school filter
$school_filter_sql = '';
if ($admin_role === 'principal' && $admin_school_id) {
    $school_filter_sql = " AND school_id = $admin_school_id ";
}

$filter_school = (int)($_GET['school'] ?? 0);
$extra_filter = '';
if ($filter_school) $extra_filter .= " AND school_id = $filter_school ";

// ─── Summary Stats ───
$total_schools = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM schools WHERE status='active' $school_filter_sql");
if ($r) $total_schools = $r->fetch_assoc()['cnt'];

$total_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = $admin_school_id" : ""));
if ($r) $total_students = $r->fetch_assoc()['cnt'];

$total_teachers = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = $admin_school_id" : ""));
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

$timed_in_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_in_today = $r->fetch_assoc()['cnt'];

$timed_out_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_out IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_out_today = $r->fetch_assoc()['cnt'];

$relevant_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = $admin_school_id " : "") . ($filter_school ? "AND school_id = $filter_school" : ""));
if ($r) $relevant_students = $r->fetch_assoc()['cnt'];
$absent_today = $relevant_students - $timed_in_today;
$attendance_rate = $relevant_students > 0 ? round(($timed_in_today / $relevant_students) * 100, 1) : 0;

$teachers_in = 0;
$r = $conn->query("SELECT COUNT(DISTINCT person_id) as cnt FROM attendance WHERE person_type='teacher' AND date='$filter_date' AND time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $teachers_in = $r->fetch_assoc()['cnt'];

// ─── 2-Day Flag Count ───
$yesterday = date('Y-m-d', strtotime('-1 day', strtotime($filter_date)));
$flag_count = 0;
$flag_sql = "SELECT COUNT(*) as cnt FROM students s
    WHERE s.status = 'active'
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date')
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday')
    " . ($admin_role === 'principal' && $admin_school_id ? "AND s.school_id = $admin_school_id" : "") . "
    " . ($filter_school ? "AND s.school_id = $filter_school" : "");
$r = $conn->query($flag_sql);
if ($r) $flag_count = $r->fetch_assoc()['cnt'];

// ─── Per-School Breakdown ───
$school_breakdown = [];
$school_sql = "SELECT s.id, s.name, s.code,
    (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active') as enrolled,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a WHERE a.person_type='student' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a WHERE a.person_type='teacher' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as teachers_present,
    (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as total_teachers
    FROM schools s WHERE s.status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND s.id = $admin_school_id" : "") . "
    ORDER BY s.name";
$r = $conn->query($school_sql);
if ($r) { while ($row = $r->fetch_assoc()) { $row['absent'] = $row['enrolled'] - $row['present']; $row['rate'] = $row['enrolled'] > 0 ? round(($row['present'] / $row['enrolled']) * 100, 1) : 0; $school_breakdown[] = $row; } }

// Schools list for filter
$schools_list = [];
$r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name");
if ($r) { while ($row = $r->fetch_assoc()) $schools_list[] = $row; }

// System logo
$systemLogo = '';
$lr = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'");
if ($lr && $lrow = $lr->fetch_assoc()) {
    $lf = $lrow['setting_value'] ?? '';
    if ($lf && file_exists(__DIR__ . '/assets/uploads/logos/' . $lf)) $systemLogo = 'assets/uploads/logos/' . $lf;
}

$is_today = ($filter_date === $today);
$non_school = !isSchoolDay($filter_date, $conn);
$non_school_reason = $non_school ? getNonSchoolDayReason($filter_date, $conn) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#4338ca">
    <title>Dashboard — EduTrack | SDO-Sipalay City</title>
    <?php if ($systemLogo): ?><link rel="icon" type="image/png" href="<?= $systemLogo ?>"><?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #4338ca; --primary-light: #6366f1; --primary-bg: rgba(67,56,202,0.08);
            --bg: #f1f5f9; --card: #fff; --text: #0f172a; --muted: #64748b; --border: #e2e8f0;
            --green: #16a34a; --green-bg: #f0fdf4; --red: #dc2626; --red-bg: #fef2f2;
            --amber: #d97706; --amber-bg: #fffbeb; --blue: #2563eb; --blue-bg: #eff6ff;
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        html, body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; overflow-x: hidden; -webkit-tap-highlight-color: transparent; }

        /* ─── App Shell ─── */
        .app-header {
            position: sticky; top: 0; z-index: 100;
            background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);
            padding: calc(12px + var(--safe-top)) 20px 16px;
            color: #fff;
        }
        .app-header-top {
            display: flex; align-items: center; justify-content: space-between;
        }
        .app-header-brand {
            display: flex; align-items: center; gap: 12px;
        }
        .app-header-brand .logo {
            width: 40px; height: 40px; border-radius: 12px; overflow: hidden; background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .app-header-brand .logo img { width: 100%; height: 100%; object-fit: cover; }
        .app-header-brand .logo i { font-size: 1.1rem; color: #fff; }
        .app-header-brand h1 { font-size: 1.05rem; font-weight: 800; letter-spacing: -0.02em; }
        .app-header-brand small { font-size: 0.68rem; opacity: 0.75; font-weight: 500; }
        .header-actions { display: flex; gap: 8px; }
        .header-btn {
            width: 38px; height: 38px; border-radius: 12px; background: rgba(255,255,255,0.15);
            border: none; color: #fff; font-size: 1rem; cursor: pointer; display: flex;
            align-items: center; justify-content: center; transition: background 0.2s;
        }
        .header-btn:hover { background: rgba(255,255,255,0.25); }

        /* Date bar */
        .date-bar {
            margin-top: 14px; display: flex; align-items: center; gap: 10px;
        }
        .date-chip {
            display: flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.15);
            padding: 8px 14px; border-radius: 10px; font-size: 0.78rem; font-weight: 600;
        }
        .date-chip i { font-size: 0.7rem; opacity: 0.7; }
        .date-input {
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; padding: 8px 12px; border-radius: 10px; font-size: 0.78rem;
            font-family: 'Inter', sans-serif; font-weight: 600; outline: none;
            color-scheme: dark;
        }
        .live-dot {
            width: 8px; height: 8px; border-radius: 50%; background: #4ade80;
            box-shadow: 0 0 8px rgba(74,222,128,0.6); animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

        /* No-class banner */
        .no-class-banner {
            margin: 16px 16px 0; padding: 14px 18px; border-radius: 14px;
            background: linear-gradient(135deg, #fffbeb, #fef3c7); border: 1px solid #fde68a;
            display: flex; align-items: center; gap: 12px;
        }
        .no-class-banner i { font-size: 1.4rem; color: #d97706; flex-shrink: 0; }
        .no-class-banner .ncb-text strong { color: #92400e; font-size: 0.85rem; }
        .no-class-banner .ncb-text p { color: #a16207; font-size: 0.75rem; margin-top: 2px; }

        /* ─── Content ─── */
        .content { padding: 16px 16px calc(80px + var(--safe-bottom)); }

        /* Ring chart */
        .ring-card {
            background: var(--card); border-radius: 20px; padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 16px; text-align: center;
        }
        .ring-wrap { position: relative; width: 160px; height: 160px; margin: 0 auto 16px; }
        .ring-wrap svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .ring-wrap .ring-bg { fill: none; stroke: #e2e8f0; stroke-width: 10; }
        .ring-wrap .ring-fill { fill: none; stroke-width: 10; stroke-linecap: round; transition: stroke-dashoffset 1s ease; }
        .ring-center {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            text-align: center;
        }
        .ring-center .pct { font-size: 2.2rem; font-weight: 900; letter-spacing: -2px; color: var(--text); }
        .ring-center .pct-label { font-size: 0.65rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Stat pills row */
        .stat-pills {
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;
        }
        .pill {
            background: var(--card); border-radius: 16px; padding: 18px 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 14px;
        }
        .pill-icon {
            width: 44px; height: 44px; border-radius: 14px; display: flex;
            align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;
        }
        .pill-icon.green { background: var(--green-bg); color: var(--green); }
        .pill-icon.red { background: var(--red-bg); color: var(--red); }
        .pill-icon.amber { background: var(--amber-bg); color: var(--amber); }
        .pill-icon.blue { background: var(--blue-bg); color: var(--blue); }
        .pill-icon.purple { background: var(--primary-bg); color: var(--primary); }
        .pill-val { font-size: 1.4rem; font-weight: 900; letter-spacing: -1px; line-height: 1; }
        .pill-label { font-size: 0.65rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; margin-top: 2px; }

        /* School cards */
        .section-title {
            font-size: 0.8rem; font-weight: 700; color: var(--muted); text-transform: uppercase;
            letter-spacing: 0.8px; margin: 20px 0 12px; display: flex; align-items: center; gap: 8px;
        }
        .section-title i { font-size: 0.75rem; }
        .school-card {
            background: var(--card); border-radius: 16px; padding: 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 10px;
        }
        .sc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .sc-name { font-size: 0.88rem; font-weight: 700; color: var(--text); line-height: 1.2; }
        .sc-code {
            font-size: 0.62rem; font-weight: 700; background: var(--primary-bg); color: var(--primary);
            padding: 4px 10px; border-radius: 8px; white-space: nowrap;
        }
        .sc-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-bottom: 10px; }
        .sc-bar .fill { height: 100%; border-radius: 3px; transition: width 0.6s ease; }
        .sc-stats { display: flex; gap: 16px; }
        .sc-stat { text-align: center; flex: 1; }
        .sc-stat .v { font-size: 1rem; font-weight: 800; }
        .sc-stat .l { font-size: 0.6rem; color: var(--muted); font-weight: 600; text-transform: uppercase; }
        .v-green { color: var(--green); }
        .v-red { color: var(--red); }
        .v-blue { color: var(--blue); }

        /* School filter dropdown (mobile) */
        .filter-select {
            width: 100%; background: var(--card); border: 1px solid var(--border);
            padding: 12px 16px; border-radius: 12px; font-size: 0.82rem; font-family: 'Inter', sans-serif;
            font-weight: 600; color: var(--text); margin-bottom: 12px; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 14px center;
        }

        /* Bottom nav */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 100;
            background: var(--card); border-top: 1px solid var(--border);
            padding: 8px 16px calc(8px + var(--safe-bottom));
            display: flex; justify-content: space-around;
        }
        .nav-item {
            display: flex; flex-direction: column; align-items: center; gap: 3px;
            font-size: 0.6rem; font-weight: 600; color: var(--muted); text-decoration: none;
            padding: 6px 12px; border-radius: 10px; transition: all 0.2s; border: none; background: none; cursor: pointer;
        }
        .nav-item.active { color: var(--primary); }
        .nav-item i { font-size: 1.1rem; }

        /* Pull to refresh indicator */
        .refresh-indicator {
            text-align: center; padding: 12px; font-size: 0.75rem; color: var(--muted); font-weight: 600; display: none;
        }
        .refresh-indicator.visible { display: block; }

        /* Slide-up panel for school filter */
        .filter-panel {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 200;
            background: var(--card); border-radius: 20px 20px 0 0;
            padding: 20px 20px calc(20px + var(--safe-bottom));
            box-shadow: 0 -10px 40px rgba(0,0,0,0.15);
            transform: translateY(100%); transition: transform 0.3s ease;
        }
        .filter-panel.open { transform: translateY(0); }
        .filter-backdrop {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 199;
            background: rgba(0,0,0,0.3); opacity: 0; pointer-events: none; transition: opacity 0.3s;
        }
        .filter-backdrop.open { opacity: 1; pointer-events: auto; }
        .filter-handle { width: 40px; height: 4px; background: #cbd5e1; border-radius: 2px; margin: 0 auto 16px; }
        .filter-title { font-size: 0.95rem; font-weight: 800; margin-bottom: 14px; }
        .filter-option {
            padding: 14px 16px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;
            cursor: pointer; transition: background 0.15s; display: flex; justify-content: space-between; align-items: center;
        }
        .filter-option:hover { background: var(--bg); }
        .filter-option.selected { background: var(--primary-bg); color: var(--primary); }
        .filter-option .check { display: none; }
        .filter-option.selected .check { display: inline; }
        .filter-list { max-height: 50vh; overflow-y: auto; }

        /* Notification bell */
        .header-btn { position: relative; }
        .notif-dot {
            position: absolute; top: 6px; right: 6px; width: 8px; height: 8px;
            border-radius: 50%; background: #ef4444; display: none;
            box-shadow: 0 0 6px rgba(239,68,68,0.6);
        }
        .notif-dot.active { display: block; animation: pulse 1.5s infinite; }
        .notif-toast {
            position: fixed; top: -60px; left: 50%; transform: translateX(-50%);
            background: #0f172a; color: #fff; padding: 12px 20px; border-radius: 14px;
            font-size: 0.82rem; font-weight: 600; z-index: 300; display: flex;
            align-items: center; gap: 8px; transition: top 0.35s ease;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }
        .notif-toast.show { top: calc(20px + var(--safe-top)); }
        .notif-toast .fa-check-circle { color: #4ade80; }
        .notif-toast .fa-times-circle { color: #f87171; }
    </style>
</head>
<body>
    <!-- APP HEADER -->
    <header class="app-header">
        <div class="app-header-top">
            <div class="app-header-brand">
                <div class="logo">
                    <?php if ($systemLogo): ?>
                        <img src="<?= htmlspecialchars($systemLogo) ?>" alt="Logo">
                    <?php else: ?>
                        <i class="fas fa-chart-pie"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <h1>Attendance Dashboard</h1>
                    <small><?= htmlspecialchars($admin_name) ?> · <?= ucfirst(str_replace('_', ' ', $admin_role)) ?></small>
                </div>
            </div>
            <div class="header-actions">
                <button class="header-btn" id="notifBell" onclick="toggleNotifications()" title="Notifications">
                    <i class="fas fa-bell" id="bellIcon"></i>
                    <span class="notif-dot" id="notifDot"></span>
                </button>
                <button class="header-btn" onclick="location.reload()" title="Refresh"><i class="fas fa-sync-alt"></i></button>
                <a href="admin/logout.php" class="header-btn" title="Logout"><i class="fas fa-right-from-bracket"></i></a>
            </div>
        </div>
        <div class="date-bar">
            <?php if ($is_today): ?>
                <div class="live-dot"></div>
                <div class="date-chip"><i class="fas fa-clock"></i> Live — <?= date('D, M j') ?></div>
            <?php else: ?>
                <div class="date-chip"><i class="fas fa-calendar"></i> <?= date('D, M j, Y', strtotime($filter_date)) ?></div>
            <?php endif; ?>
            <input type="date" class="date-input" value="<?= $filter_date ?>" onchange="applyDate(this.value)">
        </div>
    </header>

    <!-- NO CLASS NOTICE -->
    <?php if ($non_school): ?>
    <div class="no-class-banner">
        <i class="fas fa-calendar-xmark"></i>
        <div class="ncb-text">
            <strong>No Classes Today</strong>
            <p><?= htmlspecialchars($non_school_reason ?? 'Non-school day') ?> — Data shown for reference only.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- MAIN CONTENT -->
    <div class="content">

        <!-- Attendance Ring -->
        <div class="ring-card">
            <div class="ring-wrap">
                <svg viewBox="0 0 120 120">
                    <circle class="ring-bg" cx="60" cy="60" r="52"/>
                    <?php
                        $circumference = 2 * M_PI * 52;
                        $offset = $circumference - ($attendance_rate / 100) * $circumference;
                        $ring_color = $attendance_rate >= 80 ? '#16a34a' : ($attendance_rate >= 50 ? '#d97706' : '#dc2626');
                    ?>
                    <circle class="ring-fill" cx="60" cy="60" r="52"
                        stroke="<?= $ring_color ?>"
                        stroke-dasharray="<?= $circumference ?>"
                        stroke-dashoffset="<?= $offset ?>"/>
                </svg>
                <div class="ring-center">
                    <div class="pct"><?= $attendance_rate ?>%</div>
                    <div class="pct-label">Student Attendance</div>
                </div>
            </div>
            <div style="font-size:0.75rem;color:var(--muted);font-weight:500;">
                <?= $timed_in_today ?> of <?= $relevant_students ?> students present<?php if ($filter_school && $schools_list): ?> (filtered)<?php endif; ?>
            </div>
        </div>

        <!-- Stat Pills -->
        <div class="stat-pills">
            <div class="pill">
                <div class="pill-icon green"><i class="fas fa-user-check"></i></div>
                <div><div class="pill-val"><?= $timed_in_today ?></div><div class="pill-label">Present</div></div>
            </div>
            <div class="pill">
                <div class="pill-icon red"><i class="fas fa-user-xmark"></i></div>
                <div><div class="pill-val"><?= $absent_today ?></div><div class="pill-label">Absent</div></div>
            </div>
            <div class="pill">
                <div class="pill-icon amber"><i class="fas fa-triangle-exclamation"></i></div>
                <div><div class="pill-val"><?= $flag_count ?></div><div class="pill-label">2-Day Flag</div></div>
            </div>
            <div class="pill">
                <div class="pill-icon blue"><i class="fas fa-chalkboard-teacher"></i></div>
                <div><div class="pill-val"><?= $teachers_in ?><span style="font-size:0.7rem;font-weight:600;color:var(--muted);">/<?= $total_teachers ?></span></div><div class="pill-label">Teachers</div></div>
            </div>
            <div class="pill">
                <div class="pill-icon purple"><i class="fas fa-school"></i></div>
                <div><div class="pill-val"><?= $total_schools ?></div><div class="pill-label">Schools</div></div>
            </div>
            <div class="pill">
                <div class="pill-icon green"><i class="fas fa-arrow-right-from-bracket"></i></div>
                <div><div class="pill-val"><?= $timed_out_today ?></div><div class="pill-label">Timed Out</div></div>
            </div>
        </div>

        <!-- School Filter -->
        <?php if ($admin_role !== 'principal' && count($schools_list) > 1): ?>
        <button class="filter-select" onclick="openFilter()" style="cursor:pointer;">
            <?= $filter_school ? htmlspecialchars(array_values(array_filter($schools_list, fn($s) => $s['id'] == $filter_school))[0]['name'] ?? 'All Schools') : 'All Schools' ?>
        </button>
        <?php endif; ?>

        <!-- Per-School Breakdown -->
        <div class="section-title"><i class="fas fa-school"></i> School Breakdown</div>
        <?php if (empty($school_breakdown)): ?>
            <div class="school-card" style="text-align:center;color:var(--muted);padding:30px;">No schools found.</div>
        <?php else: foreach ($school_breakdown as $sb): ?>
        <div class="school-card">
            <div class="sc-top">
                <div class="sc-name"><?= htmlspecialchars($sb['name']) ?></div>
                <span class="sc-code"><?= htmlspecialchars($sb['code']) ?></span>
            </div>
            <div class="sc-bar">
                <div class="fill" style="width:<?= $sb['rate'] ?>%;background:<?= $sb['rate'] >= 80 ? 'var(--green)' : ($sb['rate'] >= 50 ? 'var(--amber)' : 'var(--red)') ?>;"></div>
            </div>
            <div class="sc-stats">
                <div class="sc-stat"><div class="v v-green"><?= $sb['present'] ?></div><div class="l">Present</div></div>
                <div class="sc-stat"><div class="v v-red"><?= $sb['absent'] ?></div><div class="l">Absent</div></div>
                <div class="sc-stat"><div class="v"><?= $sb['rate'] ?>%</div><div class="l">Rate</div></div>
                <div class="sc-stat"><div class="v v-blue"><?= $sb['teachers_present'] ?>/<?= $sb['total_teachers'] ?></div><div class="l">Teachers</div></div>
            </div>
        </div>
        <?php endforeach; endif; ?>

    </div>

    <!-- FILTER PANEL (Slide-up) -->
    <div class="filter-backdrop" id="filterBackdrop" onclick="closeFilter()"></div>
    <div class="filter-panel" id="filterPanel">
        <div class="filter-handle"></div>
        <div class="filter-title">Select School</div>
        <div class="filter-list">
            <div class="filter-option <?= !$filter_school ? 'selected' : '' ?>" onclick="applySchool(0)">
                All Schools <i class="fas fa-check check"></i>
            </div>
            <?php foreach ($schools_list as $sch): ?>
            <div class="filter-option <?= $filter_school == $sch['id'] ? 'selected' : '' ?>" onclick="applySchool(<?= $sch['id'] ?>)">
                <?= htmlspecialchars($sch['name']) ?> <i class="fas fa-check check"></i>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- NOTIFICATION TOAST -->
    <div class="notif-toast" id="notifToast">
        <i class="fas fa-check-circle" id="notifToastIcon"></i>
        <span id="notifToastMsg">Notifications enabled</span>
    </div>

    <!-- CHECK ABSENCES BUTTON (for admins) -->
    <div class="content" style="padding:0 16px 8px;">
        <button onclick="checkAbsences()" id="checkAbsBtn" style="width:100%;padding:14px;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border:none;border-radius:14px;font-size:0.85rem;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 12px rgba(220,38,38,0.25);">
            <i class="fas fa-bell"></i> Check & Send Absence Alerts Now
        </button>
        <div id="checkResult" style="margin-top:8px;font-size:0.78rem;color:var(--muted);text-align:center;"></div>
    </div>

    <!-- BOTTOM NAV -->
    <nav class="bottom-nav">
        <button class="nav-item active"><i class="fas fa-chart-pie"></i> Dashboard</button>
        <button class="nav-item" id="notifNavBtn" onclick="toggleNotifications()"><i class="fas fa-bell"></i> <span id="notifNavLabel">Alerts</span></button>
        <button class="nav-item" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
        <a href="admin/logout.php" class="nav-item"><i class="fas fa-right-from-bracket"></i> Logout</a>
    </nav>

    <script>
    // Unregister any leftover service workers from old PWA
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(regs => {
            regs.forEach(r => r.unregister());
        });
    }

    // ══════════════════════════════════════════════════════════════
    // NOTIFICATIONS (Native app handles via WorkManager)
    // ══════════════════════════════════════════════════════════════
    function updateBellUI(subscribed) {
        const dot = document.getElementById('notifDot');
        const icon = document.getElementById('bellIcon');
        const navLabel = document.getElementById('notifNavLabel');
        if (subscribed) {
            dot.classList.add('active');
            icon.className = 'fas fa-bell';
            if (navLabel) navLabel.textContent = 'Alerts On';
        } else {
            dot.classList.remove('active');
            icon.className = 'far fa-bell';
            if (navLabel) navLabel.textContent = 'Alerts Off';
        }
    }

    // Native app — notifications handled automatically
    updateBellUI(true);

    function toggleNotifications() {
        showToast('Notifications are managed by the app automatically.', true);
    }

    function showToast(msg, success) {
        const toast = document.getElementById('notifToast');
        const icon = document.getElementById('notifToastIcon');
        const text = document.getElementById('notifToastMsg');
        icon.className = success ? 'fas fa-check-circle' : 'fas fa-times-circle';
        text.textContent = msg;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    // ══════════════════════════════════════════════════════════════
    // CHECK ABSENCES + SEND NOTIFICATIONS (manual trigger)
    // ══════════════════════════════════════════════════════════════
    async function checkAbsences() {
        const btn = document.getElementById('checkAbsBtn');
        const result = document.getElementById('checkResult');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
        result.textContent = '';

        try {
            const resp = await fetch('api/check_absences_notify.php');
            const data = await resp.json();

            if (data.skipped) {
                result.textContent = data.message;
            } else if (data.success) {
                result.innerHTML = '<strong style="color:var(--red);">' + data.flagged + ' students flagged.</strong> ' +
                    data.notifications.sent + ' notifications sent.';
                if (data.flagged > 0) {
                    showToast(data.flagged + ' students flagged for 2-day absence', true);
                } else {
                    showToast('No students flagged — all good!', true);
                }
            } else {
                result.textContent = data.error || 'Unknown error';
            }
        } catch (err) {
            result.textContent = 'Network error: ' + err.message;
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-bell"></i> Check & Send Absence Alerts Now';
    }

    // ══════════════════════════════════════════════════════════════
    // GENERAL
    // ══════════════════════════════════════════════════════════════
    let refreshTimer = setTimeout(() => location.reload(), 60000);

    function applyDate(val) {
        const params = new URLSearchParams(window.location.search);
        params.set('date', val);
        window.location.search = params.toString();
    }

    function applySchool(id) {
        const params = new URLSearchParams(window.location.search);
        if (id) { params.set('school', id); } else { params.delete('school'); }
        window.location.search = params.toString();
    }

    function openFilter() {
        document.getElementById('filterPanel').classList.add('open');
        document.getElementById('filterBackdrop').classList.add('open');
    }

    function closeFilter() {
        document.getElementById('filterPanel').classList.remove('open');
        document.getElementById('filterBackdrop').classList.remove('open');
    }
    </script>
</body>
</html>
