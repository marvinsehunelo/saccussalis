<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once("../db.php");
require_once("../auth/admin_session.php");
if (!isset($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$clientId = $_GET['client_id'] ?? null;
if (!$clientId) { echo json_encode(['success'=>false,'message'=>'Missing client_id']); exit; }

try {
    $stmt = $pdo->prepare("SELECT user_id, full_name, email, phone, kyc_status FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new Exception("Client not found");
    echo json_encode(['success'=>true,'client'=>$client]);
} catch(Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
