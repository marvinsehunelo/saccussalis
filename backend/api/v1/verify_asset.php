<?php
/**
 * verify_asset.php - SACCUSSALIS VERSION
 * Checks BOTH wallet balance AND ewallet_pin validity
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

// Get request parameters
$pin = $input['pin'] ?? $input['ewallet_pin'] ?? $input['atm_pin'] ?? null;
$phone = $input['phone'] ?? $input['wallet_phone'] ?? null;
$amount = floatval($input['amount'] ?? $input['value'] ?? 0);
$assetType = strtoupper($input['asset_type'] ?? 'WALLET');

error_log("verify_asset: PIN=" . ($pin ? substr($pin, -4) : 'null') . ", phone=$phone, amount=$amount, assetType=$assetType");

if (!$pin) {
    error_log("verify_asset: No PIN provided");
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "PIN number required"
    ]);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed to initialize.");
    }

    // ============================================================
    // STEP 1: CHECK ewallet_pins TABLE FOR THE PIN
    // ============================================================
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
            "success" => true,
            "verified" => false,
            "message" => "PIN not found, already redeemed, or expired",
            "pin" => $pin
        ]);
        exit;
    }

    error_log("PIN found: id={$pinRecord['id']}, pin_amount={$pinRecord['pin_amount']}, hold_status={$pinRecord['hold_status']}");

    // Check if PIN is on hold
    if ($pinRecord['hold_status'] == true) {
        error_log("verify_asset: PIN is on hold: {$pinRecord['hold_reference']} held by {$pinRecord['held_by']}");
        echo json_encode([
            "success" => true,
            "verified" => false,
            "message" => "PIN is currently on hold. Hold reference: {$pinRecord['hold_reference']}",
            "is_on_hold" => true,
            "hold_reference" => $pinRecord['hold_reference'],
            "pin" => $pin
        ]);
        exit;
    }

    // ============================================================
    // STEP 2: CHECK WALLET TABLE FOR BALANCE AND STATUS
    // ============================================================
    if (empty($phone)) {
        error_log("verify_asset: No phone provided for wallet check");
        echo json_encode([
            "success" => true,
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

    // Check wallet
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
        // Try without +
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
                "success" => true,
                "verified" => false,
                "message" => "Wallet not found for phone: $targetPhone",
                "pin" => $pin
            ]);
            exit;
        }
    }

    error_log("Wallet found: ID={$wallet['wallet_id']}, Balance={$wallet['balance']}, Status={$wallet['status']}");

    // Check if wallet is active
    if ($wallet['status'] !== 'active') {
        error_log("verify_asset: Wallet is not active: {$wallet['status']}");
        echo json_encode([
            "success" => true,
            "verified" => false,
            "message" => "Wallet is not active: {$wallet['status']}",
            "pin" => $pin
        ]);
        exit;
    }

    // Check if wallet is frozen
    if ($wallet['is_frozen'] == true) {
        error_log("verify_asset: Wallet is frozen");
        echo json_encode([
            "success" => true,
            "verified" => false,
            "message" => "Wallet is frozen",
            "pin" => $pin
        ]);
        exit;
    }

    // Check if wallet has sufficient balance
    if ($amount > 0 && $wallet['balance'] < $amount) {
        error_log("verify_asset: Insufficient funds. Balance: {$wallet['balance']}, Requested: $amount");
        echo json_encode([
            "success" => true,
            "verified" => false,
            "message" => "Insufficient funds. Available: {$wallet['balance']}, Requested: $amount",
            "balance" => (float)$wallet['balance'],
            "pin" => $pin
        ]);
        exit;
    }

    // Also check if PIN amount matches (PIN should have at least the requested amount)
    if ($pinRecord['pin_amount'] < $amount) {
        error_log("verify_asset: PIN has insufficient value. PIN Amount: {$pinRecord['pin_amount']}, Requested: $amount");
        echo json_encode([
            "success" => true,
            "verified" => false,
            "message" => "PIN has insufficient value. Available: {$pinRecord['pin_amount']}, Requested: $amount",
            "pin" => $pin
        ]);
        exit;
    }

    // ============================================================
    // BOTH WALLET AND PIN ARE VALID - Return success
    // ============================================================
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
            "pin" => $pinRecord['pin'],
            "pin_created_at" => $pinRecord['created_at'],
            "pin_sender_phone" => $pinRecord['sender_phone'],
            "pin_recipient_phone" => $pinRecord['recipient_phone'],
            "pin_hold_status" => $pinRecord['hold_status']
        ]
    ];
    
    error_log("verify_asset: BOTH wallet and PIN verified successfully. Wallet ID={$wallet['wallet_id']}, PIN ID={$pinRecord['id']}");
    echo json_encode($responsePayload);

} catch (Exception $e) {
    error_log("verify_asset error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => $e->getMessage()
    ]);
}
