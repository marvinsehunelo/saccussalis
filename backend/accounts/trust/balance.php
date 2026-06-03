<?php
// /backend/accounts/trust/balance.php

header("Content-Type: application/json; charset=utf-8");

$stmt = $pdo->prepare("SELECT balance, account_number, account_type FROM accounts WHERE account_number = 'TRUST-CAZACOM-001'");
$stmt->execute();
$trust = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trust) {
    echo json_encode(['status' => 'error', 'message' => 'Trust account not found']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'account_number' => $trust['account_number'],
    'balance' => (float)$trust['balance'],
    'currency' => 'BWP'
]);
