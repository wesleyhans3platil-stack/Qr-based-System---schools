<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * WEB PUSH SENDER — Pure PHP (no Composer needed)
 * ══════════════════════════════════════════════════════════════════
 * Sends push notifications using Web Push protocol with VAPID auth.
 * Uses raw cURL + OpenSSL — no external libraries required.
 *
 * Usage:
 *   require_once 'config/web_push.php';
 *   $result = sendPushNotification($subscription, $payload, $vapidKeys);
 */

require_once __DIR__ . '/vapid.php';

/**
 * Send a push notification to a single subscriber.
 *
 * @param array $subscription ['endpoint', 'p256dh', 'auth']
 * @param array $payload      ['title', 'body', 'icon', 'url', 'tag']
 * @param array $vapidKeys    ['publicKey', 'privateKey'] (base64url)
 * @return array ['success' => bool, 'status' => int, 'reason' => string]
 */
function sendPushNotification($subscription, $payload, $vapidKeys = null) {
    if (!$vapidKeys) {
        $vapidKeys = getVapidKeys();
    }

    $endpoint = $subscription['endpoint'];
    $p256dh   = $subscription['p256dh'];
    $auth     = $subscription['auth'];

    // Encode payload as JSON
    $payloadJson = json_encode($payload);

    // Encrypt the payload using the subscription keys
    $encrypted = encryptPayload($payloadJson, $p256dh, $auth);
    if (!$encrypted) {
        return ['success' => false, 'status' => 0, 'reason' => 'Encryption failed'];
    }

    // Create VAPID Authorization header
    $parsedUrl = parse_url($endpoint);
    $audience  = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    $vapidHeaders = createVapidAuth($audience, $vapidKeys);

    // Send via cURL
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'Content-Length: ' . strlen($encrypted['ciphertext']),
        'TTL: 86400',
        'Urgency: high',
        'Topic: absence-alert',
        'Authorization: ' . $vapidHeaders['authorization'],
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $encrypted['ciphertext'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $success = ($httpCode >= 200 && $httpCode < 300);

    return [
        'success' => $success,
        'status'  => $httpCode,
        'reason'  => $success ? 'Delivered' : ($error ?: "HTTP $httpCode: $response")
    ];
}

/**
 * Send a notification to ALL subscribed admins.
 *
 * @param array $payload ['title', 'body', 'icon', 'url', 'tag']
 * @return array ['sent' => int, 'failed' => int, 'cleaned' => int]
 */
function sendPushToAll($payload) {
    $conn = getDBConnection();
    $vapidKeys = getVapidKeys();

    $r = $conn->query("SELECT id, admin_id, endpoint, p256dh, auth FROM push_subscriptions");
    if (!$r) return ['sent' => 0, 'failed' => 0, 'cleaned' => 0];

    $sent = 0;
    $failed = 0;
    $cleaned = 0;

    while ($sub = $r->fetch_assoc()) {
        $result = sendPushNotification($sub, $payload, $vapidKeys);

        if ($result['success']) {
            $sent++;
        } else {
            $failed++;
            // Remove expired/invalid subscriptions (HTTP 404 or 410)
            if (in_array($result['status'], [404, 410])) {
                $conn->query("DELETE FROM push_subscriptions WHERE id = " . (int)$sub['id']);
                $cleaned++;
            }
        }
    }

    // Log it
    $title = $conn->real_escape_string($payload['title'] ?? '');
    $body  = $conn->real_escape_string($payload['body'] ?? '');
    $status = $sent > 0 ? 'sent' : 'failed';
    $conn->query("INSERT INTO push_logs (title, body, status) VALUES ('$title', '$body', '$status')");

    return ['sent' => $sent, 'failed' => $failed, 'cleaned' => $cleaned];
}

/**
 * Encrypt payload for Web Push (aes128gcm).
 * Implements RFC 8291 / RFC 8188 content encoding.
 */
function encryptPayload($payload, $userPublicKey, $userAuth) {
    $userPublicKey = base64url_decode($userPublicKey);
    $userAuth      = base64url_decode($userAuth);

    if (strlen($userPublicKey) !== 65 || strlen($userAuth) !== 16) {
        return null;
    }

    // Generate local ECDH key pair
    $localKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $localDetails = openssl_pkey_get_details($localKey);
    $localPublicRaw = "\x04" . str_pad($localDetails['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($localDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);

    // Compute shared secret via ECDH
    // We need to derive the shared secret using the user's public key and our private key
    $sharedSecret = computeECDH($localKey, $userPublicKey);
    if (!$sharedSecret) return null;

    // Generate 16-byte salt
    $salt = random_bytes(16);

    // Key derivation (RFC 8291)
    // IKM = ECDH shared secret
    // PRK = HKDF-Extract(auth_secret, IKM)
    $prk = hash_hmac('sha256', $sharedSecret, $userAuth, true);

    // info for Content-Encryption-Key
    $cekInfo = "WebPush: info\x00" . $userPublicKey . $localPublicRaw;
    $ikm = hkdf($prk, $cekInfo, 32);

    // PRK for actual key/nonce derivation
    $prk2 = hash_hmac('sha256', $ikm, $salt, true);

    // Content encryption key (16 bytes)
    $cek = hkdf($prk2, "Content-Encoding: aes128gcm\x00", 16);

    // Nonce (12 bytes)
    $nonce = hkdf($prk2, "Content-Encoding: nonce\x00", 12);

    // Pad the payload with a delimiter byte
    $paddedPayload = $payload . "\x02";

    // Encrypt with AES-128-GCM
    $tag = '';
    $ciphertext = openssl_encrypt($paddedPayload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) return null;

    // Build aes128gcm content (RFC 8188)
    // Header: salt(16) + rs(4) + idlen(1) + keyid(65)
    $rs = pack('N', 4096);
    $header = $salt . $rs . chr(65) . $localPublicRaw;

    return ['ciphertext' => $header . $ciphertext . $tag];
}

/**
 * Compute ECDH shared secret.
 */
function computeECDH($localPrivateKey, $peerPublicKeyRaw) {
    // Create a PEM from the peer's raw public key
    $peerPem = convertRawPublicKeyToPEM($peerPublicKeyRaw);
    if (!$peerPem) return null;

    $peerKey = openssl_pkey_get_public($peerPem);
    if (!$peerKey) return null;

    // Use openssl_pkey_derive if available (PHP 7.3+)
    if (function_exists('openssl_pkey_derive')) {
        $shared = openssl_pkey_derive($peerKey, $localPrivateKey, 32);
        return $shared ?: null;
    }

    return null;
}

/**
 * Convert raw P-256 public key (65 bytes, uncompressed) to PEM format.
 */
function convertRawPublicKeyToPEM($rawKey) {
    if (strlen($rawKey) !== 65) return null;

    // ASN.1 DER prefix for P-256 uncompressed public key
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $rawKey;

    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return $pem;
}

/**
 * HKDF-Expand (simplified — single block, which is enough for our key sizes).
 */
function hkdf($prk, $info, $length) {
    $t = hash_hmac('sha256', $info . "\x01", $prk, true);
    return substr($t, 0, $length);
}

/**
 * Create VAPID Authorization header (JWT + key).
 */
function createVapidAuth($audience, $vapidKeys) {
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));

    $payload = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 86400,
        'sub' => 'mailto:admin@sipalay.edu.ph'
    ]));

    $signingInput = "$header.$payload";

    // Sign with ES256 (ECDSA P-256 + SHA-256)
    $privateKeyRaw = base64url_decode($vapidKeys['privateKey']);
    $publicKeyRaw  = base64url_decode($vapidKeys['publicKey']);

    // Build PEM from raw private key
    $pem = buildECPrivateKeyPEM($privateKeyRaw, $publicKeyRaw);

    $privKey = openssl_pkey_get_private($pem);
    if (!$privKey) {
        return ['authorization' => ''];
    }

    $signature = '';
    openssl_sign($signingInput, $derSig, $privKey, OPENSSL_ALGO_SHA256);

    // Convert DER signature to raw R||S (64 bytes)
    $rawSig = derToRaw($derSig);

    $jwt = "$signingInput." . base64url_encode($rawSig);
    $vapidPublicUrl = $vapidKeys['publicKey'];

    return [
        'authorization' => "vapid t=$jwt, k=$vapidPublicUrl"
    ];
}

/**
 * Build EC private key PEM from raw key bytes.
 */
function buildECPrivateKeyPEM($privateKeyRaw, $publicKeyRaw) {
    // ASN.1 DER structure for EC private key (P-256)
    $oid = hex2bin('06082a8648ce3d030107'); // OID for prime256v1

    $privKey = "\x04" . chr(strlen($privateKeyRaw)) . $privateKeyRaw;
    $pubKey  = "\x03" . chr(strlen($publicKeyRaw) + 1) . "\x00" . $publicKeyRaw;

    $seq = "\x02\x01\x01" . $privKey . "\xa0" . chr(strlen($oid)) . $oid . "\xa1" . chr(strlen($pubKey)) . $pubKey;
    $der = "\x30" . chr(strlen($seq)) . $seq;

    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/**
 * Convert DER-encoded ECDSA signature to raw R||S format (64 bytes).
 */
function derToRaw($der) {
    $offset = 2; // skip SEQUENCE tag + length

    // Read R
    if (ord($der[$offset]) !== 0x02) return $der;
    $rLen = ord($der[$offset + 1]);
    $r = substr($der, $offset + 2, $rLen);
    $offset += 2 + $rLen;

    // Read S
    if (ord($der[$offset]) !== 0x02) return $der;
    $sLen = ord($der[$offset + 1]);
    $s = substr($der, $offset + 2, $sLen);

    // Pad/trim to 32 bytes each
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

    return $r . $s;
}
