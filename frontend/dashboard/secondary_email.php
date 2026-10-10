<?php
// frontend/dashboard/secondary_email.php
//
// Add and verify a secondary email address. The email the account was
// opened with may be a placeholder; a verified address here also gets the
// code SaccusSalis sends when VouchMorph asks to link this account.

ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['authToken']) || empty($_SESSION['user_id'])) {
    header("Location: ../public/login.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers/secondary_email.php';

if (empty($_SESSION['se_csrf'])) {
    $_SESSION['se_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['se_csrf'];

$error   = '';
$notice  = '';
$pending = $_SESSION['se_pending_email'] ?? null; // address a code was sent to, until it is verified
$emailIn = trim((string)($_POST['email'] ?? ''));

if (!$pdo) {
    $error = 'We cannot reach our systems right now. Please try again shortly.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $error = 'Your session expired. Please try again.';
        } elseif ($action === 'send') {
            $email = se_normalize($emailIn);
            if ($email === null) {
                $error = 'Please enter a valid email address.';
            } else {
                $result = se_send_code($pdo, $user_id, $email, 'verify_email');
                if ($result['ok']) {
                    $pending = $_SESSION['se_pending_email'] = $email;
                    $notice  = $result['message'];
                } else {
                    $error = $result['message'];
                }
            }
        } elseif ($action === 'resend' && $pending) {
            $result = se_send_code($pdo, $user_id, $pending, 'verify_email');
            $result['ok'] ? $notice = $result['message'] : $error = $result['message'];
        } elseif ($action === 'verify' && $pending) {
            $result = se_confirm_email($pdo, $user_id, (string)($_POST['code'] ?? ''));
            if ($result['ok']) {
                unset($_SESSION['se_pending_email']);
                $pending = null;
                $notice  = $result['message'] . ' Codes for linking your account to VouchMorph will also be sent here.';
            } else {
                $error = $result['message'];
            }
        } elseif ($action === 'cancel') {
            unset($_SESSION['se_pending_email']);
            $pending = null;
        } elseif ($action === 'remove') {
            se_remove($pdo, $user_id);
            unset($_SESSION['se_pending_email']);
            $pending = null;
            $notice  = 'Your secondary email was removed.';
        }
    } catch (Throwable $e) {
        error_log('[SecondaryEmail page] ' . $action . ' failed: ' . $e->getMessage());
        $error = 'Something went wrong. Please try again.';
    }
}

$verified = null;
if ($pdo) {
    try {
        $verified = se_verified_email($pdo, $user_id);
    } catch (Throwable $e) {
        error_log('[SecondaryEmail page] lookup failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Saccussalis Private Bank - Secondary Email</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:wght@400;700&display=swap" rel="stylesheet">
<style>
    :root {
        --bg: #FFFAF0; --card: #FFFFFF; --fg: #000000; --muted: #444444;
        --border: #E8E8E8; --positive: #008000; --negative: #CC0000;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--fg); font-family: 'Libre Baskerville', Georgia, serif; }
    header { display: flex; justify-content: space-between; align-items: center; padding: 20px 32px; border-bottom: 1px solid var(--fg); }
    header h1 { margin: 0; font-size: 18px; letter-spacing: 3px; text-transform: uppercase; }
    header a { color: var(--fg); font-size: 13px; }
    main { max-width: 560px; margin: 40px auto; padding: 0 16px; }
    .card { background: var(--card); border: 1px solid var(--border); padding: 28px; }
    h2 { margin: 0 0 8px; font-size: 20px; }
    p.lead { color: var(--muted); font-size: 14px; line-height: 1.6; margin: 0 0 20px; }
    label { display: block; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 6px; }
    input[type=email], input[name=code] { width: 100%; padding: 12px; border: 1px solid var(--fg); background: var(--bg); font-size: 15px; font-family: inherit; }
    input[name=code] { letter-spacing: 8px; text-align: center; font-size: 20px; }
    .btn { width: 100%; margin-top: 14px; padding: 13px; background: var(--fg); color: var(--bg); border: 1px solid var(--fg); font-family: inherit; font-size: 13px; letter-spacing: 2px; text-transform: uppercase; cursor: pointer; }
    .link { background: none; border: none; padding: 0; font-family: inherit; font-size: 13px; color: var(--fg); text-decoration: underline; cursor: pointer; }
    .link.danger { color: var(--negative); }
    .row { display: flex; justify-content: space-between; margin-top: 16px; }
    .msg { padding: 12px 14px; margin-bottom: 18px; font-size: 14px; border-left: 3px solid; }
    .msg.error { color: var(--negative); border-color: var(--negative); background: #FFF0F0; }
    .msg.ok { color: var(--positive); border-color: var(--positive); background: #F0FFF0; }
    .current { display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border); background: var(--bg); padding: 14px; margin-bottom: 22px; }
    .current .verified { color: var(--positive); font-size: 12px; margin-top: 4px; }
</style>
</head>
<body>
<header>
    <h1>Saccussalis</h1>
    <a href="dashboard.php">&larr; Back to dashboard</a>
</header>
<main>
    <div class="card">
        <h2>Secondary email</h2>
        <p class="lead">Add an email address you can reach. When you link this account to VouchMorph, we send the verification code here as well as by SMS.</p>

        <?php if ($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="msg ok"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

        <?php if ($verified && !$pending): ?>
        <div class="current">
            <div>
                <strong><?= htmlspecialchars($verified['email']) ?></strong>
                <div class="verified">&#10003; Verified <?= htmlspecialchars(date('j M Y', strtotime($verified['verified_at']))) ?></div>
            </div>
            <form method="POST" onsubmit="return confirm('Remove this email? Codes for linking to VouchMorph will then only go to your phone.');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="remove">
                <button class="link danger">Remove</button>
            </form>
        </div>
        <label>Change to a different address</label>
        <?php endif; ?>

        <?php if ($pending): ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="verify">
            <label for="code">Code sent to <?= htmlspecialchars($pending) ?></label>
            <input id="code" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required autofocus>
            <button class="btn">Verify email</button>
        </form>
        <div class="row">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="resend">
                <button class="link">Send a new code</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="link">Use a different address</button>
            </form>
        </div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="send">
            <?php if (!$verified): ?><label for="email">Email address</label><?php endif; ?>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($emailIn) ?>" placeholder="you@example.com" autocomplete="email" required>
            <button class="btn">Send verification code</button>
        </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
