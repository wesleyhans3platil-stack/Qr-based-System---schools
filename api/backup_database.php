<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    http_response_code(403);
    die('Unauthorized');
}

$conn = getDBConnection();

$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

$backup = "-- QR Attendance System Database Backup\n";
$backup .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
$backup .= "-- Database: " . DB_NAME . "\n\n";
$backup .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    // Skip file_storage to keep backup size manageable
    if ($table === 'file_storage') continue;

    // CREATE TABLE
    $create = $conn->query("SHOW CREATE TABLE `$table`");
    if ($create) {
        $row = $create->fetch_row();
        $backup .= "DROP TABLE IF EXISTS `$table`;\n";
        $backup .= $row[1] . ";\n\n";
    }

    // INSERT data
    $data = $conn->query("SELECT * FROM `$table`");
    if ($data && $data->num_rows > 0) {
        while ($row = $data->fetch_assoc()) {
            $values = array_map(function($v) use ($conn) {
                if ($v === null) return 'NULL';
                return "'" . $conn->real_escape_string($v) . "'";
            }, array_values($row));
            $backup .= "INSERT INTO `$table` VALUES(" . implode(',', $values) . ");\n";
        }
        $backup .= "\n";
    }
}

$backup .= "SET FOREIGN_KEY_CHECKS=1;\n";

$filename = 'qr_attendance_backup_' . date('Y-m-d_His') . '.sql';

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($backup));
echo $backup;
exit;
