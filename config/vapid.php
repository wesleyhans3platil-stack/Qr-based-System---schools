<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * VAPID KEY CONFIGURATION — Web Push Notifications
 * ══════════════════════════════════════════════════════════════════
 * VAPID (Voluntary Application Server Identification) keys are used
 * to authenticate your server with push services (Google FCM, Mozilla, etc.)
 *
 * These keys are generated once and stored in the database.
 * To regenerate: DELETE FROM system_settings WHERE setting_key LIKE 'vapid_%';
 *                Then delete config/.db_initialized and reload.
 */

require_once __DIR__ . '/database.php';

/**
 * Get or generate VAPID keys.
 * Returns ['publicKey' => ..., 'privateKey' => ...]
 */
function getVapidKeys() {
    $conn = getDBConnection();

    // Check if keys already exist in DB
    $r = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('vapid_public_key', 'vapid_private_key')");
    $keys = [];
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $keys[$row['setting_key']] = $row['setting_value'];
        }
    }

    if (!empty($keys['vapid_public_key']) && !empty($keys['vapid_private_key'])) {
        return [
            'publicKey'  => $keys['vapid_public_key'],
            'privateKey' => $keys['vapid_private_key']
        ];
    }

    // Generate new VAPID key pair using OpenSSL (P-256 / prime256v1)
    $keyPair = generateVapidKeys();

    // Store in DB
    $pub = $conn->real_escape_string($keyPair['publicKey']);
    $priv = $conn->real_escape_string($keyPair['privateKey']);
    $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('vapid_public_key', '$pub') ON DUPLICATE KEY UPDATE setting_value = '$pub'");
    $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('vapid_private_key', '$priv') ON DUPLICATE KEY UPDATE setting_value = '$priv'");

    return $keyPair;
}

/**
 * Generate a VAPID key pair (P-256 / ECDSA).
 * Returns base64url-encoded public and private keys.
 */
function generateVapidKeys() {
    $config = [
        'curve_name'  => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];

    $key = openssl_pkey_new($config);
    if (!$key) {
        throw new Exception('Failed to generate VAPID keys. OpenSSL EC support required.');
    }

    $details = openssl_pkey_get_details($key);

    // Extract raw private key (32 bytes) and public key (65 bytes uncompressed)
    $privateKeyRaw = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
    $publicKeyRaw  = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    return [
        'publicKey'  => base64url_encode($publicKeyRaw),
        'privateKey' => base64url_encode($privateKeyRaw)
    ];
}

/**
 * Base64url encode (RFC 7515)
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64url decode
 */
function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}
