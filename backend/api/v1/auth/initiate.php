// saccussalis/api/v1/auth/verify.php
// POST /api/v1/auth/verify

require_once __DIR__ . '/../../../db.php';

header('Content-Type: application/json');

try {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!isset($input['auth_id']) || !isset($input['otp'])) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "auth_id and otp required"
        ]);
        exit;
    }

    $authId = trim($input['auth_id']);
    $otp = trim($input['otp']);

    // Verify OTP
    $stmt = $pdo->prepare("
        SELECT * FROM auth_otps 
        WHERE auth_id = ? 
        AND otp = ? 
        AND status = 'sent'
        AND expires_at > CURRENT_TIMESTAMP AT TIME ZONE 'UTC'
        LIMIT 1
    ");
    $stmt->execute([$authId, $otp]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($record) {
        // Mark as verified
        $update = $pdo->prepare("
            UPDATE auth_otps 
            SET status = 'verified', verified_at = CURRENT_TIMESTAMP AT TIME ZONE 'UTC'
            WHERE auth_id = ?
        ");
        $update->execute([$authId]);

        error_log("[AUTH] OTP verified for auth_id: {$authId}, user_id: " . ($record['user_id'] ?? 'unknown'));

        echo json_encode([
            "success" => true,
            "authorized" => true,
            "message" => "OTP verified successfully",
            "user_id" => $record['user_id'],
            "identifier" => $record['identifier']
        ]);
    } else {
        error_log("[AUTH] OTP verification failed for auth_id: {$authId}");
        
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "authorized" => false,
            "message" => "Invalid or expired OTP"
        ]);
    }
} catch (Throwable $e) {
    error_log("[AUTH VERIFY ERROR] " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
