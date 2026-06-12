<?php
// /backend/api/v1/external/confirm-cashout/index.php

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
    error_log("SACCUSSALIS CONFIRM_CASHOUT: No certificate provided");
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

error_log("SACCUSSALIS CONFIRM_CASHOUT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("SACCUSSALIS CONFIRM_CASHOUT: Requester: {$requester}");

if (!$isValid) {
    error_log("SACCUSSALIS CONFIRM_CASHOUT: Certificate verification failed");
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("SACCUSSALIS CONFIRM_CASHOUT: Request verified from {$requester} using certificate");

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

    error_log("SACCUSSALIS CONFIRM_CASHOUT: Found hold ID={$hold['id']}, Amount={$hold['amount']}, Wallet ID={$hold['wallet_id']}");

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
            confirmation_signature_verified = ?,
            updated_at = NOW()
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
            confirmed_by = ?,
            updated_at = NOW()
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
            confirmed_by = ?,
            updated_at = NOW()
        WHERE instrument_id = (
            SELECT instrument_id 
            FROM cash_instruments 
            WHERE hold_reference = ?
        )
        AND status = 'ACTIVE'
    ");
    $stmt->execute([$requester, $input['hold_reference']]);

    // 7️⃣ Create transaction record
    $reference = 'CASHOUT_' . time() . '_' . rand(100, 999);

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
    $claimRef = 'CLM_' . time() . '_' . rand(1000, 9999);

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

    // Get updated wallet balance
    $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ?");
    $stmt->execute([$hold['wallet_id']]);
    $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    error_log("SACCUSSALIS CONFIRM_CASHOUT: Cashout completed successfully - Ref: {$reference}, Claim: {$claimRef}");

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'message' => 'Cashout committed successfully',
        'amount' => $hold['amount'],
        'transaction_reference' => $reference,
        'claim_reference' => $claimRef,
        'hold_reference' => $input['hold_reference'],
        'new_balance' => $updatedWallet['balance'] - ($updatedWallet['held_balance'] ?? 0),
        'available_balance' => $updatedWallet['balance'] - ($updatedWallet['held_balance'] ?? 0),
        'requester' => $requester,
        'signature_verified' => $isValid
    ];

    Idempotency::store($idempotencyKey, $responsePayload);
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("SACCUSSALIS CONFIRM_CASHOUT ERROR: " . $e->getMessage());
    error_log("SACCUSSALIS CONFIRM_CASHOUT Input: " . json_encode($input ?? []));
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Cashout confirmation failed',
        'reason' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
