<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once("../db.php");
require_once("../auth/admin_session.php");
if (!isset($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$input = json_decode(file_get_contents('php://input'), true);
$loan_id = $input['loan_id'] ?? null;
$approve = !empty($input['approve']);
if (!$loan_id) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE loan_id = ? FOR UPDATE");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) throw new Exception("Loan not found");
    if ($loan['status'] !== 'pending') throw new Exception("Loan already processed");

    if ($approve) {
        // Mark approved, optionally disburse funds to the user's account
        $stmt = $pdo->prepare("UPDATE loans SET status='approved', approved_by_admin_id=?, approved_at=NOW() WHERE loan_id = ?");
        $stmt->execute([$_SESSION['admin_id'], $loan_id]);
        // disburse to user's first account
        $stmt2 = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE user_id = ? LIMIT 1 FOR UPDATE");
        $stmt2->execute([$loan['user_id']]);
        $acc = $stmt2->fetch(PDO::FETCH_ASSOC);
        if (!$acc) throw new Exception("User has no account to disburse loan");
        $newBal = $acc['balance'] + $loan['principal'];
        $u = $pdo->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?");
        $u->execute([$newBal, $acc['account_id']]);
        // Add transaction
        $ins = $pdo->prepare("INSERT INTO transactions (account_id, user_id, type, amount, reference, status, created_at) VALUES (?, ?, 'loan_disbursement', ?, ?, 'completed', NOW())");
        $ins->execute([$acc['account_id'], $loan['user_id'], $loan['principal'], 'LOAN-' . bin2hex(random_bytes(5))]);
    } else {
        $stmt = $pdo->prepare("UPDATE loans SET status='rejected' WHERE loan_id = ?");
        $stmt->execute([$loan_id]);
    }

    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, ip_address) VALUES ('admin', ?, ?, ?)");
    $audit->execute([$_SESSION['admin_id'], ($approve ? "Approved loan $loan_id" : "Rejected loan $loan_id"), $_SERVER['REMOTE_ADDR'] ?? null]);

    $pdo->commit();
    echo json_encode(['success'=>true,'message'=> $approve ? 'Loan approved and disbursed' : 'Loan rejected']);
} catch(Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
