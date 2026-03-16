<?php
require_once '../config/database.php';
$conn = getDBConnection();

if (!isset($_SESSION['admin_id'])) { header('Location: ../admin_login.php'); exit; }

$current_page = 'sms_logs';
$page_title = 'SMS Logs';

$filter_date = sanitize($_GET['date'] ?? '');
$search = sanitize($_GET['search'] ?? '');

$where = ["1=1"];
if ($filter_date) $where[] = "DATE(sl.sent_at) = '$filter_date'";
if ($search) $where[] = "(sl.phone_number LIKE '%$search%' OR sl.message LIKE '%$search%')";

$sql = "SELECT sl.* FROM sms_logs sl WHERE " . implode(' AND ', $where) . " ORDER BY sl.sent_at DESC LIMIT 200";
$logs = [];
$r = $conn->query($sql);
if ($r) { while ($row = $r->fetch_assoc()) $logs[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/header.php'; ?></head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-sms" style="color:var(--primary);margin-right:8px;"></i> SMS Notification Logs</h1>
            <p>View all sent SMS notifications</p>
        </div>

        <form method="GET" class="filters-bar">
            <div class="filter-group"><label>Date</label><input type="date" name="date" class="form-control" value="<?= $filter_date ?>" onchange="this.form.submit()"></div>
            <div class="filter-group"><label>Search</label><input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Phone or message..."></div>
            <div class="filter-group" style="justify-content:flex-end;"><label>&nbsp;</label><button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button></div>
        </form>

        <div class="card" style="padding:0;">
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Date/Time</th><th>Phone</th><th>Message</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="4"><div class="empty-state"><i class="fas fa-sms"></i><h3>No SMS logs</h3><p>SMS notifications will appear here once sent.</p></div></td></tr>
                        <?php else: foreach ($logs as $l): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?= date('M j, Y h:i A', strtotime($l['sent_at'])) ?></td>
                                <td><code><?= htmlspecialchars($l['phone_number']) ?></code></td>
                                <td style="max-width:400px;"><?= htmlspecialchars($l['message']) ?></td>
                                <td><span class="badge <?= $l['status'] === 'sent' ? 'badge-success' : 'badge-error' ?>"><?= ucfirst($l['status']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
