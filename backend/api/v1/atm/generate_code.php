<?php
// --------------------------------------------------
// generate_code.php
// SAT Instrument Generator for SACCUSSALIS
// ALIGNED with SwapService expectations
// WITH CERTIFICATE-BASED VERIFICATION
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../../db.php';
require_once '../../../helpers/crypto.php';
require_once '../../../helpers/CertificateManager.php';

function generateSAT(PDO $pdo, array $payload, string $requester, bool $signatureVerified, string $verificationMethod): array
{
    try {
        error_log("SACCUSSALIS generate_code.php received from: {$requester}");
        error_log("Verification method: {$verificationMethod}, valid: " . ($signatureVerified ? 'YES' : 'NO'));
        error_log("Payload: " . json_encode($payload));
        
        $pdo->beginTransaction();

        // 1️⃣ Validate Input
        $phone = trim($payload['beneficiary_phone'] ?? '');
        $amount = floatval($payload['amount'] ?? 0);
        $reference = trim($payload['reference'] ?? '');
        $sourceInstitution = trim($payload['source_institution'] ?? '');
        $sourceHoldReference = trim($payload['hold_reference'] ?? '');
        $sourceAssetType = trim($payload['source_asset_type'] ?? '');
        $codeHash = trim($payload['code_hash'] ?? '');
        $idempotencyKey = $payload['idempotency_key'] ?? $payload['idempotencyKey'] ?? null;
        
        $acquirerNetwork = $sourceInstitution ?: 'ZURUBANK';

        if ($phone === '' || $amount <= 0) {
            error_log("SACCUSSALIS: Invalid phone or amount");
            throw new Exception("Invalid beneficiary_phone or amount");
        }

        // Check idempotency
        if ($idempotencyKey) {
            $checkStmt = $pdo->prepare("
                SELECT sat_id FROM sat_tokens 
                WHERE code_hash = :code_hash OR reference = :reference
                LIMIT 1
            ");
            $checkStmt->execute([
                ':code_hash' => $codeHash,
                ':reference' => $reference
            ]);
            if ($checkStmt->fetch()) {
                error_log("SACCUSSALIS: Duplicate SAT request prevented");
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'Duplicate request - SAT already generated'
                ];
            }
        }

        // 2️⃣ Normalize phone
        $normalizedPhone = ltrim($phone, '+');
        error_log("SACCUSSALIS: Normalized phone: $normalizedPhone");

        // 3️⃣ Find or create user/wallet
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE phone = :phone OR phone = :phone_with_plus");
        $stmt->execute([
            ':phone' => $normalizedPhone,
            ':phone_with_plus' => $phone
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $userId = null;
        if ($user) {
            $userId = $user['user_id'];
            error_log("SACCUSSALIS: Found existing user: $userId");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at)
                VALUES (:full_name, :email, :phone, :password, 'customer', 'active', NOW())
                RETURNING user_id
            ");
            $stmt->execute([
                ':full_name' => 'ATM Customer',
                ':email' => 'atm_' . uniqid() . '@example.com',
                ':phone' => $normalizedPhone,
                ':password' => password_hash(uniqid(), PASSWORD_DEFAULT)
            ]);
            $userId = $stmt->fetchColumn();
            error_log("SACCUSSALIS: Created new user: $userId");
        }

        // Check if user has a wallet
        $stmt = $pdo->prepare("SELECT wallet_id FROM wallets WHERE user_id = :user_id AND status = 'active'");
        $stmt->execute([':user_id' => $userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $walletId = null;
        if ($wallet) {
            $walletId = $wallet['wallet_id'];
            error_log("SACCUSSALIS: Found existing wallet: $walletId");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO wallets (user_id, phone, wallet_type, currency, balance, status, created_at)
                VALUES (:user_id, :phone, 'EWALLET', 'BWP', 0, 'active', NOW())
                RETURNING wallet_id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':phone' => $normalizedPhone
            ]);
            $walletId = $stmt->fetchColumn();
            error_log("SACCUSSALIS: Created new wallet: $walletId");
        }

        // 4️⃣ Expiry (24 hours)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // 5️⃣ Create Cash Instrument with requester info
        $stmt = $pdo->prepare("
            INSERT INTO cash_instruments (
                phone,
                instrument_type,
                reserved_amount,
                status,
                wallet_id,
                requester,
                signature_,
                verification_method,
                created_at,
                updated_at
            )
            VALUES (
                :phone,
                'VOUCHER',
                :amount,
                'ACTIVE',
                :wallet_id,
                :requester,
                :sig_,
                :verification_method,
                NOW(),
                NOW()
            )
            RETURNING instrument_id
        ");

        $stmt->execute([
            ':phone' => $normalizedPhone,
            ':amount' => $amount,
            ':wallet_id' => $walletId,
            ':requester' => $requester,
            ':sig_verified' => $signatureVerified ? 1 : 0,
            ':verification_method' => $verificationMethod
        ]);

        $instrumentId = $stmt->fetchColumn();

        if (!$instrumentId) {
            throw new Exception("Failed to create cash instrument");
        }

        // 6️⃣ Generate SAT Number + PIN
        $satNumber = str_pad(random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $pin       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 7️⃣ Insert SAT Token with requester info
        $stmt = $pdo->prepare("
            INSERT INTO sat_tokens (
                instrument_id,
                sat_number,
                pin,
                issuer_bank,
                acquirer_network,
                amount,
                expires_at,
                status,
                processing,
                code_hash,
                requester,
                signature_verified,
                verification_method,
                created_at,
                updated_at
            )
            VALUES (
                :instrument_id,
                :sat_number,
                :pin,
                'SACCUS',
                :acquirer_network,
                :amount,
                :expires_at,
                'ACTIVE',
                FALSE,
                :code_hash,
                :requester,
                :sig_verified,
                :verification_method,
                NOW(),
                NOW()
            )
            RETURNING sat_id
        ");

        $stmt->execute([
            ':instrument_id' => $instrumentId,
            ':sat_number'    => $satNumber,
            ':pin'           => $pin,
            ':acquirer_network' => $acquirerNetwork,
            ':amount'        => $amount,
            ':expires_at'    => $expiresAt,
            ':code_hash'     => $codeHash,
            ':requester'     => $requester,
            ':sig_verified'  => $signatureVerified ? 1 : 0,
            ':verification_method' => $verificationMethod
        ]);

        $satId = $stmt->fetchColumn();

        // 8️⃣ Log the transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                user_id,
                reference,
                amount,
                type,
                status,
                requester,
                signature_verified,
                verification_method,
                notes,
                created_at,
                updated_at
            )
            VALUES (
                :user_id,
                :reference,
                :amount,
                'ATM_TOKEN_GENERATION',
                'COMPLETED',
                :requester,
                :sig_verified,
                :verification_method,
                :notes,
                NOW(),
                NOW()
            )
        ");
        
        $notes = json_encode([
            'sat_id' => $satId,
            'instrument_id' => $instrumentId,
            'source_institution' => $sourceInstitution,
            'source_hold_reference' => $sourceHoldReference,
            'source_asset_type' => $sourceAssetType,
            'code_hash' => $codeHash,
            'verification_method' => $verificationMethod
        ]);
        
        $stmt->execute([
            ':user_id' => $userId,
            ':reference' => $reference ?: $satNumber,
            ':amount' => $amount,
            ':requester' => $requester,
            ':sig_verified' => $signatureVerified ? 1 : 0,
            ':verification_method' => $verificationMethod,
            ':notes' => $notes
        ]);

        $pdo->commit();

        error_log("SACCUSSALIS SAT: Generated SAT {$satNumber} for amount {$amount}, Instrument ID: {$instrumentId}");

        // Return format expected by GenericBankClient/SwapService
        return [
            'success' => true,
            'token_generated' => true,
            'sat_number' => $satNumber,
            'pin' => $pin,
            'atm_pin' => $pin,
            'token_reference' => $satNumber,
            'instrument_id' => $instrumentId,
            'sat_id' => $satId,
            'amount' => $amount,
            'currency' => 'BWP',
            'issuer_bank' => 'SACCUS',
            'acquirer_network' => $acquirerNetwork,
            'expires_at' => $expiresAt,
            'expiry' => $expiresAt,
            'requester' => $requester,
            'signature_verified' => $signatureVerified,
            'verification_method' => $verificationMethod,
            'metadata' => [
                'wallet_id' => $walletId,
                'user_id' => $userId,
                'reference' => $reference,
                'source_institution' => $sourceInstitution,
                'source_hold_reference' => $sourceHoldReference
            ]
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("SACCUSSALIS SAT Generation Error: " . $e->getMessage());
        return [
            'success' => false,
            'token_generated' => false,
            'error' => $e->getMessage()
        ];
    }
}

// -------------------------
// Endpoint Execution
// -------------------------

$payload = json_decode(file_get_contents('php://input'), true);

if (!$payload) {
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => 'Invalid JSON payload',
        'timestamp' => time()
    ]);
    exit;
}

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($payload['certificate'])) {
    error_log("SACCUSSALIS SAT: No certificate provided");
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('SACCUSSALIS');
$verification = $certManager->verifySignedRequest($payload);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("SACCUSSALIS SAT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("SACCUSSALIS SAT: Requester: {$requester}");

if (!$isValid) {
    error_log("SACCUSSALIS SAT: Certificate verification failed");
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("SACCUSSALIS SAT: Request verified from {$requester} using certificate");

// Execute generation with verified requester
$result = generateSAT($pdo, $payload, $requester, $isValid, 'certificate');

// ============================================================
// SEND SIGNED RESPONSE WITH CERTIFICATE
// ============================================================
if ($result['success']) {
    $responsePayload = [
        'success' => true,
        'token_generated' => true,
        'sat_number' => $result['sat_number'],
        'pin' => $result['pin'],
        'atm_pin' => $result['pin'],
        'amount' => $result['amount'],
        'currency' => $result['currency'],
        'expires_at' => $result['expires_at'],
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'timestamp' => time()
    ];
    send_signed_response($responsePayload);
} else {
    echo json_encode($result);
}
