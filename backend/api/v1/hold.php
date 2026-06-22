<?php
// backend/api/v1/hold.php - CERTIFICATE-ONLY VERIFICATION with PIN HOLD support

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

header('Content-Type: application/json');

// Disable gzip compression to avoid encoding issues
ini_set('zlib.output_compression', 'Off');

if (ob_get_level()) {
    ob_end_clean();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("=== HOLD.PHP RECEIVED === " . json_encode($input));

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION (REQUIRED)
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
            'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown')
        ]);
        exit;
    }
    
    error_log("HOLD: Verified from {$requester}");

    // ============================================================
    // PROCESS HOLD (Supports both WALLET and PIN VOUCHER)
    // ============================================================

    $action = strtoupper(trim($input['action'] ?? 'PLACE_HOLD'));
    $amount = floatval($input['amount'] ?? 0);
    $holdReference = $input['hold_reference'] ?? $input['reference'] ?? uniqid('HOLD-');
    $foreignBank = $input['foreign_bank'] ?? $input['destination_institution'] ?? 'UNKNOWN';
    $sessionId = trim($input['session_id'] ?? $input['reference'] ?? uniqid('SESSION-'));
    
    // ============================================================
    // CHECK FOR PIN - This is a column in ewallet_pins table
    // Check multiple possible field names
    // ============================================================
    $pin = $input['pin'] ?? $input['atm_pin'] ?? $input['atm_code'] ?? $input['cashout_pin'] ?? null;
    
    // Asset type determines which table to use
    $assetType = strtoupper($input['asset_type'] ?? 'WALLET');
    
    // ============================================================
    // AUTO-DETECT PIN: If pin is present, force asset_type to PIN
    // ============================================================
    if ($pin && !empty($pin)) {
        $assetType = 'PIN';
        error_log("HOLD: Auto-detected PIN asset. PIN: " . substr($pin, -4));
    }
    
    $phone = $input['phone'] ?? $input['wallet_phone'] ?? null;
    $accountNumber = $input['account_number'] ?? null;
    
    error_log("HOLD: asset_type=$assetType, pin=" . ($pin ? substr($pin, -4) : 'null') . ", action=$action");
    
    // Check if PDO is available
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection not available");
    }
    
    $pdo->beginTransaction();

    // ============================================================
    // HANDLE PIN VOUCHER HOLDS (ewallet_pins table)
    // This runs when a PIN is provided (regardless of asset_type)
    // ============================================================
    if ($pin && !empty($pin)) {
        error_log("HOLD: Processing PIN hold for PIN: " . substr($pin, -4));
        
        if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
            // Place hold on PIN voucher
            $stmt = $pdo->prepare("
                SELECT id, pin, amount, sat_purchased, is_redeemed, hold_status, 
                       held_by, hold_reference, redeemed_at, expires_at
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
            // UPDATE hold_status = true ON THE PIN ROW
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
            
            error_log("PIN hold_status updated to true for id={$pinRecord['id']}");
            
            // Log the hold
            $stmt = $pdo->prepare("
                INSERT INTO pin_hold_logs (pin_id, hold_reference, action, amount, held_by, created_at)
                VALUES (?, ?, 'HELD', ?, ?, NOW())
            ");
            $stmt->execute([$pinRecord['id'], $holdReference, $amount, $requester]);
            
            $message = "PIN voucher hold placed successfully";
            $holdPlaced = true;
            $updatedWallet = [
                'balance' => $pinRecord['amount'],
                'held_balance' => $amount
            ];
            
            error_log("PIN HOLD PLACED: id={$pinRecord['id']}, hold_reference=$holdReference, hold_status=true");
            
        } elseif (in_array($action, ['RELEASE_HOLD', 'RELEASE'])) {
            // Release hold on PIN voucher
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
            
            // ============================================================
            // UPDATE hold_status = false ON THE PIN ROW
            // ============================================================
            $stmt = $pdo->prepare("
                UPDATE ewallet_pins 
                SET hold_status = false, 
                    hold_reference = NULL, 
                    held_at = NULL, 
                    held_by = NULL
                WHERE id = ?
            ");
            $stmt->execute([$pinRecord['id']]);
            
            error_log("PIN hold_status updated to false for id={$pinRecord['id']}");
            
            // Log the release
            $stmt = $pdo->prepare("
                UPDATE pin_hold_logs 
                SET action = 'RELEASED', 
                    released_at = NOW(), 
                    released_by = ?
                WHERE hold_reference = ? AND action = 'HELD'
            ");
            $stmt->execute([$requester, $holdReference]);
            
            $message = "PIN voucher hold released successfully";
            $holdPlaced = false;
            $updatedWallet = ['balance' => $pinRecord['amount'], 'held_balance' => 0];
            
            error_log("PIN HOLD RELEASED: id={$pinRecord['id']}, hold_reference=$holdReference, hold_status=false");
            
        } elseif (in_array($action, ['DEBIT_FUNDS', 'DEBIT', 'COMMIT'])) {
            // Redeem the PIN (commit)
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
            
            $debitAmount = $amount > 0 ? $amount : $pinRecord['amount'];
            
            // ============================================================
            // MARK AS REDEEMED (is_redeemed = true, hold_status = false)
            // ============================================================
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
            
            error_log("PIN redeemed: id={$pinRecord['id']}, is_redeemed=true, hold_status=false");
            
            // Log the debit
            $stmt = $pdo->prepare("
                UPDATE pin_hold_logs 
                SET action = 'DEBITED', 
                    amount = ?,
                    released_at = NOW(), 
                    released_by = ?
                WHERE hold_reference = ? AND action = 'HELD'
            ");
            $stmt->execute([$debitAmount, $requester, $holdReference]);
            
            $message = "PIN voucher redeemed successfully (funds debited)";
            $holdPlaced = false;
            $updatedWallet = ['balance' => 0, 'held_balance' => 0];
            
            error_log("PIN REDEEMED: id={$pinRecord['id']}, amount=$debitAmount");
        } else {
            throw new Exception("Unsupported action for PIN: $action");
        }
        
    } else {
        // ============================================================
        // HANDLE WALLET HOLDS (Original logic)
        // ============================================================
        
        error_log("HOLD: Processing WALLET hold");
        
        if (in_array($action, ['PLACE_HOLD', 'PLACE', 'HOLD', 'AUTHORIZE'])) {
            if (!$phone && !$accountNumber) {
                throw new Exception("No identifier found");
            }
            if ($amount <= 0) {
                throw new Exception("Valid amount required");
            }
        }
        
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
        }
        
        // Get updated wallet balance
        $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ?");
        $stmt->execute([$wallet['wallet_id']]);
        $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $pdo->commit();

    // Build response payload
    $responsePayload = [
        'status' => 'SUCCESS',
        'hold_placed' => $holdPlaced ?? true,
        'hold_reference' => $holdReference,
        'session_id' => $sessionId,
        'message' => $message,
        'asset_type' => $assetType,
        'new_balance' => (float)($updatedWallet['balance'] - ($updatedWallet['held_balance'] ?? 0)),
        'held_balance' => (float)($updatedWallet['held_balance'] ?? 0),
        'available_balance' => (float)($updatedWallet['balance'] - ($updatedWallet['held_balance'] ?? 0)),
        'requester' => $requester,
        'signature_verified' => $isValid
    ];
    
    // Include PIN in response if it was used
    if ($pin && !empty($pin)) {
        $responsePayload['pin'] = $pin;
    }
    
    error_log("HOLD: Response payload: " . json_encode($responsePayload));
    
    // Send response without gzip
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
        'message' => $e->getMessage(),
        'reason' => $e->getMessage()
    ];
    
    echo json_encode($errorResponse);
    http_response_code(400);
}
