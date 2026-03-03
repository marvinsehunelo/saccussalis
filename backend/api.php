<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON header
header("Content-Type: application/json; charset=utf-8");

// Include database
require_once(__DIR__ . "/db.php");

// --- Token Authentication ---
$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_GET['token'] ?? null);

if (!$token) {
    echo json_encode(["status" => "error", "message" => "Token required"]);
    exit;
}

// Trim any whitespace/newlines
$token = trim(str_replace("Bearer", "", $token));

// --- Validate token in DB ---
$stmt = $pdo->prepare("
    SELECT user_id 
    FROM sessions 
    WHERE token = ? 
      AND (expires_at IS NULL OR expires_at > NOW())
    LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(["status" => "error", "message" => "Invalid token"]);
    exit;
}

// Current logged-in user
$current_user_id = $user['user_id'];

// --- Routing ---
$path = $_GET['path'] ?? null;
if (!$path) {
    echo json_encode(["status" => "error", "message" => "Missing path"]);
    exit;
}

// Split path into folder/file
$parts = explode("/", $path);
$folder = $parts[0] ?? "";
$file = $parts[1] ?? "";

$targetFile = __DIR__ . "/$folder/$file.php";

if (file_exists($targetFile)) {
    // $current_user_id will be available to included files
    require_once($targetFile);
} else {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Route not found: $path"]);
    exit;
}
