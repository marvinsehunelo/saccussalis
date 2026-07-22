<?php
$cert = '-----BEGIN CERTIFICATE-----
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
-----END CERTIFICATE-----';

$caCert = getenv('VOUCHMORPH_CA_CERT_CONTENT');
if ($caCert) {
    $caCert = str_replace(['\\n', '\n'], "\n", $caCert);
    echo "CA Certificate found!\n";
} else {
    echo "No CA Certificate found!\n";
    exit;
}

// Save to temp files
$tempCert = tempnam(sys_get_temp_dir(), 'cert_');
$tempCA = tempnam(sys_get_temp_dir(), 'ca_');
file_put_contents($tempCert, $cert);
file_put_contents($tempCA, $caCert);

// Verify the files were written
echo "Cert file size: " . filesize($tempCert) . "\n";
echo "CA file size: " . filesize($tempCA) . "\n";

// Test with openssl verify
$cmd = "openssl verify -CAfile " . escapeshellarg($tempCA) . " " . escapeshellarg($tempCert) . " 2>&1";
echo "Running: $cmd\n";
exec($cmd, $output, $returnCode);
echo "Return code: $returnCode\n";
echo "Output:\n" . implode("\n", $output) . "\n";

// Also try the PHP way
$certResource = openssl_x509_read($cert);
if ($certResource) {
    $certInfo = openssl_x509_parse($certResource);
    echo "\nCertificate Subject: " . ($certInfo['subject']['CN'] ?? 'unknown') . "\n";
    echo "Certificate Issuer: " . ($certInfo['issuer']['CN'] ?? 'unknown') . "\n";
} else {
    echo "\nFailed to parse certificate with PHP\n";
}

$caResource = openssl_x509_read($caCert);
if ($caResource) {
    $caInfo = openssl_x509_parse($caResource);
    echo "CA Subject: " . ($caInfo['subject']['CN'] ?? 'unknown') . "\n";
} else {
    echo "Failed to parse CA certificate with PHP\n";
}

unlink($tempCert);
unlink($tempCA);
