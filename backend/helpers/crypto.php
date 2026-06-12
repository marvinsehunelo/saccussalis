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
 * Canonicalizes an array payload using deterministic formatting rules.
 * Forces all scalar properties to strings to stop deep data type drift.
 */
function canonicalize_payload(array $payload): string
{
    // 1. Remove systemic transit fields
    unset($payload['signature']);
    
    // 2. Normalize every parameter cleanly to remove deep type variants (e.g., 100 vs "100")
    $normalized = [];
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            ksort($value, SORT_STRING);
            $normalized[$key] = array_map(function($v) {
                return (is_scalar($v) && !is_bool($v)) ? (string)$v : $v;
            }, $value);
        } else {
            $normalized[$key] = (is_scalar($value) && !is_bool($value)) ? (string)$value : $value;
        }
    }
    
    // 3. Alphabetize keys structurally
    ksort($normalized, SORT_STRING);
    
    // 4. Force uniform unescaped slash encoding
    return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
    $payloadWithTimestamp = array_merge($payload, ['_timestamp' => (string)$timestamp]);
    
    // Canonicalize data string structure natively
    $payloadJson = canonicalize_payload($payloadWithTimestamp);
    
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

/**
 * Validates the incoming signature using a strategic multi-variant normalization fallback.
 */
function verify_signature($payload, $signature, $publicKey, $timestamp = null, $maxAgeSeconds = 300)
{
    error_log("\n========== RSA SIGNATURE DIAGNOSTIC START ==========");
    
    // Diagnostic 1: Timestamp validation
    if ($timestamp) {
        $age = abs(time() - $timestamp);
        if ($age > $maxAgeSeconds) {
            error_log("[DIAG-1] RESULT: FAILED (timestamp expired by {$age}s)");
            return false;
        }
    }
    
    // Diagnostic 2: Format and fix Public Key if necessary
    $publicKey = trim($publicKey);
    if (strpos($publicKey, '-----BEGIN') === false) {
        $cleanBody = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', ' ', "\r", "\n"], '', $publicKey);
        $publicKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($cleanBody, 64, "\n") . "-----END PUBLIC KEY-----";
    }
    
    $publicKeyResource = openssl_pkey_get_public($publicKey);
    if ($publicKeyResource === false) {
        error_log("[DIAG-2] RESULT: FAILED - Cannot parse public key asset layout");
        return false;
    }
    
    $decodedSig = base64_decode($signature);
    if ($decodedSig === false) {
        error_log("[DIAG-3] RESULT: FAILED - Cannot base64 decode signature string");
        openssl_free_key($publicKeyResource);
        return false;
    }
    
    // Diagnostic 4: Test Processing Combinations
    $testPayloads = [];
    $rawInput = file_get_contents('php://input');
    $streamDecoded = json_decode($rawInput, true) ?? [];

    // Strategy Variant A: Full Canonical RFC 8785 Serialization (Primary Strategy)
    $testPayloads['A) Strict Canonicalization (RFC 8785)'] = canonicalize_payload($payload);

    // Strategy Variant B: Pure Unsorted Array Structure
    $pureUnsorted = $payload;
    unset($pureUnsorted['signature']);
    $testPayloads['B) Natural Passed Payload (Unsorted)'] = json_encode($pureUnsorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!empty($streamDecoded)) {
        // Strategy Variant C: Byte-perfect raw payload stream manipulation
        $rawStripped = preg_replace('/,"signature":"[^"]+"/', '', $rawInput);
        $rawStripped = preg_replace('/"signature":"[^"]+",?/', '', $rawStripped);
        $rawStripped = "{" . trim($rawStripped, " ,{}") . "}";
        $testPayloads['C) Raw Stream Payload (Regex Stripped)'] = $rawStripped;

        // Strategy Variant D: Core transaction metrics footprint filter
        $coreData = [
            "action"      => $streamDecoded['action'] ?? null,
            "reference"   => $streamDecoded['reference'] ?? null,
            "amount"      => isset($streamDecoded['amount']) ? (string)$streamDecoded['amount'] : null,
            "currency"    => $streamDecoded['currency'] ?? null,
            "timestamp"   => isset($streamDecoded['timestamp']) ? (string)$streamDecoded['timestamp'] : null,
            "requester"   => $streamDecoded['requester'] ?? null
        ];
        ksort($coreData);
        $testPayloads['D) Core Financial Ledger Attributes Only'] = json_encode($coreData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // Process checks over engine permutations
    foreach ($testPayloads as $strategyName => $jsonStringContent) {
        $result = openssl_verify($jsonStringContent, $decodedSig, $publicKeyResource, OPENSSL_ALGO_SHA256);
        if ($result === 1) {
            error_log("  ✓ MATCH FOUND via: [{$strategyName}]");
            error_log("========== RSA SIGNATURE DIAGNOSTIC END (SUCCESS) ==========\n");
            openssl_free_key($publicKeyResource);
            return true;
        }
    }
    
    // Diagnostic 5: Failure analysis fingerprinting
    error_log("\n[DIAG-5] All variations failed. Fingerprint data matching properties:");
    $keyDetails = openssl_pkey_get_details($publicKeyResource);
    error_log("  - Key Bit Length: " . ($keyDetails['bits'] ?? 'Unknown'));
    error_log("  - Signature Byte Length: " . strlen($decodedSig));
    error_log("  - Expected Content Sample: " . canonicalize_payload($payload));
    
    openssl_free_key($publicKeyResource);
    error_log("========== RSA SIGNATURE DIAGNOSTIC END (ALL FAILED) ==========\n");
    return false;
}

/**
 * Diagnostic helper to determine if your environment public key matches the incoming key format signature
 */
function test_key_mismatch($payload, $signature, $publicKey)
{
    error_log("\n========== KEY MISMATCH DIAGNOSTIC ==========");
    
    $key = openssl_pkey_get_public($publicKey);
    if ($key === false) {
        error_log("ERROR: Cannot load public key");
        return;
    }
    
    $testPayload = $payload;
    unset($testPayload['signature']);
    $exactPayloadJson = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    $decodedSig = base64_decode($signature);
    $result = openssl_verify($exactPayloadJson, $decodedSig, $key, OPENSSL_ALGO_SHA256);
    
    if ($result === 1) {
        error_log("✓ Cryptographic Key Pair Alignment Verified Successfully.");
    } else {
        error_log("✗ SIGNATURE KEY PAIR MISMATCH DETECTED.");
        error_log("The public key configured inside your environment does not map back to the private key processing signatures inside VouchMorph.");
    }
    openssl_free_key($key);
}
