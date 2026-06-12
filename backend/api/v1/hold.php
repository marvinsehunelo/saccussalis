<?php
// backend/api/v1/hold.php - CERTIFICATE-ONLY VERIFICATION

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("=== SACCUSSALIS HOLD.PHP RECEIVED === " . json_encode($input));

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION (REQUIRED)
    // ============================================================
    
    if (!isset($input['certificate'])) {
        error_log("SACCUSSALIS HOLD: No certificate provided");
        echo json_encode([
            'status' => 'ERROR',
            'hold_placed' => false,
            'message' => 'Certificate required'
        ]);
        exit;
    }
    
    $certManager = new CertificateManager('SACCUSSALIS');
    $verification = $certManager->verifySignedRequest($input);
    $isValid = $verification['verified'];
    $requester = $verification['requester'];
    
    error_log("Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    
    if (!$isValid) {
        echo json_encode([
            'status' => 'ERROR',
            'hold_placed' => false,
            'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown')
        ]);
        exit;
    }
    
    error_log("SACCUSSALIS HOLD: Verified from {$requester}");

    // ============================================================
    // PROCESS HOLD
    // ============================================================

    $action = strtoupper(trim($input['action'] ?? 'PLACE_HOLD'));
    $amount = floatval($input['amount'] ?? 0);
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? uniqid('HOLD-');
    $foreignBank = $input['foreign_bank'] ?? $input['destination_institution'] ?? 'UNKNOWN';
    $sessionId = trim($input['session_id'] ?? $input['reference'] ?? uniqid('SESSION-'));
    
    $phone = $input['phone'] ?? $input['wallet_phone'] ?? null;
    $accountNumber = $input['account_number'] ?? null;

    if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
        if (!$phone && !$accountNumber) {
            throw new Exception("No identifier found");
        }
        if ($amount <= 0) {
            throw new Exception("Valid amount required");
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
            throw new Exception("Insufficient balance");
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
        
    } elseif (in_array($action, ['RELEASE_HOLD', 'RELEASE'])) {
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

    // Get updated balance
    $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ?");
    $stmt->execute([$wallet['wallet_id']]);
    $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    // Send response
    $responsePayload = [
        'status' => 'SUCCESS',
        'hold_placed' => $holdPlaced ?? true,
        'hold_reference' => $holdReference,
        'session_id' => $sessionId,
        'message' => $message,
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
        'message' => 'Bank communication failed',
        'reason' => $e->getMessage()
    ]);
    http_response_code(400);
}
