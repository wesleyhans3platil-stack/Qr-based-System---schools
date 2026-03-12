<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * MOBILE DASHBOARD — Material Design 3
 * ══════════════════════════════════════════════════════════════════
 * Full super-admin dashboard for mobile / WebView.
 * Flutter-inspired Material Design 3 interface.
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
$filter_date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) $filter_date = $today;
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

$school_filter_sql = '';
if ($admin_role === 'principal' && $admin_school_id) {
    $school_filter_sql = " AND school_id = " . (int)$admin_school_id . " ";
}

$filter_school = (int)($_GET['school'] ?? 0);
$extra_filter = '';
if ($filter_school) $extra_filter .= " AND school_id = $filter_school ";

// ─── Summary Stats ───
$total_schools = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM schools WHERE status='active' $school_filter_sql");
if ($r) $total_schools = $r->fetch_assoc()['cnt'];

$total_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = " . (int)$admin_school_id : ""));
if ($r) $total_students = $r->fetch_assoc()['cnt'];

$total_teachers = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = " . (int)$admin_school_id : ""));
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

// Students timed in today (only active students via JOIN)
$timed_in_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_in_today = $r->fetch_assoc()['cnt'];

// Students timed out today (only active)
$timed_out_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_out IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_out_today = $r->fetch_assoc()['cnt'];

// Relevant students (exclude newly created unless they have attendance)
$relevant_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND (DATE(created_at) < '$filter_date' OR id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)) " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = " . (int)$admin_school_id . " " : "") . ($filter_school ? "AND school_id = $filter_school" : ""));
if ($r) $relevant_students = $r->fetch_assoc()['cnt'];
$absent_today = max(0, $relevant_students - $timed_in_today);
$attendance_rate = $relevant_students > 0 ? round(($timed_in_today / $relevant_students) * 100, 1) : 0;

// Teachers timed in (only active)
$teachers_in = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $teachers_in = $r->fetch_assoc()['cnt'];

// ─── 2-Day Consecutive Absence Flag (full details like website) ───
$yesterday = date('Y-m-d', strtotime('-1 day', strtotime($filter_date)));
$flagged_students = [];
$flag_sql = "SELECT s.id, s.lrn, s.name, sch.name as school_name, sch.code as school_code, gl.name as grade_name, sec.name as section_name
    FROM students s
    LEFT JOIN schools sch ON s.school_id = sch.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.status = 'active'
    AND DATE(s.created_at) < '$filter_date'
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date')
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday')
    " . ($admin_role === 'principal' && $admin_school_id ? "AND s.school_id = " . (int)$admin_school_id : "") . "
    " . ($filter_school ? "AND s.school_id = $filter_school" : "") . "
    ORDER BY sch.name, gl.id, s.name
    LIMIT 100";
$r = $conn->query($flag_sql);
if ($r) { while ($row = $r->fetch_assoc()) $flagged_students[] = $row; }
$flag_count = count($flagged_students);

// ─── Per-School Breakdown (accurate queries matching website) ───
$school_breakdown = [];
$school_sql = "SELECT s.id, s.name, s.code,
    (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active' AND (DATE(st.created_at) < '$filter_date' OR st.id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL))) as enrolled,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as teachers_present,
    (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as total_teachers
    FROM schools s WHERE s.status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND s.id = " . (int)$admin_school_id : "") . "
    ORDER BY s.name";
$r = $conn->query($school_sql);
if ($r) { while ($row = $r->fetch_assoc()) { $row['present'] = min($row['present'], $row['enrolled']); $row['absent'] = max(0, $row['enrolled'] - $row['present']); $row['rate'] = $row['enrolled'] > 0 ? min(100, round(($row['present'] / $row['enrolled']) * 100, 1)) : 0; $school_breakdown[] = $row; } }

// Schools list for filter
$schools_list = [];
$r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name");
if ($r) { while ($row = $r->fetch_assoc()) $schools_list[] = $row; }

// System logo
$systemLogo = '';
$lr = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'");
if ($lr && $lrow = $lr->fetch_assoc()) {
    $lf = $lrow['setting_value'] ?? '';
    if ($lf && file_exists(__DIR__ . '/assets/uploads/logos/' . basename($lf))) $systemLogo = 'assets/uploads/logos/' . basename($lf);
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
    <meta name="theme-color" content="#059669">
    <title>Dashboard — EduTrack</title>
    <?php if ($systemLogo): ?><link rel="icon" type="image/png" href="<?= $systemLogo ?>"><?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        :root{
            --md-primary:#059669;--md-on-primary:#fff;
            --md-primary-container:#d1fae5;--md-on-primary-container:#022c22;
            --md-secondary:#065f46;--md-surface:#fafdfb;--md-surface-container:#f0fdf4;
            --md-surface-container-high:#e8f8ee;--md-on-surface:#1a1c1a;--md-on-surface-variant:#414942;
            --md-outline:#71806f;--md-outline-variant:#c1c9bf;
            --md-error:#dc2626;--md-error-container:#fee2e2;--md-on-error:#fff;
            --md-tertiary:#d97706;--md-tertiary-container:#fef3c7;
            --md-inverse-surface:#2f312f;--md-inverse-on-surface:#f0f1ec;
            --el1:0 1px 3px 1px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.1);
            --el2:0 2px 6px 2px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.1);
            --el3:0 4px 8px 3px rgba(0,0,0,.08),0 1px 3px rgba(0,0,0,.1);
            --safe-top:env(safe-area-inset-top,0px);--safe-bottom:env(safe-area-inset-bottom,0px);
        }
        html,body{font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:var(--md-surface);color:var(--md-on-surface);min-height:100vh;overflow-x:hidden}

        /* ─── M3 Top App Bar ─── */
        .app-bar{
            position:sticky;top:0;z-index:100;
            background:var(--md-primary);color:var(--md-on-primary);
            padding:calc(12px + var(--safe-top)) 16px 16px;
        }
        .app-bar-row{display:flex;align-items:center;justify-content:space-between}
        .app-bar-brand{display:flex;align-items:center;gap:12px}
        .app-bar-logo{
            width:40px;height:40px;border-radius:12px;overflow:hidden;
            background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;
        }
        .app-bar-logo img{width:100%;height:100%;object-fit:cover}
        .app-bar-logo i{font-size:1.1rem;color:#fff}
        .app-bar-title{font-size:1.05rem;font-weight:700;letter-spacing:-.02em}
        .app-bar-subtitle{font-size:.7rem;opacity:.85;font-weight:500;margin-top:1px}
        .app-bar-actions{display:flex;gap:8px}
        .icon-btn{
            width:40px;height:40px;border-radius:20px;border:none;
            background:rgba(255,255,255,.12);color:#fff;font-size:.95rem;
            cursor:pointer;display:flex;align-items:center;justify-content:center;
            transition:background .2s;text-decoration:none;
        }
        .icon-btn:active{background:rgba(255,255,255,.24)}

        /* Date bar */
        .date-bar{display:flex;align-items:center;gap:8px;margin-top:14px}
        .date-chip{
            display:flex;align-items:center;gap:6px;font-size:.75rem;font-weight:600;
            background:rgba(255,255,255,.12);padding:8px 14px;border-radius:8px;
        }
        .date-chip i{font-size:.7rem;opacity:.8}
        .date-input{
            background:rgba(255,255,255,.12);border:none;color:#fff;padding:8px 12px;
            border-radius:8px;font-size:.75rem;font-family:'Inter',sans-serif;font-weight:600;
            outline:none;color-scheme:dark;
        }
        .live-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;animation:pulse 1.5s infinite;box-shadow:0 0 8px rgba(74,222,128,.6)}

        /* ─── No-Class Banner ─── */
        .no-class-banner{
            margin:12px 16px 0;padding:14px 16px;border-radius:12px;
            background:var(--md-tertiary-container);display:flex;align-items:center;gap:12px;
            box-shadow:var(--el1);
        }
        .no-class-banner i{font-size:1.3rem;color:var(--md-tertiary);flex-shrink:0}
        .no-class-banner strong{color:#92400e;font-size:.84rem;display:block}
        .no-class-banner p{color:#a16207;font-size:.74rem;margin-top:2px}

        /* ─── Content ─── */
        .content{padding:16px 16px calc(84px + var(--safe-bottom))}

        /* ─── Greeting ─── */
        .greeting{margin-bottom:16px;animation:fadeUp .4s ease}
        .greeting-sub{font-size:.78rem;color:var(--md-on-surface-variant);font-weight:500}
        .greeting-name{font-size:1.2rem;font-weight:800;letter-spacing:-.03em;color:var(--md-on-surface)}

        /* ─── M3 Card ─── */
        .m3-card{
            background:#fff;border-radius:16px;padding:20px;margin-bottom:12px;
            box-shadow:var(--el1);position:relative;overflow:hidden;
            animation:fadeUp .4s ease backwards;
        }

        /* ─── Ring Chart Card ─── */
        .ring-card{text-align:center;padding:24px 20px}
        .ring-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--md-primary),#34d399,var(--md-primary));border-radius:16px 16px 0 0}
        .ring-wrap{position:relative;width:160px;height:160px;margin:0 auto 16px}
        .ring-wrap svg{width:100%;height:100%;transform:rotate(-90deg)}
        .ring-bg{fill:none;stroke:var(--md-primary-container);stroke-width:10}
        .ring-fill{fill:none;stroke-width:10;stroke-linecap:round;transition:stroke-dashoffset 1s cubic-bezier(.4,0,.2,1)}
        .ring-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center}
        .ring-pct{font-size:2.4rem;font-weight:900;letter-spacing:-2px;color:var(--md-on-surface)}
        .ring-label{font-size:.65rem;color:var(--md-on-surface-variant);font-weight:700;text-transform:uppercase;letter-spacing:.6px;margin-top:2px}
        .ring-sub{font-size:.78rem;color:var(--md-on-surface-variant);font-weight:500}
        .ring-sub strong{color:var(--md-on-surface)}

        /* ─── Stat Grid — M3 Filled Cards ─── */
        .stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
        .stat-card{
            background:#fff;border-radius:16px;padding:16px 14px;
            display:flex;align-items:center;gap:12px;
            box-shadow:var(--el1);transition:transform .15s;
            animation:fadeUp .4s ease backwards;
        }
        .stat-card:active{transform:scale(.97)}
        .stat-card:nth-child(1){animation-delay:.04s}
        .stat-card:nth-child(2){animation-delay:.06s}
        .stat-card:nth-child(3){animation-delay:.08s}
        .stat-card:nth-child(4){animation-delay:.1s}
        .stat-card:nth-child(5){animation-delay:.12s}
        .stat-card:nth-child(6){animation-delay:.14s}
        .stat-icon{
            width:44px;height:44px;border-radius:14px;display:flex;
            align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0;
        }
        .stat-icon.green{background:linear-gradient(135deg,#ecfdf5,#d1fae5);color:#059669}
        .stat-icon.red{background:linear-gradient(135deg,#fef2f2,#fee2e2);color:#dc2626}
        .stat-icon.amber{background:linear-gradient(135deg,#fffbeb,#fef3c7);color:#d97706}
        .stat-icon.blue{background:linear-gradient(135deg,#eff6ff,#dbeafe);color:#2563eb}
        .stat-icon.teal{background:linear-gradient(135deg,#f0fdfa,#ccfbf1);color:#0d9488}
        .stat-val{font-size:1.4rem;font-weight:900;letter-spacing:-1px;line-height:1;color:var(--md-on-surface)}
        .stat-label{font-size:.62rem;color:var(--md-on-surface-variant);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:2px}

        /* ─── Section Header ─── */
        .section-hdr{
            font-size:.72rem;font-weight:700;color:var(--md-on-surface-variant);text-transform:uppercase;
            letter-spacing:.8px;margin:20px 0 12px;display:flex;align-items:center;gap:8px;
        }
        .section-hdr i{font-size:.68rem;color:var(--md-primary)}
        .section-hdr::after{content:'';flex:1;height:1px;background:var(--md-outline-variant)}
        .section-hdr .badge{
            font-size:.6rem;font-weight:700;padding:3px 8px;border-radius:10px;
            background:var(--md-primary-container);color:var(--md-on-primary-container);
        }

        /* ─── School Filter ─── */
        .filter-btn{
            width:100%;background:#fff;border:1px solid var(--md-outline-variant);
            padding:13px 16px;border-radius:12px;font-size:.84rem;font-family:'Inter',sans-serif;
            font-weight:600;color:var(--md-on-surface);cursor:pointer;
            box-shadow:var(--el1);appearance:none;text-align:left;
            display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;
        }
        .filter-btn i{color:var(--md-on-surface-variant);font-size:.7rem}
        .filter-btn:active{background:var(--md-surface-container)}

        /* ─── School Breakdown Cards ─── */
        .school-card{
            background:#fff;border-radius:16px;padding:16px;margin-bottom:10px;
            box-shadow:var(--el1);border-left:3px solid var(--md-primary);
            animation:fadeUp .35s ease backwards;
        }
        .sc-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
        .sc-name{font-size:.88rem;font-weight:700;color:var(--md-on-surface);line-height:1.25}
        .sc-code{
            font-size:.6rem;font-weight:700;background:var(--md-primary-container);
            color:var(--md-on-primary-container);padding:4px 10px;border-radius:8px;white-space:nowrap;
        }
        .sc-bar{height:6px;background:var(--md-primary-container);border-radius:3px;overflow:hidden;margin-bottom:12px}
        .sc-bar .fill{height:100%;border-radius:3px;transition:width .8s cubic-bezier(.4,0,.2,1)}
        .sc-stats{display:flex;gap:0}
        .sc-stat{text-align:center;flex:1;padding:6px 0}
        .sc-stat .v{font-size:1rem;font-weight:800}
        .sc-stat .l{font-size:.58rem;color:var(--md-on-surface-variant);font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-top:1px}
        .v-green{color:#059669}.v-red{color:#dc2626}.v-blue{color:#2563eb}.v-pct{color:var(--md-on-surface)}

        /* ─── Flagged Students Section ─── */
        .expand-toggle{
            display:flex;align-items:center;justify-content:space-between;
            width:100%;background:none;border:none;cursor:pointer;padding:0;
            font-family:'Inter',sans-serif;color:var(--md-on-surface);
        }
        .expand-toggle .toggle-icon{
            width:28px;height:28px;border-radius:14px;display:flex;align-items:center;justify-content:center;
            background:var(--md-surface-container);font-size:.7rem;color:var(--md-on-surface-variant);
            transition:transform .3s ease;
        }
        .expand-toggle.open .toggle-icon{transform:rotate(180deg)}
        .expandable{max-height:0;overflow:hidden;transition:max-height .35s cubic-bezier(.4,0,.2,1)}
        .expandable.open{max-height:2000px}
        .flag-item{
            display:flex;justify-content:space-between;align-items:center;
            padding:12px 0;border-bottom:1px solid var(--md-surface-container-high);
        }
        .flag-item:last-child{border-bottom:none}
        .flag-name{font-size:.84rem;font-weight:600;color:var(--md-on-surface)}
        .flag-meta{font-size:.7rem;color:var(--md-on-surface-variant);margin-top:2px}
        .flag-school{
            font-size:.58rem;font-weight:700;padding:3px 8px;border-radius:6px;
            background:var(--md-surface-container-high);color:var(--md-on-surface-variant);
        }
        .flag-badge{font-size:.65rem;color:var(--md-tertiary);font-weight:700;margin-top:3px}
        .flag-empty{text-align:center;padding:24px;color:var(--md-on-surface-variant)}
        .flag-empty i{font-size:1.5rem;color:#059669;opacity:.4;display:block;margin-bottom:8px}

        /* ─── Teacher Attendance Section ─── */
        .teacher-row{
            display:flex;align-items:center;justify-content:space-between;
            padding:12px 0;border-bottom:1px solid var(--md-surface-container-high);
        }
        .teacher-row:last-child{border-bottom:none}
        .teacher-school{font-size:.84rem;font-weight:600;color:var(--md-on-surface)}
        .teacher-count{font-size:.7rem;color:var(--md-on-surface-variant);margin-top:2px}
        .teacher-pct{font-size:1rem;font-weight:800}

        /* ─── Absence Alert Button ─── */
        .alert-btn{
            width:100%;padding:14px;border:none;border-radius:12px;
            font-size:.86rem;font-weight:700;font-family:'Inter',sans-serif;
            cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
            background:var(--md-error);color:var(--md-on-error);
            box-shadow:var(--el2);transition:transform .15s,box-shadow .15s;margin-top:8px;
        }
        .alert-btn:active{transform:scale(.97);box-shadow:var(--el1)}
        .alert-btn:disabled{opacity:.7}
        #checkResult{margin-top:8px;font-size:.76rem;color:var(--md-on-surface-variant);text-align:center;font-weight:500}

        /* ─── M3 Navigation Bar ─── */
        .nav-bar{
            position:fixed;bottom:0;left:0;right:0;z-index:100;
            background:var(--md-surface);
            border-top:1px solid rgba(0,0,0,.06);
            padding:0 8px var(--safe-bottom);height:calc(80px + var(--safe-bottom));
            display:flex;justify-content:space-around;align-items:stretch;
        }
        .nav-item{
            display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;
            font-size:.6rem;font-weight:600;color:var(--md-on-surface-variant);text-decoration:none;
            padding:0 12px;border:none;background:none;cursor:pointer;position:relative;flex:1;
        }
        .nav-item i{font-size:1.15rem;z-index:1;transition:transform .2s}
        .nav-item span{z-index:1}
        .nav-item.active{color:var(--md-on-primary-container)}
        .nav-item .indicator{
            position:absolute;top:14px;width:56px;height:28px;border-radius:14px;
            background:var(--md-primary-container);opacity:0;transition:opacity .2s;
        }
        .nav-item.active .indicator{opacity:1}
        .nav-item:active i{transform:scale(.85)}

        /* ─── Filter Panel ─── */
        .filter-backdrop{
            position:fixed;top:0;left:0;right:0;bottom:0;z-index:199;
            background:rgba(0,0,0,.32);opacity:0;pointer-events:none;transition:opacity .3s;
        }
        .filter-backdrop.open{opacity:1;pointer-events:auto}
        .filter-panel{
            position:fixed;bottom:0;left:0;right:0;z-index:200;
            background:var(--md-surface);border-radius:28px 28px 0 0;
            padding:0 24px calc(20px + var(--safe-bottom));
            box-shadow:0 -8px 40px rgba(0,0,0,.12);
            transform:translateY(100%);transition:transform .35s cubic-bezier(.32,.72,0,1);
        }
        .filter-panel.open{transform:translateY(0)}
        .filter-handle{width:32px;height:4px;border-radius:2px;margin:12px auto 20px;background:var(--md-outline-variant)}
        .filter-title{font-size:1rem;font-weight:700;margin-bottom:16px;color:var(--md-on-surface)}
        .filter-option{
            padding:14px 16px;border-radius:12px;font-size:.84rem;font-weight:600;
            cursor:pointer;display:flex;justify-content:space-between;align-items:center;
            transition:background .15s;color:var(--md-on-surface);
        }
        .filter-option:active{background:var(--md-surface-container)}
        .filter-option.selected{background:var(--md-primary-container);color:var(--md-on-primary-container)}
        .filter-option .check{display:none}
        .filter-option.selected .check{display:inline}
        .filter-list{max-height:50vh;overflow-y:auto}

        /* ─── Toast ─── */
        .toast{
            position:fixed;top:-70px;left:50%;transform:translateX(-50%);
            background:var(--md-inverse-surface);color:var(--md-inverse-on-surface);
            padding:12px 20px;border-radius:12px;font-size:.82rem;font-weight:600;z-index:300;
            display:flex;align-items:center;gap:8px;
            transition:top .4s cubic-bezier(.32,.72,0,1);box-shadow:var(--el3);
        }
        .toast.show{top:calc(16px + var(--safe-top))}
        .toast .fa-check-circle{color:#4ade80}
        .toast .fa-times-circle{color:#f87171}

        /* Animations */
        @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
    </style>
</head>
<body>

    <!-- ══════ M3 TOP APP BAR ══════ -->
    <header class="app-bar">
        <div class="app-bar-row">
            <div class="app-bar-brand">
                <div class="app-bar-logo">
                    <?php if ($systemLogo): ?>
                        <img src="<?= htmlspecialchars($systemLogo) ?>" alt="Logo">
                    <?php else: ?>
                        <i class="fas fa-chart-pie"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="app-bar-title">Attendance Dashboard</div>
                    <div class="app-bar-subtitle"><?= htmlspecialchars($admin_name) ?> · <?= ucfirst(str_replace('_', ' ', $admin_role)) ?></div>
                </div>
            </div>
            <div class="app-bar-actions">
                <button class="icon-btn" onclick="location.reload()" title="Refresh"><i class="fas fa-sync-alt"></i></button>
                <a href="admin/logout.php" class="icon-btn" title="Sign out"><i class="fas fa-right-from-bracket"></i></a>
            </div>
        </div>
        <div class="date-bar">
            <?php if ($is_today): ?>
                <div class="live-dot"></div>
                <div class="date-chip"><i class="fas fa-clock"></i> Live — <?= date('D, M j') ?></div>
            <?php else: ?>
                <div class="date-chip"><i class="fas fa-calendar"></i> <?= date('D, M j, Y', strtotime($filter_date)) ?></div>
            <?php endif; ?>
            <input type="date" class="date-input" value="<?= htmlspecialchars($filter_date) ?>" onchange="applyDate(this.value)">
        </div>
    </header>

    <!-- ══════ NO CLASS NOTICE ══════ -->
    <?php if ($non_school): ?>
    <div class="no-class-banner">
        <i class="fas fa-calendar-xmark"></i>
        <div>
            <strong>No Classes Today</strong>
            <p><?= htmlspecialchars($non_school_reason ?? 'Non-school day') ?> — Data shown for reference only.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════ MAIN CONTENT ══════ -->
    <div class="content">

        <!-- Greeting -->
        <div class="greeting">
            <div class="greeting-sub"><?php
                $hour = (int)date('G');
                echo $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
            ?> 👋</div>
            <div class="greeting-name"><?= htmlspecialchars($admin_name) ?></div>
        </div>

        <!-- ── Attendance Ring ── -->
        <div class="m3-card ring-card">
            <div class="ring-wrap">
                <svg viewBox="0 0 120 120">
                    <circle class="ring-bg" cx="60" cy="60" r="52"/>
                    <?php
                        $circ = 2 * M_PI * 52;
                        $offset = $circ - ($attendance_rate / 100) * $circ;
                        $rcolor = $attendance_rate >= 80 ? '#059669' : ($attendance_rate >= 50 ? '#d97706' : '#dc2626');
                    ?>
                    <circle class="ring-fill" cx="60" cy="60" r="52"
                        stroke="<?= $rcolor ?>"
                        stroke-dasharray="<?= $circ ?>"
                        stroke-dashoffset="<?= $offset ?>"/>
                </svg>
                <div class="ring-center">
                    <div class="ring-pct"><?= $attendance_rate ?>%</div>
                    <div class="ring-label">Attendance</div>
                </div>
            </div>
            <div class="ring-sub"><strong><?= $timed_in_today ?></strong> of <strong><?= $relevant_students ?></strong> students present<?php if ($filter_school): ?> · <em>filtered</em><?php endif; ?></div>
        </div>

        <!-- ── Quick Stats (2x3 grid) ── -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                <div><div class="stat-val"><?= $timed_in_today ?></div><div class="stat-label">Present</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-user-xmark"></i></div>
                <div><div class="stat-val"><?= $absent_today ?></div><div class="stat-label">Absent</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon amber"><i class="fas fa-triangle-exclamation"></i></div>
                <div><div class="stat-val"><?= $flag_count ?></div><div class="stat-label">2-Day Flag</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-chalkboard-teacher"></i></div>
                <div><div class="stat-val"><?= $teachers_in ?><span style="font-size:.7rem;font-weight:600;color:var(--md-on-surface-variant);">/<?= $total_teachers ?></span></div><div class="stat-label">Teachers</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-school"></i></div>
                <div><div class="stat-val"><?= $total_schools ?></div><div class="stat-label">Schools</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon teal"><i class="fas fa-arrow-right-from-bracket"></i></div>
                <div><div class="stat-val"><?= $timed_out_today ?></div><div class="stat-label">Timed Out</div></div>
            </div>
        </div>

        <!-- ── School Filter ── -->
        <?php if ($admin_role !== 'principal' && count($schools_list) > 1): ?>
        <button class="filter-btn" onclick="openFilter()">
            <span><?= $filter_school ? htmlspecialchars(array_values(array_filter($schools_list, fn($s) => $s['id'] == $filter_school))[0]['name'] ?? 'All Schools') : 'All Schools' ?></span>
            <i class="fas fa-chevron-down"></i>
        </button>
        <?php endif; ?>

        <!-- ══════ SCHOOL BREAKDOWN ══════ -->
        <div class="section-hdr"><i class="fas fa-school"></i> School Attendance Breakdown</div>
        <?php if (empty($school_breakdown)): ?>
            <div class="m3-card" style="text-align:center;color:var(--md-on-surface-variant);padding:28px;">No schools found.</div>
        <?php else: foreach ($school_breakdown as $i => $sb): ?>
        <div class="school-card" style="animation-delay:<?= $i * .03 ?>s">
            <div class="sc-top">
                <div class="sc-name"><?= htmlspecialchars($sb['name']) ?></div>
                <span class="sc-code"><?= htmlspecialchars($sb['code']) ?></span>
            </div>
            <div class="sc-bar">
                <div class="fill" style="width:<?= $sb['rate'] ?>%;background:<?= $sb['rate'] >= 80 ? '#059669' : ($sb['rate'] >= 50 ? '#d97706' : '#dc2626') ?>;"></div>
            </div>
            <div class="sc-stats">
                <div class="sc-stat"><div class="v v-green"><?= $sb['present'] ?></div><div class="l">Present</div></div>
                <div class="sc-stat"><div class="v v-red"><?= $sb['absent'] ?></div><div class="l">Absent</div></div>
                <div class="sc-stat"><div class="v v-pct" style="color:<?= $sb['rate'] >= 80 ? '#059669' : ($sb['rate'] >= 50 ? '#d97706' : '#dc2626') ?>"><?= $sb['rate'] ?>%</div><div class="l">Rate</div></div>
                <div class="sc-stat"><div class="v v-blue"><?= $sb['teachers_present'] ?>/<?= $sb['total_teachers'] ?></div><div class="l">Teachers</div></div>
            </div>
        </div>
        <?php endforeach; endif; ?>

        <!-- ══════ 2-DAY FLAGGED STUDENTS (NEW — from website) ══════ -->
        <div class="section-hdr">
            <i class="fas fa-exclamation-triangle" style="color:var(--md-tertiary)"></i> 2-Day Consecutive Absences
            <span class="badge" style="background:var(--md-tertiary-container);color:#92400e;"><?= $flag_count ?></span>
        </div>
        <div class="m3-card" style="padding:16px">
            <button class="expand-toggle" id="flagToggle" onclick="toggleSection('flag')">
                <span style="font-size:.84rem;font-weight:700;">
                    <?= $flag_count > 0 ? $flag_count . ' student' . ($flag_count > 1 ? 's' : '') . ' flagged' : 'No flags — all good!' ?>
                </span>
                <?php if ($flag_count > 0): ?>
                <div class="toggle-icon"><i class="fas fa-chevron-down"></i></div>
                <?php endif; ?>
            </button>
            <?php if ($flag_count > 0): ?>
            <div class="expandable" id="flagList">
                <?php foreach ($flagged_students as $fs): ?>
                <div class="flag-item">
                    <div>
                        <div class="flag-name"><?= htmlspecialchars($fs['name']) ?></div>
                        <div class="flag-meta">LRN: <?= htmlspecialchars($fs['lrn']) ?> · <?= htmlspecialchars($fs['grade_name'] ?? '') ?> — <?= htmlspecialchars($fs['section_name'] ?? '') ?></div>
                    </div>
                    <div style="text-align:right;">
                        <span class="flag-school"><?= htmlspecialchars($fs['school_code'] ?? '') ?></span>
                        <div class="flag-badge">2+ days</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="flag-empty">
                <i class="fas fa-check-circle"></i>
                All students have been attending.
            </div>
            <?php endif; ?>
        </div>

        <!-- ══════ TEACHER ATTENDANCE (NEW — from website) ══════ -->
        <div class="section-hdr"><i class="fas fa-chalkboard-teacher" style="color:#2563eb"></i> Teacher Attendance</div>
        <div class="m3-card" style="padding:16px">
            <?php if (empty($school_breakdown)): ?>
                <div style="text-align:center;padding:20px;color:var(--md-on-surface-variant);font-size:.84rem;">No data.</div>
            <?php else: ?>
                <button class="expand-toggle" id="teacherToggle" onclick="toggleSection('teacher')">
                    <span style="font-size:.84rem;font-weight:700;"><?= $teachers_in ?> of <?= $total_teachers ?> teachers present</span>
                    <div class="toggle-icon"><i class="fas fa-chevron-down"></i></div>
                </button>
                <div class="expandable" id="teacherList">
                    <?php foreach ($school_breakdown as $sb): ?>
                    <div class="teacher-row">
                        <div>
                            <div class="teacher-school"><?= htmlspecialchars($sb['name']) ?></div>
                            <div class="teacher-count"><?= $sb['teachers_present'] ?> of <?= $sb['total_teachers'] ?> present</div>
                        </div>
                        <div class="teacher-pct" style="color:<?= $sb['total_teachers'] > 0 && $sb['teachers_present'] == $sb['total_teachers'] ? '#059669' : '#d97706' ?>">
                            <?= $sb['total_teachers'] > 0 ? round(($sb['teachers_present'] / $sb['total_teachers']) * 100) . '%' : '—' ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ══════ CHECK ABSENCES ══════ -->
        <div style="margin-top:16px;">
            <button onclick="checkAbsences()" id="checkAbsBtn" class="alert-btn">
                <i class="fas fa-bell"></i> Check & Send Absence Alerts
            </button>
            <div id="checkResult"></div>
        </div>

    </div><!-- end .content -->

    <!-- ══════ FILTER PANEL (Bottom Sheet) ══════ -->
    <div class="filter-backdrop" id="filterBackdrop" onclick="closeFilter()"></div>
    <div class="filter-panel" id="filterPanel">
        <div class="filter-handle"></div>
        <div class="filter-title">Select School</div>
        <div class="filter-list">
            <div class="filter-option <?= !$filter_school ? 'selected' : '' ?>" onclick="applySchool(0)">
                All Schools <i class="fas fa-check check"></i>
            </div>
            <?php foreach ($schools_list as $sch): ?>
            <div class="filter-option <?= $filter_school == $sch['id'] ? 'selected' : '' ?>" onclick="applySchool(<?= (int)$sch['id'] ?>)">
                <?= htmlspecialchars($sch['name']) ?> <i class="fas fa-check check"></i>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══════ TOAST ══════ -->
    <div class="toast" id="toast">
        <i class="fas fa-check-circle" id="toastIcon"></i>
        <span id="toastMsg"></span>
    </div>

    <!-- ══════ M3 NAVIGATION BAR ══════ -->
    <nav class="nav-bar">
        <a href="app_dashboard.php" class="nav-item active">
            <div class="indicator"></div>
            <i class="fas fa-chart-pie"></i>
            <span>Dashboard</span>
        </a>
        <a href="admin/attendance.php" class="nav-item">
            <div class="indicator"></div>
            <i class="fas fa-clipboard-list"></i>
            <span>Attendance</span>
        </a>
        <a href="admin/school_browser.php" class="nav-item">
            <div class="indicator"></div>
            <i class="fas fa-school"></i>
            <span>Schools</span>
        </a>
        <a href="admin/reports.php" class="nav-item">
            <div class="indicator"></div>
            <i class="fas fa-file-alt"></i>
            <span>Reports</span>
        </a>
        <a href="Qrscanattendance.php" class="nav-item">
            <div class="indicator"></div>
            <i class="fas fa-qrcode"></i>
            <span>Scanner</span>
        </a>
    </nav>

    <script>
    // Unregister leftover service workers
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(r => r.forEach(reg => reg.unregister()));
    }

    function showToast(msg, success) {
        const t = document.getElementById('toast');
        document.getElementById('toastIcon').className = success ? 'fas fa-check-circle' : 'fas fa-times-circle';
        document.getElementById('toastMsg').textContent = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3000);
    }

    // ── Expand / Collapse sections ──
    function toggleSection(id) {
        const toggle = document.getElementById(id + 'Toggle');
        const list = document.getElementById(id + 'List');
        if (!toggle || !list) return;
        const isOpen = list.classList.contains('open');
        list.classList.toggle('open');
        toggle.classList.toggle('open');
    }

    // ── Check Absences + Send Notifications ──
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
                result.innerHTML = '<strong style="color:var(--md-error);">' + data.flagged + ' students flagged.</strong> ' +
                    data.notifications.sent + ' notifications sent.';
                showToast(data.flagged > 0 ? data.flagged + ' students flagged' : 'No students flagged — all good!', true);
            } else {
                result.textContent = data.error || 'Unknown error';
            }
        } catch (err) {
            result.textContent = 'Network error: ' + err.message;
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-bell"></i> Check & Send Absence Alerts';
    }

    // ── General ──
    let refreshTimer = setTimeout(() => location.reload(), 60000);

    function applyDate(val) {
        const p = new URLSearchParams(window.location.search);
        p.set('date', val);
        window.location.search = p.toString();
    }

    function applySchool(id) {
        const p = new URLSearchParams(window.location.search);
        if (id) { p.set('school', id); } else { p.delete('school'); }
        window.location.search = p.toString();
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
