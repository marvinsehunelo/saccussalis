<?php
// saccussalis/api/v1/auth/initiate.php
// POST /api/v1/auth/initiate

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';

header('Content-Type: application/json');

// Check if PDO connection exists
if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['identifier']) || empty($input['asset_type'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'identifier and asset_type required']);
    exit;
}

$authId = $input['auth_id'] ?? 'AUTH_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
$identifier = $input['identifier'];
$assetType = $input['asset_type'];

// Generate OTP (6 digits)
$otp = sprintf("%06d", random_int(0, 999999));

// Store in database - FIX: $pdo instead of $db
$sql = "INSERT INTO auth_otps (auth_id, identifier, asset_type, otp, expires_at, status) 
        VALUES (?, ?, ?, ?, NOW() + INTERVAL 5 MINUTE, 'pending')";
$stmt = $pdo->prepare($sql);
$stmt->execute([$authId, $identifier, $assetType, $otp]);

// Send OTP via SMS (implement your SMS provider)
$smsSent = sendSms($identifier, "Your verification code: $otp");

// Log attempt
error_log("[Auth] OTP sent to $identifier: $otp");

echo json_encode([
    'success' => true,
    'auth_id' => $authId,
    'message' => 'OTP sent successfully',
    'expires_in' => 300
]);
