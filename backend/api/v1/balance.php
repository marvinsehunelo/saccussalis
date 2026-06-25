<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/balance.php
// SIMPLE BALANCE CHECK - Accept both GET and POST

header('Content-Type: application/json');
require_once __DIR__ . '/../../db.php';

// Get parameters from GET request OR POST JSON
$input = json_decode(file_get_contents('php://input'), true);
$type = $_GET['type'] ?? $_POST['type'] ?? $input['type'] ?? $input['asset_type'] ?? 'wallet';
$identifier = $_GET['identifier'] ?? $_POST['identifier'] ?? $input['source_identifier'] ?? $input['identifier'] ?? null;

if (!$identifier) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Identifier (phone or account number) required'
    ]);
    exit;
}

try {
    $responseData = null;

    if (strtolower($type) === 'wallet' || strtolower($type) === 'bank-wallet') {
        // Normalize phone number (remove + if present)
        $normalizedPhone = ltrim($identifier, '+');
        
        // Query wallet by phone
        $stmt = $pdo->prepare("
            SELECT w.wallet_id, w.phone, w.balance, w.held_balance, w.currency, 
                   w.wallet_type, w.status, w.is_frozen, u.full_name, u.user_id
            FROM wallets w
            LEFT JOIN users u ON w.user_id = u.user_id
            WHERE w.phone = :phone OR w.phone = :phone_with_plus
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
                'message' => 'Wallet not found for phone: ' . $identifier,
                'timestamp' => time()
            ]);
            exit;
        }

        if ($wallet['is_frozen'] == 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Wallet is frozen',
                'timestamp' => time()
            ]);
            exit;
        }

        $availableBalance = $wallet['balance'] - ($wallet['held_balance'] ?? 0);
        
        $responseData = [
            'status' => 'success',
            'verified' => true,
            'data' => [
                'wallet_id' => $wallet['wallet_id'],
                'phone' => $wallet['phone'],
                'balance' => (float)$wallet['balance'],
                'held_balance' => (float)($wallet['held_balance'] ?? 0),
                'available_balance' => (float)$availableBalance,
                'currency' => $wallet['currency'] ?? 'BWP',
                'wallet_type' => $wallet['wallet_type'] ?? 'EWALLET',
                'holder_name' => $wallet['full_name'] ?? 'Wallet Holder',
                'status' => $wallet['status'],
                'timestamp' => time()
            ]
        ];

    } elseif (strtolower($type) === 'account') {
        // Query bank account by account number
        $stmt = $pdo->prepare("
            SELECT a.account_id, a.account_number, a.account_name, a.balance, a.currency, 
                   a.status, a.account_type, u.full_name, u.user_id
            FROM accounts a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE a.account_number = :account_number
            LIMIT 1
        ");
        $stmt->execute([':account_number' => $identifier]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Account not found for number: ' . $identifier,
                'timestamp' => time()
            ]);
            exit;
        }

        if ($account['status'] !== 'active') {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Account is not active',
                'timestamp' => time()
            ]);
            exit;
        }

        $responseData = [
            'status' => 'success',
            'verified' => true,
            'data' => [
                'account_id' => $account['account_id'],
                'account_number' => $account['account_number'],
                'account_name' => $account['account_name'],
                'balance' => (float)$account['balance'],
                'available_balance' => (float)$account['balance'],
                'currency' => $account['currency'] ?? 'BWP',
                'account_type' => $account['account_type'] ?? 'SAVINGS',
                'holder_name' => $account['full_name'] ?? 'Account Holder',
                'status' => $account['status'],
                'timestamp' => time()
            ]
        ];

    } else {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid type. Use "wallet" or "account"',
            'timestamp' => time()
        ]);
        exit;
    }

    echo json_encode($responseData);

} catch (Exception $e) {
    error_log("SACCUSSALIS BALANCE error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Balance check failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
