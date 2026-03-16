<?php
/**
 * Google Sign-In Verification Endpoint
 * Receives the Google ID token from the frontend, verifies it,
 * and logs in the matching admin by email.
 */
require_once '../config/database.php';
header('Content-Type: application/json');

$conn = getDBConnection();

// Get the credential (ID token) from POST
$credential = $_POST['credential'] ?? '';
if (empty($credential)) {
    echo json_encode(['success' => false, 'message' => 'No credential provided.']);
    exit;
}

// Get Google Client ID from settings
$r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='google_client_id'");
$client_id = $r ? ($r->fetch_assoc()['setting_value'] ?? '') : '';
if (empty($client_id)) {
    echo json_encode(['success' => false, 'message' => 'Google Sign-In is not configured.']);
    exit;
}

// Verify the Google ID token using Google's tokeninfo endpoint
$verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
$ch = curl_init($verify_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200 || !$response) {
    echo json_encode(['success' => false, 'message' => 'Failed to verify Google token.']);
    exit;
}

$payload = json_decode($response, true);
if (!$payload || !isset($payload['email'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid token payload.']);
    exit;
}

// Verify the audience matches our client ID
if (($payload['aud'] ?? '') !== $client_id) {
    echo json_encode(['success' => false, 'message' => 'Token audience mismatch.']);
    exit;
}

// Verify email is verified
if (($payload['email_verified'] ?? 'false') !== 'true') {
    echo json_encode(['success' => false, 'message' => 'Email not verified by Google.']);
    exit;
}

$email = strtolower($payload['email']);

// Find admin by email
$stmt = $conn->prepare("SELECT id, username, full_name, role, school_id FROM admins WHERE LOWER(email) = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

if (!$admin) {
    echo json_encode(['success' => false, 'message' => 'No account linked to ' . htmlspecialchars($email) . '. Please contact the administrator.']);
    exit;
}

// Log in the admin
$_SESSION['admin_id'] = $admin['id'];
$_SESSION['admin_username'] = $admin['username'];
$_SESSION['admin_name'] = $admin['full_name'];
$_SESSION['admin_role'] = $admin['role'];
$_SESSION['admin_school_id'] = $admin['school_id'];

$conn->query("UPDATE admins SET last_login = NOW() WHERE id = " . $admin['id']);

// Determine redirect URL based on role
switch ($admin['role']) {
    case 'superintendent':
        $redirect = 'admin/sds_dashboard.php';
        break;
    case 'asst_superintendent':
        $redirect = 'admin/asds_dashboard.php';
        break;
    case 'principal':
        $redirect = 'admin/principal_dashboard.php';
        break;
    default:
        $redirect = 'admin/dashboard.php';
        break;
}

echo json_encode(['success' => true, 'redirect' => $redirect, 'name' => $admin['full_name']]);
