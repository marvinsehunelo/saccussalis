<?php
// backend/api/v1/settlement/cashout_settlements.php

header('Content-Type: application/json');
require_once '../../../db.php';
require_once '../../../middleware/Idempotency.php';
require_once '../../../helpers/crypto.php';

$input = json_decode(file_get_contents('php://input'), true);

// ============================================================
// VERIFY INCOMING SIGNATURE
// ============================================================
$signature = $input['signature'] ?? null;
$timestamp = $input['timestamp'] ?? null;
$requester = $input['requester'] ?? 'VOUCHMORPH';

$payloadToVerify = [
    'type' => $input['type'] ?? null,
    'amount' => $input['amount'] ?? null,
    'wallet_id' => $input['wallet_id'] ?? null,
    'sat_number' => $input['sat_number'] ?? null
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("SACCUSSALIS SETTLEMENT: Missing signature from {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing signature - settlement requests must be signed'
    ]);
    exit;
}

$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("SACCUSSALIS SETTLEMENT: No public key for requester: {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => "No public key found for requester: {$requester}"
    ]);
    exit;
}

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("SACCUSSALIS SETTLEMENT: Invalid signature from {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid signature - settlement request cannot be trusted'
    ]);
    exit;
}

error_log("SACCUSSALIS SETTLEMENT: Signature verified from {$requester}");

// ============================================================
// PROCESS SETTLEMENT
// ============================================================

// Idempotency
$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $input['request_id'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Idempotency key required']);
    exit;
}
Idempotency::check($idempotencyKey);

try {
    $pdo->beginTransaction();

    $type = $input['type'] ?? null;
    if (!$type) throw new Exception("Settlement type required");

    $amount = floatval($input['amount'] ?? 0);
    $reference = 'SET' . time() . rand(100, 999);

    switch (strtoupper($type)) {

        case 'SAT_TOKEN':
            $stmt = $pdo->prepare("
                INSERT INTO settlements 
                (settlement_ref, type, sat_number, amount, issuer_bank, acquirer_bank, status, 
                 requester, signature_verified, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            ");
            $stmt->execute([
                $reference,
                $input['sat_number'] ?? null,
                $input['sat_number'] ?? null,
                $amount,
                $input['issuer_bank'] ?? null,
                $input['acquirer_bank'] ?? null,
                $requester,
                $isValid ? 1 : 0
            ]);
            $message = "SAT token settlement recorded successfully";
            break;

        case 'EWALLET_SWAP':
            $walletId = $input['wallet_id'] ?? null;
            if (!$walletId) throw new Exception("Wallet ID required for eWallet swap settlement");

            $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE wallet_id = ? FOR UPDATE");
            $stmt->execute([$walletId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$wallet) throw new Exception("Wallet not found");
            if ($wallet['balance'] < $amount) throw new Exception("Insufficient wallet balance");

            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE wallet_id = ?");
            $stmt->execute([$amount, $walletId]);

            $stmt = $pdo->prepare("
                INSERT INTO settlements 
                (settlement_ref, wallet_id, amount, status, requester, signature_verified, created_at)
                VALUES (?, ?, ?, 'completed', ?, ?, NOW())
            ");
            $stmt->execute([$reference, $walletId, $amount, $requester, $isValid ? 1 : 0]);

            $message = "eWallet swap settlement completed successfully";
            break;

        default:
            throw new Exception("Unsupported settlement type: $type");
    }

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'settlement_ref' => $reference,
        'type' => $type,
        'amount' => $amount,
        'message' => $message,
        'requester' => $requester,
        'signature_verified' => $isValid
    ];

    Idempotency::store($idempotencyKey, $responsePayload);
    send_signed_response($responsePayload);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("SACCUSSALIS SETTLEMENT error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
