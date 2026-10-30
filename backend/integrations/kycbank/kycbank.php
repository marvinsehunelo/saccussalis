<?php
// backend/integrations/kycbank/kycbank.php
//
// Saccussalis side of the KYC Bank integration:
//   - kycbank_requests: one row per request the bank sends to KYC Bank,
//     with the consent's state and, once the customer approves, the fetched
//     profile (the bank's own KYC record of what KYC Bank shared).
//   - kycbank_webhook_events: webhook event ids already processed, so a
//     repeated delivery is not processed twice.
//
// Tables are created on first use; the same SQL is in
// database/migrations/2026_10_30_kycbank.sql.
//
// Rules:
//   - The raw phone / Omang value used for a request is never stored, only
//     its kind ('mobile' | 'omang').
//   - Logs, audit rows and webhook responses carry ids only (request id,
//     consent id, bank user id, event id), never names, numbers or profile
//     content. The profile is never logged.
//   - users.kyc_status changes only when a staff member approves or rejects
//     using a fetched profile (kb_review). Webhooks never change it.
//   - Once a consent is withdrawn the stored profile is hidden from staff
//     and never fetched again; the copy is kept only for record-keeping.

require_once __DIR__ . '/KycBankClient.php';
/** What users.kyc_status is set to when staff approve KYC based on a KYC Bank profile. */
/** What users.kyc_status is set to on approval: the value backend/admin/admin_approve_kyc.php writes. */
const KB_KYC_APPROVED = 'approved'; // users.kyc_status values: 'pending' | 'approved' | 'rejected'
const KB_KYC_REJECTED = 'rejected';

const KB_DEFAULT_PURPOSE = 'Open and verify your Saccussalis account';
const KB_DURATION_DAYS   = 365;

/** Bank-side states before KYC Bank has the request, plus KYC Bank's own consent states. */
const KB_STATES = ['not_sent', 'failed', 'requested', 'active', 'rejected', 'lapsed', 'withdrawn', 'expired', 'suspended'];
/** States in which a customer already has a request under way (a new one is not offered). */
const KB_OPEN_STATES = ['not_sent', 'requested', 'active', 'suspended'];

function kb_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kycbank_requests (
            id                       BIGSERIAL PRIMARY KEY,
            user_id                  INTEGER NOT NULL REFERENCES users (user_id),
            consent_id               VARCHAR(100) NULL UNIQUE,
            idempotency_key          VARCHAR(100) NOT NULL UNIQUE,
            identifier_kind          VARCHAR(10) NOT NULL CHECK (identifier_kind IN ('mobile', 'omang')),
            purpose                  VARCHAR(200) NOT NULL,
            level                    VARCHAR(10) NOT NULL CHECK (level IN ('basic', 'standard')),
            state                    VARCHAR(20) NOT NULL DEFAULT 'not_sent',
            suspended_reason         VARCHAR(40) NULL,
            requested_at             TIMESTAMPTZ NULL,
            decided_at               TIMESTAMPTZ NULL,
            starts_at                TIMESTAMPTZ NULL,
            ends_at                  TIMESTAMPTZ NULL,
            lapses_at                TIMESTAMPTZ NULL,
            latest_profile_version   INTEGER NULL,
            profile_version          INTEGER NULL,
            profile_json             TEXT NULL,
            profile_fetched_at       TIMESTAMPTZ NULL,
            needs_review             BOOLEAN NOT NULL DEFAULT FALSE,
            reviewed_by              INTEGER NULL,
            reviewed_at              TIMESTAMPTZ NULL,
            reviewed_profile_version INTEGER NULL,
            review_decision          VARCHAR(10) NULL CHECK (review_decision IN ('approved', 'rejected')),
            requested_by             INTEGER NULL,
            last_error_code          VARCHAR(60) NULL,
            created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            withdrawn_at             TIMESTAMPTZ NULL
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_kycbank_requests_user ON kycbank_requests (user_id, created_at DESC)');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kycbank_webhook_events (
            event_id     VARCHAR(100) PRIMARY KEY,
            event        VARCHAR(60) NOT NULL,
            consent_id   VARCHAR(100) NULL,
            received_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            processed_at TIMESTAMPTZ NULL
        )
    ");
    $ready = true;
}

// ----------------------------------------------------------------------
// Reading
// ----------------------------------------------------------------------

function kb_request(PDO $pdo, int $id): ?array
{
    kb_ensure_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM kycbank_requests WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kb_request_by_consent(PDO $pdo, string $consentId): ?array
{
    kb_ensure_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM kycbank_requests WHERE consent_id = ?');
    $stmt->execute([$consentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A customer's requests, newest first. */
function kb_requests_for_user(PDO $pdo, int $userId): array
{
    kb_ensure_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM kycbank_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kb_bool($value): bool
{
    return $value === true || $value === 't' || $value === 1 || $value === '1' || $value === 'true';
}

/** The profile may be shown and used: the consent is active and a profile was fetched. */
function kb_profile_usable(array $row): bool
{
    return $row['state'] === 'active' && $row['profile_json'] !== null && $row['profile_json'] !== '';
}

// ----------------------------------------------------------------------
// Creating a request
// ----------------------------------------------------------------------

/**
 * Sends a consent request for a customer.
 *
 * A fresh request gets a new row (state 'not_sent') and idempotency key,
 * saved before KYC Bank is called. If KYC Bank cannot be reached the row
 * stays 'not_sent' and $retryId sends it again with the same key, so a
 * request that did reach KYC Bank is not created twice.
 *
 * @return array{ok: bool, message: string, request_id?: int}
 */
function kb_create_request(
    PDO $pdo,
    KycBankClient $client,
    int $userId,
    int $adminId,
    string $kind,
    string $value,
    string $level,
    string $purpose,
    ?int $retryId = null
): array {
    kb_ensure_tables($pdo);

    $value = $kind === 'mobile' ? kb_normalize_mobile($value) : preg_replace('/\s+/', '', $value);
    if ($kind === 'mobile' && !KycBankClient::isValidMobile($value)) {
        return ['ok' => false, 'message' => 'Enter the phone number in international form, e.g. +267 7X XXX XXX.'];
    }
    if ($kind === 'omang' && !KycBankClient::isValidOmang($value)) {
        return ['ok' => false, 'message' => 'An Omang number has exactly 9 digits.'];
    }
    if (!in_array($kind, ['mobile', 'omang'], true)) {
        return ['ok' => false, 'message' => 'Choose phone or Omang.'];
    }

    if ($retryId !== null) {
        $row = kb_request($pdo, $retryId);
        if (!$row || (int)$row['user_id'] !== $userId || $row['state'] !== 'not_sent' || $row['consent_id'] !== null) {
            return ['ok' => false, 'message' => 'That request cannot be sent again.'];
        }
        // The key, level and purpose stay as first saved: a repeated key
        // returns KYC Bank's first answer anyway.
        $level   = $row['level'];
        $purpose = $row['purpose'];
        if ($row['identifier_kind'] !== $kind) {
            return ['ok' => false, 'message' => 'Send it again with the same kind of identifier (' . ($row['identifier_kind'] === 'mobile' ? 'phone' : 'Omang') . ').'];
        }
    } else {
        $purpose = trim($purpose);
        $len = function_exists('mb_strlen') ? mb_strlen($purpose, 'UTF-8') : strlen($purpose);
        if ($len < 1 || $len > 200) {
            return ['ok' => false, 'message' => 'The purpose must be between 1 and 200 characters.'];
        }
        if (!in_array($level, ['basic', 'standard'], true)) {
            return ['ok' => false, 'message' => 'Choose the basic or standard level.'];
        }
        $open = $pdo->prepare('SELECT id FROM kycbank_requests WHERE user_id = ? AND state IN (' . implode(',', array_fill(0, count(KB_OPEN_STATES), '?')) . ') LIMIT 1');
        $open->execute(array_merge([$userId], KB_OPEN_STATES));
        if ($open->fetchColumn()) {
            return ['ok' => false, 'message' => 'This customer already has a KYC Bank request under way.'];
        }
        $insert = $pdo->prepare("
            INSERT INTO kycbank_requests (user_id, idempotency_key, identifier_kind, purpose, level, state, requested_by)
            VALUES (?, ?, ?, ?, ?, 'not_sent', ?)
            RETURNING *
        ");
        $insert->execute([$userId, 'ss-' . bin2hex(random_bytes(16)), $kind, $purpose, $level, $adminId]);
        $row = $insert->fetch(PDO::FETCH_ASSOC);
    }
    $rowId = (int)$row['id'];

    try {
        $consent = $client->createConsentRequest($kind, $value, $level, $purpose, (string)$row['idempotency_key'], KB_DURATION_DAYS, true);
    } catch (KycBankException $e) {
        $state = $e->isTransient() ? 'not_sent' : 'failed';
        $pdo->prepare('UPDATE kycbank_requests SET state = ?, last_error_code = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$state, substr($e->errorCode, 0, 60), $rowId]);
        error_log("[KycBank] request {$rowId} for user {$userId} not created: {$e->errorCode} (HTTP {$e->httpStatus})");
        kb_audit($pdo, 'kycbank_request_failed', $userId, $adminId, ['request' => $rowId, 'code' => $e->errorCode]);
        return ['ok' => false, 'request_id' => $rowId, 'message' => $e->isTransient()
            ? 'KYC Bank could not be reached (' . $e->forStaff() . '). Send it again; the same request key is reused.'
            : 'KYC Bank refused the request: ' . $e->forStaff()];
    }

    $consentId = (string)($consent['id'] ?? '');
    if (!KycBankClient::isValidConsentId($consentId)) {
        $pdo->prepare("UPDATE kycbank_requests SET last_error_code = 'bad_response', updated_at = NOW() WHERE id = ?")->execute([$rowId]);
        return ['ok' => false, 'request_id' => $rowId, 'message' => 'KYC Bank answered without a consent id. Send it again.'];
    }
    $pdo->prepare('UPDATE kycbank_requests SET consent_id = ?, last_error_code = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([$consentId, $rowId]);
    kb_store_consent($pdo, $rowId, $consent);
    error_log("[KycBank] request {$rowId} for user {$userId} created as consent {$consentId}");
    kb_audit($pdo, 'kycbank_request_sent', $userId, $adminId, ['request' => $rowId, 'consent' => $consentId]);

    return ['ok' => true, 'request_id' => $rowId,
        'message' => 'Request sent. The customer approves it in the KYC Bank app; this page updates when they do.'];
}

/** Local Botswana numbers (7X XXX XXX) and 267... become +267...; anything else is only stripped of spaces. */
function kb_normalize_mobile(string $raw): string
{
    $v = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';
    if (str_starts_with($v, '00')) {
        $v = '+' . substr($v, 2);
    }
    if (preg_match('/^[1-9]\d{7}$/', $v)) {      // 8-digit local number
        return '+267' . $v;
    }
    if (preg_match('/^267\d{8}$/', $v)) {
        return '+' . $v;
    }
    return $v;
}

// ----------------------------------------------------------------------
// Keeping a request in step with KYC Bank
// ----------------------------------------------------------------------

function kb_ts($value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i:sP');
    } catch (Throwable $e) {
        return null;
    }
}

/** Saves a consent's state and dates. A withdrawn request stays withdrawn. */
function kb_store_consent(PDO $pdo, int $rowId, array $consent): void
{
    $state = (string)($consent['state'] ?? '');
    if (!in_array($state, KB_STATES, true) || in_array($state, ['not_sent', 'failed'], true)) {
        throw new KycBankException('bad_response', 'KYC Bank sent an unknown consent state.', 200);
    }
    $reason = $state === 'suspended' && is_string($consent['suspendedReason'] ?? null)
        ? substr($consent['suspendedReason'], 0, 40) : null;
    $latest = isset($consent['latestProfileVersion']) && is_numeric($consent['latestProfileVersion'])
        ? (int)$consent['latestProfileVersion'] : null;

    $pdo->prepare("
        UPDATE kycbank_requests SET
            state                  = CASE WHEN state = 'withdrawn' THEN state ELSE ? END,
            suspended_reason       = CASE WHEN state = 'withdrawn' THEN suspended_reason ELSE ? END,
            requested_at           = COALESCE(?, requested_at),
            decided_at             = COALESCE(?, decided_at),
            starts_at              = COALESCE(?, starts_at),
            ends_at                = COALESCE(?, ends_at),
            lapses_at              = COALESCE(?, lapses_at),
            latest_profile_version = COALESCE(?, latest_profile_version),
            withdrawn_at           = CASE WHEN ? = 'withdrawn' THEN COALESCE(withdrawn_at, NOW()) ELSE withdrawn_at END,
            updated_at             = NOW()
        WHERE id = ?
    ")->execute([
        $state, $reason,
        kb_ts($consent['requestedAt'] ?? null), kb_ts($consent['decidedAt'] ?? null),
        kb_ts($consent['startsAt'] ?? null), kb_ts($consent['endsAt'] ?? null), kb_ts($consent['lapsesAt'] ?? null),
        $latest,
        $state,
        $rowId,
    ]);
}

/** Records a state named by a webhook event, without asking KYC Bank. */
function kb_set_state(PDO $pdo, int $rowId, string $state, ?string $occurredAt = null): void
{
    $pdo->prepare("
        UPDATE kycbank_requests SET
            state            = CASE WHEN state = 'withdrawn' THEN state ELSE ? END,
            suspended_reason = CASE WHEN ? = 'suspended' THEN suspended_reason ELSE NULL END,
            withdrawn_at     = CASE WHEN ? = 'withdrawn' THEN COALESCE(withdrawn_at, ?::timestamptz, NOW()) ELSE withdrawn_at END,
            updated_at       = NOW()
        WHERE id = ?
    ")->execute([$state, $state, $state, kb_ts($occurredAt), $rowId]);
}

/**
 * Asks KYC Bank for the consent, saves its state and, when it is active,
 * fetches the profile: $fetch 'always', 'if_new' (no profile yet, or KYC
 * Bank has a newer version) or 'never'. Nothing is fetched for a withdrawn
 * request. Returns the request as saved.
 */
function kb_sync(PDO $pdo, KycBankClient $client, array $row, string $fetch = 'if_new'): array
{
    $rowId = (int)$row['id'];
    if ($row['state'] === 'withdrawn' || $row['consent_id'] === null) {
        return $row;
    }
    $consent = $client->getConsentRequest((string)$row['consent_id']);
    kb_store_consent($pdo, $rowId, $consent);
    $row = kb_request($pdo, $rowId);

    if ($row['state'] !== 'active' || $fetch === 'never') {
        return $row;
    }
    $hasNewer = $row['profile_json'] === null
        || ($row['latest_profile_version'] !== null && (int)$row['latest_profile_version'] !== (int)$row['profile_version']);
    if ($fetch === 'always' || $hasNewer) {
        kb_fetch_profile($pdo, $client, $row);
        $row = kb_request($pdo, $rowId);
    }
    return $row;
}

/** Fetches and stores the profile of an active consent. A changed version after a first copy is flagged for staff review. */
function kb_fetch_profile(PDO $pdo, KycBankClient $client, array $row): void
{
    $rowId = (int)$row['id'];
    try {
        $result = $client->getProfileRaw((string)$row['consent_id']);
    } catch (KycBankException $e) {
        if ($e->errorCode === 'consent_not_active') {
            // The consent changed since we looked; record what it is now.
            kb_store_consent($pdo, $rowId, $client->getConsentRequest((string)$row['consent_id']));
            return;
        }
        throw $e;
    }
    $version = isset($result['profile']['profileVersion']) && is_numeric($result['profile']['profileVersion'])
        ? (int)$result['profile']['profileVersion'] : null;

    $stmt = $pdo->prepare("
        UPDATE kycbank_requests SET
            needs_review       = CASE WHEN profile_json IS NOT NULL AND profile_version IS DISTINCT FROM ? THEN TRUE ELSE needs_review END,
            profile_json       = ?,
            profile_version    = ?,
            profile_fetched_at = NOW(),
            last_error_code    = NULL,
            updated_at         = NOW()
        WHERE id = ? AND state = 'active'
    ");
    $stmt->bindValue(1, $version, $version === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(2, $result['raw'], PDO::PARAM_STR);
    $stmt->bindValue(3, $version, $version === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(4, $rowId, PDO::PARAM_INT);
    $stmt->execute();

    error_log("[KycBank] request {$rowId} consent {$row['consent_id']}: profile version " . ($version ?? '?') . ' fetched');
    kb_audit($pdo, 'kycbank_profile_fetched', (int)$row['user_id'], null, ['request' => $rowId, 'consent' => $row['consent_id'], 'version' => $version]);
}

// ----------------------------------------------------------------------
// Webhook events
// ----------------------------------------------------------------------

/**
 * Processes one verified webhook event.
 *
 * @return array{status: int, outcome: string} 200 when processed or
 *         deliberately ignored, 503 when it should be retried.
 */
function kb_handle_event(PDO $pdo, ?KycBankClient $client, array $event): array
{
    kb_ensure_tables($pdo);

    $eventId   = (string)$event['id'];
    $name      = (string)($event['event'] ?? '');
    $consentId = is_string($event['consentId'] ?? null) ? $event['consentId'] : null;

    $pdo->prepare('INSERT INTO kycbank_webhook_events (event_id, event, consent_id) VALUES (?, ?, ?) ON CONFLICT (event_id) DO NOTHING')
        ->execute([$eventId, substr($name, 0, 60), $consentId !== null ? substr($consentId, 0, 100) : null]);
    $seen = $pdo->prepare('SELECT processed_at FROM kycbank_webhook_events WHERE event_id = ?');
    $seen->execute([$eventId]);
    if ($seen->fetchColumn()) {
        return ['status' => 200, 'outcome' => 'duplicate'];
    }

    $done = function (string $outcome) use ($pdo, $eventId): array {
        $pdo->prepare('UPDATE kycbank_webhook_events SET processed_at = NOW() WHERE event_id = ?')->execute([$eventId]);
        return ['status' => 200, 'outcome' => $outcome];
    };

    $row = ($consentId !== null && KycBankClient::isValidConsentId($consentId)) ? kb_request_by_consent($pdo, $consentId) : null;
    if (!$row) {
        return $done('ignored_unknown_consent');
    }
    $rowId = (int)$row['id'];

    $direct = [
        'consent.rejected'  => 'rejected',
        'consent.lapsed'    => 'lapsed',
        'consent.expired'   => 'expired',
        'consent.withdrawn' => 'withdrawn',
    ];
    $sync = ['consent.approved', 'consent.resumed', 'profile.updated', 'consent.suspended', 'profile.stale'];

    if (isset($direct[$name])) {
        kb_set_state($pdo, $rowId, $direct[$name], is_string($event['occurredAt'] ?? null) ? $event['occurredAt'] : null);
        if ($name === 'consent.withdrawn') {
            kb_audit($pdo, 'kycbank_consent_withdrawn', (int)$row['user_id'], null, ['request' => $rowId, 'consent' => $consentId, 'event' => $eventId]);
        }
        return $done('state_' . $direct[$name]);
    }
    if (!in_array($name, $sync, true)) {
        return $done('ignored_event');
    }
    if ($row['state'] === 'withdrawn') {
        return $done('ignored_withdrawn');
    }
    if ($name === 'consent.suspended') {
        // Stop treating the profile as usable straight away; the reason follows from KYC Bank.
        kb_set_state($pdo, $rowId, 'suspended');
    }
    if ($client === null) {
        error_log("[KycBank] event {$eventId} for consent {$consentId} not processed: KYC Bank is not configured");
        return ['status' => 503, 'outcome' => 'not_configured'];
    }

    try {
        $fetch = in_array($name, ['consent.approved', 'consent.resumed', 'profile.updated'], true) ? 'always' : 'never';
        $row = kb_sync($pdo, $client, kb_request($pdo, $rowId), $fetch);
    } catch (KycBankException $e) {
        $pdo->prepare('UPDATE kycbank_requests SET last_error_code = ?, updated_at = NOW() WHERE id = ?')
            ->execute([substr($e->errorCode, 0, 60), $rowId]);
        error_log("[KycBank] event {$eventId} for consent {$consentId}: {$e->errorCode} (HTTP {$e->httpStatus})");
        if ($e->isTransient()) {
            return ['status' => 503, 'outcome' => 'retry'];
        }
        return $done('error_' . $e->errorCode);
    }
    return $done('state_' . $row['state']);
}

// ----------------------------------------------------------------------
// Staff review
// ----------------------------------------------------------------------

/**
 * A staff decision on a fetched profile. Sets users.kyc_status the way
 * backend/admin/admin_approve_kyc.php does ('verified' or 'rejected').
 *
 * @return array{ok: bool, message: string}
 */
function kb_review(PDO $pdo, int $rowId, int $userId, string $decision, int $adminId): array
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'Unknown decision.'];
    }
    kb_ensure_tables($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM kycbank_requests WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$rowId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !kb_profile_usable($row)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'There is no usable KYC Bank profile for this request (the consent must be active).'];
        }
        $pdo->prepare("
            UPDATE kycbank_requests
               SET review_decision = ?, reviewed_by = ?, reviewed_at = NOW(),
                   reviewed_profile_version = profile_version, needs_review = FALSE, updated_at = NOW()
             WHERE id = ?
        ")->execute([$decision, $adminId, $rowId]);
        $status = $decision === 'approved' ? KB_KYC_APPROVED : KB_KYC_REJECTED;
        $pdo->prepare('UPDATE users SET kyc_status = ?, updated_at = NOW() WHERE user_id = ?')->execute([$status, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    error_log("[KycBank] admin {$adminId} {$decision} KYC for user {$userId} using request {$rowId}");
    kb_audit($pdo, 'kycbank_kyc_' . $decision, $userId, $adminId, ['request' => $rowId, 'consent' => $row['consent_id'], 'version' => $row['profile_version'], 'kyc_status' => $status]);
    return ['ok' => true, 'message' => $decision === 'approved'
        ? 'KYC approved using the KYC Bank profile. Customer KYC status is now ' . $status . '.'
        : 'KYC rejected. Customer KYC status is now ' . $status . '.'];
}

/** A short audit row (ids only) in audit_logs. Never throws: auditing must not break the action. */
function kb_audit(PDO $pdo, string $action, ?int $userId, ?int $adminId, array $ids = []): void
{
    try {
        $pdo->prepare("
            INSERT INTO audit_logs (entity, entity_id, category, action, performed_by, ip_address, user_agent, new_value)
            VALUES ('users', ?, 'kyc', ?, ?, ?, ?, ?)
        ")->execute([
            $userId, $action, $adminId,
            isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null,
            isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
            json_encode($ids),
        ]);
    } catch (Throwable $e) {
        error_log("[KycBank] audit '{$action}' not written: " . get_class($e));
    }
}

// ----------------------------------------------------------------------
// Words for staff
// ----------------------------------------------------------------------

function kb_state_label(string $state): string
{
    return [
        'not_sent'  => 'Not sent',
        'failed'    => 'Refused by KYC Bank',
        'requested' => 'Waiting for customer',
        'active'    => 'Active',
        'rejected'  => 'Declined by customer',
        'lapsed'    => 'Lapsed',
        'withdrawn' => 'Withdrawn',
        'expired'   => 'Expired',
        'suspended' => 'Suspended',
    ][$state] ?? $state;
}

function kb_state_help(array $row): string
{
    switch ($row['state']) {
        case 'not_sent':
            return 'The request has not reached KYC Bank yet. Send it again; the same request key is reused, so it is never created twice.';
        case 'failed':
            return 'KYC Bank refused this request. Fix the problem and make a new request.';
        case 'requested':
            return 'Waiting for the customer to approve in the KYC Bank app. KYC Bank never says whether a person has a profile, so a number without one also stays here until the request lapses.';
        case 'active':
            return 'The customer approved. The profile is what KYC Bank verified; Saccussalis still makes its own KYC decision.';
        case 'rejected':
            return 'The customer declined to share their KYC.';
        case 'lapsed':
            return 'The customer did not answer before the request lapsed.';
        case 'expired':
            return 'The consent period has ended. Make a new request if the data is needed again.';
        case 'suspended':
            return kb_suspended_reason_text($row['suspended_reason'] ?? null) . ' The profile must not be used while the consent is suspended.';
        case 'withdrawn':
            return 'Withdrawn by the customer on ' . kb_fmt_date($row['withdrawn_at'] ?? null) . ' — data no longer in use.';
    }
    return '';
}

function kb_suspended_reason_text(?string $reason): string
{
    return [
        'profile_not_verified' => 'The customer has not finished verifying their KYC Bank profile yet. It resumes by itself once they are verified.',
        'profile_stale'        => 'The customer\'s KYC Bank profile is out of date and has to be re-verified by KYC Bank.',
        'profile_on_hold'      => 'KYC Bank has put the customer\'s profile on hold.',
    ][$reason ?? ''] ?? 'KYC Bank has suspended this consent.';
}

/**
 * A warning when staff approved KYC on this consent and it is no longer
 * active, or when an updated profile is waiting for review; null otherwise.
 */
function kb_staff_warning(array $row): ?string
{
    if ($row['review_decision'] === 'approved' && $row['state'] !== 'active') {
        if ($row['state'] === 'withdrawn') {
            return 'KYC basis withdrawn: staff approved this customer\'s KYC using this KYC Bank consent, and the customer withdrew it on '
                . kb_fmt_date($row['withdrawn_at'] ?? null) . '. The KYC status was not changed automatically; review the customer\'s KYC.';
        }
        return 'KYC basis no longer active: staff approved this customer\'s KYC using this KYC Bank consent, which is now '
            . strtolower(kb_state_label($row['state'])) . '. The KYC status was not changed automatically; review the customer\'s KYC.';
    }
    if ($row['state'] === 'active' && kb_bool($row['needs_review']) && $row['review_decision'] !== null) {
        return 'Updated profile — needs staff review. KYC Bank sent profile version ' . (int)$row['profile_version']
            . '; the last staff decision was on version ' . (int)$row['reviewed_profile_version'] . '.';
    }
    return null;
}

function kb_fmt_date($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('Africa/Gaborone'))->format('j M Y, H:i');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

// ----------------------------------------------------------------------
// Showing a profile
// ----------------------------------------------------------------------

function kb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kb_label(string $key): string
{
    $special = ['dateOfBirth' => 'Date of birth', 'nextOfKin' => 'Next of kin', 'sourceOfFunds' => 'Source of funds',
        'identityDocuments' => 'Identity documents', 'personal' => 'Personal details'];
    if (isset($special[$key])) {
        return $special[$key];
    }
    $words = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', str_replace('_', ' ', $key)) ?? $key;
    return ucfirst(strtolower($words));
}

function kb_scalar($value): string
{
    if ($value === null || $value === '') {
        return '<span class="kb-muted">—</span>';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    return kb_h($value);
}

function kb_is_list(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

/** Key/value rows for an object, nested objects and lists rendered inside. */
function kb_render_value($value, int $depth = 0): string
{
    if (!is_array($value)) {
        return kb_scalar($value);
    }
    if ($value === []) {
        return '<span class="kb-muted">—</span>';
    }
    if (kb_is_list($value)) {
        $allScalar = !array_filter($value, 'is_array');
        if ($allScalar) {
            return implode(', ', array_map('kb_scalar', $value));
        }
        $html = '';
        foreach ($value as $i => $item) {
            $title = is_array($item) ? ($item['kind'] ?? $item['type'] ?? $item['relationship'] ?? null) : null;
            $flag  = is_array($item) && kb_bool($item['primary'] ?? false) ? ' · primary' : '';
            $html .= '<div class="kb-item"><div class="kb-item-title">' . kb_h(is_scalar($title) ? (string)$title : '#' . ($i + 1)) . kb_h($flag) . '</div>'
                . kb_render_value($item, $depth + 1) . '</div>';
        }
        return $html;
    }
    $html = '<table class="kb-kv">';
    foreach ($value as $k => $v) {
        $html .= '<tr><th>' . kb_h(kb_label((string)$k)) . '</th><td>' . kb_render_value($v, $depth + 1) . '</td></tr>';
    }
    return $html . '</table>';
}

/** The profile as HTML: personal details, ID, addresses and contacts first, then whatever else it holds. */
function kb_render_profile(array $profile): string
{
    $meta = [];
    foreach (['profileVersion', 'level', 'verifiedAt', 'verificationMethod', 'snapshotHash', 'consentId'] as $k) {
        if (array_key_exists($k, $profile)) {
            $meta[$k] = $profile[$k];
            unset($profile[$k]);
        }
    }

    $sections = [];
    if (isset($profile['personal']) && is_array($profile['personal'])) {
        $order = ['title', 'firstName', 'middleName', 'surname', 'gender', 'dateOfBirth', 'placeOfBirth', 'countryOfBirth', 'nationalities', 'maritalStatus'];
        $personal = [];
        foreach ($order as $k) {
            if (array_key_exists($k, $profile['personal'])) {
                $personal[$k] = $profile['personal'][$k];
            }
        }
        $sections['personal'] = $personal + $profile['personal'];
        unset($profile['personal']);
    }
    foreach (['identityDocuments', 'addresses', 'contacts'] as $k) {
        if (array_key_exists($k, $profile)) {
            $sections[$k] = $profile[$k];
            unset($profile[$k]);
        }
    }
    $sections += $profile;

    $html = '<div class="kb-section"><h3>Verification</h3>' . kb_render_value($meta) . '</div>';
    foreach ($sections as $k => $v) {
        $html .= '<div class="kb-section"><h3>' . kb_h(kb_label((string)$k)) . '</h3>' . kb_render_value($v) . '</div>';
    }
    return $html;
}
