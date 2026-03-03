<?php
// create_ewallet_transaction.php — create transaction, deduct sender (if needed), generate PIN, send SMS via CazaCom
header("Content-Type: application/json");
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config/integration.php';
require_once __DIR__ . '/../integrations/cazacom_gateway.php';

$db = $pdo;
$config = require __DIR__ . '/../config/integration.php';

// Token/Auth: either header or ?token=
$headers = getallheaders();
$token = trim($headers['Authorization'] ?? ($_GET['token'] ?? ''));
if (!$token) {
    echo json_encode(['status'=>'error','message'=>'Token required']); exit;
}
// Map token -> user id (replace with your sessions table logic)
$stmt = $db->prepare("SELECT user_id FROM sessions WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { echo json_encode(['status'=>'error','message'=>'Invalid token']); exit; }
$sender_id = $user['user_id'];

// Input
$input = json_decode(file_get_contents('php://input'), true);
$recipient_phone = $input['recipient_phone'] ?? null;
$amount = isset($input['amount']) ? (float)$input['amount'] : 0.0;
$sender_id_override = isset($input['sender_id']) ? (int)$input['sender_id'] : $sender_id;

if (!$recipient_phone || $amount <= 0) {
    echo json_encode(['status'=>'error','message'=>'recipient_phone and positive amount required']); exit;
}
$recipient_phone_norm = ltrim(str_replace(' ','',$recipient_phone), '+');

try {
    // Ensure recipient wallet exists
    $stmt = $db->prepare("SELECT wallet_id, user_id, balance FROM wallets WHERE phone = ? OR REPLACE(phone,'+','') = ? LIMIT 1");
    $stmt->execute([$recipient_phone, ltrim($recipient_phone, '+')]);
    $recipient_wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$recipient_wallet) throw new Exception('Recipient wallet not found');

    // Deduct from sender wallet (sender must be a real wallet owner)
    $stmt = $db->prepare("SELECT wallet_id, balance FROM wallets WHERE user_id = ? LIMIT 1");
    $stmt->execute([$sender_id_override]);
    $sender_wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sender_wallet) throw new Exception('Sender wallet not found');
    if ($sender_wallet['balance'] < $amount) throw new Exception('Insufficient balance');

    $db->beginTransaction();

    // Deduct
    $new_sender_balance = $sender_wallet['balance'] - $amount;
    $stmt = $db->prepare("UPDATE wallets SET balance = ? WHERE wallet_id = ?");
    $stmt->execute([$new_sender_balance, $sender_wallet['wallet_id']]);

    // Create transaction
    $stmt = $db->prepare("INSERT INTO transactions (user_id, from_account, to_account, amount, type, status, created_at) VALUES (?, ?, ?, ?, 'wallet_send', 'pending', NOW())");
    $stmt->execute([$sender_id_override, $sender_wallet['wallet_id'], $recipient_phone_norm, $amount]);
    $transaction_id = $db->lastInsertId();

    // Generate PIN
    $pin = random_int(100000,999999);
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $stmt = $db->prepare("INSERT INTO ewallet_pins (transaction_id, pin, is_redeemed, swap_enabled, generated_by, expires_at) VALUES (?, ?, 0, 0, ?, ?)");
    $stmt->execute([$transaction_id, $pin, $sender_id_override, $expires_at]);

    // Optionally mark transaction completed (or wait until redeemed)
    $stmt = $db->prepare("UPDATE transactions SET status = 'completed' WHERE transaction_id = ?");
    $stmt->execute([$transaction_id]);

    // Credit recipient wallet balance? (business choice)
    // If cash-out-only at ATM, you probably do NOT auto-credit wallet. If you want wallets to reflect, credit now:
    $creditNow = true; // set false if you want ATM-only redemption
    if ($creditNow) {
        $new_recipient_balance = $recipient_wallet['balance'] + $amount;
        $stmt = $db->prepare("UPDATE wallets SET balance = ? WHERE wallet_id = ?");
        $stmt->execute([$new_recipient_balance, $recipient_wallet['wallet_id']]);
    }

    $db->commit();

    // Send SMS to recipient via CazaCom
    $message = "You received BWP {$amount}. Use PIN {$pin} at a Saccusalis ATM. Expires: {$expires_at}";
    $smsResult = sendSmsToCazaCom($recipient_phone, $message, $config['SYSTEM_SENDER_NUMBER']);

    echo json_encode([
        'status'=>'success',
        'transaction_id'=>$transaction_id,
        'pin'=>$pin,
        'expires_at'=>$expires_at,
        'sms' => $smsResult
    ]);
    exit;

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("create_ewallet_transaction error: ".$e->getMessage());
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    exit;
}
