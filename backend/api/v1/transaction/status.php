<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/transaction/status/index.php
header('Content-Type: application/json');
require_once '../../../db.php';

$trace = $_GET['trace'] ?? null;

if (!$trace) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Trace number required']);
    exit;
}

try {
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
        echo json_encode([
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $pin['is_redeemed'] ? 'REDEEMED' : 'ACTIVE',
            'amount' => $pin['amount'],
            'type' => 'EWALLET_PIN',
            'timestamp' => $pin['created_at'],
            'expires_at' => $pin['expires_at'],
            'is_redeemed' => (bool)$pin['is_redeemed'],
            'redeemed_at' => $pin['redeemed_at']
        ]);
        exit;
    }

    // Check sat_tokens
    $stmt = $pdo->prepare("
        SELECT s.*, c.phone, c.instrument_type
        FROM sat_tokens s
        JOIN cash_instruments c ON s.instrument_id = c.instrument_id
        WHERE s.sat_code = ? OR s.auth_code = ?
    ");
    $stmt->execute([$trace, $trace]);
    $sat = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sat) {
        echo json_encode([
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $sat['status'],
            'amount' => $sat['amount'],
            'type' => 'SAT_TOKEN',
            'timestamp' => $sat['created_at'],
            'expires_at' => $sat['expires_at']
        ]);
        exit;
    }

    // Check transactions table
    $stmt = $pdo->prepare("
        SELECT * FROM transactions 
        WHERE reference = ? OR transaction_id::text = ?
    ");
    $stmt->execute([$trace, $trace]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($transaction) {
        echo json_encode([
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $transaction['status'],
            'amount' => $transaction['amount'],
            'type' => $transaction['type'],
            'timestamp' => $transaction['created_at']
        ]);
        exit;
    }

    // Check atm_authorizations
    $stmt = $pdo->prepare("SELECT * FROM atm_authorizations WHERE trace_number = ? OR auth_code = ?");
    $stmt->execute([$trace, $trace]);
    $auth = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($auth) {
        echo json_encode([
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $auth['response_code'] == '00' ? 'AUTHORIZED' : 'DECLINED',
            'amount' => $auth['amount'],
            'type' => 'ATM_AUTHORIZATION',
            'timestamp' => $auth['created_at']
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Status query failed']);
}
