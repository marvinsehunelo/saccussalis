<?php
// SAT Cashout Endpoint - Saccussalis
// Handles ATM cashout using SAT token

header('Content-Type: application/json');
require_once '../../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

// JSON payload from ATM / VouchMorph
$input = json_decode(file_get_contents('php://input'), true);

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($input['certificate'])) {
    error_log("SACCUSSALIS SAT CASHOUT: No certificate provided");
    echo json_encode([
        'success' => false,
        'message' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('SACCUSSALIS');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("SACCUSSALIS SAT CASHOUT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("SACCUSSALIS SAT CASHOUT: Requester: {$requester}");

if (!$isValid) {
    error_log("SACCUSSALIS SAT CASHOUT: Certificate verification failed");
    echo json_encode([
        'success' => false,
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("SACCUSSALIS SAT CASHOUT: Request verified from {$requester} using certificate");

// ============================================================
// PROCESS CASHOUT
// ============================================================

$sat_number = $input['sat_number'] ?? null;
$atm_id     = $input['atm_id'] ?? null;
$trace_number = $input['trace_number'] ?? null;

if (!$sat_number || !$atm_id || !$trace_number) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameters: sat_number, atm_id, trace_number',
        'timestamp' => time()
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Fetch token and cash instrument with lock
    $stmt = $pdo->prepare("
        SELECT t.*, ci.wallet_id, ci.instrument_id, ci.holder_name, ci.currency
        FROM sat_tokens t
        JOIN cash_instruments ci ON t.instrument_id = ci.instrument_id
        WHERE t.sat_number = :sat_number 
        FOR UPDATE
    ");
    $stmt->execute(['sat_number' => $sat_number]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token) {
        throw new Exception('SAT token not found: ' . $sat_number);
    }

    error_log("SACCUSSALIS SAT CASHOUT: Found token ID={$token['sat_id']}, Amount={$token['amount']}, Status={$token['status']}");

    // Validate token status
    if ($token['status'] !== 'ACTIVE') {
        throw new Exception('SAT token not active. Current status: ' . $token['status']);
    }
    
    if (!$token['processing']) {
        throw new Exception('SAT token not in processing state. Please initiate hold first.');
    }

    // Check if token is expired
    if ($token['expires_at'] && strtotime($token['expires_at']) < time()) {
        throw new Exception('SAT token has expired');
    }

    // Generate authorization code
    $authCode = 'AUTH' . strtoupper(substr(uniqid(), -6)) . rand(100, 999);

    // Mark token as USED with requester info
    $stmt = $pdo->prepare("
        UPDATE sat_tokens 
        SET status = 'USED', 
            used_at = NOW(), 
            processing = FALSE,
            used_by = :requester,
            usage_signature_verified = :sig_verified,
            auth_code = :auth_code,
            updated_at = NOW()
        WHERE sat_id = :id
    ");
    $stmt->execute([
        'id' => $token['sat_id'],
        'requester' => $requester,
        'sig_verified' => $isValid ? 1 : 0,
        'auth_code' => $authCode
    ]);

    // Update cash instrument
    $stmt = $pdo->prepare("
        UPDATE cash_instruments 
        SET status = 'CASHED_OUT', 
            cashed_out_at = NOW(), 
            foreign_atm_id = :atm_id,
            cashed_out_by = :requester,
            auth_code = :auth_code,
            updated_at = NOW()
        WHERE instrument_id = :instrument_id
    ");
    $stmt->execute([
        'instrument_id' => $token['instrument_id'],
        'atm_id' => $atm_id,
        'requester' => $requester,
        'auth_code' => $authCode
    ]);

    // Log ATM authorization with signature info
    $stmt = $pdo->prepare("
        INSERT INTO atm_authorizations
            (sat_code, trace_number, acquirer_bank, amount, response_code, auth_code, dispense_trace,
             atm_id, requester, signature_verified, created_at)
        VALUES 
            (:sat_code, :trace_number, :acquirer_bank, :amount, '00', :auth_code, :dispense_trace,
             :atm_id, :requester, :sig_verified, NOW())
    ");
    $stmt->execute([
        'sat_code' => $sat_number,
        'trace_number' => $trace_number,
        'acquirer_bank' => $token['acquirer_network'] ?? 'UNKNOWN',
        'amount' => $token['amount'],
        'auth_code' => $authCode,
        'dispense_trace' => $trace_number,
        'atm_id' => $atm_id,
        'requester' => $requester,
        'sig_verified' => $isValid ? 1 : 0
    ]);

    $authId = $pdo->lastInsertId();

    // Update wallet balances if linked
    $updatedBalance = null;
    if ($token['wallet_id']) {
        // Lock wallet for update
        $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ? FOR UPDATE");
        $stmt->execute([$token['wallet_id']]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($wallet) {
            if ($wallet['balance'] < $token['amount']) {
                throw new Exception("Insufficient wallet balance. Available: {$wallet['balance']}, Required: {$token['amount']}");
            }
            
            $stmt = $pdo->prepare("
                UPDATE wallets 
                SET balance = balance - :amount,
                    updated_at = NOW()
                WHERE wallet_id = :wallet_id
            ");
            $stmt->execute([
                'amount' => $token['amount'],
                'wallet_id' => $token['wallet_id']
            ]);
            
            // Get updated balance
            $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE wallet_id = ?");
            $stmt->execute([$token['wallet_id']]);
            $updatedBalance = $stmt->fetchColumn();
            
            error_log("SACCUSSALIS SAT CASHOUT: Wallet {$token['wallet_id']} debited {$token['amount']}, new balance: {$updatedBalance}");
        }
    }

    // Create transaction record
    $transactionRef = 'SATCO_' . time() . '_' . rand(100, 999);
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
            (reference, amount, type, status, channel, notes, requester, signature_verified, created_at)
        VALUES 
            (?, ?, 'sat_cashout', 'completed', 'atm', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $transactionRef,
        $token['amount'],
        json_encode([
            'sat_number' => $sat_number,
            'atm_id' => $atm_id,
            'trace_number' => $trace_number,
            'auth_code' => $authCode
        ]),
        $requester,
        $isValid ? 1 : 0
    ]);

    $pdo->commit();

    error_log("SACCUSSALIS SAT CASHOUT: Cashout completed - SAT: {$sat_number}, Amount: {$token['amount']}, Auth: {$authCode}");

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'success' => true,
        'message' => 'Cashout successful',
        'sat_number' => $token['sat_number'],
        'amount' => (float)$token['amount'],
        'currency' => $token['currency'] ?? 'BWP',
        'atm_id' => $atm_id,
        'trace_number' => $trace_number,
        'auth_code' => $authCode,
        'auth_id' => $authId,
        'transaction_ref' => $transactionRef,
        'wallet_balance' => $updatedBalance,
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'timestamp' => time()
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS SAT CASHOUT ERROR: " . $e->getMessage());
    error_log("SACCUSSALIS SAT CASHOUT Input: " . json_encode($input ?? []));
    
    echo json_encode([
        'success' => false,
        'message' => 'Cashout failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
