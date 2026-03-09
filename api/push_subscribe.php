<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * PUSH SUBSCRIBE API — Save/remove push notification subscriptions
 * ══════════════════════════════════════════════════════════════════
 * POST /api/push_subscribe.php
 *   Body: { "subscription": { "endpoint": "...", "keys": { "p256dh": "...", "auth": "..." } } }
 *
 * DELETE /api/push_subscribe.php
 *   Body: { "endpoint": "..." }
 */
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$conn = getDBConnection();
$admin_id = (int)$_SESSION['admin_id'];

// ── SUBSCRIBE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $sub = $input['subscription'] ?? null;

    if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid subscription data']);
        exit;
    }

    $endpoint = $sub['endpoint'];
    $p256dh = $sub['keys']['p256dh'];
    $auth = $sub['keys']['auth'];

    // Remove old subscription for this admin + endpoint (avoid duplicates)
    $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE admin_id = ? AND endpoint = ?");
    $stmt->bind_param("is", $admin_id, $endpoint);
    $stmt->execute();
    $stmt->close();

    // Insert new subscription
    $stmt = $conn->prepare("INSERT INTO push_subscriptions (admin_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $admin_id, $endpoint, $p256dh, $auth);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Notifications enabled']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save subscription']);
    }
    $stmt->close();
    exit;
}

// ── UNSUBSCRIBE ──
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $endpoint = $input['endpoint'] ?? '';

    if (empty($endpoint)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing endpoint']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE admin_id = ? AND endpoint = ?");
    $stmt->bind_param("is", $admin_id, $endpoint);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Notifications disabled']);
    exit;
}

// ── GET STATUS ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $r = $conn->query("SELECT COUNT(*) as cnt FROM push_subscriptions WHERE admin_id = $admin_id");
    $count = $r ? $r->fetch_assoc()['cnt'] : 0;
    echo json_encode(['subscribed' => $count > 0, 'count' => (int)$count]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
