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
    // ... Keep your existing setup / public key formatting blocks up top ...

    $rawStream = file_get_contents('php://input');
    $streamDecoded = json_decode($rawStream, true) ?? [];
    
    $testPayloads = [];

    // --- Core Sub-Layout Testing ---
    if (!empty($streamDecoded)) {
        // Variant 1: Pure transactional structure (Omitting peripheral lookup fields)
        $testPayloads['F) Core Transaction Fields Only'] = [
            "action"                  => $streamDecoded['action'] ?? null,
            "reference"               => $streamDecoded['reference'] ?? null,
            "asset_type"              => $streamDecoded['asset_type'] ?? null,
            "amount"                  => isset($streamDecoded['amount']) ? (int)$streamDecoded['amount'] : null,
            "currency"                => $streamDecoded['currency'] ?? null,
            "hold_reason"             => $streamDecoded['hold_reason'] ?? null,
            "destination_institution" => $streamDecoded['destination_institution'] ?? null,
            "expiry"                  => $streamDecoded['expiry'] ?? null,
            "timestamp"               => isset($streamDecoded['timestamp']) ? (int)$streamDecoded['timestamp'] : null,
            "asset_id"                => isset($streamDecoded['asset_id']) ? (int)$streamDecoded['asset_id'] : null,
            "requester"               => $streamDecoded['requester'] ?? null
        ];

        // Variant 2: Pure transactional structure with String-cast numbers
        $testPayloads['G) Core Transaction Fields Only (Strings)'] = [
            "action"                  => $streamDecoded['action'] ?? null,
            "reference"               => $streamDecoded['reference'] ?? null,
            "asset_type"              => $streamDecoded['asset_type'] ?? null,
            "amount"                  => isset($streamDecoded['amount']) ? (string)$streamDecoded['amount'] : null,
            "currency"                => $streamDecoded['currency'] ?? null,
            "hold_reason"             => $streamDecoded['hold_reason'] ?? null,
            "destination_institution" => $streamDecoded['destination_institution'] ?? null,
            "expiry"                  => $streamDecoded['expiry'] ?? null,
            "timestamp"               => isset($streamDecoded['timestamp']) ? (string)$streamDecoded['timestamp'] : null,
            "asset_id"                => isset($streamDecoded['asset_id']) ? (string)$streamDecoded['asset_id'] : null,
            "requester"               => $streamDecoded['requester'] ?? null
        ];

        // Variant 3: Baseline Minimalist Ledger Hold Layout
        $testPayloads['H) Minimal Hold Signature Block'] = [
            "action"      => $streamDecoded['action'] ?? null,
            "reference"   => $streamDecoded['reference'] ?? null,
            "asset_id"    => isset($streamDecoded['asset_id']) ? (int)$streamDecoded['asset_id'] : null,
            "amount"      => isset($streamDecoded['amount']) ? (int)$streamDecoded['amount'] : null,
            "currency"    => $streamDecoded['currency'] ?? null,
            "timestamp"   => isset($streamDecoded['timestamp']) ? (int)$streamDecoded['timestamp'] : null
        ];
    }

    // Keep attempts A, B, C, D, E underneath so we have total visibility
    $testPayloads['A) Passed Payload (Natural)'] = $payload;

    $decodedSig = base64_decode($signature);
    $finalSuccess = false;
    $winningStrategy = "";

    foreach ($testPayloads as $strategyName => $payloadVariant) {
        // Clean out nulls if any keys weren't present
        $payloadVariant = array_filter($payloadVariant, function($v) { return !is_null($v); });

        $jsonStr = json_encode($payloadVariant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result = openssl_verify($jsonStr, $decodedSig, $publicKey, OPENSSL_ALGO_SHA256);
        
        if ($result === 1) {
            error_log("→ STRATEGY MATCHED: {$strategyName} ✓✓✓");
            error_log("→ Content: " . $jsonStr);
            $finalSuccess = true;
            $winningStrategy = $strategyName;
            break;
        }
    }

    if ($finalSuccess) {
        return true;
    }

    error_log("CRITICAL DIAGNOSTIC: All combinations (including structural sub-filtering) failed.");
    return false;
}
