<?php
// redeem_pin.php
if (session_status() === PHP_SESSION_NONE) session_start();
header("Content-Type: application/json");
require_once(__DIR__ . "/../db.php");

// --- Token Authentication ---
$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_GET['token'] ?? null);

if (!$token) {
    echo json_encode(["status" => "error", "message" => "Token required"]);
    exit;
}

// Validate token (replace this with your real DB/JWT token logic)
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? AND expires_at > NOW() LIMIT 1");
$stmt->execute([$token]);
$user_session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_session) {
    echo json_encode(["status" => "error", "message" => "Invalid token"]);
    exit;
}

$redeemer_id = $user_session['user_id'];

// --- Input ---
$data = json_decode(file_get_contents("php://input"), true);
$pin = $data['pin'] ?? null;
$recipient_phone = $data['recipient_phone'] ?? null;

if (!$pin || !$recipient_phone) {
    echo json_encode(["status"=>"error","message"=>"PIN and recipient phone are required"]);
    exit;
}

// --- Normalize phone ---
$recipient_phone = ltrim(str_replace(' ', '', $recipient_phone), '+');

// --- Fetch PIN record ---
$stmt = $pdo->prepare("
    SELECT ep.id AS pin_id, ep.transaction_id, ep.is_redeemed, ep.expires_at, t.amount, w.wallet_id, w.balance
    FROM ewallet_pins ep
    JOIN transactions t ON ep.transaction_id = t.transaction_id
    JOIN wallets w ON w.wallet_id = t.to_account OR w.phone = ?
    WHERE ep.pin = ?
    LIMIT 1
");
$stmt->execute([$recipient_phone, $pin]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    echo json_encode(["status"=>"error","message"=>"Invalid PIN or phone number"]);
    exit;
}

// --- Check if already redeemed ---
if ($record['is_redeemed']) {
    echo json_encode(["status"=>"error","message"=>"PIN already redeemed"]);
    exit;
}

// --- Check if expired ---
if (strtotime($record['expires_at']) < time()) {
    echo json_encode(["status"=>"error","message"=>"PIN expired"]);
    exit;
}

// --- Credit recipient wallet ---
$new_balance = $record['balance'] + $record['amount'];
$stmt = $pdo->prepare("UPDATE wallets SET balance = ? WHERE wallet_id = ?");
$stmt->execute([$new_balance, $record['wallet_id']]);

// --- Mark PIN as redeemed ---
$stmt = $pdo->prepare("UPDATE ewallet_pins SET is_redeemed = 1, redeemed_at = NOW(), redeemed_by = ? WHERE id = ?");
$stmt->execute([$redeemer_id, $record['pin_id']]);

// --- Log transaction ---
$stmt = $pdo->prepare("
    INSERT INTO transactions (user_id, from_account, to_account, amount, type, status, created_at)
    VALUES (?, ?, ?, ?, 'wallet_receive', 'completed', NOW())
");
$stmt->execute([$redeemer_id, 'PIN-' . $pin, $record['wallet_id'], $record['amount']]);

// --- Response ---
echo json_encode([
    "status" => "success",
    "message" => "PIN redeemed successfully",
    "credited_amount" => $record['amount'],
    "new_balance" => $new_balance
]);
