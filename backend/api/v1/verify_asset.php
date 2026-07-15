<?php
/**
 * verify_asset.php - SACCUSSALIS VERSION
 * Checks BOTH wallet balance AND ewallet_pin validity
 * NOW WITH ACCOUNT VERIFICATION
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
// DETECT PIN FROM ALL POSSIBLE LOCATIONS
// ============================================================
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

// Detect asset type and identifiers
$assetType = strtoupper($input['asset_type'] ?? 'WALLET');
$sourceIdentifier = $input['source_identifier'] ?? $input['account_number'] ?? $input['phone'] ?? $input['wallet_phone'] ?? null;
$phone = $input['phone'] ?? $input['wallet_phone'] ?? null;
$accountNumber = $input['account_number'] ?? $input['source_identifier'] ?? null;
$amount = floatval($input['amount'] ?? $input['value'] ?? 0);

error_log("verify_asset: PIN=" . ($pin ? substr($pin, -4) : 'null') . 
          ", sourceIdentifier=$sourceIdentifier, assetType=$assetType, amount=$amount");

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed to initialize.");
    }

    // ============================================================
    // BRANCH BASED ON ASSET TYPE
    // ============================================================
    
    if ($assetType === 'ACCOUNT') {
        // ============================================================
        // ACCOUNT VERIFICATION
        // ============================================================
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
                status,
                created_at,
                updated_at
            FROM accounts 
            WHERE account_number = :account_number
            AND (status = 'active' OR status IS NULL)
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

        error_log("verify_asset: Account found: ID={$account['account_id']}, Balance={$account['balance']}, Status={$account['status']}");

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

        // Account is valid
        $responsePayload = [
            "success" => true,
            "verified" => true,
            "asset_id" => $account['account_id'],
            "asset_type" => "ACCOUNT",
            "available_balance" => $availableBalance,
            "balance" => (float)$account['balance'],
            "held_balance" => (float)($account['held_balance'] ?? 0),
            "holder_name" => "Saccus Salis Customer",
            "account_number" => $account['account_number'],
            "account_type" => $account['account_type'],
            "currency" => $account['currency'] ?? 'BWP',
            "status" => $account['status'] ?? 'active',
            "is_frozen" => $account['is_frozen'] == true,
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
    // WALLET / BANK-WALLET VERIFICATION (Original Logic)
    // ============================================================
    if ($assetType === 'WALLET' || $assetType === 'BANK-WALLET' || $assetType === 'MNO-WALLET') {
        
        if (!$pin) {
            error_log("verify_asset: No PIN found for wallet verification");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "PIN number required for wallet verification"
            ]);
            exit;
        }

        // STEP 1: Check ewallet_pins table for the PIN
        $stmt = $pdo->prepare("
            SELECT 
                id,
                pin,
                amount as pin_amount,
                sat_purchased,
                is_redeemed,
                hold_status,
                hold_reference,
                held_at,
                held_by,
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
        $stmt->execute(['pin' => $pin]);
        $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pinRecord) {
            error_log("verify_asset: PIN not found or already redeemed/expired: $pin");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "PIN not found, already redeemed, or expired",
                "pin" => $pin
            ]);
            exit;
        }

        error_log("PIN found: id={$pinRecord['id']}, pin_amount={$pinRecord['pin_amount']}, hold_status={$pinRecord['hold_status']}");

        // Check if PIN is on hold
        if ($pinRecord['hold_status'] == true) {
            error_log("verify_asset: PIN is on hold: {$pinRecord['hold_reference']}");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "PIN is currently on hold. Hold reference: {$pinRecord['hold_reference']}",
                "is_on_hold" => true,
                "hold_reference" => $pinRecord['hold_reference'],
                "pin" => $pin
            ]);
            exit;
        }

        // Check if PIN has enough value
        if ($amount > 0 && $pinRecord['pin_amount'] < $amount) {
            error_log("verify_asset: PIN has insufficient value. PIN Amount: {$pinRecord['pin_amount']}, Requested: $amount");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "PIN has insufficient value. Available: {$pinRecord['pin_amount']}, Requested: $amount",
                "pin" => $pin
            ]);
            exit;
        }

        // STEP 2: Check wallet table for balance and status
        if (empty($phone)) {
            // Try to get phone from source_identifier
            $phone = $sourceIdentifier;
        }

        if (empty($phone)) {
            error_log("verify_asset: No phone provided for wallet check");
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

        $stmt = $pdo->prepare("
            SELECT 
                wallet_id,
                phone,
                balance,
                status,
                wallet_type,
                currency,
                is_frozen,
                user_id,
                created_at
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
                SELECT * FROM wallets 
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
                    "message" => "Wallet not found for phone: $targetPhone",
                    "pin" => $pin
                ]);
                exit;
            }
        }

        error_log("Wallet found: ID={$wallet['wallet_id']}, Balance={$wallet['balance']}, Status={$wallet['status']}");

        if ($wallet['status'] !== 'active') {
            error_log("verify_asset: Wallet is not active: {$wallet['status']}");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Wallet is not active: {$wallet['status']}",
                "pin" => $pin
            ]);
            exit;
        }

        if ($wallet['is_frozen'] == true) {
            error_log("verify_asset: Wallet is frozen");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Wallet is frozen",
                "pin" => $pin
            ]);
            exit;
        }

        if ($amount > 0 && $wallet['balance'] < $amount) {
            error_log("verify_asset: Insufficient funds. Balance: {$wallet['balance']}, Requested: $amount");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Insufficient funds. Available: {$wallet['balance']}, Requested: $amount",
                "balance" => (float)$wallet['balance'],
                "pin" => $pin
            ]);
            exit;
        }

        // BOTH WALLET AND PIN ARE VALID - Return success
        $responsePayload = [
            "success" => true,
            "verified" => true,
            "asset_id" => $wallet['wallet_id'],
            "asset_type" => "BANK-WALLET",
            "available_balance" => (float)$wallet['balance'],
            "balance" => (float)$wallet['balance'],
            "holder_name" => "Saccus Salis Customer",
            "recipient_phone" => $wallet['phone'],
            "expiry_date" => null,
            "pin" => $pin,
            "pin_id" => $pinRecord['id'],
            "pin_amount" => (float)$pinRecord['pin_amount'],
            "is_on_hold" => false,
            "hold_status" => "FREE",
            "metadata" => [
                "wallet_id" => $wallet['wallet_id'],
                "wallet_type" => $wallet['wallet_type'],
                "currency" => $wallet['currency'] ?? 'BWP',
                "phone" => $wallet['phone'],
                "status" => $wallet['status'],
                "is_frozen" => $wallet['is_frozen'],
                "pin_id" => $pinRecord['id'],
                "pin_created_at" => $pinRecord['created_at'],
                "pin_sender_phone" => $pinRecord['sender_phone'],
                "pin_recipient_phone" => $pinRecord['recipient_phone'],
                "pin_hold_status" => $pinRecord['hold_status']
            ]
        ];
        
        error_log("verify_asset: BOTH wallet and PIN verified successfully. Wallet ID={$wallet['wallet_id']}, PIN ID={$pinRecord['id']}");
        echo json_encode($responsePayload);
        exit;
    }

    // ============================================================
    // UNKNOWN ASSET TYPE
    // ============================================================
    error_log("verify_asset: Unknown asset type: $assetType");
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "Unknown asset type: $assetType. Supported: ACCOUNT, WALLET, BANK-WALLET, MNO-WALLET"
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
