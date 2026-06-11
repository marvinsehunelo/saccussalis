<?php
require_once(__DIR__ . "/../includes/secure_api_header.php"); // $pdo, $user_id
header("Content-Type: application/json; charset=utf-8");

// --- Input ---
$data = json_decode(file_get_contents("php://input"), true);
$recipient_phone = trim($data['recipient_phone'] ?? '');
$amount = floatval($data['amount'] ?? 0);
$from_account_type = $data['from_account_type'] ?? null;
$notes = trim($data['notes'] ?? '');

if (empty($recipient_phone) || $amount <= 0 || empty($from_account_type)) {
    echo json_encode(["status" => "error", "message" => "Recipient phone, valid amount, and account type are required"]);
    exit;
}

// --- Get sender account ---
$stmt = $pdo->prepare("
    SELECT user_id, account_number, account_type, balance 
    FROM accounts 
    WHERE user_id = ? AND account_type = ? 
    LIMIT 1
");
$stmt->execute([$user_id, $from_account_type]);
$sender_account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sender_account) {
    echo json_encode(["status" => "error", "message" => "Sender account not found"]);
    exit;
}

// --- Calculate fees ---
$fee_percentage = 0.015;
$min_fee = 2;
$fee_amount = max($min_fee, round($amount * $fee_percentage, 2));
$total_debit = $amount + $fee_amount;

if ($sender_account['balance'] < $total_debit) {
    echo json_encode(["status" => "error", "message" => "Insufficient balance for amount + fee"]);
    exit;
}

// --- Transaction replay guard ---
$stmt = $pdo->prepare("
    SELECT ep.id
    FROM ewallet_pins ep
    JOIN transactions t ON t.transactions_id = ep.transaction_id
    WHERE t.user_id = ? AND t.amount = ? AND ep.recipient_phone = ? AND ep.created_at > NOW() - INTERVAL '5 seconds'
    LIMIT 1
");
$stmt->execute([$user_id, $amount, $recipient_phone]);
if ($stmt->rowCount() > 0) {
    echo json_encode(["status" => "error", "message" => "Transfer is already in progress or just completed. Please wait."]);
    exit;
}

// --- Get or create recipient wallet ---
$stmt = $pdo->prepare("SELECT wallet_id, balance FROM wallets WHERE phone = ? LIMIT 1");
$stmt->execute([$recipient_phone]);
$recipient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipient) {
    $stmt = $pdo->prepare("
        INSERT INTO wallets (user_id, wallet_type, phone, balance, created_at)
        VALUES (NULL, 'default', ?, 0, NOW())
        RETURNING wallet_id
    ");
    $stmt->execute([$recipient_phone]);
    $recipient_wallet_id = $stmt->fetchColumn();
} else {
    $recipient_wallet_id = $recipient['wallet_id'];
}

// --- Get sender phone ---
$stmt = $pdo->prepare("SELECT phone FROM users WHERE user_id = ? LIMIT 1");
$stmt->execute([$user_id]);
$sender_phone = $stmt->fetchColumn();

if (!$sender_phone) {
    echo json_encode(["status" => "error", "message" => "Sender phone not found"]);
    exit;
}

// --- Begin transaction ---
$pdo->beginTransaction();

try {
    // Deduct from sender
    $stmt = $pdo->prepare("
        UPDATE accounts
        SET balance = balance - :total
        WHERE user_id = :uid AND account_type = :type AND balance >= :total
    ");
    $stmt->execute([
        ':total' => $total_debit,
        ':uid' => $user_id,
        ':type' => $from_account_type
    ]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Deduction failed: insufficient balance or already processed");
    }

    // Credit recipient wallet
    $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE wallet_id = ?");
    $stmt->execute([$amount, $recipient_wallet_id]);

    // Split fees between internal accounts
    $saccus_fee = round($fee_amount * 0.6, 2);
    $cazacom_fee = $fee_amount - $saccus_fee;
    $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE user_id = 42")->execute([$saccus_fee]);
    $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE user_id = 52")->execute([$cazacom_fee]);

    // Log transaction
    $stmt = $pdo->prepare("
        INSERT INTO transactions
        (user_id, from_account, to_account, external_bank_id, amount, fee_amount, type, direction, channel, status, notes, created_at)
        VALUES (?, ?, ?, NULL, ?, ?, 'wallet_transfer', 'out', 'ewallet', 'completed', ?, NOW())
        RETURNING transactions_id
    ");
    $stmt->execute([$user_id, $sender_account['account_number'], $recipient_phone, $amount, $fee_amount, $notes]);
    $transaction_id = $stmt->fetchColumn();

    // Generate PIN & store
    $pin = random_int(100000, 999999);
    $stmt = $pdo->prepare("
        INSERT INTO ewallet_pins (transaction_id, recipient_phone, generated_by, pin, is_redeemed, created_at, expires_at, sender_phone, amount)
        VALUES (?, ?, ?, ?, FALSE, NOW(), NOW() + INTERVAL '15 minutes', ?, ?)
    ");
    $stmt->execute([$transaction_id, $recipient_phone, $user_id, $pin, $sender_phone, $amount]);

    // Commit transaction
    $pdo->commit();

    // Send SMS via your own SMS service (not Cazacom DB)
    $sms_sent = false;
    if (function_exists('sendSmsNotification')) {
        // Send to recipient
        sendSmsNotification($recipient_phone, "You received P$amount via eWallet from Saccussalis. Use PIN: $pin to withdraw. Valid for 15 minutes.");
        // Send to sender
        sendSmsNotification($sender_phone, "You sent P$amount from account {$sender_account['account_number']} to $recipient_phone. Fee: P$fee_amount. Reference: $transaction_id");
        $sms_sent = true;
    } else {
        error_log("SMS notification function not available for transaction $transaction_id");
    }

    echo json_encode([
        "status" => "success",
        "message" => "eWallet transfer successful" . ($sms_sent ? "" : " (SMS notifications skipped)"),
        "transaction_id" => $transaction_id,
        "recipient_phone" => $recipient_phone,
        "pin" => $pin
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("eWallet Transfer Failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Transfer failed: " . $e->getMessage()]);
}
?>
