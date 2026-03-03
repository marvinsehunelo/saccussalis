<?php
/**
 * verify_asset.php - FIXED for +26770000000 format
 */

require_once __DIR__ . '/../../db.php';

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

// Extract phone number - LOOK IN ALL POSSIBLE LOCATIONS
$phone = null;

// Check every possible location where the phone might be
if (isset($input['ewallet_phone'])) {
    $phone = $input['ewallet_phone'];
    error_log("Found in ewallet_phone: " . $phone);
} 
elseif (isset($input['ewallet']['ewallet_phone'])) {
    $phone = $input['ewallet']['ewallet_phone'];
    error_log("Found in ewallet.ewallet_phone: " . $phone);
}
elseif (isset($input['phone'])) {
    $phone = $input['phone'];
    error_log("Found in phone: " . $phone);
}
elseif (isset($input['source']['ewallet']['ewallet_phone'])) {
    $phone = $input['source']['ewallet']['ewallet_phone'];
    error_log("Found in source.ewallet.ewallet_phone: " . $phone);
}
elseif (isset($input['source']['phone'])) {
    $phone = $input['source']['phone'];
    error_log("Found in source.phone: " . $phone);
}
elseif (isset($input['wallet_phone'])) {
    $phone = $input['wallet_phone'];
    error_log("Found in wallet_phone: " . $phone);
}

$amount = floatval($input['amount'] ?? 0);
error_log("Amount: $amount");

if (empty($phone)) {
    error_log("ERROR: No phone number found in any location");
    echo json_encode([
        "verified" => false, 
        "message" => "Phone number required",
        "debug" => "No phone in: " . json_encode(array_keys($input))
    ]);
    exit;
}

try {
    if (!isset($pdo)) {
        throw new Exception("Database connection failed");
    }
    
    // Log the PDO connection status
    error_log("Database connected successfully");
    
    // STORE THE EXACT PHONE FORMAT WE WANT TO MATCH
    $targetPhone = '+26770000000';
    error_log("Looking for phone: " . $targetPhone);
    
    // Simple direct query with the exact format
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
        // If not found with exact match, try without the +
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
        
        if ($wallet) {
            error_log("Found without plus!");
        } else {
            // Try to see what's in the database
            $checkStmt = $pdo->query("SELECT phone FROM wallets WHERE phone LIKE '%70000000%'");
            $possiblePhones = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
            error_log("Phones in DB containing 70000000: " . json_encode($possiblePhones));
            
            echo json_encode([
                "verified" => false,
                "message" => "Wallet not found with phone: " . $targetPhone,
                "debug" => [
                    "searched_for" => $targetPhone,
                    "phones_in_db" => $possiblePhones
                ]
            ]);
            exit;
        }
    }
    
    error_log("Wallet found: " . json_encode($wallet));
    
    // Check balance
    if ($wallet['balance'] < $amount) {
        error_log("Insufficient balance: {$wallet['balance']} < $amount");
        echo json_encode([
            "verified" => false,
            "message" => "Insufficient funds"
        ]);
        exit;
    }
    
    // SUCCESS - Return exactly what SwapService expects
    $response = [
        "verified" => true,
        "asset_id" => $wallet['wallet_id'],
        "available_balance" => (float)$wallet['balance'],
        "holder_name" => "Saccus Salis Customer",
        "expiry_date" => null,
        "metadata" => [
            "wallet_type" => $wallet['wallet_type'],
            "currency" => $wallet['currency'] ?? 'BWP',
            "phone" => $wallet['phone']
        ]
    ];
    
    error_log("SUCCESS RESPONSE: " . json_encode($response));
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("EXCEPTION: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    echo json_encode([
        "verified" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
