<?php
// test_saccussalis_certificate.php
// Run on Saccussalis server

require_once __DIR__ . '/../../backend/helpers/CertificateManager.php';

echo "=== SACCUSSALIS CERTIFICATE VERIFICATION TEST ===\n\n";

// Test payloads that Saccussalis would receive from VouchMorph
$testPayloads = [
    'VERIFY_ACCOUNT (WALLET)' => [
        'action' => 'VERIFY_ACCOUNT',
        'reference' => 'SWAP_1784973884251',
        'account_identifier' => '+26770000000',
        'identifier_type' => 'phone',
        'requester' => 'VOUCHMORPH',
        'timestamp' => time(),
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'to_institution' => 'SACCUSSALIS',
        'destination_institution' => 'SACCUSSALIS',
        'destination_asset_type' => 'WALLET'
    ],
    'VERIFY_ACCOUNT (ACCOUNT)' => [
        'action' => 'VERIFY_ACCOUNT',
        'reference' => 'SWAP_1784973884252',
        'account_identifier' => '10000001',
        'identifier_type' => 'account_number',
        'requester' => 'VOUCHMORPH',
        'timestamp' => time(),
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'to_institution' => 'SACCUSSALIS',
        'destination_institution' => 'SACCUSSALIS',
        'destination_asset_type' => 'ACCOUNT'
    ],
    'PROCESS_DEPOSIT_WITH_PROOF' => [
        '_skip_hold' => true,
        'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
        'reference' => 'SWAP_1784973884251',
        'amount' => 994,
        'currency' => 'BWP',
        'asset_type' => 'WALLET',
        'destination_asset_type' => 'WALLET',
        'destination_identifier' => '+26770000000',
        'destination_identifier_type' => 'phone',
        'destination_institution' => 'SACCUSSALIS',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'to_institution' => 'SACCUSSALIS',
        'hold_reference' => 'SWAP_1784973884251',
        'user_id' => 42,
        'bank' => 'ZURUBANK',
        'requester' => 'VOUCHMORPH',
        'timestamp' => time()
    ]
];

$cm = new CertificateManager('SACCUSSALIS');

echo "CertificateManager configured: " . ($cm->isConfigured() ? "YES" : "NO") . "\n";

// Simulate a signed request from VouchMorph
$vouchmorphSigner = new CertificateManager('VOUCHMORPH');

foreach ($testPayloads as $name => $payload) {
    echo "\n=== TESTING $name ===\n";
    echo "Original payload keys: " . implode(', ', array_keys($payload)) . "\n";
    
    // Sign as VouchMorph would
    $signed = $vouchmorphSigner->createSignedRequest($payload, 'VOUCHMORPH');
    
    echo "Signed payload keys: " . implode(', ', array_keys($signed)) . "\n";
    echo "Has signature: " . (isset($signed['signature']) ? 'YES' : 'NO') . "\n";
    echo "Has certificate: " . (isset($signed['certificate']) ? 'YES' : 'NO') . "\n";
    echo "Has requester: " . (isset($signed['requester']) ? 'YES' : 'NO') . "\n";
    
    // Verify as Saccussalis would
    $verification = $cm->verifySignedRequest($signed);
    echo "Verification result: " . ($verification['verified'] ? "VALID ✓" : "INVALID ✗") . "\n";
    echo "Message: " . $verification['message'] . "\n";
    
    if (!$verification['verified']) {
        echo "\n=== DEBUG: What Saccussalis is verifying ===\n";
        $payloadToVerify = $signed;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        unset($payloadToVerify['requester']);
        ksort($payloadToVerify);
        echo "JSON verified: " . json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        
        echo "\n=== What was signed by VouchMorph ===\n";
        $signedPayload = $signed;
        unset($signedPayload['signature']);
        unset($signedPayload['certificate']);
        // requester IS included in VouchMorph's signed payload
        ksort($signedPayload);
        echo "JSON signed: " . json_encode($signedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        
        echo "\n=== DIFFERENCES ===\n";
        $signedKeys = array_keys($signedPayload);
        $verifyKeys = array_keys($payloadToVerify);
        echo "Signed keys: " . implode(', ', $signedKeys) . "\n";
        echo "Verify keys: " . implode(', ', $verifyKeys) . "\n";
        
        $missingFromVerify = array_diff($signedKeys, $verifyKeys);
        $extraInVerify = array_diff($verifyKeys, $signedKeys);
        
        if (!empty($missingFromVerify)) {
            echo "Keys in signed but NOT in verify: " . implode(', ', $missingFromVerify) . "\n";
        }
        if (!empty($extraInVerify)) {
            echo "Keys in verify but NOT in signed: " . implode(', ', $extraInVerify) . "\n";
        }
    }
}

echo "\n=== TEST COMPLETE ===\n";
