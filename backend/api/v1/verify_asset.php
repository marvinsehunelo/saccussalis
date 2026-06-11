<?php
/**
 * verify_asset.php - SACCUSSALIS VERSION
 * Simple asset verification (like Zurubank) - NO signature verification
 */

require_once __DIR__ . '/../../db.php';

header("Content-Type: application/json");

error_log("=== SACCUSSALIS verify_asset.php CALLED ===");
error_log("RAW POST: " . file_get_contents("php://input"));

// -------------------------
// 1. Method Guard
// -------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "verified" => false, 
        "message" => "Method not allowed"
    ]);
    exit;
}

// -------------------------
// 2. Read Input
// -------------------------
$input = json_decode(file_get_contents("php://input"), true);

error_log("Parsed input: " . json_encode($input));

// Extract asset type and details
$assetType = strtoupper(
    $input['asset_type'] ?? 
    $input['type'] ?? 
    $input['source']['asset_type'] ?? 
    ''
);

// Extract phone number (multiple possible locations)
$phone = $input['wallet_phone'] ?? 
         $input['ewallet_phone'] ?? 
         $input['phone'] ?? 
         $input['source']['ewallet']['ewallet_phone'] ??
         $input['source']['phone'] ??
         null;

$amount = floatval($input['amount'] ?? $input['value'] ?? 0);
$reference = $input['reference'] ?? $input['transaction_reference'] ?? null;

error_log("Normalized - Type: $assetType, Phone: $phone, Amount: $amount, Reference: $reference");

// SACCUSSALIS HANDLES BANK-WALLET and ACCOUNT asset types
if ($assetType !== 'BANK-WALLET' && $assetType !== 'ACCOUNT') {
    error_log("ERROR: Unsupported asset type for SACCUSSALIS: $assetType");
    echo json_encode([
        "success" => true,
        "verified" => false,
        "message" => "SACCUSSALIS only supports BANK-WALLET or ACCOUNT asset type",
        "debug" => [
            "received_type" => $assetType,
            "supported_types" => ["BANK-WALLET", "ACCOUNT"]
        ]
    ]);
    exit;
}

try {
    if (!isset($pdo)) {
        throw new Exception("Database connection failed to initialize.");
    }

    if (empty($phone)) {
        throw new Exception("Phone number required");
    }

    // Format phone number for database lookup
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
        // Try without the plus sign
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

    // Check if wallet has sufficient balance
    if ($amount > 0 && $wallet['balance'] < $amount) {
        throw new Exception("Insufficient funds. Available: {$wallet['balance']}, Requested: $amount");
    }

    // ============================================================
    // BUILD RESPONSE (like Zurubank - simple JSON, no signature)
    // ============================================================
    $responsePayload = [
        "success" => true,
        "verified" => true,
        "asset_id" => $wallet['wallet_id'],
        "asset_type" => $assetType,
        "available_balance" => (float)$wallet['balance'],
        "balance" => (float)$wallet['balance'],
        "holder_name" => "Saccus Salis Customer",
        "recipient_phone" => $wallet['phone'],
        "expiry_date" => null,
        "metadata" => [
            "wallet_id" => $wallet['wallet_id'],
            "wallet_type" => $wallet['wallet_type'],
            "currency" => $wallet['currency'] ?? 'BWP',
            "phone" => $wallet['phone'],
            "status" => $wallet['status']
        ]
    ];
    
    error_log("SUCCESS RESPONSE: " . json_encode($responsePayload));
    echo json_encode($responsePayload);

} catch (Exception $e) {
    error_log("SACCUSSALIS verify_asset error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "verified" => false,
        "message" => $e->getMessage(),
        "timestamp" => time(),
        "debug" => [
            "asset_type" => $assetType,
            "phone" => $phone ?? null
        ]
    ]);
}
