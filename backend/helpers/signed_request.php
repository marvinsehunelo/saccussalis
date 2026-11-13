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
 * What the signature covers: every field of the body except `signature`, `certificate` and
 * `requester`, top-level keys sorted, JSON-encoded as VouchMorph does. So `requester` is a
 * claim, not proof; `signer` (the certificate's subject) is who really signed.
 *
 * @param mixed $input  the decoded request body
 * @return array{verified: bool, requester: string, signer: string, message: string}
 */
function verify_signed_caller($input, ?CertificateManager $certManager = null): array
{
    $refuse = static function (string $message, string $requester = 'UNKNOWN'): array {
        return ['verified' => false, 'requester' => $requester, 'signer' => '', 'message' => $message];
    };

    if (!is_array($input)) {
        return $refuse('Signed JSON body required');
    }

    // Made safe to write to a log: printable ASCII only, at most 64 characters.
    $requester = (isset($input['requester']) && is_string($input['requester']))
        ? substr(preg_replace('/[^\x20-\x7E]/', '?', $input['requester']), 0, 64)
        : 'UNKNOWN';

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

    $parsed = @openssl_x509_parse($input['certificate']);
    $cn = is_array($parsed) ? ($parsed['subject']['CN'] ?? $parsed['name'] ?? '') : '';
    $signer = is_array($cn) ? implode(',', $cn) : (string)$cn;
    $signer = substr(preg_replace('/[^\x20-\x7E]/', '?', $signer), 0, 128);

    return ['verified' => true, 'requester' => $requester, 'signer' => $signer, 'message' => 'Signature verified'];
}
