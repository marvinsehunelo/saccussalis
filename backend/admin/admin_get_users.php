<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");

try {
    // Fetch all users + total balance (sum of accounts)
    $stmt = $pdo->prepare("
        SELECT 
            u.user_id,
            u.email,
            COALESCE(SUM(a.balance),0) AS balance,
            u.status
        FROM users u
        LEFT JOIN accounts a ON u.user_id = a.user_id
        GROUP BY u.user_id, u.email, u.status
        ORDER BY u.user_id ASC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "users" => $users
    ]);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
