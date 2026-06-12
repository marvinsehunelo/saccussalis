<?php
// backend/api/v1/test_verify.php
// Standalone signature verification test

require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json');

// Get the raw input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

echo "========== SIGNATURE VERIFICATION TEST SUITE ==========\n\n";

// Get the stored public key
$publicKey = getenv('VOUCHMORPH_PUBLIC_KEY');
if (!$publicKey) {
    echo "ERROR: VOUCHMORPH_PUBLIC_KEY not found in environment\n";
    exit;
}

$publicKey = str_replace(['\\n', '\n'], "\n", $publicKey);
$pubKeyResource = openssl_pkey_get_public($publicKey);

if (!$pubKeyResource) {
    echo "ERROR: Cannot load public key: " . openssl_error_string() . "\n";
    exit;
}

$keyDetails = openssl_pkey_get_details($pubKeyResource);
echo "Public key fingerprint: " . hash('sha256', $keyDetails['key']) . "\n";
echo "Expected fingerprint:   88b4e56bdda18b632569e8e15bb31c14ddc890591c428e15a2d124e65ad98e23\n\n";

// Extract signature and payload
$signature = $input['signature'] ?? null;
if (!$signature) {
    echo "ERROR: No signature found in request\n";
    exit;
}

$decodedSig = base64_decode($signature);
echo "Signature length: " . strlen($decodedSig) . " bytes\n\n";

// Create payload without signature
$payloadToVerify = $input;
unset($payloadToVerify['signature']);

// ============================================================
// TEST 1: No modification, no sorting
// ============================================================
echo "TEST 1: Original payload as-is (no sorting, no field removal)\n";
$json1 = json_encode($payloadToVerify);
$result1 = openssl_verify($json1, $decodedSig, $pubKeyResource, OPENSSL_ALGO_SHA256);
echo "  JSON: " . $json1 . "\n";
echo "  Result: " . ($result1 === 1 ? "✓ VALID" : ($result1 === 0 ? "✗ INVALID" : "ERROR")) . "\n\n";

// ============================================================
// TEST 2: Remove requester field (not part of signature)
// ============================================================
echo "TEST 2: Remove 'requester' field\n";
$test2 = $payloadToVerify;
unset($test2['requester']);
$json2 = json_encode($test2);
$result2 = openssl_verify($json2, $decodedSig, $pubKeyResource, OPENSSL_ALGO_SHA256);
echo "  JSON: " . $json2 . "\n";
echo "  Result: " . ($result2 === 1 ? "✓ VALID" : ($result2 === 0 ? "✗ INVALID" : "ERROR")) . "\n\n";

// ============================================================
// TEST 3: Sort keys (alphabetical)
// ============================================================
echo "TEST 3: Sort keys alphabetically\n";
$test3 = $payloadToVerify;
unset($test3['requester']);
ksort($test3);
$json3 = json_encode($test3);
$result3 = openssl_verify($json3, $decodedSig, $pubKeyResource, OPENSSL_ALGO_SHA256);
echo "  JSON: " . $json3 . "\n";
echo "  Result: " . ($result3 === 1 ? "✓ VALID" : ($result3 === 0 ? "✗ INVALID" : "ERROR")) . "\n\n";

// ============================================================
// TEST 4: Sort keys + JSON_UNESCAPED_SLASHES
// ============================================================
echo "TEST 4: Sort keys + JSON_UNESCAPED_SLASHES\n";
$test4 = $payloadToVerify;
unset($test4['requester']);
ksort($test4);
$json4 = json_encode($test4, JSON_UNESCAPED_SLASHES);
$result4 = openssl_verify($json4, $decodedSig, $pubKeyResource, OPENSSL_ALGO_SHA256);
echo "  JSON: " . $json4 . "\n";
echo "  Result: " . ($result4 === 1 ? "✓ VALID" : ($result4 === 0 ? "✗ INVALID" : "ERROR")) . "\n\n";

// ============================================================
// TEST 5: Sort keys + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE
// ============================================================
echo "TEST 5: Sort keys + JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE\n";
$test5 = $payloadToVerify;
unset($test5['requester']);
ksort($test5);
$json5 = json_encode($test5, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$result5 = openssl_verify($json5, $decodedSig, $pubKeyResource, OPENSSL_ALGO_SHA256);
echo "  JSON: " . $json5 . "\n";
echo "  Result: " . ($result5 === 1 ? "✓ VALID" : ($result5 === 0 ? "✗ INVALID" : "ERROR")) . "\n\n";

// ============================================================
// TEST 6: Convert numbers to strings
// ============================================================
echo "TEST 6: Convert numbers to strings + sort + flags\n";
$test6 = $payloadToVerify;
unset($test6['requester']);
foreach ($test6 as $key => $value) {
    if (is_numeric($value) && !is_bool($value)) {
        $test6[$key] = (string)$value;
    }
}
ksort($test6);
$json6 = json_encode($test6, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$result6 = openssl_verify($json6, $decodedSig, $pubKeyResource, OPENSSL_ALGO_SHA256);
echo "  JSON: " . $json6 . "\n";
echo "  Result: " . ($result6 === 1 ? "✓ VALID" : ($result6 === 0 ? "✗ INVALID" : "ERROR")) . "\n\n";

// ============================================================
// TEST 7: What VouchMorph actually signs (from our test script)
// ============================================================
echo "TEST 7: What VouchMorph SHOULD sign (from test script)\n";
$test7 = $payloadToVerify;
unset($test7['requester']);
// Remove any fields that shouldn't be in signature
ksort($test7);
$vouchmorphSignedJson = json_encode($test7, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo "  Expected JSON from test script: " . $vouchmorphSignedJson . "\n";
$result7 = openssl_verify($vouchmorphSignedJson, $decodedSig, $pubKeyResource, OPENSSL_ALGO_SHA256);
echo "  Result: " . ($result7 === 1 ? "✓ VALID" : ($result7 === 0 ? "✗ INVALID" : "ERROR")) . "\n\n";

// ============================================================
// TEST 8: Raw input stream (exactly what was received)
// ============================================================
echo "TEST 8: Raw input stream (exactly as received)\n";
$rawStripped = $rawInput;
// Remove signature from raw JSON string
$rawStripped = preg_replace('/,"signature":"[^"]+"/', '', $rawStripped);
$rawStripped = preg_replace('/"signature":"[^"]+",?/', '', $rawStripped);
$result8 = openssl_verify($rawStripped, $decodedSig, $pubKeyResource, OPENSSL_ALGO_SHA256);
echo "  Raw (stripped): " . substr($rawStripped, 0, 200) . "...\n";
echo "  Result: " . ($result8 === 1 ? "✓ VALID" : ($result8 === 0 ? "✗ INVALID" : "ERROR")) . "\n\n";

// ============================================================
// SUMMARY
// ============================================================
echo "========== SUMMARY ==========\n";
$passed = false;
if ($result1 === 1) { echo "✓ TEST 1 passed\n"; $passed = true; }
if ($result2 === 1) { echo "✓ TEST 2 passed\n"; $passed = true; }
if ($result3 === 1) { echo "✓ TEST 3 passed\n"; $passed = true; }
if ($result4 === 1) { echo "✓ TEST 4 passed\n"; $passed = true; }
if ($result5 === 1) { echo "✓ TEST 5 passed\n"; $passed = true; }
if ($result6 === 1) { echo "✓ TEST 6 passed\n"; $passed = true; }
if ($result7 === 1) { echo "✓ TEST 7 passed\n"; $passed = true; }
if ($result8 === 1) { echo "✓ TEST 8 passed\n"; $passed = true; }

if (!$passed) {
    echo "\n✗ NO TESTS PASSED\n";
    echo "This means the public key in Saccussalis does NOT match VouchMorph's private key.\n";
    echo "\nSolution: Update VOUCHMORPH_PUBLIC_KEY with the correct public key.\n";
} else {
    echo "\n✓ A test passed! Use that method for verification.\n";
}

openssl_free_key($pubKeyResource);
?>
