<?php
/**
 * API endpoint to generate Word document using Python
 * Receives base64 images and returns a properly formatted .docx file
 */

header('Content-Type: application/json');

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['images']) || empty($data['images'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No images provided']);
    exit;
}

// Create temp directory for files
$tempDir = sys_get_temp_dir();
$timestamp = date('Y-m-d_H-i-s');
$inputFile = $tempDir . '/qr_word_input_' . $timestamp . '_' . uniqid() . '.json';
$outputFile = $tempDir . '/QR_Attendance_IDs_' . $timestamp . '.docx';

// Prepare input data for Python script
$pythonInput = [
    'images' => $data['images'],
    'output_path' => $outputFile,
    'ids_per_page' => isset($data['idsPerPage']) ? intval($data['idsPerPage']) : 4
];

// Write input to temp file
file_put_contents($inputFile, json_encode($pythonInput));

// Path to Python script
$scriptPath = __DIR__ . '/generate_word.py';

// Try different Python commands
$pythonCommands = ['python', 'python3', 'py'];
$pythonCmd = null;

foreach ($pythonCommands as $cmd) {
    $testOutput = shell_exec($cmd . ' --version 2>&1');
    if ($testOutput && strpos($testOutput, 'Python') !== false) {
        $pythonCmd = $cmd;
        break;
    }
}

if (!$pythonCmd) {
    // Cleanup
    @unlink($inputFile);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Python not found on system. Please install Python 3.']);
    exit;
}

// Execute Python script
$command = escapeshellcmd($pythonCmd) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($inputFile) . ' 2>&1';
$output = shell_exec($command);

// Cleanup input file
@unlink($inputFile);

// Parse Python output
$result = json_decode($output, true);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to parse Python output: ' . $output]);
    exit;
}

if (!$result['success']) {
    http_response_code(500);
    echo json_encode($result);
    exit;
}

// Check if output file exists
if (!file_exists($outputFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Output file was not created']);
    exit;
}

// Read the file and return as base64
$fileContent = file_get_contents($outputFile);
$base64Content = base64_encode($fileContent);

// Cleanup output file
@unlink($outputFile);

// Return success with file data
echo json_encode([
    'success' => true,
    'filename' => 'QR_Attendance_IDs_' . date('Y-m-d') . '.docx',
    'data' => $base64Content,
    'message' => $result['message']
]);
