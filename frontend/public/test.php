<?php
require_once '/app/backend/helpers/CertificateManager.php';

// Set content type to plain text for debugging
header('Content-Type: text/plain');

$certManager = new CertificateManager('SACCUSSALIS');

echo "=== CERTIFICATE MANAGER TEST ===\n";
echo "1. CA Certificate loaded: " . ($certManager->getCACertificate() ? "YES ✓" : "NO ✗") . "\n";
echo "2. Method exists - getCACertificate: " . (method_exists($certManager, 'getCACertificate') ? "YES ✓" : "NO ✗") . "\n";
echo "3. Method exists - verifySignedRequest: " . (method_exists($certManager, 'verifySignedRequest') ? "YES ✓" : "NO ✗") . "\n";
echo "4. isConfigured: " . ($certManager->isConfigured() ? "YES ✓" : "NO ✗") . "\n";

// Show environment variable status
echo "\n=== ENVIRONMENT VARIABLES ===\n";
echo "MEMBER_NAME: " . (getenv('MEMBER_NAME') ?: 'NOT SET') . "\n";
echo "VOUCHMORPH_CA_CERT_CONTENT: " . (getenv('VOUCHMORPH_CA_CERT_CONTENT') ? "SET (length: " . strlen(getenv('VOUCHMORPH_CA_CERT_CONTENT')) . ")" : "NOT SET") . "\n";
echo "SACCUSSALIS_PRIVATE_KEY_CONTENT: " . (getenv('SACCUSSALIS_PRIVATE_KEY_CONTENT') ? "SET" : "NOT SET") . "\n";
echo "SACCUSSALIS_CERT_CONTENT: " . (getenv('SACCUSSALIS_CERT_CONTENT') ? "SET" : "NOT SET") . "\n";
