<?php
require_once '/app/backend/helpers/CertificateManager.php';


// Sample payload from the logs
$payload = json_decode('{"action":"GENERATE_TOKEN","amount":400,"beneficiary_phone":"+26770000000","currency":"BWP","destination_institution":"SACCUSSALIS","from_institution":"ZURUBANK","hold_reference":"CASHOUT_ZURU_1784714501","reference":"CASHOUT_ZURU_1784714501","requester":"VOUCHMORPH","source_hold":{"payload":{"action":"PLACE_HOLD","reference":"CASHOUT_ZURU_1784714501","asset_type":"ACCOUNT","amount":500,"currency":"BWP","hold_reason":"PENDING_SWAP","destination_institution":"SACCUSSALIS","expiry":"2026-07-23 10:01:42","timestamp":1784714502,"from_institution":"ZURUBANK","source_institution":"ZURUBANK","user_id":1,"source_identifier":"10000001","source_identifier_type":"account","asset_id":10},"signature":"bnvwBZgaAUEhnJ7TeOcCZHLc/bPYFAzm82thhEqcdR5JQ7uLAlExhJ/0WeT1P86Q0YlzZ1s0RCEvvyqDvq4tM/Wn7ZRwixVQMwKmQgjWTcJiEZUHgycAUnQoWbtjRfGeEqPFK1Taa0NGEZEBqIGXpTqEe+xUjY1Wnr3oCG8pzESnIFowG9uQuffFt3QO7a/nK+kJfWAHqOBMeYAI4jiR1pJsXzNxOIcG6OnqMnhyjFyV5sQsm0eBlUBkzV0XGYNpihcFc1YmSCVSnMUJO5cT8eEgIOgRAw19/U3ddtBeyAV75kjUh0wQ2ZRBbzV/iUA58ht96MTPU0N0Fg3+yNU3Zg==","source":"ZURUBANK","timestamp":1784714502,"is_hooked":false},"source_institution":"ZURUBANK","source_verification":{"payload":{"action":"VERIFY_ASSET","reference":"CASHOUT_ZURU_1784714501","asset_type":"ACCOUNT","amount":500,"currency":"BWP","institution":"ZURUBANK","timestamp":1784714502,"swap_type":"CASHOUT","requester":"VOUCHMORPH","from_institution":"ZURUBANK","source_institution":"ZURUBANK","source_identifier":"10000001","source_identifier_type":"account"},"signature":"zv5buTjJwWMAMx3aCUXor5iFkP4ZGbwrurv8Mms2ya5CPrz8/eavygkCWi/R1drlX/hqYfXLFAMN9KmSpzXm9nRVnr8qw6SU2gJLBP/ocIOIk3dGk1quSUAo1PIyDk3gAGJPmbjRCv1R/2+LITB7chZjVFB5LzPiqJ3WBEr/fCYUA1bMGMuA59IKUbfjyE4YnGEPDHtr9yB9/MjfWzCiW99dwsCg/Fz0yb7hW0xhn6nJsj4CWIT0yNdzOgykAmR5Ap5mrd5aO8vzXtcwzrNaraV7ivtHFEE+77krTHweezAcCIFj8WIXSPy3ch16SMEbG/vWE/aOwZqB6fw6Ce7F9A==","source":"ZURUBANK","timestamp":1784714502,"is_hooked":false},"timestamp":1784714502,"to_institution":"SACCUSSALIS"}', true);

$certManager = new CertificateManager('SACCUSSALIS');

// Test 1: Verify with the actual signature
$result1 = $certManager->verifySignedRequest($payload);
echo "Test 1 - Original verification: " . ($result1['verified'] ? "PASSED" : "FAILED") . "\n";
echo "Message: " . $result1['message'] . "\n\n";

// Test 2: Check what JSON is being verified
$payloadToVerify = $payload;
unset($payloadToVerify['certificate']);
// Remove all signatures recursively
function removeAllSignatures($data) {
    $result = [];
    foreach ($data as $key => $value) {
        if ($key === 'signature') continue;
        if (is_array($value)) {
            $result[$key] = removeAllSignatures($value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}
$payloadToVerify = removeAllSignatures($payloadToVerify);
ksort($payloadToVerify);
$jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo "JSON being verified: " . $jsonToVerify . "\n\n";

// Test 3: Manual verification with public key
$cert = $payload['certificate'];
$pubKey = $certManager->extractPublicKeyFromCert($cert);
$sig = base64_decode($payload['signature']);
$keyResource = openssl_pkey_get_public($pubKey);
$result = openssl_verify($jsonToVerify, $sig, $keyResource, OPENSSL_ALGO_SHA256);
echo "Test 3 - Manual verification result: " . $result . " (1=valid, 0=invalid, -1=error)\n";
