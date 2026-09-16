<?php
/**
 * payout.php - SACCUSSALIS VERSION (SANDBOX SCAFFOLDING)
 *
 * Disburses a claim's HOLDING balance once the recipient has decided
 * how to receive it:
 *   - payout_method "DEPOSIT": credits an existing account or wallet
 *     directly (destination_asset_type + destination_identifier).
 *     Does not auto-create the destination - it must already exist
 *     (e.g. via verify_account.php / credit_funds.php / the
 *     reservation-account endpoints) before payout is requested.
 *   - payout_method "CASHOUT": generates a claim-scoped SAT/auth code
 *     the recipient can redeem at an ATM, funded from HOLDING.
 *
 * Debits the internal IDENTITY_CLAIMS_HOLDING ledger account by the
 * claim's held_total and marks the claim DISBURSED.
 */

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

header("Content-Type: application/json");

error_log("=== identity_claims/payout.php CALLED ===");
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
$payoutMethod = strtoupper($input['payout_method'] ?? '');

if (empty($claimReference) || !in_array($payoutMethod, ['DEPOSIT', 'CASHOUT'], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Missing/invalid fields: claim_reference required, payout_method must be DEPOSIT or CASHOUT"
    ]);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed to initialize.");
    }

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

    $amount = (float)$claim['held_total'];
    if ($amount <= 0) {
        $pdo->rollBack();
        echo json_encode([
            "success" => false,
            "message" => "Claim {$claimReference} has no held balance to disburse (received_total: {$claim['received_total']}). Sweep pending legs first."
        ]);
        exit;
    }

    // Debit the internal HOLDING ledger account
    $stmt = $pdo->prepare("
        UPDATE settlement_accounts
        SET balance = balance - :amount, updated_at = NOW()
        WHERE account_name = 'IDENTITY_CLAIMS_HOLDING' AND balance >= :amount2
        RETURNING balance
    ");
    $stmt->execute([':amount' => $amount, ':amount2' => $amount]);
    $holding = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$holding) {
        throw new Exception("IDENTITY_CLAIMS_HOLDING has insufficient balance to disburse {$amount}");
    }

    $responsePayload = [];

    if ($payoutMethod === 'DEPOSIT') {
        $destinationAssetType = strtoupper($input['destination_asset_type'] ?? 'ACCOUNT');
        $destinationIdentifier = $input['destination_identifier'] ?? null;

        if (empty($destinationIdentifier)) {
            throw new Exception("destination_identifier required for DEPOSIT payout");
        }

        if ($destinationAssetType === 'ACCOUNT') {
            $stmt = $pdo->prepare("
                UPDATE accounts SET balance = balance + :amount, updated_at = NOW()
                WHERE account_number = :identifier AND is_frozen = false
                RETURNING account_id, balance
            ");
            $stmt->execute([':amount' => $amount, ':identifier' => $destinationIdentifier]);
            $destination = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$destination) {
                throw new Exception("Destination account not found or frozen: {$destinationIdentifier}. Create/link it first (e.g. via credit_funds.php or the reservation-account endpoints) before requesting payout.");
            }

            $responsePayload = [
                "destination_asset_type" => "ACCOUNT",
                "destination_identifier" => $destinationIdentifier,
                "new_balance" => (float)$destination['balance']
            ];
        } elseif ($destinationAssetType === 'WALLET') {
            $stmt = $pdo->prepare("
                UPDATE wallets SET balance = balance + :amount, updated_at = NOW()
                WHERE phone = :identifier AND status = 'active' AND is_frozen = false
                RETURNING wallet_id, balance
            ");
            $stmt->execute([':amount' => $amount, ':identifier' => $destinationIdentifier]);
            $destination = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$destination) {
                throw new Exception("Destination wallet not found, inactive, or frozen: {$destinationIdentifier}");
            }

            $responsePayload = [
                "destination_asset_type" => "WALLET",
                "destination_identifier" => $destinationIdentifier,
                "new_balance" => (float)$destination['balance']
            ];
        } else {
            throw new Exception("Unsupported destination_asset_type: {$destinationAssetType}. Supported: ACCOUNT, WALLET");
        }

    } else {
        // CASHOUT
        $phone = $input['phone'] ?? $claim['beneficiary_identifier'];
        if (empty($phone)) {
            throw new Exception("phone required for CASHOUT payout");
        }

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

        $satCode = bin2hex(random_bytes(4));
        $authCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = $pdo->prepare("
            INSERT INTO identity_claim_cashout_codes
                (claim_reference, phone, sat_code, auth_code, amount, currency, status, expires_at, created_at)
            VALUES
                (:claim_reference, :phone, :sat_code, :auth_code, :amount, :currency, 'ACTIVE', :expires_at, NOW())
        ");
        $stmt->execute([
            ':claim_reference' => $claimReference,
            ':phone' => $phone,
            ':sat_code' => $satCode,
            ':auth_code' => $authCode,
            ':amount' => $amount,
            ':currency' => $claim['currency'],
            ':expires_at' => $expiresAt
        ]);

        $responsePayload = [
            "sat_code" => $satCode,
            "auth_code" => $authCode,
            "phone" => $phone,
            "expires_at" => $expiresAt
        ];
    }

    $stmt = $pdo->prepare("
        UPDATE identity_claims
        SET held_total = 0, status = 'DISBURSED', updated_at = NOW()
        WHERE claim_reference = :claim_reference
    ");
    $stmt->execute([':claim_reference' => $claimReference]);

    $pdo->commit();

    error_log("identity_claims/payout: Disbursed claim {$claimReference} via {$payoutMethod}, amount={$amount}, holding_balance={$holding['balance']}");

    echo json_encode(array_merge([
        "success" => true,
        "claim_reference" => $claimReference,
        "payout_method" => $payoutMethod,
        "amount" => $amount,
        "currency" => $claim['currency'],
        "message" => "Claim disbursed successfully"
    ], $responsePayload));

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("identity_claims/payout PDO ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("identity_claims/payout ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
