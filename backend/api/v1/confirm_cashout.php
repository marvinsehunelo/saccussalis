<?php
// /backend/api/v1/external/confirm-cashout/index.php

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
    'hold_reference' => $input['hold_reference'] ?? null,
    'session_id' => $input['session_id'] ?? null,
    'foreign_atm_id' => $input['foreign_atm_id'] ?? null
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("SACCUSSALIS CONFIRM_CASHOUT: Missing signature from {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing signature - cashout confirmation must be signed'
    ]);
    exit;
}

$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("SACCUSSALIS CONFIRM_CASHOUT: No public key for requester: {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => "No public key found for requester: {$requester}"
    ]);
    exit;
}

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("SACCUSSALIS CONFIRM_CASHOUT: Invalid signature from {$requester}");
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid signature - cashout confirmation cannot be trusted'
    ]);
    exit;
}

error_log("SACCUSSALIS CONFIRM_CASHOUT: Signature verified from {$requester}");

// ============================================================
// PROCESS CASHOUT CONFIRMATION
// ============================================================

$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $input['request_id'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Idempotency key required']);
    exit;
}

Idempotency::check($idempotencyKey);

if (
    !isset($input['hold_reference']) ||
    !isset($input['session_id']) ||
    !isset($input['foreign_atm_id'])
) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1️⃣ Lock HOLD + WALLET
    $stmt = $pdo->prepare("
        SELECT fh.*, w.user_id, w.phone, w.wallet_id, 
               w.balance, w.held_balance
        FROM financial_holds fh
        JOIN wallets w ON fh.wallet_id = w.wallet_id
        WHERE fh.hold_reference = ? 
        AND fh.status = 'HELD'
        AND fh.expires_at > NOW()
        FOR UPDATE
    ");
    $stmt->execute([$input['hold_reference']]);
    $hold = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$hold) {
        throw new Exception("Hold not found, expired, or already processed");
    }

    // 2️⃣ Validate held balance
    if ($hold['held_balance'] < $hold['amount']) {
        throw new Exception("Held balance inconsistency detected");
    }

    // 3️⃣ FINAL DEBIT (real movement happens here)
    $stmt = $pdo->prepare("
        UPDATE wallets 
        SET balance = balance - ?, 
            held_balance = held_balance - ?
        WHERE wallet_id = ?
    ");
    $stmt->execute([
        $hold['amount'],
        $hold['amount'],
        $hold['wallet_id']
    ]);

    // 4️⃣ Mark hold released with requester info
    $stmt = $pdo->prepare("
        UPDATE financial_holds 
        SET status = 'RELEASED', 
            released_at = NOW(),
            foreign_atm_id = ?,
            cashout_confirmed = true,
            confirmed_by = ?,
            confirmation_signature_verified = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $input['foreign_atm_id'],
        $requester,
        $isValid ? 1 : 0,
        $hold['id']
    ]);

    // 5️⃣ Mark cash instrument as cashed
    $stmt = $pdo->prepare("
        UPDATE cash_instruments 
        SET status = 'CASHED_OUT', 
            cashed_out_at = NOW(),
            foreign_atm_id = ?,
            confirmed_by = ?
        WHERE hold_reference = ? 
        AND status = 'HELD'
    ");
    $stmt->execute([
        $input['foreign_atm_id'],
        $requester,
        $input['hold_reference']
    ]);

    // 6️⃣ Mark SAT as USED and clear processing
    $stmt = $pdo->prepare("
        UPDATE sat_tokens
        SET status = 'USED',
            processing = FALSE,
            expires_at = NOW(),
            confirmed_by = ?
        WHERE instrument_id = (
            SELECT instrument_id 
            FROM cash_instruments 
            WHERE hold_reference = ?
        )
        AND status = 'ACTIVE'
    ");
    $stmt->execute([$requester, $input['hold_reference']]);

    // 7️⃣ Create transaction record
    $reference = 'CASHOUT' . time() . rand(100, 999);

    $stmt = $pdo->prepare("
        INSERT INTO transactions (
            user_id, reference, from_account, amount, type, 
            status, channel, notes, requester, signature_verified, created_at
        ) VALUES (?, ?, ?, ?, 'ewallet_cashout', 'completed', 'foreign_atm', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $hold['user_id'],
        $reference,
        $hold['phone'],
        $hold['amount'],
        json_encode([
            'hold_reference' => $input['hold_reference'],
            'foreign_atm_id' => $input['foreign_atm_id'],
            'session_id' => $input['session_id']
        ]),
        $requester,
        $isValid ? 1 : 0
    ]);

    // 8️⃣ Create interbank claim
    $claimRef = 'CLM' . time() . rand(1000, 9999);

    $stmt = $pdo->prepare("
        INSERT INTO interbank_claims (
            foreign_institution, amount, reference, hold_reference, 
            status, requester, signature_verified, created_at
        ) VALUES (?, ?, ?, ?, 'PENDING', ?, ?, NOW())
    ");
    $stmt->execute([
        $hold['foreign_bank'],
        $hold['amount'],
        $claimRef,
        $input['hold_reference'],
        $requester,
        $isValid ? 1 : 0
    ]);

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'message' => 'Cashout committed successfully',
        'amount' => $hold['amount'],
        'transaction_reference' => $reference,
        'claim_reference' => $claimRef,
        'requester' => $requester,
        'signature_verified' => $isValid
    ];

    Idempotency::store($idempotencyKey, $responsePayload);
    send_signed_response($responsePayload);

} catch (Exception $e) {
    $pdo->rollBack();

    error_log("SACCUSSALIS CONFIRM_CASHOUT error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
