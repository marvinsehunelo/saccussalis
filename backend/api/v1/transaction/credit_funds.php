<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/transactions/credit_funds.php
header('Content-Type: application/json');
require_once '../../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';

$input = json_decode(file_get_contents("php://input"), true);

error_log("=== SACCUSSALIS credit_funds.php received ===");
error_log(json_encode($input));

// ============================================================
// VERIFY INCOMING SIGNATURE
// ============================================================
$signature = $input['signature'] ?? null;
$timestamp = $input['timestamp'] ?? null;
$requester = $input['requester'] ?? 'VOUCHMORPH';

$payloadToVerify = [
    'amount' => $input['amount'] ?? $input['value'] ?? null,
    'from_bank' => $input['from_bank'] ?? $input['source_institution'] ?? null,
    'phone' => $input['phone'] ?? $input['destination_phone'] ?? $input['beneficiary_phone'] ?? null,
    'account_number' => $input['account_number'] ?? $input['destination_account'] ?? null,
    'reference' => $input['reference'] ?? null
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("SACCUSSALIS CREDIT_FUNDS: Missing signature from {$requester}");
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Missing signature - credit requests must be signed'
    ]);
    exit;
}

$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("SACCUSSALIS CREDIT_FUNDS: No public key for requester: {$requester}");
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => "No public key found for requester: {$requester}"
    ]);
    exit;
}

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("SACCUSSALIS CREDIT_FUNDS: Invalid signature from {$requester}");
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Invalid signature - credit request cannot be trusted'
    ]);
    exit;
}

error_log("SACCUSSALIS CREDIT_FUNDS: Signature verified from {$requester}");

// ============================================================
// PROCESS CREDIT
// ============================================================

// Map fields from different possible sources
$amount = $input['amount'] ?? $input['value'] ?? null;

// Determine source bank
$fromBank = $input['from_bank'] ?? $input['source_institution'] ?? $input['institution'] ?? null;

// Determine destination
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
        'processed' => false,
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
    $settlementAccountNumber = '10000001';
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

    // --- 3. Create a settlement record with requester info ---
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
            requester VARCHAR(100),
            signature_verified BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO settlements 
            (settlement_ref, type, issuer_bank, recipient_type, recipient_id, amount, status, 
             requester, signature_verified, created_at) 
        VALUES 
            (?, 'SWAP_CREDIT', ?, ?, ?, ?, 'pending', ?, ?, NOW())
    ");
    $stmt->execute([
        $settlementRef,
        $issuerBank,
        $recipientType,
        $recipientId,
        $amount,
        $requester,
        $isValid ? 1 : 0
    ]);

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'processed' => true,
        'transaction_reference' => $settlementRef,
        'settlement_ref' => $settlementRef,
        'from_bank' => $fromBank,
        'recipient_type' => $recipientType,
        'recipient_id' => $recipientId,
        'amount' => $amount,
        'message' => 'Funds credited successfully',
        'requester' => $requester,
        'signature_verified' => $isValid
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS credit_funds.php error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
