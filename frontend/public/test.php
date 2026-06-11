<?php
// /app/frontend/public/test.php

// Fix the paths - go up to root then into backend
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers/crypto.php';

header("Content-Type: text/plain");

$signature = "qI8bcCqRpqFloIcyLNAMTVBC6AxRwW3lW7D/PafbUJtruHa60AJpmtjAUVyrtq0MaWJjDeNiSHzITPWLuM788cQOixR0rcP7vTtQ4hmszdPgzA3mH4flpOHvJ5H02w3/gBHkT64jQnYZmgAUzG6XGaXJLxOfuwPGCnh9pvifu3KwjaKCvoPq3SL8T0Ht5Kov2yHouZW2Gzz9IOk+O/4rzV9pTnSUgTFKfk6lhqbvtw8+YqwtZvwXAPFxP+3b5G24nSnui+nKENHGG2PerxW5cWzqQHFV1XudPBcU7eA0v38TTYQpJQPpGA9F9WFsA9x95ANyuRAnL1Jvl9OIG7eJpw==";
$timestamp = 1781206104;

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
    'reference' => 'TEST_1781206104',
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'institution' => 'SACCUSSALIS',
    'swap_type' => 'CASHOUT',
    'source_identifier' => '+26770000000'
];

$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

echo "Signature verification result: " . ($isValid ? "VALID" : "INVALID") . "\n";
