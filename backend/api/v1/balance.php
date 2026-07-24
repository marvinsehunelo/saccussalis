<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/balance.php
// SACCUSSALIS BALANCE CHECK - Accounts + Wallets (NO MOCKS)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY, X-Correlation-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../db.php';

// ============================================================
// 1. AUTHENTICATION
// ============================================================
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
$expectedApiKey = getenv('SACCUSSALIS_API_KEY') ?: 'saccussalis_live_3uV4wX5yZ6aB7cD8';

if (!$apiKey || $apiKey !== $expectedApiKey) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid API key',
        'timestamp' => time()
    ]);
    exit();
}

// ============================================================
// 2. GET INPUT
// ============================================================
$input = json_decode(file_get_contents('php://input'), true);
$type = $_GET['type'] ?? $_POST['type'] ?? $input['type'] ?? $input['asset_type'] ?? 'wallet';
$identifier = $_GET['identifier'] ?? $_POST['identifier'] ?? $input['source_identifier'] ?? $input['identifier'] ?? null;

if (!$identifier) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Identifier (phone or account number) required',
        'timestamp' => time()
    ]);
    exit();
}

// ============================================================
// 3. GET BALANCE - NO MOCKS
// ============================================================
try {
    $responseData = null;
    $typeLower = strtolower($type);

    // ============================================================
    // CHECK WALLET (for phone numbers)
    // ============================================================
    if ($typeLower === 'wallet' || $typeLower === 'bank-wallet' || $typeLower === 'mno-wallet') {
        // Normalize phone number (remove + if present)
        $normalizedPhone = ltrim($identifier, '+');
        
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
            WHERE phone = :phone OR phone = :phone_with_plus
            LIMIT 1
        ");
        $stmt->execute([
            ':phone' => $normalizedPhone,
            ':phone_with_plus' => $identifier
        ]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Wallet not found for: ' . $identifier,
                'timestamp' => time()
            ]);
            exit();
        }

        if ($wallet['is_frozen'] == 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Wallet is frozen',
                'timestamp' => time()
            ]);
            exit();
        }

        $balance = (float)$wallet['balance'];
        $heldBalance = (float)($wallet['held_balance'] ?? 0);
        $availableBalance = $balance - $heldBalance;

        $responseData = [
            'status' => 'success',
            'verified' => true,
            'data' => [
                'wallet_id' => (int)$wallet['wallet_id'],
                'user_id' => (int)$wallet['user_id'],
                'phone' => $wallet['phone'],
                'wallet_type' => $wallet['wallet_type'] ?? 'EWALLET',
                'balance' => $balance,
                'held_balance' => $heldBalance,
                'available_balance' => $availableBalance,
                'currency' => $wallet['currency'] ?? 'BWP',
                'is_frozen' => (bool)$wallet['is_frozen'],
                'status' => $wallet['status'] ?? 'active',
                'created_at' => $wallet['created_at'],
                'updated_at' => $wallet['updated_at'],
                'timestamp' => time()
            ],
            'requester' => 'SACCUSSALIS',
            'verification_method' => 'database'
        ];

    // ============================================================
    // CHECK ACCOUNT (NO status column - uses is_frozen)
    // ============================================================
    } elseif ($typeLower === 'account') {
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
        $stmt->execute([':account_number' => $identifier]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Account not found for: ' . $identifier,
                'timestamp' => time()
            ]);
            exit();
        }

        // Accounts use is_frozen instead of status
        if ($account['is_frozen'] == 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Account is frozen',
                'timestamp' => time()
            ]);
            exit();
        }

        $balance = (float)$account['balance'];
        $heldBalance = (float)($account['held_balance'] ?? 0);
        $availableBalance = $balance - $heldBalance;

        $responseData = [
            'status' => 'success',
            'verified' => true,
            'data' => [
                'account_id' => (int)$account['account_id'],
                'user_id' => (int)$account['user_id'],
                'account_number' => $account['account_number'],
                'account_type' => $account['account_type'] ?? 'ACCOUNT',
                'balance' => $balance,
                'held_balance' => $heldBalance,
                'available_balance' => $availableBalance,
                'currency' => $account['currency'] ?? 'BWP',
                'is_frozen' => (bool)$account['is_frozen'],
                'created_at' => $account['created_at'],
                'updated_at' => $account['updated_at'],
                'timestamp' => time()
            ],
            'requester' => 'SACCUSSALIS',
            'verification_method' => 'database'
        ];

    } else {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid type. Use "wallet" or "account"',
            'timestamp' => time()
        ]);
        exit();
    }

    echo json_encode($responseData);

} catch (PDOException $e) {
    error_log("SACCUSSALIS BALANCE PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    error_log("SACCUSSALIS BALANCE Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Balance check failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
