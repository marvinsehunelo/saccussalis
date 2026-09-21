<?php
// saccussalis/backend/api/bank_callback.php
//
// Receives the central bank's settlement notices. Before, it accepted any
// request without a signature and credited whatever account it named, so
// anyone could create money at SaccusSalis. Now:
//  - every notice must carry the central bank's signature
//    (X-CB-Callback-Timestamp, X-CB-Callback-Signature: sha256=HMAC of
//    "<timestamp>.<raw body>" with CENTRAL_BANK_CALLBACK_SECRET) and be
//    less than five minutes old;
//  - each notice is processed once (central_bank_notices table), so a
//    repeated notice cannot credit or refund twice;
//  - "role" decides what happens:
//      sender    approved -> our outgoing transfer is completed
//                rejected -> the customer is refunded amount + fee
//      recipient approved -> the named SaccusSalis account is credited
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

function reply(int $code, string $status, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

$raw = file_get_contents('php://input');
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
$ts = $headers['x-cb-callback-timestamp'] ?? ($_SERVER['HTTP_X_CB_CALLBACK_TIMESTAMP'] ?? '');
$sig = $headers['x-cb-callback-signature'] ?? ($_SERVER['HTTP_X_CB_CALLBACK_SIGNATURE'] ?? '');
$secret = getenv('CENTRAL_BANK_CALLBACK_SECRET');

if (!$secret) { error_log('[bank_callback] CENTRAL_BANK_CALLBACK_SECRET not set'); reply(503, 'error', 'Not configured'); }
$when = strtotime((string)$ts);
if (!$when || abs(time() - $when) > 300) reply(401, 'error', 'Missing or stale timestamp');
if (!str_starts_with((string)$sig, 'sha256=') || !hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), substr((string)$sig, 7))) {
    reply(401, 'error', 'Invalid signature');
}

$n = json_decode($raw, true);
if (!is_array($n) || empty($n['transfer_id']) || empty($n['status']) || empty($n['role'])) reply(400, 'error', 'Invalid notice');
$transferId = (int)$n['transfer_id'];
$role = $n['role'] === 'recipient' ? 'recipient' : 'sender';
$status = (string)$n['status'];
$amount = round((float)($n['amount'] ?? 0), 2);

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS central_bank_notices (
            transfer_id BIGINT NOT NULL,
            role VARCHAR(10) NOT NULL,
            status VARCHAR(20) NOT NULL,
            payload JSONB,
            processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
            PRIMARY KEY (transfer_id, role)
        )
    ");
    $pdo->beginTransaction();
    $claim = $pdo->prepare("INSERT INTO central_bank_notices (transfer_id, role, status, payload) VALUES (?, ?, ?, ?::jsonb) ON CONFLICT DO NOTHING");
    $claim->execute([$transferId, $role, $status, $raw]);
    if ($claim->rowCount() === 0) { $pdo->rollBack(); reply(200, 'success', 'Already processed'); }

    if ($role === 'sender') {
        $stmt = $pdo->prepare("
            SELECT transaction_id, user_id, from_account, amount, fee_amount, status FROM transactions
            WHERE reference = ? AND direction = 'out' FOR UPDATE
        ");
        $stmt->execute([(string)($n['reference_code'] ?? '')]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tx) throw new DomainException('No outgoing transfer with reference ' . ($n['reference_code'] ?? '?'));
        if ($tx['status'] !== 'pending') { $pdo->commit(); reply(200, 'success', 'Transfer already ' . $tx['status']); }

        if ($status === 'approved') {
            $pdo->prepare("UPDATE transactions SET status = 'completed', updated_at = NOW() WHERE transaction_id = ?")->execute([$tx['transaction_id']]);
            $msg = 'Transfer completed';
        } elseif ($status === 'rejected') {
            $refund = (float)$tx['amount'] + (float)$tx['fee_amount'];
            $pdo->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE account_number = ?")->execute([$refund, $tx['from_account']]);
            $pdo->prepare("UPDATE transactions SET status = 'failed', notes = COALESCE(notes, '') || ?, updated_at = NOW() WHERE transaction_id = ?")
                ->execute([' | Rejected by central bank: ' . substr((string)($n['message'] ?? ''), 0, 200) . sprintf('; refunded P%.2f', $refund), $tx['transaction_id']]);
            $msg = 'Transfer rejected; customer refunded';
        } else {
            throw new DomainException('Unknown status ' . $status);
        }
    } else {
        if ($status !== 'approved') { $pdo->commit(); reply(200, 'success', 'Nothing to credit'); }
        if ($amount <= 0) throw new DomainException('Invalid amount');
        $account = (string)($n['recipient_account_number'] ?? '');
        $stmt = $pdo->prepare("SELECT account_id, user_id FROM accounts WHERE account_number = ? FOR UPDATE");
        $stmt->execute([$account]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) throw new DomainException('Recipient account not found at SaccusSalis');

        $pdo->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE account_id = ?")->execute([$amount, $acc['account_id']]);
        $pdo->prepare("
            INSERT INTO transactions (user_id, reference, from_account, to_account, amount, type, direction, channel, status, notes)
            VALUES (?, ?, ?, ?, ?, 'interbank_transfer', 'in', 'central_bank', 'completed', ?)
        ")->execute([$acc['user_id'], 'CB-' . $transferId, (string)($n['from_account'] ?? ''), $account, $amount,
                     'From ' . ($n['from_bank_code'] ?? '?') . ' via central bank transfer ' . $transferId]);
        $msg = 'Recipient credited';
    }
    $pdo->commit();
    reply(200, 'success', $msg);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    reply(422, 'error', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[bank_callback] ' . $e->getMessage());
    reply(500, 'error', 'Notice could not be processed');
}
