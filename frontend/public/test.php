<?php
// Place at: backend/api/v1/test_keys.php
// Run this by accessing it directly in browser

header('Content-Type: text/plain');

echo "========== SIMPLE KEY TEST ==========\n\n";

// 1. Load your public key
$publicKey = getenv('VOUCHMORPH_PUBLIC_KEY');
if (!$publicKey) {
    echo "ERROR: VOUCHMORPH_PUBLIC_KEY not found\n";
    exit;
}

$publicKey = str_replace(['\\n', '\n'], "\n", $publicKey);
echo "Public key loaded (length: " . strlen($publicKey) . ")\n";

// 2. Load your private key
$privateKey = getenv('SACCUSSALIS_PRIVATE_KEY');
if (!$privateKey) {
    echo "ERROR: SACCUSSALIS_PRIVATE_KEY not found\n";
    exit;
}

$privateKey = str_replace(['\\n', '\n'], "\n", $privateKey);
$privKey = openssl_pkey_get_private($privateKey);

if (!$privKey) {
    echo "ERROR: Cannot load private key: " . openssl_error_string() . "\n";
    exit;
}

echo "Private key loaded successfully\n\n";

// 3. Create a test payload
$testPayload = [
    'action' => 'TEST',
    'reference' => 'TEST_' . time(),
    'amount' => 100,
    'currency' => 'BWP',
    'timestamp' => time()
];

echo "Test payload: " . json_encode($testPayload) . "\n\n";

// 4. Sort and encode
ksort($testPayload);
$jsonToSign = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo "JSON to sign: " . $jsonToSign . "\n\n";

// 5. Sign with private key
$signature = '';
$signSuccess = openssl_sign($jsonToSign, $signature, $privKey, OPENSSL_ALGO_SHA256);
$signature_b64 = base64_encode($signature);

echo "Signature created: " . substr($signature_b64, 0, 50) . "...\n";
echo "Sign success: " . ($signSuccess ? "YES" : "NO") . "\n\n";

// 6. Verify with public key
$pubKey = openssl_pkey_get_public($publicKey);
$verifyResult = openssl_verify($jsonToSign, $signature, $pubKey, OPENSSL_ALGO_SHA256);

echo "VERIFICATION RESULT: ";
if ($verifyResult === 1) {
    echo "✓ VALID - Your keys work correctly!\n";
    echo "\nThe problem is VouchMorph is using a DIFFERENT key pair.\n";
    echo "Send them this public key fingerprint:\n";
    
    $keyDetails = openssl_pkey_get_details($pubKey);
    $fingerprint = hash('sha256', $keyDetails['key']);
    echo "SHA-256: " . $fingerprint . "\n";
    
} elseif ($verifyResult === 0) {
    echo "✗ INVALID - Your keys don't match each other!\n";
    echo "Your VOUCHMORPH_PUBLIC_KEY does not match your SACCUSSALIS_PRIVATE_KEY\n";
    echo "Regenerate both keys as a pair.\n";
} else {
    echo "ERROR: " . openssl_error_string() . "\n";
}

openssl_free_key($privKey);
openssl_free_key($pubKey);
