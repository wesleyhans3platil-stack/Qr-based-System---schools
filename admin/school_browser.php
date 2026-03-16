<?php
require_once '../config/database.php';
if (!isset($_SESSION['admin_id'])) { header('Location: ../admin_login.php'); exit; }

$current_page = 'school_browser';
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;

// ─── Determine what to show ───
$view = $_GET['view'] ?? 'schools'; // schools | grades | sections | students
$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : null;
$grade_id = isset($_GET['grade_id']) ? (int)$_GET['grade_id'] : null;
$section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : null;

// If principal, lock to their school
if ($admin_role === 'principal' && $admin_school_id) {
    if ($view === 'schools') {
        header("Location: school_browser.php?view=grades&school_id=$admin_school_id");
        exit;
    }
    $school_id = $admin_school_id;
}

// Breadcrumb data
$breadcrumbs = [];
$breadcrumbs[] = ['label' => 'All Schools', 'url' => 'school_browser.php', 'icon' => 'fas fa-school'];

$school_name = '';
$grade_name = '';
$section_name = '';

if ($school_id) {
    $s = $conn->query("SELECT name FROM schools WHERE id = $school_id");
    if ($s && $row = $s->fetch_assoc()) $school_name = $row['name'];
    $breadcrumbs[] = ['label' => $school_name, 'url' => "school_browser.php?view=grades&school_id=$school_id", 'icon' => 'fas fa-school'];
}
if ($grade_id) {
    $g = $conn->query("SELECT name FROM grade_levels WHERE id = $grade_id");
    if ($g && $row = $g->fetch_assoc()) $grade_name = $row['name'];
    $breadcrumbs[] = ['label' => $grade_name, 'url' => "school_browser.php?view=sections&school_id=$school_id&grade_id=$grade_id", 'icon' => 'fas fa-layer-group'];
}
if ($section_id) {
    $sec = $conn->query("SELECT s.name, t.name as adviser_name FROM sections s LEFT JOIN teachers t ON s.adviser_id = t.id WHERE s.id = $section_id");
    if ($sec && $row = $sec->fetch_assoc()) $section_name = $row['name'];
    $breadcrumbs[] = ['label' => $section_name, 'url' => '#', 'icon' => 'fas fa-users'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schools Browser — QR Attendance</title>
    <?php $__fl=$conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'")->fetch_assoc(); if(!empty($__fl['setting_value'])&&file_exists('../assets/uploads/logos/'.$__fl['setting_value'])):?><link rel="icon" type="image/png" href="../assets/uploads/logos/<?=htmlspecialchars($__fl['setting_value'])?>"><?php endif;?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="includes/styles.css">
    <style>
        /* ─── Breadcrumb ─── */
        .breadcrumb-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .breadcrumb-nav a, .breadcrumb-nav span {
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }
        .breadcrumb-nav a:hover {
            color: var(--primary);
        }
        .breadcrumb-nav .breadcrumb-active {
            color: var(--primary);
            font-weight: 700;
        }
        .breadcrumb-nav .breadcrumb-sep {
            color: var(--border);
            font-size: 0.75rem;
        }

        /* ─── School Cards Grid ─── */
        .browser-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .browser-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0;
            cursor: pointer;
            transition: var(--transition);
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .browser-card.school-card {
            aspect-ratio: 1 / 1;
        }
        .browser-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }
        .browser-card:hover .card-icon-wrap {
            background: var(--primary);
        }
        .browser-card:hover .card-icon-wrap i {
            color: #fff;
        }

        .card-top-accent {
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            flex-shrink: 0;
        }

        .card-body-inner {
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 12px;
            flex: 1;
            min-height: 0;
        }

        .card-icon-wrap {
            width: 80px;
            height: 80px;
            background: var(--primary-bg);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .card-icon-wrap i {
            font-size: 2rem;
            color: var(--primary);
            transition: var(--transition);
        }

        .school-card-logo {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: contain;
            flex-shrink: 0;
            border: none;
            background: transparent;
            padding: 0;
        }
        .card-icon-wrap.success { background: var(--success-bg); }
        .card-icon-wrap.success i { color: var(--success); }
        .card-icon-wrap.warning { background: var(--warning-bg); }
        .card-icon-wrap.warning i { color: var(--warning); }
        .card-icon-wrap.info { background: var(--info-bg); }
        .card-icon-wrap.info i { color: var(--info); }

        .card-details {
            flex: 1;
            min-width: 0;
            text-align: center;
        }
        .card-details h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
            line-height: 1.3;
        }
        .card-details p {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin: 0;
        }

        .card-stats-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            padding: 14px 24px;
            background: var(--card-bg-alt);
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        .card-stat-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.72rem;
            color: var(--text-muted);
            white-space: nowrap;
        }
        .card-stat-item i {
            font-size: 0.7rem;
        }
        .card-stat-item strong {
            color: var(--text);
            font-weight: 700;
        }

        .card-arrow {
            display: none;
        }

        /* ─── Section detail view ─── */
        .section-detail-header {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 28px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .section-detail-header .section-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .section-detail-header .section-icon i {
            font-size: 1.6rem;
            color: #fff;
        }
        .section-detail-header .section-meta h2 {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 4px;
        }
        .section-detail-header .section-meta p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Teacher highlight card */
        .teacher-card {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 2px solid var(--success);
            border-radius: var(--radius);
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .teacher-card .teacher-avatar {
            width: 56px;
            height: 56px;
            background: var(--success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: #fff;
            font-weight: 700;
        }
        .teacher-card .teacher-info h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #15803d;
            margin-bottom: 2px;
        }
        .teacher-card .teacher-info p {
            font-size: 0.8rem;
            color: #166534;
        }
        .teacher-card .teacher-badge {
            margin-left: auto;
            background: var(--success);
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 5px 14px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Student List Table */
        .student-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .student-table th {
            background: var(--card-bg-alt);
            padding: 12px 16px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            text-align: left;
            border-bottom: 2px solid var(--border);
        }
        .student-table td {
            padding: 12px 16px;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border);
            color: var(--text);
        }
        .student-table tr:hover td {
            background: rgba(67,56,202,0.04);
        }
        .student-table tr:last-child td {
            border-bottom: none;
        }

        /* Absent row highlight */
        .row-absent td {
            background: rgba(220,38,38,0.05) !important;
        }
        .row-absent {
            border-left: 3px solid var(--error);
        }
        .row-absent .student-name-text {
            color: var(--error);
        }
        .student-num {
            width: 40px;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.78rem;
        }
        .student-name-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .student-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--primary);
            flex-shrink: 0;
        }
        .student-name-text {
            font-weight: 600;
        }
        .student-lrn {
            color: var(--text-muted);
            font-size: 0.75rem;
            font-family: monospace;
        }

        .lrn-badge {
            background: var(--primary-bg);
            color: var(--primary);
            font-family: 'Courier New', monospace;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 6px;
        }

        .status-badge {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
        }
        .status-active {
            background: var(--success-bg);
            color: var(--success);
        }
        .status-inactive {
            background: var(--error-bg);
            color: var(--error);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .empty-state h3 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text);
        }
        .empty-state p {
            font-size: 0.85rem;
        }

        /* ─── Grade color cycle ─── */
        .grade-card:nth-child(6n+1) .card-icon-wrap { background: rgba(67,56,202,0.1); }
        .grade-card:nth-child(6n+1) .card-icon-wrap i { color: #4338ca; }
        .grade-card:nth-child(6n+2) .card-icon-wrap { background: rgba(22,163,74,0.1); }
        .grade-card:nth-child(6n+2) .card-icon-wrap i { color: #16a34a; }
        .grade-card:nth-child(6n+3) .card-icon-wrap { background: rgba(217,119,6,0.1); }
        .grade-card:nth-child(6n+3) .card-icon-wrap i { color: #d97706; }
        .grade-card:nth-child(6n+4) .card-icon-wrap { background: rgba(37,99,235,0.1); }
        .grade-card:nth-child(6n+4) .card-icon-wrap i { color: #2563eb; }
        .grade-card:nth-child(6n+5) .card-icon-wrap { background: rgba(220,38,38,0.1); }
        .grade-card:nth-child(6n+5) .card-icon-wrap i { color: #dc2626; }
        .grade-card:nth-child(6n+6) .card-icon-wrap { background: rgba(147,51,234,0.1); }
        .grade-card:nth-child(6n+6) .card-icon-wrap i { color: #9333ea; }

        .view-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .view-title h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text);
        }
        .view-title .count-badge {
            background: var(--primary-bg);
            color: var(--primary);
            font-size: 0.78rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            transition: var(--transition);
            margin-bottom: 20px;
        }
        .back-btn:hover {
            background: var(--primary-bg);
            color: var(--primary);
            border-color: var(--primary-light);
        }

        @media (max-width: 768px) {
            .browser-grid {
                grid-template-columns: 1fr;
            }
            .card-body-inner {
                padding: 18px;
            }
            .section-detail-header {
                flex-direction: column;
                text-align: center;
            }
            .teacher-card {
                flex-direction: column;
                text-align: center;
            }
            .teacher-card .teacher-badge {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<main class="main-content">

    <!-- Breadcrumb -->
    <nav class="breadcrumb-nav">
        <?php foreach ($breadcrumbs as $i => $bc): ?>
            <?php if ($i > 0): ?>
                <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
            <?php if ($i === count($breadcrumbs) - 1): ?>
                <span class="breadcrumb-active"><i class="<?= $bc['icon'] ?>"></i> <?= htmlspecialchars($bc['label']) ?></span>
            <?php else: ?>
                <a href="<?= $bc['url'] ?>"><i class="<?= $bc['icon'] ?>"></i> <?= htmlspecialchars($bc['label']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php
    // ════════════════════════════════════════════════════════════
    // VIEW: SCHOOLS
    // ════════════════════════════════════════════════════════════
    if ($view === 'schools'):
        $schools_q = $conn->query("SELECT s.*, 
            (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status='active') as student_count,
            (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id AND t.status='active') as teacher_count,
            (SELECT COUNT(DISTINCT sec.grade_level_id) FROM sections sec WHERE sec.school_id = s.id) as grade_count,
            (SELECT COUNT(*) FROM sections sec WHERE sec.school_id = s.id AND sec.status='active') as section_count
            FROM schools s WHERE s.status='active' ORDER BY s.name");
        $school_count = $schools_q ? $schools_q->num_rows : 0;
    ?>
        <div class="view-title">
            <h1><i class="fas fa-school" style="color:var(--primary)"></i> Schools</h1>
            <span class="count-badge"><?= $school_count ?> school<?= $school_count !== 1 ? 's' : '' ?></span>
        </div>

        <?php if ($school_count > 0): ?>
        <div class="browser-grid">
            <?php while ($school = $schools_q->fetch_assoc()): ?>
            <a href="school_browser.php?view=grades&school_id=<?= $school['id'] ?>" class="browser-card school-card">
                <div class="card-top-accent"></div>
                <div class="card-body-inner">
                    <?php if (!empty($school['logo'])): ?>
                        <img src="../assets/uploads/logos/<?= htmlspecialchars($school['logo']) ?>" alt="Logo" class="school-card-logo">
                    <?php else: ?>
                        <div class="card-icon-wrap">
                            <i class="fas fa-school"></i>
                        </div>
                    <?php endif; ?>
                    <div class="card-details">
                        <h3><?= htmlspecialchars($school['name']) ?></h3>
                    </div>
                    <span class="card-arrow"><i class="fas fa-chevron-right"></i></span>
                </div>
                <div class="card-stats-row">
                    <div class="card-stat-item">
                        <i class="fas fa-user-graduate"></i>
                        <strong><?= $school['student_count'] ?></strong> Students
                    </div>
                    <div class="card-stat-item">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <strong><?= $school['teacher_count'] ?></strong> Teachers
                    </div>
                    <div class="card-stat-item">
                        <i class="fas fa-layer-group"></i>
                        <strong><?= $school['section_count'] ?></strong> Sections
                    </div>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="empty-state">
                <i class="fas fa-school"></i>
                <h3>No Schools Yet</h3>
                <p>Add schools from the Settings or Schools management page first.</p>
            </div>
        </div>
        <?php endif; ?>

    <?php
    // ════════════════════════════════════════════════════════════
    // VIEW: GRADES (within a school)
    // ════════════════════════════════════════════════════════════
    elseif ($view === 'grades' && $school_id):
        $grades_q = $conn->query("SELECT gl.*,
            (SELECT COUNT(*) FROM sections sec WHERE sec.school_id = $school_id AND sec.grade_level_id = gl.id AND sec.status='active') as section_count,
            (SELECT COUNT(*) FROM students st WHERE st.school_id = $school_id AND st.grade_level_id = gl.id AND st.status='active') as student_count
            FROM grade_levels gl 
            WHERE gl.id IN (SELECT DISTINCT grade_level_id FROM sections WHERE school_id = $school_id AND status='active')
            ORDER BY gl.id");
        $grade_count = $grades_q ? $grades_q->num_rows : 0;
    ?>
        <a href="school_browser.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Schools</a>

        <div class="view-title">
            <h1><i class="fas fa-layer-group" style="color:var(--primary)"></i> <?= htmlspecialchars($school_name) ?></h1>
            <span class="count-badge"><?= $grade_count ?> grade<?= $grade_count !== 1 ? 's' : '' ?></span>
        </div>

        <?php if ($grade_count > 0): ?>
        <div class="browser-grid">
            <?php $idx = 0; while ($grade = $grades_q->fetch_assoc()): $idx++; ?>
            <a href="school_browser.php?view=sections&school_id=<?= $school_id ?>&grade_id=<?= $grade['id'] ?>" class="browser-card grade-card" style="aspect-ratio:auto;">
                <div class="card-top-accent"></div>
                <div class="card-body-inner">
                    <div class="card-icon-wrap">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="card-details">
                        <h3><?= htmlspecialchars($grade['name']) ?></h3>
                        <p><?= $grade['section_count'] ?> section<?= $grade['section_count'] != 1 ? 's' : '' ?></p>
                    </div>
                    <span class="card-arrow"><i class="fas fa-chevron-right"></i></span>
                </div>
                <div class="card-stats-row">
                    <div class="card-stat-item">
                        <i class="fas fa-user-graduate"></i>
                        <strong><?= $grade['student_count'] ?></strong> Students
                    </div>
                    <div class="card-stat-item">
                        <i class="fas fa-door-open"></i>
                        <strong><?= $grade['section_count'] ?></strong> Sections
                    </div>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="empty-state">
                <i class="fas fa-layer-group"></i>
                <h3>No Grades Found</h3>
                <p>This school has no sections or grades configured yet.</p>
            </div>
        </div>
        <?php endif; ?>

    <?php
    // ════════════════════════════════════════════════════════════
    // VIEW: SECTIONS (within a grade)
    // ════════════════════════════════════════════════════════════
    elseif ($view === 'sections' && $school_id && $grade_id):
        $sections_q = $conn->query("SELECT sec.*, 
            t.name as adviser_name, t.employee_id as adviser_eid,
            (SELECT COUNT(*) FROM students st WHERE st.section_id = sec.id AND st.status='active') as student_count
            FROM sections sec 
            LEFT JOIN teachers t ON sec.adviser_id = t.id
            WHERE sec.school_id = $school_id AND sec.grade_level_id = $grade_id AND sec.status='active'
            ORDER BY sec.name");
        $section_count = $sections_q ? $sections_q->num_rows : 0;
    ?>
        <a href="school_browser.php?view=grades&school_id=<?= $school_id ?>" class="back-btn"><i class="fas fa-arrow-left"></i> Back to <?= htmlspecialchars($school_name) ?></a>

        <div class="view-title">
            <h1><i class="fas fa-door-open" style="color:var(--primary)"></i> <?= htmlspecialchars($grade_name) ?></h1>
            <span class="count-badge"><?= $section_count ?> section<?= $section_count !== 1 ? 's' : '' ?></span>
        </div>
        <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:24px;"><i class="fas fa-school"></i> <?= htmlspecialchars($school_name) ?></p>

        <?php if ($section_count > 0): ?>
        <div class="browser-grid">
            <?php while ($sec = $sections_q->fetch_assoc()): ?>
            <a href="school_browser.php?view=students&school_id=<?= $school_id ?>&grade_id=<?= $grade_id ?>&section_id=<?= $sec['id'] ?>" class="browser-card" style="aspect-ratio:auto;">
                <div class="card-top-accent"></div>
                <div class="card-body-inner">
                    <div class="card-icon-wrap <?= $sec['adviser_name'] ? 'success' : '' ?>">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="card-details">
                        <h3><?= htmlspecialchars($sec['name']) ?></h3>
                        <?php if ($sec['adviser_name']): ?>
                            <p><i class="fas fa-chalkboard-teacher"></i> <?= htmlspecialchars($sec['adviser_name']) ?></p>
                        <?php else: ?>
                            <p style="color:var(--warning);"><i class="fas fa-exclamation-triangle"></i> No adviser assigned</p>
                        <?php endif; ?>
                    </div>
                    <span class="card-arrow"><i class="fas fa-chevron-right"></i></span>
                </div>
                <div class="card-stats-row">
                    <div class="card-stat-item">
                        <i class="fas fa-user-graduate"></i>
                        <strong><?= $sec['student_count'] ?></strong> Students
                    </div>
                    <?php if ($sec['adviser_name']): ?>
                    <div class="card-stat-item">
                        <i class="fas fa-id-badge"></i>
                        <?= htmlspecialchars($sec['adviser_eid']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="empty-state">
                <i class="fas fa-door-open"></i>
                <h3>No Sections Found</h3>
                <p>This grade level has no sections configured yet.</p>
            </div>
        </div>
        <?php endif; ?>

    <?php
    // ════════════════════════════════════════════════════════════
    // VIEW: STUDENTS (within a section) — show teacher highlight
    // ════════════════════════════════════════════════════════════
    elseif ($view === 'students' && $school_id && $grade_id && $section_id):
        // Get section info with adviser
        $sec_info = $conn->query("SELECT sec.*, t.name as adviser_name, t.employee_id as adviser_eid, t.contact_number as adviser_contact,
            gl.name as grade_name
            FROM sections sec 
            LEFT JOIN teachers t ON sec.adviser_id = t.id
            LEFT JOIN grade_levels gl ON sec.grade_level_id = gl.id
            WHERE sec.id = $section_id")->fetch_assoc();

        // Get students (active + inactive) in this section
        $students_q = $conn->query("SELECT * FROM students WHERE section_id = $section_id ORDER BY name ASC");
        $student_count = $students_q ? $students_q->num_rows : 0;

        // Today's attendance for these students
        $today = date('Y-m-d');
        $att_map = [];
        $att_q = $conn->query("SELECT person_id, time_in, time_out, status FROM attendance WHERE person_type='student' AND date='$today' AND person_id IN (SELECT id FROM students WHERE section_id = $section_id)");
        if ($att_q) {
            while ($a = $att_q->fetch_assoc()) {
                $att_map[$a['person_id']] = $a;
            }
        }

        // Count present, late, absent (active students only)
        $present_count = 0;
        $late_count = 0;
        $inactive_count = 0;

        // Collect student records
        $all_students = [];
        if ($students_q && $student_count > 0) {
            while ($s = $students_q->fetch_assoc()) $all_students[] = $s;
            mysqli_data_seek($students_q, 0); // won't use this anymore
        }

        foreach ($all_students as $s) {
            if (($s['status'] ?? '') !== 'active') {
                $inactive_count++;
                continue;
            }
            if (isset($att_map[$s['id']])) {
                if ($att_map[$s['id']]['status'] === 'present') $present_count++;
                elseif ($att_map[$s['id']]['status'] === 'late') $late_count++;
            }
        }

        $active_count = $student_count - $inactive_count;
        $absent_count = max(0, $active_count - $present_count - $late_count);
    ?>
        <a href="school_browser.php?view=sections&school_id=<?= $school_id ?>&grade_id=<?= $grade_id ?>" class="back-btn"><i class="fas fa-arrow-left"></i> Back to <?= htmlspecialchars($grade_name) ?></a>

        <!-- Section Header -->
        <div class="section-detail-header">
            <div class="section-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="section-meta">
                <h2><?= htmlspecialchars($sec_info['name']) ?></h2>
                <p><?= htmlspecialchars($sec_info['grade_name']) ?> &middot; <?= htmlspecialchars($school_name) ?> &middot; <?= $student_count ?> student<?= $student_count !== 1 ? 's' : '' ?></p>
            </div>
        </div>

        <!-- Teacher Highlight Card -->
        <?php if ($sec_info['adviser_name']): ?>
        <div class="teacher-card">
            <div class="teacher-avatar">
                <?= strtoupper(substr($sec_info['adviser_name'], 0, 1)) ?>
            </div>
            <div class="teacher-info">
                <h3><i class="fas fa-star" style="margin-right:4px;font-size:0.8rem;"></i> <?= htmlspecialchars($sec_info['adviser_name']) ?></h3>
                <p>Employee ID: <?= htmlspecialchars($sec_info['adviser_eid']) ?>
                    <?php if ($sec_info['adviser_contact']): ?>
                     &middot; <i class="fas fa-phone"></i> <?= htmlspecialchars($sec_info['adviser_contact']) ?>
                    <?php endif; ?>
                </p>
            </div>
            <span class="teacher-badge"><i class="fas fa-chalkboard-teacher"></i> Class Adviser</span>
        </div>
        <?php else: ?>
        <div style="background:var(--warning-bg); border:1px solid var(--warning); border-radius:var(--radius); padding:16px 24px; margin-bottom:24px; display:flex; align-items:center; gap:12px; color:#92400e; font-size:0.85rem;">
            <i class="fas fa-exclamation-triangle" style="font-size:1.1rem;"></i>
            <span>No class adviser assigned to this section.</span>
        </div>
        <?php endif; ?>

        <!-- Attendance Summary Stats -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:20px;">
            <div class="stat-card" style="border-left:4px solid var(--primary);">
                <div class="stat-icon primary" style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--primary-bg);">
                    <i class="fas fa-users" style="color:var(--primary);font-size:1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.4rem;font-weight:800;color:var(--text);"><?= $student_count ?></div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;">Total Students</div>
                </div>
            </div>
            <div class="stat-card" style="border-left:4px solid var(--warning);">
                <div class="stat-icon warning" style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--warning-bg);">
                    <i class="fas fa-user-slash" style="color:var(--warning);font-size:1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.4rem;font-weight:800;color:var(--warning);"><?= $inactive_count ?></div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;">Inactive Students</div>
                </div>
            </div>
            <div class="stat-card" style="border-left:4px solid var(--success);">
                <div style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--success-bg);">
                    <i class="fas fa-check-circle" style="color:var(--success);font-size:1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.4rem;font-weight:800;color:var(--success);"><?= $present_count ?></div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;">Present</div>
                </div>
            </div>
            <div class="stat-card" style="border-left:4px solid var(--warning);">
                <div style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--warning-bg);">
                    <i class="fas fa-clock" style="color:var(--warning);font-size:1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.4rem;font-weight:800;color:var(--warning);"><?= $late_count ?></div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;">Late</div>
                </div>
            </div>
            <div class="stat-card" style="border-left:4px solid var(--error);">
                <div style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--error-bg);">
                    <i class="fas fa-times-circle" style="color:var(--error);font-size:1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.4rem;font-weight:800;color:var(--error);"><?= $absent_count ?></div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;">Absent</div>
                </div>
            </div>
        </div>

        <!-- Students List -->
        <div class="card" style="padding:0; overflow:hidden;">
            <div style="padding:18px 24px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
                <h3 style="font-size:1rem; font-weight:700; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-user-graduate" style="color:var(--primary);"></i> Students
                    <span class="count-badge"><?= $student_count ?></span>
                </h3>
                <span style="font-size:0.78rem; color:var(--text-muted);">
                    <i class="fas fa-calendar-day"></i> Today: <?= date('M j, Y') ?>
                </span>
            </div>

            <?php if ($student_count > 0): ?>
            <div style="overflow-x:auto;">
            <table class="student-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Student Name</th>
                        <th>LRN</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $num = 0; foreach ($all_students as $stu): $num++;
                        $att = $att_map[$stu['id']] ?? null;
                        $initials = strtoupper(substr($stu['name'], 0, 1));
                        $is_absent = !$att; // no attendance record = absent
                    ?>
                    <tr class="<?= $is_absent ? 'row-absent' : '' ?>">
                        <td class="student-num"><?= $num ?></td>
                        <td>
                            <div class="student-name-cell">
                                <div class="student-avatar" <?= $is_absent ? 'style="background:var(--error-bg);color:var(--error);"' : '' ?>><?= $initials ?></div>
                                <span class="student-name-text"><?= htmlspecialchars($stu['name']) ?></span>
                            </div>
                        </td>
                        <td><span class="lrn-badge"><?= htmlspecialchars($stu['lrn']) ?></span></td>
                        <td>
                            <?php if ($att && $att['time_in']): ?>
                                <span style="color:var(--success); font-weight:600;"><?= date('h:i A', strtotime($att['time_in'])) ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">--:--</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($att && $att['time_out']): ?>
                                <span style="color:var(--info); font-weight:600;"><?= date('h:i A', strtotime($att['time_out'])) ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">--:--</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($stu['status'] ?? '') !== 'active'): ?>
                                <span class="badge badge-warning">Inactive</span>
                            <?php elseif ($att): ?>
                                <span class="status-badge <?= $att['status'] === 'present' ? 'status-active' : ($att['status'] === 'late' ? 'status-badge' : 'status-inactive') ?>"
                                    style="<?= $att['status'] === 'late' ? 'background:var(--warning-bg);color:var(--warning);' : '' ?>">
                                    <?= ucfirst($att['status']) ?>
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-inactive"><i class="fas fa-times-circle" style="margin-right:3px;"></i> Absent</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-graduate"></i>
                <h3>No Students</h3>
                <p>This section has no enrolled students yet.</p>
            </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</main>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
