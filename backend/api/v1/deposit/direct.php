<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/deposit/direct/index.php

header('Content-Type: application/json');
require_once '../../../db.php';
require_once '../../../middleware/Idempotency.php';
require_once '../../../helpers/crypto.php';

$input = json_decode(file_get_contents("php://input"), true);

// ============================================================
// VERIFY INCOMING SIGNATURE
// ============================================================
$signature = $input['signature'] ?? null;
$timestamp = $input['timestamp'] ?? null;
$requester = $input['requester'] ?? 'VOUCHMORPH';

$payloadToVerify = [
    'depositRef' => $input['depositRef'] ?? null,
    'amount' => $input['amount'] ?? null,
    'account_number' => $input['account_number'] ?? null
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("SACCUSSALIS DEPOSIT: Missing signature from {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing signature - deposit requests must be signed'
    ]);
    exit;
}

$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("SACCUSSALIS DEPOSIT: No public key for requester: {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => "No public key found for requester: {$requester}"
    ]);
    exit;
}

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("SACCUSSALIS DEPOSIT: Invalid signature from {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid signature - deposit request cannot be trusted'
    ]);
    exit;
}

error_log("SACCUSSALIS DEPOSIT: Signature verified from {$requester}");

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

if (!isset($input['depositRef']) || !isset($input['amount']) || !isset($input['account_number'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Find account
    $stmt = $pdo->prepare("SELECT account_id, user_id FROM accounts WHERE account_number = ?");
    $stmt->execute([$input['account_number']]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("Account not found");
    }

    // Credit account
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_number = ?");
    $stmt->execute([$input['amount'], $input['account_number']]);

    // Create transaction record with requester info
    $reference = 'DEP' . time() . rand(100, 999);
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
            (user_id, reference, to_account, amount, type, status, 
             requester, signature_verified, created_at)
        VALUES 
            (?, ?, ?, ?, 'deposit', 'completed', ?, ?, NOW())
    ");
    $stmt->execute([
        $account['user_id'], 
        $reference, 
        $input['account_number'], 
        $input['amount'],
        $requester,
        $isValid ? 1 : 0
    ]);

    // Create ledger entry with requester info
    $stmt = $pdo->prepare("
        INSERT INTO ledger_entries 
            (reference, debit_account, credit_account, amount, notes, requester, signature_verified)
        VALUES 
            (?, 'SETTLEMENT_SUSPENSE', ?, ?, 'Direct deposit', ?, ?)
    ");
    $stmt->execute([
        $reference, 
        $input['account_number'], 
        $input['amount'],
        $requester,
        $isValid ? 1 : 0
    ]);

    $pdo->commit();

    // Get new balance
    $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_number = ?");
    $stmt->execute([$input['account_number']]);
    $newBalance = $stmt->fetchColumn();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'transaction_ref' => $reference,
        'credited' => true,
        'amount' => $input['amount'],
        'new_balance' => (float)$newBalance,
        'requester' => $requester,
        'signature_verified' => $isValid
    ];

    Idempotency::store($idempotencyKey, $responsePayload);
    send_signed_response($responsePayload);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("SACCUSSALIS DEPOSIT error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Deposit failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
