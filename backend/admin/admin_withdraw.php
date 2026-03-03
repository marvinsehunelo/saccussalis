<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once("../db.php");
require_once("../auth/admin_session.php");
if (!isset($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$account_id = $input['account_id'] ?? null;
$amount = floatval($input['amount'] ?? 0);
if (!$account_id || $amount <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT balance, user_id FROM accounts WHERE account_id = ? FOR UPDATE");
    $stmt->execute([$account_id]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) throw new Exception("Account not found");
    if ($acc['balance'] < $amount) throw new Exception("Insufficient funds");

    $new = $acc['balance'] - $amount;
    $upd = $pdo->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?");
    $upd->execute([$new, $account_id]);

    $tx = $pdo->prepare("INSERT INTO transactions (account_id, user_id, type, amount, reference, status, created_at) VALUES (?, ?, 'withdrawal', ?, ?, 'completed', NOW())");
    $ref = 'ADMINWTH-' . bin2hex(random_bytes(6));
    $tx->execute([$account_id, $acc['user_id'], -$amount, $ref]);

    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, ip_address) VALUES ('admin', ?, ?, ?)");
    $audit->execute([$_SESSION['admin_id'], "Withdrawal $amount from account $account_id (ref $ref)", $_SERVER['REMOTE_ADDR'] ?? null]);

    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'Withdrawal successful','new_balance'=>$new]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
