<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers/crypto.php';

header("Content-Type: text/plain");

// FRESH VALUES FROM VOUCHMORPH
$signature = "O0paexzLEKtRproK3Oy1FwPMvmc8T2RCqQkn+BnV96puUOFRD+uJRTXwKXi0tCGkNTvS9gRJKf60qIJfECVGxWoyTUoXmT3kxuVbVBGh3RSXVa4ASo5xS64YEfmVSTvv39xxd+HC8TYpN8O6sxPS+q8STgLtwcm95eOa7uKbTF4DvbyUCAa+odN8aVMzVD8sQHmJJunfg0ts4XxzORgzYFqE/bg/p7fjs9rO8N1cOrqfcxrYf63VHaQFRFqWD5xD9+vhMnmkJBhwSkkSr6ryiQP1bdbOEhUHazSCBifHlZyGGnzStEGptuAajvgfPes5DITNjzbWJ5nQVx2kSw3r6w==";
$timestamp = 1781208203;

echo "Testing signature from VouchMorph\n";
echo "Timestamp: $timestamp\n";
echo "Signature (first 50 chars): " . substr($signature, 0, 50) . "...\n\n";

// Get VOUCHMORPH public key
$stmt = $pdo->prepare("SELECT public_key FROM trusted_partners WHERE name = 'VOUCHMORPH' AND is_active = true");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "ERROR: VOUCHMORPH public key not found\n";
    exit;
}

$publicKey = $row['public_key'];
echo "Public key found (length: " . strlen($publicKey) . " chars)\n";
echo "Public key first 50 chars: " . substr($publicKey, 0, 50) . "...\n\n";

// Payload to verify (without signature)
$payloadToVerify = [
    'action' => 'VERIFY_ASSET',
    'reference' => 'TEST_1781208203',
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
