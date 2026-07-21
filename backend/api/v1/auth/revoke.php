<?php
// saccussalis/api/v1/auth/revoke.php
// DELETE /api/v1/auth/revoke/{source_reference}

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';

header('Content-Type: application/json');

// Get source_reference from URL
$path = $_SERVER['REQUEST_URI'];
preg_match('/\/revoke\/([^\/]+)/', $path, $matches);
$sourceRef = $matches[1] ?? null;

if (!$sourceRef) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'source_reference required']);
    exit;
}

$sql = "UPDATE authorized_sources SET status = 'revoked' WHERE source_reference = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$sourceRef]);

echo json_encode([
    'success' => true,
    'message' => 'Source revoked successfully'
]);
