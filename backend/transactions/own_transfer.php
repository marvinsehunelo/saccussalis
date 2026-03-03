<?php
header("Content-Type: application/json");
require_once("../db.php");

try {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? ($_POST['token'] ?? null);

    if (!$token) throw new Exception("Token required");

    // Validate session
    $stmt = $pdo->prepare("
        SELECT s.*, u.full_name 
        FROM sessions s 
        JOIN users u ON s.user_id = u.user_id 
        WHERE s.token = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) throw new Exception("Invalid or expired token");

    $userId = $session['user_id'];

    // Input
    $source = $_POST['source'] ?? null;
    $target = $_POST['target'] ?? null;
    $amount = (float)($_POST['amount'] ?? 0);

    if (!$source || !$target || $source === $target || $amount <= 0) {
        throw new Exception("Invalid input");
    }

    $pdo->beginTransaction();

    // Source account
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_number=? AND user_id=? FOR UPDATE");
    $stmt->execute([$source, $userId]);
    $srcAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$srcAcc) throw new Exception("Source account not found");

    // Target account (same user)
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_number=? AND user_id=? FOR UPDATE");
    $stmt->execute([$target, $userId]);
    $tgtAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tgtAcc) throw new Exception("Target account not found");

    // No fees for own transfers
    if ($srcAcc['balance'] < $amount) {
        throw new Exception("Insufficient funds. Required: $amount");
    }

    // Deduct from source
    $stmt = $pdo->prepare("UPDATE accounts SET balance=balance-? WHERE account_number=?");
    $stmt->execute([$amount, $source]);

    // Credit target
    $stmt = $pdo->prepare("UPDATE accounts SET balance=balance+? WHERE account_number=?");
    $stmt->execute([$amount, $target]);

    // Log transactions
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$userId, -$amount, 'Own Transfer']);
    $stmt->execute([$userId, $amount, 'Own Transfer Received']);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Transfer of $$amount between your accounts completed."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
