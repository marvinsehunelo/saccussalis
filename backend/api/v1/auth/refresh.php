<?php
// saccussalis/api/v1/auth/refresh.php
// POST /api/v1/auth/refresh

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['refresh_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'refresh_token required']);
    exit;
}

$refreshToken = $input['refresh_token'];

// Find source by refresh token
$sql = "SELECT * FROM authorized_sources WHERE refresh_token = ? AND status = 'active'";
$stmt = $db->prepare($sql);
$stmt->execute([$refreshToken]);
$source = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$source) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid refresh token']);
    exit;
}

// Generate new access token
$newAccessToken = bin2hex(random_bytes(32));
$newExpiresAt = date('Y-m-d H:i:s', time() + 3600);

// Update
$sql = "UPDATE authorized_sources SET access_token = ?, token_expires_at = ? WHERE source_reference = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$newAccessToken, $newExpiresAt, $source['source_reference']]);

echo json_encode([
    'success' => true,
    'access_token' => $newAccessToken,
    'expires_at' => $newExpiresAt
]);
