<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");

// DEBUG: see session content
// Remove this after debugging
echo json_encode($_SESSION);
exit;

require_once("../db.php");

try {
    // Check method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("POST method required");
    }

    // Allowed roles for deposit
    $allowedRoles = ['superadmin', 'admin', 'teller', 'manager'];

    // Check session and role
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles)) {
        throw new Exception("Admin not logged in or insufficient privileges");
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $target_user_id = $data['user_id'] ?? null; // Target user
    $account_id = $data['account_id'] ?? null;
    $amount = $data['amount'] ?? 0;

    if (!$target_user_id || !$account_id || $amount <= 0) {
        throw new Exception("Invalid user, account, or amount");
    }

    // Verify the account belongs to the target user
    $check = $pdo->prepare("SELECT account_id FROM accounts WHERE account_id = ? AND user_id = ?");
    $check->execute([$account_id, $target_user_id]);
    if ($check->rowCount() === 0) {
        throw new Exception("Account not found for the specified user");
    }

    // Update balance
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
    $stmt->execute([$amount, $account_id]);

    // Log transaction
    $log = $pdo->prepare("INSERT INTO wallet_transactions (user_id, account_id, amount, type, admin_id, created_at)
                          VALUES (?, ?, ?, 'deposit', ?, NOW())");
    $log->execute([$target_user_id, $account_id, $amount, $_SESSION['user_id']]); // admin_id stored for auditing

    echo json_encode(["status" => "success", "message" => "Deposit successful"]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
