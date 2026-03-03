<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");

try {
    // Protect access
    if (empty($_SESSION['adminAuthToken'])) {
        echo json_encode(["status" => "error", "message" => "Not authenticated"]);
        exit;
    }

    // Fetch last 50 transactions with full details
    $stmt = $pdo->prepare("
        SELECT 
            t.transaction_id AS id,
            t.user_id,
            u.full_name AS user_name,
            t.from_account,
            t.to_account,
            t.external_bank_id,
            t.amount,
            t.fee_amount,
            t.type,
            t.status,
            t.created_at
        FROM transactions t
        LEFT JOIN users u ON u.user_id = t.user_id
        ORDER BY t.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "transactions" => $rows], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
