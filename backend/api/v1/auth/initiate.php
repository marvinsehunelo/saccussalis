<?php
// saccussalis/api/v1/auth/initiate.php
// POST /api/v1/auth/initiate

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/sms.php';

header('Content-Type: application/json');

try {

    // Database check
    if (!isset($pdo) || !$pdo) {
        throw new Exception('Database connection failed');
    }

    // Read request once
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    error_log("[AUTH] Incoming: " . $raw);

    if (
        !is_array($input) ||
        empty($input['identifier']) ||
        empty($input['asset_type'])
    ) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'identifier and asset_type required'
        ]);
        exit;
    }

    $authId = $input['auth_id']
        ?? 'AUTH_' . date('Ymd') . '_' . bin2hex(random_bytes(6));

    $identifier = trim($input['identifier']);
    $assetType  = strtoupper(trim($input['asset_type']));

    // Generate OTP
    $otp = sprintf("%06d", random_int(0, 999999));

    // Save OTP
    $sql = "
        INSERT INTO auth_otps
        (
            auth_id,
            identifier,
            asset_type,
            otp,
            expires_at,
            status
        )
        VALUES
        (
            ?, ?, ?, ?,
            NOW() + INTERVAL '5 minutes',
            'pending'
        )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        $authId,
        $identifier,
        $assetType,
        $otp
    ]);

    // SMS text
    $message =
        "Your SaccusSalis verification code is {$otp}. "
        . "It expires in 5 minutes. "
        . "Do not share this code with anyone.";

    // Send SMS
    $smsSent = sendSms($identifier, $message);

    if ($smsSent) {

        $pdo->prepare("
            UPDATE auth_otps
            SET status='sent'
            WHERE auth_id=?
        ")->execute([$authId]);

        error_log("[AUTH] OTP successfully sent to {$identifier}");

        echo json_encode([
            'success' => true,
            'auth_id' => $authId,
            'message' => 'OTP sent successfully',
            'expires_in' => 300
        ]);

    } else {

        $pdo->prepare("
            UPDATE auth_otps
            SET status='failed'
            WHERE auth_id=?
        ")->execute([$authId]);

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'auth_id' => $authId,
            'message' => 'Unable to send OTP'
        ]);

    }

} catch (Throwable $e) {

    error_log("[AUTH] " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}
