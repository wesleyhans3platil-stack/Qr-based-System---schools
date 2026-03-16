<?php
require_once '../config/database.php';
$conn = getDBConnection();

if (!isset($_SESSION['admin_id'])) { header('Location: ../admin_login.php'); exit; }

$current_page = 'notifications';
$page_title = 'Notifications';
$admin_role = $_SESSION['admin_role'] ?? '';
$admin_school = $_SESSION['admin_school_id'] ?? null;

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// ── 2-Day Absence Flags: students absent both yesterday and today ──
$school_filter = '';
if ($admin_role === 'principal' && $admin_school) {
    $school_filter = " AND s.school_id = " . (int)$admin_school;
}

$absent_students = [];
$sql = "SELECT s.id, s.name, s.lrn, s.guardian_contact, sc.name as school_name, 
               gl.name as grade_name, sec.name as section_name,
               t.name as adviser_name, t.contact_number as adviser_contact, t.id as adviser_id
        FROM students s
        JOIN schools sc ON s.school_id = sc.id
        JOIN grade_levels gl ON s.grade_level_id = gl.id
        JOIN sections sec ON s.section_id = sec.id
        LEFT JOIN teachers t ON sec.adviser_id = t.id
        WHERE s.status = 'active' $school_filter
        AND DATE(s.created_at) < ?
        AND s.id NOT IN (SELECT person_id FROM attendance WHERE person_type='student' AND date = ?)
        AND s.id NOT IN (SELECT person_id FROM attendance WHERE person_type='student' AND date = ?)
        ORDER BY sc.name, gl.name, s.name
        LIMIT 500";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $today, $today, $yesterday);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $absent_students[] = $row;

// ── 2-Day Absence Flags: teachers absent both yesterday and today ──
$teacher_filter = '';
if ($admin_role === 'principal' && $admin_school) {
    $teacher_filter = " AND t.school_id = " . (int)$admin_school;
}

$absent_teachers = [];
$sql2 = "SELECT t.id, t.name, t.employee_id, sc.name as school_name
         FROM teachers t
         JOIN schools sc ON t.school_id = sc.id
         WHERE t.status = 'active' $teacher_filter
         AND t.id NOT IN (SELECT person_id FROM attendance WHERE person_type='teacher' AND date = ?)
         AND t.id NOT IN (SELECT person_id FROM attendance WHERE person_type='teacher' AND date = ?)
         ORDER BY sc.name, t.name
         LIMIT 200";
$stmt2 = $conn->prepare($sql2);
$stmt2->bind_param("ss", $today, $yesterday);
$stmt2->execute();
$r2 = $stmt2->get_result();
while ($row = $r2->fetch_assoc()) $absent_teachers[] = $row;

// ── User Activity: recent admin logins (super_admin only) ──
$admin_activity = [];
$role_labels = [
    'super_admin' => ['Super Admin', '#dc2626'],
    'superintendent' => ['Superintendent', '#7c3aed'],
    'asst_superintendent' => ['Asst. Superintendent', '#0891b2'],
    'principal' => ['Principal', '#059669']
];
if ($admin_role === 'super_admin') {
    $sql3 = "SELECT a.id, a.username, a.full_name, a.role, a.last_login, a.created_at, sc.name as school_name
             FROM admins a
             LEFT JOIN schools sc ON a.school_id = sc.id
             ORDER BY COALESCE(a.last_login, '2000-01-01') DESC
             LIMIT 50";
    $r3 = $conn->query($sql3);
    if ($r3) { while ($row = $r3->fetch_assoc()) $admin_activity[] = $row; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/header.php'; ?></head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-bell" style="color:var(--primary);margin-right:8px;"></i> Notifications</h1>
            <p>Absence alerts and user activity</p>
        </div>

        <!-- 2-Day Absence Flags -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-title" style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
                <i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i> 2-Day Consecutive Absence Alerts
                <span class="badge badge-error" style="margin-left:8px;"><?= count($absent_students) + count($absent_teachers) ?></span>
            </div>

            <?php if (empty($absent_students) && empty($absent_teachers)): ?>
                <div class="empty-state" style="padding:32px 0;">
                    <i class="fas fa-check-circle" style="color:#059669;font-size:2rem;"></i>
                    <h3 style="margin-top:12px;">No 2-Day Absences</h3>
                    <p>All students and teachers have attended within the last 2 school days.</p>
                </div>
            <?php else: ?>
                <?php if (!empty($absent_students)): ?>
                <div style="margin-bottom:20px;">
                    <h3 style="font-size:0.88rem;font-weight:600;color:var(--text);margin-bottom:10px;">
                        <i class="fas fa-user-graduate" style="color:#7c3aed;margin-right:6px;"></i> Students (<?= count($absent_students) ?>)
                    </h3>
                    <div class="table-wrapper" style="max-height:350px;overflow-y:auto;">
                        <table>
                            <thead><tr><th>Name</th><th>School</th><th>Grade & Section</th><th>Adviser</th></tr></thead>
                            <tbody>
                                <?php foreach ($absent_students as $s): ?>
                                <tr>
                                    <td style="font-weight:600;"><?= htmlspecialchars($s['name']) ?></td>
                                    <td style="font-size:0.82rem;"><?= htmlspecialchars($s['school_name']) ?></td>
                                    <td style="font-size:0.82rem;"><?= htmlspecialchars($s['grade_name']) ?> — <?= htmlspecialchars($s['section_name']) ?></td>
                                    <td>
                                        <?php if ($s['adviser_name']): ?>
                                            <a href="#" class="adviser-link" onclick="showAdviser(<?= (int)$s['adviser_id'] ?>, '<?= htmlspecialchars(addslashes($s['adviser_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($s['adviser_contact'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($s['school_name']), ENT_QUOTES) ?>'); return false;" style="color:var(--primary);font-weight:600;text-decoration:none;">
                                                <i class="fas fa-user-tie" style="margin-right:4px;"></i><?= htmlspecialchars($s['adviser_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);font-size:0.82rem;">No adviser</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($absent_teachers)): ?>
                <div>
                    <h3 style="font-size:0.88rem;font-weight:600;color:var(--text);margin-bottom:10px;">
                        <i class="fas fa-chalkboard-teacher" style="color:#0891b2;margin-right:6px;"></i> Teachers (<?= count($absent_teachers) ?>)
                    </h3>
                    <div class="table-wrapper" style="max-height:250px;overflow-y:auto;">
                        <table>
                            <thead><tr><th>Name</th><th>Employee ID</th><th>School</th></tr></thead>
                            <tbody>
                                <?php foreach ($absent_teachers as $t): ?>
                                <tr>
                                    <td style="font-weight:600;"><?= htmlspecialchars($t['name']) ?></td>
                                    <td><code style="font-size:0.8rem;"><?= htmlspecialchars($t['employee_id']) ?></code></td>
                                    <td style="font-size:0.82rem;"><?= htmlspecialchars($t['school_name']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($admin_role === 'super_admin'): ?>
        <!-- User Activity -->
        <div class="card">
            <div class="card-title" style="margin-bottom:16px;">
                <i class="fas fa-users-cog" style="color:var(--primary);"></i> User Activity
            </div>
            <?php if (empty($admin_activity)): ?>
                <div class="empty-state" style="padding:32px 0;">
                    <i class="fas fa-user-clock" style="font-size:2rem;color:var(--text-muted);"></i>
                    <h3>No activity yet</h3>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Name</th><th>Role</th><th>School</th><th>Last Login</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($admin_activity as $a):
                                $rl = $role_labels[$a['role']] ?? ['Unknown', '#6b7280'];
                                $isOnlineRecent = $a['last_login'] && (time() - strtotime($a['last_login'])) < 3600;
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($a['full_name']) ?></div>
                                    <div style="font-size:0.75rem;color:var(--text-muted);">@<?= htmlspecialchars($a['username']) ?></div>
                                </td>
                                <td><span class="badge" style="background:<?= $rl[1] ?>20;color:<?= $rl[1] ?>;font-size:0.72rem;"><?= $rl[0] ?></span></td>
                                <td style="font-size:0.82rem;"><?= htmlspecialchars($a['school_name'] ?? 'Division Office') ?></td>
                                <td style="font-size:0.82rem;">
                                    <?php if ($a['last_login']): ?>
                                        <?= date('M j, Y', strtotime($a['last_login'])) ?><br>
                                        <span style="color:var(--text-muted);font-size:0.75rem;"><?= date('h:i A', strtotime($a['last_login'])) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isOnlineRecent): ?>
                                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.78rem;color:#059669;"><span style="width:8px;height:8px;background:#059669;border-radius:50;display:inline-block;"></span> Active</span>
                                    <?php else: ?>
                                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.78rem;color:var(--text-muted);"><span style="width:8px;height:8px;background:#d1d5db;border-radius:50%;display:inline-block;"></span> Offline</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<!-- Adviser Profile Modal -->
<div id="adviserModal" style="display:none; position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff; border-radius:16px; padding:28px; max-width:380px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3); position:relative;">
        <button onclick="document.getElementById('adviserModal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text-muted);">&times;</button>
        <div style="text-align:center; margin-bottom:20px;">
            <div id="adviserAvatar" style="width:64px;height:64px;border-radius:50%;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:700;margin-bottom:12px;"></div>
            <h3 id="adviserName" style="font-size:1.1rem;font-weight:700;margin:0;"></h3>
            <p id="adviserSchool" style="font-size:0.82rem;color:var(--text-muted);margin:4px 0 0;"></p>
        </div>
        <div style="background:var(--card-bg-alt);border-radius:10px;padding:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                <i class="fas fa-phone" style="color:var(--primary);"></i>
                <div>
                    <div style="font-size:0.75rem;color:var(--text-muted);">Contact Number</div>
                    <div id="adviserPhone" style="font-weight:600;font-size:0.9rem;"></div>
                </div>
            </div>
            <div id="adviserActions" style="display:flex;gap:8px;margin-top:12px;"></div>
        </div>
    </div>
</div>

<script>
function showAdviser(id, name, contact, school) {
    document.getElementById('adviserAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('adviserName').textContent = name;
    document.getElementById('adviserSchool').textContent = school;
    var phoneEl = document.getElementById('adviserPhone');
    var actionsEl = document.getElementById('adviserActions');
    if (contact) {
        phoneEl.textContent = contact;
        actionsEl.innerHTML =
            '<a href="tel:' + contact + '" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px;background:var(--primary);color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.85rem;"><i class="fas fa-phone"></i> Call</a>' +
            '<a href="sms:' + contact + '" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px;background:#059669;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.85rem;"><i class="fas fa-sms"></i> SMS</a>';
    } else {
        phoneEl.textContent = 'Not available';
        actionsEl.innerHTML = '<span style="color:var(--text-muted);font-size:0.82rem;">No contact number on file</span>';
    }
    var modal = document.getElementById('adviserModal');
    modal.style.display = 'flex';
}
</script>

<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
