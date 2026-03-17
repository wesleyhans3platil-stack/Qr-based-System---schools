<?php
/**
 * Session debug endpoint — check if sessions persist.
 * Access: /api/session_debug.php
 * DELETE THIS FILE after debugging.
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$conn = getDBConnection();

// Check if php_sessions table exists and has data
$tableExists = $conn->query("SHOW TABLES LIKE 'php_sessions'");
$sessionCount = 0;
if ($tableExists && $tableExists->num_rows > 0) {
    $r = $conn->query("SELECT COUNT(*) as cnt FROM php_sessions");
    $sessionCount = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
}

// Fetch the raw session row from the database (if present)
$sessionRow = null;
$sessionStmt = $conn->prepare("SELECT sess_id, sess_data, sess_time FROM php_sessions WHERE sess_id = ?");
if ($sessionStmt) {
    $sid = session_id();
    $sessionStmt->bind_param('s', $sid);
    $sessionStmt->execute();
    $res = $sessionStmt->get_result();
    if ($res && $res->num_rows > 0) {
        $sessionRow = $res->fetch_assoc();
    }
}

// Try to decode raw session data to JSON (for readability)
$rawSessionData = null;
if ($sessionRow && isset($sessionRow['sess_data'])) {
    // PHP session data format is not JSON; show raw string and a simplified decode attempt
    $rawSessionData = $sessionRow['sess_data'];
    // Attempt minimal decode (supports simple key|value strings)
    $decoded = [];
    $parts = explode('|', $rawSessionData);
    for ($i = 0; $i < count($parts) - 1; $i += 2) {
        $key = $parts[$i];
        $value = $parts[$i + 1];
        $decoded[$key] = $value;
    }
    if (!empty($decoded)) {
        $rawSessionData = $decoded;
    }
}

echo json_encode([
    'session_status' => session_status(),
    'session_id' => session_id(),
    'session_data' => [
        'admin_id' => $_SESSION['admin_id'] ?? null,
        'admin_role' => $_SESSION['admin_role'] ?? null,
        'admin_name' => $_SESSION['admin_name'] ?? null,
    ],
    'db_sessions_table_exists' => ($tableExists && $tableExists->num_rows > 0),
    'db_sessions_count' => $sessionCount,
    'session_row' => $sessionRow,
    'session_row_decoded' => $rawSessionData,
    'php_version' => PHP_VERSION,
    'session_save_handler' => ini_get('session.save_handler'),
    'server_time' => date('Y-m-d H:i:s'),
], JSON_PRETTY_PRINT);
