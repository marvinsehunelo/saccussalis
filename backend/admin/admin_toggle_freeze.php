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
if (!$account_id) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }

try {
    $stmt = $pdo->prepare("SELECT status FROM accounts WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) throw new Exception("Account not found");
    $new = ($acc['status'] === 'active') ? 'frozen' : 'active';
    $upd = $pdo->prepare("UPDATE accounts SET status = ? WHERE account_id = ?");
    $upd->execute([$new, $account_id]);

    $audit = $pdo->prepare("INSERT INTO audit_log (actor_type, actor_id, action, ip_address) VALUES ('admin', ?, ?, ?)");
    $audit->execute([$_SESSION['admin_id'], "Set account $account_id status to $new", $_SERVER['REMOTE_ADDR'] ?? null]);

    echo json_encode(['success'=>true,'message'=>"Account status updated to $new"]);
} catch(Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
