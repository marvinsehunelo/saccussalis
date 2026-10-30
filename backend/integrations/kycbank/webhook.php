<?php
// backend/integrations/kycbank/webhook.php
//
// Public endpoint KYC Bank POSTs consent and profile events to:
//   https://<bank domain>/backend/integrations/kycbank/webhook.php
//
// Body: {"id":"evt_...","event":"consent.approved","occurredAt":"...","consentId":"cns_...","profileVersion":3}
// Headers: x-kyc-signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">,
//          x-kyc-event, x-kyc-delivery.
//
// No admin session: the signature (KYCBANK_WEBHOOK_SECRET) is the
// authentication. Answers 200 for events processed or deliberately ignored
// (duplicates, unknown consent, test pings), 401 for a bad signature and 503
// for a failure worth retrying (KYC Bank retries non-2xx for about a day).
// Responses and logs carry ids only.

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function kb_webhook_reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    kb_webhook_reply(405, ['error' => 'method_not_allowed']);
}

// The raw body, before anything decodes it: the signature covers these exact bytes.
$rawBody = (string)file_get_contents('php://input');

require_once __DIR__ . '/KycBankClient.php';

$secret = KycBankClient::webhookSecret();
if ($secret === '') {
    error_log('[KycBank webhook] KYCBANK_WEBHOOK_SECRET is not set; event refused');
    kb_webhook_reply(503, ['error' => 'not_configured']);
}
$signature = $_SERVER['HTTP_X_KYC_SIGNATURE'] ?? null;
if (!KycBankClient::verifyWebhookSignature($rawBody, is_string($signature) ? $signature : null, $secret, time())) {
    error_log('[KycBank webhook] rejected: missing, invalid or stale signature (delivery '
        . preg_replace('/[^A-Za-z0-9_\-]/', '', substr((string)($_SERVER['HTTP_X_KYC_DELIVERY'] ?? '-'), 0, 80)) . ')');
    kb_webhook_reply(401, ['error' => 'invalid_signature']);
}

$event = json_decode($rawBody, true);
$eventId = is_array($event) ? ($event['id'] ?? null) : null;
if (!is_array($event) || !is_string($eventId) || !preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $eventId) || !is_string($event['event'] ?? null)) {
    error_log('[KycBank webhook] signed body is not a KYC Bank event; ignored');
    kb_webhook_reply(400, ['error' => 'bad_event']);
}

if (!empty($event['test']) || ($event['consentId'] ?? null) === 'cns_test') {
    error_log("[KycBank webhook] test event {$eventId} received");
    kb_webhook_reply(200, ['received' => true, 'eventId' => $eventId, 'outcome' => 'test']);
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/kycbank.php';

if (!$pdo) {
    error_log("[KycBank webhook] event {$eventId}: database unavailable; asking KYC Bank to retry");
    kb_webhook_reply(503, ['error' => 'unavailable', 'eventId' => $eventId]);
}

try {
    $result = kb_handle_event($pdo, KycBankClient::fromEnv(), $event);
} catch (Throwable $e) {
    error_log("[KycBank webhook] event {$eventId} failed: " . get_class($e) . ' ' . ($e instanceof KycBankException ? $e->errorCode : ''));
    kb_webhook_reply(503, ['error' => 'retry', 'eventId' => $eventId]);
}

$consentId = is_string($event['consentId'] ?? null) ? preg_replace('/[^A-Za-z0-9_\-]/', '', substr($event['consentId'], 0, 100)) : '-';
$eventName = preg_replace('/[^a-z._]/', '', substr($event['event'], 0, 60));
error_log("[KycBank webhook] event {$eventId} ({$eventName}) consent {$consentId}: {$result['outcome']}");
kb_webhook_reply($result['status'], ['received' => $result['status'] === 200, 'eventId' => $eventId, 'outcome' => $result['outcome']]);
