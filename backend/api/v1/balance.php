<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/balance.php
// Get wallet or account balance

header('Content-Type: application/json');
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';

// Get parameters from GET request
$type = $_GET['type'] ?? $_POST['type'] ?? 'wallet'; // 'wallet' or 'account'
$identifier = $_GET['identifier'] ?? $_POST['identifier'] ?? null; // phone number or account number
$signature = $_GET['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? null;
$timestamp = $_GET['timestamp'] ?? $_SERVER['HTTP_X_TIMESTAMP'] ?? null;
$requester = $_GET['requester'] ?? $_SERVER['HTTP_X_REQUESTER'] ?? 'UNKNOWN';

if (!$identifier) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Identifier (phone or account number) required'
    ]);
    exit;
}

// ============================================================
// VERIFY SIGNATURE (Optional for balance queries)
// ============================================================
$signatureVerified = false;
if ($signature) {
    $payloadToVerify = [
        'type' => $type,
        'identifier' => $identifier
    ];
    $publicKey = get_requester_public_key($requester, $pdo);
    if ($publicKey) {
        $signatureVerified = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);
        if ($signatureVerified) {
            error_log("SACCUSSALIS BALANCE: Signature verified from {$requester}");
        } else {
            error_log("SACCUSSALIS BALANCE: Invalid signature from {$requester}");
        }
    }
}

try {
    $responseData = null;

    if (strtolower($type) === 'wallet') {
        // Normalize phone number (remove + if present)
        $normalizedPhone = ltrim($identifier, '+');
        
        // Query wallet by phone
        $stmt = $pdo->prepare("
            SELECT w.wallet_id, w.phone, w.balance, w.held_balance, w.currency, 
                   w.wallet_type, w.status, w.is_frozen, u.full_name, u.user_id
            FROM wallets w
            LEFT JOIN users u ON w.user_id = u.user_id
            WHERE w.phone = :phone OR w.phone = :phone_with_plus
            AND w.status = 'active'
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
                'message' => 'Wallet not found for phone: ' . $identifier
            ]);
            exit;
        }

        if ($wallet['is_frozen'] == 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Wallet is frozen'
            ]);
            exit;
        }

        $availableBalance = $wallet['balance'] - ($wallet['held_balance'] ?? 0);
        
        $responseData = [
            'status' => 'success',
            'type' => 'wallet',
            'wallet_id' => $wallet['wallet_id'],
            'holder_name' => $wallet['full_name'] ?? 'Wallet Holder',
            'phone' => $wallet['phone'],
            'balance' => (float)$wallet['balance'],
            'held_balance' => (float)($wallet['held_balance'] ?? 0),
            'available_balance' => (float)$availableBalance,
            'currency' => $wallet['currency'] ?? 'BWP',
            'wallet_type' => $wallet['wallet_type'] ?? 'EWALLET',
            'timestamp' => time()
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
                'message' => 'Account not found for number: ' . $identifier
            ]);
            exit;
        }

        if ($account['status'] !== 'active') {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Account is not active'
            ]);
            exit;
        }

        $responseData = [
            'status' => 'success',
            'type' => 'account',
            'account_id' => $account['account_id'],
            'account_number' => $account['account_number'],
            'account_name' => $account['account_name'],
            'holder_name' => $account['full_name'] ?? 'Account Holder',
            'balance' => (float)$account['balance'],
            'currency' => $account['currency'] ?? 'BWP',
            'account_type' => $account['account_type'] ?? 'SAVINGS',
            'timestamp' => time()
        ];

    } else {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid type. Use "wallet" or "account"'
        ]);
        exit;
    }

    // Add requester and signature verification info
    $responseData['requester'] = $requester;
    $responseData['signature_verified'] = $signatureVerified;

    // ============================================================
    // SEND SIGNED RESPONSE (Optional for queries but recommended)
    // ============================================================
    if ($signature && $signatureVerified) {
        send_signed_response($responseData);
    } else {
        // If no signature provided, still return data but without signature
        echo json_encode($responseData);
    }

} catch (Exception $e) {
    error_log("SACCUSSALIS BALANCE error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Balance check failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
