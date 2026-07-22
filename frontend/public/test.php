<?php
require_once '/app/backend/helpers/CertificateManager.php';

// Use the actual payload from the logs (including the real signature and certificate)
$payload = json_decode('{"action":"GENERATE_TOKEN","amount":400,"beneficiary_phone":"+26770000000","currency":"BWP","destination_institution":"SACCUSSALIS","from_institution":"ZURUBANK","hold_reference":"CASHOUT_ZURU_1784716367","reference":"CASHOUT_ZURU_1784716367","requester":"VOUCHMORPH","source_hold":{"payload":{"action":"PLACE_HOLD","reference":"CASHOUT_ZURU_1784716367","asset_type":"ACCOUNT","amount":500,"currency":"BWP","hold_reason":"PENDING_SWAP","destination_institution":"SACCUSSALIS","expiry":"2026-07-23 10:32:48","timestamp":1784716368,"from_institution":"ZURUBANK","source_institution":"ZURUBANK","user_id":1,"source_identifier":"10000001","source_identifier_type":"account","asset_id":10},"signature":"AvuBrDSkckwndc7JfjZbM9P0nPg1vWFBETazYWkynvL6baXFr7Qqq59Fwt0QvM71JhESyDa39q0gWLKWqgfJguNe746o++eq+iAL\/F4CYW3g5BAgoaHc\/+VkBn","source":"ZURUBANK","timestamp":1784716368,"is_hooked":false},"source_institution":"ZURUBANK","source_verification":{"payload":{"action":"VERIFY_ASSET","reference":"CASHOUT_ZURU_1784716367","asset_type":"ACCOUNT","amount":500,"currency":"BWP","institution":"ZURUBANK","timestamp":1784716368,"swap_type":"CASHOUT","requester":"VOUCHMORPH","from_institution":"ZURUBANK","source_institution":"ZURUBANK","source_identifier":"10000001","source_identifier_type":"account"},"signature":"twondnh6XCc9P65aB3gbMkPr90mGr7kj1hTALvbTabqUUP6lazGKgatGFyefp6QA0ycQJaPVofFUx+br","source":"ZURUBANK","timestamp":1784716368,"is_hooked":false},"timestamp":1784716368,"to_institution":"SACCUSSALIS"}', true);

$payload['certificate'] = "-----BEGIN CERTIFICATE-----
MIIEbTCCAlUCFGM7U2vcVe90JNEe6\/Mxhts3A+vhMA0GCSqGSIb3DQEBCwUAMHcx
CzAJBgNVBAYTAkJXMREwDwYDVQQIDAhHYWJvcm9uZTERMA8GA1UEBwwIR2Fib3Jv
bmUxJTAjBgNVBAoMHFZvdWNoTW9ycGggRmluYW5jaWFsIE5ldHdvcmsxGzAZBgNV
BAMMElZvdWNoTW9ycGggUm9vdCBDQTAeFw0yNjA2MTIyMTM0MDRaFw0zMTA2MTEy
MTM0MDRaMG8xCzAJBgNVBAYTAkJXMREwDwYDVQQIDAhHYWJvcm9uZTERMA8GA1UE
BwwIR2Fib3JvbmUxJTAjBgNVBAoMHFZvdWNoTW9ycGggRmluYW5jaWFsIE5ldHdv
cmsxEzARBgNVBAMMClZPVUNITU9SUEgwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAw
ggEKAoIBAQDDqszylCQMnxUJVr1Io6TUlDa4qVnqMwz9ClisTWE1COSJOCmFEjoL
ZTh4146xiJD0Ga2mu7PTc6hwHV23UNmZE+UTeLn0BM10nB6BulU3maBKI8lXOjK7
nI\/p\/jzHYrVvFJQdQTo5fmxgsNPLJX1QozyonV3dY6TCGFdtjXqUEvB4HynHk\/kR
NDYbJw8k3GtKMkFL2xliffcenayC7n+WdYEVmidwsUxXvp8bRWwfrgoB3phxEoBp
92rKb84uY1JmlXz89E4Swba1FfLQg00PTy9tUcIJujPe6AuGujQKNKkkIzLhH\/H6
krdaNj29AJyLhsHmtY73mqZacMQvWf8DAgMBAAEwDQYJKoZIhvcNAQELBQADggIB
AGLi5JD\/Uf7l4kuH2Yzgd2vd8QkD\/YKzD3TW\/cU2cP\/K5ujcPd\/m9gtNLDx7DbBl
ug76f7OXrqU7Z2PAUNa+bxk8hlC+MpPoSZNZxv6iZ68UZ01KOzVKHHLX5O7m3IUs
NZPjQ216gnSFsS0FRbBAd1QK0IazOXBVdpsgwQr4YLuYeuc861POEo87\/hO8A66A
grWPOuS8H4MPqxbQgQ4Q5eKCBfXTFrG5JECyqOjapO9x4MVKLvC4IwkQYBmlO3jU
c7rCTQJiuRzDjCm9P62L1mWnX6PQPttlYunBOJX7Un4Bwi0GbkGqSFJ4IgrPWjov
MGktLr8AfzX4zN74gPvnr0HpeUNnHjEohtqptcd1+NVWGNqRyXiyGYsQzxyuPJfG
eTaas8siIq0dGJavRYq\/lC5Jga3RB9h\/\/zUbtvEOK2RW7z1Tq\/YWu+qWYchkSs1c
RcLQCR8hD+MFHwaiI5G7blk9TSxtflAnuXYQqrEHcQiR4CKY2AaVBsc0gaX1OSYt
9\/nraZvFmf0YwR1opW3p\/YrfW3h4Yh7en1G\/Wf\/IzJw3gxVes0E1CwjKEzr9Yky5
OMrPGTpmS+xzJdUN6pF5QIoIblWeLJvprcMODu1nwagR7I\/xdg4isln+TtVdRt60
QQNPdCuu3QqNCq7suNoAEd+hHQVTzYgWKEby+XRZqkFd
-----END CERTIFICATE-----";

$certManager = new CertificateManager('SACCUSSALIS');

// Test the verification
$result = $certManager->verifySignedRequest($payload);

echo "========================================\n";
echo "REAL PAYLOAD TEST\n";
echo "========================================\n\n";

echo "Verification result:\n";
echo "  Verified: " . ($result['verified'] ? "✅ YES" : "❌ NO") . "\n";
echo "  Message: " . $result['message'] . "\n";
echo "  Requester: " . $result['requester'] . "\n";

if ($result['verified']) {
    echo "\n✅ SUCCESS! The signature is valid.\n";
} else {
    echo "\n❌ FAILED! The signature is invalid.\n";
    
    // Debug: Check what JSON was verified
    echo "\nDebug info:\n";
    $payloadToVerify = [];
    $signedFields = [
        'action', 'amount', 'beneficiary_phone', 'currency',
        'destination_institution', 'from_institution', 'hold_reference',
        'reference', 'requester', 'source_institution', 'timestamp',
        'to_institution'
    ];
    foreach ($signedFields as $field) {
        if (array_key_exists($field, $payload)) {
            $payloadToVerify[$field] = $payload[$field];
        }
    }
    ksort($payloadToVerify);
    $jsonVerified = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "JSON verified: " . $jsonVerified . "\n";
}
php /app/frontend/public/test_real.php
