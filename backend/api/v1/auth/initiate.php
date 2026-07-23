<?php
// saccussalis/api/v1/auth/initiate.php
// POST /api/v1/auth/initiate

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/sms.php';

header('Content-Type: application/json');

try {
    if (!isset($pdo) || !$pdo) {
        throw new Exception("Database connection failed");
    }

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    error_log("[AUTH] Incoming: " . $raw);

    if (!is_array($input) || empty($input['identifier']) || empty($input['asset_type'])) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "identifier and asset_type required"
        ]);
        exit;
    }

    $authId = $input['auth_id'] ?? 'AUTH_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
    $identifier = trim($input['identifier']);
    $assetType = strtoupper(trim($input['asset_type']));
    $userId = $input['user_id'] ?? null;

    // Generate 6 digit OTP
    $otp = sprintf("%06d", random_int(0, 999999));

    // ============================================================
    // FIX: Determine where to send the OTP
    // ============================================================
    $phoneNumber = $identifier; // Default: use identifier

    // If this is an ACCOUNT and we have a user_id, look up the user's phone
    if ($assetType === 'ACCOUNT' && $userId) {
        $userStmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && !empty($user['phone'])) {
            $phoneNumber = $user['phone'];
            error_log("[AUTH] Account: Sending OTP to user's phone: {$phoneNumber}");
        } else {
            error_log("[AUTH] WARNING: No phone found for user_id={$userId}, falling back to identifier");
        }
    } else {
        // For WALLET, CARD, etc., use the identifier directly
        error_log("[AUTH] Sending OTP to identifier: {$identifier}");
    }

    // Store OTP - keep the same table structure
    $sql = "
        INSERT INTO auth_otps (
            auth_id,
            identifier,
            asset_type,
            otp,
            expires_at,
            status
        ) VALUES (
            ?, ?, ?, ?,
            (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') + INTERVAL '5 minutes',
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

    // Send SMS to the phone number (not the identifier for accounts)
    $message = "Your SaccusSalis verification code is {$otp}. It expires in 5 minutes. Do not share this code.";
    $smsSent = sendSms($phoneNumber, $message);

    if ($smsSent) {
        $update = $pdo->prepare("UPDATE auth_otps SET status='sent' WHERE auth_id=?");
        $update->execute([$authId]);

        error_log("[AUTH] OTP sent to phone: {$phoneNumber} for auth_id: {$authId}");

        // KEEP THE SAME RESPONSE FORMAT - This is critical!
        // VouchMorph expects this exact format
        echo json_encode([
            "success" => true,
            "auth_id" => $authId,
            "message" => "OTP sent successfully",
            "expires_in" => 300
        ]);
    } else {
        $pdo->prepare("UPDATE auth_otps SET status='failed' WHERE auth_id=?")->execute([$authId]);

        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "SMS sending failed"
        ]);
    }
} catch (Throwable $e) {
    error_log("[AUTH ERROR] " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
