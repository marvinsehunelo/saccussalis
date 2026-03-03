<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../wallet/EwalletController.php";

// Always validate session or token
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_POST['token'] ?? null);

// --- Simple Token Verification ---
function verifyToken($db, $token) {
    if (!$token) return false;
    $stmt = $db->prepare("SELECT user_id FROM sessions WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC)['user_id'] ?? false;
}

// --- Parse Path & Request ---
$path = $_GET['path'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$controller = new EwalletController($pdo);

// --- Route Handling ---
try {
    switch ($path) {
        case 'ewallet/generate':
            if ($method !== 'POST') throw new Exception("POST required");
            $input = json_decode(file_get_contents("php://input"), true);
            $transaction_id = $input['transaction_id'] ?? null;
            if (!$transaction_id) throw new Exception("Missing transaction_id");

            $user_id = verifyToken($pdo, $token);
            if (!$user_id) {
                echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
                exit;
            }

            $_SESSION['user_id'] = $user_id; // for controller
            $response = $controller->generate($transaction_id);
            echo json_encode($response);
            break;

        case 'ewallet/redeem':
            if ($method !== 'POST') throw new Exception("POST required");
            $input = json_decode(file_get_contents("php://input"), true);
            $pin = $input['pin'] ?? null;
            if (!$pin) throw new Exception("Missing PIN");

            $response = $controller->redeem($pin);
            echo json_encode($response);
            break;

        case 'ewallet/regenerate':
            if ($method !== 'POST') throw new Exception("POST required");
            $input = json_decode(file_get_contents("php://input"), true);
            $old_pin = $input['old_pin'] ?? null;
            if (!$old_pin) throw new Exception("Missing old PIN");

            $user_id = verifyToken($pdo, $token);
            if (!$user_id) {
                echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
                exit;
            }

            $_SESSION['user_id'] = $user_id;
            $response = $controller->regenerate($old_pin);
            echo json_encode($response);
            break;

        default:
            echo json_encode(["status" => "error", "message" => "Invalid path"]);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
