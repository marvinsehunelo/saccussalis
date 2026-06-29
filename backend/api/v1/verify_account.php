<?php
/**
 * verify_account.php - SACCUSSALIS VERSION
 * Simple account verification - checks if account exists in accounts table
 */

require_once __DIR__ . '/../../db.php';

header("Content-Type: application/json");

error_log("=== verify_account.php CALLED ===");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "Method not allowed"
    ]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
error_log("verify_account input: " . json_encode($input));

if (!$input) {
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "Invalid JSON input"
    ]);
    exit;
}

// Get account identifier from various possible locations
$identifier = $input['account_identifier'] ?? 
              $input['destination_identifier'] ?? 
              $input['identifier'] ?? 
              $input['account_number'] ?? 
              null;

if (!$identifier) {
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "Account identifier required"
    ]);
    exit;
}

error_log("Checking account: {$identifier}");

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed");
    }

    // Check if account exists in accounts table
    $stmt = $pdo->prepare("
        SELECT 
            account_id,
            account_number,
            account_type,
            currency,
            balance,
            is_frozen
        FROM accounts 
        WHERE account_number = :identifier
        LIMIT 1
    ");
    $stmt->execute(['identifier' => $identifier]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        error_log("Account not found: {$identifier}");
        echo json_encode([
            "success" => false,
            "verified" => false,
            "message" => "Account not found"
        ]);
        exit;
    }

    error_log("Account found: " . $account['account_number']);

    // Check if account is frozen
    if ($account['is_frozen'] == true) {
        echo json_encode([
            "success" => false,
            "verified" => false,
            "message" => "Account is frozen"
        ]);
        exit;
    }

    // Account exists and is valid
    echo json_encode([
        "success" => true,
        "verified" => true,
        "account_id" => $account['account_id'],
        "account_number" => $account['account_number'],
        "account_type" => $account['account_type'] ?? 'checking',
        "currency" => $account['currency'] ?? 'BWP',
        "balance" => (float)$account['balance'],
        "is_frozen" => $account['is_frozen'],
        "message" => "Account verified successfully"
    ]);

} catch (Exception $e) {
    error_log("verify_account error: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => $e->getMessage()
    ]);
}
