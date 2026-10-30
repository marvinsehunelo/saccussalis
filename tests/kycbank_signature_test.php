<?php
// tests/kycbank_signature_test.php
//
// Checks KYC Bank webhook signature verification. Command line only:
//     php tests/kycbank_signature_test.php
// The secret and event below are made up for the test.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/integrations/kycbank/KycBankClient.php';

$secret = 'test-secret-not-a-real-one';
$now    = 1_800_000_000;
$body   = '{"id":"evt_00000000-0000-4000-8000-000000000001","event":"consent.approved","occurredAt":"2027-01-15T08:00:00Z","consentId":"cns_test_example","profileVersion":3}';

function sign(string $body, string $secret, int $t): string
{
    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
}

$cases = [
    'valid signature'              => [true,  $body, sign($body, $secret, $now), $secret, $now],
    'valid, 4 min old'             => [true,  $body, sign($body, $secret, $now - 240), $secret, $now],
    'valid, upper-case hex'        => [true,  $body, 't=' . $now . ',v1=' . strtoupper(hash_hmac('sha256', $now . '.' . $body, $secret)), $secret, $now],
    'valid, spaces after comma'    => [true,  $body, 't=' . $now . ', v1=' . hash_hmac('sha256', $now . '.' . $body, $secret), $secret, $now],
    'tampered body'                => [false, str_replace('"profileVersion":3', '"profileVersion":4', $body), sign($body, $secret, $now), $secret, $now],
    'wrong secret'                 => [false, $body, sign($body, 'another-made-up-secret', $now), $secret, $now],
    'stale t (6 min old)'          => [false, $body, sign($body, $secret, $now - 360), $secret, $now],
    't 6 min in the future'        => [false, $body, sign($body, $secret, $now + 360), $secret, $now],
    'missing header (null)'        => [false, $body, null, $secret, $now],
    'empty header'                 => [false, $body, '', $secret, $now],
    'no v1'                        => [false, $body, 't=' . $now, $secret, $now],
    'no t'                         => [false, $body, 'v1=' . hash_hmac('sha256', $now . '.' . $body, $secret), $secret, $now],
    'non-numeric t'                => [false, $body, 't=abc,v1=' . hash_hmac('sha256', 'abc.' . $body, $secret), $secret, $now],
    'timestamp changed after sign' => [false, $body, 't=' . ($now - 1) . ',v1=' . hash_hmac('sha256', $now . '.' . $body, $secret), $secret, $now],
    'empty secret configured'      => [false, $body, sign($body, '', $now), '', $now],
];

$failed = 0;
foreach ($cases as $name => [$expect, $b, $header, $s, $at]) {
    $got = KycBankClient::verifyWebhookSignature($b, $header, $s, $at);
    $ok  = $got === $expect;
    $failed += $ok ? 0 : 1;
    printf("%s  %-30s expected %s, got %s\n", $ok ? 'PASS' : 'FAIL', $name, $expect ? 'valid' : 'rejected', $got ? 'valid' : 'rejected');
}

$checks = [
    'mobile +26770123456 valid'  => KycBankClient::isValidMobile('+26770123456') === true,
    'mobile 70123456 invalid'    => KycBankClient::isValidMobile('70123456') === false,
    'omang 123456789 valid'      => KycBankClient::isValidOmang('123456789') === true,
    'omang 12345678 invalid'     => KycBankClient::isValidOmang('12345678') === false,
    'consent id cns_abc valid'   => KycBankClient::isValidConsentId('cns_abc') === true,
    'consent id ../x invalid'    => KycBankClient::isValidConsentId('../x') === false,
];
foreach ($checks as $name => $ok) {
    $failed += $ok ? 0 : 1;
    printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
}

echo $failed === 0 ? "\nAll checks passed.\n" : "\n{$failed} check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
