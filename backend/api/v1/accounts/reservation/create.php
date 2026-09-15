<?php
/**
 * create.php - SACCUSSALIS VERSION
 * Creates a dedicated reservation account for a VouchMorph beneficiary
 * who hasn't linked a real account yet.
 *
 * Idempotent on bank_reference: VouchMorph retries with the SAME
 * bank_reference after a timeout/ambiguous response, so a repeat call
 * looks up the existing reservation account and returns it instead of
 * creating a second one.
 *
 * Synchronous only - always responds with status "active" or a failure.
 * (No async/pending path - see task notes for why.)
 */

require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../../../helpers/CertificateManager.php';

header("Content-Type: application/json");

error_log("=== reservation/create.php CALLED ===");
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
    error_log("reservation/create: Invalid JSON input");
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON input"
    ]);
    exit;
}

// ============================================================
// OPTIONAL CERTIFICATE VERIFICATION (soft - logged, not enforced)
// Matches hold.php's convention: verify if a certificate is present,
// but don't hard-fail the request if verification is unavailable.
// ============================================================
$requester = $input['requester'] ?? 'VOUCHMORPH';
if (isset($input['certificate']) && !empty($input['certificate'])) {
    try {
        if (class_exists('CertificateManager')) {
            $certManager = new CertificateManager('SACCUSSALIS');
            $verification = $certManager->verifySignedRequest($input);
            if (!$verification['verified']) {
                error_log("reservation/create: Certificate verification FAILED: " . ($verification['message'] ?? 'Unknown error'));
            } else {
                $requester = $verification['requester'] ?? $requester;
                error_log("reservation/create: Verified from {$requester}");
            }
        }
    } catch (Exception $e) {
        error_log("reservation/create: Certificate verification exception: " . $e->getMessage());
    }
}

$bankReference = $input['bank_reference'] ?? $input['reference'] ?? null;
$reference = $input['reference'] ?? $bankReference;
$userId = $input['user_id'] ?? null;
$currency = strtoupper($input['currency'] ?? 'BWP');

if (empty($bankReference)) {
    echo json_encode([
        "success" => false,
        "message" => "Missing required field: bank_reference"
    ]);
    exit;
}

if (empty($userId)) {
    echo json_encode([
        "success" => false,
        "message" => "Missing required field: user_id"
    ]);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed to initialize.");
    }

    // ============================================================
    // Self-provision the reservation_accounts table if it doesn't
    // exist yet (same pattern used elsewhere in this codebase for
    // integration-specific tables, e.g. vouchmorph_notifications).
    // ============================================================
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

    // ============================================================
    // IDEMPOTENCY: a repeat call with the same bank_reference
    // returns the existing reservation account instead of creating
    // a duplicate.
    // ============================================================
    $stmt = $pdo->prepare("
        SELECT bank_reference, status, account_identifier, account_identifier_type
        FROM reservation_accounts
        WHERE bank_reference = :bank_reference
        LIMIT 1
    ");
    $stmt->execute([':bank_reference' => $bankReference]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        error_log("reservation/create: Idempotent replay for bank_reference={$bankReference}, returning existing account {$existing['account_identifier']}");
        echo json_encode([
            "success" => true,
            "status" => $existing['status'],
            "account_identifier" => $existing['account_identifier'],
            "account_identifier_type" => $existing['account_identifier_type'],
            "message" => "Reservation account already exists for this bank_reference"
        ]);
        exit;
    }

    // ============================================================
    // Generate a plausible, unique account number for the
    // reservation account: RES + zero-padded user_id + random suffix.
    // Mirrors the SAV/CUR/WAL account-number convention already used
    // for real accounts in backend/auth/register.php.
    // ============================================================
    $accountIdentifier = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'RES' . str_pad((string)$userId, 8, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(2)));
        $checkStmt = $pdo->prepare("SELECT 1 FROM reservation_accounts WHERE account_identifier = :aid LIMIT 1");
        $checkStmt->execute([':aid' => $candidate]);
        if (!$checkStmt->fetchColumn()) {
            $accountIdentifier = $candidate;
            break;
        }
    }

    if (!$accountIdentifier) {
        throw new Exception("Failed to generate a unique account identifier");
    }

    $stmt = $pdo->prepare("
        INSERT INTO reservation_accounts
            (bank_reference, reference, user_id, currency, account_identifier, account_identifier_type, status, requester, created_at, updated_at)
        VALUES
            (:bank_reference, :reference, :user_id, :currency, :account_identifier, 'account_number', 'active', :requester, NOW(), NOW())
    ");
    $stmt->execute([
        ':bank_reference' => $bankReference,
        ':reference' => $reference,
        ':user_id' => $userId,
        ':currency' => $currency,
        ':account_identifier' => $accountIdentifier,
        ':requester' => $requester
    ]);

    error_log("reservation/create: Created reservation account {$accountIdentifier} for user_id={$userId}, bank_reference={$bankReference}");

    echo json_encode([
        "success" => true,
        "status" => "active",
        "account_identifier" => $accountIdentifier,
        "account_identifier_type" => "account_number",
        "message" => "Reservation account created"
    ]);

} catch (PDOException $e) {
    error_log("reservation/create PDO ERROR: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("reservation/create ERROR: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
