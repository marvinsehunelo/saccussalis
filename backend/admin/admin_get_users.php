<?php
// backend/admin/admin_get_users.php - the dashboard's USERS table (signed-in staff only).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");
require_once("../auth/admin_session.php");

try {
    // Every user with their KYC status, first account number and total balance.
    $stmt = $pdo->prepare("
        SELECT
            u.user_id,
            u.full_name,
            u.email,
            u.kyc_status,
            u.status,
            MIN(a.account_number) AS account_number,
            COALESCE(SUM(a.balance), 0) AS balance
        FROM users u
        LEFT JOIN accounts a ON u.user_id = a.user_id
        GROUP BY u.user_id, u.full_name, u.email, u.kyc_status, u.status
        ORDER BY u.user_id ASC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "users" => $users
    ]);
} catch (Exception $e) {
    error_log('[admin_get_users] ' . get_class($e));
    echo json_encode([
        "status" => "error",
        "message" => "Could not load users"
    ]);
}
