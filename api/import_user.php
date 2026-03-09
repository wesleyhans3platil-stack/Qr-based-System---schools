<?php
require_once '../config/database.php';

header('Content-Type: application/json');

// Get database connection
$conn = getDBConnection();

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
        exit;
    }
    
    $name = sanitize($input['name'] ?? '');
    $level = sanitize($input['level'] ?? '');
    $role = sanitize($input['role'] ?? '');
    $sport = sanitize($input['sport'] ?? '');
    $coach = sanitize($input['coach'] ?? '');
    $assistant_coach = sanitize($input['assistant_coach'] ?? '');
    $chaperon = sanitize($input['chaperon'] ?? '');
    
    if (empty($name) || empty($role)) {
        echo json_encode(['success' => false, 'error' => 'Name and Category are required']);
        exit;
    }
    
    // Generate QR code data
    $qr_data = json_encode([
        'name' => $name,
        'level' => $level,
        'role' => $role,
        'sport' => $sport,
        'coach' => $coach,
        'assistant_coach' => $assistant_coach,
        'chaperon' => $chaperon
    ]);
    
    // Insert user
    $stmt = $conn->prepare("INSERT INTO users (name, level, role, sport, coach, assistant_coach, chaperon, qr_code, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param("ssssssss", $name, $level, $role, $sport, $coach, $assistant_coach, $chaperon, $qr_data);
    
    if ($stmt->execute()) {
        $user_id = $conn->insert_id;
        
        // Update QR data with ID
        $qr_data = json_encode([
            'id' => $user_id,
            'name' => $name,
            'level' => $level,
            'role' => $role,
            'sport' => $sport,
            'coach' => $coach,
            'assistant_coach' => $assistant_coach,
            'chaperon' => $chaperon
        ]);
        
        $update_stmt = $conn->prepare("UPDATE users SET qr_code = ? WHERE id = ?");
        $update_stmt->bind_param("si", $qr_data, $user_id);
        $update_stmt->execute();
        
        echo json_encode([
            'success' => true,
            'user_id' => $user_id,
            'message' => 'User imported successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
