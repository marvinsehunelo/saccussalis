<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");

try {
    // --- Only accept POST ---
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("POST method required");
    }

    // --- Robust Token Retrieval (same as add_staff) ---
    $authHeader = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
    }
    if (!$authHeader) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    }

    $token = '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    }

    if (!$token) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Authorization token required"]);
        exit;
    }

    // --- Validate token in sessions table ---
    $stmt = $pdo->prepare("
        SELECT s.user_id, u.role, u.full_name
        FROM sessions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.token = ? AND s.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !in_array(strtolower($admin['role']), ['superadmin','admin','manager','teller'])) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Insufficient privileges"]);
        exit;
    }

    $admin_id = $admin['user_id'];
    $admin_role = $admin['role'];

    // --- Get input ---
    $input = json_decode(file_get_contents("php://input"), true);
    $account_id = $input['account_id'] ?? null;
    $amount     = (float)($input['amount'] ?? 0);
    $operation  = strtolower(trim($input['operation'] ?? ''));
    $reason     = trim($input['reason'] ?? '');

    if (!$account_id || $amount <= 0 || !$operation || !$reason) {
        throw new Exception("All fields (Account, Amount, Type, Reason) are required and amount must be positive.");
    }

    if (!in_array($operation, ['credit', 'debit'])) {
        throw new Exception("Invalid operation type. Use 'credit' or 'debit'.");
    }

    // --- Fetch account balance ---
    $stmt = $pdo->prepare("SELECT balance, user_id, account_number, account_type FROM accounts WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) throw new Exception("Target account not found.");

    $new_balance = $account['balance'];
    if ($operation === 'credit') {
        $new_balance += $amount;
    } else {
        if ($account['balance'] < $amount) throw new Exception("Insufficient balance for debit.");
        $new_balance -= $amount;
    }

    // --- Update account balance ---
    $stmt = $pdo->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?");
    $stmt->execute([$new_balance, $account_id]);

    // --- Record transaction ---
    $transactionType = ucfirst($operation) . " Adjustment";
    $stmt = $pdo->prepare("
        INSERT INTO transactions
        (user_id, from_account, to_account, amount, type, reason, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())
    ");
    $stmt->execute([
        $account['user_id'], // account owner
        $admin_id,           // admin performing adjustment
        $account_id,         // target account
        $amount,
        $transactionType,
        "$reason (by $admin_role)"
    ]);

    echo json_encode([
        "status" => "success",
        "message" => ucfirst($operation) . " of " . number_format($amount,2) . " successful by $admin_role.",
        "new_balance" => number_format($new_balance,2),
        "account_number" => $account['account_number'],
        "account_type" => $account['account_type']
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
