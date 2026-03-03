<?php
// backend/api/v1/settlement/cashout_settlements.php

header('Content-Type: application/json');
require_once '../../../db.php';
require_once '../../../middleware/Idempotency.php';

$input = json_decode(file_get_contents('php://input'), true);

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

    $type = $input['type'] ?? null; // 'SAT_TOKEN' or 'EWALLET_SWAP'
    if (!$type) throw new Exception("Settlement type required");

    $amount = floatval($input['amount'] ?? 0);
    $reference = 'SET' . time() . rand(100, 999);

    switch (strtoupper($type)) {

        case 'SAT_TOKEN':
            // No wallet involved, just record the settlement bill
            $stmt = $pdo->prepare("
                INSERT INTO settlements 
                (settlement_ref, type, sat_number, amount, issuer_bank, acquirer_bank, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $reference,
                $input['sat_number'] ?? null,
                $input['sat_number'] ?? null,
                $amount,
                $input['issuer_bank'] ?? null,
                $input['acquirer_bank'] ?? null
            ]);
            $message = "SAT token settlement recorded successfully";
            break;

        case 'EWALLET_SWAP':
            // Release hold and move funds from wallet to settlement account
            $walletId = $input['wallet_id'] ?? null;
            if (!$walletId) throw new Exception("Wallet ID required for eWallet swap settlement");

            // Check balance
            $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE wallet_id = ? FOR UPDATE");
            $stmt->execute([$walletId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$wallet) throw new Exception("Wallet not found");
            if ($wallet['balance'] < $amount) throw new Exception("Insufficient wallet balance");

            // Debit wallet
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE wallet_id = ?");
            $stmt->execute([$amount, $walletId]);

            // Credit settlement account (internal account)
            $stmt = $pdo->prepare("
                INSERT INTO settlements 
                (settlement_ref, wallet_id, amount, status, created_at)
                VALUES (?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([$reference, $walletId, $amount]);

            $message = "eWallet swap settlement completed successfully";
            break;

        default:
            throw new Exception("Unsupported settlement type: $type");
    }

    $pdo->commit();

    $response = [
        'status' => 'success',
        'settlement_ref' => $reference,
        'type' => $type,
        'amount' => $amount,
        'message' => $message
    ];

    Idempotency::store($idempotencyKey, $response);
    echo json_encode($response);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
