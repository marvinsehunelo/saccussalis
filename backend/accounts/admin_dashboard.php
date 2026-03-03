<?php
header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");
require_once("../admin_auth.php");

try {
    $session = admin_validate_token($pdo);
    $adminId = $session['user_id'];

    // Optional filters from GET
    $search = $_GET['search'] ?? null; // account number or user
    $limit = (int)($_GET['limit'] ?? 100);
    $limit = max(20, min(500, $limit));

    // 1) All accounts or filtered
    if ($search) {
        $like = "%$search%";
        $stmt = $pdo->prepare("
            SELECT a.*, u.full_name 
            FROM accounts a
            JOIN users u ON a.user_id = u.user_id
            WHERE a.account_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?
            ORDER BY a.account_id DESC
            LIMIT ?
        ");
        $stmt->execute([$like,$like,$like,$limit]);
    } else {
        $stmt = $pdo->prepare("SELECT a.*, u.full_name FROM accounts a JOIN users u ON a.user_id = u.user_id ORDER BY a.account_id DESC LIMIT ?");
        $stmt->execute([$limit]);
    }
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as &$a) $a['balance'] = (float)$a['balance'];
    unset($a);

    // 2) Pending wallet transactions (deposits/withdrawals) for approval
    $stmt = $pdo->prepare("SELECT wt.*, u.full_name FROM wallet_transactions wt JOIN users u ON wt.user_id = u.user_id WHERE wt.status = 'pending' ORDER BY wt.created_at ASC");
    $stmt->execute();
    $pendingWallet = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingWallet as &$w) $w['amount'] = (float)$w['amount'];
    unset($w);

    // 3) Recent transactions (global)
    $stmt = $pdo->prepare("SELECT t.*, u.full_name FROM transactions t JOIN users u ON t.user_id = u.user_id ORDER BY t.created_at DESC LIMIT 200");
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($transactions as &$t) $t['amount'] = (float)$t['amount'];
    unset($t);

    // 4) Summary statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total_clients FROM users WHERE role='client'");
    $clientsCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT SUM(balance) as total_deposits FROM accounts");
    $totalDeposits = (float)$stmt->fetchColumn();

    echo json_encode([
        "status" => "success",
        "admin" => ["user_id"=>$session['user_id'], "name"=>$session['full_name'], "role"=>$session['role']],
        "accounts" => $accounts,
        "pendingWalletTransactions" => $pendingWallet,
        "recentTransactions" => $transactions,
        "summary" => ["total_clients"=>$clientsCount, "total_deposits"=>$totalDeposits]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
