<?php
// backend/wallet/ewallet_check_balance.php
// Token-authenticated balance check (for USSD & SPA). Minimal output for USSD.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/WalletController.php';

$headers = getallheaders();
$token = $headers['Authorization'] ?? null;
if (!$token) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'Token required']); exit; }

$stmt = $db->prepare("SELECT s.*, u.user_id, u.phone FROM sessions s JOIN users u ON u.user_id = s.user_id WHERE s.token = ? AND (s.expires_at IS NULL OR s.expires_at > NOW()) LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'Invalid or expired token']); exit; }

$controller = new WalletController($db);
$res = $controller->checkBalance((int)$session['user_id']);

// For USSD, you might want a tiny message. We'll return JSON; CazaCom transforms to USSD text.
header('Content-Type: application/json');
echo json_encode($res);
