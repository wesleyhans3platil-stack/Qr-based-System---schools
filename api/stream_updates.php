<?php
/**
 * Server-Sent Events (SSE) stream for real-time dashboard updates.
 * Clients can listen to this endpoint and trigger polls when new data arrives.
 *
 * This is lightweight and works over HTTPS without needing a separate WebSocket server.
 */

require_once __DIR__ . '/../config/database.php';

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Disable PHP execution time limit for the streaming loop
set_time_limit(0);

// Flush buffering immediately
while (ob_get_level()) ob_end_flush();

// Helper: get the last update timestamp from system_settings
function getLastUpdateTs($conn) {
    $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='last_update_at'");
    if ($r && $row = $r->fetch_assoc()) {
        return $row['setting_value'];
    }
    return null;
}

$conn = getDBConnection();
$lastTs = getLastUpdateTs($conn);

// Send initial heartbeat so client knows connection is alive
function sendEvent($id, $data) {
    echo "id: {$id}\n";
    echo "data: {$data}\n\n";
    @ob_flush();
    @flush();
}

$start = time();
$heartbeat = 0;

while (!connection_aborted() && (time() - $start) < 55) {
    $current = getLastUpdateTs($conn);
    if ($current !== $lastTs) {
        $lastTs = $current;
        sendEvent(time(), json_encode(['type' => 'refresh', 'ts' => $current]));
    }

    // heartbeat comment to keep connection alive (every 15s)
    if (++$heartbeat >= 3) {
        echo ": heartbeat\n\n";
        @ob_flush();
        @flush();
        $heartbeat = 0;
    }

    sleep(5);
}

// End stream cleanly
echo "event: close\ndata: \n\n";
@ob_flush();
@flush();
