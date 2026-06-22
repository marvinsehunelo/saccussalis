<?php
// backend/api/v1/hold.php - SACCUSSALIS HOLD
// Updates BOTH ewallet_pins hold_status AND wallet held_balance

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

header('Content-Type: application/json');

// Disable gzip compression
ini_set('zlib.output_compression', 'Off');

if (ob_get_level()) {
    ob_end_clean();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("=== HOLD.PHP RECEIVED === " . json_encode($input));

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION
    // ============================================================
    
    if (!isset($input['certificate'])) {
        error_log("HOLD: No certificate provided");
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
            'message' => 'Certificate verification failed'
        ]);
        exit;
    }
    
    error_log("HOLD: Verified from {$requester}");

    // ============================================================
    // PROCESS HOLD - Updates BOTH tables
    // ============================================================

    $action = strtoupper(trim($input['action'] ?? 'PLACE_HOLD'));
    $amount = floatval($input['amount'] ?? 0);
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? uniqid('HOLD-');
    $foreignBank = $input['foreign_bank'] ?? $input['destination_institution'] ?? 'UNKNOWN';
    $sessionId = trim($input['session_id'] ?? $input['reference'] ?? uniqid('SESSION-'));
    
    // Get PIN from request
    $pin = $input['pin'] ?? $input['atm_pin'] ?? $input['cashout_pin'] ?? null;
    $phone = $input['phone'] ?? $input['wallet_phone'] ?? null;
    
    error_log("HOLD: pin=" . ($pin ? substr($pin, -4) : 'null') . ", phone=$phone, amount=$amount, action=$action");

    // Check if PDO is available
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection not available");
    }
    
    $pdo->beginTransaction();

    if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
        
        if (!$pin) {
            throw new Exception("PIN is required to place a hold");
        }
        if (!$phone) {
            throw new Exception("Phone number is required");
        }
        if ($amount <= 0) {
            throw new Exception("Valid amount required");
        }

        // ============================================================
        // STEP 1: FIND THE PIN IN ewallet_pins
        // ============================================================
        $stmt = $pdo->prepare("
            SELECT id, pin, amount, is_redeemed, hold_status, hold_reference, held_by, expires_at
            FROM ewallet_pins 
            WHERE pin = ? AND is_redeemed = false 
            AND (hold_status = false OR hold_status IS NULL)
            AND (expires_at IS NULL OR expires_at > NOW())
            FOR UPDATE
        ");
        $stmt->execute([$pin]);
        $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pinRecord) {
            throw new Exception("PIN not found, already redeemed, expired, or currently on hold");
        }
        
        error_log("PIN found: id={$pinRecord['id']}, amount={$pinRecord['amount']}, hold_status={$pinRecord['hold_status']}");
        
        if ($amount > 0 && $pinRecord['amount'] < $amount) {
            throw new Exception("PIN has insufficient value. Available: {$pinRecord['amount']}, Requested: $amount");
        }

        // ============================================================
        // STEP 2: FIND THE WALLET
        // ============================================================
        $targetPhone = $phone;
        if (!str_starts_with($targetPhone, '+')) {
            $targetPhone = '+' . $targetPhone;
        }
        
        $stmt = $pdo->prepare("
            SELECT wallet_id, balance, held_balance, status, is_frozen
            FROM wallets 
            WHERE phone = ? AND status = 'active' AND is_frozen = false
            FOR UPDATE
        ");
        $stmt->execute([$targetPhone]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet) {
            $phoneWithoutPlus = ltrim($targetPhone, '+');
            $stmt = $pdo->prepare("
                SELECT wallet_id, balance, held_balance, status, is_frozen
                FROM wallets 
                WHERE phone = ? AND status = 'active' AND is_frozen = false
                FOR UPDATE
            ");
            $stmt->execute([$phoneWithoutPlus]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$wallet) {
            throw new Exception("Wallet not found for phone: $phone");
        }
        
        error_log("Wallet found: ID={$wallet['wallet_id']}, Balance={$wallet['balance']}, Held={$wallet['held_balance']}");
        
        // Check available balance
        $availableBalance = $wallet['balance'] - ($wallet['held_balance'] ?? 0);
        if ($availableBalance < $amount) {
            throw new Exception("Insufficient wallet balance. Available: $availableBalance, Requested: $amount");
        }

        // ============================================================
        // STEP 3: UPDATE ewallet_pins hold_status = true
        // ============================================================
        $stmt = $pdo->prepare("
            UPDATE ewallet_pins 
            SET hold_status = true, 
                hold_reference = ?, 
                held_at = NOW(), 
                held_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$holdReference, $requester, $pinRecord['id']]);
        
        error_log("PIN hold_status updated to true for id={$pinRecord['id']}, hold_reference=$holdReference");

        // ============================================================
        // STEP 4: UPDATE wallet held_balance
        // ============================================================
        $stmt = $pdo->prepare("
            UPDATE wallets 
            SET held_balance = COALESCE(held_balance, 0) + ? 
            WHERE wallet_id = ?
        ");
        $stmt->execute([$amount, $wallet['wallet_id']]);
        
        error_log("Wallet held_balance updated: wallet_id={$wallet['wallet_id']}, amount=$amount");

        // ============================================================
        // STEP 5: INSERT INTO financial_holds (for audit)
        // ============================================================
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

        // ============================================================
        // STEP 6: INSERT INTO pin_hold_logs (if table exists)
        // ============================================================
        try {
            $stmt = $pdo->prepare("
                INSERT INTO pin_hold_logs (pin_id, hold_reference, action, amount, held_by, created_at)
                VALUES (?, ?, 'HELD', ?, ?, NOW())
            ");
            $stmt->execute([$pinRecord['id'], $holdReference, $amount, $requester]);
        } catch (Exception $e) {
            error_log("pin_hold_logs insert skipped: " . $e->getMessage());
        }
        
        $message = "PIN hold placed successfully";
        $holdPlaced = true;
        $updatedWallet = [
            'balance' => $wallet['balance'],
            'held_balance' => $wallet['held_balance'] + $amount
        ];
        
    } elseif (in_array($action, ['RELEASE_HOLD', 'RELEASE'])) {
        
        // ============================================================
        // RELEASE HOLD - Reverse both tables
        // ============================================================
        
        // Find the PIN hold
        $stmt = $pdo->prepare("
            SELECT id, pin, amount, hold_status, hold_reference, held_by
            FROM ewallet_pins 
            WHERE hold_reference = ? AND hold_status = true
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pinRecord) {
            throw new Exception("PIN hold not found for reference: $holdReference");
        }
        
        // Find the financial hold
        $stmt = $pdo->prepare("
            SELECT id, wallet_id, amount 
            FROM financial_holds 
            WHERE hold_reference = ? AND status = 'HELD'
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($hold) {
            // Release financial hold
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED', released_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$hold['id']]);
            
            // Release wallet held_balance
            $stmt = $pdo->prepare("
                UPDATE wallets 
                SET held_balance = GREATEST(COALESCE(held_balance, 0) - ?, 0) 
                WHERE wallet_id = ?
            ");
            $stmt->execute([$hold['amount'], $hold['wallet_id']]);
        }
        
        // Release PIN hold
        $stmt = $pdo->prepare("
            UPDATE ewallet_pins 
            SET hold_status = false, 
                hold_reference = NULL, 
                held_at = NULL, 
                held_by = NULL
            WHERE id = ?
        ");
        $stmt->execute([$pinRecord['id']]);
        
        error_log("PIN hold released: id={$pinRecord['id']}");
        
        $message = "PIN hold released successfully";
        $holdPlaced = false;
        $updatedWallet = ['balance' => 0, 'held_balance' => 0];
        
    } elseif (in_array($action, ['DEBIT_FUNDS', 'DEBIT', 'COMMIT'])) {
        
        // ============================================================
        // DEBIT - Redeem the PIN and deduct from wallet
        // ============================================================
        
        // Find the PIN hold
        $stmt = $pdo->prepare("
            SELECT id, pin, amount, hold_status, hold_reference, held_by
            FROM ewallet_pins 
            WHERE hold_reference = ? AND hold_status = true
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pinRecord) {
            throw new Exception("PIN hold not found for reference: $holdReference");
        }
        
        // Find the financial hold
        $stmt = $pdo->prepare("
            SELECT id, wallet_id, amount 
            FROM financial_holds 
            WHERE hold_reference = ? AND status = 'HELD'
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($hold) {
            $debitAmount = $amount > 0 ? $amount : $hold['amount'];
            
            // Debit the wallet
            $stmt = $pdo->prepare("
                UPDATE wallets 
                SET balance = balance - ?, 
                    held_balance = GREATEST(COALESCE(held_balance, 0) - ?, 0) 
                WHERE wallet_id = ?
            ");
            $stmt->execute([$debitAmount, $debitAmount, $hold['wallet_id']]);
            
            // Update financial hold status
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'DEBITED', debited_at = NOW(), debited_by = ? 
                WHERE id = ?
            ");
            $stmt->execute([$requester, $hold['id']]);
        }
        
        // Mark PIN as redeemed
        $stmt = $pdo->prepare("
            UPDATE ewallet_pins 
            SET is_redeemed = true,
                hold_status = false,
                redeemed_at = NOW(),
                redeemed_by = ?,
                hold_reference = NULL,
                held_at = NULL,
                held_by = NULL
            WHERE id = ?
        ");
        $stmt->execute([$requester, $pinRecord['id']]);
        
        error_log("PIN redeemed: id={$pinRecord['id']}");
        
        $message = "PIN redeemed successfully (funds debited)";
        $holdPlaced = false;
        $updatedWallet = ['balance' => 0, 'held_balance' => 0];
        
    } else {
        throw new Exception("Unsupported action: $action");
    }

    $pdo->commit();

    // Build response payload
    $responsePayload = [
        'status' => 'SUCCESS',
        'hold_placed' => $holdPlaced ?? true,
        'hold_reference' => $holdReference,
        'session_id' => $sessionId,
        'message' => $message,
        'asset_type' => 'PIN',
        'new_balance' => (float)($updatedWallet['balance'] - ($updatedWallet['held_balance'] ?? 0)),
        'held_balance' => (float)($updatedWallet['held_balance'] ?? 0),
        'available_balance' => (float)($updatedWallet['balance'] - ($updatedWallet['held_balance'] ?? 0)),
        'requester' => $requester,
        'signature_verified' => $isValid,
        'pin' => $pin
    ];
    
    error_log("HOLD: Response: " . json_encode($responsePayload));
    echo json_encode($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Hold.php ERROR: " . $e->getMessage());
    error_log("Hold.php TRACE: " . $e->getTraceAsString());
    
    $errorResponse = [
        'status' => 'ERROR',
        'hold_placed' => false,
        'message' => $e->getMessage()
    ];
    
    echo json_encode($errorResponse);
    http_response_code(400);
}
