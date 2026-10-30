<?php
// backend/integrations/kycbank/admin_guard.php
//
// Staff session checks shared by the KYC Bank admin page
// (frontend/dashboard/admin_kycbank.php) and its actions
// (backend/admin/admin_kycbank_action.php). Same session as the admin
// dashboard: admin_id + userRole set by backend/auth/admin_login.php, and
// the same 30-minute inactivity timeout. POSTs also need the CSRF token
// this page puts in its forms.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Roles that may see customers' KYC Bank requests (the dashboard's USERS roles). */
const KB_VIEW_ROLES = ['SUPERADMIN', 'ADMIN', 'MANAGER', 'TELLER', 'AUDITOR', 'COMPLIANCE'];
/** Roles that may send requests and approve or reject KYC (auditors only look). */
const KB_ACT_ROLES  = ['SUPERADMIN', 'ADMIN', 'MANAGER', 'TELLER', 'COMPLIANCE'];

/** The signed-in staff member, or a redirect to the admin login. */
function kb_require_admin(string $loginUrl): array
{
    if (empty($_SESSION['admin_id']) || empty($_SESSION['userRole'])) {
        session_unset();
        session_destroy();
        header('Location: ' . $loginUrl);
        exit;
    }
    $timeout = 1800;
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $timeout)) {
        session_unset();
        session_destroy();
        header('Location: ' . $loginUrl . '?timeout=1');
        exit;
    }
    $_SESSION['LAST_ACTIVITY'] = time();

    $role = strtoupper(trim((string)$_SESSION['userRole']));
    if (!in_array($role, KB_VIEW_ROLES, true)) {
        http_response_code(403);
        echo 'Your role cannot use KYC Bank.';
        exit;
    }
    if (empty($_SESSION['kb_csrf'])) {
        $_SESSION['kb_csrf'] = bin2hex(random_bytes(32));
    }
    return [
        'id'      => (int)$_SESSION['admin_id'],
        'role'    => $role,
        'name'    => (string)($_SESSION['staffUsername'] ?? ($_SESSION['admin_name'] ?? 'STAFF')),
        'can_act' => in_array($role, KB_ACT_ROLES, true),
        'csrf'    => (string)$_SESSION['kb_csrf'],
    ];
}

function kb_csrf_ok(): bool
{
    return !empty($_SESSION['kb_csrf']) && hash_equals((string)$_SESSION['kb_csrf'], (string)($_POST['csrf'] ?? ''));
}

/** A one-time message for the next page view. */
function kb_flash(?string $message = null, bool $ok = true): ?array
{
    if ($message !== null) {
        $_SESSION['kb_flash'] = ['message' => $message, 'ok' => $ok];
        return null;
    }
    $flash = $_SESSION['kb_flash'] ?? null;
    unset($_SESSION['kb_flash']);
    return $flash;
}
