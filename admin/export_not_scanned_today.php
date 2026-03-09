<?php
// Export users who have not scanned today to CSV
include_once '../config/database.php';

// Get today's date
$today = date('Y-m-d');

// Connect to database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// Query users who have NOT scanned today
$sql = "SELECT u.id, u.name, u.level, u.role FROM users u WHERE u.id NOT IN (
    SELECT user_id FROM attendance WHERE date = ?
)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=not_scanned_today_users.csv');

$output = fopen('php://output', 'w');
// CSV header
fputcsv($output, ['ID', 'Category', 'Level', 'Name']);

// Output user rows
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [$row['id'], $row['role'], $row['level'], $row['name']]);
}

fclose($output);
$stmt->close();
$conn->close();
exit;
