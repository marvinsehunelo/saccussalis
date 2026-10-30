<?php
// backend/admin/admin_kycbank_action.php
//
// Staff actions on KYC Bank requests, posted from
// frontend/dashboard/admin_kycbank.php (CSRF-protected forms):
//   request  - ask KYC Bank for a customer's KYC (phone or Omang)
//   resend   - send a request that did not reach KYC Bank again, same key
//   refresh  - GET the consent from KYC Bank (if a webhook was missed)
//   approve  - approve the customer's KYC using the fetched profile
//   reject   - reject it
// Then redirects back to the page with a message.

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../integrations/kycbank/admin_guard.php';
$admin = kb_require_admin('../../frontend/admin/admin_login.php');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../integrations/kycbank/kycbank.php';

$userId = (int)($_POST['user_id'] ?? 0);
$back   = '../../frontend/dashboard/admin_kycbank.php' . ($userId > 0 ? '?user_id=' . $userId : '');

function kb_done(string $back, string $message, bool $ok): void
{
    kb_flash($message, $ok);
    header('Location: ' . $back, true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $back, true, 303);
    exit;
}
if (!kb_csrf_ok()) {
    kb_done($back, 'Your session expired. Please try again.', false);
}
if (!$admin['can_act']) {
    kb_done($back, 'Your role can view KYC Bank requests but not act on them.', false);
}
if (!$pdo) {
    kb_done($back, 'The database is not reachable right now. Please try again shortly.', false);
}
if ($userId <= 0) {
    kb_done($back, 'Choose a customer first.', false);
}

$action    = (string)($_POST['action'] ?? '');
$requestId = (int)($_POST['request_id'] ?? 0);

try {
    $user = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ?');
    $user->execute([$userId]);
    if (!$user->fetchColumn()) {
        kb_done($back, 'Customer not found.', false);
    }

    $row = null;
    if ($requestId > 0) {
        $row = kb_request($pdo, $requestId);
        if (!$row || (int)$row['user_id'] !== $userId) {
            kb_done($back, 'That KYC Bank request does not belong to this customer.', false);
        }
    }

    if (in_array($action, ['request', 'resend', 'refresh'], true)) {
        $client = KycBankClient::fromEnv();
        if ($client === null) {
            kb_done($back, 'KYC Bank is not configured.', false);
        }
    }

    switch ($action) {
        case 'request':
        case 'resend':
            $kind  = (string)($_POST['identifier_kind'] ?? '');
            $value = (string)($kind === 'omang' ? ($_POST['omang'] ?? '') : ($_POST['mobile'] ?? ''));
            $result = kb_create_request(
                $pdo, $client, $userId, $admin['id'], $kind, $value,
                (string)($_POST['level'] ?? 'basic'),
                (string)($_POST['purpose'] ?? KB_DEFAULT_PURPOSE),
                $action === 'resend' ? ($row ? (int)$row['id'] : -1) : null
            );
            kb_done($back, $result['message'], $result['ok']);

        case 'refresh':
            if (!$row) {
                kb_done($back, 'Choose a request to refresh.', false);
            }
            if ($row['state'] === 'withdrawn') {
                kb_done($back, 'This consent was withdrawn; nothing more is fetched from KYC Bank.', true);
            }
            if ($row['consent_id'] === null) {
                kb_done($back, 'This request has not reached KYC Bank yet; send it again instead.', false);
            }
            try {
                $row = kb_sync($pdo, $client, $row, 'if_new');
            } catch (KycBankException $e) {
                $pdo->prepare('UPDATE kycbank_requests SET last_error_code = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([substr($e->errorCode, 0, 60), (int)$row['id']]);
                error_log("[KycBank] refresh of request {$row['id']} failed: {$e->errorCode} (HTTP {$e->httpStatus})");
                kb_done($back, 'KYC Bank: ' . $e->forStaff(), false);
            }
            kb_audit($pdo, 'kycbank_status_refreshed', $userId, $admin['id'], ['request' => (int)$row['id'], 'consent' => $row['consent_id']]);
            kb_done($back, 'Status refreshed: ' . kb_state_label($row['state']) . '.', true);

        case 'approve':
        case 'reject':
            if (!$row) {
                kb_done($back, 'Choose a request first.', false);
            }
            $result = kb_review($pdo, (int)$row['id'], $userId, $action === 'approve' ? 'approved' : 'rejected', $admin['id']);
            kb_done($back, $result['message'], $result['ok']);
    }
    kb_done($back, 'Unknown action.', false);
} catch (Throwable $e) {
    error_log("[KycBank] admin action '{$action}' for user {$userId} failed: " . get_class($e) . ($e instanceof KycBankException ? ' ' . $e->errorCode : ''));
    kb_done($back, $e instanceof KycBankException ? 'KYC Bank: ' . $e->forStaff() : 'Something went wrong. Please try again.', false);
}
