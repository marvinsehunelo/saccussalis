<?php
// SAT Cashout Endpoint - Saccussalis
// Handles ATM cashout using SAT token

header('Content-Type: application/json');
require_once '../../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';

// JSON payload from ATM / VouchMorph
$input = json_decode(file_get_contents('php://input'), true);

// ============================================================
// VERIFY INCOMING SIGNATURE
// ============================================================
$signature = $input['signature'] ?? null;
$timestamp = $input['timestamp'] ?? null;
$requester = $input['requester'] ?? 'VOUCHMORPH';

$payloadToVerify = [
    'sat_number' => $input['sat_number'] ?? null,
    'atm_id' => $input['atm_id'] ?? null,
    'trace_number' => $input['trace_number'] ?? null
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("SACCUSSALIS SAT CASHOUT: Missing signature from {$requester}");
    echo json_encode([
        'success' => false,
        'message' => 'Missing signature - cashout requests must be signed'
    ]);
    exit;
}

$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("SACCUSSALIS SAT CASHOUT: No public key for requester: {$requester}");
    echo json_encode([
        'success' => false,
        'message' => "No public key found for requester: {$requester}"
    ]);
    exit;
}

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("SACCUSSALIS SAT CASHOUT: Invalid signature from {$requester}");
    echo json_encode([
        'success' => false,
        'message' => 'Invalid signature - cashout request cannot be trusted'
    ]);
    exit;
}

error_log("SACCUSSALIS SAT CASHOUT: Signature verified from {$requester}");

// ============================================================
// PROCESS CASHOUT
// ============================================================

$sat_number = $input['sat_number'] ?? null;
$atm_id     = $input['atm_id'] ?? null;
$trace_number = $input['trace_number'] ?? null;

if (!$sat_number || !$atm_id || !$trace_number) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameters'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Fetch token and cash instrument
    $stmt = $pdo->prepare("
        SELECT t.*, ci.wallet_id, ci.instrument_id 
        FROM sat_tokens t
        JOIN cash_instruments ci ON t.instrument_id = ci.instrument_id
        WHERE t.sat_number = :sat_number 
        FOR UPDATE
    ");
    $stmt->execute(['sat_number' => $sat_number]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token) {
        throw new Exception('SAT token not found');
    }

    if ($token['status'] !== 'ACTIVE' || $token['processing'] != true) {
        throw new Exception('SAT token not eligible for cashout');
    }

    // Mark token as USED with requester info
    $stmt = $pdo->prepare("
        UPDATE sat_tokens 
        SET status = 'USED', 
            used_at = NOW(), 
            processing = FALSE,
            used_by = :requester,
            usage_signature_verified = :sig_verified
        WHERE sat_id = :id
    ");
    $stmt->execute([
        'id' => $token['sat_id'],
        'requester' => $requester,
        'sig_verified' => $isValid ? 1 : 0
    ]);

    // Update cash instrument
    $stmt = $pdo->prepare("
        UPDATE cash_instruments 
        SET status = 'USED', 
            cashed_out_at = NOW(), 
            foreign_atm_id = :atm_id,
            cashed_out_by = :requester
        WHERE instrument_id = :instrument_id
    ");
    $stmt->execute([
        'instrument_id' => $token['instrument_id'],
        'atm_id' => $atm_id,
        'requester' => $requester
    ]);

    // Log ATM authorization with signature info
    $stmt = $pdo->prepare("
        INSERT INTO atm_authorizations
            (sat_code, trace_number, acquirer_bank, amount, response_code, auth_code, dispense_trace,
             requester, signature_verified)
        VALUES 
            (:sat_code, :trace_number, :acquirer_bank, :amount, '00', :auth_code, :dispense_trace,
             :requester, :sig_verified)
    ");
    $stmt->execute([
        'sat_code' => $sat_number,
        'trace_number' => $trace_number,
        'acquirer_bank' => $token['acquirer_network'],
        'amount' => $token['amount'],
        'auth_code' => 'AUTH' . rand(1000, 9999),
        'dispense_trace' => $trace_number,
        'requester' => $requester,
        'sig_verified' => $isValid ? 1 : 0
    ]);

    // Update wallet balances if linked
    if ($token['wallet_id']) {
        $stmt = $pdo->prepare("
            UPDATE wallets 
            SET balance = balance - :amount 
            WHERE wallet_id = :wallet_id
        ");
        $stmt->execute([
            'amount' => $token['amount'],
            'wallet_id' => $token['wallet_id']
        ]);
    }

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'success' => true,
        'message' => 'Cashout successful',
        'sat_number' => $token['sat_number'],
        'amount' => $token['amount'],
        'atm_id' => $atm_id,
        'trace_number' => $trace_number,
        'requester' => $requester,
        'signature_verified' => $isValid
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("SACCUSSALIS SAT CASHOUT error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
