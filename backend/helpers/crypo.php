<?php
// /SaccusSalisbank/backend/helpers/crypto.php

/**
 * Sign a payload for outgoing response
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        $privateKey = getenv('SACCUSSALIS_PRIVATE_KEY');
    }
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['_timestamp' => $timestamp]);
    $payloadJson = json_encode($payloadWithTimestamp);
    $signature = hash_hmac('sha256', $payloadJson, $privateKey);
    
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
    
    $response = array_merge($payload, [
        'signature' => $signed['signature'],
        'timestamp' => $signed['timestamp']
    ]);
    
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}

/**
 * Verify incoming signature from requester
 */
function verify_signature($payload, $signature, $publicKey, $timestamp = null, $maxAgeSeconds = 300)
{
    // Reject old messages
    if ($timestamp && abs(time() - $timestamp) > $maxAgeSeconds) {
        return false;
    }
    
    if ($timestamp) {
        $payloadToVerify = array_merge($payload, ['_timestamp' => $timestamp]);
    } else {
        $payloadToVerify = $payload;
    }
    
    $payloadJson = json_encode($payloadToVerify);
    $expectedSignature = base64_encode(hash_hmac('sha256', $payloadJson, $publicKey, true));
    
    return hash_equals($expectedSignature, $signature);
}
