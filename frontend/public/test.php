<?php
// Test the exact signature from the logs
$signatureFromLog = "AvuBrDSkckwndc7JfjZbM9P0nPg1vWFBETazYWkynvL6baXFr7Qqq59Fwt0QvM71JhESyDa39q0gWLKWqgfJguNe746o++eq+iAL\/F4CYW3g5BAgoaHc\/+VkBn";

// The signature as it would be after JSON decoding (\/ becomes /)
$signatureDecoded = "AvuBrDSkckwndc7JfjZbM9P0nPg1vWFBETazYWkynvL6baXFr7Qqq59Fwt0QvM71JhESyDa39q0gWLKWqgfJguNe746o++eq+iAL/F4CYW3g5BAgoaHc/+VkBn";

$certificate = "-----BEGIN CERTIFICATE-----
MIIEbTCCAlUCFGM7U2vcVe90JNEe6/Mxhts3A+vhMA0GCSqGSIb3DQEBCwUAMHcx
CzAJBgNVBAYTAkJXMREwDwYDVQQIDAhHYWJvcm9uZTERMA8GA1UEBwwIR2Fib3Jv
bmUxJTAjBgNVBAoMHFZvdWNoTW9ycGggRmluYW5jaWFsIE5ldHdvcmsxGzAZBgNV
BAMMElZvdWNoTW9ycGggUm9vdCBDQTAeFw0yNjA2MTIyMTM0MDRaFw0zMTA2MTEy
MTM0MDRaMG8xCzAJBgNVBAYTAkJXMREwDwYDVQQIDAhHYWJvcm9uZTERMA8GA1UE
BwwIR2Fib3JvbmUxJTAjBgNVBAoMHFZvdWNoTW9ycGggRmluYW5jaWFsIE5ldHdv
cmsxEzARBgNVBAMMClZPVUNITU9SUEgwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAw
ggEKAoIBAQDDqszylCQMnxUJVr1Io6TUlDa4qVnqMwz9ClisTWE1COSJOCmFEjoL
ZTh4146xiJD0Ga2mu7PTc6hwHV23UNmZE+UTeLn0BM10nB6BulU3maBKI8lXOjK7
nI/p/jzHYrVvFJQdQTo5fmxgsNPLJX1QozyonV3dY6TCGFdtjXqUEvB4HynHk/kR
NDYbJw8k3GtKMkFL2xliffcenayC7n+WdYEVmidwsUxXvp8bRWwfrgoB3phxEoBp
92rKb84uY1JmlXz89E4Swba1FfLQg00PTy9tUcIJujPe6AuGujQKNKkkIzLhH/H6
krdaNj29AJyLhsHmtY73mqZacMQvWf8DAgMBAAEwDQYJKoZIhvcNAQELBQADggIB
AGLi5JD/Uf7l4kuH2Yzgd2vd8QkD/YKzD3TW/cU2cP/K5ujcPd/m9gtNLDx7DbBl
ug76f7OXrqU7Z2PAUNa+bxk8hlC+MpPoSZNZxv6iZ68UZ01KOzVKHHLX5O7m3IUs
NZPjQ216gnSFsS0FRbBAd1QK0IazOXBVdpsgwQr4YLuYeuc861POEo87/hO8A66A
grWPOuS8H4MPqxbQgQ4Q5eKCBfXTFrG5JECyqOjapO9x4MVKLvC4IwkQYBmlO3jU
c7rCTQJiuRzDjCm9P62L1mWnX6PQPttlYunBOJX7Un4Bwi0GbkGqSFJ4IgrPWjov
MGktLr8AfzX4zN74gPvnr0HpeUNnHjEohtqptcd1+NVWGNqRyXiyGYsQzxyuPJfG
eTaas8siIq0dGJavRYq/lC5Jga3RB9h//zUbtvEOK2RW7z1Tq/YWu+qWYchkSs1c
RcLQCR8hD+MFHwaiI5G7blk9TSxtflAnuXYQqrEHcQiR4CKY2AaVBsc0gaX1OSYt
9/nraZvFmf0YwR1opW3p/YrfW3h4Yh7en1G/Wf/IzJw3gxVes0E1CwjKEzr9Yky5
OMrPGTpmS+xzJdUN6pF5QIoIblWeLJvprcMODu1nwagR7I/xdg4isln+TtVdRt60
QQNPdCuu3QqNCq7suNoAEd+hHQVTzYgWKEby+XRZqkFd
-----END CERTIFICATE-----";

$signedPayload = [
    'action' => 'GENERATE_TOKEN',
    'amount' => 400,
    'beneficiary_phone' => '+26770000000',
    'currency' => 'BWP',
    'destination_institution' => 'SACCUSSALIS',
    'from_institution' => 'ZURUBANK',
    'hold_reference' => 'CASHOUT_ZURU_1784716367',
    'reference' => 'CASHOUT_ZURU_1784716367',
    'requester' => 'VOUCHMORPH',
    'source_institution' => 'ZURUBANK',
    'timestamp' => 1784716368,
    'to_institution' => 'SACCUSSALIS'
];

ksort($signedPayload);
$jsonToVerify = json_encode($signedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "JSON to verify:\n" . $jsonToVerify . "\n\n";

// Extract public key
$certResource = openssl_x509_read($certificate);
$publicKey = openssl_pkey_get_public($certResource);

// Test both signature versions
echo "Testing signature with escaped slashes (from JSON):\n";
$sig1 = base64_decode($signatureFromLog);
$result1 = openssl_verify($jsonToVerify, $sig1, $publicKey, OPENSSL_ALGO_SHA256);
echo "  Result: " . $result1 . " (1=valid, 0=invalid)\n";

echo "\nTesting signature with unescaped slashes (after JSON decode):\n";
$sig2 = base64_decode($signatureDecoded);
$result2 = openssl_verify($jsonToVerify, $sig2, $publicKey, OPENSSL_ALGO_SHA256);
echo "  Result: " . $result2 . " (1=valid, 0=invalid)\n";

// Test with the signature as-is (no base64 decode)
echo "\nTesting raw signature (no base64 decode):\n";
$result3 = openssl_verify($jsonToVerify, $signatureFromLog, $publicKey, OPENSSL_ALGO_SHA256);
echo "  Result: " . $result3 . " (1=valid, 0=invalid)\n";

echo "\n";

// Also test the same with VouchMorph's actual private key by generating a new signature
// We need to find if the private key matches
$privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT');
if ($privateKeyContent) {
    $privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);
    $privateKey = openssl_pkey_get_private($privateKeyContent);
    if ($privateKey) {
        // Generate a new signature
        $newSignature = '';
        openssl_sign($jsonToVerify, $newSignature, $privateKey, OPENSSL_ALGO_SHA256);
        $newSigB64 = base64_encode($newSignature);
        
        echo "Generated new signature with VouchMorph's private key:\n";
        echo substr($newSigB64, 0, 100) . "...\n";
        
        // Verify the new signature
        $result4 = openssl_verify($jsonToVerify, $newSignature, $publicKey, OPENSSL_ALGO_SHA256);
        echo "New signature verification result: " . $result4 . " (1=valid, 0=invalid)\n";
        
        // Compare with the signature from the log
        echo "\nSignature from log: " . substr($signatureFromLog, 0, 50) . "...\n";
        echo "New signature:      " . substr($newSigB64, 0, 50) . "...\n";
        
        if ($signatureFromLog === $newSigB64) {
            echo "\n✅ Signatures MATCH! The signature should be valid.\n";
        } else {
            echo "\n❌ Signatures DO NOT MATCH! The signature is from a different private key.\n";
        }
    } else {
        echo "Could not load private key from environment\n";
    }
} else {
    echo "VOUCHMORPH_PRIVATE_KEY_CONTENT not found in environment\n";
}
