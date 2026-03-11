<?php
/**
 * Lightweight polling endpoint for real-time data sync.
 * Returns a hash of current data state so clients can detect changes.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
$conn = getDBConnection();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$today = date('Y-m-d');
$role = $_SESSION['admin_role'] ?? '';
$school_id = $_SESSION['admin_school_id'] ?? null;

// Build school filter
$school_where = '';
if ($role === 'principal' && $school_id) {
    $school_where = " AND person_id IN (SELECT id FROM students WHERE school_id = " . (int)$school_id . ")";
}

// Get today's attendance count and latest scan time
$r = $conn->query("SELECT COUNT(*) as cnt, MAX(created_at) as last_scan 
                    FROM attendance 
                    WHERE date = '$today' $school_where");
$row = $r->fetch_assoc();

// Get total student count (changes when students are added/removed)
$stu_filter = ($role === 'principal' && $school_id) ? " AND school_id = " . (int)$school_id : "";
$sr = $conn->query("SELECT COUNT(*) as cnt FROM students WHERE status='active' $stu_filter");
$students = $sr->fetch_assoc();

// Create a hash from the key metrics - changes when any data updates
$state = $row['cnt'] . '|' . ($row['last_scan'] ?? '') . '|' . $students['cnt'];
$hash = md5($state);

echo json_encode([
    'hash' => $hash,
    'attendance_count' => (int)$row['cnt'],
    'last_scan' => $row['last_scan'],
    'total_students' => (int)$students['cnt'],
    'server_time' => date('Y-m-d H:i:s')
]);
