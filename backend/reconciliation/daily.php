<?php
// /backend/reconciliation/daily.php

header("Content-Type: application/json; charset=utf-8");

// Check if this is an internal request
$internalKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? null;
if ($internalKey !== 'SACCUS_INTERNAL_KEY_2025') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) as total_credits,
        COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) as total_debits,
        COUNT(*) as transaction_count
    FROM settlement_ledger 
    WHERE created_at >= CURRENT_DATE
");
$stmt->execute();
$daily = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_number = 'TRUST-CAZACOM-001'");
$stmt->execute();
$trust = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'status' => 'success',
    'date' => date('Y-m-d'),
    'trust_balance' => (float)$trust['balance'],
    'daily_credits' => (float)$daily['total_credits'],
    'daily_debits' => (float)$daily['total_debits'],
    'net_change' => (float)$daily['total_credits'] - (float)$daily['total_debits'],
    'transaction_count' => (int)$daily['transaction_count']
]);
