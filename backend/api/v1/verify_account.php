<?php
/**
 * verify_account.php - SACCUSSALIS VERSION
 * Account/Wallet verification - checks if account or wallet exists
 * 
 * Supports:
 * - ACCOUNT: Checks accounts table
 * - WALLET: Checks wallets table and user details
 * 
 * FIXED: 
 * - Removed a.status (column doesn't exist in accounts table)
 * - Added u.status as user_status for user account status
 * - Fully qualified column names with table aliases
 */

require_once __DIR__ . '/../../db.php';

header("Content-Type: application/json");

error_log("=== verify_account.php CALLED ===");

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
error_log("verify_account input: " . json_encode($input));

if (!$input) {
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "Invalid JSON input"
    ]);
    exit;
}

// Get account identifier from various possible locations
$identifier = $input['account_identifier'] ?? 
              $input['destination_identifier'] ?? 
              $input['identifier'] ?? 
              $input['account_number'] ?? 
              $input['phone'] ?? 
              $input['email'] ?? 
              null;

// Get identifier type
$identifierType = strtolower($input['identifier_type'] ?? 
                             $input['destination_identifier_type'] ?? 
                             'account');

// Get destination asset type
$assetType = strtoupper($input['destination_asset_type'] ?? 
                         $input['asset_type'] ?? 
                         'ACCOUNT');

if (!$identifier) {
    error_log("verify_account error: No identifier provided");
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => "Account or wallet identifier required"
    ]);
    exit;
}

error_log("Checking identifier: {$identifier} (type: {$identifierType}, asset_type: {$assetType})");

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed");
    }

    // ============================================================
    // HANDLE WALLET VERIFICATION
    // ============================================================
    if ($assetType === 'WALLET') {
        error_log("Verifying WALLET: {$identifier}");
        
        // Build the WHERE clause based on identifier type
        $whereClause = '';
        $params = [];
        
        if ($identifierType === 'phone' || $identifierType === 'msisdn') {
            $cleanPhone = preg_replace('/[^0-9]/', '', $identifier);
            $whereClause = "w.phone = :identifier OR REPLACE(w.phone, '+', '') = :clean_phone";
            $params['identifier'] = $identifier;
            $params['clean_phone'] = $cleanPhone;
        } elseif ($identifierType === 'email' || $identifierType === 'email_address') {
            $whereClause = "u.email = :identifier";
            $params['identifier'] = $identifier;
        } elseif ($identifierType === 'national_id' || $identifierType === 'national_id_number') {
            $whereClause = "u.national_id = :identifier";
            $params['identifier'] = $identifier;
        } else {
            $cleanPhone = preg_replace('/[^0-9]/', '', $identifier);
            $whereClause = "w.phone = :identifier OR REPLACE(w.phone, '+', '') = :clean_phone OR u.email = :identifier";
            $params['identifier'] = $identifier;
            $params['clean_phone'] = $cleanPhone;
        }
        
        // Check if wallet exists
        $stmt = $pdo->prepare("
            SELECT 
                w.wallet_id,
                w.user_id,
                w.phone,
                w.wallet_type,
                w.currency,
                w.balance,
                w.held_balance,
                w.is_frozen,
                w.status,
                w.created_at,
                w.updated_at,
                u.full_name,
                u.email as user_email,
                u.phone as user_phone,
                u.kyc_status,
                u.status as user_status
            FROM wallets w
            LEFT JOIN users u ON w.user_id = u.user_id
            WHERE {$whereClause}
            LIMIT 1
        ");
        $stmt->execute($params);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet) {
            error_log("Wallet not found: {$identifier}");
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Wallet not found for identifier: {$identifier}",
                "debug" => [
                    "identifier" => $identifier,
                    "identifier_type" => $identifierType,
                    "asset_type" => $assetType
                ]
            ]);
            exit;
        }
        
        error_log("Wallet found: " . $wallet['wallet_id'] . " (User: " . ($wallet['user_id'] ?? 'unknown') . ")");
        
        // Check if wallet is frozen
        if ($wallet['is_frozen'] == true) {
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Wallet is frozen",
                "wallet_id" => $wallet['wallet_id']
            ]);
            exit;
        }
        
        // Check if wallet is active
        if ($wallet['status'] !== 'active') {
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "Wallet is not active (status: {$wallet['status']})",
                "wallet_id" => $wallet['wallet_id']
            ]);
            exit;
        }
        
        // Check if user is active
        if ($wallet['user_status'] !== null && $wallet['user_status'] !== 'active') {
            echo json_encode([
                "success" => false,
                "verified" => false,
                "message" => "User account is not active (status: {$wallet['user_status']})",
                "wallet_id" => $wallet['wallet_id'],
                "user_id" => $wallet['user_id']
            ]);
            exit;
        }
        
        // Wallet exists and is valid
        $response = [
            "success" => true,
            "verified" => true,
            "asset_type" => "WALLET",
            "wallet_id" => $wallet['wallet_id'],
            "user_id" => $wallet['user_id'],
            "phone" => $wallet['phone'],
            "wallet_type" => $wallet['wallet_type'] ?? 'standard',
            "currency" => $wallet['currency'] ?? 'BWP',
            "balance" => (float)$wallet['balance'],
            "held_balance" => (float)$wallet['held_balance'],
            "is_frozen" => (bool)$wallet['is_frozen'],
            "status" => $wallet['status'],
            "holder_name" => $wallet['full_name'] ?? $wallet['user_email'] ?? 'Wallet Holder',
            "holder_email" => $wallet['user_email'],
            "holder_phone" => $wallet['user_phone'],
            "kyc_status" => $wallet['kyc_status'] ?? 'pending',
            "created_at" => $wallet['created_at'],
            "updated_at" => $wallet['updated_at'],
            "message" => "Wallet verified successfully"
        ];
        
        error_log("Wallet verification successful: " . $wallet['wallet_id']);
        echo json_encode($response);
        exit;
    }
    
    // ============================================================
    // HANDLE ACCOUNT VERIFICATION (Default)
    // ============================================================
    error_log("Verifying ACCOUNT: {$identifier}");
    
    // ✅ FIXED: Removed a.status (column doesn't exist in accounts table)
    // Added u.status as user_status to check user account status
    $stmt = $pdo->prepare("
        SELECT 
            a.account_id,
            a.account_number,
            a.account_type,
            a.currency,
            a.balance,
            a.is_frozen,
            a.created_at,
            a.updated_at,
            u.full_name,
            u.email as user_email,
            u.phone as user_phone,
            u.kyc_status,
            u.status as user_status
        FROM accounts a
        LEFT JOIN users u ON a.user_id = u.user_id
        WHERE a.account_number = :identifier
        LIMIT 1
    ");
    $stmt->execute(['identifier' => $identifier]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        error_log("Account not found: {$identifier}");
        echo json_encode([
            "success" => false,
            "verified" => false,
            "message" => "Account not found: {$identifier}",
            "debug" => [
                "identifier" => $identifier,
                "identifier_type" => $identifierType,
                "asset_type" => $assetType
            ]
        ]);
        exit;
    }
    
    error_log("Account found: " . $account['account_number']);
    
    // Check if account is frozen
    if ($account['is_frozen'] == true) {
        echo json_encode([
            "success" => false,
            "verified" => false,
            "message" => "Account is frozen",
            "account_id" => $account['account_id']
        ]);
        exit;
    }
    
    // ✅ FIXED: Check user status instead of account status
    // Accounts table doesn't have a status column, so we check user status
    if ($account['user_status'] !== null && $account['user_status'] !== 'active') {
        echo json_encode([
            "success" => false,
            "verified" => false,
            "message" => "User account is not active (status: {$account['user_status']})",
            "account_id" => $account['account_id'],
            "user_id" => $account['user_id'] ?? 'unknown'
        ]);
        exit;
    }
    
    // Account exists and is valid
    // Accounts are considered active by default since there's no status column
    $response = [
        "success" => true,
        "verified" => true,
        "asset_type" => "ACCOUNT",
        "account_id" => $account['account_id'],
        "account_number" => $account['account_number'],
        "account_type" => $account['account_type'] ?? 'checking',
        "currency" => $account['currency'] ?? 'BWP',
        "balance" => (float)$account['balance'],
        "held_balance" => (float)($account['held_balance'] ?? 0),
        "is_frozen" => (bool)$account['is_frozen'],
        "status" => 'active', // Accounts are active by default
        "holder_name" => $account['full_name'] ?? 'Account Holder',
        "holder_email" => $account['user_email'],
        "holder_phone" => $account['user_phone'],
        "kyc_status" => $account['kyc_status'] ?? 'pending',
        "created_at" => $account['created_at'],
        "updated_at" => $account['updated_at'],
        "message" => "Account verified successfully"
    ];
    
    error_log("Account verification successful: " . $account['account_id']);
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("verify_account error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    echo json_encode([
        "success" => false,
        "verified" => false,
        "message" => $e->getMessage()
    ]);
}
?>
