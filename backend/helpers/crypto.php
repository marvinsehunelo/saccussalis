<?php
// /SaccusSalisbank/backend/helpers/crypto.php

/**
 * Get public key for a requester institution from environment or trusted_partners table
 * This is how Saccussalis verifies that requests come from trusted partners (like VOUCHMORPH)
 */
function get_requester_public_key($requester, $pdo)
{
    error_log("get_requester_public_key called for: {$requester}");
    
    // For VOUCHMORPH, check environment variable first (no database issues)
    if ($requester === 'VOUCHMORPH') {
        $envKey = getenv('VOUCHMORPH_PUBLIC_KEY');
        if ($envKey && !empty($envKey)) {
            error_log("Using VOUCHMORPH_PUBLIC_KEY from environment");
            return $envKey;
        }
    }
    
    // Fallback to database for other institutions
    try {
        $stmt = $pdo->prepare("
            SELECT public_key 
            FROM trusted_partners 
            WHERE name = :name 
            AND is_active = true
            LIMIT 1
        ");
        $stmt->execute([':name' => $requester]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && !empty($row['public_key'])) {
            error_log("Found public key for {$requester} in trusted_partners table");
            return $row['public_key'];
        }
        
        error_log("No public key found for requester: {$requester}");
        return null;
        
    } catch (Exception $e) {
        error_log("Database error getting public key: " . $e->getMessage());
        return null;
    }
}

/**
 * Sign a payload for outgoing response using RSA private key
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        // Get private key from environment variable (Railway secret)
        $privateKeyContent = getenv('SACCUSSALIS_PRIVATE_KEY');
        if (!$privateKeyContent) {
            error_log("SACCUSSALIS_PRIVATE_KEY not found in environment");
            return null;
        }
        // Handle line breaks in environment variable
        $privateKeyContent = str_replace('\\n', "\n", $privateKeyContent);
        $privateKeyContent = str_replace('\n', "\n", $privateKeyContent);
        $privateKey = openssl_pkey_get_private($privateKeyContent);
        if (!$privateKey) {
            error_log("Failed to load private key: " . openssl_error_string());
            return null;
        }
    }
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['_timestamp' => $timestamp]);
    
    // Standardize outbound format: Sort keys alphabetically
    ksort($payloadWithTimestamp);
    $payloadJson = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    // Generate RSA signature
    $signature = '';
    $success = openssl_sign($payloadJson, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    
    if (!$success) {
        error_log("Failed to sign payload: " . openssl_error_string());
        return null;
    }
    
    return [
        'signature' => base64_encode($signature),
        'timestamp' => $timestamp
    ];
}

/**
 * Send signed JSON response
 */
function send_signed_response($payload, $httpCode = 200)
{
    $signed = sign_payload($payload);
    
    if (!$signed) {
        // Fallback to unsigned response if signing fails
        error_log("WARNING: Sending unsigned response due to signing failure");
        http_response_code($httpCode);
        echo json_encode($payload);
        exit;
    }
    
    $response = array_merge($payload, [
        'signature' => $signed['signature'],
        'timestamp' => $signed['timestamp']
    ]);
    
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}


function verify_signature($payload, $signature, $publicKey, $timestamp = null, $maxAgeSeconds = 300)
{
    error_log("\n========== RSA SIGNATURE DIAGNOSTIC START ==========");
    
    // Diagnostic 1: Timestamp validation
    error_log("[DIAG-1] Timestamp check:");
    error_log("  - Received timestamp: " . ($timestamp ?? 'null'));
    error_log("  - Current server time: " . time());
    if ($timestamp) {
        $age = abs(time() - $timestamp);
        error_log("  - Age: {$age} seconds");
        error_log("  - Max allowed: {$maxAgeSeconds} seconds");
        if ($age > $maxAgeSeconds) {
            error_log("  - RESULT: FAILED (timestamp too old)");
            return false;
        } else {
            error_log("  - RESULT: PASSED");
        }
    }
    
    // Diagnostic 2: Public key format
    error_log("\n[DIAG-2] Public key format check:");
    error_log("  - Raw public key length: " . strlen($publicKey));
    error_log("  - First 50 chars: " . substr($publicKey, 0, 50));
    error_log("  - Contains BEGIN marker: " . (strpos($publicKey, '-----BEGIN PUBLIC KEY-----') !== false ? 'YES' : 'NO'));
    error_log("  - Contains END marker: " . (strpos($publicKey, '-----END PUBLIC KEY-----') !== false ? 'YES' : 'NO'));
    
    // Try to load the public key
    $testKey = openssl_pkey_get_public($publicKey);
    if ($testKey === false) {
        error_log("  - RESULT: FAILED - Cannot load public key");
        error_log("  - OpenSSL error: " . openssl_error_string());
        
        // Try to fix formatting
        if (strpos($publicKey, '-----BEGIN') === false) {
            $fixedKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($publicKey, 64, "\n") . "-----END PUBLIC KEY-----";
            error_log("  - Attempting to fix with PEM headers");
            $testKey = openssl_pkey_get_public($fixedKey);
            if ($testKey !== false) {
                error_log("  - ✓ Fixed key works!");
                $publicKey = $fixedKey;
            } else {
                error_log("  - Still failing after fix");
                return false;
            }
        }
    } else {
        error_log("  - RESULT: PASSED - Public key loads successfully");
        openssl_free_key($testKey);
    }
    
    // Diagnostic 3: Signature format
    error_log("\n[DIAG-3] Signature format check:");
    error_log("  - Raw signature length: " . strlen($signature));
    error_log("  - First 50 chars: " . substr($signature, 0, 50));
    
    $decodedSig = base64_decode($signature);
    if ($decodedSig === false) {
        error_log("  - RESULT: FAILED - Cannot base64 decode signature");
        return false;
    }
    error_log("  - Decoded signature length: " . strlen($decodedSig));
    error_log("  - RESULT: PASSED - Signature decodes successfully");
    
    // Diagnostic 4: Payload structure analysis
    error_log("\n[DIAG-4] Payload structure:");
    error_log("  - Original payload keys: " . implode(', ', array_keys($payload)));
    error_log("  - Payload contains 'timestamp': " . (isset($payload['timestamp']) ? 'YES (value: ' . $payload['timestamp'] . ')' : 'NO'));
    error_log("  - Payload contains '_timestamp': " . (isset($payload['_timestamp']) ? 'YES' : 'NO'));
    
    // Create payload copy for testing
    $testPayload = $payload;
    unset($testPayload['signature']);
    error_log("  - Keys after removing 'signature': " . implode(', ', array_keys($testPayload)));
    
    // Diagnostic 5: Test all possible payload variations
    error_log("\n[DIAG-5] Testing payload variations:");
    
    $variations = [];
    $publicKeyResource = openssl_pkey_get_public($publicKey);
    
    // Variation A: Original payload with timestamp (no modification)
    $variations['A) Original payload (timestamp as is)'] = $testPayload;
    
    // Variation B: Original payload without timestamp
    $noTimestamp = $testPayload;
    unset($noTimestamp['timestamp']);
    $variations['B) Without timestamp field'] = $noTimestamp;
    
    // Variation C: Original payload with _timestamp instead
    $withUnderscore = $testPayload;
    if (isset($withUnderscore['timestamp'])) {
        $withUnderscore['_timestamp'] = $withUnderscore['timestamp'];
        unset($withUnderscore['timestamp']);
    }
    $variations['C) With _timestamp instead of timestamp'] = $withUnderscore;
    
    // Variation D: Only core fields (minimal)
    $variations['D) Core fields only'] = [
        'action' => $testPayload['action'] ?? null,
        'reference' => $testPayload['reference'] ?? null,
        'amount' => $testPayload['amount'] ?? null,
        'asset_id' => $testPayload['asset_id'] ?? null,
        'timestamp' => $testPayload['timestamp'] ?? null,
    ];
    
    // Variation E: String values instead of integers
    $stringsPayload = [];
    foreach ($testPayload as $key => $value) {
        if (is_int($value) || is_float($value)) {
            $stringsPayload[$key] = (string)$value;
        } else {
            $stringsPayload[$key] = $value;
        }
    }
    $variations['E) String-typed numbers'] = $stringsPayload;
    
    // Test each variation with and without sorting
    foreach ($variations as $varName => $varPayload) {
        // Remove null values
        $varPayload = array_filter($varPayload, function($v) { return $v !== null; });
        
        // Test with sorting
        $sorted = $varPayload;
        ksort($sorted);
        $sortedJson = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $resultSorted = openssl_verify($sortedJson, $decodedSig, $publicKeyResource, OPENSSL_ALGO_SHA256);
        
        error_log("\n  {$varName}:");
        error_log("    Sorted keys: " . ($resultSorted === 1 ? "✓ VALID" : ($resultSorted === 0 ? "✗ INVALID" : "ERROR")));
        if ($resultSorted === 1) {
            error_log("    ✓✓✓ MATCH FOUND! ✓✓✓");
            error_log("    Sorted JSON: " . $sortedJson);
            error_log("========== RSA SIGNATURE DIAGNOSTIC END ==========\n");
            openssl_free_key($publicKeyResource);
            return true;
        }
        
        // Test without sorting
        $unsortedJson = json_encode($varPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $resultUnsorted = openssl_verify($unsortedJson, $decodedSig, $publicKeyResource, OPENSSL_ALGO_SHA256);
        
        error_log("    Unsorted keys: " . ($resultUnsorted === 1 ? "✓ VALID" : ($resultUnsorted === 0 ? "✗ INVALID" : "ERROR")));
        if ($resultUnsorted === 1) {
            error_log("    ✓✓✓ MATCH FOUND! ✓✓✓");
            error_log("    Unsorted JSON: " . $unsortedJson);
            error_log("========== RSA SIGNATURE DIAGNOSTIC END ==========\n");
            openssl_free_key($publicKeyResource);
            return true;
        }
    }
    
    // Diagnostic 6: If all fail, show what VouchMorph likely signed vs what we're testing
    error_log("\n[DIAG-6] All variations failed. Showing what we tried:");
    
    // Get raw input for comparison
    $rawInput = file_get_contents('php://input');
    $rawDecoded = json_decode($rawInput, true);
    if ($rawDecoded && isset($rawDecoded['signature'])) {
        unset($rawDecoded['signature']);
        $rawJson = json_encode($rawDecoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log("  Raw input (as received from VouchMorph): " . $rawJson);
    }
    
    error_log("\n  Our best attempt (sorted, original payload):");
    $bestPayload = $testPayload;
    ksort($bestPayload);
    $bestJson = json_encode($bestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    error_log("  " . $bestJson);
    
    error_log("\n  Signature we're verifying: " . $signature);
    error_log("  Decoded signature (hex first 32 bytes): " . bin2hex(substr($decodedSig, 0, 32)));
    
    openssl_free_key($publicKeyResource);
    error_log("\n========== RSA SIGNATURE DIAGNOSTIC END (ALL FAILED) ==========\n");
    
    return false;
}
// Add this temporary function to crypto.php

function test_key_mismatch($payload, $signature, $publicKey)
{
    error_log("\n========== KEY MISMATCH DIAGNOSTIC ==========");
    
    // Load the public key
    $key = openssl_pkey_get_public($publicKey);
    if ($key === false) {
        error_log("ERROR: Cannot load public key");
        return;
    }
    
    // Get key details
    $keyDetails = openssl_pkey_get_details($key);
    error_log("Public key bits: " . ($keyDetails['bits'] ?? 'unknown'));
    error_log("Public key type: " . ($keyDetails['type'] ?? 'unknown'));
    
    // Try to verify with raw signature (no payload manipulation)
    $testPayload = $payload;
    unset($testPayload['signature']);
    
    // The EXACT method VouchMorph likely uses
    $exactPayloadJson = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    error_log("Payload JSON (no sorting): " . $exactPayloadJson);
    
    $decodedSig = base64_decode($signature);
    $result = openssl_verify($exactPayloadJson, $decodedSig, $key, OPENSSL_ALGO_SHA256);
    
    if ($result === 1) {
        error_log("✓ SIGNATURE VERIFIES!");
        error_log("The keys DO match!");
    } else {
        error_log("✗ SIGNATURE DOES NOT VERIFY");
        error_log("This indicates the private key used to sign");
        error_log("does NOT match the public key you have.");
        error_log("");
        error_log("SOLUTION:");
        error_log("1. Get the correct public key from VouchMorph team");
        error_log("2. Or have VouchMorph use the private key that matches");
        error_log("   your current public key");
        error_log("");
        error_log("Current public key fingerprint:");
        $fingerprint = hash('sha256', $publicKey);
        error_log("SHA-256: " . $fingerprint);
    }
    
    openssl_free_key($key);
    error_log("========== END KEY MISMATCH DIAGNOSTIC ==========\n");
}
