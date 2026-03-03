<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
require_once(__DIR__ . "/../db.php");
$db = $pdo;

// --- Token authentication ---
$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_GET['token'] ?? null);
if (!$token) {
    echo json_encode(["status"=>"error","message"=>"Token required"]);
    exit;
}

// Map token to user
$stmt = $db->prepare("SELECT user_id FROM sessions WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo json_encode(["status"=>"error","message"=>"Invalid token"]);
    exit;
}
$recipient_id = $user['user_id'];

// Input
$input = json_decode(file_get_contents("php://input"), true);
$recipient_phone = $input['recipient_phone'] ?? null;
$transaction_id = $input['transaction_id'] ?? null;
$reissue_fee = $input['reissue_fee'] ?? 5; // default fee if not specified

if (!$recipient_phone || !$transaction_id) {
    echo json_encode(["status"=>"error","message"=>"recipient_phone and transaction_id are required"]);
    exit;
}

// Normalize phone
$recipient_phone_norm = ltrim(str_replace(' ', '', $recipient_phone), '+');

// Check recipient wallet balance for fee
$stmt = $db->prepare("SELECT wallet_id, balance FROM wallets WHERE user_id = ? AND phone = ?");
$stmt->execute([$recipient_id, $recipient_phone_norm]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$wallet) {
    echo json_encode(["status"=>"error","message"=>"Recipient wallet not found"]);
    exit;
}

if ($wallet['balance'] < $reissue_fee) {
    echo json_encode(["status"=>"error","message"=>"Insufficient balance to regenerate PIN"]);
    exit;
}

// Deduct reissue fee
$new_balance = $wallet['balance'] - $reissue_fee;
$stmt = $db->prepare("UPDATE wallets SET balance = ? WHERE wallet_id = ?");
$stmt->execute([$new_balance, $wallet['wallet_id']]);

// Generate new PIN and expiry
$new_pin = random_int(100000, 999999);
$new_expires_at = date("Y-m-d H:i:s", strtotime("+15 minutes"));

// Insert new PIN record
$stmt = $db->prepare("
    INSERT INTO ewallet_pins (transaction_id, pin, is_redeemed, regenerated_by, generated_by, reissue_fee, expires_at)
    VALUES (?, ?, 0, ?, ?, ?, ?)
");
$stmt->execute([$transaction_id, $new_pin, $recipient_id, $reissue_fee, $sender_id, $new_expires_at]);

echo json_encode([
    "status" => "success",
    "pin" => $new_pin,
    "expires_at" => $new_expires_at,
    "reissue_fee" => $reissue_fee,
    "recipient_wallet_balance" => $new_balance
]);
