<?php
// saccussalis/backend/api/v1/settlement/advice.php
// VouchMorph settlement advice for SaccusSalis (see settlement_desk.php). Certificate required.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';
require_once __DIR__ . '/../../settlement_store.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { http_response_code(400); echo json_encode(['success' => false, 'settled' => false, 'message' => 'Invalid JSON']); exit; }
if ($err = SettlementDesk::verifyVouchMorph($input, new CertificateManager('SACCUSSALIS'))) {
    http_response_code(401); echo json_encode(['success' => false, 'settled' => false, 'message' => $err]); exit;
}
try {
    echo json_encode(saccussalis_desk($pdo)->receiveAdvice($input));
} catch (Throwable $e) {
    error_log('[settlement/advice] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['success' => false, 'settled' => false, 'message' => 'Could not be processed']);
}
