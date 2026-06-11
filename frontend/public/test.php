<?php
// /public/test_verify.php (on Saccussalis server)
// This file should have NO HTML tags - pure PHP

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers/crypto.php';

// Set content type to plain text
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
    echo "SUCCESS - SIGNATURE IS VALID!\n";
    echo "The key pair is working correctly.\n";
    echo "Hold requests should now work.\n";
} else {
    echo "FAILURE - SIGNATURE IS INVALID!\n";
    echo "The public key in trusted_partners does NOT match VouchMorph's private key.\n";
}
echo "========================================\n";
