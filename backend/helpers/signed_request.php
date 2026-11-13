<?php
// backend/helpers/signed_request.php
//
// One place that decides whether a request body really came from a member of the VouchMorph
// scheme. It fails closed: the caller is only told "verified" when the body carries a
// certificate that chains to the trusted CA (VOUCHMORPH_CA_CERT_CONTENT) and a signature, made
// with that certificate's key, over the rest of the body. Anything else - no certificate, no
// signature, an unset CA, an error while checking - is "not verified".
//
// Same check as notify_debit.php (CertificateManager::verifySignedRequest), wrapped so that an
// endpoint cannot skip it by accident.

require_once __DIR__ . '/CertificateManager.php';

/**
 * The certificate names allowed to call, from HOLD_ALLOWED_SIGNERS (comma-separated common
 * names, compared exactly after trimming spaces). null means the limit is off: the variable is
 * not set, or holds nothing but spaces and commas. Then any certificate the scheme CA issued may
 * call, as before.
 *
 * @return string[]|null
 */
function allowed_signers(): ?array
{
    $raw = getenv('HOLD_ALLOWED_SIGNERS');
    if (!is_string($raw)) {
        return null;
    }
    $names = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($n) => $n !== ''));
    return $names === [] ? null : $names;
}

/** The common names in a certificate's subject (usually one), or [] when it cannot be read. */
function certificate_common_names($certificatePem): array
{
    if (!is_string($certificatePem) || $certificatePem === '') {
        return [];
    }
    $parsed = @openssl_x509_parse($certificatePem);
    $cn = is_array($parsed) ? ($parsed['subject']['CN'] ?? null) : null;
    if (is_string($cn)) {
        return [$cn];
    }
    return is_array($cn) ? array_values(array_filter($cn, 'is_string')) : [];
}

/**
 * The one check, shared by hold.php and notify_debit.php, of who may call once the signature is
 * known to be good. With the limit off it allows every signer. With it on, the certificate must
 * carry exactly one common name and that name must be on the list.
 */
function signer_is_allowed($certificatePem): bool
{
    $allowed = allowed_signers();
    if ($allowed === null) {
        return true;
    }
    $names = certificate_common_names($certificatePem);
    return count($names) === 1 && in_array($names[0], $allowed, true);
}

/** Text from a caller, cut down so it is safe to write to a log. */
function log_safe($value, int $max): string
{
    return substr(preg_replace('/[^\x20-\x7E]/', '?', is_scalar($value) ? (string)$value : ''), 0, $max);
}

/**
 * What may be logged about a request body: its action and the names of its fields, never the
 * values (the body can carry a PIN, a certificate and a signature).
 */
function request_shape_for_log($input): string
{
    if (!is_array($input)) {
        return 'body=(not a JSON object)';
    }
    $action = is_string($input['action'] ?? null) ? log_safe($input['action'], 32) : '(none)';
    return 'action=' . $action . ', fields=' . log_safe(implode(',', array_map('strval', array_keys($input))), 300);
}

/**
 * What the signature covers: every field of the body except `signature`, `certificate` and
 * `requester`, top-level keys sorted, JSON-encoded as VouchMorph does. So `requester` is a
 * claim, not proof; `signer` (the certificate's subject) is who really signed.
 *
 * @param mixed $input  the decoded request body
 * A correctly signed request whose certificate is not on HOLD_ALLOWED_SIGNERS (when that is
 * set) comes back with verified = false and not_allowed = true.
 *
 * @return array{verified: bool, not_allowed: bool, requester: string, signer: string, message: string}
 */
function verify_signed_caller($input, ?CertificateManager $certManager = null): array
{
    $refuse = static function (string $message, string $requester = 'UNKNOWN', bool $notAllowed = false, string $signer = ''): array {
        return ['verified' => false, 'not_allowed' => $notAllowed, 'requester' => $requester, 'signer' => $signer, 'message' => $message];
    };

    if (!is_array($input)) {
        return $refuse('Signed JSON body required');
    }

    // A label, not proof (the signature does not cover it); made safe to write to a log.
    $requester = (isset($input['requester']) && is_string($input['requester'])) ? log_safe($input['requester'], 64) : 'UNKNOWN';

    if (empty($input['certificate']) || !is_string($input['certificate'])) {
        return $refuse('Certificate required', $requester);
    }
    if (empty($input['signature']) || !is_string($input['signature'])) {
        return $refuse('Signature required', $requester);
    }

    try {
        $certManager = $certManager ?? new CertificateManager('SACCUSSALIS');
        $result = $certManager->verifySignedRequest($input);
    } catch (Throwable $e) {
        error_log('verify_signed_caller: verification error: ' . $e->getMessage());
        return $refuse('Certificate verification failed', $requester);
    }

    if (!is_array($result) || ($result['verified'] ?? false) !== true) {
        $reason = is_array($result) ? (string)($result['message'] ?? 'Invalid signature') : 'Invalid signature';
        return $refuse('Certificate verification failed: ' . $reason, $requester);
    }

    $signer = log_safe(implode(',', certificate_common_names($input['certificate'])), 128);

    if (!signer_is_allowed($input['certificate'])) {
        return $refuse('Caller not allowed', $requester, true, $signer);
    }

    return ['verified' => true, 'not_allowed' => false, 'requester' => $requester, 'signer' => $signer, 'message' => 'Signature verified'];
}
