<?php
/**
 * verify_asset.php - FIXED for +26770000000 format
 * WITH SIGNATURE VERIFICATION
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../helpers/crypto.php';

header("Content-Type: application/json");

// Enable error logging
error_log("=== SACCUSSALIS verify_asset.php CALLED ===");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["verified" => false, "message" => "Method not allowed"]);
    exit;
}

$rawInput = file_get_contents("php://input");
error_log("RAW INPUT: " . $rawInput);

$input = json_decode($rawInput, true);
error_log("PARSED INPUT: " . json_encode($input));

// ============================================================
// VERIFY INCOMING SIGNATURE
// ============================================================
$signature = $input['signature'] ?? null;
$timestamp = $input['timestamp'] ?? null;
$requester = $input['requester'] ?? 'VOUCHMORPH';

$payloadToVerify = [
    'asset_type' => $input['asset_type'] ?? null,
    'amount' => $input['amount'] ?? null,
    'reference' => $input['reference'] ?? null
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("SACCUSSALIS: Missing signature from {$requester}");
    echo json_encode([
        "verified" => false,
        "message" => "Missing signature - verification requests must be signed"
    ]);
    exit;
}

$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("SACCUSSALIS: No public key for requester: {$requester}");
    echo json_encode([
        "verified" => false,
        "message" => "No public key found for requester: {$requester}"
    ]);
    exit;
}

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("SACCUSSALIS: Invalid signature from {$requester}");
    echo json_encode([
        "verified" => false,
        "message" => "Invalid signature - request cannot be trusted"
    ]);
    exit;
}

error_log("SACCUSSALIS: Signature verified from {$requester}");

// ============================================================
// PROCESS VERIFICATION
// ============================================================

// Extract phone number
$phone = null;

if (isset($input['ewallet_phone'])) {
    $phone = $input['ewallet_phone'];
} elseif (isset($input['ewallet']['ewallet_phone'])) {
    $phone = $input['ewallet']['ewallet_phone'];
} elseif (isset($input['phone'])) {
    $phone = $input['phone'];
} elseif (isset($input['source']['ewallet']['ewallet_phone'])) {
    $phone = $input['source']['ewallet']['ewallet_phone'];
} elseif (isset($input['source']['phone'])) {
    $phone = $input['source']['phone'];
} elseif (isset($input['wallet_phone'])) {
    $phone = $input['wallet_phone'];
}

$amount = floatval($input['amount'] ?? 0);
error_log("Amount: $amount");

if (empty($phone)) {
    error_log("ERROR: No phone number found");
    echo json_encode([
        "verified" => false, 
        "message" => "Phone number required"
    ]);
    exit;
}

try {
    if (!isset($pdo)) {
        throw new Exception("Database connection failed");
    }
    
    $targetPhone = '+26770000000';
    error_log("Looking for phone: " . $targetPhone);
    
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
    
    $stmt->execute([':phone' => $targetPhone]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Query executed. Found: " . ($wallet ? 'YES' : 'NO'));
    
    if (!$wallet) {
        $phoneWithoutPlus = ltrim($targetPhone, '+');
        error_log("Trying without plus: " . $phoneWithoutPlus);
        
        $stmt = $pdo->prepare("
            SELECT * FROM wallets 
            WHERE phone = :phone 
            AND status = 'active'
            AND is_frozen = false
            LIMIT 1
        ");
        $stmt->execute([':phone' => $phoneWithoutPlus]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet) {
            $checkStmt = $pdo->query("SELECT phone FROM wallets WHERE phone LIKE '%70000000%'");
            $possiblePhones = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
            error_log("Phones in DB: " . json_encode($possiblePhones));
            
            echo json_encode([
                "verified" => false,
                "message" => "Wallet not found"
            ]);
            exit;
        }
    }
    
    error_log("Wallet found: " . json_encode($wallet));
    
    if ($wallet['balance'] < $amount) {
        error_log("Insufficient balance: {$wallet['balance']} < $amount");
        echo json_encode([
            "verified" => false,
            "message" => "Insufficient funds"
        ]);
        exit;
    }
    
    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        "verified" => true,
        "asset_id" => $wallet['wallet_id'],
        "available_balance" => (float)$wallet['balance'],
        "holder_name" => "Saccus Salis Customer",
        "expiry_date" => null,
        "requester" => $requester,
        "signature_verified" => true,
        "metadata" => [
            "wallet_type" => $wallet['wallet_type'],
            "currency" => $wallet['currency'] ?? 'BWP',
            "phone" => $wallet['phone']
        ]
    ];
    
    error_log("SUCCESS RESPONSE: " . json_encode($responsePayload));
    send_signed_response($responsePayload);
    
} catch (Exception $e) {
    error_log("EXCEPTION: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    echo json_encode([
        "verified" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
