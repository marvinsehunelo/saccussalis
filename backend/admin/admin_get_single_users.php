<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
require_once("../db.php"); // PDO connection

try {
    // Only allow POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => 'POST method required'
        ]);
        exit;
    }

    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');

    if (!$email) {
        throw new Exception('Email is required');
    }

    // Fetch user
    $stmt = $pdo->prepare("
        SELECT user_id, full_name, email, status
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found'
        ]);
        exit;
    }

    // Fetch accounts for this user
    $stmt = $pdo->prepare("
        SELECT account_id, account_number, account_type, balance, is_frozen
        FROM accounts
        WHERE user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user['user_id']]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $user['accounts'] = $accounts;

    echo json_encode([
        'status' => 'success',
        'user' => $user
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
