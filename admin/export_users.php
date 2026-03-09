<?php
session_start();
require_once '../config/database.php';

// Optional: restrict to logged-in admin if you use a session flag
// if (!isset($_SESSION['admin_name'])) { http_response_code(403); exit('Forbidden'); }

$conn = getDBConnection();


// Fetch all users with their latest attendance (last time_in and time_out)
$sql = "SELECT u.id, u.name, u.level, u.role, u.sport, u.coach, u.assistant_coach, u.chaperon, u.status, u.created_at,
    (
        SELECT a.time_in FROM attendance a WHERE a.user_id = u.id AND a.time_in IS NOT NULL ORDER BY a.date DESC, a.time_in DESC LIMIT 1
    ) as last_time_in,
    (
        SELECT a.time_out FROM attendance a WHERE a.user_id = u.id AND a.time_out IS NOT NULL ORDER BY a.date DESC, a.time_out DESC LIMIT 1
    ) as last_time_out
FROM users u ORDER BY u.id ASC";
$result = $conn->query($sql);
if (!$result) {
    http_response_code(500);
    exit('Database error');
}

$filename = 'users_export_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
// BOM for Excel to recognize UTF-8
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');


// Header row
fputcsv($out, ['ID','Name','Level','Category','Event','Coach','Assistant Coach','Chaperon','Status','Created At','Last Time In','Last Time Out']);


while ($row = $result->fetch_assoc()) {
    $last_time_in = $row['last_time_in'] ? date('h:i A', strtotime($row['last_time_in'])) : '';
    $last_time_out = $row['last_time_out'] ? date('h:i A', strtotime($row['last_time_out'])) : '';
    fputcsv($out, [
        $row['id'],
        $row['name'],
        $row['level'],
        $row['role'],
        $row['sport'],
        $row['coach'],
        $row['assistant_coach'],
        $row['chaperon'],
        $row['status'],
        $row['created_at'],
        $last_time_in,
        $last_time_out
    ]);
}

fclose($out);
exit();
