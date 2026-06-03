<?php
// /backend/settlement/debit.php

header("Content-Type: application/json; charset=utf-8");

// $pdo and $current_user_id are available from parent api.php

$input = json_decode(file_get_contents("php://input"), true);

$amount = isset($input['amount']) ? (float)$input['amount'] : 0;
$reference = $input['reference'] ?? null;
$destinationAccount = $input['destination_account'] ?? null;

if (!$amount || $amount <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid amount"]);
    exit;
}

if (!$reference) {
    echo json_encode(["status" "error", "message" => "Reference required"]);
    exit;
}

$pdo->beginTransaction();

try {
    // Check trust account balance
    $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_number = 'TRUST-CAZACOM-001' FOR UPDATE");
    $stmt->execute();
    $trust = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trust || $trust['balance'] < $amount) {
        throw new Exception("Insufficient trust account balance");
    }

    // Debit trust account
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - :amount, updated_at = NOW() WHERE account_number = 'TRUST-CAZACOM-001'");
    $stmt->execute(['amount' => $amount]);

    // Credit destination account if provided
    if ($destinationAccount) {
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + :amount, updated_at = NOW() WHERE account_number = :account");
        $stmt->execute(['amount' => $amount, 'account' => $destinationAccount]);
    }

    // Record settlement transaction
    $transactionId = 'SETTLE-' . time() . '-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("
        INSERT INTO settlement_ledger 
        (transaction_id, reference, amount, direction, status, counterparty, created_at) 
        VALUES (:tid, :ref, :amt, 'debit', 'completed', 'CAZACOM', NOW())
    ");
    $stmt->execute([
        'tid' => $transactionId,
        'ref' => $reference,
        'amt' => $amount
    ]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'transaction_id' => $transactionId,
        'trust_balance' => $trust['balance'] - $amount,
        'message' => "Debited BWP{$amount} from Cazacom Trust Account"
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
