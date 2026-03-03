<?php
require_once("../db.php"); // your DB connection

header("Content-Type: application/json");

// ✅ Only allow POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit;
}

// ✅ Support both JSON and normal form submit
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!$data) {
    $data = $_POST; // fallback for form-encoded requests
}

// ✅ Validate input
if (empty($data['full_name']) || empty($data['email']) || empty($data['phone']) || empty($data['password'])) {
    echo json_encode(["status" => "error", "message" => "All fields are required"]);
    exit;
}

$full_name = trim($data['full_name']);
$email = trim($data['email']);
$phone = trim($data['phone']);
$password = password_hash($data['password'], PASSWORD_BCRYPT);
$role = "user"; // default role
$created_at = date("Y-m-d H:i:s");

try {
    $pdo->beginTransaction();

    // ✅ Insert into users (with role + created_at)
    $stmt = $pdo->prepare("
        INSERT INTO users (full_name, email, phone, password_hash, role, created_at) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$full_name, $email, $phone, $password, $role, $created_at]);
    $user_id = $pdo->lastInsertId();

    // ✅ Create accounts
    $savingsAcc = "SAV" . str_pad($user_id, 8, "0", STR_PAD_LEFT);
    $currentAcc = "CUR" . str_pad($user_id, 8, "0", STR_PAD_LEFT);

    $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, account_type) VALUES (?, ?, 'savings')");
    $stmt->execute([$user_id, $savingsAcc]);

    $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, account_type) VALUES (?, ?, 'current')");
    $stmt->execute([$user_id, $currentAcc]);

    // ✅ Create wallet
    $walletNo = "WAL" . str_pad($user_id, 8, "0", STR_PAD_LEFT);
    $stmt = $pdo->prepare("INSERT INTO wallets (user_id, wallet_type, phone, balance) VALUES (?, 'fnb_style', ?, 0.00)");
    $stmt->execute([$user_id, $phone]);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Registration successful",
        "user_id" => $user_id,
        "accounts" => [
            "savings" => $savingsAcc,
            "current" => $currentAcc
        ],
        "wallet" => $walletNo
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["status" => "error", "message" => "Registration failed: " . $e->getMessage()]);
}
