<?php
/**
 * status.php - SACCUSSALIS VERSION
 * Looks up a reservation account by bank_reference.
 */

require_once __DIR__ . '/../../../../db.php';

header("Content-Type: application/json");

error_log("=== reservation/status.php CALLED ===");
error_log("RAW POST: " . file_get_contents("php://input"));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
error_log("Parsed input: " . json_encode($input));

if (!$input) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON input"
    ]);
    exit;
}

$bankReference = $input['bank_reference'] ?? $input['reference'] ?? null;

if (empty($bankReference)) {
    echo json_encode([
        "success" => false,
        "message" => "Missing required field: bank_reference"
    ]);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed to initialize.");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reservation_accounts (
            id SERIAL PRIMARY KEY,
            bank_reference VARCHAR(128) UNIQUE NOT NULL,
            reference VARCHAR(128),
            user_id INT NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'BWP',
            account_identifier VARCHAR(64) UNIQUE NOT NULL,
            account_identifier_type VARCHAR(32) NOT NULL DEFAULT 'account_number',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            requester VARCHAR(50),
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $stmt = $pdo->prepare("
        SELECT bank_reference, status, account_identifier, account_identifier_type
        FROM reservation_accounts
        WHERE bank_reference = :bank_reference
        LIMIT 1
    ");
    $stmt->execute([':bank_reference' => $bankReference]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        error_log("reservation/status: No reservation account found for bank_reference={$bankReference}");
        echo json_encode([
            "success" => false,
            "message" => "Reservation account not found for bank_reference: {$bankReference}"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "status" => $account['status'],
        "account_identifier" => $account['account_identifier'],
        "account_identifier_type" => $account['account_identifier_type'],
        "message" => "Reservation account status retrieved"
    ]);

} catch (PDOException $e) {
    error_log("reservation/status PDO ERROR: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("reservation/status ERROR: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
