<?php
/**
 * sweep.php - SACCUSSALIS VERSION (SANDBOX SCAFFOLDING)
 *
 * Consolidates every RECEIVED leg on a claim into the claim's HOLDING
 * balance, moving the matching amount from the internal
 * IDENTITY_CLAIMS_RECEIVING ledger account to IDENTITY_CLAIMS_HOLDING.
 * This is what turns several separate inbound legs into one balance
 * the recipient can draw against in payout.php.
 *
 * Safe to call repeatedly / idempotent in effect: a claim with no
 * pending RECEIVED legs just reports nothing to sweep.
 */

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

header("Content-Type: application/json");

error_log("=== identity_claims/sweep.php CALLED ===");
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

$claimReference = $input['claim_reference'] ?? null;
if (empty($claimReference)) {
    echo json_encode(["success" => false, "message" => "Missing required field: claim_reference"]);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed to initialize.");
    }

    // Tables are self-provisioned by receive.php; if sweep is called
    // before any leg was ever received these simply won't exist yet.
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

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM identity_claims WHERE claim_reference = :ref LIMIT 1 FOR UPDATE");
    $stmt->execute([':ref' => $claimReference]);
    $claim = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$claim) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Claim not found: {$claimReference}"]);
        exit;
    }

    if ($claim['status'] !== 'OPEN') {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Claim {$claimReference} is not open (status: {$claim['status']})"]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, amount FROM identity_claim_legs
        WHERE claim_reference = :ref AND status = 'RECEIVED'
        FOR UPDATE
    ");
    $stmt->execute([':ref' => $claimReference]);
    $pendingLegs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sweepTotal = 0.0;
    foreach ($pendingLegs as $leg) {
        $sweepTotal += (float)$leg['amount'];
    }

    if ($sweepTotal <= 0) {
        $pdo->rollBack();
        echo json_encode([
            "success" => true,
            "claim_reference" => $claimReference,
            "swept_amount" => 0,
            "received_total" => (float)$claim['received_total'],
            "held_total" => (float)$claim['held_total'],
            "message" => "Nothing to sweep - no pending received legs"
        ]);
        exit;
    }

    // Internal transfer: RECEIVING -> HOLDING
    $stmt = $pdo->prepare("
        UPDATE settlement_accounts
        SET balance = balance - :amount, updated_at = NOW()
        WHERE account_name = 'IDENTITY_CLAIMS_RECEIVING' AND balance >= :amount2
        RETURNING balance
    ");
    $stmt->execute([':amount' => $sweepTotal, ':amount2' => $sweepTotal]);
    $receiving = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receiving) {
        throw new Exception("IDENTITY_CLAIMS_RECEIVING has insufficient balance to sweep {$sweepTotal}");
    }

    $stmt = $pdo->prepare("
        UPDATE settlement_accounts
        SET balance = balance + :amount, updated_at = NOW()
        WHERE account_name = 'IDENTITY_CLAIMS_HOLDING'
        RETURNING balance
    ");
    $stmt->execute([':amount' => $sweepTotal]);
    $holding = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$holding) {
        throw new Exception("IDENTITY_CLAIMS_HOLDING settlement account not found");
    }

    $legIds = array_column($pendingLegs, 'id');
    $placeholders = implode(',', array_fill(0, count($legIds), '?'));
    $stmt = $pdo->prepare("UPDATE identity_claim_legs SET status = 'SWEPT', swept_at = NOW() WHERE id IN ({$placeholders})");
    $stmt->execute($legIds);

    $stmt = $pdo->prepare("
        UPDATE identity_claims
        SET received_total = received_total - :amount,
            held_total = held_total + :amount2,
            updated_at = NOW()
        WHERE claim_reference = :claim_reference
        RETURNING received_total, held_total
    ");
    $stmt->execute([':amount' => $sweepTotal, ':amount2' => $sweepTotal, ':claim_reference' => $claimReference]);
    $updatedClaim = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    error_log("identity_claims/sweep: Swept {$sweepTotal} for claim {$claimReference} across " . count($pendingLegs) . " leg(s). Receiving={$receiving['balance']}, Holding={$holding['balance']}");

    echo json_encode([
        "success" => true,
        "claim_reference" => $claimReference,
        "swept_amount" => $sweepTotal,
        "legs_swept" => count($pendingLegs),
        "received_total" => (float)$updatedClaim['received_total'],
        "held_total" => (float)$updatedClaim['held_total'],
        "message" => "Swept received legs into holding"
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("identity_claims/sweep PDO ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("identity_claims/sweep ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
