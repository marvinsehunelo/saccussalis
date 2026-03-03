<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once("../db.php");
header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(["status" => "error", "message" => "Email and password required"]);
    exit;
}

try {
    // Only allow superadmin, admin, manager, teller, auditor
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND role IN ('superadmin','admin','manager','teller','auditor')");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(["status" => "error", "message" => "Invalid credentials"]);
        exit;
    }

    // --- Critical session variables ---
    $_SESSION['admin_id'] = $user['user_id'];
    $_SESSION['userRole'] = $user['role'];
    $_SESSION['admin_name'] = $user['full_name'];

    // --- Generate token ---
    $token = bin2hex(random_bytes(32));
    $_SESSION['adminAuthToken'] = $token;

    // --- Collect session info ---
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $expires_at = date('Y-m-d H:i:s', strtotime('+12 hours'));
    $data_column = json_encode([
        'role' => $user['role'],
        'full_name' => $user['full_name']
    ]);
    $last_activity = date('Y-m-d H:i:s');

    // --- Insert into sessions table ---
    $stmtToken = $pdo->prepare("
        INSERT INTO sessions (user_id, ip_address, user_agent, token, expires_at, data, last_activity)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtToken->execute([$user['user_id'], $ip_address, $user_agent, $token, $expires_at, $data_column, $last_activity]);

    echo json_encode([
        "status" => "success",
        "message" => "Login successful",
        "token" => $token,
        "role" => $user['role'],
        "full_name" => $user['full_name']
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Login failed: " . $e->getMessage()]);
}
