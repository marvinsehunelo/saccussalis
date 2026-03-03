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

    // Fetch last 50 audit logs
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.user_id,
            u.full_name AS user_name,
            a.action,
            a.details,
            a.created_at
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "audits" => $rows], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
