
<?php
require_once '../config/database.php';
$conn = getDBConnection();
$type = isset($_GET['type']) ? $_GET['type'] : '';
$where = '';
if ($type === 'inside') {
    $where = "WHERE time_in IS NOT NULL AND (time_out IS NULL OR time_out = '')";
} elseif ($type === 'outside') {
    $where = "WHERE time_out IS NOT NULL AND time_out != ''";
}
$sql = "SELECT guest_id, date, time_in, time_out FROM guest_attendance $where ORDER BY id DESC LIMIT 20";
$result = $conn->query($sql);
$guests = [];
while ($row = $result->fetch_assoc()) {
    $guests[] = $row;
}
header('Content-Type: application/json');
echo json_encode($guests);
