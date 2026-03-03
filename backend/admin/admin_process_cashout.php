<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once("../db.php");
require_once("../auth/admin_session.php");
if (!isset($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$input = json_decode(file_get_contents('php://input'), true);
$request_id = $input['request_id'] ?? null;
$action = $input['action'] ?? null; // 'approve' or 'reject'
if (!$request_id || !in_array($action, ['approve','reject'])) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM cashout_requests WHERE request_id = ? FOR UPDATE");
    $stmt->execute([$request_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception("Cashout request not found");
    if ($r['status'] !== 'pending') throw new Exception("Already processed");

    if ($action === 'reject') {
        $upd = $pdo->prepare("UPDATE cashout_requests SET status='rejected' WHERE request_id = ?");
        $upd->execute([$request_id]);
        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Cashout rejected']);
        exit;
    }

    // Approve path: debit user's wallet or account as needed
    // Here we assume user has an account; deduct from primary account
    $stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE user_id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$r['user_id']]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) throw new Exception("User account not found to debit for cashout");
    if ($acc['balance'] < $r['amount']) throw new Exception("Insufficient funds for cashout");

    $newBalance = $acc['balance'] - $r['amount'];
    $updAcc = $pdo->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?");
    $updAcc->execute([$newBalance, $acc['account_id']]);

    $updReq = $pdo->prepare("UPDATE cashout_requests SET status='approved', processed_by=?, processed_at=NOW() WHERE request_id = ?");
    $updReq->execute([$_SESSION['admin_id'], $request_id]);

    $tx = $pdo->prepare("INSERT INTO transactions (account_id, user_id, type, amount, reference, status, created_at) VALUES (?, ?, 'cashout', ?, ?, 'completed', NOW())");
    $tx->execute([$acc['account_id'], $r['user_id'], -$r['amount'], 'CASHOUT-' . bin2hex(random_bytes(6))]);

    $audit = $pdo->prepare("INSERT INTO audit_log (actor_type, actor_id, action, ip_address) VALUES ('admin', ?, ?, ?)");
    $audit->execute([$_SESSION['admin_id'], "Approved cashout $request_id for user {$r['user_id']}", $_SERVER['REMOTE_ADDR'] ?? null]);

    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'Cashout approved and debited']);
} catch(Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
