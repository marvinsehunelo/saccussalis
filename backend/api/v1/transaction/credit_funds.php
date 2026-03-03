<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/transactions/credit_funds.php
header('Content-Type: application/json');
require_once '../../../db.php';

$input = json_decode(file_get_contents("php://input"), true);

error_log("=== credit_funds.php received ===");
error_log(json_encode($input));

// Map fields from different possible sources
$amount = $input['amount'] ?? $input['value'] ?? null;

// Determine source bank - check multiple possible field names
$fromBank = $input['from_bank'] ?? $input['source_institution'] ?? $input['institution'] ?? null;

// Determine destination - check multiple possible field names
$phone = $input['phone'] ?? $input['destination_phone'] ?? $input['beneficiary_phone'] ?? null;
$accountNumber = $input['account_number'] ?? $input['destination_account'] ?? $input['account'] ?? null;

// Also check in nested structures
if (!$phone && isset($input['destination']['account'])) {
    $phone = $input['destination']['account'];
}
if (!$accountNumber && isset($input['destination']['account'])) {
    $accountNumber = $input['destination']['account'];
}

// Validate required fields
if (!$amount || !$fromBank || (!$phone && !$accountNumber)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required fields. Need amount, from_bank/source_institution, and either phone or account_number.',
        'debug_received' => $input
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // --- 1. Determine recipient type ---
    $recipientType = null;
    $recipientId = null;

    if ($phone) {
        // Credit wallet
        $stmt = $pdo->prepare("SELECT wallet_id, balance FROM wallets WHERE phone = ? FOR UPDATE");
        $stmt->execute([$phone]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            throw new Exception("Wallet not found for phone $phone");
        }

        $recipientType = 'WALLET';
        $recipientId = $wallet['wallet_id'];

        // Credit wallet balance
        $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE wallet_id = ?");
        $stmt->execute([$amount, $recipientId]);

    } elseif ($accountNumber) {
        // Credit bank account
        $stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE account_number = ? FOR UPDATE");
        $stmt->execute([$accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception("Bank account not found for account number $accountNumber");
        }

        $recipientType = 'ACCOUNT';
        $recipientId = $account['account_id'];

        // Credit account balance
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
        $stmt->execute([$amount, $recipientId]);
    }

    // --- 2. Deduct from settlement account (internal liquidity) ---
    $settlementAccountNumber = '10000001'; // Operational account
    $stmt = $pdo->prepare("
        UPDATE accounts 
        SET balance = balance - :amount 
        WHERE account_number = :acc_num AND balance >= :amount
        RETURNING account_id, balance
    ");
    $stmt->execute([
        ':amount' => $amount,
        ':acc_num' => $settlementAccountNumber
    ]);
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settlement) {
        throw new Exception("Settlement account not found or insufficient funds.");
    }

    // --- 3. Create a settlement record to bill the external bank later ---
    $settlementRef = $input['reference'] ?? $input['settlement_ref'] ?? ('SET' . round(microtime(true) * 1000));
    $issuerBank = $fromBank;

    // Create settlements table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settlements (
            id BIGSERIAL PRIMARY KEY,
            settlement_ref VARCHAR(100) UNIQUE NOT NULL,
            type VARCHAR(50) NOT NULL,
            issuer_bank VARCHAR(100) NOT NULL,
            recipient_type VARCHAR(50) NOT NULL,
            recipient_id BIGINT NOT NULL,
            amount DECIMAL(20,4) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO settlements 
            (settlement_ref, type, issuer_bank, recipient_type, recipient_id, amount, status, created_at) 
        VALUES 
            (?, 'SWAP_CREDIT', ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $settlementRef,
        $issuerBank,
        $recipientType,
        $recipientId,
        $amount
    ]);

    $pdo->commit();

    // Return in format that SwapService expects
    echo json_encode([
        'status' => 'success',
        'processed' => true,
        'transaction_reference' => $settlementRef,
        'settlement_ref' => $settlementRef,
        'from_bank' => $fromBank,
        'recipient_type' => $recipientType,
        'recipient_id' => $recipientId,
        'amount' => $amount,
        'message' => 'Funds credited successfully'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("credit_funds.php error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => $e->getMessage()
    ]);
}
