<?php
header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");

try {
    // --- token handling ---
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? ($_GET['token'] ?? null);
    if (!$token) throw new Exception("Token required");

    // --- validate session ---
    $stmt = $pdo->prepare("
        SELECT s.*, u.full_name, u.role 
        FROM sessions s 
        JOIN users u ON s.user_id = u.user_id 
        WHERE s.token = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) throw new Exception("Invalid or expired token");

    $userId = $session['user_id'];
    $username = $session['full_name'];
    $role = $session['role'];

    // --- accounts ---
    $stmt = $pdo->prepare("SELECT account_number, account_type, balance FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as &$acc) $acc['balance'] = (float)$acc['balance'];
    unset($acc);
    $totalBalance = array_sum(array_column($accounts, "balance"));

    // --- pending wallet transactions ---
    $stmt = $pdo->prepare("
        SELECT id AS wallet_transaction_id, amount, transaction_type, status, created_at 
        FROM wallet_transactions 
        WHERE user_id = ? AND status='pending'
    ");
    $stmt->execute([$userId]);
    $pendingWallet = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingWallet as &$wt) $wt['amount'] = (float)$wt['amount'];
    unset($wt);

    $totalPending = array_sum(array_column($pendingWallet, 'amount'));
    $availableBalance = $totalBalance - $totalPending;

    // --- recent transactions ---
    $stmt = $pdo->prepare("
        SELECT transaction_id, amount, type, created_at 
        FROM transactions 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($transactions as &$tx) $tx['amount'] = (float)$tx['amount'];
    unset($tx);

    echo json_encode([
        "status" => "success",
        "username" => $username,
        "role" => $role,
        "totalBalance" => $totalBalance,
        "availableBalance" => $availableBalance, // NEW: balance after pending transactions
        "accounts" => $accounts,
        "recentTransactions" => $transactions,
        "pendingWalletTransactions" => $pendingWallet
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
