$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_GET['token'] ?? null);
$token = trim($token); // remove whitespace or newline

// Hardcoded valid token for testing
$valid_token = "ab95fa1a6a4c93aa84add0a1397dfdd1b567f157d25a155d538e073b892f0432";

if (!$token) {
    echo json_encode(["status" => "error", "message" => "Token required"]);
    exit;
}

if ($token !== $valid_token) {
    echo json_encode(["status" => "error", "message" => "Invalid token"]);
    exit;
}
