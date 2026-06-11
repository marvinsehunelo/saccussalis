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

    // Remove signature only - keep everything else
    $payloadToVerify = [];
    foreach ($input as $key => $value) {
        if ($key !== 'signature') {
            $payloadToVerify[$key] = $value;
        }
    }
    
    // Remove timestamp fields from payload (verify_signature will add _timestamp back)
    if (isset($payloadToVerify['timestamp'])) {
        unset($payloadToVerify['timestamp']);
    }
    if (isset($payloadToVerify['_timestamp'])) {
        unset($payloadToVerify['_timestamp']);
    }
    
    error_log("SACCUSSALIS HOLD: Verifying payload: " . json_encode($payloadToVerify));
    error_log("SACCUSSALIS HOLD: Signature: " . substr($signature, 0, 50) . "...");
    error_log("SACCUSSALIS HOLD: Timestamp: " . $timestamp);

    if (!$signature) {
        error_log("SACCUSSALIS HOLD: Missing signature from {$requester}");
        echo json_encode([
            'status' => 'ERROR',
            'hold_placed' => false,
            'debited' => false,
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
            'debited' => false,
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
            'debited' => false,
            'message' => 'Invalid signature - hold request cannot be trusted'
        ]);
        exit;
    }

    error_log("SACCUSSALIS HOLD: Signature verified from {$requester}");

    // ============================================================
    // PROCESS HOLD
    // ============================================================

    // Determine action - check multiple possible field names
    $action = strtoupper(trim($input['action'] ?? $input['type'] ?? 'PLACE_HOLD'));
    
    // Get amount - check multiple possible field names
    $amount = floatval($input['amount'] ?? $input['value'] ?? 0);
    
    // Get hold reference - check multiple possible field names
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? uniqid('HOLD-');
    
    // Map foreign bank - check multiple possible field names
    $foreignBank = $input['foreign_bank'] ?? $input['destination_institution'] ?? $input['destination'] ?? $input['beneficiary_bank'] ?? null;
    
    // Get phone - check ALL possible field names from SwapService
    $phone = $input['phone'] ?? 
             $input['ewallet_phone'] ?? 
             $input['wallet_phone'] ?? 
             $input['claimant_phone'] ?? 
             $input['beneficiary_phone'] ?? 
             $input['account_phone'] ?? 
             null;
    
    // Get asset-specific identifiers
    $accountNumber = $input['account_number'] ?? null;
    $voucherNumber = $input['voucher_number'] ?? null;
    $cardNumber = $input['card_number'] ?? null;
    
    // Log what we found
    error_log("[HOLD.PHP DEBUG] Action: $action, Amount: $amount, Phone: $phone, ForeignBank: $foreignBank");
    error_log("[HOLD.PHP DEBUG] Asset identifiers - Account: $accountNumber, Voucher: $voucherNumber, Card: $cardNumber");

    // Validate based on action type
    if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
        if (!$phone && !$accountNumber && !$voucherNumber && !$cardNumber) {
            throw new Exception("No identifier found. Need one of: phone, ewallet_phone, wallet_phone, account_number, voucher_number, or card_number");
        }
        
        if ($amount <= 0) {
            throw new Exception("Valid amount required");
        }
        
        if (!$foreignBank) {
            error_log("WARNING: foreign_bank not provided in payload");
            $foreignBank = 'UNKNOWN';
        }
    }

    // Ensure session_id exists (for tracking)
    $sessionId = trim($input['session_id'] ?? $input['reference'] ?? uniqid('SESSION-'));
    
    $pdo->beginTransaction();

    // Determine which wallet to use - try phone first, then other identifiers
    $wallet = null;
    
    if ($phone) {
        $stmt = $pdo->prepare("
            SELECT wallet_id, balance, held_balance 
            FROM wallets 
            WHERE phone = ? AND status = 'active' AND is_frozen = false
            FOR UPDATE
        ");
        $stmt->execute([$phone]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($accountNumber) {
        $stmt = $pdo->prepare("
            SELECT w.wallet_id, w.balance, w.held_balance 
            FROM wallets w
            JOIN accounts a ON a.user_id = w.user_id
            WHERE a.account_number = ? AND w.status = 'active' AND w.is_frozen = false
            FOR UPDATE
        ");
        $stmt->execute([$accountNumber]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$wallet) {
        $identifier = $phone ?? $accountNumber ?? $voucherNumber ?? $cardNumber ?? 'unknown';
        throw new Exception("Wallet not found or inactive/frozen for identifier: $identifier");
    }

    if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
        $availableBalance = $wallet['balance'] - ($wallet['held_balance'] ?? 0);
        if ($availableBalance < $amount) {
            throw new Exception("Insufficient available balance. Available: {$availableBalance}, Required: {$amount}");
        }

        $stmt = $pdo->prepare("UPDATE wallets SET held_balance = COALESCE(held_balance,0) + ? WHERE wallet_id = ?");
        $stmt->execute([$amount, $wallet['wallet_id']]);

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

    } elseif (in_array($action, ['RELEASE_HOLD', 'RELEASE', 'REVERSE'])) {
        $stmt = $pdo->prepare("
            SELECT id, wallet_id, amount 
            FROM financial_holds 
            WHERE hold_reference = ? AND status = 'HELD'
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hold) {
            throw new Exception("Active hold not found for reference: $holdReference");
        }

        $stmt = $pdo->prepare("UPDATE financial_holds SET status = 'RELEASED', released_at = NOW() WHERE id = ?");
        $stmt->execute([$hold['id']]);

        $stmt = $pdo->prepare("UPDATE wallets SET held_balance = GREATEST(COALESCE(held_balance,0) - ?, 0) WHERE wallet_id = ?");
        $stmt->execute([$amount, $wallet['wallet_id']]);

        $message = "Hold released successfully";
        $holdPlaced = false;
        
    } elseif (in_array($action, ['DEBIT_FUNDS', 'DEBIT', 'COMMIT'])) {
        $stmt = $pdo->prepare("
            SELECT id, wallet_id, amount 
            FROM financial_holds 
            WHERE hold_reference = ? AND status = 'HELD'
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hold) {
            throw new Exception("Active hold not found for reference: $holdReference");
        }

        $stmt = $pdo->prepare("
            UPDATE wallets 
            SET balance = balance - ?,
                held_balance = GREATEST(COALESCE(held_balance,0) - ?, 0)
            WHERE wallet_id = ?
        ");
        $stmt->execute([$amount, $amount, $wallet['wallet_id']]);

        $stmt = $pdo->prepare("UPDATE financial_holds SET status = 'DEBITED', debited_at = NOW(), debited_by = ? WHERE id = ?");
        $stmt->execute([$requester, $hold['id']]);

        $message = "Funds debited successfully";
        $holdPlaced = false;
        
    } else {
        throw new Exception("Unsupported action: $action");
    }

    // Get updated wallet balance
    $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ?");
    $stmt->execute([$wallet['wallet_id']]);
    $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'SUCCESS',
        'hold_placed' => isset($holdPlaced) ? $holdPlaced : ($action === 'DEBIT_FUNDS' ? false : true),
        'hold_reference' => $holdReference,
        'session_id' => $sessionId,
        'message' => $message,
        'debited' => $action === 'DEBIT_FUNDS',
        'new_balance' => $updatedWallet['balance'] - $updatedWallet['held_balance'],
        'held_balance' => $updatedWallet['held_balance'],
        'available_balance' => $updatedWallet['balance'] - $updatedWallet['held_balance'],
        'requester' => $requester,
        'signature_verified' => $isValid
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("SACCUSSALIS Hold.php ERROR: " . $e->getMessage());
    error_log("Hold.php Input: " . json_encode($input ?? []));

    echo json_encode([
        'status' => 'ERROR',
        'hold_placed' => false,
        'debited' => false,
        'message' => 'Bank communication failed',
        'reason' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'timestamp' => time()
    ]);
    http_response_code(400);
}
