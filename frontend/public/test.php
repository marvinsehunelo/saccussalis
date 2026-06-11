<?php
// /frontend/public/test_fresh.php

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers/crypto.php';

header("Content-Type: text/plain");

// PASTE THE FRESH VALUES HERE:
$signature = "PASTE_SIGNATURE_HERE";
$timestamp = 1234567890; // Replace with actual timestamp

$stmt = $pdo->prepare("SELECT public_key FROM trusted_partners WHERE name = 'VOUCHMORPH' AND is_active = true");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "ERROR: VOUCHMORPH public key not found\n";
    exit;
}

$publicKey = $row['public_key'];

$payloadToVerify = [
    'action' => 'VERIFY_ASSET',
    'reference' => 'TEST_' . $timestamp,
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'institution' => 'SACCUSSALIS',
    'swap_type' => 'CASHOUT',
    'source_identifier' => '+26770000000'
];

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

echo "RESULT: " . ($isValid ? "VALID ✓" : "INVALID ✗") . "\n";
