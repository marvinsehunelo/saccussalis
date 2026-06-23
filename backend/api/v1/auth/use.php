<?php
// saccussalis/api/v1/source/use.php
// POST /api/v1/source/use

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/crypto.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['source_reference']) || empty($input['access_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'source_reference and access_token required']);
    exit;
}

$sourceRef = $input['source_reference'];
$accessToken = $input['access_token'];
$data = $input['data'] ?? [];

// Verify source
$sql = "SELECT * FROM authorized_sources WHERE source_reference = ? AND access_token = ? AND status = 'active' AND token_expires_at > NOW()";
$stmt = $db->prepare($sql);
$stmt->execute([$sourceRef, $accessToken]);
$source = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$source) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}

// Get balance
$balance = getBalance($source['identifier'], $source['asset_type']);

// Update last used
$sql = "UPDATE authorized_sources SET last_used_at = NOW() WHERE source_reference = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$sourceRef]);

echo json_encode([
    'success' => true,
    'verified' => true,
    'balance' => $balance,
    'available_balance' => $balance,
    'currency' => 'BWP',
    'source_details' => [
        'holder_name' => $source['holder_name'],
        'asset_type' => $source['asset_type'],
        'identifier' => $source['identifier']
    ]
]);

function getBalance($identifier, $assetType) {
    // Implement actual balance check
    // For demo, return a sample balance
    return 1000.00;
}
