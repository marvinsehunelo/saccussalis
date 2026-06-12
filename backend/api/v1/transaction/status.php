<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/transaction/status/index.php
header('Content-Type: application/json');
require_once '../../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

// Get parameters from GET request
$trace = $_GET['trace'] ?? null;
$signature = $_GET['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? null;
$timestamp = $_GET['timestamp'] ?? $_SERVER['HTTP_X_TIMESTAMP'] ?? null;
$requester = $_GET['requester'] ?? $_SERVER['HTTP_X_REQUESTER'] ?? 'UNKNOWN';

// Also check for certificate in POST body (if called via POST)
$input = json_decode(file_get_contents('php://input'), true);
$certificate = $input['certificate'] ?? null;

if (!$trace) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Trace number required']);
    exit;
}

// ============================================================
// VERIFY WITH CERTIFICATE OR SIGNATURE
// ============================================================
$signatureVerified = false;
$verificationMethod = 'none';

// Method 1: Certificate-based verification (preferred)
if ($certificate) {
    $certManager = new CertificateManager('SACCUSSALIS');
    
    // Create a verification payload
    $verifyRequest = [
        'certificate' => $certificate,
        'signature' => $signature,
        'requester' => $requester,
        'timestamp' => $timestamp,
        'trace' => $trace
    ];
    
    $verification = $certManager->verifySignedRequest($verifyRequest);
    $signatureVerified = $verification['verified'];
    $requester = $verification['requester'] ?? $requester;
    $verificationMethod = 'certificate';
    
    if ($signatureVerified) {
        error_log("SACCUSSALIS TRANSACTION STATUS: Certificate verified from {$requester}");
    } else {
        error_log("SACCUSSALIS TRANSACTION STATUS: Certificate verification failed from {$requester}: " . ($verification['message'] ?? 'Unknown'));
    }
}
// Method 2: Legacy signature verification (backward compatible)
else if ($signature) {
    $payloadToVerify = ['trace' => $trace];
    $publicKey = get_requester_public_key($requester, $pdo);
    if ($publicKey) {
        $signatureVerified = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);
        $verificationMethod = 'legacy_signature';
        if ($signatureVerified) {
            error_log("SACCUSSALIS TRANSACTION STATUS: Legacy signature verified from {$requester}");
        } else {
            error_log("SACCUSSALIS TRANSACTION STATUS: Invalid legacy signature from {$requester}");
        }
    }
} else {
    error_log("SACCUSSALIS TRANSACTION STATUS: No verification provided from {$requester}");
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
        $stmt = $pdo->prepare("
            SELECT * FROM atm_authorizations 
            WHERE trace_number = ? OR auth_code = ?
        ");
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
        echo json_encode([
            'status' => 'error', 
            'message' => 'Transaction not found',
            'trace' => $trace,
            'timestamp' => time()
        ]);
        exit;
    }

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responseData['requester'] = $requester;
    $responseData['signature_verified'] = $signatureVerified;
    $responseData['verification_method'] = $verificationMethod;
    $responseData['response_timestamp'] = time();
    
    if ($signatureVerified || $verificationMethod === 'certificate') {
        send_signed_response($responseData);
    } else {
        // If no verification provided, still return data but without signature
        echo json_encode($responseData);
    }

} catch (Exception $e) {
    error_log("SACCUSSALIS TRANSACTION STATUS ERROR: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Status query failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
