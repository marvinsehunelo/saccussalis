<?php
// Place at: backend/api/v1/openssl_diagnostic.php

header('Content-Type: text/plain');

echo "========== OPENSSL RSA SIGNATURE DIAGNOSTIC ==========\n\n";

// Get the raw input from VouchMorph
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    echo "ERROR: No input received or invalid JSON\n";
    echo "Raw input: " . substr($rawInput, 0, 500) . "\n";
    exit;
}

echo "1. RAW INPUT RECEIVED:\n";
echo substr($rawInput, 0, 1000) . "\n\n";

// Get the public key
$publicKey = getenv('VOUCHMORPH_PUBLIC_KEY');
if (!$publicKey) {
    echo "ERROR: VOUCHMORPH_PUBLIC_KEY not found in environment\n";
    exit;
}

// Clean the public key
$publicKey = str_replace(['\\n', '\n'], "\n", $publicKey);
echo "2. PUBLIC KEY (first 200 chars):\n";
echo substr($publicKey, 0, 200) . "...\n\n";

// Load public key and get details
$keyResource = openssl_pkey_get_public($publicKey);
if ($keyResource === false) {
    echo "ERROR: Cannot load public key: " . openssl_error_string() . "\n";
    exit;
}

$keyDetails = openssl_pkey_get_details($keyResource);
echo "3. PUBLIC KEY DETAILS:\n";
echo "   - Bits: " . ($keyDetails['bits'] ?? 'unknown') . "\n";
echo "   - Type: " . ($keyDetails['type'] ?? 'unknown') . "\n";
echo "   - Key format: " . (strpos($publicKey, 'BEGIN RSA PUBLIC KEY') !== false ? 'PKCS#1' : 'PKCS#8 (standard)') . "\n\n";

// Get signature
$signature = $input['signature'] ?? $input['mac'] ?? null;
if (!$signature) {
    echo "ERROR: No signature/mac field found in input\n";
    echo "Available fields: " . implode(', ', array_keys($input)) . "\n";
    exit;
}

$decodedSig = base64_decode($signature);
echo "4. SIGNATURE DETAILS:\n";
echo "   - Base64 length: " . strlen($signature) . "\n";
echo "   - Decoded length: " . strlen($decodedSig) . " bytes\n";
echo "   - Expected RSA length: " . ($keyDetails['bits'] / 8) . " bytes\n\n";

// Prepare payload (remove signature field)
$payloadToVerify = $input;
unset($payloadToVerify['signature']);
unset($payloadToVerify['mac']);

echo "5. TESTING DIFFERENT PAYLOAD VARIATIONS:\n\n";

// Variation A: Original as-is (no sorting, original order)
$jsonA = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$resultA = openssl_verify($jsonA, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
echo "A) Original order, no sorting:\n";
echo "   JSON: " . $jsonA . "\n";
echo "   Result: " . ($resultA === 1 ? "✓ VALID" : ($resultA === 0 ? "✗ INVALID" : "ERROR: " . openssl_error_string())) . "\n\n";

// Variation B: Sorted keys
$sorted = $payloadToVerify;
ksort($sorted);
$jsonB = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$resultB = openssl_verify($jsonB, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
echo "B) Sorted keys alphabetically:\n";
echo "   JSON: " . $jsonB . "\n";
echo "   Result: " . ($resultB === 1 ? "✓ VALID" : ($resultB === 0 ? "✗ INVALID" : "ERROR: " . openssl_error_string())) . "\n\n";

// Variation C: With _timestamp instead of timestamp
$withUnderscore = $payloadToVerify;
if (isset($withUnderscore['timestamp'])) {
    $withUnderscore['_timestamp'] = $withUnderscore['timestamp'];
    unset($withUnderscore['timestamp']);
}
ksort($withUnderscore);
$jsonC = json_encode($withUnderscore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$resultC = openssl_verify($jsonC, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
echo "C) _timestamp instead of timestamp:\n";
echo "   JSON: " . $jsonC . "\n";
echo "   Result: " . ($resultC === 1 ? "✓ VALID" : ($resultC === 0 ? "✗ INVALID" : "ERROR: " . openssl_error_string())) . "\n\n";

// Variation D: Numbers as strings (no quotes in JSON)
$asStrings = $payloadToVerify;
array_walk_recursive($asStrings, function(&$value) {
    if (is_int($value) || is_float($value)) {
        $value = (string)$value;
    }
});
ksort($asStrings);
$jsonD = json_encode($asStrings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$resultD = openssl_verify($jsonD, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
echo "D) Numbers as strings:\n";
echo "   JSON: " . $jsonD . "\n";
echo "   Result: " . ($resultD === 1 ? "✓ VALID" : ($resultD === 0 ? "✗ INVALID" : "ERROR: " . openssl_error_string())) . "\n\n";

// Variation E: Without timestamp entirely
$noTimestamp = $payloadToVerify;
unset($noTimestamp['timestamp']);
unset($noTimestamp['_timestamp']);
ksort($noTimestamp);
$jsonE = json_encode($noTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$resultE = openssl_verify($jsonE, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
echo "E) No timestamp field:\n";
echo "   JSON: " . $jsonE . "\n";
echo "   Result: " . ($resultE === 1 ? "✓ VALID" : ($resultE === 0 ? "✗ INVALID" : "ERROR: " . openssl_error_string())) . "\n\n";

// Variation F: Using JSON_PRESERVE_ZERO_FRACTION (keeps .0 on integers)
$jsonF = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
$resultF = openssl_verify($jsonF, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
echo "F) With JSON_PRESERVE_ZERO_FRACTION:\n";
echo "   JSON: " . $jsonF . "\n";
echo "   Result: " . ($resultF === 1 ? "✓ VALID" : ($resultF === 0 ? "✗ INVALID" : "ERROR: " . openssl_error_string())) . "\n\n";

// ============================================
// NOW TEST IF WE CAN SIGN AND VERIFY OURSELVES
// ============================================

echo "========== SELF-TEST: Can we sign and verify with our own keys? ==========\n\n";

// Get our private key
$privateKeyContent = getenv('SACCUSSALIS_PRIVATE_KEY');
if ($privateKeyContent) {
    $privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);
    $privateKey = openssl_pkey_get_private($privateKeyContent);
    
    if ($privateKey) {
        // Create a test payload
        $testPayload = [
            'action' => 'TEST',
            'reference' => 'TEST_' . time(),
            'amount' => 100,
            'timestamp' => time()
        ];
        
        ksort($testPayload);
        $testJson = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Sign with our private key
        $testSignature = '';
        $signSuccess = openssl_sign($testJson, $testSignature, $privateKey, OPENSSL_ALGO_SHA256);
        $testSignatureB64 = base64_encode($testSignature);
        
        echo "Self-test payload JSON: " . $testJson . "\n";
        echo "Signature created: " . substr($testSignatureB64, 0, 50) . "...\n";
        echo "Sign success: " . ($signSuccess ? "YES" : "NO") . "\n";
        
        // Verify with our public key
        $verifyResult = openssl_verify($testJson, $testSignature, $keyResource, OPENSSL_ALGO_SHA256);
        echo "Self-test verify result: " . ($verifyResult === 1 ? "✓ VALID" : ($verifyResult === 0 ? "✗ INVALID" : "ERROR")) . "\n";
        
        if ($verifyResult === 1) {
            echo "\n✓ GOOD NEWS: Your own key pair works correctly!\n";
            echo "  The problem is VouchMorph is using a DIFFERENT key pair.\n";
        } else {
            echo "\n✗ BAD NEWS: Your own key pair doesn't even work!\n";
            echo "  The keys in your environment are corrupted.\n";
        }
        
        openssl_free_key($privateKey);
    }
}

// ============================================
// DIAGNOSTIC SUMMARY AND RECOMMENDATION
// ============================================

echo "\n========== SUMMARY & RECOMMENDATION ==========\n";

if ($resultA === 1 || $resultB === 1 || $resultC === 1 || $resultD === 1 || $resultE === 1 || $resultF === 1) {
    echo "✓✓✓ A VARIATION WORKED! ✓✓✓\n\n";
    if ($resultA === 1) echo "  → Use Method A (original order, no sorting)\n";
    if ($resultB === 1) echo "  → Use Method B (sorted keys)\n";
    if ($resultC === 1) echo "  → Use Method C (_timestamp instead of timestamp)\n";
    if ($resultD === 1) echo "  → Use Method D (numbers as strings)\n";
    if ($resultE === 1) echo "  → Use Method E (no timestamp)\n";
    if ($resultF === 1) echo "  → Use Method F (JSON_PRESERVE_ZERO_FRACTION)\n";
} else {
    echo "✗ NO VARIATION WORKED\n\n";
    echo "This confirms the issue is NOT payload formatting.\n";
    echo "The problem is ONE of these:\n\n";
    echo "1. KEY MISMATCH - VouchMorph is using a different private key\n";
    echo "   → Solution: Exchange new public keys with VouchMorph\n\n";
    echo "2. WRONG SIGNATURE ALGORITHM - VouchMorph might be using:\n";
    echo "   → RSASSA-PSS instead of PKCS#1 v1.5\n";
    echo "   → SHA384 or SHA512 instead of SHA256\n";
    echo "   → Raw RSA without hashing\n\n";
    echo "3. DIFFERENT ENCODING - VouchMorph might be:\n";
    echo "   → Using DER encoding instead of raw signature\n";
    echo "   → Adding ASN.1 headers to the signature\n\n";
}

openssl_free_key($keyResource);
