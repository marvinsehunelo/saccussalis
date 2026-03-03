<?php
// ewallet_reverse.php — Reverses wallet funding: Debits Wallet -> Credits Settlement
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
header("Content-Type: application/json; charset=utf-8");

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/../db.php";

$logDir = __DIR__ . '/../../APP_LAYER/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$debugLog = $logDir . '/ewallet_reverse_debug.log';

function jsonResponse(string $status,string $message,array $extra=[]): void{
    echo json_encode(array_merge(['status'=>$status,'message'=>$message],$extra));
    exit;
}

function dbg(string $line): void{
    global $debugLog;
    file_put_contents($debugLog, date('Y-m-d H:i:s')." | ".$line.PHP_EOL, FILE_APPEND);
}

// ... sendSmsToCazaComDirect remains the same ...

try {
    // ----------------------
    // 1. AUTH & DYNAMIC SYSTEM USER
    // ----------------------
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = 'system@saccussalis.com' LIMIT 1");
    $stmt->execute();
    $sysUser = $stmt->fetch();
    $system_user_id = $sysUser ? (int)$sysUser['user_id'] : 2;

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $apiKey = $headers['X-API-Key'] ?? ($_POST['api_key'] ?? null);
    
    // Simple check for this example; use your existing token logic if needed
    if ($apiKey !== 'SACCUS_LOCAL_KEY_DEF456') throw new Exception("Auth failed");
    $reverser_id = $system_user_id;

    // ----------------------
    // 2. INPUT
    // ----------------------
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $withdrawal_tx_id = $input['withdrawal_transaction_id'] ?? null;
    $pin = $input['pin'] ?? null;

    if (!$withdrawal_tx_id && !$pin) throw new Exception("Transaction ID or PIN required");

    $pdo->beginTransaction();

    // ----------------------
    // 3. LOCATE ORIGINAL TRANSACTION & WALLET
    // ----------------------
    $stmt = $pdo->prepare("
        SELECT t.transaction_id, t.amount, ep.id AS pin_id, ep.pin, ep.recipient_phone, w.wallet_id, w.balance as wallet_balance
        FROM transactions t
        JOIN ewallet_pins ep ON ep.transaction_id = t.transaction_id
        JOIN wallets w ON w.phone = ep.recipient_phone
        WHERE (t.transaction_id = ? OR ep.pin = ?)
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$withdrawal_tx_id, $pin]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) throw new Exception("Original wallet transaction not found");

    $amount = (float)$record['amount'];
    $wallet_id = (int)$record['wallet_id'];
    $recipient_phone = $record['recipient_phone'];

    // CHECK: Does the wallet have enough to be reversed?
    if ((float)$record['wallet_balance'] < $amount) {
        throw new Exception("Reversal failed: Recipient has already spent the funds.");
    }

    // ----------------------
    // 4. ACCOUNT MOVEMENTS
    // ----------------------
    
    // A. Debit the Wallet
    $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE wallet_id = ?");
    $stmt->execute([$amount, $wallet_id]);

    // B. Credit the Settlement Account
    $stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE account_type='partner_bank_settlement' FOR UPDATE LIMIT 1");
    $stmt->execute();
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settlement) throw new Exception("Settlement account missing");

    $new_settlement_balance = (float)$settlement['balance'] + $amount;
    $pdo->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?")
        ->execute([$new_settlement_balance, $settlement['account_id']]);

    // ----------------------
    // 5. UPDATE PIN & LOGS
    // ----------------------
    
    // Set PIN to redeemed/inactive so it can't be used again after reversal
    $pdo->prepare("UPDATE ewallet_pins SET is_redeemed = TRUE, redeemed_at = NOW(), redeemed_by = 'REVERSED' WHERE id = ?")
        ->execute([$record['pin_id']]);

    // Log the high-level reversal
    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, from_account, to_account, amount, type, status, reference, notes)
        VALUES (?, ?, ?, ?, 'wallet_reverse', 'completed', ?, ?)
        RETURNING transaction_id
    ");
    $stmt->execute([
        $reverser_id, 
        'WALLET:' . $recipient_phone, 
        'BANK_SETTLEMENT', 
        $amount, 
        'REV-' . $record['pin'],
        "Reversed wallet funding for PIN: " . $record['pin']
    ]);
    $reverse_tx_id = $stmt->fetchColumn();

    // Log the wallet-specific reversal
    $pdo->prepare("INSERT INTO wallet_transactions (user_id, wallet_id, transaction_type, amount, status) VALUES (?, ?, 'reversal', ?, 'completed')")
        ->execute([$reverser_id, $wallet_id, $amount]);

    $pdo->commit();

    // ----------------------
    // 6. NOTIFY
    // ----------------------
    $smsMsg = "ZuruBank: Reversal processed. BWP {$amount} has been debited from your wallet.";
    sendSmsToCazaComDirect($recipient_phone, $smsMsg, $reverser_id);

    jsonResponse('success', 'Wallet reversal successful', [
        'reverse_transaction_id' => $reverse_tx_id,
        'amount_reversed' => $amount,
        'new_settlement_balance' => $new_settlement_balance
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    dbg("ERROR: " . $e->getMessage());
    jsonResponse('error', $e->getMessage());
}
