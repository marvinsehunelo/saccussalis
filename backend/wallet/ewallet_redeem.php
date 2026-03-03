<?php
// backend/wallet/ewallet_redeem.php
// API endpoint used by ATM or USSD "withdraw" flow. No token required if ATM calls with own api key.
// For simplicity this expects a secret header 'X-API-KEY' (ATM provider or CazaCom should provide).

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/WalletController.php';

$headers = getallheaders();
$apiKey = $headers['X-API-KEY'] ?? null;
$allowedKey = defined('ATM_API_KEY') ? ATM_API_KEY : null; // fallback if defined elsewhere

// If you want token-based auth: check Authorization header instead.
// Here we allow a pre-shared key for ATM integration:
if (!$apiKey) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'X-API-KEY header required']);
    exit;
}
// Validate against sessions or config as required:
if ($allowedKey && $apiKey !== $allowedKey) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Invalid API key']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$pin = $body['pin'] ?? null;
$recipient_phone = $body['recipient_phone'] ?? null;

if (!$pin || !$recipient_phone) {
    echo json_encode(['status'=>'error','message'=>'pin and recipient_phone required']);
    exit;
}

$controller = new WalletController($db);
$result = $controller->redeemAtmPin($recipient_phone, $pin);

header('Content-Type: application/json');
echo json_encode($result);
