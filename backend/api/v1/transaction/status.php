<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/transaction/status/index.php
header('Content-Type: application/json');
require_once '../../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';

$trace = $_GET['trace'] ?? null;
$signature = $_GET['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? null;
$timestamp = $_GET['timestamp'] ?? $_SERVER['HTTP_X_TIMESTAMP'] ?? null;
$requester = $_GET['requester'] ?? $_SERVER['HTTP_X_REQUESTER'] ?? 'UNKNOWN';

if (!$trace) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Trace number required']);
    exit;
}

// ============================================================
// VERIFY SIGNATURE (Optional - recommended for queries)
// ============================================================
$signatureVerified = false;
if ($signature) {
    $payloadToVerify = ['trace' => $trace];
    $publicKey = get_requester_public_key($requester, $pdo);
    if ($publicKey) {
        $signatureVerified = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);
        if ($signatureVerified) {
            error_log("SACCUSSALIS STATUS: Signature verified from {$requester}");
        } else {
            error_log("SACCUSSALIS STATUS: Invalid signature from {$requester}");
        }
    }
}

try {
    $responseData = null;

    // Check ewallet_pins first
    $stmt = $pdo->prepare("
        SELECT ep.*, w.phone as wallet_phone, w.balance as wallet_balance
        FROM ewallet_pins ep
        LEFT JOIN wallets w ON ep.recipient_phone = w.phone
        WHERE ep.pin = ? OR ep.id::text = ? OR ep.transaction_id::text = ?
    ");
    $stmt->execute([$trace, $trace, $trace]);
    $pin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pin) {
        $responseData = [
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $pin['is_redeemed'] ? 'REDEEMED' : 'ACTIVE',
            'amount' => $pin['amount'],
            'type' => 'EWALLET_PIN',
            'timestamp' => $pin['created_at'],
            'expires_at' => $pin['expires_at'],
            'is_redeemed' => (bool)$pin['is_redeemed'],
            'redeemed_at' => $pin['redeemed_at']
        ];
    }

    // Check sat_tokens
    if (!$responseData) {
        $stmt = $pdo->prepare("
            SELECT s.*, c.phone, c.instrument_type
            FROM sat_tokens s
            JOIN cash_instruments c ON s.instrument_id = c.instrument_id
            WHERE s.sat_code = ? OR s.auth_code = ?
        ");
        $stmt->execute([$trace, $trace]);
        $sat = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sat) {
            $responseData = [
                'status' => 'success',
                'trace_number' => $trace,
                'transaction_status' => $sat['status'],
                'amount' => $sat['amount'],
                'type' => 'SAT_TOKEN',
                'timestamp' => $sat['created_at'],
                'expires_at' => $sat['expires_at']
            ];
        }
    }

    // Check transactions table
    if (!$responseData) {
        $stmt = $pdo->prepare("
            SELECT * FROM transactions 
            WHERE reference = ? OR transaction_id::text = ?
        ");
        $stmt->execute([$trace, $trace]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($transaction) {
            $responseData = [
                'status' => 'success',
                'trace_number' => $trace,
                'transaction_status' => $transaction['status'],
                'amount' => $transaction['amount'],
                'type' => $transaction['type'],
                'timestamp' => $transaction['created_at']
            ];
        }
    }

    // Check atm_authorizations
    if (!$responseData) {
        $stmt = $pdo->prepare("SELECT * FROM atm_authorizations WHERE trace_number = ? OR auth_code = ?");
        $stmt->execute([$trace, $trace]);
        $auth = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($auth) {
            $responseData = [
                'status' => 'success',
                'trace_number' => $trace,
                'transaction_status' => $auth['response_code'] == '00' ? 'AUTHORIZED' : 'DECLINED',
                'amount' => $auth['amount'],
                'type' => 'ATM_AUTHORIZATION',
                'timestamp' => $auth['created_at']
            ];
        }
    }

    if (!$responseData) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
        exit;
    }

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responseData['requester'] = $requester;
    $responseData['signature_verified'] = $signatureVerified;
    
    send_signed_response($responseData);

} catch (Exception $e) {
    error_log("SACCUSSALIS STATUS error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Status query failed',
        'timestamp' => time()
    ]);
}
