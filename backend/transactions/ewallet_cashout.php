<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header("Content-Type: application/json; charset=utf-8");

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/../db.php";

/* =========================
   DIRECT SMS SENDER
========================= */
function sendSmsToCazaComDirect(string $recipient, string $message, int $userId): array {
    $url = "http://localhost/CazaCom/backend/routes/api.php?path=sms/send";
    $payload = [
        'recipient_number' => $recipient,
        'message' => $message,
        'user_id' => $userId
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return $error ? ['status' => 'error', 'error' => $error] : (json_decode($response, true) ?: ['status' => 'ok']);
}

/* =========================
   LOGGING
========================= */
$logDir = __DIR__ . '/../../APP_LAYER/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$debugLog = $logDir . '/redeem_pin_debug.log';

try {
    /* =========================
       AUTHENTICATION
    ========================== */
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token  = $headers['Authorization'] ?? ($_GET['token'] ?? null);
    $apiKey = $headers['X-API-Key'] ?? ($_POST['api_key'] ?? null);

    if ($token && str_starts_with($token, 'Bearer ')) $token = trim(substr($token, 7));

    $redeemer_id = null;
    $recipient_phone_default = null;

    if ($apiKey === 'SACCUS_LOCAL_KEY_DEF456') {
        $redeemer_id = 2; 
    } elseif ($token) {
        $stmt = $pdo->prepare("SELECT user_id, phone FROM sessions WHERE token = ? AND (expires_at > NOW()) LIMIT 1");
        $stmt->execute([$token]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sess) throw new Exception("Invalid session");
        $redeemer_id = (int)$sess['user_id'];
        $recipient_phone_default = $sess['phone'];
    } else {
        throw new Exception("Authentication required");
    }

    /* =========================
       INPUT
    ========================== */
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $pin = trim((string)($data['pin'] ?? ''));
    $recipient_phone = $data['recipient_phone'] ?? $recipient_phone_default ?? null;

    if (!$pin || !$recipient_phone) throw new Exception("PIN and phone required");

    $recipient_phone_norm = '+' . ltrim(preg_replace('/\D/', '', (string)$recipient_phone), '+');

    $pdo->beginTransaction();

    /* =========================
       FETCH PIN + WALLET (LOCKING)
    ========================== */
    $stmt = $pdo->prepare("
        SELECT 
            ep.id AS pin_id, ep.amount, ep.is_redeemed, ep.expires_at, ep.sat_purchased,
            w.wallet_id, w.balance, w.user_id AS wallet_owner_id
        FROM ewallet_pins ep
        INNER JOIN wallets w ON w.phone = ep.recipient_phone
        WHERE ep.pin = ? AND ep.recipient_phone = ?
        FOR UPDATE
    ");
    $stmt->execute([$pin, $recipient_phone_norm]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) throw new Exception("Invalid PIN or record not found");
    if ($record['is_redeemed']) throw new Exception("This PIN has already been used");
    if ($record['expires_at'] && strtotime($record['expires_at']) < time()) throw new Exception("PIN has expired");

    $amount = (float)$record['amount'];
    if ((float)$record['balance'] < $amount) throw new Exception("Insufficient wallet balance for cashout");

    /* =========================
       1. DEBIT USER WALLET
    ========================== */
    $new_user_balance = (float)$record['balance'] - $amount;
    $pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE wallet_id = ?")
        ->execute([$new_user_balance, $record['wallet_id']]);

    /* =========================
       2. CREDIT BANK SETTLEMENT
    ========================== */
    $stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE account_type = 'partner_bank_settlement' FOR UPDATE");
    $stmt->execute();
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settlement) throw new Exception("Bank settlement account not found");

    $new_settlement_balance = (float)$settlement['balance'] + $amount;
    $pdo->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?")
        ->execute([$new_settlement_balance, $settlement['account_id']]);

    /* =========================
       3. MARK PIN REDEEMED (Preserve sat_purchased)
    ========================== */
    $stmt = $pdo->prepare("
        UPDATE ewallet_pins
        SET is_redeemed = TRUE,
            redeemed_by = ?,
            redeemed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$redeemer_id, $record['pin_id']]);

    /* =========================
       4. LOG TRANSACTION
    ========================== */
    $stmt = $pdo->prepare("
        INSERT INTO transactions
            (user_id, from_account, to_account, amount, type, status, created_at, notes)
        VALUES
            (?, ?, ?, ?, 'withdrawal', 'completed', NOW(), ?)
        RETURNING transaction_id
    ");
    $stmt->execute([
        $redeemer_id,
        'WALLET:' . $recipient_phone_norm,
        'BANK_SETTLEMENT',
        $amount,
        "Cashout via PIN: $pin"
    ]);
    $tx_ref = (string)$stmt->fetchColumn();

    // Log to specific wallet history
    $pdo->prepare("INSERT INTO wallet_transactions (user_id, wallet_id, transaction_type, amount, status) VALUES (?, ?, 'withdrawal', ?, 'completed')")
        ->execute([$record['wallet_owner_id'], $record['wallet_id'], $amount]);

    $pdo->commit();

    /* =========================
       SEND NOTIFICATION
    ========================== */
    $smsMsg = "Cashout successful. BWP " . number_format($amount, 2) . " debited from wallet. Ref: $tx_ref";
    $sms = sendSmsToCazaComDirect($recipient_phone_norm, $smsMsg, $redeemer_id);

    echo json_encode([
        "status" => "success",
        "message" => "Cashout complete: Wallet debited, Bank credited",
        "details" => [
            "debited_amount" => $amount,
            "wallet_balance" => $new_user_balance,
            "settlement_balance" => $new_settlement_balance,
            "sat_purchased" => $record['sat_purchased'],
            "reference" => $tx_ref,
            "sms" => $sms
        ]
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " | ERROR | " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
