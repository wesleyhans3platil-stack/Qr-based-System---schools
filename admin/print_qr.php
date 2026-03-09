<?php
session_start();
require_once '../config/database.php';
$conn = getDBConnection();

if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'], ['super_admin', 'principal'])) {
    header('Location: ../admin_login.php');
    exit;
}

$current_page = 'print_qr';
$page_title = 'Print QR Codes';

$school_id = (int)($_GET['school_id'] ?? 0);
$grade_id = (int)($_GET['grade_id'] ?? 0);
$section_id = (int)($_GET['section_id'] ?? 0);
$type = $_GET['type'] ?? 'students';
$layout = $_GET['layout'] ?? 'id_card';

// Fetch filter options
$schools = []; $r = $conn->query("SELECT id, name, code FROM schools WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $schools[] = $row;
$grades = []; $r = $conn->query("SELECT id, name FROM grade_levels ORDER BY id"); if ($r) while ($row = $r->fetch_assoc()) $grades[] = $row;
$sections_all = []; $r = $conn->query("SELECT id, name, school_id, grade_level_id FROM sections WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $sections_all[] = $row;

// Fetch persons to print
$persons = [];
if ($school_id > 0) {
    if ($type === 'students') {
        $sql = "SELECT s.*, sch.name as school_name, sch.code as school_code, gl.name as grade_name, sec.name as section_name, sec.track as track
                FROM students s
                LEFT JOIN schools sch ON s.school_id = sch.id
                LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE s.status = 'active' AND s.school_id = ?";
        $params = [$school_id];
        $types = "i";

        if ($grade_id > 0) { $sql .= " AND s.grade_level_id = ?"; $params[] = $grade_id; $types .= "i"; }
        if ($section_id > 0) { $sql .= " AND s.section_id = ?"; $params[] = $section_id; $types .= "i"; }
        $sql .= " ORDER BY s.name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $persons[] = $row;
    } else {
        $sql = "SELECT t.*, sch.name as school_name, sch.code as school_code
                FROM teachers t
                LEFT JOIN schools sch ON t.school_id = sch.id
                WHERE t.status = 'active' AND t.school_id = ?
                ORDER BY t.name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $persons[] = $row;
    }
}

$printing = isset($_GET['print']) && count($persons) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/header.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        /* ─── Print-specific styles ─────────────────────── */
        .qr-cards-grid {
            display: grid;
            gap: 16px;
        }
        .qr-cards-grid.id-card {
            grid-template-columns: repeat(3, 1fr);
        }
        .qr-cards-grid.a4-list {
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .qr-id-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            text-align: center;
            break-inside: avoid;
        }
        .qr-card-header {
            background: linear-gradient(135deg, #4338ca, #3730a3);
            color: #fff;
            padding: 12px 14px;
        }
        .qr-card-header .school-name {
            font-size: 0.7rem;
            font-weight: 600;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .qr-card-header .system-label {
            font-size: 0.6rem;
            opacity: 0.7;
            margin-top: 2px;
        }
        .qr-card-body {
            padding: 16px 14px;
        }
        .qr-card-body .qr-container {
            width: 120px;
            height: 120px;
            margin: 0 auto 10px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qr-card-body .person-name {
            font-size: 0.88rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        .qr-card-body .person-id {
            font-size: 0.72rem;
            color: #64748b;
            font-family: monospace;
            margin-bottom: 6px;
        }
        .qr-card-body .person-detail {
            font-size: 0.7rem;
            color: #94a3b8;
        }
        .qr-card-footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 6px 14px;
            font-size: 0.62rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ─── Compact A4 variant ───────────────────────── */
        .a4-list .qr-id-card .qr-card-body .qr-container {
            width: 90px;
            height: 90px;
        }
        .a4-list .qr-id-card .qr-card-header { padding: 8px 10px; }
        .a4-list .qr-id-card .qr-card-body { padding: 10px; }
        .a4-list .qr-id-card .person-name { font-size: 0.78rem; }

        /* ─── Print Media ──────────────────────────────── */
        @media print {
            body { background: white !important; }
            .sidebar, .page-header, .filter-card, .no-print, .mobile-menu-toggle { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .qr-cards-grid { gap: 8px !important; }
            .qr-id-card { border: 1px solid #ccc !important; border-radius: 8px !important; box-shadow: none !important; }
            .qr-cards-grid.id-card { grid-template-columns: repeat(3, 1fr) !important; }
            .qr-cards-grid.a4-list { grid-template-columns: repeat(4, 1fr) !important; }
        }

        /* Hide hamburger on QR print page to prevent overlap */
        .mobile-menu-toggle { display: none !important; }

        .filter-card { margin-bottom: 20px; }
        .filter-row {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
        }
        .filter-row .form-group { flex: 1; min-width: 150px; margin-bottom: 0; }
        .print-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
            justify-content: space-between;
        }
        .count-badge {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <!-- Override sidebar hamburger on this page -->
    <style>
        .mobile-menu-toggle { display: none !important; }
        @media print {
            .sidebar, .mobile-menu-toggle { display: none !important; }
            .main-content { margin-left: 0 !important; }
        }
    </style>
    <div class="main-content">
        <div class="page-header no-print">
            <h1><i class="fas fa-qrcode" style="color:var(--primary);margin-right:8px;"></i> Print QR Codes</h1>
            <p>Generate and print QR code ID cards for students and teachers</p>
        </div>

        <!-- ─── Filters ────────────────────────────────── -->
        <div class="card filter-card no-print">
            <div class="card-title"><i class="fas fa-filter"></i> Select Students / Teachers</div>
            <form method="GET">
                <div class="filter-row">
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" class="form-control" id="qr_type">
                            <option value="students" <?= $type === 'students' ? 'selected' : '' ?>>Students</option>
                            <option value="teachers" <?= $type === 'teachers' ? 'selected' : '' ?>>Teachers</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>School *</label>
                        <select name="school_id" class="form-control" required id="qr_school" onchange="filterQrSections()">
                            <option value="">Select School</option>
                            <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>" <?= $school_id == $sch['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="grade_group">
                        <label>Grade Level</label>
                        <select name="grade_id" class="form-control" id="qr_grade" onchange="filterQrSections()">
                            <option value="">All Grades</option>
                            <?php foreach ($grades as $g): ?><option value="<?= $g['id'] ?>" <?= $grade_id == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="section_group">
                        <label>Section</label>
                        <select name="section_id" class="form-control" id="qr_section">
                            <option value="">All Sections</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Layout</label>
                        <select name="layout" class="form-control">
                            <option value="id_card" <?= $layout === 'id_card' ? 'selected' : '' ?>>ID Card (3/row)</option>
                            <option value="a4_compact" <?= $layout === 'a4_compact' ? 'selected' : '' ?>>Compact (4/row)</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0;">
                        <button type="submit" class="btn btn-primary" style="white-space:nowrap;"><i class="fas fa-search"></i> Generate</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if (count($persons) > 0): ?>
        <!-- ─── Print Actions ──────────────────────────── -->
        <div class="print-actions no-print">
            <div class="count-badge">
                <i class="fas fa-<?= $type === 'students' ? 'user-graduate' : 'chalkboard-teacher' ?>"></i>
                <?= count($persons) ?> QR Code(s) Ready
            </div>
            <div style="display:flex;gap:10px;">
                <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print All</button>
            </div>
        </div>

        <!-- ─── QR Cards Grid ─────────────────────────── -->
        <div class="qr-cards-grid <?= $layout === 'a4_compact' ? 'a4-list' : 'id-card' ?>">
            <?php foreach ($persons as $p): ?>
            <div class="qr-id-card">
                <div class="qr-card-header">
                    <div class="school-name"><?= htmlspecialchars($p['school_name'] ?? 'School') ?></div>
                </div>
                <div class="qr-card-body">
                    <div class="qr-container" id="qr_<?= $p['id'] ?>_<?= $type ?>"></div>
                    <div class="person-name"><?= htmlspecialchars($p['name']) ?></div>
                    <?php if ($type === 'students'): ?>
                        <div class="person-id">LRN: <?= htmlspecialchars($p['lrn']) ?></div>
                        <div class="person-detail"><?= htmlspecialchars(($p['grade_name'] ?? '') . ' - ' . ($p['section_name'] ?? '')) ?></div>
                        <?php if (!empty($p['track'])): ?>
                            <div class="person-detail" style="font-weight:600;color:#4338ca;font-size:0.68rem;margin-top:2px;"><?= htmlspecialchars($p['track']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="person-id">ID: <?= htmlspecialchars($p['employee_id']) ?></div>
                        <div class="person-detail">Teacher</div>
                    <?php endif; ?>
                </div>
                <div class="qr-card-footer"><?= htmlspecialchars($p['qr_code'] ?? '') ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php elseif ($school_id > 0): ?>
        <div class="card" style="text-align:center;padding:60px 20px;">
            <i class="fas fa-inbox" style="font-size:3rem;color:var(--text-muted);margin-bottom:16px;"></i>
            <h3 style="color:var(--text-muted);font-weight:600;">No <?= $type ?> found</h3>
            <p style="color:var(--text-muted);font-size:0.85rem;">Try changing the filters above.</p>
        </div>
        <?php else: ?>
        <div class="card" style="text-align:center;padding:60px 20px;">
            <i class="fas fa-qrcode" style="font-size:3rem;color:var(--primary);opacity:0.3;margin-bottom:16px;"></i>
            <h3 style="color:var(--text-muted);font-weight:600;">Select a School</h3>
            <p style="color:var(--text-muted);font-size:0.85rem;">Choose a school and filters above to generate QR codes.</p>
        </div>
        <?php endif; ?>
    </div>

    <script>
    const allSections = <?= json_encode($sections_all) ?>;

    function filterQrSections() {
        const schoolId = document.getElementById('qr_school').value;
        const gradeId = document.getElementById('qr_grade').value;
        const sel = document.getElementById('qr_section');
        sel.innerHTML = '<option value="">All Sections</option>';
        allSections.forEach(s => {
            if ((!schoolId || s.school_id == schoolId) && (!gradeId || s.grade_level_id == gradeId)) {
                sel.innerHTML += `<option value="${s.id}">${s.name}</option>`;
            }
        });

        // Show/hide grade and section for teachers
        const isTeacher = document.getElementById('qr_type').value === 'teachers';
        document.getElementById('grade_group').style.display = isTeacher ? 'none' : '';
        document.getElementById('section_group').style.display = isTeacher ? 'none' : '';
    }

    document.getElementById('qr_type').addEventListener('change', filterQrSections);

    // Restore section selection
    <?php if ($section_id): ?>
    setTimeout(() => {
        filterQrSections();
        document.getElementById('qr_section').value = '<?= $section_id ?>';
    }, 100);
    <?php else: ?>
    filterQrSections();
    <?php endif; ?>

    // Generate QR codes
    <?php foreach ($persons as $p):
        $qr_val = $p['qr_code'] ?? ($type === 'students' ? 'STU-' . $p['lrn'] : 'TCH-' . $p['employee_id']);
    ?>
    new QRCode(document.getElementById('qr_<?= $p['id'] ?>_<?= $type ?>'), {
        text: '<?= addslashes($qr_val) ?>',
        width: <?= $layout === 'a4_compact' ? 90 : 120 ?>,
        height: <?= $layout === 'a4_compact' ? 90 : 120 ?>,
        colorDark: '#1e293b',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
    <?php endforeach; ?>
    </script>
</body>
</html>
