<?php
// --------------------------------------------------
// generate_code.php
// SAT Instrument Generator for SACCUSSALIS
// Using existing tables: cash_instruments, sat_tokens
// Fixed: PIN is 6-digit, not hashed in this column
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../../db.php';

function generateSAT(PDO $pdo, array $payload): array
{
    try {
        // Log incoming payload for debugging
        error_log("SACCUSSALIS generate_code.php received: " . json_encode($payload));
        
        $pdo->beginTransaction();

        // 1️⃣ Validate Input - Map from SwapService payload
        $phone = trim($payload['beneficiary_phone'] ?? $payload['recipient_phone'] ?? '');
        $amount = floatval($payload['amount'] ?? 0);
        $reference = trim($payload['reference'] ?? '');
        $sourceInstitution = trim($payload['source_institution'] ?? '');
        $sourceHoldReference = trim($payload['source_hold_reference'] ?? '');
        $sourceAssetType = trim($payload['source_asset_type'] ?? '');
        
        // Map acquirer network from source_institution
        $acquirerNetwork = trim($payload['acquirer_network'] ?? $sourceInstitution ?: 'ZURUBANK');

        if ($phone === '' || $amount <= 0) {
            error_log("SACCUSSALIS: Invalid phone or amount - phone: '$phone', amount: $amount");
            throw new Exception("Invalid recipient_phone or amount");
        }

        // 2️⃣ Normalize phone (remove + if present for storage)
        $normalizedPhone = ltrim($phone, '+');
        error_log("SACCUSSALIS: Normalized phone: $normalizedPhone");

        // 3️⃣ Find or create user/wallet
        // First check if user exists with this phone
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
            // Create new user
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
            // Create new wallet
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

        // 5️⃣ Create Cash Instrument
        $stmt = $pdo->prepare("
            INSERT INTO cash_instruments (
                phone,
                instrument_type,
                reserved_amount,
                status,
                wallet_id,
                created_at
            )
            VALUES (
                :phone,
                'VOUCHER',
                :amount,
                'ACTIVE',
                :wallet_id,
                NOW()
            )
            RETURNING instrument_id
        ");

        $stmt->execute([
            ':phone' => $normalizedPhone,
            ':amount' => $amount,
            ':wallet_id' => $walletId
        ]);

        $instrumentId = $stmt->fetchColumn();

        if (!$instrumentId) {
            throw new Exception("Failed to create cash instrument");
        }

        // 6️⃣ Generate SAT Number (12-digit) + PIN (6-digit)
        $satNumber = str_pad(random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $pin       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // IMPORTANT: pin column is CHAR(6), so we store the plain 6-digit PIN
        // No hashing for this column - it's meant to store the actual PIN

        // 7️⃣ Insert SAT Token
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
                NOW()
            )
            RETURNING sat_id
        ");

        $stmt->execute([
            ':instrument_id' => $instrumentId,
            ':sat_number'    => $satNumber,
            ':pin'           => $pin, // Store plain 6-digit PIN, not hashed
            ':acquirer_network' => $acquirerNetwork,
            ':amount'        => $amount,
            ':expires_at'    => $expiresAt
        ]);

        $satId = $stmt->fetchColumn();

        // 8️⃣ Log the transaction for reference
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                user_id,
                reference,
                amount,
                type,
                status,
                notes,
                created_at
            )
            VALUES (
                :user_id,
                :reference,
                :amount,
                'ATM_TOKEN_GENERATION',
                'COMPLETED',
                :notes,
                NOW()
            )
        ");
        
        $notes = json_encode([
            'sat_id' => $satId,
            'instrument_id' => $instrumentId,
            'source_institution' => $sourceInstitution,
            'source_hold_reference' => $sourceHoldReference,
            'source_asset_type' => $sourceAssetType
        ]);
        
        $stmt->execute([
            ':user_id' => $userId,
            ':reference' => $reference ?: $satNumber,
            ':amount' => $amount,
            ':notes' => $notes
        ]);

        $pdo->commit();

        // Return format expected by GenericBankClient/SwapService
        $response = [
            'success' => true,
            'token_generated' => true,
            'sat_number' => $satNumber,
            'pin' => $pin, // Return plain PIN for SMS
            'atm_pin' => $pin, // For compatibility
            'token_reference' => $satNumber,
            'instrument_id' => $instrumentId,
            'sat_id' => $satId,
            'amount' => $amount,
            'currency' => 'BWP',
            'issuer_bank' => 'SACCUS',
            'acquirer_bank' => $acquirerNetwork,
            'expires_at' => $expiresAt,
            'metadata' => [
                'wallet_id' => $walletId,
                'user_id' => $userId,
                'reference' => $reference,
                'source_institution' => $sourceInstitution
            ]
        ];

        error_log("SACCUSSALIS: Success response: " . json_encode($response));
        return $response;

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("SACCUSSALIS SAT Generation Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

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
        'error' => 'Invalid JSON payload'
    ]);
    exit;
}

$result = generateSAT($pdo, $payload);
echo json_encode($result);
