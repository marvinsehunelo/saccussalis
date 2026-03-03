<?php
// zurubank/backend/transactions/external_transfer.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';      // 
require_once __DIR__ . '/../config/hmac.php';    // function generate_hmac()

// Central bank configuration
$CENTRAL_BANK_URL = 'http://localhost/centralbank/api/submit_transfer.php';
$CENTRAL_BANK_SECRET = 'supersecret-for-zuru';
$CENTRAL_BANK_CODE = 'ZUR001'; // ZuruBank code at central bank

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success'=>false,'message'=>'Method not allowed']);
        exit;
    }

    // Check login
    $logged_in_user_id = $_SESSION['user']['id'] ?? null;
    $api_token = $_SESSION['authToken'] ?? null;

    if (!$logged_in_user_id || !$api_token) {
        throw new Exception('User not logged in or auth token missing.');
    }

    // Grab POST data
    $source_account      = $_POST['source'] ?? null;
    $recipient_bank_name = $_POST['recipient_bank_name'] ?? null;
    $recipient_bank_code = $_POST['recipient_bank_code'] ?? null;
    $recipient_account   = $_POST['recipient_account_number'] ?? null;
    $amount              = isset($_POST['amount']) ? (float)$_POST['amount'] : null;

    if (!$source_account || !$recipient_bank_name || !$recipient_bank_code || !$recipient_account || !$amount || $amount <= 0) {
        throw new Exception('Missing or invalid input data.');
    }

    // Start transaction
    $pdo->beginTransaction();

    // Check ownership & balance
    $stmt = $pdo->prepare("SELECT account_id, user_id, balance FROM accounts WHERE account_number=? AND user_id=? FOR UPDATE");
    $stmt->execute([$source_account, $logged_in_user_id]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        throw new Exception('Source account not found or not owned by user.');
    }
    if ($source['balance'] < $amount) {
        throw new Exception('Insufficient balance.');
    }

    // Debit source account
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id=?");
    $stmt->execute([$amount, $source['account_id']]);

    // Log transaction
    $stmt = $pdo->prepare("
        INSERT INTO transactions (account_id, user_id, type, amount, description, status, created_at)
        VALUES (?, ?, 'transfer', ?, ?, 'pending', NOW())
    ");
    $description = "External transfer to {$recipient_bank_name} ({$recipient_account})";
    $stmt->execute([$source['account_id'], $logged_in_user_id, $amount, $description]);
    $transaction_id = $pdo->lastInsertId();

    // Insert into external_transfer_queue
    $stmt = $pdo->prepare("
        INSERT INTO external_transfer_queue 
        (transaction_id, user_id, source_account, recipient_bank_name, recipient_bank_code, recipient_account, amount, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_central_bank', NOW())
    ");
    $stmt->execute([$transaction_id, $logged_in_user_id, $source_account, $recipient_bank_name, $recipient_bank_code, $recipient_account, $amount]);

    // Prepare payload for central bank
    $payload = [
        'sender_bank_code'        => $CENTRAL_BANK_CODE,
        'sender_account'          => $source_account,
        'recipient_bank_code'     => $recipient_bank_code,
        'recipient_account'       => $recipient_account,
        'amount'                  => $amount,
        'reference_code'          => $transaction_id,
        'origin_transaction_id'   => $transaction_id,
        'origin_callback_url'     => 'http://localhost/zurubank/backend/api/bank_callback.php',
        'timestamp'               => time()
    ];

    $signature = generate_hmac($payload, $CENTRAL_BANK_SECRET);

    // Send to central bank
    $ch = curl_init($CENTRAL_BANK_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        "Authorization: Bearer {$api_token}",
        "X-Request-Signature: {$signature}"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    $centralResp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        // Rollback ZuruBank DB changes
        $pdo->rollBack();
        throw new Exception('Central bank connection error: '.$curlErr);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Transfer submitted to Central Bank. Pending approval.',
        'transaction_id' => $transaction_id,
        'central_response' => json_decode($centralResp,true) ?: $centralResp
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
