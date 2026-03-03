<?php
// saccussalis/backend/api/bank_callback.php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

// Get raw JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!$input || !isset($input['recipient_account_number'], $input['amount'], $input['status'])) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid callback']);
    exit;
}

$recipient_account = $input['recipient_account_number'];
$amount = floatval($input['amount']);
$status = $input['status']; // 'approved' or 'rejected'
$from_bank = $input['from_bank_code'] ?? null;
$from_account = $input['from_account'] ?? null;

// Begin transaction
try {
    $pdo->beginTransaction();

    // --- Get recipient account and associated user ---
    $stmt = $pdo->prepare("SELECT account_id, user_id FROM accounts WHERE account_number=? FOR UPDATE");
    $stmt->execute([$recipient_account]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("Recipient account not found");
    }

    $user_id = $account['user_id'];

    // If no user is linked, create a placeholder "system" user
    if (!$user_id) {
        $stmt = $pdo->prepare("INSERT INTO users (full_name, email, role, status, phone) VALUES (?, ?, 'customer', 'active', ?)");
        $stmt->execute(["Interbank Recipient", "interbank+{$recipient_account}@system.local", "0000000000"]);
        $user_id = $pdo->lastInsertId();

        // Link the user to the account
        $stmt = $pdo->prepare("UPDATE accounts SET user_id=? WHERE account_id=?");
        $stmt->execute([$user_id, $account['account_id']]);
    }

    // --- Handle approved transfer ---
    if ($status === 'approved') {
        // Credit recipient account
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id=?");
        $stmt->execute([$amount, $account['account_id']]);

        // Insert into transactions table
        $stmt = $pdo->prepare("
            INSERT INTO transactions
            (user_id, from_account, to_account, amount, type, status, created_at, external_bank_id, reason)
            VALUES (?, ?, ?, ?, 'credit', 'success', NOW(), ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $from_account,
            $recipient_account,
            $amount,
            $from_bank,
            'Interbank transfer received'
        ]);

        $pdo->commit();
        echo json_encode(['status'=>'success','message'=>'Recipient credited and transaction logged']);
        exit;
    }
    // --- Handle rejected transfer ---
    elseif ($status === 'rejected') {
        // Optional: log a failed transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions
            (user_id, from_account, to_account, amount, type, status, created_at, external_bank_id, reason)
            VALUES (?, ?, ?, ?, 'credit', 'failed', NOW(), ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $from_account,
            $recipient_account,
            $amount,
            $from_bank,
            'Interbank transfer rejected'
        ]);

        $pdo->commit();
        echo json_encode(['status'=>'success','message'=>'Transfer rejected, logged transaction']);
        exit;
    } else {
        throw new Exception("Unknown status: {$status}");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    exit;
}
