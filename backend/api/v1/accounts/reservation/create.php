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
require_once __DIR__ . '/../../../../helpers/virtual_accounts.php';
require_once __DIR__ . '/../../../../helpers/crypto.php';

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

// ============================================================
// Identity virtual account (VouchMorph identity reservation account).
// Input: bank_reference (idempotency key), currency, and the identity
// (identity_type + identity_value). A legacy request carrying only a
// VouchMorph user_id is keyed as identity "vouchmorph_user".
// One virtual account per identity per currency: a repeat request for the
// same identity returns the same account.
// ============================================================
$bankReference = $input['bank_reference'] ?? $input['reference'] ?? null;
$reference = $input['reference'] ?? $bankReference;
$currency = strtoupper($input['currency'] ?? 'BWP');
$identityType = $input['identity_type'] ?? null;
$identityValue = $input['identity_value'] ?? null;
if ((!$identityType || !$identityValue) && !empty($input['user_id'])) {
    $identityType = 'vouchmorph_user';
    $identityValue = (string)$input['user_id'];
}
if (!$bankReference || !$identityType || $identityValue === null || $identityValue === '') {
    echo json_encode(['success' => false, 'message' => 'bank_reference and identity_type + identity_value are required']);
    exit;
}

try {
    va_ensure_schema($pdo);

    $st = $pdo->prepare("SELECT * FROM reservation_accounts WHERE bank_reference = ? LIMIT 1");
    $st->execute([$bankReference]);
    if ($existing = $st->fetch(PDO::FETCH_ASSOC)) {
        send_signed_response([
            'success' => true, 'status' => $existing['status'] ?? 'active',
            'account_identifier' => $existing['account_number'] ?? $existing['account_identifier'],
            'account_identifier_type' => 'account_number', 'virtual_account' => true,
            'message' => 'Already processed for this bank_reference',
        ]);
        exit;
    }

    $va = va_open_or_get($pdo, (string)$identityType, (string)$identityValue, $currency, (string)($requester ?? 'VOUCHMORPH'));

    $pdo->prepare("
        INSERT INTO reservation_accounts (bank_reference, reference, user_id, identity_type, identity_value, account_id, account_number,
                                          account_identifier, account_identifier_type, currency, status, requester, signature_verified)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'account_number', ?, 'active', ?, ?)
        ON CONFLICT (bank_reference) DO NOTHING
    ")->execute([
        $bankReference, $reference, is_numeric($input['user_id'] ?? null) ? (int)$input['user_id'] : null,
        strtolower((string)$identityType), (string)$identityValue, $va['account_id'], $va['account_number'], $va['account_number'],
        $currency, (string)($requester ?? 'VOUCHMORPH'), !empty($isValid) ? 'true' : 'false',
    ]);

    error_log("RESERVATION CREATE: identity {$identityType}={$identityValue} {$currency} -> virtual account {$va['account_number']} (" . ($va['opened'] ? 'opened' : 'existing') . ")");
    send_signed_response([
        'success' => true,
        'status' => 'active',
        'account_identifier' => $va['account_number'],
        'account_identifier_type' => 'account_number',
        'virtual_account' => true,
        'opened' => $va['opened'],
        'message' => $va['opened'] ? 'Identity virtual account opened' : 'Identity virtual account already open',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('RESERVATION CREATE ERROR: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
