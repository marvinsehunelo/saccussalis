<?php
// cazacom_ussd_handler.php — USSD callback endpoint for CazaCom
// This endpoint expects CazaCom to POST JSON or form-data with fields like sessionId, msisdn, text.
// Responds with plain text: "CON ..." to continue or "END ..." to finish.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config/integration.php';

// Simple verification: shared secret header (provider should send this)
$incomingSecret = $_SERVER['HTTP_X_CAZACOM_SECRET'] ?? ($_POST['secret'] ?? null);
if (!empty($config['CAZACOM_WEBHOOK_SECRET'])) {
    if (!$incomingSecret || hash_equals($config['CAZACOM_WEBHOOK_SECRET'], $incomingSecret) === false) {
        http_response_code(403);
        echo "END Invalid request";
        exit;
    }
}

// Accept JSON or form data
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: $_POST;

// Extract common fields (tolerant)
$sessionId = $payload['sessionId'] ?? $payload['session_id'] ?? $payload['session'] ?? null;
$msisdn = $payload['msisdn'] ?? $payload['phoneNumber'] ?? $payload['msisdn'] ?? null;
$text = $payload['text'] ?? $payload['message'] ?? $payload['ussdString'] ?? '';

// normalize
$msisdn_norm = $msisdn ? ltrim(str_replace(' ', '', $msisdn), '+') : null;
$text = trim($text);

// If start of session
if ($text === '' || $text === null) {
    // Show menu
    header('Content-Type: text/plain; charset=utf-8');
    echo "CON Welcome to Saccusalis Wallet\n1. Redeem PIN\n2. Regenerate PIN\n0. Exit";
    exit;
}

// Split by '*' as many USSD gateways give replies like "1*123456"
$parts = explode('*', $text);
$option = $parts[0];

// Redeem flow
if ($option === '1') {
    // If PIN provided in same string: "1*PIN"
    if (isset($parts[1]) && $parts[1] !== '') {
        $pin = trim($parts[1]);
        $reply = ussd_redeem_pin_internal($msisdn_norm, $pin);
        header('Content-Type: text/plain; charset=utf-8');
        echo "END " . $reply;
        exit;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "CON Enter 6-digit PIN to redeem:";
        exit;
    }
}

// Regenerate flow
if ($option === '2') {
    // If transaction id provided in same input: "2*123"
    if (isset($parts[1]) && $parts[1] !== '') {
        $transaction_id = intval($parts[1]);
        $reply = ussd_regenerate_pin_internal($msisdn_norm, $transaction_id);
        header('Content-Type: text/plain; charset=utf-8');
        echo "END " . $reply;
        exit;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "CON Enter transaction ID to regenerate PIN:";
        exit;
    }
}

if ($option === '0') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "END Thank you for using Saccusalis";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "END Invalid option";

// ----------------- internal helpers -----------------

function ussd_redeem_pin_internal($msisdn, $pin) {
    global $pdo;
    if (!$msisdn || !$pin) return "Invalid input.";

    $msisdn_cmp = ltrim($msisdn, '+');

    $stmt = $pdo->prepare("
        SELECT ep.id, ep.pin, ep.is_redeemed, ep.expires_at, t.amount, t.transaction_id, t.to_account
        FROM ewallet_pins ep
        JOIN transactions t ON ep.transaction_id = t.transaction_id
        WHERE ep.pin = ? 
          AND (REPLACE(t.to_account, '+', '') = ? OR t.to_account = ?)
        LIMIT 1
    ");
    $stmt->execute([$pin, $msisdn_cmp, $msisdn]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return "Invalid PIN or phone number.";
    if ((int)$row['is_redeemed'] === 1) return "This PIN has already been redeemed.";
    if (strtotime($row['expires_at']) < time()) return "PIN expired.";

    // find recipient wallet
    $stmt = $pdo->prepare("SELECT wallet_id, user_id, balance FROM wallets WHERE phone = ? OR REPLACE(phone,'+','') = ? LIMIT 1");
    $stmt->execute([$msisdn, $msisdn_cmp]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) return "Recipient wallet not found.";

    try {
        $pdo->beginTransaction();
        $new_balance = $wallet['balance'] + $row['amount'];
        $stmt = $pdo->prepare("UPDATE wallets SET balance = ? WHERE wallet_id = ?");
        $stmt->execute([$new_balance, $wallet['wallet_id']]);
        $stmt = $pdo->prepare("UPDATE ewallet_pins SET is_redeemed = 1, redeemed_at = NOW() WHERE id = ?");
        $stmt->execute([$row['id']]);
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, from_account, to_account, amount, type, status, created_at)
                               VALUES (?, ?, ?, ?, 'wallet_receive', 'completed', NOW())");
        $stmt->execute([ $wallet['user_id'] ?? 0, 'PIN-' . $row['pin'], $wallet['wallet_id'], $row['amount'] ]);
        $pdo->commit();

        return "PIN redeemed. Amount {$row['amount']} credited. New balance: {$new_balance}";
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("USSD redeem error: " . $e->getMessage());
        return "Error while processing redemption. Try again later.";
    }
}

function ussd_regenerate_pin_internal($msisdn, $transaction_id) {
    global $pdo, $config;
    $msisdn_cmp = ltrim($msisdn, '+');

    $stmt = $pdo->prepare("SELECT t.transaction_id, t.amount FROM transactions t WHERE t.transaction_id = ? LIMIT 1");
    $stmt->execute([$transaction_id]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) return "Transaction not found.";

    $stmt = $pdo->prepare("SELECT wallet_id, user_id, balance FROM wallets WHERE phone = ? OR REPLACE(phone,'+','') = ? LIMIT 1");
    $stmt->execute([$msisdn, $msisdn_cmp]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) return "Recipient wallet not found.";

    // fee from settings or default
    $fee = 5.00;

    if ($wallet['balance'] < $fee) return "Insufficient balance to pay reissue fee.";

    try {
        $pdo->beginTransaction();
        $new_balance = $wallet['balance'] - $fee;
        $stmt = $pdo->prepare("UPDATE wallets SET balance = ? WHERE wallet_id = ?");
        $stmt->execute([$new_balance, $wallet['wallet_id']]);

        $new_pin = random_int(100000,999999);
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $stmt = $pdo->prepare("INSERT INTO ewallet_pins (transaction_id, pin, is_redeemed, regenerated_by, reissue_fee, expires_at) VALUES (?, ?, 0, ?, ?, ?)");
        $stmt->execute([$transaction_id, $new_pin, $wallet['user_id'], $fee, $expires_at]);

        $stmt = $pdo->prepare("INSERT INTO wallet_transactions (user_id, recipient_identifier, transaction_type, amount, status, created_at) VALUES (?, ?, 'pin_reissue_fee', ?, 'completed', NOW())");
        $stmt->execute([$wallet['user_id'], $msisdn, $fee]);

        $pdo->commit();

        // send SMS by calling sendSmsToCazaCom if available
        if (function_exists('sendSmsToCazaCom')) {
            sendSmsToCazaCom($msisdn, "New PIN: {$new_pin}. Expires in 15 minutes.");
        }

        return "New PIN sent via SMS. Fee BWP {$fee} charged. New balance: {$new_balance}";
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("USSD regenerate error: " . $e->getMessage());
        return "Failed to regenerate PIN. Try later.";
    }
}
