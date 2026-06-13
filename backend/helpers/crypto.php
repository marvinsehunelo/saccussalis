<?php
// backend/helpers/crypto.php

require_once __DIR__ . '/CertificateManager.php';

/**
 * Get requester public key (now extracts from certificate)
 */
function get_requester_public_key($requester, $pdo)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['certificate'])) {
        $certManager = new CertificateManager();
        $publicKey = $certManager->extractPublicKeyFromCert($input['certificate']);
        if ($publicKey) {
            error_log("Extracted public key from certificate for {$requester}");
            return $publicKey;
        }
    }
    
    // Legacy fallback
    $envKeyName = strtoupper($requester) . '_PUBLIC_KEY';
    $envKey = getenv($envKeyName);
    if ($envKey) {
        return str_replace(['\\n', '\n'], "\n", $envKey);
    }
    
    return null;
}

/**
 * JSON canonicalization - used for VERIFYING signatures
 * This matches what VOUCHMORPH uses to verify
 */
function canonicalize_payload(array $payload): string
{
    // For VERIFYING incoming requests - remove signature fields
    unset($payload['signature']);
    unset($payload['certificate']);
    // Keep requester for verification
    ksort($payload);
    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Sign payload for outgoing response
 * CRITICAL: Must match exactly what VOUCHMORPH expects to verify
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        $privateKeyContent = getenv('SACCUSSALIS_PRIVATE_KEY_CONTENT');
        if (!$privateKeyContent) {
            error_log("SACCUSSALIS_PRIVATE_KEY_CONTENT not found");
            return null;
        }
        $privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);
        
        if (strpos($privateKeyContent, '-----BEGIN PRIVATE KEY-----') === false) {
            $privateKeyContent = "-----BEGIN PRIVATE KEY-----\n" . 
                                 chunk_split(trim($privateKeyContent), 64, "\n") . 
                                 "-----END PRIVATE KEY-----\n";
        }
        
        $privateKey = openssl_pkey_get_private($privateKeyContent);
        if (!$privateKey) {
            error_log("Failed to load private key");
            return null;
        }
    }
    
    // CRITICAL: VOUCHMORPH expects timestamp to be included in the signed payload
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
    
    // CRITICAL: VOUCHMORPH uses ksort before verification
    ksort($payloadWithTimestamp);
    
    // CRITICAL: Must use EXACT same JSON encoding as VOUCHMORPH
    $payloadJson = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    error_log("SACCUSSALIS: Signing payload: " . $payloadJson);
    
    $signature = '';
    $signResult = openssl_sign($payloadJson, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    
    if (!$signResult) {
        error_log("SACCUSSALIS: Failed to sign payload - " . openssl_error_string());
        return null;
    }
    
    $encodedSignature = base64_encode($signature);
    error_log("SACCUSSALIS: Generated signature length: " . strlen($encodedSignature));
    
    return [
        'signature' => $encodedSignature,
        'timestamp' => $timestamp
    ];
}

/**
 * Send signed response with certificate
 * This is what hold.php calls
 */
function send_signed_response($payload, $httpCode = 200)
{
    // Sign the payload
    $signed = sign_payload($payload);
    
    if (!$signed) {
        error_log("SACCUSSALIS: Failed to sign response - sending unsigned");
        http_response_code($httpCode);
        echo json_encode($payload);
        exit;
    }
    
    // Get certificate
    $certContent = getenv('SACCUSSALIS_CERT_CONTENT');
    if ($certContent) {
        $certContent = str_replace(['\\n', '\n'], "\n", $certContent);
        error_log("SACCUSSALIS: Certificate loaded, length: " . strlen($certContent));
    } else {
        error_log("SACCUSSALIS: WARNING - No certificate content found in environment");
    }
    
    // Build response exactly as VOUCHMORPH expects
    // VOUCHMORPH will remove 'signature' and 'certificate' for verification
    // but will keep 'requester' and 'timestamp'
    $response = array_merge($payload, [
        'signature' => $signed['signature'],
        'timestamp' => $signed['timestamp'],
        'certificate' => $certContent
    ]);
    
    // Log the response structure (without full cert for brevity)
    $logResponse = $response;
    if (isset($logResponse['certificate'])) {
        $logResponse['certificate'] = '[CERTIFICATE LENGTH: ' . strlen($logResponse['certificate']) . ']';
    }
    error_log("SACCUSSALIS: Sending signed response: " . json_encode($logResponse));
    
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}

/**
 * Verify a signed response (for SACCUSSALIS to verify VOUCHMORPH responses)
 * This matches what VOUCHMORPH does
 */
function verify_signed_response($response, $expectedRequester = 'VOUCHMORPH')
{
    $certificate = $response['certificate'] ?? null;
    $signature = $response['signature'] ?? null;
    $requester = $response['requester'] ?? 'UNKNOWN';
    
    if (!$certificate || !$signature) {
        error_log("SACCUSSALIS: Missing certificate or signature in response");
        return false;
    }
    
    $certManager = new CertificateManager();
    
    // Verify certificate
    if (!$certManager->verifyCertificate($certificate)) {
        error_log("SACCUSSALIS: Certificate verification failed for {$requester}");
        return false;
    }
    
    // Extract public key
    $publicKey = $certManager->extractPublicKeyFromCert($certificate);
    if (!$publicKey) {
        error_log("SACCUSSALIS: Cannot extract public key");
        return false;
    }
    
    // Build payload for verification - keep requester and timestamp
    $payloadToVerify = $response;
    unset($payloadToVerify['signature']);
    unset($payloadToVerify['certificate']);
    // Keep requester and timestamp - they are part of the signed payload
    ksort($payloadToVerify);
    
    $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $decodedSig = base64_decode($signature);
    
    $keyResource = openssl_pkey_get_public($publicKey);
    $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
    $isValid = ($result === 1);
    
    error_log("SACCUSSALIS: Response from {$requester} - Signature: " . ($isValid ? "VALID" : "INVALID"));
    
    return $isValid;
}
