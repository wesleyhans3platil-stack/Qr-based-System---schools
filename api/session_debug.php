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
    'php_version' => PHP_VERSION,
    'session_save_handler' => ini_get('session.save_handler'),
    'server_time' => date('Y-m-d H:i:s'),
], JSON_PRETTY_PRINT);
