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
 * JSON canonicalization
 */
function canonicalize_payload(array $payload): string
{
    unset($payload['signature']);
    unset($payload['requester']);
    ksort($payload);
    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Sign payload for outgoing response
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
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
    ksort($payloadWithTimestamp);
    
    $payloadJson = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    $signature = '';
    openssl_sign($payloadJson, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    
    return [
        'signature' => base64_encode($signature),
        'timestamp' => $timestamp
    ];
}

/**
 * Send signed response with certificate
 */
function send_signed_response($payload, $httpCode = 200)
{
    $signed = sign_payload($payload);
    
    if (!$signed) {
        http_response_code($httpCode);
        echo json_encode($payload);
        exit;
    }
    
    $certContent = getenv('SACCUSSALIS_CERT_CONTENT');
    if ($certContent) {
        $certContent = str_replace(['\\n', '\n'], "\n", $certContent);
    }
    
    $response = array_merge($payload, [
        'signature' => $signed['signature'],
        'timestamp' => $signed['timestamp'],
        'certificate' => $certContent
    ]);
    
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}
