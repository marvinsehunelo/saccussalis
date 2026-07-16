<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/deposit/direct/index.php

header('Content-Type: application/json');
require_once '../../../db.php';
require_once '../../../middleware/Idempotency.php';
require_once '../../../helpers/crypto.php';
require_once '../../../helpers/CertificateManager.php';

$input = json_decode(file_get_contents("php://input"), true);

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($input['certificate'])) {
    error_log("SACCUSSALIS DEPOSIT: No certificate provided");
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('SACCUSSALIS');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("SACCUSSALIS DEPOSIT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("SACCUSSALIS DEPOSIT: Requester: {$requester}");

if (!$isValid) {
    error_log("SACCUSSALIS DEPOSIT: Certificate verification failed");
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("SACCUSSALIS DEPOSIT: Request verified from {$requester} using certificate");

// ============================================================
// PROCESS DEPOSIT
// ============================================================

$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $input['request_id'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Idempotency key required']);
    exit;
}

Idempotency::check($idempotencyKey);

$depositRef = $input['reference'] ?? $input['depositRef'] ?? null;
if (!$depositRef || !isset($input['amount']) || !isset($input['account_number'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: reference, amount, account_number']);
    exit;
}

// Initialize variables
$reference = null;
$transactionId = null;
$newBalance = null;

try {
    $pdo->beginTransaction();

    // Find account with lock
    $stmt = $pdo->prepare("SELECT account_id, user_id, balance, status FROM accounts WHERE account_number = ? FOR UPDATE");
    $stmt->execute([$input['account_number']]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("Account not found for number: " . $input['account_number']);
    }

    if ($account['status'] !== 'active') {
        throw new Exception("Account is not active. Status: " . $account['status']);
    }

    // Credit account
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE account_number = ?");
    $stmt->execute([$input['amount'], $input['account_number']]);

    // Get new balance
    $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_number = ?");
    $stmt->execute([$input['account_number']]);
    $newBalance = $stmt->fetchColumn();

    // Create transaction record with requester info
    $reference = 'DEP_' . time() . '_' . rand(100, 999);
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
            (user_id, reference, to_account, amount, type, status, 
             requester, signature_verified, channel, notes, created_at, updated_at)
        VALUES 
            (?, ?, ?, ?, 'deposit', 'completed', ?, ?, 'direct_deposit', ?, NOW(), NOW())
        RETURNING transaction_id
    ");
    $stmt->execute([
        $account['user_id'], 
        $reference, 
        $input['account_number'], 
        $input['amount'],
        $requester,
        $isValid ? 1 : 0,
        json_encode([
            'deposit_ref' => $input['depositRef'],
            'source' => $requester,
            'timestamp' => time()
        ])
    ]);
    $transactionId = $stmt->fetchColumn();

    // Create ledger entry with requester info
    $stmt = $pdo->prepare("
        INSERT INTO ledger_entries 
            (reference, debit_account, credit_account, amount, currency, notes, 
             requester, signature_verified, created_at)
        VALUES 
            (?, 'SETTLEMENT_SUSPENSE', ?, ?, 'BWP', 'Direct deposit from ' || ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $reference, 
        $input['account_number'], 
        $input['amount'],
        $requester,
        $requester,
        $isValid ? 1 : 0
    ]);

    // Create settlement record for tracking
    $stmt = $pdo->prepare("
        INSERT INTO settlements 
            (settlement_ref, type, amount, recipient_type, recipient_id, status, 
             requester, signature_verified, created_at, updated_at)
        VALUES 
            (?, 'DIRECT_DEPOSIT', ?, 'ACCOUNT', ?, 'completed', ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $reference,
        $input['amount'],
        $account['account_id'],
        $requester,
        $isValid ? 1 : 0
    ]);

    $pdo->commit();

    error_log("SACCUSSALIS DEPOSIT: Deposit completed - Ref: {$reference}, Amount: {$input['amount']}, Account: {$input['account_number']}");

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'transaction_ref' => $reference,
        'transaction_id' => $transactionId,
        'credited' => true,
        'amount' => (float)$input['amount'],
        'new_balance' => (float)$newBalance,
        'transaction_reference' => $reference,  // <-- ADD THIS
        'account_number' => $input['account_number'],
        'deposit_ref' => $input['depositRef'],
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'timestamp' => time()
    ];

    Idempotency::store($idempotencyKey, $responsePayload);
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS DEPOSIT ERROR: " . $e->getMessage());
    error_log("SACCUSSALIS DEPOSIT Input: " . json_encode($input ?? []));
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Deposit failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
