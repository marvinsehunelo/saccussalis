<?php
// backend/wallet/ewallet_reissue_pin.php
// API for recipients to reissue expired PINs. Token-authenticated (recipient must be logged in)

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/WalletController.php';

// token auth:
$headers = getallheaders();
$token = $headers['Authorization'] ?? null;
if (!$token) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'Token required']); exit; }

$stmt = $db->prepare("SELECT s.*, u.user_id, u.phone FROM sessions s JOIN users u ON u.user_id = s.user_id WHERE s.token = ? AND (s.expires_at IS NULL OR s.expires_at > NOW()) LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'Invalid or expired token']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$old_pin_id = isset($body['old_pin_id']) ? (int)$body['old_pin_id'] : 0;

if (!$old_pin_id) { echo json_encode(['status'=>'error','message'=>'old_pin_id required']); exit; }

$controller = new WalletController($db);
$result = $controller->reissuePin($old_pin_id, (int)$session['user_id']);

header('Content-Type: application/json');
echo json_encode($result);
