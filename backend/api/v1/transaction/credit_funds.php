<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/transactions/credit_funds.php
header('Content-Type: application/json');
require_once '../../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

$input = json_decode(file_get_contents("php://input"), true);

error_log("=== SACCUSSALIS credit_funds.php received ===");
error_log(json_encode($input));

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($input['certificate'])) {
    error_log("SACCUSSALIS CREDIT_FUNDS: No certificate provided from {$requester}");
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('SACCUSSALIS');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("SACCUSSALIS CREDIT_FUNDS: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("SACCUSSALIS CREDIT_FUNDS: Requester: {$requester}");

if (!$isValid) {
    error_log("SACCUSSALIS CREDIT_FUNDS: Certificate verification failed");
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("SACCUSSALIS CREDIT_FUNDS: Request verified from {$requester} using certificate");

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

// Initialize variables
$recipientType = null;
$recipientId = null;
$updatedBalance = null;

try {
    $pdo->beginTransaction();

    // --- 1. Determine recipient type ---
    if ($phone) {
        // Credit wallet
        $stmt = $pdo->prepare("SELECT wallet_id, balance, held_balance FROM wallets WHERE phone = ? AND status = 'active' AND is_frozen = false FOR UPDATE");
        $stmt->execute([$phone]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            throw new Exception("Wallet not found for phone $phone");
        }

        $recipientType = 'WALLET';
        $recipientId = $wallet['wallet_id'];

        // Credit wallet balance
        $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ?, updated_at = NOW() WHERE wallet_id = ?");
        $stmt->execute([$amount, $recipientId]);

        // Get updated balance
        $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ?");
        $stmt->execute([$recipientId]);
        $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedBalance = $updatedWallet['balance'];

        error_log("SACCUSSALIS CREDIT_FUNDS: Credited {$amount} to wallet {$recipientId}, new balance: {$updatedBalance}");

    } elseif ($accountNumber) {
        // Credit bank account
        $stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE account_number = ? AND status = 'active' FOR UPDATE");
        $stmt->execute([$accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception("Bank account not found for account number $accountNumber");
        }

        $recipientType = 'ACCOUNT';
        $recipientId = $account['account_id'];

        // Credit account balance
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE account_id = ?");
        $stmt->execute([$amount, $recipientId]);

        // Get updated balance
        $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_id = ?");
        $stmt->execute([$recipientId]);
        $updatedAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedBalance = $updatedAccount['balance'];

        error_log("SACCUSSALIS CREDIT_FUNDS: Credited {$amount} to account {$recipientId}, new balance: {$updatedBalance}");
    }

    // --- 2. Deduct from settlement account (internal liquidity) ---
    $settlementAccountNumber = '10000001';
    $stmt = $pdo->prepare("
        UPDATE accounts 
        SET balance = balance - :amount, updated_at = NOW()
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

    error_log("SACCUSSALIS CREDIT_FUNDS: Settlement account {$settlementAccountNumber} new balance: {$settlement['balance']}");

    // --- 3. Create a settlement record with requester info ---
    $settlementRef = $input['reference'] ?? $input['settlement_ref'] ?? ('SET' . round(microtime(true) * 1000));

    // Ensure settlements table exists with proper columns
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
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO settlements 
            (settlement_ref, type, issuer_bank, recipient_type, recipient_id, amount, status, 
             requester, signature_verified, created_at, updated_at) 
        VALUES 
            (?, 'SWAP_CREDIT', ?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $settlementRef,
        $fromBank,
        $recipientType,
        $recipientId,
        $amount,
        $requester,
        $isValid ? 1 : 0
    ]);

    $pdo->commit();

    error_log("SACCUSSALIS CREDIT_FUNDS: Credit completed successfully - Ref: {$settlementRef}");

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
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
        'new_balance' => $updatedBalance,
        'message' => 'Funds credited successfully',
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate'
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS credit_funds.php ERROR: " . $e->getMessage());
    error_log("SACCUSSALIS credit_funds.php Input: " . json_encode($input ?? []));
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Credit failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
