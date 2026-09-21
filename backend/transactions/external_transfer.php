<?php
// saccussalis/backend/transactions/external_transfer.php
//
// A signed-in SaccusSalis customer sends money to an account at another
// bank. The transfer goes to the central bank, which settles it between
// the banks' settlement accounts; the outcome comes back to
// backend/api/bank_callback.php.
//
// Flow: lock the customer's account -> debit amount + fee -> record the
// transaction as pending -> submit to the central bank -> commit only if
// the central bank accepted it. If the central bank refuses or cannot be
// reached, everything is rolled back: the customer is never left debited
// for a transfer that was not queued.
//
// Settings (Railway variables):
//   CENTRAL_BANK_URL         default https://centralbank-production.up.railway.app
//   CENTRAL_BANK_API_SECRET  SaccusSalis' signing secret at the central bank (required)
//   SACCUSSALIS_PUBLIC_URL   default https://saccussalis-production.up.railway.app
//
// Accepts what the dashboard sends (source, external_account, amount,
// bank_name) and the explicit form (recipient_account_number,
// recipient_bank_code). Replies with both "status" and "success".
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

const SACCUSSALIS_CODE = 'SACCUSSALIS';

function reply(int $code, bool $ok, string $message, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['status' => $ok ? 'success' : 'error', 'success' => $ok, 'message' => $message] + $extra);
    exit;
}

// Bank names the dashboard offers, mapped to central bank codes.
function bank_code_for(string $nameOrCode): ?string {
    $k = strtolower(preg_replace('/[^a-z]/i', '', $nameOrCode));
    $map = [
        'zurubank' => 'ZURUBANK', 'zuru' => 'ZURUBANK',
        'absa' => 'ABSA', 'absabank' => 'ABSA', 'absabankbotswana' => 'ABSA',
        'cazacom' => 'CAZACOM', 'cazacommobilemoney' => 'CAZACOM',
        'mtn' => 'MTN', 'mtnmomo' => 'MTN', 'mtnmobilemoney' => 'MTN',
        'saccussalis' => 'SACCUSSALIS', 'saccussalisbank' => 'SACCUSSALIS',
    ];
    return $map[$k] ?? null;
}

$userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, false, 'Method not allowed');
if (!$userId) reply(401, false, 'Please sign in again.');

$source = trim((string)($_POST['source'] ?? ''));
$recipientAccount = trim((string)($_POST['recipient_account_number'] ?? $_POST['external_account'] ?? ''));
$bankInput = (string)($_POST['recipient_bank_code'] ?? $_POST['bank_name'] ?? $_POST['recipient_bank_name'] ?? '');
$recipientBank = bank_code_for($bankInput) ?? strtoupper(trim($bankInput));
$amount = round((float)($_POST['amount'] ?? 0), 2);

if ($source === '' || $recipientAccount === '' || $recipientBank === '' || $amount <= 0) reply(400, false, 'Missing or invalid transfer details.');
if ($recipientBank === SACCUSSALIS_CODE) reply(400, false, 'That account is at SaccusSalis. Use an internal transfer instead.');
if (!in_array($recipientBank, ['ZURUBANK', 'ABSA', 'CAZACOM', 'MTN'], true)) reply(400, false, 'Unknown recipient bank.');

$secret = getenv('CENTRAL_BANK_API_SECRET');
if (!$secret) { error_log('[external_transfer] CENTRAL_BANK_API_SECRET not set'); reply(503, false, 'Interbank transfers are not available right now.'); }
$centralUrl = rtrim(getenv('CENTRAL_BANK_URL') ?: 'https://centralbank-production.up.railway.app', '/') . '/api/submit_transfer.php';
$callbackUrl = rtrim(getenv('SACCUSSALIS_PUBLIC_URL') ?: 'https://saccussalis-production.up.railway.app', '/') . '/backend/api/bank_callback.php';

// Same fee the dashboard shows the customer: 1.5%, minimum P2. It stays with SaccusSalis.
$fee = max(2.00, round($amount * 0.015, 2));
$totalDebit = $amount + $fee;
$reference = 'SAC' . gmdate('ymdHis') . strtoupper(bin2hex(random_bytes(3)));   // 21 chars, unique

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT account_id, balance, is_frozen FROM accounts WHERE account_number = ? AND user_id = ? FOR UPDATE");
    $stmt->execute([$source, $userId]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) throw new DomainException('Source account not found.');
    if (!empty($acc['is_frozen']) && $acc['is_frozen'] !== 'f') throw new DomainException('This account is frozen.');
    if ((float)$acc['balance'] < $totalDebit) throw new DomainException(sprintf('Insufficient balance: P%.2f needed including the P%.2f fee.', $totalDebit, $fee));

    $pdo->prepare("UPDATE accounts SET balance = balance - ?, updated_at = NOW() WHERE account_id = ?")->execute([$totalDebit, $acc['account_id']]);
    $ins = $pdo->prepare("
        INSERT INTO transactions (user_id, reference, from_account, to_account, amount, fee_amount, type, direction, channel, status, notes)
        VALUES (?, ?, ?, ?, ?, ?, 'interbank_transfer', 'out', 'central_bank', 'pending', ?)
        RETURNING transaction_id
    ");
    $ins->execute([$userId, $reference, $source, $recipientAccount, $amount, $fee, "To {$recipientBank} account {$recipientAccount}"]);
    $txId = (int)$ins->fetchColumn();

    $payload = [
        'sender_bank_code' => SACCUSSALIS_CODE,
        'sender_account' => $source,
        'recipient_bank_code' => $recipientBank,
        'recipient_account' => $recipientAccount,
        'amount' => $amount,
        'fee' => 0,
        'reference_code' => $reference,
        'origin_transaction_id' => (string)$txId,
        'origin_callback_url' => $callbackUrl,
        'timestamp' => time(),
    ];
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $ch = curl_init($centralUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: HMAC ' . SACCUSSALIS_CODE . ':' . hash_hmac('sha256', $raw, $secret)],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $resp = json_decode((string)$body, true) ?: [];

    if ($err || !in_array($code, [200, 202], true) || empty($resp['success'])) {
        $pdo->rollBack();
        error_log("[external_transfer] central bank refused {$reference}: HTTP {$code} {$err} " . substr((string)$body, 0, 300));
        reply(502, false, 'The central bank did not accept the transfer' . (!empty($resp['message']) ? ': ' . $resp['message'] : '.') . ' You have not been charged.');
    }

    $pdo->prepare("UPDATE transactions SET notes = ?, updated_at = NOW() WHERE transaction_id = ?")
        ->execute(["To {$recipientBank} account {$recipientAccount}; central bank transfer " . ($resp['transfer_id'] ?? '?'), $txId]);
    $pdo->commit();

    reply(200, true, sprintf('Transfer of P%.2f to %s submitted. It will complete when the central bank settles it.', $amount, $recipientBank), [
        'transaction_id' => $txId, 'reference' => $reference, 'fee' => $fee, 'central_transfer_id' => $resp['transfer_id'] ?? null,
    ]);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    reply(400, false, $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[external_transfer] ' . $e->getMessage());
    reply(500, false, 'The transfer could not be sent. You have not been charged.');
}
