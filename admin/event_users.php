<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

$event = $_GET['event'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'both'; // inside, outside, both

if (empty($event)) {
    echo json_encode(['error' => 'Missing event parameter']);
    exit;
}

// Fetch attendance rows for the date and event

// Select each user's latest attendance row for the requested date, then join to users to filter by event
$sql = "SELECT u.id, u.name, u.role, u.sport, u.level, a.time_in, a.time_out
        FROM users u
        JOIN (
            SELECT a2.* FROM attendance a2
            JOIN (
                SELECT user_id, MAX(id) as max_id FROM attendance WHERE date = ? GROUP BY user_id
            ) latest ON a2.user_id = latest.user_id AND a2.id = latest.max_id
        ) a ON u.id = a.user_id
        WHERE (u.sport = ? OR (u.sport IS NULL AND ? = 'Unassigned'))";

$stmt = $conn->prepare($sql);
$stmt->bind_param('sss', $date, $event, $event);
$stmt->execute();
$res = $stmt->get_result();

$inside = [];
$outside = [];
$seen = [];
while ($r = $res->fetch_assoc()) {
    $uid = (int)$r['id'];
    if (isset($seen[$uid])) continue; // keep first/latest row per user as provided by DB ordering
    $seen[$uid] = true;
    $item = [
        'id' => $uid,
        'name' => $r['name'],
        'role' => $r['role'],
        'sport' => $r['sport'],
        'level' => $r['level'],
        'time_in' => $r['time_in'],
        'time_out' => $r['time_out']
    ];
    if (!empty($r['time_out'])) {
        $outside[] = $item;
    } else {
        $inside[] = $item;
    }
}

$out = [];
if ($type === 'inside') $out['inside'] = $inside;
else if ($type === 'outside') $out['outside'] = $outside;
else $out = ['inside' => $inside, 'outside' => $outside];

echo json_encode(['status' => 'ok', 'event' => $event, 'date' => $date, 'data' => $out]);

exit;
?>
