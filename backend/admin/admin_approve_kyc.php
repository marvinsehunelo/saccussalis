<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once("../db.php");
require_once("../auth/admin_session.php");
if (!isset($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$clientId = $input['client_id'] ?? null;
$status = $input['status'] ?? null;
if (!$clientId || !in_array($status, ['verified','rejected'])) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }

try {
    $stmt = $pdo->prepare("UPDATE users SET kyc_status = ? WHERE user_id = ?");
    $stmt->execute([$status, $clientId]);

    // audit log
    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, ip_address) VALUES ('admin', ?, ?, ?)");
    $action = "KYC set to $status for user $clientId";
    $audit->execute([$_SESSION['admin_id'], $action, $_SERVER['REMOTE_ADDR'] ?? null]);

    echo json_encode(['success'=>true,'message'=>"KYC updated to $status"]);
} catch(Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
