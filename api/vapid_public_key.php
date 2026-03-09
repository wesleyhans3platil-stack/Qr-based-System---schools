<?php
/**
 * Returns the VAPID public key for the push subscription.
 * GET /api/vapid_public_key.php
 */
require_once '../config/vapid.php';

header('Content-Type: application/json');

try {
    $keys = getVapidKeys();
    echo json_encode(['publicKey' => $keys['publicKey']]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to get VAPID keys: ' . $e->getMessage()]);
}
