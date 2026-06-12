<?php
// Place this as backend/api/v1/test_signature_debug.php on Saccussalis
// Call it from VouchMorph to debug

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/crypto.php';

header('Content-Type: application/json');

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    error_log("\n\n========== SIGNATURE DEBUG TOOL ==========");
    error_log("Raw Input: " . $rawInput);
    
    $signature = $input['signature'] ?? null;
    $requester = $input['requester'] ?? 'VOUCHMORPH';
    $timestamp = $input['timestamp'] ?? null;
    
    // Get the public key
    $publicKey = get_requester_public_key($requester, $pdo);
    
    if (!$publicKey) {
        echo json_encode(['error' => 'Public key not found']);
        exit;
    }
    
    // Remove signature for verification
    $payloadToVerify = $input;
    unset($payloadToVerify['signature']);
    
    $results = [];
    
    // TEST 1: VouchMorph's likely method (no sorting, no modification)
    $json1 = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig1 = base64_decode($signature);
    $result1 = openssl_verify($json1, $sig1, $publicKey, OPENSSL_ALGO_SHA256);
    $results['Method 1: No sorting, raw payload'] = [
        'json' => $json1,
        'result' => $result1 === 1 ? 'VALID' : ($result1 === 0 ? 'INVALID' : 'ERROR'),
        'json_length' => strlen($json1)
    ];
    
    // TEST 2: Sorted keys
    $sorted = $payloadToVerify;
    ksort($sorted);
    $json2 = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result2 = openssl_verify($json2, $sig1, $publicKey, OPENSSL_ALGO_SHA256);
    $results['Method 2: Sorted keys'] = [
        'json' => $json2,
        'result' => $result2 === 1 ? 'VALID' : ($result2 === 0 ? 'INVALID' : 'ERROR'),
        'json_length' => strlen($json2)
    ];
    
    // TEST 3: With _timestamp instead of timestamp
    $withUnderscore = $payloadToVerify;
    if (isset($withUnderscore['timestamp'])) {
        $withUnderscore['_timestamp'] = $withUnderscore['timestamp'];
        unset($withUnderscore['timestamp']);
    }
    $json3 = json_encode($withUnderscore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result3 = openssl_verify($json3, $sig1, $publicKey, OPENSSL_ALGO_SHA256);
    $results['Method 3: _timestamp instead of timestamp'] = [
        'json' => $json3,
        'result' => $result3 === 1 ? 'VALID' : ($result3 === 0 ? 'INVALID' : 'ERROR'),
        'json_length' => strlen($json3)
    ];
    
    // TEST 4: Numbers as strings
    $asStrings = $payloadToVerify;
    foreach ($asStrings as $key => $value) {
        if (is_int($value) || is_float($value)) {
            $asStrings[$key] = (string)$value;
        }
    }
    $json4 = json_encode($asStrings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result4 = openssl_verify($json4, $sig1, $publicKey, OPENSSL_ALGO_SHA256);
    $results['Method 4: Numbers as strings'] = [
        'json' => $json4,
        'result' => $result4 === 1 ? 'VALID' : ($result4 === 0 ? 'INVALID' : 'ERROR'),
        'json_length' => strlen($json4)
    ];
    
    // TEST 5: Without any timestamp
    $noTimestamp = $payloadToVerify;
    unset($noTimestamp['timestamp']);
    unset($noTimestamp['_timestamp']);
    $json5 = json_encode($noTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result5 = openssl_verify($json5, $sig1, $publicKey, OPENSSL_ALGO_SHA256);
    $results['Method 5: No timestamp field'] = [
        'json' => $json5,
        'result' => $result5 === 1 ? 'VALID' : ($result5 === 0 ? 'INVALID' : 'ERROR'),
        'json_length' => strlen($json5)
    ];
    
    // TEST 6: Without spaces in JSON (compact vs pretty)
    $json6 = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    $result6 = openssl_verify($json6, $sig1, $publicKey, OPENSSL_ALGO_SHA256);
    $results['Method 6: JSON_PRESERVE_ZERO_FRACTION'] = [
        'json' => $json6,
        'result' => $result6 === 1 ? 'VALID' : ($result6 === 0 ? 'INVALID' : 'ERROR'),
        'json_length' => strlen($json6)
    ];
    
    // Display the public key fingerprint for reference
    $fingerprint = hash('sha256', $publicKey);
    
    $response = [
        'public_key_fingerprint' => $fingerprint,
        'public_key_preview' => substr($publicKey, 0, 100) . '...',
        'signature_preview' => substr($signature, 0, 50) . '...',
        'timestamp' => $timestamp,
        'server_time' => time(),
        'test_results' => $results,
        'recommendation' => ''
    ];
    
    // Find if any method worked
    $anyValid = false;
    foreach ($results as $method => $data) {
        if ($data['result'] === 'VALID') {
            $anyValid = true;
            $response['recommendation'] = "✓ Method works: $method";
            $response['working_json'] = $data['json'];
            break;
        }
    }
    
    if (!$anyValid) {
        $response['recommendation'] = "NO METHOD WORKS - Keys definitely don't match or signature generation is different";
        
        // Let's also test if the public key can even verify anything
        // Create a test signature ourselves
        $testPayload = ['test' => 'data', 'timestamp' => time()];
        $testJson = json_encode($testPayload);
        
        // Try to sign with our private key (if available)
        $privateKey = getenv('SACCUSSALIS_PRIVATE_KEY');
        if ($privateKey) {
            $privateKey = str_replace(['\\n', '\n'], "\n", $privateKey);
            $privKey = openssl_pkey_get_private($privateKey);
            if ($privKey) {
                $testSig = '';
                openssl_sign($testJson, $testSig, $privKey, OPENSSL_ALGO_SHA256);
                $testSigB64 = base64_encode($testSig);
                
                // Now verify with our public key
                $testVerify = openssl_verify($testJson, $testSig, $publicKey, OPENSSL_ALGO_SHA256);
                $response['self_test'] = [
                    'can_verify_own_signature' => $testVerify === 1,
                    'public_key_works_for_self_signing' => $testVerify === 1
                ];
                
                openssl_free_key($privKey);
            }
        }
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
