<?php
session_start();
require_once '../config/database.php';
require_once '../config/school_days.php';
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../admin_login.php');
    exit;
}
// Redirect role-specific users to their dashboards
$role = $_SESSION['admin_role'] ?? 'super_admin';
if ($role === 'principal') { header('Location: principal_dashboard.php'); exit; }
if ($role === 'superintendent') { header('Location: sds_dashboard.php'); exit; }
if ($role === 'asst_superintendent') { header('Location: asds_dashboard.php'); exit; }

$conn = getDBConnection();

$current_page = 'dashboard';
$page_title = 'Dashboard';

$today = date('Y-m-d');
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;

// Role-based school filter
$school_filter_sql = '';
if ($admin_role === 'principal' && $admin_school_id) {
    $school_filter_sql = " AND school_id = $admin_school_id ";
}

$filter_school = (int)($_GET['school'] ?? 0);
$filter_date = sanitize($_GET['date'] ?? $today);

$extra_filter = '';
if ($filter_school) $extra_filter .= " AND school_id = $filter_school ";

// ─── Summary Stats ───
$total_schools = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM schools WHERE status='active' $school_filter_sql"); 
if ($r) $total_schools = $r->fetch_assoc()['cnt'];

$total_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' " . str_replace('school_id', 's.school_id', $school_filter_sql));
// Simpler query:
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = $admin_school_id" : ""));
if ($r) $total_students = $r->fetch_assoc()['cnt'];

$total_teachers = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM teachers WHERE status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = $admin_school_id" : ""));
if ($r) $total_teachers = $r->fetch_assoc()['cnt'];

// Students timed in today (only count active students)
$timed_in_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_in_today = $r->fetch_assoc()['cnt'];

// Students timed out today (only count active students)
$timed_out_today = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.date='$filter_date' AND a.time_out IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $timed_out_today = $r->fetch_assoc()['cnt'];

// Absent today (not scanned — exclude students created today unless they attended)
$relevant_students = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' AND (DATE(created_at) < '$filter_date' OR id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL)) " . ($admin_role === 'principal' && $admin_school_id ? "AND school_id = $admin_school_id " : "") . ($filter_school ? "AND school_id = $filter_school" : ""));
if ($r) $relevant_students = $r->fetch_assoc()['cnt'];
$absent_today = max(0, $relevant_students - $timed_in_today);

// Teachers timed in today (only count active teachers)
$teachers_in = 0;
$r = $conn->query("SELECT COUNT(DISTINCT a.person_id) as cnt FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.date='$filter_date' AND a.time_in IS NOT NULL $school_filter_sql $extra_filter");
if ($r) $teachers_in = $r->fetch_assoc()['cnt'];

// ─── Per-School Breakdown ───
$school_breakdown = [];
$school_sql = "SELECT s.id, s.name, s.code,
    (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active' AND (DATE(st.created_at) < '$filter_date' OR st.id IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date' AND time_in IS NOT NULL))) as enrolled,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN students st ON a.person_id = st.id AND st.status='active' WHERE a.person_type='student' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as present,
    (SELECT COUNT(DISTINCT a.person_id) FROM attendance a INNER JOIN teachers t ON a.person_id = t.id AND t.status='active' WHERE a.person_type='teacher' AND a.school_id = s.id AND a.date='$filter_date' AND a.time_in IS NOT NULL) as teachers_present,
    (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as total_teachers
    FROM schools s WHERE s.status='active' " . ($admin_role === 'principal' && $admin_school_id ? "AND s.id = $admin_school_id" : "") . "
    ORDER BY s.name";
$r = $conn->query($school_sql);
if ($r) { while ($row = $r->fetch_assoc()) { $row['present'] = min($row['present'], $row['enrolled']); $row['absent'] = max(0, $row['enrolled'] - $row['present']); $row['rate'] = $row['enrolled'] > 0 ? min(100, round(($row['present'] / $row['enrolled']) * 100, 1)) : 0; $school_breakdown[] = $row; } }

// ─── 2-Day Consecutive Absence Flag ───
$flagged_students = [];
$yesterday = date('Y-m-d', strtotime('-1 day', strtotime($filter_date)));
$flag_sql = "SELECT s.id, s.lrn, s.name, sch.name as school_name, sch.code as school_code, gl.name as grade_name, sec.name as section_name
    FROM students s
    LEFT JOIN schools sch ON s.school_id = sch.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.status = 'active'
    AND DATE(s.created_at) < '$filter_date'
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$filter_date')
    AND s.id NOT IN (SELECT DISTINCT person_id FROM attendance WHERE person_type='student' AND date='$yesterday')
    " . ($admin_role === 'principal' && $admin_school_id ? "AND s.school_id = $admin_school_id" : "") . "
    " . ($filter_school ? "AND s.school_id = $filter_school" : "") . "
    ORDER BY sch.name, gl.id, s.name
    LIMIT 100";
$r = $conn->query($flag_sql);
if ($r) { while ($row = $r->fetch_assoc()) $flagged_students[] = $row; }

// ─── Fetch schools for filter dropdown ───
$schools_list = [];
$r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name");
if ($r) { while ($row = $r->fetch_assoc()) $schools_list[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/header.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                colors: {
                    primary: { DEFAULT: '#4338ca', dark: '#3730a3', light: '#6366f1' },
                }
            }
        }
    }
    </script>
    <style type="text/tailwindcss">
        @layer utilities {
            .tw-card { @apply bg-white rounded-2xl shadow-sm border border-slate-200 p-6; }
            .tw-progress-bar { @apply h-2.5 rounded-full overflow-hidden flex bg-slate-200; }
            .tw-progress-fill { @apply h-full transition-all duration-500 ease-out; }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="relative overflow-hidden bg-gradient-to-r from-indigo-700 via-indigo-600 to-purple-600 text-white rounded-2xl p-8 mb-7 shadow-lg">
            <div class="absolute -right-12 -top-12 w-52 h-52 bg-white/10 rounded-full"></div>
            <div class="absolute right-20 bottom-[-30px] w-32 h-32 bg-white/5 rounded-full"></div>
            <div class="relative z-10">
                <h2 class="text-2xl font-bold mb-1">Welcome back, <?= htmlspecialchars($_SESSION['admin_full_name'] ?? 'Super Admin') ?> 👋</h2>
                <p class="text-indigo-100 text-sm">Here is your division-wide attendance overview for <?= date('l, F j, Y', strtotime($filter_date)) ?></p>
            </div>
        </div>

        <!-- Page Header + Filters -->
        <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-indigo-600"></i> Division Dashboard
                </h1>
                <p class="text-slate-500 text-sm mt-1">Real-time attendance monitoring</p>
            </div>
            <form method="GET" class="flex items-center gap-2.5 bg-white px-3 py-2 rounded-xl shadow-sm border border-slate-200">
                <?php if ($admin_role !== 'principal'): ?>
                <select name="school" class="text-sm text-slate-700 bg-slate-50 border-0 rounded-lg px-3 py-2 min-w-[200px] focus:ring-2 focus:ring-indigo-500 focus:outline-none" onchange="this.form.submit()">
                    <option value="">All Schools</option>
                    <?php foreach ($schools_list as $sch): ?>
                        <option value="<?= $sch['id'] ?>" <?= $filter_school == $sch['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <input type="date" name="date" class="text-sm text-slate-700 bg-slate-50 border-0 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none" value="<?= $filter_date ?>" onchange="this.form.submit()">
            </form>
        </div>

        <?php if (!isSchoolDay($filter_date, $conn)):
            $non_school_reason = getNonSchoolDayReason($filter_date, $conn);
        ?>
        <!-- Non-School Day Alert -->
        <div class="flex items-center gap-4 bg-gradient-to-r from-amber-50 to-yellow-50 border border-amber-300 rounded-2xl px-6 py-4 mb-5">
            <i class="fas fa-calendar-xmark text-3xl text-amber-600"></i>
            <div>
                <strong class="text-amber-900 text-base">No Classes Today</strong>
                <p class="text-amber-700 text-sm mt-0.5"><?= htmlspecialchars($non_school_reason ?? 'Non-school day') ?> — Attendance data shown is for reference only.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Stat Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-7">
            <!-- Schools -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-school text-indigo-600 text-lg"></i>
                </div>
                <div>
                    <div class="text-2xl font-extrabold text-slate-800"><?= $total_schools ?></div>
                    <div class="text-xs text-slate-500 font-medium uppercase tracking-wide">Schools</div>
                </div>
            </div>
            <!-- Present -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-emerald-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-user-check text-emerald-600 text-lg"></i>
                </div>
                <div>
                    <div class="text-2xl font-extrabold text-slate-800"><?= $timed_in_today ?></div>
                    <div class="text-xs text-slate-500 font-medium uppercase tracking-wide">Students Present</div>
                </div>
            </div>
            <!-- Absent -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-red-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-user-times text-red-600 text-lg"></i>
                </div>
                <div>
                    <div class="text-2xl font-extrabold text-slate-800"><?= $absent_today ?></div>
                    <div class="text-xs text-slate-500 font-medium uppercase tracking-wide">Students Absent</div>
                </div>
            </div>
            <!-- 2-Day Flagged -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-amber-600 text-lg"></i>
                </div>
                <div>
                    <div class="text-2xl font-extrabold text-slate-800"><?= count($flagged_students) ?></div>
                    <div class="text-xs text-slate-500 font-medium uppercase tracking-wide">2-Day Flagged</div>
                </div>
            </div>
            <!-- Teachers -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-chalkboard-teacher text-blue-600 text-lg"></i>
                </div>
                <div>
                    <div class="text-2xl font-extrabold text-slate-800"><?= $teachers_in ?>/<?= $total_teachers ?></div>
                    <div class="text-xs text-slate-500 font-medium uppercase tracking-wide">Teachers Present</div>
                </div>
            </div>
        </div>

        <!-- School Attendance Table -->
        <div class="tw-card mb-6">
            <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2 mb-5">
                <i class="fas fa-school text-indigo-600"></i> School Attendance Breakdown
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider border-b border-slate-200">
                            <th class="pb-3 pl-1">School</th>
                            <th class="pb-3 text-center">Enrolled</th>
                            <th class="pb-3 text-center">Present</th>
                            <th class="pb-3 text-center">Absent</th>
                            <th class="pb-3 text-center">Rate</th>
                            <th class="pb-3 text-center">Teachers</th>
                            <th class="pb-3 text-center w-36">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($school_breakdown)): ?>
                            <tr><td colspan="7" class="text-center py-10 text-slate-400">No schools found.</td></tr>
                        <?php else: foreach ($school_breakdown as $sb): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="py-3.5 pl-1 font-semibold text-slate-700"><?= htmlspecialchars($sb['name']) ?></td>
                            <td class="py-3.5 text-center text-slate-600"><?= $sb['enrolled'] ?></td>
                            <td class="py-3.5 text-center font-bold text-emerald-600"><?= $sb['present'] ?></td>
                            <td class="py-3.5 text-center font-bold text-red-600"><?= $sb['absent'] ?></td>
                            <td class="py-3.5 text-center font-bold <?= $sb['rate'] >= 90 ? 'text-emerald-600' : ($sb['rate'] >= 75 ? 'text-amber-600' : 'text-red-600') ?>"><?= $sb['rate'] ?>%</td>
                            <td class="py-3.5 text-center"><span class="text-indigo-600 font-semibold"><?= $sb['teachers_present'] ?></span><span class="text-slate-400">/<?= $sb['total_teachers'] ?></span></td>
                            <td class="py-3.5 text-center">
                                <div class="tw-progress-bar">
                                    <div class="tw-progress-fill bg-emerald-500 rounded-l-full" style="width:<?= $sb['rate'] ?>%"></div>
                                    <div class="tw-progress-fill bg-red-400 rounded-r-full" style="width:<?= 100 - $sb['rate'] ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Bottom Cards Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- 2-Day Flagged Students -->
            <div class="tw-card">
                <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2 mb-4">
                    <i class="fas fa-exclamation-triangle text-amber-500"></i> 2-Day Consecutive Absences
                    <span class="ml-auto inline-flex items-center justify-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-700"><?= count($flagged_students) ?></span>
                </h3>
                <div class="max-h-96 overflow-y-auto space-y-1">
                    <?php if (empty($flagged_students)): ?>
                        <div class="text-center py-10">
                            <i class="fas fa-check-circle text-4xl text-emerald-200 mb-3"></i>
                            <h4 class="text-emerald-600 font-semibold mb-1">No flags!</h4>
                            <p class="text-sm text-slate-400">All students have been attending.</p>
                        </div>
                    <?php else: foreach ($flagged_students as $fs): ?>
                        <div class="flex justify-between items-center px-4 py-3 rounded-xl hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0">
                            <div>
                                <div class="font-semibold text-sm text-slate-700"><?= htmlspecialchars($fs['name']) ?></div>
                                <div class="text-xs text-slate-400 mt-0.5">LRN: <?= htmlspecialchars($fs['lrn']) ?> · <?= htmlspecialchars($fs['grade_name'] ?? '') ?> — <?= htmlspecialchars($fs['section_name'] ?? '') ?></div>
                            </div>
                            <div class="text-right flex-shrink-0 ml-3">
                                <span class="inline-block px-2 py-0.5 bg-blue-50 text-blue-600 rounded-md text-[0.65rem] font-bold"><?= htmlspecialchars($fs['school_code'] ?? '') ?></span>
                                <div class="text-[0.7rem] text-amber-600 font-semibold mt-1">2+ days</div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Teacher Attendance Summary -->
            <div class="tw-card">
                <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2 mb-4">
                    <i class="fas fa-chalkboard-teacher text-blue-600"></i> Teacher Attendance
                </h3>
                <div class="max-h-96 overflow-y-auto divide-y divide-slate-100">
                    <?php foreach ($school_breakdown as $sb): ?>
                        <div class="flex justify-between items-center py-3.5 px-2">
                            <div>
                                <div class="font-semibold text-sm text-slate-700"><?= htmlspecialchars($sb['name']) ?></div>
                                <div class="text-xs text-slate-400 mt-0.5"><?= $sb['teachers_present'] ?> of <?= $sb['total_teachers'] ?> present</div>
                            </div>
                            <div class="text-lg font-extrabold <?= $sb['total_teachers'] > 0 && $sb['teachers_present'] == $sb['total_teachers'] ? 'text-emerald-600' : 'text-amber-600' ?>">
                                <?= $sb['total_teachers'] > 0 ? round(($sb['teachers_present'] / $sb['total_teachers']) * 100) . '%' : '—' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($school_breakdown)): ?>
                        <div class="text-center py-10 text-slate-400 text-sm">No data.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
