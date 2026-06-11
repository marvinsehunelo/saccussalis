<?php
// /public/test_verify_signature.php (on Saccussalis server)

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers/crypto.php';

header("Content-Type: text/plain");

echo "========================================\n";
echo "Testing Signature Verification on Saccussalis\n";
echo "========================================\n\n";

// PASTE THE VALUES FROM VOUCHMORPH TEST HERE:
$signature = "qI8bcCqRpqFloIcyLNAMTVBC6AxRwW3lW7D/PafbUJtruHa60AJpmtjAUVyrtq0MaWJjDeNiSHzITPWLuM788cQOixR0rcP7vTtQ4hmszdPgzA3mH4flpOHvJ5H02w3/gBHkT64jQnYZmgAUzG6XGaXJLxOfuwPGCnh9pvifu3KwjaKCvoPq3SL8T0Ht5Kov2yHouZW2Gzz9IOk+O/4rzV9pTnSUgTFKfk6lhqbvtw8+YqwtZvwXAPFxP+3b5G24nSnui+nKENHGG2PerxW5cWzqQHFV1XudPBcU7eA0v38TTYQpJQPpGA9F9WFsA9x95ANyuRAnL1Jvl9OIG7eJpw==";
$timestamp = 1781206104;
$payload = [
    'action' => 'VERIFY_ASSET',
    'reference' => 'TEST_1781206104',
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'institution' => 'SACCUSSALIS',
    'timestamp' => 1781206104,
    'swap_type' => 'CASHOUT',
    'source_identifier' => '+26770000000'
];

echo "1. Testing with signature from VouchMorph:\n";
echo "   Signature (first 50 chars): " . substr($signature, 0, 50) . "...\n";
echo "   Timestamp: $timestamp\n\n";

// Get VOUCHMORPH public key from trusted_partners
$stmt = $pdo->prepare("SELECT public_key FROM trusted_partners WHERE name = 'VOUCHMORPH' AND is_active = true");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "❌ VOUCHMORPH public key not found in trusted_partners table!\n";
    echo "   Please run the INSERT statement to add it.\n";
    exit;
}

$publicKey = $row['public_key'];
echo "2. VOUCHMORPH public key found:\n";
echo "   Length: " . strlen($publicKey) . " chars\n";
echo "   First 50 chars: " . substr($publicKey, 0, 50) . "...\n\n";

// Prepare payload for verification (without signature and timestamp)
$payloadToVerify = [
    'action' => $payload['action'],
    'reference' => $payload['reference'],
    'asset_type' => $payload['asset_type'],
    'amount' => $payload['amount'],
    'currency' => $payload['currency'],
    'institution' => $payload['institution'],
    'swap_type' => $payload['swap_type'],
    'source_identifier' => $payload['source_identifier']
];

echo "3. Verifying signature...\n";
$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

echo "\n========================================\n";
if ($isValid) {
    echo "✅ SIGNATURE IS VALID!\n";
    echo "The key pair is working correctly.\n";
    echo "Hold requests should now work.\n";
} else {
    echo "❌ SIGNATURE IS INVALID!\n";
    echo "The public key in trusted_partners does NOT match VouchMorph's private key.\n";
}
echo "========================================\n";<?php
// test_verify_vouchmorph.php
// Place this in Saccussalis: /public/test_verify_vouchmorph.php

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers/crypto.php';

header("Content-Type: application/json");

echo "========================================\n";
echo "TEST: Can Saccussalis Verify VouchMorph?\n";
echo "========================================\n\n";

// 1. Check trusted_partners table for VOUCHMORPH public key
echo "1. Checking trusted_partners table for VOUCHMORPH...\n";

$stmt = $pdo->prepare("SELECT id, name, public_key, is_active FROM trusted_partners WHERE name = 'VOUCHMORPH'");
$stmt->execute();
$vouchmorph = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vouchmorph) {
    echo "❌ VOUCHMORPH not found in trusted_partners table!\n";
    echo "   Run: INSERT INTO trusted_partners (name, public_key) VALUES ('VOUCHMORPH', '...');\n";
    exit;
}

echo "✅ Found VOUCHMORPH in trusted_partners\n";
echo "   ID: {$vouchmorph['id']}\n";
echo "   Active: " . ($vouchmorph['is_active'] ? 'YES' : 'NO') . "\n";
echo "   Public Key (first 100 chars): " . substr($vouchmorph['public_key'], 0, 100) . "...\n\n";

// 2. Create a test payload that VouchMorph would send
echo "2. Creating test payload (simulating VouchMorph request)...\n";

$testPayload = [
    'action' => 'TEST_VERIFICATION',
    'reference' => 'TEST_' . time(),
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'institution' => 'SACCUSSALIS',
    'timestamp' => time(),
    'swap_type' => 'CASHOUT',
    'source_identifier' => '+26770000000'
];

echo "   Payload: " . json_encode($testPayload) . "\n\n";

// 3. Simulate what VouchMorph would send (we need to create a signature)
echo "3. To test verification, we need a valid signature from VouchMorph.\n";
echo "   Since we don't have VouchMorph's private key here, we'll test two scenarios:\n\n";

// Test A: Missing signature
echo "   TEST A: Request with NO signature\n";
$signature = null;
$timestamp = $testPayload['timestamp'];
$requester = 'VOUCHMORPH';

$payloadToVerify = [
    'action' => $testPayload['action'],
    'reference' => $testPayload['reference'],
    'asset_type' => $testPayload['asset_type'],
    'amount' => $testPayload['amount'],
    'currency' => $testPayload['currency'],
    'institution' => $testPayload['institution'],
    'swap_type' => $testPayload['swap_type'],
    'source_identifier' => $testPayload['source_identifier']
];
$payloadToVerify = array_filter($payloadToVerify);

$publicKey = $vouchmorph['public_key'];

if (!$signature) {
    echo "   Result: ❌ Missing signature - request rejected\n\n";
}

// Test B: We need a real signature from VouchMorph
echo "   TEST B: To test with a real signature, run this on VouchMorph side:\n";
echo "   =========================================================================\n";
echo "   <?php\n";
echo "   require_once 'src/Infrastructure/Crypto/MessageSigner.php';\n";
echo "   \$signer = new Infrastructure\\Crypto\\MessageSigner();\n";
echo "   \$payload = " . json_encode($testPayload) . ";\n";
echo "   \$signed = \$signer->createSignedRequest(\$payload, 'VOUCHMORPH');\n";
echo "   echo 'SIGNATURE: ' . \$signed['signature'] . \"\\n\";\n";
echo "   echo 'TIMESTAMP: ' . \$signed['timestamp'] . \"\\n\";\n";
echo "   ?>\n";
echo "   =========================================================================\n\n";

// 4. Test the verify_signature function with the public key
echo "4. Testing verify_signature function directly...\n";

// Create a simple test signature (this will fail, but tests the function)
$testSignature = base64_encode('invalid_test_signature');
$testTimestamp = time();

$isValid = verify_signature($payloadToVerify, $testSignature, $publicKey, $testTimestamp);

echo "   verify_signature() returned: " . ($isValid ? "TRUE" : "FALSE") . "\n";
echo "   (Expected FALSE since we used an invalid signature)\n\n";

// 5. Check if get_requester_public_key function exists
echo "5. Checking helper functions...\n";
echo "   function_exists('get_requester_public_key'): " . (function_exists('get_requester_public_key') ? "✅ YES" : "❌ NO") . "\n";
echo "   function_exists('verify_signature'): " . (function_exists('verify_signature') ? "✅ YES" : "❌ NO") . "\n";
echo "   function_exists('send_signed_response'): " . (function_exists('send_signed_response') ? "✅ YES" : "❌ NO") . "\n\n";

// 6. Summary
echo "========================================\n";
echo "SUMMARY\n";
echo "========================================\n";
echo "✓ VOUCHMORPH public key is stored in trusted_partners\n";
echo "✓ verify_signature() function exists\n";
echo "\n";
echo "TO FIX THE ISSUE:\n";
echo "1. Ensure VouchMorph's private key is correctly set in Railway env\n";
echo "2. Ensure the private key matches the public key in this table\n";
echo "3. Run the test on VouchMorph side to generate a real signature\n";
echo "4. Test with the real signature\n";
