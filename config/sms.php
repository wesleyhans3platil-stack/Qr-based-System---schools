<?php
// SMS Configuration — Semaphore API (Philippines)
// Get your API key from https://semaphore.co

define('SMS_API_KEY', ''); // Set your Semaphore API key here
define('SMS_SENDER_NAME', 'SDO-SIPALAY');
define('SMS_ENABLED', false); // Set to true when API key is configured

/**
 * Send SMS via Semaphore API
 */
function sendSMS($phone_number, $message) {
    if (!SMS_ENABLED || empty(SMS_API_KEY)) {
        return ['success' => false, 'error' => 'SMS not configured'];
    }

    // Clean phone number — ensure +63 format
    $phone_number = preg_replace('/[^0-9]/', '', $phone_number);
    if (strlen($phone_number) === 10) {
        $phone_number = '63' . $phone_number;
    } elseif (strlen($phone_number) === 11 && $phone_number[0] === '0') {
        $phone_number = '63' . substr($phone_number, 1);
    }

    $params = [
        'apikey' => SMS_API_KEY,
        'number' => $phone_number,
        'message' => $message,
        'sendername' => SMS_SENDER_NAME
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.semaphore.co/api/v4/messages');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        return ['success' => true, 'response' => $response];
    }

    return ['success' => false, 'error' => 'HTTP ' . $http_code, 'response' => $response];
}

/**
 * Log SMS to database
 */
function logSMS($conn, $recipient_type, $recipient_name, $phone_number, $message, $student_id, $status) {
    $stmt = $conn->prepare("INSERT INTO sms_logs (recipient_type, recipient_name, phone_number, message, student_id, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssis", $recipient_type, $recipient_name, $phone_number, $message, $student_id, $status);
    $stmt->execute();
}
?>
