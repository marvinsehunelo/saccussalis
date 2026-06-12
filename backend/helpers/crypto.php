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
    error_log("=================================================");
    error_log("=== CRYPTO.PHP SIGNATURE DIAGNOSTIC ACTIVE ===");
    error_log("=================================================");

    // 1. Check Replay Attack Window
    if ($timestamp && abs(time() - $timestamp) > $maxAgeSeconds) {
        error_log("DIAGNOSTIC: Rejected - timestamp expired (age: " . abs(time() - $timestamp) . "s)");
        return false;
    }
    
    // 2. Format Public Key
    $publicKey = trim($publicKey);
    if (strpos($publicKey, "\n") === false) {
        $cleanBody = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', ' ', "\r", "\n"], '', $publicKey);
        $chunks = str_split($cleanBody, 64);
        $publicKey = "-----BEGIN PUBLIC KEY-----\n" . implode("\n", $chunks) . "\n-----END PUBLIC KEY-----";
    }

    // 3. Inspect Raw Input Stream directly from the network wire
    $rawStream = file_get_contents('php://input');
    error_log("DIAGNOSTIC - RAW HTTP STREAM: " . $rawStream);

    $streamDecoded = json_decode($rawStream, true) ?? [];
    
    // Build combinations to test systematically
    $testPayloads = [];

    // Combination A: Raw parsed input payload exactly as passed into function
    $testPayloads['A) Passed Payload (Natural)'] = $payload;

    // Combination B: Passed payload with all scalar values normalized to strings
    $normPayload = [];
    foreach ($payload as $k => $v) {
        $normPayload[$k] = (is_scalar($v) && !is_bool($v)) ? (string)$v : $v;
    }
    $testPayloads['B) Passed Payload (String Normalized)'] = $normPayload;

    // Combination C: Alphabetized version of the passed payload
    $alphaPayload = $payload;
    ksort($alphaPayload);
    $testPayloads['C) Passed Payload (Alphabetical ksort)'] = $alphaPayload;

    // Combination D: Raw Network Input Stream minus the signature key (Most likely candidate)
    if (!empty($streamDecoded)) {
        $streamMinusSig = $streamDecoded;
        unset($streamMinusSig['signature']);
        $testPayloads['D) Raw Stream (Minus Signature Key)'] = $streamMinusSig;
        
        // Combination E: Alphabetized Network Input Stream minus signature
        $alphaStream = $streamMinusSig;
        ksort($alphaStream);
        $testPayloads['E) Raw Stream (Alphabetical ksort, Minus Signature)'] = $alphaStream;
    }

    // 4. Run calculations across all variants to find the valid structure
    $decodedSig = base64_decode($signature);
    $finalSuccess = false;
    $winningStrategy = "";

    foreach ($testPayloads as $strategyName => $payloadVariant) {
        // Run verification test
        $jsonStr = json_encode($payloadVariant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $result = openssl_verify($jsonStr, $decodedSig, $publicKey, OPENSSL_ALGO_SHA256);
        $checkStatus = ($result === 1) ? "VALID ✓✓✓" : "INVALID ✗";
        
        error_log("STRATEGY TEST -> {$strategyName}");
        error_log("  ↳ Content: " . $jsonStr);
        error_log("  ↳ Status:  " . $checkStatus);

        if ($result === 1 && !$finalSuccess) {
            $finalSuccess = true;
            $winningStrategy = $strategyName;
        }
    }

    if ($finalSuccess) {
        error_log("SUCCESS: Winning match found via [{$winningStrategy}]!");
        return true;
    }

    error_log("CRITICAL: All signature combinations failed. Inspect the layout matching patterns above.");
    error_log("=================================================");
    return false;
}
