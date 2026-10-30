<?php
// backend/admin/admin_approve_kyc.php
//
// The dashboard's APPROVE KYC / REJECT KYC action. Body (JSON): { client_id, status } with status
// "approved" or "rejected" (users.kyc_status values; the old "verified" is read as "approved").
// Answers { success, status, message }. Auditors can look but not decide.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once("../db.php");
require_once("../auth/admin_session.php");

function kyc_reply(bool $ok, string $message, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(['success' => $ok, 'status' => $ok ? 'success' : 'error', 'message' => $message]);
    exit;
}

$role = strtoupper(trim((string)($_SESSION['userRole'] ?? '')));
if (!in_array($role, ['SUPERADMIN', 'ADMIN', 'MANAGER', 'TELLER', 'COMPLIANCE'], true)) {
    kyc_reply(false, 'Your role cannot change KYC status.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kyc_reply(false, 'POST only', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$clientId = (int)($input['client_id'] ?? $input['userId'] ?? 0);
$status = strtolower(trim((string)($input['status'] ?? 'approved')));
if ($status === 'verified') $status = 'approved';
if ($clientId <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
    kyc_reply(false, 'Invalid input', 400);
}
if (!$pdo) kyc_reply(false, 'Database unavailable', 503);

try {
    $pdo->beginTransaction();
    $old = $pdo->prepare("SELECT kyc_status FROM users WHERE user_id = ? FOR UPDATE");
    $old->execute([$clientId]);
    $previous = $old->fetchColumn();
    if ($previous === false) {
        $pdo->rollBack();
        kyc_reply(false, 'No such customer', 404);
    }
    $pdo->prepare("UPDATE users SET kyc_status = ?, updated_at = NOW() WHERE user_id = ?")
        ->execute([$status, $clientId]);
    $pdo->prepare("
        INSERT INTO audit_logs (entity, entity_id, category, action, performed_by, ip_address, user_agent, old_value, new_value)
        VALUES ('user', ?, 'kyc', ?, ?, ?, ?, ?, ?)
    ")->execute([
        $clientId,
        $status === 'approved' ? 'KYC_APPROVED' : 'KYC_REJECTED',
        (int)$_SESSION['admin_id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        json_encode(['kyc_status' => $previous]),
        json_encode(['kyc_status' => $status]),
    ]);
    $pdo->commit();
    kyc_reply(true, $status === 'approved' ? 'KYC approved' : 'KYC rejected');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin_approve_kyc] failed for user ' . $clientId . ': ' . get_class($e));
    kyc_reply(false, 'Could not update KYC status', 500);
}
