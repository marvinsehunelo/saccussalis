<?php
// backend/api/v1/hold.php - CERTIFICATE-BASED VERIFICATION

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../../src/Infrastructure/Crypto/CertificateManager.php';

use Infrastructure\Crypto\CertificateManager;

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("=== SACCUSSALIS HOLD.PHP RECEIVED === " . json_encode($input));

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION (Visa/Mastercard Model)
    // ============================================================
    
    $certManager = new CertificateManager('SACCUSSALIS');
    
    $isValid = false;
    $requester = $input['requester'] ?? 'UNKNOWN';
    
    // Try certificate-based verification first (preferred)
    if (isset($input['certificate'])) {
        $verification = $certManager->verifySignedRequest($input);
        $isValid = $verification['verified'];
        $requester = $verification['requester'];
        
        error_log("Certificate verification result: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
        
        if (!$isValid) {
            echo json_encode([
                'status' => 'ERROR',
                'hold_placed' => false,
                'debited' => false,
                'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
            ]);
            exit;
        }
    } 
    // Fallback to legacy signature verification (for backward compatibility)
    else {
        $signature = $input['signature'] ?? null;
        $timestamp = $input['timestamp'] ?? null;
        
        if (!$signature) {
            echo json_encode([
                'status' => 'ERROR',
                'hold_placed' => false,
                'message' => 'Missing signature - no certificate provided'
            ]);
            exit;
        }
        
        $publicKey = get_requester_public_key($requester, $pdo);
        if (!$publicKey) {
            echo json_encode(['status' => 'ERROR', 'message' => "No public key for {$requester}"]);
            exit;
        }
        
        $payloadToVerify = $input;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['requester']);
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        $keyResource = openssl_pkey_get_public($publicKey);
        
        $isValid = (openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256) === 1);
        
        if (!$isValid) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Invalid signature']);
            exit;
        }
    }
    
    error_log("SACCUSSALIS HOLD: Request verified from {$requester}");

    // ============================================================
    // PROCESS HOLD
    // ============================================================

    $action = strtoupper(trim($input['action'] ?? $input['type'] ?? 'PLACE_HOLD'));
    $amount = floatval($input['amount'] ?? $input['value'] ?? 0);
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? uniqid('HOLD-');
    $foreignBank = $input['foreign_bank'] ?? $input['destination_institution'] ?? $input['destination'] ?? $input['beneficiary_bank'] ?? null;
    $sessionId = trim($input['session_id'] ?? $input['reference'] ?? uniqid('SESSION-'));
    
    $phone = $input['phone'] ?? $input['wallet_phone'] ?? null;
    $accountNumber = $input['account_number'] ?? null;
    $voucherNumber = $input['voucher_number'] ?? null;
    $cardNumber = $input['card_number'] ?? null;
    
    error_log("[HOLD.PHP DEBUG] Action: $action, Amount: $amount, Phone: $phone, ForeignBank: $foreignBank");

    if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
        if (!$phone && !$accountNumber && !$voucherNumber && !$cardNumber) {
            throw new Exception("No identifier found");
        }
        if ($amount <= 0) {
            throw new Exception("Valid amount required");
        }
        if (!$foreignBank) {
            $foreignBank = 'UNKNOWN';
        }
    }
    
    $pdo->beginTransaction();

    // Find wallet
    $wallet = null;
    if ($phone) {
        $stmt = $pdo->prepare("SELECT wallet_id, balance, held_balance FROM wallets WHERE phone = ? AND status = 'active' AND is_frozen = false FOR UPDATE");
        $stmt->execute([$phone]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($accountNumber) {
        $stmt = $pdo->prepare("SELECT w.wallet_id, w.balance, w.held_balance FROM wallets w JOIN accounts a ON a.user_id = w.user_id WHERE a.account_number = ? AND w.status = 'active' AND w.is_frozen = false FOR UPDATE");
        $stmt->execute([$accountNumber]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$wallet) {
        throw new Exception("Wallet not found");
    }

    if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
        $availableBalance = $wallet['balance'] - ($wallet['held_balance'] ?? 0);
        if ($availableBalance < $amount) {
            throw new Exception("Insufficient available balance");
        }

        $stmt = $pdo->prepare("UPDATE wallets SET held_balance = COALESCE(held_balance,0) + ? WHERE wallet_id = ?");
        $stmt->execute([$amount, $wallet['wallet_id']]);

        $stmt = $pdo->prepare("
            INSERT INTO financial_holds 
                (wallet_id, amount, hold_reference, foreign_bank, session_id, status, 
                 requester, signature_verified, expires_at, created_at)
            VALUES 
                (?, ?, ?, ?, ?, 'HELD', ?, ?, NOW() + INTERVAL '24 hours', NOW())
            RETURNING id
        ");
        $stmt->execute([
            $wallet['wallet_id'], 
            $amount, 
            $holdReference, 
            $foreignBank, 
            $sessionId,
            $requester,
            $isValid ? 1 : 0
        ]);
        $holdId = $stmt->fetchColumn();

        $message = "Hold placed successfully";
        $holdPlaced = true;

    } elseif (in_array($action, ['RELEASE_HOLD', 'RELEASE', 'REVERSE'])) {
        $stmt = $pdo->prepare("SELECT id, wallet_id, amount FROM financial_holds WHERE hold_reference = ? AND status = 'HELD' FOR UPDATE");
        $stmt->execute([$holdReference]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hold) throw new Exception("Hold not found");

        $stmt = $pdo->prepare("UPDATE financial_holds SET status = 'RELEASED', released_at = NOW() WHERE id = ?");
        $stmt->execute([$hold['id']]);

        $stmt = $pdo->prepare("UPDATE wallets SET held_balance = GREATEST(COALESCE(held_balance,0) - ?, 0) WHERE wallet_id = ?");
        $stmt->execute([$amount, $wallet['wallet_id']]);

        $message = "Hold released successfully";
        $holdPlaced = false;
        
    } elseif (in_array($action, ['DEBIT_FUNDS', 'DEBIT', 'COMMIT'])) {
        $stmt = $pdo->prepare("SELECT id, wallet_id, amount FROM financial_holds WHERE hold_reference = ? AND status = 'HELD' FOR UPDATE");
        $stmt->execute([$holdReference]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hold) throw new Exception("Hold not found");

        $inputAmount = $amount > 0 ? $amount : $hold['amount'];
        $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ?, held_balance = GREATEST(COALESCE(held_balance,0) - ?, 0) WHERE wallet_id = ?");
        $stmt->execute([$inputAmount, $inputAmount, $wallet['wallet_id']]);

        $stmt = $pdo->prepare("UPDATE financial_holds SET status = 'DEBITED', debited_at = NOW(), debited_by = ? WHERE id = ?");
        $stmt->execute([$requester, $hold['id']]);

        $message = "Funds debited successfully";
        $holdPlaced = false;
        
    } else {
        throw new Exception("Unsupported action: $action");
    }

    // Get updated wallet balance
    $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ?");
    $stmt->execute([$wallet['wallet_id']]);
    $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'SUCCESS',
        'hold_placed' => isset($holdPlaced) ? $holdPlaced : ($action === 'DEBIT_FUNDS' ? false : true),
        'hold_reference' => $holdReference,
        'session_id' => $sessionId,
        'message' => $message,
        'debited' => $action === 'DEBIT_FUNDS',
        'new_balance' => $updatedWallet['balance'] - $updatedWallet['held_balance'],
        'held_balance' => $updatedWallet['held_balance'],
        'available_balance' => $updatedWallet['balance'] - $updatedWallet['held_balance'],
        'requester' => $requester,
        'signature_verified' => $isValid
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("SACCUSSALIS Hold.php ERROR: " . $e->getMessage());
    
    echo json_encode([
        'status' => 'ERROR',
        'hold_placed' => false,
        'debited' => false,
        'message' => 'Bank communication failed',
        'reason' => $e->getMessage()
    ]);
    http_response_code(400);
}
