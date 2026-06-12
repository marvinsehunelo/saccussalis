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

<?php
// /SaccusSalisbank/backend/helpers/crypto.php

function verify_signature($payload, $signature, $publicKey, $timestamp = null, $maxAgeSeconds = 300)
{
    // Reject old messages (prevent replay attacks)
    if ($timestamp && abs(time() - $timestamp) > $maxAgeSeconds) {
        error_log("Signature rejected: timestamp too old (age: " . abs(time() - $timestamp) . "s)");
        return false;
    }
    
    // IMPORTANT: The payload already contains ALL fields including 'timestamp'
    // We should NOT add '_timestamp' or modify the payload in any way
    // Just verify against the payload AS-IS
    
    // Ensure the payload has the timestamp field (VouchMorph uses 'timestamp', not '_timestamp')
    if (!isset($payload['timestamp']) && $timestamp) {
        // Only add if missing (shouldn't happen with VouchMorph)
        $payload['timestamp'] = $timestamp;
    }
    
    // Remove signature field if it somehow got into the payload
    unset($payload['signature']);
    
    // Sort keys alphabetically (important! VouchMorph likely does this)
    ksort($payload);
    
    // Create JSON string with the same formatting as VouchMorph
    // Use JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE to match common practice
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    error_log("VERIFYING SIGNATURE WITH PAYLOAD: " . $payloadJson);
    error_log("SIGNATURE (first 50 chars): " . substr($signature, 0, 50));
    
    // Decode signature from base64
    $decodedSignature = base64_decode($signature);
    if ($decodedSignature === false) {
        error_log("Failed to decode signature from base64");
        return false;
    }
    
    // Load public key
    $key = openssl_pkey_get_public($publicKey);
    if ($key === false) {
        error_log("Failed to load public key: " . openssl_error_string());
        return false;
    }
    
    // Verify signature
    $result = openssl_verify($payloadJson, $decodedSignature, $key, OPENSSL_ALGO_SHA256);
    
    // Free key resource
    openssl_free_key($key);
    
    if ($result === 1) {
        error_log("✓ SIGNATURE VALID ✓");
        return true;
    } elseif ($result === 0) {
        error_log("✗ SIGNATURE INVALID ✗");
        
        // Diagnostic: Try without ksort to see if ordering is the issue
        $payloadUnsorted = $payload;
        unset($payloadUnsorted['signature']);
        $payloadUnsortedJson = json_encode($payloadUnsorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result2 = openssl_verify($payloadUnsortedJson, $decodedSignature, $key, OPENSSL_ALGO_SHA256);
        
        if ($result2 === 1) {
            error_log("  → Actually valid WITHOUT sorting! VouchMorph may not sort keys.");
            error_log("  → Unsorted payload: " . $payloadUnsortedJson);
            return true;
        }
        
        // Diagnostic: Try with original raw input
        $rawInput = file_get_contents('php://input');
        $rawDecoded = json_decode($rawInput, true);
        if ($rawDecoded && isset($rawDecoded['signature'])) {
            unset($rawDecoded['signature']);
            ksort($rawDecoded);
            $rawJson = json_encode($rawDecoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $result3 = openssl_verify($rawJson, $decodedSignature, $key, OPENSSL_ALGO_SHA256);
            
            if ($result3 === 1) {
                error_log("  → Actually valid with raw input (sorted)!");
                error_log("  → Raw payload: " . $rawJson);
                return true;
            }
            
            // Try raw input without sorting
            $rawUnsorted = $rawDecoded;
            $rawUnsortedJson = json_encode($rawUnsorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $result4 = openssl_verify($rawUnsortedJson, $decodedSignature, $key, OPENSSL_ALGO_SHA256);
            
            if ($result4 === 1) {
                error_log("  → Actually valid with raw input (unsorted)!");
                error_log("  → Raw unsorted payload: " . $rawUnsortedJson);
                return true;
            }
        }
        
        error_log("  → All verification attempts failed");
        return false;
    } else {
        error_log("OpenSSL verification error: " . openssl_error_string());
        return false;
    }
}
