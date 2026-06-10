<?php
// backend/api/v1/hold.php

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("=== SACCUSSALIS HOLD.PHP RECEIVED === " . json_encode($input));

    // ============================================================
    // VERIFY INCOMING SIGNATURE
    // ============================================================
    $signature = $input['signature'] ?? null;
    $timestamp = $input['timestamp'] ?? null;
    $requester = $input['requester'] ?? 'VOUCHMORPH';

    $payloadToVerify = [
        'asset_type' => $input['asset_type'] ?? null,
        'amount' => $input['amount'] ?? null,
        'reference' => $input['reference'] ?? null,
        'destination_institution' => $input['destination_institution'] ?? null
    ];
    $payloadToVerify = array_filter($payloadToVerify);

    if (!$signature) {
        error_log("SACCUSSALIS HOLD: Missing signature from {$requester}");
        echo json_encode([
            'status' => 'ERROR',
            'hold_placed' => false,
            'message' => 'Missing signature - hold requests must be signed'
        ]);
        exit;
    }

    $publicKey = get_requester_public_key($requester, $pdo);

    if (!$publicKey) {
        error_log("SACCUSSALIS HOLD: No public key for requester: {$requester}");
        echo json_encode([
            'status' => 'ERROR',
            'hold_placed' => false,
            'message' => "No public key found for requester: {$requester}"
        ]);
        exit;
    }

    $isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

    if (!$isValid) {
        error_log("SACCUSSALIS HOLD: Invalid signature from {$requester}");
        echo json_encode([
            'status' => 'ERROR',
            'hold_placed' => false,
            'message' => 'Invalid signature - hold request cannot be trusted'
        ]);
        exit;
    }

    error_log("SACCUSSALIS HOLD: Signature verified from {$requester}");

    // ============================================================
    // PROCESS HOLD
    // ============================================================

    // Determine action
    $action = strtoupper(trim($input['action'] ?? $input['type'] ?? 'PLACE'));

    // Get amount & hold reference
    $amount = floatval($input['amount'] ?? $input['value'] ?? 0);
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? uniqid('HOLD-');

    // Map foreign bank
    $foreignBank = $input['foreign_bank'] ?? $input['destination_institution'] ?? $input['destination'] ?? null;

    // Get phone
    $phone = $input['ewallet_phone'] ?? $input['wallet_phone'] ?? $input['phone'] ?? null;
    if (!$phone) {
        throw new Exception("Phone number required (ewallet_phone, wallet_phone, or phone)");
    }

    if ($amount <= 0) {
        throw new Exception("Valid amount required");
    }

    if (in_array($action, ['PLACE', 'HOLD', 'PLACE_HOLD']) && !$foreignBank) {
        error_log("WARNING: foreign_bank not provided in payload");
        $foreignBank = 'UNKNOWN';
    }

    // Ensure session_id exists
    $sessionId = trim($input['session_id'] ?? '');
    if ($sessionId === '') {
        $sessionId = uniqid('SESSION-');
    }

    $pdo->beginTransaction();

    // Fetch wallet
    $stmt = $pdo->prepare("
        SELECT wallet_id, balance, held_balance 
        FROM wallets 
        WHERE phone = ? AND status = 'active' AND is_frozen = false
        FOR UPDATE
    ");
    $stmt->execute([$phone]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$wallet) {
        throw new Exception("Wallet not found or inactive/frozen for phone: $phone");
    }

    if (in_array($action, ['PLACE', 'HOLD', 'PLACE_HOLD'])) {
        $availableBalance = $wallet['balance'] - ($wallet['held_balance'] ?? 0);
        if ($availableBalance < $amount) {
            throw new Exception("Insufficient available balance. Available: {$availableBalance}, Required: {$amount}");
        }

        // Update held_balance
        $stmt = $pdo->prepare("UPDATE wallets SET held_balance = COALESCE(held_balance,0)+? WHERE wallet_id=?");
        $stmt->execute([$amount, $wallet['wallet_id']]);

        // Insert hold with session_id and requester info
        $stmt = $pdo->prepare("
            INSERT INTO financial_holds 
                (wallet_id, amount, hold_reference, foreign_bank, session_id, status, 
                 requester, signature_verified, expires_at, created_at)
            VALUES 
                (?, ?, ?, ?, ?, 'HELD', ?, ?, NOW() + INTERVAL '24 hours', NOW())
            RETURNING id
        ");
        $stmt->execute([
            $wallet['wallet_id'], 
            $amount, 
            $holdReference, 
            $foreignBank, 
            $sessionId,
            $requester,
            $isValid ? 1 : 0
        ]);
        $holdId = $stmt->fetchColumn();

        $message = "Hold placed successfully";
        $holdPlaced = true;

    } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD'])) {
        $stmt = $pdo->prepare("
            SELECT id, wallet_id, amount 
            FROM financial_holds 
            WHERE hold_reference=? AND status='HELD'
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hold) {
            throw new Exception("Active hold not found for reference: $holdReference");
        }

        $stmt = $pdo->prepare("UPDATE financial_holds SET status='RELEASED', released_at=NOW() WHERE id=?");
        $stmt->execute([$hold['id']]);

        $stmt = $pdo->prepare("UPDATE wallets SET held_balance = GREATEST(COALESCE(held_balance,0)-?,0) WHERE wallet_id=?");
        $stmt->execute([$amount, $wallet['wallet_id']]);

        $message = "Hold released successfully";
        $holdPlaced = false;
    } else {
        throw new Exception("Unsupported action: $action");
    }

    $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id=?");
    $stmt->execute([$wallet['wallet_id']]);
    $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'SUCCESS',
        'hold_placed' => ($action !== 'RELEASE'),
        'hold_reference' => $holdReference,
        'session_id' => $sessionId,
        'message' => $message,
        'new_balance' => $updatedWallet['balance'] - $updatedWallet['held_balance'],
        'held_balance' => $updatedWallet['held_balance'],
        'requester' => $requester,
        'signature_verified' => $isValid
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS Hold.php ERROR: " . $e->getMessage());

    echo json_encode([
        'status' => 'ERROR',
        'hold_placed' => false,
        'message' => 'Bank communication failed',
        'reason' => $e->getMessage(),
        'timestamp' => time()
    ]);
    http_response_code(400);
}
