<?php
require_once '/app/backend/helpers/CertificateManager.php';

echo "========================================\n";
echo "TESTING REAL VOUCHMORPH SIGNATURE\n";
echo "========================================\n\n";

// Get the certificate that VouchMorph is sending (from environment)
$vouchmorphCert = getenv('VOUCHMORPH_CERT_CONTENT');
if (!$vouchmorphCert) {
    echo "VOUCHMORPH_CERT_CONTENT not found in environment!\n";
    exit;
}
$vouchmorphCert = str_replace(['\\n', '\n'], "\n", $vouchmorphCert);

echo "1. VouchMorph Certificate (from environment):\n";
echo "   Length: " . strlen($vouchmorphCert) . "\n";
echo "   Subject: " . openssl_x509_parse($vouchmorphCert)['subject']['CN'] ?? 'unknown' . "\n\n";

// Create a test payload that VouchMorph would sign
$testPayload = [
    'action' => 'GENERATE_TOKEN',
    'amount' => 400,
    'beneficiary_phone' => '+26770000000',
    'currency' => 'BWP',
    'destination_institution' => 'SACCUSSALIS',
    'from_institution' => 'ZURUBANK',
    'hold_reference' => 'TEST_HOLD_REF',
    'reference' => 'TEST_REF',
    'requester' => 'VOUCHMORPH',
    'source_institution' => 'ZURUBANK',
    'to_institution' => 'SACCUSSALIS'
];
$testPayload['timestamp'] = time();
ksort($testPayload);
$jsonToSign = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "2. JSON to sign:\n" . $jsonToSign . "\n\n";

// Get the private key (this should match the certificate)
$privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT');
if (!$privateKeyContent) {
    echo "VOUCHMORPH_PRIVATE_KEY_CONTENT not found in environment!\n";
    exit;
}
$privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);
$privateKey = openssl_pkey_get_private($privateKeyContent);
if (!$privateKey) {
    echo "Failed to load private key\n";
    exit;
}

// Sign the payload
$signature = '';
openssl_sign($jsonToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
$signatureB64 = base64_encode($signature);

echo "3. Generated signature:\n" . $signatureB64 . "\n\n";

// Now verify using SACCUSSALIS's CertificateManager
$certManager = new CertificateManager('SACCUSSALIS');

// Create the full request payload
$fullRequest = $testPayload;
$fullRequest['signature'] = $signatureB64;
$fullRequest['certificate'] = $vouchmorphCert;
$fullRequest['requester'] = 'VOUCHMORPH';

echo "4. Verifying with SACCUSSALIS CertificateManager...\n";
$verification = $certManager->verifySignedRequest($fullRequest);

echo "   Verified: " . ($verification['verified'] ? "✅ YES" : "❌ NO") . "\n";
echo "   Message: " . $verification['message'] . "\n";

if ($verification['verified']) {
    echo "\n✅ SUCCESS! Signature verification works!\n";
    echo "The problem is that VouchMorph is using a DIFFERENT private key.\n";
} else {
    echo "\n❌ FAILED! The private key in VouchMorph's environment doesn't match the certificate.\n";
    echo "Even though they match the files, the certificate and private key are not a valid pair.\n";
    
    // Test: verify the signature manually
    echo "\n5. Manual verification with openssl:\n";
    $publicKey = openssl_pkey_get_public($vouchmorphCert);
    $result = openssl_verify($jsonToSign, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    echo "   openssl_verify result: " . $result . " (1=valid, 0=invalid)\n";
}
