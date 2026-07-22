<?php
require_once '/app/backend/helpers/CertificateManager.php';


// The actual payload from the logs
$payload = json_decode('{"action":"GENERATE_TOKEN","amount":400,"beneficiary_phone":"+26770000000","currency":"BWP","destination_institution":"SACCUSSALIS","from_institution":"ZURUBANK","hold_reference":"CASHOUT_ZURU_1784714501","reference":"CASHOUT_ZURU_1784714501","requester":"VOUCHMORPH","source_hold":{"payload":{"action":"PLACE_HOLD","reference":"CASHOUT_ZURU_1784714501","asset_type":"ACCOUNT","amount":500,"currency":"BWP","hold_reason":"PENDING_SWAP","destination_institution":"SACCUSSALIS","expiry":"2026-07-23 10:01:42","timestamp":1784714502,"from_institution":"ZURUBANK","source_institution":"ZURUBANK","user_id":1,"source_identifier":"10000001","source_identifier_type":"account","asset_id":10},"signature":"bnvwBZgaAUEhnJ7TeOcCZHLc/bPYFAzm82thhEqcdR5JQ7uLAlExhJ/0WeT1P86Q0YlzZ1s0RCEvvyqDvq4tM/Wn7ZRwixVQMwKmQgjWTcJiEZUHgycAUnQoWbtjRfGeEqPFK1Taa0NGEZEBqIGXpTqEe+xUjY1Wnr3oCG8pzESnIFowG9uQuffFt3QO7a/nK+kJfWAHqOBMeYAI4jiR1pJsXzNxOIcG6OnqMnhyjFyV5sQsm0eBlUBkzV0XGYNpihcFc1YmSCVSnMUJO5cT8eEgIOgRAw19/U3ddtBeyAV75kjUh0wQ2ZRBbzV/iUA58ht96MTPU0N0Fg3+yNU3Zg==","source":"ZURUBANK","timestamp":1784714502,"is_hooked":false},"source_institution":"ZURUBANK","source_verification":{"payload":{"action":"VERIFY_ASSET","reference":"CASHOUT_ZURU_1784714501","asset_type":"ACCOUNT","amount":500,"currency":"BWP","institution":"ZURUBANK","timestamp":1784714502,"swap_type":"CASHOUT","requester":"VOUCHMORPH","from_institution":"ZURUBANK","source_institution":"ZURUBANK","source_identifier":"10000001","source_identifier_type":"account"},"signature":"zv5buTjJwWMAMx3aCUXor5iFkP4ZGbwrurv8Mms2ya5CPrz8/eavygkCWi/R1drlX/hqYfXLFAMN9KmSpzXm9nRVnr8qw6SU2gJLBP/ocIOIk3dGk1quSUAo1PIyDk3gAGJPmbjRCv1R/2+LITB7chZjVFB5LzPiqJ3WBEr/fCYUA1bMGMuA59IKUbfjyE4YnGEPDHtr9yB9/MjfWzCiW99dwsCg/Fz0yb7hW0xhn6nJsj4CWIT0yNdzOgykAmR5Ap5mrd5aO8vzXtcwzrNaraV7ivtHFEE+77krTHweezAcCIFj8WIXSPy3ch16SMEbG/vWE/aOwZqB6fw6Ce7F9A==","source":"ZURUBANK","timestamp":1784714502,"is_hooked":false},"timestamp":1784714502,"to_institution":"SACCUSSALIS"}', true);

// Add the certificate from the logs (copy from the actual request)
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

echo "========================================\n";
echo "TESTING SIGNATURE VERIFICATION\n";
echo "========================================\n\n";

$certManager = new CertificateManager('SACCUSSALIS');

// Test 1: What fields does VouchMorph actually sign?
// Looking at VouchMorph's createSignedRequest(), it signs:
// - All payload fields + timestamp
// - EXCLUDES: source_hold and source_verification (added AFTER signing)
$fieldsVouchMorphSigns = [
    'action', 'amount', 'beneficiary_phone', 'currency',
    'destination_institution', 'from_institution', 'hold_reference',
    'reference', 'requester', 'source_institution', 'timestamp',
    'to_institution'
];

$signedPayload = [];
foreach ($fieldsVouchMorphSigns as $field) {
    if (array_key_exists($field, $payload)) {
        $signedPayload[$field] = $payload[$field];
    }
}
ksort($signedPayload);
$jsonThatWasSigned = json_encode($signedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "1. JSON that VouchMorph ACTUALLY signed:\n";
echo $jsonThatWasSigned . "\n\n";

// Test 2: What is SACCUSSALIS currently verifying?
$payloadToVerify = $payload;
unset($payloadToVerify['certificate']);
// Remove all signatures
function removeAllSignatures($data) {
    $result = [];
    foreach ($data as $key => $value) {
        if ($key === 'signature') continue;
        if (is_array($value)) {
            $result[$key] = removeAllSignatures($value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}
$payloadToVerify = removeAllSignatures($payloadToVerify);
ksort($payloadToVerify);
$jsonCurrentlyVerified = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "2. JSON that SACCUSSALIS is currently verifying:\n";
echo $jsonCurrentlyVerified . "\n\n";

// Test 3: Compare them
if ($jsonThatWasSigned === $jsonCurrentlyVerified) {
    echo "✅ JSON MATCH! The signature should verify correctly.\n\n";
} else {
    echo "❌ JSON MISMATCH! The signature will fail.\n\n";
    echo "Differences:\n";
    
    // Find what's different
    $signedKeys = array_keys(json_decode($jsonThatWasSigned, true));
    $verifiedKeys = array_keys(json_decode($jsonCurrentlyVerified, true));
    
    $extraInSigned = array_diff($signedKeys, $verifiedKeys);
    $extraInVerified = array_diff($verifiedKeys, $signedKeys);
    
    if (!empty($extraInSigned)) {
        echo "  - Fields in signed but NOT in verified: " . implode(', ', $extraInSigned) . "\n";
    }
    if (!empty($extraInVerified)) {
        echo "  - Fields in verified but NOT in signed: " . implode(', ', $extraInVerified) . "\n";
    }
    echo "\n";
}

// Test 4: Extract public key and manually verify
$cert = $payload['certificate'];
$pubKey = $certManager->extractPublicKeyFromCert($cert);
$sig = base64_decode($payload['signature']);
$keyResource = openssl_pkey_get_public($pubKey);

$result = openssl_verify($jsonThatWasSigned, $sig, $keyResource, OPENSSL_ALGO_SHA256);

echo "3. Manual verification result:\n";
echo "   openssl_verify returned: " . $result . " (1=valid, 0=invalid, -1=error)\n";
echo "   Signature is: " . ($result === 1 ? "✅ VALID" : "❌ INVALID") . "\n\n";

// Test 5: Test with the actual CertificateManager
$verification = $certManager->verifySignedRequest($payload);
echo "4. CertificateManager verification result:\n";
echo "   Verified: " . ($verification['verified'] ? "✅ YES" : "❌ NO") . "\n";
echo "   Message: " . $verification['message'] . "\n";
