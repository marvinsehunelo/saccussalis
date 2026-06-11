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
    $payloadJson = json_encode($payloadWithTimestamp);
    
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
 * Verify incoming RSA signature from requester
 */
function verify_signature($payload, $signature, $publicKey, $timestamp = null, $maxAgeSeconds = 300)
{
    // Reject old messages (prevent replay attacks)
    if ($timestamp && abs(time() - $timestamp) > $maxAgeSeconds) {
        error_log("Signature rejected: timestamp too old (age: " . abs(time() - $timestamp) . "s)");
        return false;
    }
    
    // Prepare the payload that was signed
    if ($timestamp) {
        $payloadToVerify = array_merge($payload, ['_timestamp' => $timestamp]);
    } else {
        $payloadToVerify = $payload;
    }
    
    $payloadJson = json_encode($payloadToVerify);
    
    // Verify RSA signature using openssl
    $result = openssl_verify(
        $payloadJson,
        base64_decode($signature),
        $publicKey,
        OPENSSL_ALGO_SHA256
    );
    
    $isValid = ($result === 1);
    error_log("RSA Signature verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    
    if ($result === -1) {
        error_log("Signature verification error: " . openssl_error_string());
    }
    
    return $isValid;
}
