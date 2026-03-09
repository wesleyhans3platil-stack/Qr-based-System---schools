<?php
require_once '../config/database.php';
header('Content-Type: application/json');
$conn = getDBConnection();
$guest_id = isset($_GET['guest_id']) ? trim($_GET['guest_id']) : '';
if (empty($guest_id)) {
    echo json_encode(['error' => 'guest_id required']);
    exit;
}
$stmt = $conn->prepare("SELECT id, guest_id, date, time_in, time_out, created_at FROM guest_attendance WHERE guest_id = ? ORDER BY date DESC, id DESC LIMIT 100");
$stmt->bind_param('s', $guest_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
echo json_encode(['guest_id' => $guest_id, 'rows' => $rows]);
