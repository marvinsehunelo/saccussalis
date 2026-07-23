<?php
// saccussalis/api/v1/auth/verify.php
// POST /api/v1/auth/verify

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';

header('Content-Type: application/json');

try {

    if (!isset($pdo) || !$pdo) {
        throw new Exception("Database connection failed");
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (
        !is_array($input) ||
        empty($input['auth_id']) ||
        empty($input['otp'])
    ) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'auth_id and otp required'
        ]);

        exit;
    }


    $authId = trim($input['auth_id']);
    $otp    = trim($input['otp']);


    // Verify OTP
    $sql = "
        SELECT *
        FROM auth_otps
        WHERE auth_id = ?
        AND otp = ?
        AND status IN ('pending','sent')
        AND expires_at > NOW()
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        $authId,
        $otp
    ]);

    $auth = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$auth) {

        error_log("[AUTH] Invalid OTP for auth_id: ".$authId);

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired OTP'
        ]);

        exit;
    }



    // Mark OTP verified
    $sql = "
        UPDATE auth_otps
        SET 
            status='verified',
            verified_at=NOW()
        WHERE auth_id=?
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        $authId
    ]);



    // Generate authorization tokens

    $sourceReference =
        'SRC_' . date('Ymd') . '_' . bin2hex(random_bytes(8));


    $accessToken =
        bin2hex(random_bytes(32));


    $refreshToken =
        bin2hex(random_bytes(32));


    $expiresAt =
        date(
            'Y-m-d H:i:s',
            time() + 3600
        );



    // Save authorized source

    $sql = "
        INSERT INTO authorized_sources
        (
            source_reference,
            identifier,
            asset_type,
            access_token,
            refresh_token,
            token_expires_at,
            holder_name,
            status
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'active'
        )
    ";


    $stmt = $pdo->prepare($sql);


    $stmt->execute([

        $sourceReference,

        $auth['identifier'],

        $auth['asset_type'],

        $accessToken,

        $refreshToken,

        $expiresAt,

        $auth['holder_name'] ?? 'Saccussalis Client'

    ]);



    error_log(
        "[AUTH] Source authorized: ".$sourceReference
    );



    echo json_encode([

        'success' => true,

        'authorized' => true,

        'source_reference' => $sourceReference,

        'access_token' => $accessToken,

        'refresh_token' => $refreshToken,

        'expires_at' => $expiresAt,

        'holder_name' =>
            $auth['holder_name'] ?? 'Saccussalis Client',

        'asset_type' =>
            $auth['asset_type'],

        'identifier' =>
            $auth['identifier']

    ]);


} catch (Throwable $e) {


    error_log(
        "[AUTH VERIFY ERROR] ".$e->getMessage()
    );


    http_response_code(500);


    echo json_encode([

        'success' => false,

        'message' => $e->getMessage()

    ]);

}
