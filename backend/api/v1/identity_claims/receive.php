<?php
/**
 * receive.php - SACCUSSALIS VERSION (SANDBOX SCAFFOLDING)
 *
 * Records one inbound interbank settlement leg for an identity claim
 * where the final destination (deposit account vs. cashout, which
 * institution) isn't known yet - the recipient decides that later,
 * possibly after several separate legs from different source
 * institutions have already arrived.
 *
 * Each leg lands against the claim's RECEIVING balance here. A
 * separate sweep.php call consolidates received legs into the
 * claim's HOLDING balance, and payout.php disburses the holding
 * balance once the recipient claims it.
 *
 * NOTE: the two internal ledger accounts this touches
 * (IDENTITY_CLAIMS_RECEIVING / IDENTITY_CLAIMS_HOLDING) are
 * SACCUSSALIS-internal bookkeeping only, the same way MAIN_SETTLEMENT
 * already is. They are not account numbers meant to be published to
 * an external participant config - that's a separate decision.
 */

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

header("Content-Type: application/json");

error_log("=== identity_claims/receive.php CALLED ===");
error_log("RAW POST: " . file_get_contents("php://input"));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
    exit;
}

$requester = $input['requester'] ?? 'VOUCHMORPH';
if (isset($input['certificate']) && !empty($input['certificate'])) {
    try {
        if (class_exists('CertificateManager')) {
            $certManager = new CertificateManager('SACCUSSALIS');
            $verification = $certManager->verifySignedRequest($input);
            if ($verification['verified']) {
                $requester = $verification['requester'] ?? $requester;
                error_log("identity_claims/receive: Verified from {$requester}");
            } else {
                error_log("identity_claims/receive: Certificate verification FAILED: " . ($verification['message'] ?? 'Unknown error'));
            }
        }
    } catch (Exception $e) {
        error_log("identity_claims/receive: Certificate verification exception: " . $e->getMessage());
    }
}

$claimReference = $input['claim_reference'] ?? null;
$legReference = $input['leg_reference'] ?? null;
$beneficiaryIdentifier = $input['beneficiary_identifier'] ?? $input['phone'] ?? null;
$beneficiaryIdentifierType = $input['beneficiary_identifier_type'] ?? 'phone';
$amount = floatval($input['amount'] ?? 0);
$currency = strtoupper($input['currency'] ?? 'BWP');
$sourceInstitution = $input['source_institution'] ?? $input['from_institution'] ?? null;

if (empty($claimReference) || empty($legReference) || empty($beneficiaryIdentifier) || $amount <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Missing required fields: claim_reference, leg_reference, beneficiary_identifier, amount (> 0)"
    ]);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed to initialize.");
    }

    bootstrap_identity_claims_schema($pdo);

    // ============================================================
    // IDEMPOTENCY: a repeat call with the same leg_reference is a
    // no-op that returns the already-recorded leg.
    // ============================================================
    $stmt = $pdo->prepare("SELECT * FROM identity_claim_legs WHERE leg_reference = :leg_reference LIMIT 1");
    $stmt->execute([':leg_reference' => $legReference]);
    $existingLeg = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingLeg) {
        $claimStmt = $pdo->prepare("SELECT received_total, held_total, status FROM identity_claims WHERE claim_reference = :ref LIMIT 1");
        $claimStmt->execute([':ref' => $existingLeg['claim_reference']]);
        $claim = $claimStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "claim_reference" => $existingLeg['claim_reference'],
            "leg_reference" => $legReference,
            "status" => "already_received",
            "received_total" => (float)($claim['received_total'] ?? 0),
            "held_total" => (float)($claim['held_total'] ?? 0),
            "message" => "Leg already recorded (idempotent replay)"
        ]);
        exit;
    }

    $pdo->beginTransaction();

    // Find or create the claim
    $stmt = $pdo->prepare("SELECT * FROM identity_claims WHERE claim_reference = :ref LIMIT 1 FOR UPDATE");
    $stmt->execute([':ref' => $claimReference]);
    $claim = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$claim) {
        $stmt = $pdo->prepare("
            INSERT INTO identity_claims
                (claim_reference, beneficiary_identifier, beneficiary_identifier_type, currency, received_total, held_total, status, created_at, updated_at)
            VALUES
                (:claim_reference, :beneficiary_identifier, :beneficiary_identifier_type, :currency, 0, 0, 'OPEN', NOW(), NOW())
        ");
        $stmt->execute([
            ':claim_reference' => $claimReference,
            ':beneficiary_identifier' => $beneficiaryIdentifier,
            ':beneficiary_identifier_type' => $beneficiaryIdentifierType,
            ':currency' => $currency
        ]);
    } elseif ($claim['status'] !== 'OPEN') {
        $pdo->rollBack();
        echo json_encode([
            "success" => false,
            "message" => "Claim {$claimReference} is not open (status: {$claim['status']})"
        ]);
        exit;
    }

    // Credit the internal RECEIVING ledger account
    $stmt = $pdo->prepare("
        UPDATE settlement_accounts
        SET balance = balance + :amount, updated_at = NOW()
        WHERE account_name = 'IDENTITY_CLAIMS_RECEIVING'
        RETURNING balance
    ");
    $stmt->execute([':amount' => $amount]);
    $receiving = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receiving) {
        throw new Exception("IDENTITY_CLAIMS_RECEIVING settlement account not found");
    }

    // Record the leg
    $stmt = $pdo->prepare("
        INSERT INTO identity_claim_legs
            (claim_reference, leg_reference, source_institution, amount, status, received_at)
        VALUES
            (:claim_reference, :leg_reference, :source_institution, :amount, 'RECEIVED', NOW())
    ");
    $stmt->execute([
        ':claim_reference' => $claimReference,
        ':leg_reference' => $legReference,
        ':source_institution' => $sourceInstitution,
        ':amount' => $amount
    ]);

    // Update claim totals
    $stmt = $pdo->prepare("
        UPDATE identity_claims
        SET received_total = received_total + :amount, updated_at = NOW()
        WHERE claim_reference = :claim_reference
        RETURNING received_total, held_total
    ");
    $stmt->execute([':amount' => $amount, ':claim_reference' => $claimReference]);
    $updatedClaim = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    error_log("identity_claims/receive: Recorded leg {$legReference} for claim {$claimReference}, amount={$amount}, receiving_balance={$receiving['balance']}");

    echo json_encode([
        "success" => true,
        "claim_reference" => $claimReference,
        "leg_reference" => $legReference,
        "status" => "received",
        "received_total" => (float)$updatedClaim['received_total'],
        "held_total" => (float)$updatedClaim['held_total'],
        "message" => "Leg received and recorded against claim"
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("identity_claims/receive PDO ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("identity_claims/receive ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

/**
 * Self-provisions the tables and the two internal ledger accounts
 * this feature needs, the same way reservation_accounts.php does -
 * safe to call on every request via IF NOT EXISTS / ON CONFLICT.
 */
function bootstrap_identity_claims_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settlement_accounts (
            settlement_account_id SERIAL PRIMARY KEY,
            account_name VARCHAR(50) UNIQUE NOT NULL,
            account_type VARCHAR(50) NOT NULL DEFAULT 'operational',
            account_number VARCHAR(20) UNIQUE NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'BWP',
            balance NUMERIC(18,4) NOT NULL DEFAULT 0,
            is_frozen BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $pdo->exec("
        INSERT INTO settlement_accounts (account_name, account_type, account_number, balance)
        VALUES ('IDENTITY_CLAIMS_RECEIVING', 'operational', '10000002', 0)
        ON CONFLICT (account_number) DO NOTHING
    ");
    $pdo->exec("
        INSERT INTO settlement_accounts (account_name, account_type, account_number, balance)
        VALUES ('IDENTITY_CLAIMS_HOLDING', 'operational', '10000003', 0)
        ON CONFLICT (account_number) DO NOTHING
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS identity_claims (
            id SERIAL PRIMARY KEY,
            claim_reference VARCHAR(128) UNIQUE NOT NULL,
            beneficiary_identifier VARCHAR(128) NOT NULL,
            beneficiary_identifier_type VARCHAR(32) NOT NULL DEFAULT 'phone',
            currency CHAR(3) NOT NULL DEFAULT 'BWP',
            received_total DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
            held_total DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
            status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS identity_claim_legs (
            id SERIAL PRIMARY KEY,
            claim_reference VARCHAR(128) NOT NULL,
            leg_reference VARCHAR(128) UNIQUE NOT NULL,
            source_institution VARCHAR(50),
            amount DECIMAL(20,4) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'RECEIVED',
            received_at TIMESTAMP DEFAULT NOW(),
            swept_at TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS identity_claim_cashout_codes (
            id SERIAL PRIMARY KEY,
            claim_reference VARCHAR(128) NOT NULL,
            phone VARCHAR(32) NOT NULL,
            sat_code VARCHAR(40) UNIQUE NOT NULL,
            auth_code VARCHAR(12) NOT NULL,
            amount DECIMAL(20,4) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'BWP',
            status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
}
