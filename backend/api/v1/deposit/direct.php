<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/deposit/direct/index.php

header('Content-Type: application/json');
require_once '../../../db.php';
require_once '../../../middleware/Idempotency.php';
require_once '../../../helpers/crypto.php';
require_once '../../../helpers/CertificateManager.php';

$input = json_decode(file_get_contents("php://input"), true);

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($input['certificate'])) {
    error_log("SACCUSSALIS DEPOSIT: No certificate provided");
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('SACCUSSALIS');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("SACCUSSALIS DEPOSIT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("SACCUSSALIS DEPOSIT: Requester: {$requester}");

if (!$isValid) {
    error_log("SACCUSSALIS DEPOSIT: Certificate verification failed");
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("SACCUSSALIS DEPOSIT: Request verified from {$requester} using certificate");

// ============================================================
// PROCESS DEPOSIT
// ============================================================

$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $input['request_id'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Idempotency key required']);
    exit;
}

Idempotency::check($idempotencyKey);

$depositRef = $input['reference'] ?? $input['depositRef'] ?? null;
if (!$depositRef || !isset($input['amount'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: reference, amount']);
    exit;
}

// Determine deposit type: account or wallet
$accountNumber = $input['account_number'] ?? null;
$walletPhone = $input['wallet_phone'] ?? $input['phone'] ?? $input['beneficiary_phone'] ?? null;

if (!$accountNumber && !$walletPhone) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Missing required fields: either account_number or wallet_phone must be provided'
    ]);
    exit;
}

// Initialize variables
$reference = null;
$transactionId = null;
$newBalance = null;
$recipientType = null;
$recipientId = null;
$recipientIdentifier = null;

try {
    $pdo->beginTransaction();

    if ($accountNumber) {
        // ============================================================
        // ACCOUNT DEPOSIT
        // ============================================================
        $recipientType = 'ACCOUNT';
        $recipientIdentifier = $accountNumber;

        // Find account with lock
        $stmt = $pdo->prepare("SELECT account_id, user_id, balance, status FROM accounts WHERE account_number = ? FOR UPDATE");
        $stmt->execute([$accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception("Account not found for number: " . $accountNumber);
        }

        if ($account['status'] !== 'active') {
            throw new Exception("Account is not active. Status: " . $account['status']);
        }

        $recipientId = $account['account_id'];

        // Credit account
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE account_number = ?");
        $stmt->execute([$input['amount'], $accountNumber]);

        // Get new balance
        $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_number = ?");
        $stmt->execute([$accountNumber]);
        $newBalance = $stmt->fetchColumn();

        // Create transaction record
        $reference = 'DEP_' . time() . '_' . rand(100, 999);
        $stmt = $pdo->prepare("
            INSERT INTO transactions 
                (user_id, reference, to_account, amount, type, status, 
                 requester, signature_verified, channel, notes, created_at, updated_at)
            VALUES 
                (?, ?, ?, ?, 'deposit', 'completed', ?, ?, 'direct_deposit', ?, NOW(), NOW())
            RETURNING transaction_id
        ");
        $stmt->execute([
            $account['user_id'], 
            $reference, 
            $accountNumber, 
            $input['amount'],
            $requester,
            $isValid ? 1 : 0,
            json_encode([
                'deposit_ref' => $depositRef,
                'source' => $requester,
                'recipient_type' => 'ACCOUNT',
                'timestamp' => time()
            ])
        ]);
        $transactionId = $stmt->fetchColumn();

        // Create ledger entry
        $stmt = $pdo->prepare("
            INSERT INTO ledger_entries 
                (reference, debit_account, credit_account, amount, currency, notes, 
                 requester, signature_verified, created_at)
            VALUES 
                (?, 'SETTLEMENT_SUSPENSE', ?, ?, 'BWP', 'Direct deposit to account from ' || ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $reference, 
            $accountNumber, 
            $input['amount'],
            $requester,
            $requester,
            $isValid ? 1 : 0
        ]);

        // Create settlement record
        $stmt = $pdo->prepare("
            INSERT INTO settlements 
                (settlement_ref, type, amount, recipient_type, recipient_id, status, 
                 requester, signature_verified, created_at, updated_at)
            VALUES 
                (?, 'DIRECT_DEPOSIT', ?, 'ACCOUNT', ?, 'completed', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $reference,
            $input['amount'],
            $recipientId,
            $requester,
            $isValid ? 1 : 0
        ]);

        error_log("SACCUSSALIS DEPOSIT: Account deposit completed - Ref: {$reference}, Amount: {$input['amount']}, Account: {$accountNumber}");

    } else if ($walletPhone) {
        // ============================================================
        // WALLET DEPOSIT
        // ============================================================
        $recipientType = 'WALLET';
        $recipientIdentifier = $walletPhone;

        // Find wallet with lock
        $stmt = $pdo->prepare("SELECT wallet_id, user_id, balance, status, held_balance FROM wallets WHERE phone = ? FOR UPDATE");
        $stmt->execute([$walletPhone]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            throw new Exception("Wallet not found for phone: " . $walletPhone);
        }

        if ($wallet['status'] !== 'active') {
            throw new Exception("Wallet is not active. Status: " . $wallet['status']);
        }

        $recipientId = $wallet['wallet_id'];

        // Credit wallet
        $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ?, updated_at = NOW() WHERE phone = ?");
        $stmt->execute([$input['amount'], $walletPhone]);

        // Get new balance
        $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE phone = ?");
        $stmt->execute([$walletPhone]);
        $newBalance = $stmt->fetchColumn();

        // Create transaction record
        $reference = 'DEP_W_' . time() . '_' . rand(100, 999);
        $stmt = $pdo->prepare("
            INSERT INTO wallet_transactions 
                (wallet_id, transaction_type, amount, balance_after, reference, description, status, created_at)
            VALUES 
                (?, 'deposit', ?, ?, ?, ?, 'completed', NOW())
            RETURNING transaction_id
        ");
        $stmt->execute([
            $wallet['wallet_id'],
            $input['amount'],
            $newBalance,
            $reference,
            'Direct wallet deposit from ' . $requester
        ]);
        $transactionId = $stmt->fetchColumn();

        // Create ledger entry
        $stmt = $pdo->prepare("
            INSERT INTO ledger_entries 
                (reference, debit_account, credit_account, amount, currency, notes, 
                 requester, signature_verified, created_at)
            VALUES 
                (?, 'SETTLEMENT_SUSPENSE', ?, ?, 'BWP', 'Direct deposit to wallet from ' || ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $reference, 
            'WALLET_' . $wallet['wallet_id'], 
            $input['amount'],
            $requester,
            $requester,
            $isValid ? 1 : 0
        ]);

        // Create settlement record
        $stmt = $pdo->prepare("
            INSERT INTO settlements 
                (settlement_ref, type, amount, recipient_type, recipient_id, status, 
                 requester, signature_verified, created_at, updated_at)
            VALUES 
                (?, 'DIRECT_DEPOSIT', ?, 'WALLET', ?, 'completed', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $reference,
            $input['amount'],
            $recipientId,
            $requester,
            $isValid ? 1 : 0
        ]);

        error_log("SACCUSSALIS DEPOSIT: Wallet deposit completed - Ref: {$reference}, Amount: {$input['amount']}, Wallet Phone: {$walletPhone}, Wallet ID: {$wallet['wallet_id']}");
    }

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'transaction_ref' => $reference,
        'transaction_id' => $transactionId,
        'credited' => true,
        'amount' => (float)$input['amount'],
        'new_balance' => (float)$newBalance,
        'transaction_reference' => $reference,
        'recipient_type' => $recipientType,
        'recipient_identifier' => $recipientIdentifier,
        'recipient_id' => $recipientId,
        'deposit_ref' => $depositRef,
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'timestamp' => time()
    ];

    if ($recipientType === 'ACCOUNT') {
        $responsePayload['account_number'] = $recipientIdentifier;
    } else {
        $responsePayload['wallet_phone'] = $recipientIdentifier;
        // Generate PIN for wallet deposit (optional - for ATM/agent use)
        if (isset($input['generate_pin']) && $input['generate_pin'] === true) {
            $pin = rand(100000, 999999);
            $responsePayload['pin'] = $pin;
            $responsePayload['pin_expires_at'] = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Store PIN
            $stmt = $pdo->prepare("
                INSERT INTO ewallet_pins 
                    (wallet_id, pin_code, expires_at, created_at, status)
                VALUES 
                    (?, ?, ?, NOW(), 'active')
            ");
            $stmt->execute([$recipientId, password_hash($pin, PASSWORD_DEFAULT), date('Y-m-d H:i:s', strtotime('+15 minutes'))]);
        }
    }

    Idempotency::store($idempotencyKey, $responsePayload);
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS DEPOSIT ERROR: " . $e->getMessage());
    error_log("SACCUSSALIS DEPOSIT Input: " . json_encode($input ?? []));
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Deposit failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
