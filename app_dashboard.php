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
            --primary: #4f46e5; --primary-light: #818cf8; --primary-dark: #3730a3;
            --primary-bg: rgba(79,70,229,0.08); --primary-glow: rgba(79,70,229,0.25);
            --bg: #f0f2f8; --bg-mesh: #e8ecf4;
            --card: rgba(255,255,255,0.85); --card-solid: #fff;
            --text: #0f172a; --text-secondary: #334155; --muted: #64748b; --border: rgba(226,232,240,0.8);
            --green: #059669; --green-light: #34d399; --green-bg: linear-gradient(135deg, #ecfdf5, #d1fae5);
            --red: #dc2626; --red-light: #f87171; --red-bg: linear-gradient(135deg, #fef2f2, #fee2e2);
            --amber: #d97706; --amber-light: #fbbf24; --amber-bg: linear-gradient(135deg, #fffbeb, #fef3c7);
            --blue: #2563eb; --blue-light: #60a5fa; --blue-bg: linear-gradient(135deg, #eff6ff, #dbeafe);
            --purple-bg: linear-gradient(135deg, #f5f3ff, #ede9fe);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
            --shadow-lg: 0 10px 25px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.05);
            --shadow-xl: 0 20px 40px -8px rgba(0,0,0,0.1), 0 8px 16px -6px rgba(0,0,0,0.06);
            --shadow-3d: 0 1px 1px rgba(0,0,0,0.08), 0 2px 2px rgba(0,0,0,0.06), 0 4px 4px rgba(0,0,0,0.04), 0 8px 8px rgba(0,0,0,0.02), 0 16px 16px rgba(0,0,0,0.01);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        html, body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
            -webkit-tap-highlight-color: transparent;
        }
        body::before {
            content: '';
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: -1;
            background:
                radial-gradient(ellipse at 20% 50%, rgba(79,70,229,0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(99,102,241,0.05) 0%, transparent 50%),
                radial-gradient(ellipse at 60% 80%, rgba(34,211,238,0.03) 0%, transparent 50%),
                var(--bg);
        }

        /* ─── Entrance Animations ─── */
        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.92); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes ringDraw {
            from { stroke-dashoffset: 326.73; }
        }
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
        @keyframes floatGlow {
            0%,100% { box-shadow: 0 0 20px rgba(79,70,229,0.15); }
            50% { box-shadow: 0 0 30px rgba(79,70,229,0.25); }
        }

        /* ─── App Header — Premium Glass ─── */
        .app-header {
            position: sticky; top: 0; z-index: 100;
            background: linear-gradient(135deg, #3730a3 0%, #4f46e5 40%, #6366f1 70%, #818cf8 100%);
            padding: calc(14px + var(--safe-top)) 20px 20px;
            color: #fff;
            overflow: hidden;
        }
        .app-header::before {
            content: '';
            position: absolute; top: -60%; right: -20%; width: 260px; height: 260px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        .app-header::after {
            content: '';
            position: absolute; bottom: -40%; left: -10%; width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
            pointer-events: none;
        }
        .app-header-top {
            display: flex; align-items: center; justify-content: space-between;
            position: relative; z-index: 1;
        }
        .app-header-brand {
            display: flex; align-items: center; gap: 14px;
        }
        .app-header-brand .logo {
            width: 44px; height: 44px; border-radius: 14px; overflow: hidden;
            background: rgba(255,255,255,0.18);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            border: 1px solid rgba(255,255,255,0.2);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .app-header-brand .logo img { width: 100%; height: 100%; object-fit: cover; }
        .app-header-brand .logo i { font-size: 1.15rem; color: #fff; }
        .app-header-brand h1 { font-size: 1.1rem; font-weight: 800; letter-spacing: -0.03em; text-shadow: 0 1px 3px rgba(0,0,0,0.15); }
        .app-header-brand small { font-size: 0.7rem; opacity: 0.8; font-weight: 500; letter-spacing: 0.02em; }
        .header-actions { display: flex; gap: 10px; position: relative; z-index: 1; }
        .header-btn {
            width: 40px; height: 40px; border-radius: 14px;
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff; font-size: 1rem; cursor: pointer; display: flex;
            align-items: center; justify-content: center;
            transition: all 0.25s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header-btn:hover, .header-btn:active {
            background: rgba(255,255,255,0.22);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        /* Date bar */
        .date-bar {
            margin-top: 16px; display: flex; align-items: center; gap: 10px;
            position: relative; z-index: 1;
        }
        .date-chip {
            display: flex; align-items: center; gap: 7px;
            background: rgba(255,255,255,0.13);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            padding: 9px 16px; border-radius: 12px; font-size: 0.78rem; font-weight: 600;
            border: 1px solid rgba(255,255,255,0.12);
        }
        .date-chip i { font-size: 0.72rem; opacity: 0.8; }
        .date-input {
            background: rgba(255,255,255,0.13);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff; padding: 9px 14px; border-radius: 12px; font-size: 0.78rem;
            font-family: 'Inter', sans-serif; font-weight: 600; outline: none;
            color-scheme: dark; transition: background 0.2s;
        }
        .date-input:focus { background: rgba(255,255,255,0.2); }
        .live-dot {
            width: 9px; height: 9px; border-radius: 50%; background: #4ade80;
            box-shadow: 0 0 12px rgba(74,222,128,0.7); animation: pulse 1.5s infinite;
        }

        /* No-class banner */
        .no-class-banner {
            margin: 16px 16px 0; padding: 16px 20px; border-radius: 18px;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid rgba(253,224,71,0.4);
            display: flex; align-items: center; gap: 14px;
            box-shadow: var(--shadow-md), inset 0 1px 0 rgba(255,255,255,0.8);
            animation: fadeSlideUp 0.5s ease;
        }
        .no-class-banner i { font-size: 1.5rem; color: #d97706; flex-shrink: 0; filter: drop-shadow(0 2px 4px rgba(217,119,6,0.2)); }
        .no-class-banner .ncb-text strong { color: #92400e; font-size: 0.88rem; }
        .no-class-banner .ncb-text p { color: #a16207; font-size: 0.76rem; margin-top: 3px; }

        /* ─── Content ─── */
        .content { padding: 20px 16px calc(90px + var(--safe-bottom)); }

        /* ─── Ring Card — 3D Glass ─── */
        .ring-card {
            background: var(--card-solid);
            border-radius: 24px; padding: 28px 24px;
            margin-bottom: 20px; text-align: center;
            position: relative; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.9);
            box-shadow: var(--shadow-3d);
            animation: scaleIn 0.5s ease;
        }
        .ring-card::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light), #22d3ee, var(--primary-light), var(--primary));
            background-size: 200% 100%;
            animation: shimmer 3s ease infinite;
        }
        .ring-card::after {
            content: '';
            position: absolute; top: 4px; right: -40px; width: 120px; height: 120px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(79,70,229,0.06) 0%, transparent 70%);
            pointer-events: none;
        }
        .ring-wrap {
            position: relative; width: 180px; height: 180px; margin: 0 auto 20px;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.08));
        }
        .ring-wrap svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .ring-wrap .ring-bg { fill: none; stroke: #e8ecf4; stroke-width: 11; }
        .ring-wrap .ring-fill {
            fill: none; stroke-width: 11; stroke-linecap: round;
            transition: stroke-dashoffset 1.2s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 0 6px currentColor);
            animation: ringDraw 1.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .ring-center {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            text-align: center;
        }
        .ring-center .pct {
            font-size: 2.6rem; font-weight: 900; letter-spacing: -2px; color: var(--text);
            text-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
        .ring-center .pct-label {
            font-size: 0.68rem; color: var(--muted); font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.8px; margin-top: 2px;
        }
        .ring-subtitle {
            font-size: 0.78rem; color: var(--muted); font-weight: 500;
        }

        /* ─── Stat Pills — 3D Elevated Cards ─── */
        .stat-pills {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;
        }
        .pill {
            background: var(--card-solid);
            border-radius: 20px; padding: 20px 16px;
            display: flex; align-items: center; gap: 14px;
            position: relative; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.9);
            box-shadow: var(--shadow-3d);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            animation: fadeSlideUp 0.5s ease backwards;
        }
        .pill:nth-child(1) { animation-delay: 0.05s; }
        .pill:nth-child(2) { animation-delay: 0.1s; }
        .pill:nth-child(3) { animation-delay: 0.15s; }
        .pill:nth-child(4) { animation-delay: 0.2s; }
        .pill:nth-child(5) { animation-delay: 0.25s; }
        .pill:nth-child(6) { animation-delay: 0.3s; }
        .pill:active {
            transform: scale(0.97);
            box-shadow: var(--shadow-sm);
        }
        .pill::after {
            content: '';
            position: absolute; top: 0; right: 0; width: 60px; height: 60px;
            border-radius: 50%;
            opacity: 0.04; pointer-events: none;
            transform: translate(20px, -20px);
        }
        .pill-icon {
            width: 48px; height: 48px; border-radius: 16px; display: flex;
            align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0;
            position: relative;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }
        .pill-icon.green { background: var(--green-bg); color: var(--green); }
        .pill-icon.red { background: var(--red-bg); color: var(--red); }
        .pill-icon.amber { background: var(--amber-bg); color: var(--amber); }
        .pill-icon.blue { background: var(--blue-bg); color: var(--blue); }
        .pill-icon.purple { background: var(--purple-bg); color: var(--primary); }
        .pill-val {
            font-size: 1.5rem; font-weight: 900; letter-spacing: -1px; line-height: 1;
            background: linear-gradient(135deg, var(--text) 0%, var(--text-secondary) 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .pill-label {
            font-size: 0.65rem; color: var(--muted); font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px;
        }

        /* ─── School Cards — Elevated 3D ─── */
        .section-title {
            font-size: 0.78rem; font-weight: 800; color: var(--muted); text-transform: uppercase;
            letter-spacing: 1px; margin: 24px 0 14px; display: flex; align-items: center; gap: 10px;
        }
        .section-title i { font-size: 0.72rem; color: var(--primary-light); }
        .section-title::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, var(--border), transparent);
        }
        .school-card {
            background: var(--card-solid);
            border-radius: 20px; padding: 20px;
            margin-bottom: 12px;
            position: relative; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.9);
            border-left: 4px solid var(--primary-light);
            box-shadow: var(--shadow-3d);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            animation: fadeSlideUp 0.4s ease backwards;
        }
        .school-card:active {
            transform: scale(0.985);
            box-shadow: var(--shadow-sm);
        }
        .sc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
        .sc-name { font-size: 0.9rem; font-weight: 800; color: var(--text); line-height: 1.25; }
        .sc-code {
            font-size: 0.62rem; font-weight: 700;
            background: linear-gradient(135deg, rgba(79,70,229,0.08), rgba(129,140,248,0.12));
            color: var(--primary);
            padding: 5px 12px; border-radius: 10px; white-space: nowrap;
            border: 1px solid rgba(79,70,229,0.1);
        }
        .sc-bar {
            height: 8px; background: #e8ecf4; border-radius: 4px; overflow: hidden; margin-bottom: 14px;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.06);
        }
        .sc-bar .fill {
            height: 100%; border-radius: 4px;
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            background-image: linear-gradient(90deg, currentColor, currentColor);
            position: relative;
        }
        .sc-stats { display: flex; gap: 0; }
        .sc-stat {
            text-align: center; flex: 1;
            padding: 8px 0; border-radius: 12px;
            transition: background 0.2s;
        }
        .sc-stat .v { font-size: 1.05rem; font-weight: 900; }
        .sc-stat .l {
            font-size: 0.6rem; color: var(--muted); font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px; margin-top: 2px;
        }
        .v-green { color: var(--green); }
        .v-red { color: var(--red); }
        .v-blue { color: var(--blue); }

        /* School filter dropdown (mobile) */
        .filter-select {
            width: 100%;
            background: var(--card-solid);
            border: 1px solid var(--border);
            padding: 14px 18px; border-radius: 16px; font-size: 0.84rem; font-family: 'Inter', sans-serif;
            font-weight: 600; color: var(--text); margin-bottom: 14px; appearance: none;
            box-shadow: var(--shadow-md);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 16px center;
            transition: box-shadow 0.2s, border-color 0.2s;
        }
        .filter-select:active {
            border-color: var(--primary-light);
            box-shadow: var(--shadow-lg), 0 0 0 3px rgba(79,70,229,0.1);
        }

        /* ─── Bottom Nav — Frosted Glass ─── */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 100;
            background: rgba(255,255,255,0.82);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-top: 1px solid rgba(226,232,240,0.6);
            padding: 6px 12px calc(6px + var(--safe-bottom));
            display: flex; justify-content: space-around;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.05);
        }
        .nav-item {
            display: flex; flex-direction: column; align-items: center; gap: 3px;
            font-size: 0.6rem; font-weight: 700; color: var(--muted); text-decoration: none;
            padding: 8px 14px; border-radius: 14px; transition: all 0.25s ease;
            border: none; background: none; cursor: pointer;
            position: relative;
        }
        .nav-item.active {
            color: var(--primary);
            background: rgba(79,70,229,0.08);
        }
        .nav-item.active::before {
            content: '';
            position: absolute; top: -6px; left: 50%; transform: translateX(-50%);
            width: 20px; height: 3px; border-radius: 2px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            box-shadow: 0 1px 6px rgba(79,70,229,0.3);
        }
        .nav-item i { font-size: 1.15rem; transition: transform 0.2s; }
        .nav-item:active i { transform: scale(0.9); }

        /* Pull to refresh */
        .refresh-indicator {
            text-align: center; padding: 12px; font-size: 0.75rem; color: var(--muted); font-weight: 600; display: none;
        }
        .refresh-indicator.visible { display: block; }

        /* ─── Filter Panel — Glass Slide-up ─── */
        .filter-panel {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 200;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
            border-radius: 24px 24px 0 0;
            padding: 20px 20px calc(20px + var(--safe-bottom));
            box-shadow: 0 -10px 50px rgba(0,0,0,0.12);
            transform: translateY(100%); transition: transform 0.35s cubic-bezier(0.32, 0.72, 0, 1);
        }
        .filter-panel.open { transform: translateY(0); }
        .filter-backdrop {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 199;
            background: rgba(15,23,42,0.35);
            backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
            opacity: 0; pointer-events: none; transition: opacity 0.3s;
        }
        .filter-backdrop.open { opacity: 1; pointer-events: auto; }
        .filter-handle {
            width: 40px; height: 4px; border-radius: 2px; margin: 0 auto 18px;
            background: linear-gradient(90deg, #cbd5e1, #94a3b8, #cbd5e1);
        }
        .filter-title { font-size: 1rem; font-weight: 800; margin-bottom: 16px; letter-spacing: -0.02em; }
        .filter-option {
            padding: 15px 18px; border-radius: 14px; font-size: 0.86rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s; display: flex; justify-content: space-between; align-items: center;
        }
        .filter-option:hover { background: var(--bg); }
        .filter-option.selected {
            background: linear-gradient(135deg, rgba(79,70,229,0.06), rgba(129,140,248,0.1));
            color: var(--primary);
        }
        .filter-option .check { display: none; }
        .filter-option.selected .check { display: inline; }
        .filter-list { max-height: 50vh; overflow-y: auto; }

        /* Notification bell */
        .header-btn { position: relative; }
        .notif-dot {
            position: absolute; top: 6px; right: 6px; width: 8px; height: 8px;
            border-radius: 50%; background: #ef4444; display: none;
            box-shadow: 0 0 8px rgba(239,68,68,0.6);
        }
        .notif-dot.active { display: block; animation: pulse 1.5s infinite; }

        /* Toast — Glassmorphism */
        .notif-toast {
            position: fixed; top: -70px; left: 50%; transform: translateX(-50%);
            background: rgba(15,23,42,0.88);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            color: #fff; padding: 14px 22px; border-radius: 18px;
            font-size: 0.82rem; font-weight: 600; z-index: 300; display: flex;
            align-items: center; gap: 10px;
            transition: top 0.4s cubic-bezier(0.32, 0.72, 0, 1);
            box-shadow: 0 12px 40px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.06);
        }
        .notif-toast.show { top: calc(20px + var(--safe-top)); }
        .notif-toast .fa-check-circle { color: #4ade80; filter: drop-shadow(0 0 4px rgba(74,222,128,0.5)); }
        .notif-toast .fa-times-circle { color: #f87171; filter: drop-shadow(0 0 4px rgba(248,113,113,0.5)); }

        /* ─── Check Absence Button — Premium ─── */
        .absence-btn {
            width: 100%; padding: 16px; border: none; border-radius: 18px;
            font-size: 0.88rem; font-weight: 700; font-family: 'Inter', sans-serif;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px;
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 50%, #f87171 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(220,38,38,0.3), 0 1px 3px rgba(220,38,38,0.2);
            transition: all 0.25s ease;
            position: relative; overflow: hidden;
        }
        .absence-btn::before {
            content: '';
            position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: left 0.5s ease;
        }
        .absence-btn:active {
            transform: scale(0.97);
            box-shadow: 0 2px 8px rgba(220,38,38,0.2);
        }
        .absence-btn:active::before { left: 100%; }

        /* ─── Greeting Section ─── */
        .greeting {
            padding: 0 0 4px;
            animation: fadeSlideUp 0.4s ease;
        }
        .greeting-text {
            font-size: 0.82rem; color: var(--muted); font-weight: 600;
        }
        .greeting-name {
            font-size: 1.25rem; font-weight: 900; letter-spacing: -0.03em;
            background: linear-gradient(135deg, var(--text) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
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

        <!-- Greeting -->
        <div class="greeting">
            <div class="greeting-text"><?php
                $hour = (int) date('G');
                echo $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
            ?> 👋</div>
            <div class="greeting-name"><?= htmlspecialchars($admin_name) ?></div>
        </div>

        <!-- Attendance Ring -->
        <div class="ring-card">
            <div class="ring-wrap">
                <svg viewBox="0 0 120 120">
                    <circle class="ring-bg" cx="60" cy="60" r="52"/>
                    <?php
                        $circumference = 2 * M_PI * 52;
                        $offset = $circumference - ($attendance_rate / 100) * $circumference;
                        $ring_color = $attendance_rate >= 80 ? '#059669' : ($attendance_rate >= 50 ? '#d97706' : '#dc2626');
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
            <div class="ring-subtitle">
                <strong><?= $timed_in_today ?></strong> of <strong><?= $relevant_students ?></strong> students present<?php if ($filter_school && $schools_list): ?> · <em>filtered</em><?php endif; ?>
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

    <!-- CHECK ABSENCES BUTTON -->
    <div class="content" style="padding:0 16px 8px;">
        <button onclick="checkAbsences()" id="checkAbsBtn" class="absence-btn">
            <i class="fas fa-bell"></i> Check & Send Absence Alerts
        </button>
        <div id="checkResult" style="margin-top:10px;font-size:0.78rem;color:var(--muted);text-align:center;font-weight:500;"></div>
    </div>

    <!-- BOTTOM NAV -->
    <nav class="bottom-nav">
        <a href="app_dashboard.php" class="nav-item active"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a href="admin/attendance.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Attendance</a>
        <a href="admin/school_browser.php" class="nav-item"><i class="fas fa-school"></i> Schools</a>
        <a href="admin/reports.php" class="nav-item"><i class="fas fa-file-alt"></i> Reports</a>
        <a href="Qrscanattendance.php" class="nav-item"><i class="fas fa-qrcode"></i> Scanner</a>
    </nav>

    <script>
    // Unregister any leftover service workers from old PWA
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(regs => {
            regs.forEach(r => r.unregister());
        });
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
        btn.innerHTML = '<i class="fas fa-bell"></i> Check & Send Absence Alerts';
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
