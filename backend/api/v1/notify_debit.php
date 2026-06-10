<?php
// backend/api/v1/notify_debit.php

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../helpers/crypto.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    error_log("=== SACCUSSALIS NOTIFY_DEBIT.PHP RECEIVED ===");
    error_log(json_encode($input));

    // ============================================================
    // VERIFY INCOMING SIGNATURE
    // ============================================================
    $signature = $input['signature'] ?? null;
    $timestamp = $input['timestamp'] ?? null;
    $requester = $input['requester'] ?? 'VOUCHMORPH';

    $payloadToVerify = [
        'hold_reference' => $input['hold_reference'] ?? null,
        'amount' => $input['amount'] ?? null,
        'transaction_reference' => $input['transaction_reference'] ?? null,
        'source_institution' => $input['source_institution'] ?? null
    ];
    $payloadToVerify = array_filter($payloadToVerify);

    if (!$signature) {
        error_log("SACCUSSALIS NOTIFY_DEBIT: Missing signature from {$requester}");
        echo json_encode([
            'status' => 'ERROR',
            'debited' => false,
            'message' => 'Missing signature - debit requests must be signed'
        ]);
        exit;
    }

    $publicKey = get_requester_public_key($requester, $pdo);

    if (!$publicKey) {
        error_log("SACCUSSALIS NOTIFY_DEBIT: No public key for requester: {$requester}");
        echo json_encode([
            'status' => 'ERROR',
            'debited' => false,
            'message' => "No public key found for requester: {$requester}"
        ]);
        exit;
    }

    $isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

    if (!$isValid) {
        error_log("SACCUSSALIS NOTIFY_DEBIT: Invalid signature from {$requester}");
        echo json_encode([
            'status' => 'ERROR',
            'debited' => false,
            'message' => 'Invalid signature - debit request cannot be trusted'
        ]);
        exit;
    }

    error_log("SACCUSSALIS NOTIFY_DEBIT: Signature verified from {$requester}");

    // ============================================================
    // PROCESS DEBIT
    // ============================================================

    // Get parameters
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? null;
    $amount = floatval($input['amount'] ?? 0);
    $transactionReference = $input['transaction_reference'] ?? uniqid('DEBIT-');
    $fromBank = $input['source_institution'] ?? $input['from_bank'] ?? 'SACCUSSALIS';

    if (!$holdReference || $amount <= 0) {
        throw new Exception("Missing required parameters: hold_reference and amount");
    }

    $pdo->beginTransaction();

    // Find the hold
    $stmt = $pdo->prepare("
        SELECT fh.*, w.phone, w.user_id 
        FROM financial_holds fh
        JOIN wallets w ON fh.wallet_id = w.wallet_id
        WHERE fh.hold_reference = ? AND fh.status = 'HELD'
        FOR UPDATE
    ");
    $stmt->execute([$holdReference]);
    $hold = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$hold) {
        throw new Exception("Active hold not found for reference: $holdReference");
    }

    // Update hold status to COMMITTED with requester info
    $stmt = $pdo->prepare("
        UPDATE financial_holds 
        SET status = 'COMMITTED', 
            cashout_confirmed = TRUE,
            debited_by = :requester,
            debit_signature_verified = :sig_verified
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $hold['id'],
        ':requester' => $requester,
        ':sig_verified' => $isValid ? 1 : 0
    ]);

    // Credit the settlement account (operational account)
    $settlementAccount = '10000001';
    $stmt = $pdo->prepare("
        UPDATE accounts 
        SET balance = balance + ? 
        WHERE account_number = ?
        RETURNING balance
    ");
    $stmt->execute([$amount, $settlementAccount]);
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settlement) {
        throw new Exception("Settlement account not found");
    }

    // Create settlement record with requester info
    $stmt = $pdo->prepare("
        INSERT INTO settlements 
            (settlement_ref, type, wallet_id, amount, issuer_bank, status, 
             requester, signature_verified, created_at)
        VALUES 
            (?, 'HOLD_SETTLEMENT', ?, ?, ?, 'completed', ?, ?, NOW())
        RETURNING settlement_id
    ");
    $stmt->execute([
        $transactionReference, 
        $hold['wallet_id'], 
        $amount, 
        $fromBank,
        $requester,
        $isValid ? 1 : 0
    ]);

    // Create ledger entry
    $stmt = $pdo->prepare("
        INSERT INTO ledger_entries 
            (reference, debit_account, credit_account, amount, currency, notes, requester)
        VALUES 
            (?, 'WALLET:' || ?, 'ACCOUNT:' || ?, ?, 'BWP', 'Hold settlement', ?)
    ");
    $stmt->execute([
        $transactionReference, 
        $hold['wallet_id'], 
        $settlementAccount, 
        $amount,
        $requester
    ]);

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'SUCCESS',
        'debited' => true,
        'transaction_reference' => $transactionReference,
        'amount' => $amount,
        'message' => 'Funds debited and settled successfully',
        'requester' => $requester,
        'signature_verified' => $isValid
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS notify_debit.php error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'debited' => false,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
