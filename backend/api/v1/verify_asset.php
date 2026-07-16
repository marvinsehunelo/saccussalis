<?php
/**
 * verify_asset.php - SACCUSSALIS VERSION (UPDATED)
 * Checks wallet balance, account balance, and identity verification
 * 
 * PIN POLICY (UPDATED):
 * - PIN is OPTIONAL for wallet and account verification
 * - Authentication can be via:
 *   - PIN (legacy, optional)
 *   - Access token (from hooked sources)
 *   - Source reference (from source_accounts)
 *   - Institution-specific authentication
 * 
 * SUPPORTS:
 * - ACCOUNT: Checks accounts table for balance and frozen status
 * - WALLET / BANK-WALLET / MNO-WALLET: Checks wallets + optional PIN
 * - VOUCHER: Still requires PIN (vouchers are PIN-based)
 * - IDENTITY: Checks identity verification without PIN
 */

require_once __DIR__ . '/../../db.php';

header("Content-Type: application/json");

error_log("=== verify_asset.php CALLED ===");
error_log("RAW POST: " . file_get_contents("php://input"));

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
error_log("Parsed input: " . json_encode($input));

if (!$input) {
    error_log("verify_asset: Invalid JSON input");
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "Invalid JSON input"
    ]);
    exit;
}

// ============================================================
// DETECT AUTHENTICATION METHOD
// ============================================================
// PIN is OPTIONAL for wallet/account - check all possible auth methods
$pin = $input['pin'] ?? 
       $input['wallet_pin'] ?? 
       $input['atm_pin'] ?? 
       $input['voucher_pin'] ?? 
       $input['card_pin'] ?? 
       $input['asset_fields']['wallet_pin'] ?? 
       $input['asset_fields']['pin'] ?? 
       $input['asset_fields']['atm_pin'] ?? 
       $input['asset_fields']['voucher_pin'] ?? 
       $input['source']['wallet_pin'] ?? 
       $input['source']['pin'] ?? 
       null;

// Alternative authentication methods
$accessToken = $input['access_token'] ?? null;
$sourceReference = $input['source_reference'] ?? null;
$isHooked = isset($input['_is_hooked']) && $input['_is_hooked'] === true;

error_log("verify_asset: Auth methods - PIN: " . ($pin ? 'present' : 'null') . 
          ", AccessToken: " . ($accessToken ? 'present' : 'null') . 
          ", SourceRef: " . ($sourceReference ? 'present' : 'null') . 
          ", IsHooked: " . ($isHooked ? 'true' : 'false'));

// Detect asset type and identifiers
$assetType = strtoupper($input['asset_type'] ?? 'WALLET');
$sourceIdentifier = $input['source_identifier'] ?? $input['account_number'] ?? $input['phone'] ?? $input['wallet_phone'] ?? null;
$phone = $input['phone'] ?? $input['wallet_phone'] ?? null;
$accountNumber = $input['account_number'] ?? $input['source_identifier'] ?? null;
$amount = floatval($input['amount'] ?? $input['value'] ?? 0);
$voucherNumber = $input['voucher_number'] ?? $input['source_identifier'] ?? null;

error_log("verify_asset: sourceIdentifier=$sourceIdentifier, assetType=$assetType, amount=$amount");

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed to initialize.");
    }

    // ============================================================
    // BRANCH BASED ON ASSET TYPE
    // ============================================================
    
    // ============================================================
    // CASE 1: ACCOUNT VERIFICATION (PIN OPTIONAL)
    // ============================================================
    if ($assetType === 'ACCOUNT') {
        error_log("verify_asset: Verifying ACCOUNT: $accountNumber");
        
        if (empty($accountNumber)) {
            error_log("verify_asset: No account number provided");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Account number required for ACCOUNT verification"
            ]);
            exit;
        }

        // Find the account
        $stmt = $pdo->prepare("
            SELECT 
                account_id,
                user_id,
                account_number,
                account_type,
                currency,
                balance,
                held_balance,
                is_frozen,
                created_at,
                updated_at
            FROM accounts 
            WHERE account_number = :account_number
            LIMIT 1
        ");
        $stmt->execute(['account_number' => $accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            error_log("verify_asset: Account not found: $accountNumber");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Account not found: $accountNumber"
            ]);
            exit;
        }

        error_log("verify_asset: Account found: ID={$account['account_id']}, Balance={$account['balance']}");

        // Check if account is frozen
        if ($account['is_frozen'] == true) {
            error_log("verify_asset: Account is frozen");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Account is frozen"
            ]);
            exit;
        }

        // Check if account has sufficient balance
        $availableBalance = (float)$account['balance'] - (float)($account['held_balance'] ?? 0);
        
        if ($amount > 0 && $availableBalance < $amount) {
            error_log("verify_asset: Insufficient funds. Available: $availableBalance, Requested: $amount");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Insufficient funds. Available: $availableBalance, Requested: $amount",
                "balance" => $availableBalance,
                "held_balance" => (float)($account['held_balance'] ?? 0),
                "total_balance" => (float)$account['balance']
            ]);
            exit;
        }

        // ============================================================
        // PIN IS OPTIONAL FOR ACCOUNT - Skip PIN check if access_token present
        // ============================================================
        if ($pin && !$isHooked && !$accessToken) {
            // Legacy PIN check - optional
            error_log("verify_asset: Optional PIN provided for account: " . substr($pin, -4));
            // Verify PIN against account
            $pinStmt = $pdo->prepare("
                SELECT id FROM ewallet_pins 
                WHERE pin = :pin 
                AND is_redeemed = false 
                AND (expires_at IS NULL OR expires_at > NOW())
                LIMIT 1
            ");
            $pinStmt->execute(['pin' => $pin]);
            if (!$pinStmt->fetch()) {
                // PIN invalid - but account is still verified (PIN optional)
                error_log("verify_asset: Optional PIN invalid for account - proceeding with account verification only");
                // Don't fail - just log it
            }
        }

        // Account is valid - Return success
        $responsePayload = [
            "success" => true,
            "verified" => true,
            "asset_id" => $account['account_id'],
            "asset_type" => "ACCOUNT",
            "available_balance" => $availableBalance,
            "balance" => (float)$account['balance'],
            "held_balance" => (float)($account['held_balance'] ?? 0),
            "holder_name" => $account['account_name'] ?? "Account Holder",
            "account_number" => $account['account_number'],
            "account_type" => $account['account_type'],
            "currency" => $account['currency'] ?? 'BWP',
            "is_frozen" => $account['is_frozen'] == true,
            "auth_method" => $pin ? "pin" : ($accessToken ? "token" : "account_only"),
            "metadata" => [
                "account_id" => $account['account_id'],
                "user_id" => $account['user_id'],
                "account_number" => $account['account_number'],
                "account_type" => $account['account_type'],
                "currency" => $account['currency'] ?? 'BWP',
                "created_at" => $account['created_at']
            ]
        ];
        
        error_log("verify_asset: ACCOUNT verified successfully. Account ID={$account['account_id']}");
        echo json_encode($responsePayload);
        exit;
    }

    // ============================================================
    // CASE 2: VOUCHER VERIFICATION (PIN STILL REQUIRED)
    // Vouchers are PIN-based by design
    // ============================================================
    if ($assetType === 'VOUCHER') {
        error_log("verify_asset: Verifying VOUCHER: $voucherNumber");
        
        if (!$pin) {
            error_log("verify_asset: No PIN found for voucher verification");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "PIN number required for VOUCHER verification"
            ]);
            exit;
        }

        // Check ewallet_pins table for the PIN (vouchers are stored as pins)
        $stmt = $pdo->prepare("
            SELECT 
                id,
                pin,
                amount as pin_amount,
                is_redeemed,
                hold_status,
                hold_reference,
                held_at,
                held_by,
                expires_at,
                created_at,
                sender_phone,
                recipient_phone,
                voucher_type,
                voucher_metadata
            FROM ewallet_pins 
            WHERE pin = :pin 
            AND is_redeemed = false
            AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute(['pin' => $pin]);
        $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pinRecord) {
            error_log("verify_asset: Voucher PIN not found or already redeemed/expired: $pin");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Voucher PIN not found, already redeemed, or expired",
                "pin" => $pin
            ]);
            exit;
        }

        error_log("Voucher found: id={$pinRecord['id']}, pin_amount={$pinRecord['pin_amount']}, hold_status={$pinRecord['hold_status']}");

        // Check if voucher is on hold
        if ($pinRecord['hold_status'] == true) {
            error_log("verify_asset: Voucher is on hold: {$pinRecord['hold_reference']}");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Voucher is currently on hold. Hold reference: {$pinRecord['hold_reference']}",
                "is_on_hold" => true,
                "hold_reference" => $pinRecord['hold_reference'],
                "pin" => $pin
            ]);
            exit;
        }

        // Check if voucher has enough value
        if ($amount > 0 && $pinRecord['pin_amount'] < $amount) {
            error_log("verify_asset: Voucher has insufficient value. PIN Amount: {$pinRecord['pin_amount']}, Requested: $amount");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Voucher has insufficient value. Available: {$pinRecord['pin_amount']}, Requested: $amount",
                "pin" => $pin
            ]);
            exit;
        }

        // Voucher is valid
        $responsePayload = [
            "success" => true,
            "verified" => true,
            "asset_id" => $pinRecord['id'],
            "asset_type" => "VOUCHER",
            "available_balance" => (float)$pinRecord['pin_amount'],
            "balance" => (float)$pinRecord['pin_amount'],
            "holder_name" => "Voucher Holder",
            "voucher_number" => $pin,
            "voucher_amount" => (float)$pinRecord['pin_amount'],
            "expiry_date" => $pinRecord['expires_at'],
            "is_on_hold" => false,
            "hold_status" => "FREE",
            "voucher_type" => $pinRecord['voucher_type'] ?? 'GENERIC',
            "metadata" => [
                "pin_id" => $pinRecord['id'],
                "pin" => $pinRecord['pin'],
                "pin_amount" => $pinRecord['pin_amount'],
                "is_redeemed" => $pinRecord['is_redeemed'],
                "created_at" => $pinRecord['created_at'],
                "sender_phone" => $pinRecord['sender_phone'],
                "recipient_phone" => $pinRecord['recipient_phone'],
                "voucher_metadata" => $pinRecord['voucher_metadata']
            ]
        ];
        
        error_log("verify_asset: VOUCHER verified successfully. PIN ID={$pinRecord['id']}");
        echo json_encode($responsePayload);
        exit;
    }

    // ============================================================
    // CASE 3: WALLET / BANK-WALLET / MNO-WALLET VERIFICATION
    // PIN IS OPTIONAL - can use access_token, source_reference, or phone
    // ============================================================
    if ($assetType === 'WALLET' || $assetType === 'BANK-WALLET' || $assetType === 'MNO-WALLET') {
        
        error_log("verify_asset: Verifying WALLET: $phone (type: $assetType)");
        
        // STEP 1: Get phone for wallet lookup
        if (empty($phone)) {
            $phone = $sourceIdentifier;
        }

        if (empty($phone)) {
            error_log("verify_asset: No phone provided for wallet verification");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Phone number required for wallet verification"
            ]);
            exit;
        }

        $targetPhone = $phone;
        if (!str_starts_with($targetPhone, '+')) {
            $targetPhone = '+' . $targetPhone;
        }
        
        error_log("Looking for wallet with phone: " . $targetPhone);

        // ============================================================
        // FIX: Removed kyc_level and holder_name columns (they don't exist)
        // Only use columns that exist in the wallets table
        // ============================================================
        $stmt = $pdo->prepare("
            SELECT 
                wallet_id,
                user_id,
                phone,
                wallet_type,
                currency,
                balance,
                held_balance,
                is_frozen,
                status,
                created_at,
                updated_at
            FROM wallets 
            WHERE phone = :phone 
            AND status = 'active'
            AND is_frozen = false
            LIMIT 1
        ");
        $stmt->execute(['phone' => $targetPhone]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            $phoneWithoutPlus = ltrim($targetPhone, '+');
            $stmt = $pdo->prepare("
                SELECT 
                    wallet_id,
                    user_id,
                    phone,
                    wallet_type,
                    currency,
                    balance,
                    held_balance,
                    is_frozen,
                    status,
                    created_at,
                    updated_at
                FROM wallets 
                WHERE phone = :phone 
                AND status = 'active'
                AND is_frozen = false
                LIMIT 1
            ");
            $stmt->execute(['phone' => $phoneWithoutPlus]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$wallet) {
                error_log("verify_asset: Wallet not found for phone: $targetPhone");
                echo json_encode([
                    "success" => false,
                    "verified" => false,
                    "message" => "Wallet not found for phone: $targetPhone"
                ]);
                exit;
            }
        }

        error_log("Wallet found: ID={$wallet['wallet_id']}, Balance={$wallet['balance']}, Status={$wallet['status']}");

        // Check wallet status
        if ($wallet['status'] !== 'active') {
            error_log("verify_asset: Wallet is not active: {$wallet['status']}");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Wallet is not active: {$wallet['status']}"
            ]);
            exit;
        }

        if ($wallet['is_frozen'] == true) {
            error_log("verify_asset: Wallet is frozen");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Wallet is frozen"
            ]);
            exit;
        }

        // Calculate available balance (balance - held_balance)
        $availableBalance = (float)$wallet['balance'] - (float)($wallet['held_balance'] ?? 0);

        if ($amount > 0 && $availableBalance < $amount) {
            error_log("verify_asset: Insufficient funds. Available: $availableBalance, Requested: $amount");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Insufficient funds. Available: $availableBalance, Requested: $amount",
                "balance" => (float)$wallet['balance'],
                "held_balance" => (float)($wallet['held_balance'] ?? 0),
                "available_balance" => $availableBalance
            ]);
            exit;
        }

        // ============================================================
        // PIN IS OPTIONAL FOR WALLET - Check if provided
        // ============================================================
        $pinVerified = false;
        $pinRecord = null;

        if ($pin) {
            error_log("verify_asset: Optional PIN provided for wallet: " . substr($pin, -4));
            
            $pinStmt = $pdo->prepare("
                SELECT 
                    id,
                    pin,
                    amount as pin_amount,
                    is_redeemed,
                    hold_status,
                    hold_reference,
                    expires_at,
                    created_at,
                    sender_phone,
                    recipient_phone
                FROM ewallet_pins 
                WHERE pin = :pin 
                AND is_redeemed = false
                AND (expires_at IS NULL OR expires_at > NOW())
                LIMIT 1
            ");
            $pinStmt->execute(['pin' => $pin]);
            $pinRecord = $pinStmt->fetch(PDO::FETCH_ASSOC);

            if ($pinRecord) {
                $pinVerified = true;
                error_log("verify_asset: Optional PIN verified successfully. PIN ID={$pinRecord['id']}");
                
                // Check PIN hold status
                if ($pinRecord['hold_status'] == true) {
                    error_log("verify_asset: PIN is on hold: {$pinRecord['hold_reference']}");
                    // Still proceed - wallet is verified, just note PIN is on hold
                }
            } else {
                error_log("verify_asset: Optional PIN not found or expired - proceeding with wallet verification only");
            }
        }

        // Check if using alternative authentication
        $authMethod = 'wallet_only';
        if ($pinVerified) {
            $authMethod = 'wallet_pin';
        } elseif ($accessToken) {
            $authMethod = 'wallet_token';
        } elseif ($sourceReference) {
            $authMethod = 'wallet_source_ref';
        } elseif ($isHooked) {
            $authMethod = 'wallet_hooked';
        }

        // WALLET IS VALID - Return success (PIN optional)
        $responsePayload = [
            "success" => true,
            "verified" => true,
            "asset_id" => $wallet['wallet_id'],
            "asset_type" => $assetType,
            "available_balance" => $availableBalance,
            "balance" => (float)$wallet['balance'],
            "held_balance" => (float)($wallet['held_balance'] ?? 0),
            "holder_name" => "Wallet Holder",
            "recipient_phone" => $wallet['phone'],
            "currency" => $wallet['currency'] ?? 'BWP',
            "wallet_type" => $wallet['wallet_type'] ?? 'STANDARD',
            "auth_method" => $authMethod,
            "pin_verified" => $pinVerified,
            "metadata" => [
                "wallet_id" => $wallet['wallet_id'],
                "user_id" => $wallet['user_id'],
                "wallet_type" => $wallet['wallet_type'] ?? 'STANDARD',
                "currency" => $wallet['currency'] ?? 'BWP',
                "phone" => $wallet['phone'],
                "status" => $wallet['status'],
                "is_frozen" => $wallet['is_frozen'],
                "created_at" => $wallet['created_at'],
                "is_hooked" => $isHooked,
                "source_reference" => $sourceReference
            ]
        ];

        // Add PIN metadata if PIN was verified
        if ($pinRecord) {
            $responsePayload['pin'] = $pin;
            $responsePayload['pin_id'] = $pinRecord['id'];
            $responsePayload['pin_amount'] = (float)$pinRecord['pin_amount'];
            $responsePayload['pin_hold_status'] = $pinRecord['hold_status'];
            $responsePayload['pin_expires_at'] = $pinRecord['expires_at'];
            $responsePayload['metadata']['pin_id'] = $pinRecord['id'];
            $responsePayload['metadata']['pin_created_at'] = $pinRecord['created_at'];
            $responsePayload['metadata']['pin_sender_phone'] = $pinRecord['sender_phone'];
            $responsePayload['metadata']['pin_recipient_phone'] = $pinRecord['recipient_phone'];
            $responsePayload['metadata']['pin_hold_status'] = $pinRecord['hold_status'];
        }
        
        error_log("verify_asset: WALLET verified successfully. Wallet ID={$wallet['wallet_id']}, Auth method: $authMethod");
        echo json_encode($responsePayload);
        exit;
    }

    // ============================================================
    // CASE 4: IDENTITY VERIFICATION (No PIN required)
    // ============================================================
    if ($assetType === 'IDENTITY') {
        error_log("verify_asset: Verifying IDENTITY");
        
        $identityType = $input['identity_type'] ?? $input['identifier_type'] ?? 'national_id';
        $identityValue = $input['identity_value'] ?? $input['identifier'] ?? null;
        
        if (empty($identityValue)) {
            error_log("verify_asset: No identity value provided");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Identity value required for IDENTITY verification"
            ]);
            exit;
        }

        error_log("verify_asset: Looking for identity: $identityType = $identityValue");

        // Check user_identities table
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    identity_id,
                    user_id,
                    identity_type,
                    identity_value,
                    is_verified,
                    verified_at,
                    created_at
                FROM user_identities 
                WHERE identity_type = :identity_type 
                AND identity_value = :identity_value
                LIMIT 1
            ");
            $stmt->execute([
                'identity_type' => $identityType,
                'identity_value' => $identityValue
            ]);
            $identity = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$identity) {
                error_log("verify_asset: Identity not found: $identityType = $identityValue");
                echo json_encode([
                    "success" => false,
                    "verified" => false,
                    "message" => "Identity not found: $identityType = $identityValue"
                ]);
                exit;
            }

            // Check if identity is verified
            if ($identity['is_verified'] != true) {
                error_log("verify_asset: Identity not verified");
                echo json_encode([
                    "success" => false,
                    "verified" => false,
                    "message" => "Identity exists but not verified"
                ]);
                exit;
            }

            error_log("verify_asset: Identity verified: ID={$identity['identity_id']}, User={$identity['user_id']}");

            // Get user info
            $userStmt = $pdo->prepare("
                SELECT user_id, full_name, email, phone, national_id
                FROM users 
                WHERE user_id = :user_id
            ");
            $userStmt->execute(['user_id' => $identity['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "verified" => true,
                "asset_type" => "IDENTITY",
                "identity_id" => $identity['identity_id'],
                "identity_type" => $identity['identity_type'],
                "identity_value" => $identity['identity_value'],
                "user_id" => $identity['user_id'],
                "user_name" => $user['full_name'] ?? null,
                "user_phone" => $user['phone'] ?? null,
                "user_email" => $user['email'] ?? null,
                "is_verified" => $identity['is_verified'],
                "verified_at" => $identity['verified_at'],
                "created_at" => $identity['created_at'],
                "auth_method" => "identity_only",
                "metadata" => [
                    "identity_id" => $identity['identity_id'],
                    "identity_type" => $identity['identity_type'],
                    "identity_value" => $identity['identity_value'],
                    "user_id" => $identity['user_id'],
                    "is_verified" => $identity['is_verified'],
                    "created_at" => $identity['created_at']
                ]
            ]);
            exit;

        } catch (Exception $e) {
            error_log("verify_asset: Identity table error: " . $e->getMessage());
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Identity verification not available: " . $e->getMessage()
            ]);
            exit;
        }
    }

    // ============================================================
    // CASE 5: UNKNOWN ASSET TYPE
    // ============================================================
    error_log("verify_asset: Unknown asset type: $assetType");
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "Unknown asset type: $assetType. Supported: ACCOUNT, VOUCHER, WALLET, BANK-WALLET, MNO-WALLET, IDENTITY"
    ]);

} catch (Exception $e) {
    error_log("verify_asset error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => $e->getMessage()
    ]);
}
