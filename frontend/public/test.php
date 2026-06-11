<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers/crypto.php';

header("Content-Type: text/plain");

// FRESH VALUES FROM VOUCHMORPH
$signature = "l4Diu7jKgI+mVWMSiLQ6RLZcnhKtHT4aaXRhwJ7b+Jdo3kx9gJmXNo0jWyxuGAEnH4ZhCjrqkRESuc/hv8HbPkg2dKq/m4r2fmS92kdy8lADFFofM1Tmr5ZSAraaM8JRm2ZkbJIL93Qq1/kawwtID9DUAowjW35mRWvgJ8nzNVNmJRtNeWeOZdziPyHP+dST60fTpP7fqVVV0kd5lcA/Co0uQKF2e/gf6YEyQna1kLJXJRJCnNGFSs2tp/jaEillYDgbQPXl0QMPUVtL3gRExdKmA1hhYc2jTMtmFWZj1r81FYwlT/WR6gi9DqiENOXRJ8esh46jD8INagKMNyEU5A==";
$timestamp = 1781207723;

echo "Testing signature from VouchMorph\n";
echo "Timestamp: $timestamp\n";
echo "Signature (first 50 chars): " . substr($signature, 0, 50) . "...\n\n";

// Get VOUCHMORPH public key
$stmt = $pdo->prepare("SELECT public_key FROM trusted_partners WHERE name = 'VOUCHMORPH' AND is_active = true");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "ERROR: VOUCHMORPH public key not found in trusted_partners\n";
    exit;
}

$publicKey = $row['public_key'];
echo "Public key found (length: " . strlen($publicKey) . " chars)\n\n";

// Payload to verify (without signature)
$payloadToVerify = [
    'action' => 'VERIFY_ASSET',
    'reference' => 'TEST_1781207723',
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'institution' => 'SACCUSSALIS',
    'swap_type' => 'CASHOUT',
    'source_identifier' => '+26770000000'
];

echo "Verifying signature...\n";
$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

echo "\n========================================\n";
echo "RESULT: " . ($isValid ? "VALID ✓" : "INVALID ✗") . "\n";
echo "========================================\n";
