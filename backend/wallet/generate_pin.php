<?php
// generate_pin.php
header("Content-Type: application/json");
require_once(__DIR__ . "/../db.php");

// --- Token authentication ---
$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_GET['token'] ?? null);
$token = trim($token);

$valid_token = "ab95fa1a6a4c93aa84add0a1397dfdd1b567f157d25a155d538e073b892f0432";
if (!$token) {
    echo json_encode(["status"=>"error","message"=>"Token required"]);
    exit;
}
if ($token !== $valid_token) {
    echo json_encode(["status"=>"error","message"=>"Invalid token"]);
    exit;
}

// --- Input ---
$data = json_decode(file_get_contents("php://input"), true);
$recipient_phone = $data['recipient_phone'] ?? null;
$amount = $data['amount'] ?? null;
$sender_id = $data['sender_id'] ?? null;

if (!$recipient_phone || !$amount || !$sender_id) {
    echo json_encode(["status"=>"error","message"=>"Recipient phone, amount, and sender ID required"]);
    exit;
}

// --- Fetch sender wallet ---
$stmt = $pdo->prepare("SELECT wallet_id, balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$sender_id]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wallet) {
    echo json_encode(["status"=>"error","message"=>"Sender wallet not found"]);
    exit;
}
if ($wallet['balance'] < $amount) {
    echo json_encode(["status"=>"error","message"=>"Insufficient balance"]);
    exit;
}


// --- Deduct from sender wallet ---
$new_balance = $wallet['balance'] - $amount;
$stmt = $pdo->prepare("UPDATE wallets SET balance = ? WHERE wallet_id = ?");
$stmt->execute([$new_balance, $wallet['wallet_id']]);

// --- Create transaction ---
$stmt = $pdo->prepare("
    INSERT INTO transactions (user_id, from_account, to_account, amount, type, status, created_at)
    VALUES (?, ?, ?, ?, 'wallet_send', 'completed', NOW())
");
$stmt->execute([$sender_id, $wallet['wallet_id'], $recipient_phone, $amount]);
$transaction_id = $pdo->lastInsertId();

// --- Generate 6-digit PIN ---
$pin = random_int(100000, 999999);
$expires_at = date("Y-m-d H:i:s", strtotime("+15 minutes"));

$stmt = $pdo->prepare("
    INSERT INTO ewallet_pins 
    (transaction_id, pin, is_redeemed, generated_by, created_at, expires_at, regeneration_fee) 
    VALUES (?, ?, 0, ?, NOW(), ?, 0.00)
");
$stmt->execute([$transaction_id, $pin, $sender_id, $expires_at]);

// --- Return PIN and expiration ---
echo json_encode([
    "status" => "success",
    "pin" => $pin,
    "expires_at" => $expires_at,
    "sender_balance" => $new_balance
]);
