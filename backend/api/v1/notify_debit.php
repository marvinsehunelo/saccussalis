<?php
// backend/api/v1/notify_debit.php

require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    error_log("=== NOTIFY_DEBIT.PHP RECEIVED ===");
    error_log(json_encode($input));

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

    // Update hold status to COMMITTED (fully settled)
    $stmt = $pdo->prepare("
        UPDATE financial_holds 
        SET status = 'COMMITTED', cashout_confirmed = TRUE 
        WHERE id = ?
    ");
    $stmt->execute([$hold['id']]);

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

    // Create settlement record
    $stmt = $pdo->prepare("
        INSERT INTO settlements 
            (settlement_ref, type, wallet_id, amount, issuer_bank, status, created_at)
        VALUES 
            (?, 'HOLD_SETTLEMENT', ?, ?, ?, 'completed', NOW())
        RETURNING settlement_id
    ");
    $stmt->execute([$transactionReference, $hold['wallet_id'], $amount, $fromBank]);

    // Create ledger entry
    $stmt = $pdo->prepare("
        INSERT INTO ledger_entries 
            (reference, debit_account, credit_account, amount, currency, notes)
        VALUES 
            (?, 'WALLET:' || ?, 'ACCOUNT:' || ?, ?, 'BWP', 'Hold settlement')
    ");
    $stmt->execute([$transactionReference, $hold['wallet_id'], $settlementAccount, $amount]);

    $pdo->commit();

    echo json_encode([
        'status' => 'SUCCESS',
        'debited' => true,
        'transaction_reference' => $transactionReference,
        'amount' => $amount,
        'message' => 'Funds debited and settled successfully'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("notify_debit.php error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'debited' => false,
        'message' => $e->getMessage()
    ]);
}
