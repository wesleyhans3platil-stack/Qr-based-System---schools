<?php
// Export users currently outside the campus to CSV in proper CSV format
require_once '../config/database.php';
$conn = getDBConnection();
$today = date('Y-m-d');
$filename = 'Outside_Report_' . $today . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
$out = fopen('php://output', 'w');

// Header row
fputcsv($out, ['ID', 'Name', 'Event', 'Level', 'Last Time In', 'Last Time Out', 'Hours Since Out', 'Status']);


// Only include users whose latest attendance record for today is a time_out (currently outside)
$sql = "SELECT u.id, u.name, u.sport, u.level, a.time_in, a.time_out, u.role
        FROM users u
        JOIN attendance a ON a.user_id = u.id
        WHERE a.date = ?
        AND a.id = (
            SELECT id FROM attendance a2 WHERE a2.user_id = u.id AND a2.date = ? ORDER BY a2.id DESC LIMIT 1
        )
        AND a.time_out IS NOT NULL
        ORDER BY a.time_out DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $today, $today);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $last_time_in = $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '';
    $last_time_out = $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '';
    // Calculate hours since out
    $hours_since_out = '';
    if ($row['time_out']) {
        $out_time = strtotime($today . ' ' . $row['time_out']);
        $now = time();
        $diff = $now - $out_time;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $hours_since_out = sprintf('%dh %02dm', $h, $m);
    }
    $status = 'Outside the Campus';
    fputcsv($out, [
        '#' . $row['id'],
        $row['name'],
        $row['sport'],
        $row['level'],
        $last_time_in,
        $last_time_out,
        $hours_since_out,
        $status
    ]);
}

fclose($out);
exit();
