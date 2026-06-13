<?php
/**
 * verify_asset.php - SACCUSSALIS VERSION with PIN HOLD checking
 * Asset verification that checks both wallet balances and PIN status
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

$assetType = strtoupper($input['asset_type'] ?? $input['type'] ?? $input['source']['asset_type'] ?? '');
$phone = $input['wallet_phone'] ?? $input['ewallet_phone'] ?? $input['phone'] ?? $input['source']['ewallet']['ewallet_phone'] ?? $input['source']['phone'] ?? null;
$pin = $input['pin'] ?? $input['source']['pin'] ?? null;
$amount = floatval($input['amount'] ?? $input['value'] ?? 0);
$reference = $input['reference'] ?? $input['transaction_reference'] ?? null;

error_log("Normalized - Type: $assetType, Phone: $phone, PIN: $pin, Amount: $amount");

// SACCUSSALIS handles BANK-WALLET, ACCOUNT, and PIN asset types
if ($assetType !== 'BANK-WALLET' && $assetType !== 'ACCOUNT' && $assetType !== 'PIN') {
    error_log("ERROR: Unsupported asset type: $assetType");
    echo json_encode([
        "success" => true,
        "verified" => false,
        "message" => "Unsupported asset type. Supported: BANK-WALLET, ACCOUNT, PIN",
        "debug" => ["received_type" => $assetType]
    ]);
    exit;
}

try {
    if (!isset($pdo)) {
        throw new Exception("Database connection failed to initialize.");
    }

    $verified = false;
    $assetId = null;
    $availableBalance = 0;
    $holderName = null;
    $expiryDate = null;
    $metadata = [];

    // ============================================================
    // HANDLE PIN ASSET TYPE (with hold checking)
    // ============================================================
    if ($assetType === 'PIN') {
        if (empty($pin)) {
            throw new Exception("PIN number required for PIN asset type");
        }

        // Check PIN - MUST NOT be redeemed OR on hold
        $stmt = $pdo->prepare("
            SELECT 
                id,
                pin,
                amount,
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
            throw new Exception("PIN not found, already redeemed, or expired");
        }

        // Check if PIN is on hold
        if ($pinRecord['hold_status'] == true) {
            error_log("PIN is on hold: {$pinRecord['hold_reference']} held by {$pinRecord['held_by']}");
            throw new Exception("PIN is currently on hold. Hold reference: {$pinRecord['hold_reference']}");
        }

        // Check if amount requested is available
        if ($amount > 0 && $pinRecord['amount'] < $amount) {
            throw new Exception("PIN has insufficient value. Available: {$pinRecord['amount']}, Requested: $amount");
        }

        $verified = true;
        $assetId = $pinRecord['id'];
        $availableBalance = (float)$pinRecord['amount'];
        $holderName = "PIN Holder - " . ($pinRecord['recipient_phone'] ?? $pinRecord['sender_phone'] ?? 'Unknown');
        $expiryDate = $pinRecord['expires_at'];
        
        $metadata = [
            "pin_id" => $pinRecord['id'],
            "pin" => $pinRecord['pin'],
            "sat_purchased" => $pinRecord['sat_purchased'],
            "created_at" => $pinRecord['created_at'],
            "sender_phone" => $pinRecord['sender_phone'],
            "recipient_phone" => $pinRecord['recipient_phone'],
            "hold_status" => $pinRecord['hold_status'],
            "asset_type" => "PIN"
        ];
        
        error_log("PIN verified successfully: ID={$pinRecord['id']}, Balance={$pinRecord['amount']}");
        
    } else {
        // ============================================================
        // HANDLE WALLET/ACCOUNT ASSET TYPE (Original logic)
        // ============================================================
        
        if (empty($phone)) {
            throw new Exception("Phone number required for BANK-WALLET/ACCOUNT");
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
                throw new Exception("Wallet not found for phone: $targetPhone");
            }
        }

        error_log("Wallet found: ID={$wallet['wallet_id']}, Balance={$wallet['balance']}");

        // Check if wallet is frozen (already checked above)
        if ($wallet['status'] !== 'active') {
            throw new Exception("Wallet is not active: {$wallet['status']}");
        }

        if ($amount > 0 && $wallet['balance'] < $amount) {
            throw new Exception("Insufficient funds. Available: {$wallet['balance']}, Requested: $amount");
        }

        $verified = true;
        $assetId = $wallet['wallet_id'];
        $availableBalance = (float)$wallet['balance'];
        $holderName = "Saccus Salis Customer";
        $expiryDate = null;
        
        $metadata = [
            "wallet_id" => $wallet['wallet_id'],
            "wallet_type" => $wallet['wallet_type'],
            "currency" => $wallet['currency'] ?? 'BWP',
            "phone" => $wallet['phone'],
            "status" => $wallet['status'],
            "asset_type" => "WALLET"
        ];
    }

    // ============================================================
    // BUILD RESPONSE
    // ============================================================
    $responsePayload = [
        "success" => true,
        "verified" => $verified,
        "asset_id" => $assetId,
        "asset_type" => $assetType,
        "available_balance" => $availableBalance,
        "balance" => $availableBalance,
        "holder_name" => $holderName,
        "recipient_phone" => $phone ?? ($pin ? "PIN-$pin" : null),
        "expiry_date" => $expiryDate,
        "metadata" => $metadata
    ];
    
    if ($assetType === 'PIN' && $pin) {
        $responsePayload['pin'] = $pin;
        $responsePayload['is_on_hold'] = false;
        $responsePayload['hold_status'] = 'FREE';
    }
    
    error_log("SUCCESS RESPONSE: " . json_encode($responsePayload));
    echo json_encode($responsePayload);

} catch (Exception $e) {
    error_log("verify_asset error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    http_response_code(200);
    
    $errorResponse = [
        "success" => true,
        "verified" => false,
        "message" => $e->getMessage(),
        "timestamp" => time(),
        "debug" => [
            "asset_type" => $assetType,
            "phone" => $phone ?? null,
            "pin" => isset($pin) ? substr($pin, -4) : null
        ]
    ];
    
    // Add PIN-specific error details
    if ($assetType === 'PIN' && isset($pin)) {
        $errorResponse['pin_hold_check'] = true;
        $errorResponse['is_on_hold'] = true;
    }
    
    echo json_encode($errorResponse);
}
