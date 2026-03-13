<?php
/**
 * Attendance List API - Returns attendance records for native Android app
 * GET params: date (YYYY-MM-DD), search (name query), page (1-based), limit (default 50)
 */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
$conn = getDBConnection();

$today = date('Y-m-d');
$filter_date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) $filter_date = $today;

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;

$where = "WHERE a.date = ? AND a.person_type = 'student'";
$params = [$filter_date];
$types = "s";

if ($admin_role === 'principal' && $admin_school_id) {
    $where .= " AND s.school_id = ?";
    $params[] = (int)$admin_school_id;
    $types .= "i";
}

if ($search !== '') {
    $where .= " AND s.name LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM attendance a
    INNER JOIN students s ON a.person_id = s.id AND s.status='active'
    $where";
$stmt = $conn->prepare($count_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];

// Get records
$sql = "SELECT a.id, a.date, a.time_in, a.time_out, a.status,
    s.name as student_name, s.lrn, sch.name as school_name,
    gl.name as grade_name, sec.name as section_name
    FROM attendance a
    INNER JOIN students s ON a.person_id = s.id AND s.status='active'
    LEFT JOIN schools sch ON s.school_id = sch.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    $where
    ORDER BY a.time_in DESC
    LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = [
        'id' => (int)$row['id'],
        'student_name' => $row['student_name'],
        'lrn' => $row['lrn'],
        'school_name' => $row['school_name'],
        'grade' => $row['grade_name'],
        'section' => $row['section_name'],
        'time_in' => $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : null,
        'time_out' => $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : null,
        'status' => $row['status'] ?? 'present',
        'date' => $row['date'],
    ];
}

echo json_encode([
    'records' => $records,
    'total' => (int)$total,
    'page' => $page,
    'limit' => $limit,
    'pages' => ceil($total / $limit),
]);
