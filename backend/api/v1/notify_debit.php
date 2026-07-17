<?php
// backend/api/v1/notify_debit.php

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    error_log("=== SACCUSSALIS NOTIFY_DEBIT.PHP RECEIVED ===");
    error_log(json_encode($input));

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION (REQUIRED)
    // ============================================================
    
    if (!isset($input['certificate'])) {
        error_log("SACCUSSALIS NOTIFY_DEBIT: No certificate provided");
        echo json_encode([
            'status' => 'ERROR',
            'debited' => false,
            'message' => 'Certificate required - please upgrade to certificate-based authentication'
        ]);
        exit;
    }
    
    $certManager = new CertificateManager('SACCUSSALIS');
    $verification = $certManager->verifySignedRequest($input);
    $isValid = $verification['verified'];
    $requester = $verification['requester'];
    
    error_log("SACCUSSALIS NOTIFY_DEBIT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    error_log("SACCUSSALIS NOTIFY_DEBIT: Requester: {$requester}");
    
    if (!$isValid) {
        error_log("SACCUSSALIS NOTIFY_DEBIT: Certificate verification failed");
        echo json_encode([
            'status' => 'ERROR',
            'debited' => false,
            'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
        ]);
        exit;
    }
    
    error_log("SACCUSSALIS NOTIFY_DEBIT: Request verified from {$requester} using certificate");

    // ============================================================
    // PROCESS DEBIT
    // ============================================================

    // Initialize default values
    $debited = false;
    $hold = null;

    // Get parameters
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? null;
    $amount = floatval($input['amount'] ?? 0);
    $transactionReference = $input['transaction_reference'] ?? uniqid('DEBIT-');
    $fromBank = $input['source_institution'] ?? $input['from_bank'] ?? 'SACCUSSALIS';

    if (!$holdReference || $amount <= 0) {
        throw new Exception("Missing required parameters: hold_reference and amount");
    }

    $pdo->beginTransaction();

    // Get hold record with proper locking
    $stmt = $pdo->prepare("
        SELECT * FROM financial_holds 
        WHERE hold_reference = ? AND status = 'ACTIVE'
        FOR UPDATE
    ");
    $stmt->execute([$holdReference]);
    $hold = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$hold) {
        throw new Exception("Active hold not found for reference: $holdReference");
    }

    error_log("SACCUSSALIS NOTIFY_DEBIT: Found hold ID={$hold['id']}, Amount={$hold['amount']}, AssetType={$hold['asset_type']}");

    // Determine debit amount
    $debitAmount = $amount > 0 ? $amount : (float)$hold['amount'];
    $assetId = null;
    $assetType = strtoupper($hold['asset_type'] ?? 'WALLET');

    // ============================================================
    // BRANCH: WALLET OR ACCOUNT DEBIT
    // ============================================================
    if (!empty($hold['wallet_id'])) {
        // ============================================================
        // WALLET DEBIT PATH
        // ============================================================
        error_log("SACCUSSALIS NOTIFY_DEBIT: Processing WALLET debit for wallet_id={$hold['wallet_id']}");
        
        $stmt = $pdo->prepare("
            SELECT phone, user_id, balance, held_balance 
            FROM wallets 
            WHERE wallet_id = ? 
            FOR UPDATE
        ");
        $stmt->execute([$hold['wallet_id']]);
        $walletRow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$walletRow) {
            throw new Exception("Wallet not found: {$hold['wallet_id']}");
        }
        
        $hold = array_merge($hold, $walletRow);

        // Update wallet balance and held_balance
        $stmt = $pdo->prepare("
            UPDATE wallets 
            SET balance = balance - ?,
                held_balance = GREATEST(COALESCE(held_balance,0) - ?, 0)
            WHERE wallet_id = ?
        ");
        $stmt->execute([$debitAmount, $debitAmount, $hold['wallet_id']]);

        // Get updated wallet balance
        $stmt = $pdo->prepare("
            SELECT balance, held_balance 
            FROM wallets 
            WHERE wallet_id = ?
        ");
        $stmt->execute([$hold['wallet_id']]);
        $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $assetId = $hold['wallet_id'];
        $assetType = 'WALLET';

    } elseif (!empty($hold['account_id'])) {
        // ============================================================
        // ACCOUNT DEBIT PATH
        // ============================================================
        error_log("SACCUSSALIS NOTIFY_DEBIT: Processing ACCOUNT debit for account_id={$hold['account_id']}");
        
        $stmt = $pdo->prepare("
            SELECT balance, held_balance 
            FROM accounts 
            WHERE account_id = ? 
            FOR UPDATE
        ");
        $stmt->execute([$hold['account_id']]);
        $acctRow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$acctRow) {
            throw new Exception("Account not found: {$hold['account_id']}");
        }
        
        $hold = array_merge($hold, $acctRow);

        // Update account balance and held_balance
        $stmt = $pdo->prepare("
            UPDATE accounts 
            SET balance = balance - ?,
                held_balance = GREATEST(COALESCE(held_balance,0) - ?, 0)
            WHERE account_id = ?
        ");
        $stmt->execute([$debitAmount, $debitAmount, $hold['account_id']]);

        // Get updated account balance
        $stmt = $pdo->prepare("
            SELECT balance, held_balance 
            FROM accounts 
            WHERE account_id = ?
        ");
        $stmt->execute([$hold['account_id']]);
        $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $assetId = $hold['account_id'];
        $assetType = 'ACCOUNT';

    } else {
        throw new Exception("Hold {$holdReference} has neither wallet_id nor account_id set");
    }

    // ============================================================
    // UPDATE HOLD STATUS TO DEBITED - USING ONLY EXISTING COLUMNS
    // ============================================================
    $stmt = $pdo->prepare("
        UPDATE financial_holds 
        SET status = 'DEBITED', 
            cashout_confirmed = TRUE,
            debited_by = :requester,
            signature_verified = :sig_verified
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $hold['id'],
        ':requester' => $requester,
        ':sig_verified' => $isValid ? 1 : 0
    ]);

    // ============================================================
    // CREDIT THE SETTLEMENT ACCOUNT
    // ============================================================
    $settlementAccount = '111111111';
    $stmt = $pdo->prepare("
        UPDATE accounts 
        SET balance = balance + ? 
        WHERE account_number = ?
        RETURNING balance
    ");
    $stmt->execute([$debitAmount, $settlementAccount]);
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settlement) {
        throw new Exception("Settlement account not found");
    }

    error_log("SACCUSSALIS NOTIFY_DEBIT: Settlement account {$settlementAccount} new balance: {$settlement['balance']}");

    // ============================================================
    // CREATE SETTLEMENT RECORD
    // ============================================================
    $stmt = $pdo->prepare("
        INSERT INTO settlements 
            (settlement_ref, type, wallet_id, account_id, amount, issuer_bank, status, 
             requester, signature_verified, asset_type, created_at)
        VALUES 
            (?, 'HOLD_SETTLEMENT', ?, ?, ?, ?, 'completed', ?, ?, ?, NOW())
        RETURNING settlement_id
    ");
    
    $walletId = !empty($hold['wallet_id']) ? $hold['wallet_id'] : null;
    $accountId = !empty($hold['account_id']) ? $hold['account_id'] : null;
    
    $stmt->execute([
        $transactionReference,
        $walletId,
        $accountId,
        $debitAmount,
        $fromBank,
        $requester,
        $isValid ? 1 : 0,
        $assetType
    ]);
    $settlementId = $stmt->fetchColumn();
    error_log("SACCUSSALIS NOTIFY_DEBIT: Settlement record created ID={$settlementId}");

    // ============================================================
    // CREATE LEDGER ENTRY
    // ============================================================
    $debitAccount = $assetType . ':' . $assetId;
    $creditAccount = 'ACCOUNT:' . $settlementAccount;
    
    $stmt = $pdo->prepare("
        INSERT INTO ledger_entries 
            (reference, debit_account, credit_account, amount, currency, notes, requester, created_at)
        VALUES 
            (?, ?, ?, ?, 'BWP', 'Hold settlement', ?, NOW())
    ");
    $stmt->execute([
        $transactionReference,
        $debitAccount,
        $creditAccount,
        $debitAmount,
        $requester
    ]);

    $pdo->commit();
    $debited = true;

    error_log("SACCUSSALIS NOTIFY_DEBIT: Debit completed successfully - Ref: {$transactionReference}, AssetType: {$assetType}");

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $availableBalance = (float)($updatedWallet['balance'] ?? 0) - (float)($updatedWallet['held_balance'] ?? 0);
    
    $responsePayload = [
        'status' => 'SUCCESS',
        'debited' => true,
        'transaction_reference' => $transactionReference,
        'hold_reference' => $holdReference,
        'amount' => $debitAmount,
        'asset_type' => $assetType,
        'asset_id' => $assetId,
        'new_balance' => (float)($updatedWallet['balance'] ?? 0),
        'held_balance' => (float)($updatedWallet['held_balance'] ?? 0),
        'available_balance' => $availableBalance,
        'message' => "Funds debited from {$assetType} and settled successfully",
        'requester' => $requester,
        'signature_verified' => $isValid
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS notify_debit.php ERROR: " . $e->getMessage());
    error_log("SACCUSSALIS notify_debit.php Input: " . json_encode($input ?? []));
    
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'debited' => false,
        'message' => 'Bank communication failed',
        'reason' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
