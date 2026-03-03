<?php
// backend/wallet/ewallet_send.php
// API entry for sending money (generates PIN). Token-authenticated.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/WalletController.php';
require_once __DIR__ . '/../integrations/cazacom_gateway.php';

// Simple token auth: expecting Authorization header = token
$headers = getallheaders();
$token = $headers['Authorization'] ?? null;
if (!$token) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Authorization token required']);
    exit;
}

// validate token in sessions table (assumes sessions.token column exists)
$stmt = $db->prepare("SELECT s.*, u.user_id, u.phone, u.full_name FROM sessions s JOIN users u ON u.user_id = s.user_id WHERE s.token = ? AND (s.expires_at IS NULL OR s.expires_at > NOW()) LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Invalid or expired token']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$recipient_phone = $body['recipient_phone'] ?? null;
$amount = isset($body['amount']) ? (float)$body['amount'] : null;

if (!$recipient_phone || !$amount || $amount <= 0) {
    echo json_encode(['status'=>'error','message'=>'recipient_phone and amount required']);
    exit;
}

$controller = new WalletController($db);
$result = $controller->generateAtmPin((int)$session['user_id'], $recipient_phone, (float)$amount);

if ($result['status'] === 'success') {
    // notify recipient via CazaCom SMS
    $senderName = $session['full_name'] ?? 'Saccusalis';
    $pin = $result['pin'];
    $expires = $result['expires_at'];
    $msgToRecipient = "You have an e-cash of {$result['amount']} from {$senderName}. Use PIN {$pin} at our ATM. Expires: {$expires}.";
    sendSmsViaCazacom($recipient_phone, $msgToRecipient);

    // Optionally notify sender (confirmation)
    $msgToSender = "You sent {$result['amount']} to {$recipient_phone}. PIN: {$pin} (recipient). Expires: {$expires}.";
    sendSmsViaCazacom($session['phone'], $msgToSender);
}

header('Content-Type: application/json');
echo json_encode($result);
