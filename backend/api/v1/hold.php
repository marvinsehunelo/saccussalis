<?php
// backend/api/v1/hold.php - SACCUSSALIS HOLD
// Supports: WALLET holds (ewallet_pins + wallets) AND ACCOUNT holds (accounts)
// Updates hold_status, held_balance, and creates financial_holds records

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
    // DETECT ASSET TYPE
    // ============================================================
    $assetType = strtoupper($input['asset_type'] ?? 'WALLET');
    $action = strtoupper(trim($input['action'] ?? 'PLACE_HOLD'));
    $amount = floatval($input['amount'] ?? 0);
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? uniqid('HOLD-');
    $foreignBank = $input['foreign_bank'] ?? $input['destination_institution'] ?? 'UNKNOWN';
    $sessionId = trim($input['session_id'] ?? $input['reference'] ?? uniqid('SESSION-'));
    
    // Get identifiers
    $pin = $input['pin'] ?? $input['atm_pin'] ?? $input['cashout_pin'] ?? null;
    $phone = $input['phone'] ?? $input['wallet_phone'] ?? null;
    $accountNumber = $input['account_number'] ?? $input['source_identifier'] ?? null;
    
    error_log("HOLD: assetType=$assetType, pin=" . ($pin ? substr($pin, -4) : 'null') . 
              ", phone=$phone, accountNumber=$accountNumber, amount=$amount, action=$action");

    // Check if PDO is available
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection not available");
    }
    
    $pdo->beginTransaction();

    // ============================================================
    // ============================================================
    // CASE 1: ACCOUNT HOLD
    // ============================================================
    // ============================================================
    if ($assetType === 'ACCOUNT') {
        
        if (empty($accountNumber)) {
            throw new Exception("Account number required for ACCOUNT hold");
        }
        if ($amount <= 0) {
            throw new Exception("Valid amount required for ACCOUNT hold");
        }

        // Find the account
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
                created_at
            FROM accounts 
            WHERE account_number = :account_number
            FOR UPDATE
        ");
        $stmt->execute(['account_number' => $accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            throw new Exception("Account not found: $accountNumber");
        }
        
        error_log("Account found: ID={$account['account_id']}, Balance={$account['balance']}, Held={$account['held_balance']}");
        
        // Check if account is frozen
        if ($account['is_frozen'] == true) {
            throw new Exception("Account is frozen");
        }
        
        // Check available balance
        $availableBalance = (float)$account['balance'] - (float)($account['held_balance'] ?? 0);
        if ($availableBalance < $amount) {
            throw new Exception("Insufficient account balance. Available: $availableBalance, Requested: $amount");
        }

        if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
            
            // Update account held_balance
            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET held_balance = COALESCE(held_balance, 0) + :amount 
                WHERE account_id = :account_id
            ");
            $stmt->execute([
                'amount' => $amount,
                'account_id' => $account['account_id']
            ]);
            
            error_log("Account held_balance updated: account_id={$account['account_id']}, amount=$amount");

            // Insert into financial_holds
            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (account_id, amount, hold_reference, foreign_bank, session_id, status, 
                     requester, signature_verified, asset_type, expires_at, created_at)
                VALUES 
                    (:account_id, :amount, :hold_reference, :foreign_bank, :session_id, 'HELD', 
                     :requester, :signature_verified, 'ACCOUNT', NOW() + INTERVAL '24 hours', NOW())
                RETURNING id
            ");
            $stmt->execute([
                'account_id' => $account['account_id'],
                'amount' => $amount,
                'hold_reference' => $holdReference,
                'foreign_bank' => $foreignBank,
                'session_id' => $sessionId,
                'requester' => $requester,
                'signature_verified' => $isValid ? 1 : 0
            ]);
            $holdId = $stmt->fetchColumn();
            
            $message = "Account hold placed successfully";
            $holdPlaced = true;
            $updatedAccount = [
                'balance' => $account['balance'],
                'held_balance' => (float)($account['held_balance'] ?? 0) + $amount
            ];
            
        } elseif (in_array($action, ['RELEASE_HOLD', 'RELEASE'])) {
            
            // Find the financial hold
            $stmt = $pdo->prepare("
                SELECT id, account_id, amount 
                FROM financial_holds 
                WHERE hold_reference = :hold_reference AND status = 'HELD' AND asset_type = 'ACCOUNT'
                FOR UPDATE
            ");
            $stmt->execute(['hold_reference' => $holdReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hold) {
                throw new Exception("Account hold not found for reference: $holdReference");
            }
            
            // Release account held_balance
            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET held_balance = GREATEST(COALESCE(held_balance, 0) - :amount, 0) 
                WHERE account_id = :account_id
            ");
            $stmt->execute([
                'amount' => $hold['amount'],
                'account_id' => $hold['account_id']
            ]);
            
            // Update financial hold status
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED', released_at = NOW() 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $hold['id']]);
            
            error_log("Account hold released: hold_id={$hold['id']}, account_id={$hold['account_id']}");
            
            $message = "Account hold released successfully";
            $holdPlaced = false;
            $updatedAccount = ['balance' => 0, 'held_balance' => 0];
            
        } elseif (in_array($action, ['DEBIT_FUNDS', 'DEBIT', 'COMMIT'])) {
            
            // Find the financial hold
            $stmt = $pdo->prepare("
                SELECT id, account_id, amount 
                FROM financial_holds 
                WHERE hold_reference = :hold_reference AND status = 'HELD' AND asset_type = 'ACCOUNT'
                FOR UPDATE
            ");
            $stmt->execute(['hold_reference' => $holdReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hold) {
                throw new Exception("Account hold not found for reference: $holdReference");
            }
            
            $debitAmount = $amount > 0 ? $amount : $hold['amount'];
            
            // Debit the account
            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET balance = balance - :amount, 
                    held_balance = GREATEST(COALESCE(held_balance, 0) - :amount, 0) 
                WHERE account_id = :account_id
            ");
            $stmt->execute([
                'amount' => $debitAmount,
                'account_id' => $hold['account_id']
            ]);
            
            // Update financial hold status
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'DEBITED', debited_at = NOW(), debited_by = :requester 
                WHERE id = :id
            ");
            $stmt->execute([
                'requester' => $requester,
                'id' => $hold['id']
            ]);
            
            error_log("Account debited: hold_id={$hold['id']}, account_id={$hold['account_id']}, amount=$debitAmount");
            
            $message = "Account debited successfully";
            $holdPlaced = false;
            $updatedAccount = ['balance' => 0, 'held_balance' => 0];
        }

        $pdo->commit();

        // Build response for ACCOUNT
        $responsePayload = [
            'status' => 'SUCCESS',
            'hold_placed' => $holdPlaced ?? true,
            'hold_reference' => $holdReference,
            'session_id' => $sessionId,
            'message' => $message,
            'asset_type' => 'ACCOUNT',
            'account_number' => $accountNumber,
            'new_balance' => (float)($updatedAccount['balance'] - ($updatedAccount['held_balance'] ?? 0)),
            'held_balance' => (float)($updatedAccount['held_balance'] ?? 0),
            'available_balance' => (float)($updatedAccount['balance'] - ($updatedAccount['held_balance'] ?? 0)),
            'requester' => $requester,
            'signature_verified' => $isValid
        ];
        
        error_log("HOLD: Account Response: " . json_encode($responsePayload));
        echo json_encode($responsePayload);
        exit;
    }

    // ============================================================
    // ============================================================
    // CASE 2: WALLET / BANK-WALLET / MNO-WALLET / VOUCHER HOLD
    // ============================================================
    // ============================================================
    if (in_array($assetType, ['WALLET', 'BANK-WALLET', 'MNO-WALLET', 'VOUCHER'])) {
        
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

        if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
            
            // STEP 3a: UPDATE ewallet_pins hold_status = true
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

            // STEP 4a: UPDATE wallet held_balance
            $stmt = $pdo->prepare("
                UPDATE wallets 
                SET held_balance = COALESCE(held_balance, 0) + ? 
                WHERE wallet_id = ?
            ");
            $stmt->execute([$amount, $wallet['wallet_id']]);
            
            error_log("Wallet held_balance updated: wallet_id={$wallet['wallet_id']}, amount=$amount");

            // STEP 5a: INSERT INTO financial_holds
            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (wallet_id, amount, hold_reference, foreign_bank, session_id, status, 
                     requester, signature_verified, asset_type, expires_at, created_at)
                VALUES 
                    (?, ?, ?, ?, ?, 'HELD', ?, ?, 'WALLET', NOW() + INTERVAL '24 hours', NOW())
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

            // STEP 6a: INSERT INTO pin_hold_logs
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
            
            // Find the financial hold
            $stmt = $pdo->prepare("
                SELECT id, wallet_id, amount 
                FROM financial_holds 
                WHERE hold_reference = ? AND status = 'HELD' AND asset_type IN ('WALLET', 'VOUCHER')
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
            
            // Find the financial hold
            $stmt = $pdo->prepare("
                SELECT id, wallet_id, amount 
                FROM financial_holds 
                WHERE hold_reference = ? AND status = 'HELD' AND asset_type IN ('WALLET', 'VOUCHER')
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
        }

        $pdo->commit();

        // Build response for WALLET
        $responsePayload = [
            'status' => 'SUCCESS',
            'hold_placed' => $holdPlaced ?? true,
            'hold_reference' => $holdReference,
            'session_id' => $sessionId,
            'message' => $message,
            'asset_type' => 'WALLET',
            'new_balance' => (float)($updatedWallet['balance'] - ($updatedWallet['held_balance'] ?? 0)),
            'held_balance' => (float)($updatedWallet['held_balance'] ?? 0),
            'available_balance' => (float)($updatedWallet['balance'] - ($updatedWallet['held_balance'] ?? 0)),
            'requester' => $requester,
            'signature_verified' => $isValid,
            'pin' => $pin,
            'pin_id' => $pinRecord['id'],
            'pin_amount' => (float)$pinRecord['amount']
        ];
        
        error_log("HOLD: Wallet Response: " . json_encode($responsePayload));
        echo json_encode($responsePayload);
        exit;
    }

    // ============================================================
    // UNKNOWN ASSET TYPE
    // ============================================================
    throw new Exception("Unsupported asset type: $assetType. Supported: ACCOUNT, WALLET, BANK-WALLET, MNO-WALLET, VOUCHER");

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
