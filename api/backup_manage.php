<?php
/**
 * Database Backup Management API
 * Actions: create, restore, delete, list
 * Super admin only
 */
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$conn = getDBConnection();

// Ensure db_backups table exists
$conn->query("CREATE TABLE IF NOT EXISTS db_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_name VARCHAR(255) NOT NULL,
    backup_date DATE NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    table_count INT NOT NULL DEFAULT 0,
    row_count INT NOT NULL DEFAULT 0,
    backup_data LONGBLOB NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_backup_date (backup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        createBackup($conn);
        break;
    case 'restore':
        restoreBackup($conn);
        break;
    case 'delete':
        deleteBackup($conn);
        break;
    case 'list':
        listBackups($conn);
        break;
    case 'download':
        downloadBackup($conn);
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}

function createBackup($conn) {
    $today = date('Y-m-d');
    
    // Generate backup SQL
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    $backup = "-- QR Attendance System Database Backup\n";
    $backup .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $backup .= "-- Database: " . DB_NAME . "\n\n";
    $backup .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $totalRows = 0;
    $tableCount = 0;

    foreach ($tables as $table) {
        // Skip backup table itself and file_storage (too large)
        if ($table === 'file_storage' || $table === 'db_backups') continue;
        $tableCount++;

        $create = $conn->query("SHOW CREATE TABLE `$table`");
        if ($create) {
            $row = $create->fetch_row();
            $backup .= "DROP TABLE IF EXISTS `$table`;\n";
            $backup .= $row[1] . ";\n\n";
        }

        $data = $conn->query("SELECT * FROM `$table`");
        if ($data && $data->num_rows > 0) {
            $totalRows += $data->num_rows;
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

    $backupName = 'backup_' . $today . '_' . date('His');
    $fileSize = strlen($backup);

    // Store in database
    $stmt = $conn->prepare("INSERT INTO db_backups (backup_name, backup_date, file_size, table_count, row_count, backup_data) VALUES (?, ?, ?, ?, ?, ?)");
    $null = null;
    $stmt->bind_param("ssiisb", $backupName, $today, $fileSize, $tableCount, $totalRows, $null);
    $stmt->send_long_data(5, $backup);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Backup created successfully',
            'backup' => [
                'id' => $conn->insert_id,
                'name' => $backupName,
                'date' => $today,
                'size' => $fileSize,
                'tables' => $tableCount,
                'rows' => $totalRows
            ]
        ]);
    } else {
        echo json_encode(['error' => 'Failed to create backup: ' . $conn->error]);
    }
}

function restoreBackup($conn) {
    $id = (int)($_POST['backup_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['error' => 'Invalid backup ID']);
        return;
    }

    // Fetch backup data
    $stmt = $conn->prepare("SELECT backup_name, backup_data FROM db_backups WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $backup = $result->fetch_assoc();

    if (!$backup) {
        echo json_encode(['error' => 'Backup not found']);
        return;
    }

    $sql = $backup['backup_data'];
    
    // Split into individual statements and execute
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    
    $statements = array_filter(array_map('trim', explode(";\n", $sql)));
    $executed = 0;
    $errors = [];
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0 || $statement === 'SET FOREIGN_KEY_CHECKS=0' || $statement === 'SET FOREIGN_KEY_CHECKS=1') {
            continue;
        }
        // Skip if it would drop/modify db_backups or file_storage
        if (preg_match('/`(db_backups|file_storage)`/i', $statement)) {
            continue;
        }
        if ($conn->query($statement)) {
            $executed++;
        } else {
            $errors[] = $conn->error;
            if (count($errors) > 5) break; // Stop after too many errors
        }
    }
    
    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    if (empty($errors)) {
        echo json_encode([
            'success' => true,
            'message' => "Backup '{$backup['backup_name']}' restored successfully ($executed statements executed)"
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => "Restored with $executed statements. " . count($errors) . " warnings.",
            'warnings' => array_slice($errors, 0, 3)
        ]);
    }
}

function deleteBackup($conn) {
    $id = (int)($_POST['backup_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['error' => 'Invalid backup ID']);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM db_backups WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Backup deleted']);
    } else {
        echo json_encode(['error' => 'Backup not found']);
    }
}

function listBackups($conn) {
    $result = $conn->query("SELECT id, backup_name, backup_date, file_size, table_count, row_count, created_at FROM db_backups ORDER BY created_at DESC");
    $backups = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $backups[] = $row;
        }
    }
    echo json_encode(['success' => true, 'backups' => $backups]);
}

function downloadBackup($conn) {
    $id = (int)($_GET['backup_id'] ?? 0);
    if ($id <= 0) { die('Invalid ID'); }

    $stmt = $conn->prepare("SELECT backup_name, backup_data FROM db_backups WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $backup = $result->fetch_assoc();

    if (!$backup) { die('Backup not found'); }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $backup['backup_name'] . '.sql"');
    header('Content-Length: ' . strlen($backup['backup_data']));
    echo $backup['backup_data'];
    exit;
}
