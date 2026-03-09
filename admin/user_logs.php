<?php
// admin/user_logs.php
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    echo json_encode(['error' => 'Missing or invalid user_id']);
    exit;
}
$user_id = (int)$_GET['user_id'];

$conn = getDBConnection();

// Get user info
$stmt = $conn->prepare("SELECT id, name FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_res = $stmt->get_result();
if ($user_res->num_rows === 0) {
    echo json_encode(['error' => 'User not found']);
    exit;
}
$user = $user_res->fetch_assoc();

// Get detailed attendance events from attendance_logs
$stmt = $conn->prepare("SELECT event_date, event_time, event_type FROM attendance_logs WHERE user_id = ? ORDER BY event_date DESC, event_time DESC LIMIT 1000");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$events = [];
while ($row = $res->fetch_assoc()) {
    $events[] = $row;
}

// Organize events by date and pair IN/OUT to sessions
$logs_by_date = [];
// iterate in reverse chronological order per date: we fetched DESC; we'll process per date
$events_by_date = [];
foreach ($events as $e) {
    $date = $e['event_date'];
    if (!isset($events_by_date[$date])) $events_by_date[$date] = [];
    $events_by_date[$date][] = $e;
}

foreach ($events_by_date as $date => $evlist) {
    // sort ascending by time for pairing
    usort($evlist, function($a,$b){ return strcmp($a['event_time'],$b['event_time']); });
    $sessions = [];
    $current_in = null;
    foreach ($evlist as $ev) {
        if ($ev['event_type'] === 'IN') {
            $current_in = $ev['event_time'];
        } else if ($ev['event_type'] === 'OUT') {
            if ($current_in) {
                // pair IN -> OUT
                $inTs = strtotime($date . ' ' . $current_in);
                $outTs = strtotime($date . ' ' . $ev['event_time']);
                $duration = null;
                if ($outTs > $inTs) {
                    $diff = $outTs - $inTs;
                    $hours = floor($diff / 3600);
                    $minutes = floor(($diff % 3600) / 60);
                    $duration = ($hours > 0 ? $hours . 'h ' : '') . $minutes . 'm';
                } else {
                    $duration = '0m';
                }
                $sessions[] = ['time_in' => $current_in, 'time_out' => $ev['event_time'], 'duration' => $duration];
                $current_in = null;
            } else {
                // OUT without IN -> record OUT only
                $sessions[] = ['time_in' => null, 'time_out' => $ev['event_time'], 'duration' => null];
            }
        }
    }
    // if there's an unmatched IN remaining
    if ($current_in) {
        $sessions[] = ['time_in' => $current_in, 'time_out' => null, 'duration' => null];
    }
    $logs_by_date[$date] = $sessions;
}

echo json_encode(['user' => $user, 'logs_by_date' => $logs_by_date, 'events' => $events]);
