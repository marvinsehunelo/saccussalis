<?php
// ewallet_create.php — Funds a user wallet from the bank settlement account
declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../db.php';
$db = $pdo;

function jsonResponse(string $status, string $message, array $extra = []): void {
    echo json_encode(array_merge(['status'=>$status,'message'=>$message], $extra));
    exit;
}

try {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $recipient_phone = $input['recipient_phone'] ?? null;
    $amount          = isset($input['amount']) ? (float)$input['amount'] : 0;
    $request_user_id = 2; // System user

    if (!$recipient_phone || $amount <= 0) {
        jsonResponse('error', 'Valid phone and positive amount required');
    }

    $recipient_phone_norm = '+' . ltrim(preg_replace('/\D/', '', $recipient_phone), '+');

    $db->beginTransaction();

    // 1. Lock Bank Settlement
    $stmt = $db->prepare("SELECT account_id, balance FROM accounts WHERE account_type='partner_bank_settlement' FOR UPDATE LIMIT 1");
    $stmt->execute();
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settlement) throw new Exception("Bank settlement account missing");
    if ((float)$settlement['balance'] < $amount) throw new Exception("Insufficient bank settlement balance");

    // 2. Lock Recipient Wallet
    $stmt = $db->prepare("SELECT wallet_id, user_id, balance FROM wallets WHERE phone = ? FOR UPDATE");
    $stmt->execute([$recipient_phone_norm]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) throw new Exception("Recipient wallet not found for $recipient_phone_norm");

    // EXECUTE MONEY MOVE
    $new_settlement_bal = (float)$settlement['balance'] - $amount;
    $db->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?")
       ->execute([$new_settlement_bal, $settlement['account_id']]);

    $new_wallet_bal = (float)$wallet['balance'] + $amount;
    $db->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE wallet_id = ?")
       ->execute([$new_wallet_bal, $wallet['wallet_id']]);

    // C. Log Main Transaction
    $stmt = $db->prepare("
        INSERT INTO transactions (user_id, from_account, to_account, amount, type, status, notes, created_at)
        VALUES (?, ?, ?, ?, 'wallet_deposit', 'completed', ?, NOW())
        RETURNING transaction_id
    ");
    $stmt->execute([
        $request_user_id, 
        'BANK_SETTLEMENT', 
        'WALLET:' . $recipient_phone_norm, 
        $amount, 
        "Funding wallet from settlement"
    ]);
    $tx_id = $stmt->fetchColumn();

    // D. Log Wallet Specific Transaction - FIXING NULL USER_ID
    $transaction_user_id = $wallet['user_id'] ?? $request_user_id;

    $stmt = $db->prepare("
        INSERT INTO wallet_transactions (user_id, wallet_id, transaction_type, amount, status, created_at)
        VALUES (?, ?, 'deposit', ?, 'completed', NOW())
    ");
    $stmt->execute([$transaction_user_id, $wallet['wallet_id'], $amount]);

    $db->commit();

    jsonResponse('success', 'Wallet funded successfully', [
        'transaction_id' => $tx_id,
        'amount' => $amount,
        'new_wallet_balance' => $new_wallet_bal,
        'recipient' => $recipient_phone_norm
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse('error', $e->getMessage());
}
