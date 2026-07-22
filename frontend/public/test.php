<?php
require_once '/app/backend/helpers/CertificateManager.php';

echo "========================================\n";
echo "SIMPLE CERTIFICATEMANAGER TEST\n";
echo "========================================\n\n";

// Check environment variables directly
echo "Checking environment variables:\n";
$caCert = getenv('VOUCHMORPH_CA_CERT_CONTENT');
$privateKey = getenv('SACCUSSALIS_PRIVATE_KEY_CONTENT');
$myCert = getenv('SACCUSSALIS_CERT_CONTENT');

echo "VOUCHMORPH_CA_CERT_CONTENT: " . ($caCert ? "SET (length: " . strlen($caCert) . ")" : "NOT SET") . "\n";
echo "SACCUSSALIS_PRIVATE_KEY_CONTENT: " . ($privateKey ? "SET (length: " . strlen($privateKey) . ")" : "NOT SET") . "\n";
echo "SACCUSSALIS_CERT_CONTENT: " . ($myCert ? "SET (length: " . strlen($myCert) . ")" : "NOT SET") . "\n\n";

$certManager = new CertificateManager('SACCUSSALIS');

// Check if configured
echo "CertificateManager configured: " . ($certManager->isConfigured() ? "YES" : "NO") . "\n\n";

// Try to sign something
echo "Attempting to sign a payload...\n";
$testPayload = [
    'action' => 'TEST_SIGN',
    'amount' => 100,
    'reference' => 'TEST_' . time()
];

$signed = $certManager->createSignedRequest($testPayload, 'SACCUSSALIS');

if (isset($signed['signature']) && isset($signed['certificate'])) {
    echo "✅ Signing successful!\n";
    echo "Signature: " . substr($signed['signature'], 0, 50) . "...\n\n";
    
    // Now verify what we just signed
    echo "Verifying the signature...\n";
    $verification = $certManager->verifySignedRequest($signed);
    
    echo "Verification result:\n";
    echo "  Verified: " . ($verification['verified'] ? "✅ YES" : "❌ NO") . "\n";
    echo "  Message: " . $verification['message'] . "\n";
    echo "  Requester: " . $verification['requester'] . "\n";
} else {
    echo "❌ Signing failed!\n";
    echo "Available keys in returned array: " . implode(', ', array_keys($signed)) . "\n";
}

