<?php
// backend/api/v1/test_cert.php

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$certManager = new CertificateManager('SACCUSSALIS');

$result = [
    'ca_cert_loaded' => $certManager->getCACertificate() ? true : false,
    'certificate_in_request' => isset($input['certificate']),
    'verification' => null
];

if (isset($input['certificate'])) {
    $verification = $certManager->verifySignedRequest($input);
    $result['verification'] = $verification;
}

echo json_encode($result, JSON_PRETTY_PRINT);
