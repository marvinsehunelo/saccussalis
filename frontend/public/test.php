<?php
require_once '/app/backend/helpers/CertificateManager.php';

echo "========================================\n";
echo "AGGRESSIVE TEST ON SACCUSSALIS\n";
echo "========================================\n\n";

// 1. Get the actual certificate from the request log
// Use the certificate that VouchMorph is sending
$vouchmorphCert = getenv('VOUCHMORPH_CERT_CONTENT');
if (!$vouchmorphCert) {
    echo "❌ VOUCHMORPH_CERT_CONTENT not found!\n";
    exit;
}
$vouchmorphCert = str_replace(['\\n', '\n'], "\n", $vouchmorphCert);

// 2. Get the private key
$privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT');
if (!$privateKeyContent) {
    echo "❌ VOUCHMORPH_PRIVATE_KEY_CONTENT not found!\n";
    exit;
}
$privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);

// 3. Create a test payload matching the actual cashout
$testPayload = [
    'action' => 'GENERATE_TOKEN',
    'amount' => 400,
    'beneficiary_phone' => '+26770000000',
    'currency' => 'BWP',
    'destination_institution' => 'SACCUSSALIS',
    'from_institution' => 'ZURUBANK',
    'hold_reference' => 'CASHOUT_ZURU_' . time(),
    'reference' => 'CASHOUT_ZURU_' . time(),
    'requester' => 'VOUCHMORPH',
    'source_institution' => 'ZURUBANK',
    'to_institution' => 'SACCUSSALIS'
];
$testPayload['timestamp'] = time();
ksort($testPayload);
$jsonToSign = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "1. JSON to sign:\n" . $jsonToSign . "\n\n";

// 4. Sign with the private key
$privateKey = openssl_pkey_get_private($privateKeyContent);
if (!$privateKey) {
    echo "❌ Failed to load private key\n";
    exit;
}
$signature = '';
openssl_sign($jsonToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
$signatureB64 = base64_encode($signature);

echo "2. Generated signature:\n" . $signatureB64 . "\n\n";

// 5. Verify with the certificate - MANUAL
$publicKey = openssl_pkey_get_public($vouchmorphCert);
$result = openssl_verify($jsonToSign, $signature, $publicKey, OPENSSL_ALGO_SHA256);
echo "3. Manual verification with openssl:\n";
echo "   openssl_verify result: " . $result . " (1=valid, 0=invalid)\n";
echo "   Result: " . ($result === 1 ? "✅ VALID" : "❌ INVALID") . "\n\n";

// 6. Verify with CertificateManager
$fullRequest = $testPayload;
$fullRequest['signature'] = $signatureB64;
$fullRequest['certificate'] = $vouchmorphCert;
$fullRequest['requester'] = 'VOUCHMORPH';

$certManager = new CertificateManager('SACCUSSALIS');
$verification = $certManager->verifySignedRequest($fullRequest);

echo "4. CertificateManager verification:\n";
echo "   Verified: " . ($verification['verified'] ? "✅ YES" : "❌ NO") . "\n";
echo "   Message: " . $verification['message'] . "\n";
echo "   Requester: " . $verification['requester'] . "\n";

// 7. Check what JSON is being verified
$payloadToVerify = [];
$signedFields = [
    'action', 'amount', 'beneficiary_phone', 'currency',
    'destination_institution', 'from_institution', 'hold_reference',
    'reference', 'requester', 'source_institution', 'timestamp',
    'to_institution'
];
foreach ($signedFields as $field) {
    if (array_key_exists($field, $fullRequest)) {
        $payloadToVerify[$field] = $fullRequest[$field];
    }
}
ksort($payloadToVerify);
$jsonVerified = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "\n5. JSON being verified:\n" . $jsonVerified . "\n";
echo "   Matches signed JSON: " . ($jsonVerified === $jsonToSign ? "✅ YES" : "❌ NO") . "\n";
