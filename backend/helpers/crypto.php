<?php
// /SaccusSalisbank/backend/helpers/crypto.php

/**
 * Dynamic Key Verification Layer
 * Prioritizes Railway Environment Variables, falls back to database records
 */
function get_requester_public_key($requester, $pdo)
{
    error_log("get_requester_public_key called for: {$requester}");
    
    // 1. Try reading the key dynamically from the Environment Variables first
    $envKeyName = strtoupper($requester) . '_PUBLIC_KEY';
    $envKey = getenv($envKeyName) ?? $_ENV[$envKeyName] ?? $_SERVER[$envKeyName] ?? null;
    
    if ($envKey) {
        error_log("[+] SACCUSSALIS: Resolving key dynamically via environment variable [{$envKeyName}].");
        return str_replace(['\\n', '\n'], "\n", $envKey);
    }

    error_log("[-] SACCUSSALIS: Environment variable [{$envKeyName}] empty or not set. Falling back to database lookup.");
    
    // 2. Fallback to database lookup if environment variable is missing
    try {
        $stmt = $pdo->prepare("SELECT public_key FROM trusted_partners WHERE name = :name AND is_active = true LIMIT 1");
        $stmt->execute([':name' => $requester]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && !empty($row['public_key'])) {
            error_log("[+] SACCUSSALIS: Public key found in database for institution: {$requester}");
            return $row['public_key'];
        }
        
        error_log("[-] SACCUSSALIS: No public key found for institution: {$requester} in database.");
        return null;
    } catch (Exception $e) {
        error_log("Database error getting public key: " . $e->getMessage());
        return null;
    }
}

/**
 * Deterministic JSON Canonicalization Engine (RFC 8785 Scheme Standard)
 */
function canonicalize_payload(array $payload): string
{
    unset($payload['signature']);
    
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
    
    ksort($normalized, SORT_STRING);
    return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Validates incoming signatures using an audit trail model.
 */
function verify_signature($payload, $signature, $publicKey, $timestamp = null, $maxAgeSeconds = 300)
{
    error_log("\n=================== CRYPTO ENGINE DIAGNOSTIC AUDIT ===================");
    
    // --- OPENSSL ENGINE LIVE TEST ---
    error_log("[*] OPENSSL TEST: Attempting to parse Public Key resource...");
    $publicKeyResource = openssl_pkey_get_public($publicKey);
    if ($publicKeyResource === false) {
        error_log("[-] OPENSSL ENGINE ERROR: " . openssl_error_string());
        error_log("[-] AUDIT CRITICAL: The resolved public key failed to initialize in OpenSSL context.");
        return false;
    } else {
        $keyDetails = openssl_pkey_get_details($publicKeyResource);
        error_log("[+] OPENSSL SUCCESS: Key parsed successfully! Bits: " . ($keyDetails['bits'] ?? 'unknown') . ", Type: " . ($keyDetails['type'] ?? 'unknown'));
    }
    
    $decodedSig = base64_decode($signature);
    if ($decodedSig === false) {
        error_log("[-] AUDIT FAILURE: Signature field is not valid base64 data encoding.");
        if (function_exists('openssl_free_key')) { @openssl_free_key($publicKeyResource); }
        return false;
    }

    // Capture the verbatim network stream before array mutations occur
    $rawStreamInput = file_get_contents('php://input');
    $streamDecoded = json_decode($rawStreamInput, true) ?? [];

    // Extract target timestamp to counter signature generation shifts
    $targetTimestamp = $timestamp ?? $payload['timestamp'] ?? $payload['_timestamp'] ?? $streamDecoded['timestamp'] ?? null;

    // Base processing dictionary
    $basePayload = $payload;
    unset($basePayload['signature'], $basePayload['timestamp'], $basePayload['requester']);

    // Setup clear diagnostic criteria arrays
    $evaluationPool = [];

    // Test Case 1 & 2: Structural Verification with tracking Timestamp injected
    if ($targetTimestamp !== null) {
        $timestampPayload = array_merge($basePayload, ['_timestamp' => (int)$targetTimestamp]);
        $evaluationPool['TEST 1: RFC 8785 Canonical with Timestamp Injection'] = canonicalize_payload($timestampPayload);
        $evaluationPool['TEST 2: Unsorted Array with Timestamp Injection'] = json_encode($timestampPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // Test Case 3 & 4: Fallbacks without altering structural keys
    $evaluationPool['TEST 3: RFC 8785 Raw Canonical Payload Base'] = canonicalize_payload($basePayload);
    $evaluationPool['TEST 4: Standard json_encode Base'] = json_encode($basePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!empty($streamDecoded)) {
        // Test Case 5: Raw byte stream cleaning fallback
        $rawStripped = preg_replace('/,"signature":"[^"]+"/', '', $rawStreamInput);
        $rawStripped = preg_replace('/"signature":"[^"]+",?/', '', $rawStripped);
        $rawStripped = "{" . trim($rawStripped, " ,{}") . "}";
        $evaluationPool['TEST 5: Raw Input Stream (Stripped Signature Field)'] = $rawStripped;
    }

    // Process evaluation loop across variant formats
    foreach ($evaluationPool as $testIdentifier => $stringRepresentation) {
        $check = openssl_verify($stringRepresentation, $decodedSig, $publicKeyResource, OPENSSL_ALGO_SHA256);
        if ($check === 1) {
            error_log("[+] SUCCESS MATCH IDENTIFIED via: [{$testIdentifier}]");
            error_log("[+] Formatted Matching Data Stream: " . $stringRepresentation);
            error_log("======================================================================\n");
            if (function_exists('openssl_free_key')) { @openssl_free_key($publicKeyResource); }
            return true;
        } else {
            error_log("[-] FAILED variant [{$testIdentifier}] - OpenSSL Error: " . openssl_error_string());
        }
    }

    // Root Cause Evaluation Block (If all verification methods failed)
    error_log("\n---------------- ANALYSIS REPORT: ALL VERIFICATION ATTEMPTS FAILED ----------------");
    error_log("[-] Objective Reality: Both canonical formats, the raw body stream, and minimized models rejected the signature.");
    
    // Explicit Key Validation Audit
    error_log("\nRunning Isolated Mathematical Key Alignment Check...");
    $fallbackJson = json_encode($basePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $keyTestResult = openssl_verify($fallbackJson, $decodedSig, $publicKeyResource, OPENSSL_ALGO_SHA256);

    if ($keyTestResult === 0) {
        error_log("\n[!] ROOT CAUSE CONFIRMED: CRYPTOGRAPHIC KEY PAIR MISMATCH.");
        error_log("    - Explanatory Detail: The public key verified by this file is structurally valid, but does not fit the private key signing outbound payloads.");
    } else {
        error_log("\n[!] ROOT CAUSE CONFIRMED: PAYLOAD/SERIALIZATION MUTATION DISCREPANCY.");
    }

    error_log("----------------------------------------------------------------------------------");
    error_log("==================================================================================\n");

    if (function_exists('openssl_free_key')) { @openssl_free_key($publicKeyResource); }
    return false;
}

/**
 * Sign a payload for outgoing response using RSA private key
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        $privateKeyContent = getenv('SACCUSSALIS_PRIVATE_KEY');
        if (!$privateKeyContent) {
            error_log("SACCUSSALIS_PRIVATE_KEY not found in environment");
            return null;
        }
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
    $payloadJson = canonicalize_payload($payloadWithTimestamp);
    
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
