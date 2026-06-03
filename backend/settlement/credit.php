<?php
// /backend/settlement/credit.php

header("Content-Type: application/json; charset=utf-8");

$input = json_decode(file_get_contents("php://input"), true);

$amount = isset($input['amount']) ? (float)$input['amount'] : 0;
$reference = $input['reference'] ?? null;
$sourceReference = $input['source_reference'] ?? null;

if (!$amount || $amount <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid amount"]);
    exit;
}

if (!$reference) {
    echo json_encode(["status" => "error", "message" => "Reference required"]);
    exit;
}

$pdo->beginTransaction();

try {
    // Credit trust account
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + :amount, updated_at = NOW() WHERE account_number = 'TRUST-CAZACOM-001'");
    $stmt->execute(['amount' => $amount]);

    // Record settlement transaction
    $transactionId = 'SETTLE-' . time() . '-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("
        INSERT INTO settlement_ledger 
        (transaction_id, reference, amount, direction, status, counterparty, source_ref, created_at) 
        VALUES (:tid, :ref, :amt, 'credit', 'completed', 'CAZACOM', :src, NOW())
    ");
    $stmt->execute([
        'tid' => $transactionId,
        'ref' => $reference,
        'amt' => $amount,
        'src' => $sourceReference
    ]);

    $pdo->commit();

    $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_number = 'TRUST-CAZACOM-001'");
    $stmt->execute();
    $newBalance = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'transaction_id' => $transactionId,
        'trust_balance' => (float)$newBalance['balance'],
        'message' => "Credited BWP{$amount} to Cazacom Trust Account"
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
