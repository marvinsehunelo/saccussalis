<?php
// /public/verify_test.php

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers/crypto.php';

header("Content-Type: text/plain");

// === PASTE THE VALUES FROM VOUCHMORPH HERE (with quotes around signature!) ===
$signature = "UHy8TGh9h8zBHM2eEF2K5w2NGmgDCUA+iZWFXI22LYMrCIKICAEvL3ZnlkSkmoomsOBOpsymRlAms2pLnR9dJX4byC2rOggU9ywBL0p22v6CluUtJs0iKaFfnCc5TwVytBePId5UPnnYDmKHnU3tDxQt1hlbEfcmMwbTpS3qRmU93Tp5iBMs6kezC38pFtUISnGaRWeSUmOrxOBz9vEKBLAzAkjr+3pGuC8atcHLjdkDp8semtTQV+jDZYgjKApvKY9EHwlfh70hpstBit8QEH62Mityqg4A654djiRQ3auvQLOG0oX4Eaytzg/Vl5xFsbnUHUkmvYKvdvI8Pu+a/w==";
$timestamp = 1781211055;
$jsonThatWasSigned = '{"action":"PLACE_HOLD","amount":100,"asset_id":4,"asset_type":"BANK-WALLET","currency":"BWP","destination_institution":"ZURUBANK","email":"+26770000000","expiry":"2026-06-11 22:00:00","hold_reason":"PENDING_SWAP","national_id":"+26770000000","phone":"+26770000000","reference":"SWAP_TEST_001","source_identifier":"+26770000000","source_identifier_type":"phone","wallet_phone":"+26770000000","_timestamp":1781211055}';
// ============================================

// Get stored public key
$stmt = $pdo->prepare("SELECT public_key FROM trusted_partners WHERE name = 'VOUCHMORPH'");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$publicKey = $row['public_key'];

echo "=== SACCUSSALIS VERIFYING ===\n\n";
echo "Public key length: " . strlen($publicKey) . "\n";
echo "Using public key (first 50): " . substr($publicKey, 0, 50) . "...\n\n";
echo "Verifying JSON:\n" . $jsonThatWasSigned . "\n\n";

// Verify directly using the JSON string
$result = openssl_verify(
    $jsonThatWasSigned,
    base64_decode($signature),
    $publicKey,
    OPENSSL_ALGO_SHA256
);

echo "openssl_verify result: " . $result . "\n";
echo "SIGNATURE IS: " . ($result === 1 ? "VALID ✓" : "INVALID ✗") . "\n";

if ($result === -1) {
    echo "OpenSSL error: " . openssl_error_string() . "\n";
}

// Also test with verify_signature function
$payloadWithoutTimestamp = json_decode($jsonThatWasSigned, true);
unset($payloadWithoutTimestamp['_timestamp']);

$isValid = verify_signature($payloadWithoutTimestamp, $signature, $publicKey, $timestamp);
echo "\nverify_signature() result: " . ($isValid ? "VALID ✓" : "INVALID ✗") . "\n";
