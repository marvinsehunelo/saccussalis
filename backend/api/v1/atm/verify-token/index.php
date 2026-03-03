<?php
header('Content-Type: application/json');
require_once '../../../../db.php'; // Your PDO/PostgreSQL connection

// Get JSON payload from ATM
$input = json_decode(file_get_contents('php://input'), true);

$sat_number = $input['sat_number'] ?? null;
$pin        = $input['pin'] ?? null;
$atm_id     = $input['atm_id'] ?? null;

if (!$sat_number || !$pin || !$atm_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameters'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Fetch SAT token
    $stmt = $pdo->prepare("SELECT * FROM sat_tokens 
        WHERE sat_number = :sat_number 
        FOR UPDATE");
    $stmt->execute(['sat_number' => $sat_number]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token) {
        throw new Exception('SAT token not found');
    }

    // Check status
    if ($token['status'] !== 'ACTIVE') {
        throw new Exception('SAT token not active');
    }

    // Check expiration
    if (strtotime($token['expires_at']) < time()) {
        throw new Exception('SAT token expired');
    }

    // Check PIN
    if ($token['pin'] !== $pin) {
        // Increment attempts
        $stmt = $pdo->prepare("UPDATE sat_tokens SET attempts = attempts + 1, last_attempt_at = NOW() WHERE sat_id = :id");
        $stmt->execute(['id' => $token['sat_id']]);

        if ($token['attempts'] + 1 >= $token['max_attempts']) {
            $stmt = $pdo->prepare("UPDATE sat_tokens SET status = 'CANCELLED' WHERE sat_id = :id");
            $stmt->execute(['id' => $token['sat_id']]);
            throw new Exception('Max attempts reached, token cancelled');
        }

        throw new Exception('Incorrect PIN');
    }

    // Mark as processing to avoid double spend
    $stmt = $pdo->prepare("UPDATE sat_tokens SET processing = TRUE, last_attempt_at = NOW() WHERE sat_id = :id");
    $stmt->execute(['id' => $token['sat_id']]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'SAT token verified',
        'sat_number' => $token['sat_number'],
        'amount' => $token['amount'],
        'issuer_bank' => $token['issuer_bank'],
        'acquirer_bank' => $token['acquirer_network'],
        'expires_at' => $token['expires_at']
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
