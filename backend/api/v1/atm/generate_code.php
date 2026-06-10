<?php
// --------------------------------------------------
// generate_code.php
// SAT Instrument Generator for SACCUSSALIS
// ALIGNED with SwapService expectations
// WITH SIGNATURE VERIFICATION
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../../db.php';
require_once '../../../helpers/crypto.php';

function generateSAT(PDO $pdo, array $payload, string $requester, bool $signatureVerified): array
{
    try {
        error_log("SACCUSSALIS generate_code.php received from: {$requester}");
        error_log("Signature verified: " . ($signatureVerified ? 'YES' : 'NO'));
        error_log("Payload: " . json_encode($payload));
        
        $pdo->beginTransaction();

        // 1️⃣ Validate Input
        $phone = trim($payload['beneficiary_phone'] ?? '');
        $amount = floatval($payload['amount'] ?? 0);
        $reference = trim($payload['reference'] ?? '');
        $sourceInstitution = trim($payload['source_institution'] ?? '');
        $sourceHoldReference = trim($payload['source_hold_reference'] ?? '');
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
                signature_verified,
                created_at
            )
            VALUES (
                :phone,
                'VOUCHER',
                :amount,
                'ACTIVE',
                :wallet_id,
                :requester,
                :sig_verified,
                NOW()
            )
            RETURNING instrument_id
        ");

        $stmt->execute([
            ':phone' => $normalizedPhone,
            ':amount' => $amount,
            ':wallet_id' => $walletId,
            ':requester' => $requester,
            ':sig_verified' => $signatureVerified ? 1 : 0
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
                created_at
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
            ':sig_verified'  => $signatureVerified ? 1 : 0
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
                notes,
                created_at
            )
            VALUES (
                :user_id,
                :reference,
                :amount,
                'ATM_TOKEN_GENERATION',
                'COMPLETED',
                :requester,
                :sig_verified,
                :notes,
                NOW()
            )
        ");
        
        $notes = json_encode([
            'sat_id' => $satId,
            'instrument_id' => $instrumentId,
            'source_institution' => $sourceInstitution,
            'source_hold_reference' => $sourceHoldReference,
            'source_asset_type' => $sourceAssetType,
            'code_hash' => $codeHash
        ]);
        
        $stmt->execute([
            ':user_id' => $userId,
            ':reference' => $reference ?: $satNumber,
            ':amount' => $amount,
            ':requester' => $requester,
            ':sig_verified' => $signatureVerified ? 1 : 0,
            ':notes' => $notes
        ]);

        $pdo->commit();

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
// VERIFY INCOMING SIGNATURE
// ============================================================
$signature = $payload['signature'] ?? null;
$timestamp = $payload['timestamp'] ?? null;
$requester = $payload['requester'] ?? 'VOUCHMORPH';

$payloadToVerify = [
    'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
    'amount' => $payload['amount'] ?? null,
    'reference' => $payload['reference'] ?? null,
    'source_institution' => $payload['source_institution'] ?? null
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("SACCUSSALIS SAT: Missing signature from {$requester}");
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => 'Missing signature - SAT generation must be signed'
    ]);
    exit;
}

$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("SACCUSSALIS SAT: No public key for requester: {$requester}");
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => "No public key found for requester: {$requester}"
    ]);
    exit;
}

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("SACCUSSALIS SAT: Invalid signature from {$requester}");
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => 'Invalid signature - SAT generation cannot be trusted'
    ]);
    exit;
}

error_log("SACCUSSALIS SAT: Signature verified from {$requester}");

// Execute generation with verified requester
$result = generateSAT($pdo, $payload, $requester, $isValid);

// ============================================================
// SEND SIGNED RESPONSE
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
        'signature_verified' => $isValid
    ];
    send_signed_response($responsePayload);
} else {
    echo json_encode($result);
}
