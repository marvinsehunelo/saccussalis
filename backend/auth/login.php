<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once("../db.php");
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection unavailable"
    ]);
    exit;
}
header("Content-Type: application/json");

// CRITICAL: Check if database connected
if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection unavailable"
    ]);
    exit;
}

// Enable PDO exceptions
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to configure database"
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email and password required"]);
    exit;
}

try {
    // Test query to verify connection works
    $pdo->query("SELECT 1");
    
    // Fetch user by email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("User not found");
    }

    if (!password_verify($password, $user['password_hash'])) {
        throw new Exception("Invalid password");
    }

    // Generate token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+12 hours'));

    // Insert into sessions table
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $data_column = json_encode(['full_name' => $user['full_name']]);
    $last_activity = date('Y-m-d H:i:s');

    $stmtToken = $pdo->prepare("
        INSERT INTO sessions (user_id, token, ip_address, user_agent, data, last_activity, expires_at)
        VALUES (:user_id, :token, :ip_address, :user_agent, :data, :last_activity, :expires_at)
        RETURNING id
    ");

    $stmtToken->execute([
        ':user_id' => $user['user_id'],
        ':token' => $token,
        ':ip_address' => $ip_address,
        ':user_agent' => $user_agent,
        ':data' => $data_column,
        ':last_activity' => $last_activity,
        ':expires_at' => $expires_at
    ]);

    $sessionId = $stmtToken->fetchColumn();
    if (!$sessionId) {
        throw new Exception("Failed to create session");
    }

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['authToken'] = $token;

    echo json_encode([
        "status" => "success",
        "message" => "Login successful",
        "token" => $token,
        "full_name" => $user['full_name']
    ]);

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Login failed: Invalid credentials"
    ]);
}
?>

