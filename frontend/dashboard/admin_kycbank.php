<?php
// frontend/dashboard/admin_kycbank.php - SACCUSSALIS Admin: KYC Bank
//
// Staff page for KYC Bank, the shared KYC service:
//   ?                         customers with their latest KYC Bank request
//   ?user_id=N                one customer: request KYC, request status,
//                             refresh, history
//   ?user_id=N&request=R&view=profile
//                             the fetched profile, with "Approve KYC using
//                             this profile" and "Reject"
// Actions post to backend/admin/admin_kycbank_action.php.

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../backend/integrations/kycbank/admin_guard.php';
$admin = kb_require_admin('../admin/admin_login.php');

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/integrations/kycbank/kycbank.php';

$configured = KycBankClient::isConfigured();
$flash      = kb_flash();
$error      = '';
$userId     = (int)($_GET['user_id'] ?? 0);
$view       = (string)($_GET['view'] ?? '');
$search     = trim((string)($_GET['q'] ?? ''));
$action     = '../../backend/admin/admin_kycbank_action.php';

$customers = [];
$customer  = null;
$requests  = [];
$latest    = null;
$shown     = null;   // request whose profile is shown
$profile   = null;

if (!$pdo) {
    $error = 'The database is not reachable right now. Please try again shortly.';
} else {
    try {
        kb_ensure_tables($pdo);
        if ($userId > 0) {
            $stmt = $pdo->prepare('SELECT user_id, full_name, email, phone, kyc_status, status FROM users WHERE user_id = ?');
            $stmt->execute([$userId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($customer) {
                $requests = kb_requests_for_user($pdo, $userId);
                $latest   = $requests[0] ?? null;
                if ($view === 'profile') {
                    foreach ($requests as $r) {
                        if ((int)$r['id'] === (int)($_GET['request'] ?? 0)) {
                            $shown = $r;
                        }
                    }
                    if ($shown && kb_profile_usable($shown)) {
                        $profile = json_decode((string)$shown['profile_json'], true);
                        if (is_array($profile)) {
                            kb_audit($pdo, 'kycbank_profile_viewed', $userId, $admin['id'], ['request' => (int)$shown['id'], 'consent' => $shown['consent_id']]);
                        }
                    }
                }
            } else {
                $error = 'Customer not found.';
            }
        } else {
            $sql = "
                SELECT u.user_id, u.full_name, u.email, u.kyc_status,
                       r.id AS request_id, r.state, r.needs_review, r.review_decision, r.updated_at AS request_updated_at
                  FROM users u
                  LEFT JOIN LATERAL (
                        SELECT id, state, needs_review, review_decision, updated_at
                          FROM kycbank_requests k
                         WHERE k.user_id = u.user_id
                         ORDER BY k.created_at DESC, k.id DESC
                         LIMIT 1
                  ) r ON TRUE
                 WHERE LOWER(COALESCE(u.role, '')) NOT IN ('superadmin','admin','manager','teller','auditor','compliance','system')";
            $params = [];
            if ($search !== '') {
                $sql .= ' AND (u.full_name ILIKE ? OR u.email ILIKE ? OR CAST(u.user_id AS TEXT) = ?)';
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $params = [$like, $like, $search];
            }
            $sql .= ' ORDER BY r.updated_at DESC NULLS LAST, u.user_id ASC LIMIT 200';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('[KycBank page] load failed for user ' . $userId . ': ' . get_class($e));
        $error = 'Could not load KYC Bank data. Please try again.';
    }
}

$h = 'kb_h';
$prefillMobile = '';
if ($customer && !empty($customer['phone'])) {
    $candidate = kb_normalize_mobile((string)$customer['phone']);
    $prefillMobile = KycBankClient::isValidMobile($candidate) ? $candidate : '';
}
$canRequest = $customer && $admin['can_act'] && $configured
    && (!$latest || !in_array($latest['state'], KB_OPEN_STATES, true));

function kb_state_badge(?string $state): string
{
    if ($state === null) {
        return '<span class="badge">—</span>';
    }
    $tone = ['active' => 'ok', 'requested' => 'wait', 'not_sent' => 'wait', 'suspended' => 'warn',
        'withdrawn' => 'bad', 'rejected' => 'bad', 'failed' => 'bad', 'lapsed' => 'muted', 'expired' => 'muted'][$state] ?? 'muted';
    return '<span class="badge ' . $tone . '">' . kb_h(kb_state_label($state)) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SACCUSSALIS ADMIN - KYC BANK</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&display=swap" rel="stylesheet">
<style>
:root { --primary-black:#000; --accent-gold:#A9996F; --bg-light:#F6F7F9; --card-bg:#fff; --text-dark:#111; }
* { box-sizing: border-box; }
body { font-family:'Playfair Display', serif; background:var(--bg-light); color:var(--text-dark); margin:0; min-height:100vh; }
header { background:var(--card-bg); border-bottom:3px solid var(--accent-gold); padding:1rem; text-align:center; text-transform:uppercase; }
.brand-title { font-family:'Cinzel', serif; font-weight:900; font-size:2rem; margin:0; }
.brand-role { font-weight:700; font-size:1.1rem; color:var(--accent-gold); margin:2px 0 0; }
nav { margin-top:12px; display:flex; flex-wrap:wrap; gap:6px; justify-content:center; }
.admin-button, nav a { font-family:inherit; font-size:0.75rem; padding:6px 12px; font-weight:700; border-radius:0; border:1px solid var(--primary-black);
  background:var(--primary-black); color:#fff; cursor:pointer; text-decoration:none; text-transform:uppercase; display:inline-block; }
.admin-button.btn-outline, nav a.btn-outline { background:transparent; color:var(--primary-black); }
.admin-button:hover:not(:disabled), nav a:hover, nav a.active { background:var(--accent-gold); color:var(--primary-black); }
.admin-button.btn-sm { padding:3px 6px; font-size:0.6rem; }
.admin-button.danger { background:#b91c1c; border-color:#b91c1c; }
.admin-button:disabled { opacity:.5; cursor:not-allowed; }
main { max-width:1100px; margin:2rem auto; padding:0 1rem; display:grid; gap:20px; }
.grid { display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start; }
.card { background:var(--card-bg); border:1px solid var(--primary-black); padding:16px; box-shadow:3px 3px 0 rgba(0,0,0,0.08); }
h2 { font-weight:700; font-size:1.25rem; margin:0 0 4px; text-transform:uppercase; }
h3 { font-size:0.8rem; letter-spacing:1px; text-transform:uppercase; margin:0 0 6px; color:var(--accent-gold); }
.mini { font-size:0.65rem; color:#555; margin-bottom:8px; text-transform:uppercase; }
p, .text { font-size:0.85rem; line-height:1.5; }
table { width:100%; border-collapse:collapse; font-size:0.75rem; }
th, td { padding:6px 8px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; }
table.list th { background:var(--primary-black); color:#fff; text-transform:uppercase; }
table.list tr:nth-child(even) { background:#f8f9fb; }
table.list tr:hover { background:#eee6d9; }
input, select { width:100%; padding:6px; font-size:0.8rem; border:1px solid var(--primary-black); border-radius:0; margin:2px 0 8px; background:var(--bg-light); font-family:inherit; }
label { display:block; font-size:0.65rem; letter-spacing:1px; text-transform:uppercase; font-weight:700; }
.choice { display:flex; gap:14px; margin:4px 0 8px; font-size:0.8rem; }
.choice label { font-size:0.75rem; display:flex; gap:4px; align-items:center; text-transform:none; letter-spacing:0; font-weight:500; }
.choice input { width:auto; margin:0; }
.msg { padding:10px 14px; font-size:0.85rem; border-left:4px solid; }
.msg.ok { background:#ecfccb; border-color:#65a30d; }
.msg.error { background:#fee2e2; border-color:#ef4444; }
.msg.warn { background:#fef3c7; border-color:#d97706; font-weight:700; }
.badge { display:inline-block; padding:2px 6px; font-size:0.65rem; font-weight:700; text-transform:uppercase; border:1px solid #999; }
.badge.ok { background:#dcfce7; border-color:#15803d; color:#15803d; }
.badge.wait { background:#fef9c3; border-color:#a16207; color:#a16207; }
.badge.warn { background:#ffedd5; border-color:#c2410c; color:#c2410c; }
.badge.bad { background:#fee2e2; border-color:#b91c1c; color:#b91c1c; }
.badge.muted { background:#f1f5f9; color:#475569; }
dl.facts { display:grid; grid-template-columns:max-content 1fr; gap:4px 14px; font-size:0.8rem; margin:8px 0; }
dl.facts dt { font-weight:700; text-transform:uppercase; font-size:0.65rem; padding-top:2px; }
dl.facts dd { margin:0; }
.actions { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
.actions form { margin:0; }
.kb-section { border-top:1px solid #e2e8f0; padding:10px 0; }
.kb-kv { font-size:0.8rem; }
.kb-kv th { width:34%; font-weight:700; color:#333; }
.kb-kv .kb-kv th { width:40%; font-weight:500; }
.kb-item { border:1px dashed var(--accent-gold); padding:6px 8px; margin:6px 0; }
.kb-item-title { font-size:0.65rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px; }
.kb-muted { color:#888; }
@media (max-width: 768px) { .grid { grid-template-columns:1fr; } .brand-title { font-size:1.6rem; } }
</style>
</head>
<body>
<header>
  <div class="brand-title">SACCUSSALIS</div>
  <div class="brand-role"><?= $h($admin['role']) ?></div>
  <div class="mini">SIGNED IN AS <strong><?= $h($admin['name']) ?></strong></div>
  <nav>
    <a href="admin_dashboard.php" class="btn-outline">← Dashboard</a>
    <a href="admin_kycbank.php" class="<?= $userId > 0 ? 'btn-outline' : 'active' ?>">KYC Bank customers</a>
    <?php if ($customer): ?><a href="admin_kycbank.php?user_id=<?= (int)$customer['user_id'] ?>" class="<?= $view === 'profile' ? 'btn-outline' : 'active' ?>">Customer #<?= (int)$customer['user_id'] ?></a><?php endif; ?>
  </nav>
</header>

<main>
<?php if (!$configured): ?>
  <div class="msg warn">KYC Bank is not configured. Set <?= $h(implode(', ', KycBankClient::missingEnv())) ?> on the server to send requests; existing records stay visible.</div>
<?php endif; ?>
<?php if ($flash): ?>
  <div class="msg <?= $flash['ok'] ? 'ok' : 'error' ?>"><?= $h($flash['message']) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="msg error"><?= $h($error) ?></div>
<?php endif; ?>

<?php if ($userId <= 0 && $error === ''): ?>
  <div class="card">
    <h2>KYC Bank</h2>
    <div class="mini">Request a customer's verified KYC from KYC Bank, follow the request and review the profile</div>
    <form method="get" style="display:flex; gap:6px;">
      <input type="text" name="q" value="<?= $h($search) ?>" placeholder="Search name, email or customer ID" style="margin:0;">
      <button class="admin-button" type="submit">Search</button>
    </form>
    <div style="overflow-x:auto; margin-top:10px;">
      <table class="list">
        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>KYC status</th><th>KYC Bank</th><th></th></tr></thead>
        <tbody>
        <?php if (!$customers): ?>
          <tr><td colspan="6" style="text-align:center; padding:12px;">NO CUSTOMERS FOUND</td></tr>
        <?php endif; ?>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td><?= (int)$c['user_id'] ?></td>
            <td><?= $h($c['full_name']) ?></td>
            <td><?= $h($c['email']) ?></td>
            <td><?= $h(strtoupper((string)($c['kyc_status'] ?: 'pending'))) ?></td>
            <td>
              <?= kb_state_badge($c['state']) ?>
              <?php if ($c['state'] === 'active' && kb_bool($c['needs_review'])): ?><span class="badge warn">Needs review</span><?php endif; ?>
              <?php if ($c['review_decision'] === 'approved' && $c['state'] !== null && $c['state'] !== 'active'): ?><span class="badge bad">KYC basis <?= $c['state'] === 'withdrawn' ? 'withdrawn' : 'inactive' ?></span><?php endif; ?>
            </td>
            <td><a class="admin-button btn-sm" href="admin_kycbank.php?user_id=<?= (int)$c['user_id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($customer && $view === 'profile'): ?>
  <div class="card">
    <h2>KYC Bank profile</h2>
    <div class="mini">Customer #<?= (int)$customer['user_id'] ?> · <?= $h($customer['full_name']) ?> · KYC status <?= $h(strtoupper((string)($customer['kyc_status'] ?: 'pending'))) ?></div>
    <?php if (!$shown): ?>
      <div class="msg error">That request was not found for this customer.</div>
    <?php elseif ($shown['state'] === 'withdrawn'): ?>
      <div class="msg warn"><?= $h(kb_state_help($shown)) ?></div>
      <p>The copy received from KYC Bank is kept only for record-keeping and is not shown.</p>
    <?php elseif (!kb_profile_usable($shown)): ?>
      <div class="msg warn">No usable profile: the consent is <?= $h(strtolower(kb_state_label($shown['state']))) ?>. <?= $h(kb_state_help($shown)) ?></div>
    <?php elseif (!is_array($profile)): ?>
      <div class="msg error">The stored profile could not be read. Refresh the status to fetch it again.</div>
    <?php else: ?>
      <?php if ($w = kb_staff_warning($shown)): ?><div class="msg warn"><?= $h($w) ?></div><?php endif; ?>
      <dl class="facts">
        <dt>Consent</dt><dd><?= $h($shown['consent_id']) ?> · <?= $h($shown['level']) ?></dd>
        <dt>Profile version</dt><dd><?= $h($shown['profile_version'] ?? '—') ?>, fetched <?= $h(kb_fmt_date($shown['profile_fetched_at'])) ?></dd>
        <dt>Staff decision</dt><dd><?= $shown['review_decision'] ? $h(ucfirst($shown['review_decision'])) . ' on ' . $h(kb_fmt_date($shown['reviewed_at'])) . ' (version ' . (int)$shown['reviewed_profile_version'] . ')' : 'None yet' ?></dd>
      </dl>
      <p class="text">KYC Bank verified this profile. Saccussalis still makes its own KYC decision: the customer's KYC status changes only when you approve or reject below.</p>
      <?php if ($admin['can_act']): ?>
      <div class="actions">
        <form method="post" action="<?= $h($action) ?>" onsubmit="return confirm('Approve this customer\'s KYC using this KYC Bank profile?');">
          <input type="hidden" name="csrf" value="<?= $h($admin['csrf']) ?>">
          <input type="hidden" name="user_id" value="<?= (int)$customer['user_id'] ?>">
          <input type="hidden" name="request_id" value="<?= (int)$shown['id'] ?>">
          <button class="admin-button" name="action" value="approve" type="submit">Approve KYC using this profile</button>
        </form>
        <form method="post" action="<?= $h($action) ?>" onsubmit="return confirm('Reject this customer\'s KYC?');">
          <input type="hidden" name="csrf" value="<?= $h($admin['csrf']) ?>">
          <input type="hidden" name="user_id" value="<?= (int)$customer['user_id'] ?>">
          <input type="hidden" name="request_id" value="<?= (int)$shown['id'] ?>">
          <button class="admin-button danger" name="action" value="reject" type="submit">Reject</button>
        </form>
      </div>
      <?php endif; ?>
      <div style="margin-top:12px;"><?= kb_render_profile($profile) ?></div>
    <?php endif; ?>
  </div>
<?php elseif ($customer): ?>
  <?php foreach ($requests as $r): if ($w = kb_staff_warning($r)): ?>
    <div class="msg warn"><?= $h($w) ?></div>
  <?php endif; endforeach; ?>
  <div class="grid">
    <div style="display:grid; gap:20px;">
      <div class="card">
        <h2><?= $h($customer['full_name']) ?></h2>
        <div class="mini">Customer #<?= (int)$customer['user_id'] ?></div>
        <dl class="facts">
          <dt>Email</dt><dd><?= $h($customer['email']) ?></dd>
          <dt>Phone on file</dt><dd><?= $h($customer['phone'] ?: '—') ?></dd>
          <dt>KYC status</dt><dd><strong><?= $h(strtoupper((string)($customer['kyc_status'] ?: 'pending'))) ?></strong></dd>
        </dl>
      </div>

      <div class="card">
        <h2>KYC Bank request</h2>
        <?php if (!$latest): ?>
          <p class="text">No KYC Bank request yet for this customer.</p>
        <?php else: ?>
          <div><?= kb_state_badge($latest['state']) ?>
            <?php if ($latest['state'] === 'active' && kb_bool($latest['needs_review'])): ?><span class="badge warn">Updated profile — needs staff review</span><?php endif; ?>
          </div>
          <p class="text"><?= $h(kb_state_help($latest)) ?></p>
          <dl class="facts">
            <dt>Consent</dt><dd><?= $h($latest['consent_id'] ?? 'not created yet') ?></dd>
            <dt>Identified by</dt><dd><?= $latest['identifier_kind'] === 'omang' ? 'Omang' : 'Phone' ?> (the number itself is not stored)</dd>
            <dt>Level</dt><dd><?= $h(ucfirst($latest['level'])) ?></dd>
            <dt>Purpose</dt><dd><?= $h($latest['purpose']) ?></dd>
            <dt>Requested</dt><dd><?= $h(kb_fmt_date($latest['requested_at'] ?? $latest['created_at'])) ?></dd>
            <?php if ($latest['decided_at']): ?><dt>Decided</dt><dd><?= $h(kb_fmt_date($latest['decided_at'])) ?></dd><?php endif; ?>
            <?php if ($latest['state'] === 'requested' && $latest['lapses_at']): ?><dt>Lapses</dt><dd><?= $h(kb_fmt_date($latest['lapses_at'])) ?></dd><?php endif; ?>
            <?php if ($latest['starts_at']): ?><dt>Consent period</dt><dd><?= $h(kb_fmt_date($latest['starts_at'])) ?> – <?= $h(kb_fmt_date($latest['ends_at'])) ?></dd><?php endif; ?>
            <?php if ($latest['withdrawn_at']): ?><dt>Withdrawn</dt><dd><?= $h(kb_fmt_date($latest['withdrawn_at'])) ?></dd><?php endif; ?>
            <?php if ($latest['profile_fetched_at'] && $latest['state'] !== 'withdrawn'): ?><dt>Profile</dt><dd>Version <?= $h($latest['profile_version'] ?? '?') ?>, fetched <?= $h(kb_fmt_date($latest['profile_fetched_at'])) ?></dd><?php endif; ?>
            <?php if ($latest['review_decision']): ?><dt>Staff decision</dt><dd><?= $h(ucfirst($latest['review_decision'])) ?> on <?= $h(kb_fmt_date($latest['reviewed_at'])) ?></dd><?php endif; ?>
            <?php if ($latest['last_error_code']): ?><dt>Last error</dt><dd><?= $h($latest['last_error_code']) ?></dd><?php endif; ?>
            <dt>Updated</dt><dd><?= $h(kb_fmt_date($latest['updated_at'])) ?></dd>
          </dl>
          <div class="actions">
            <?php if (kb_profile_usable($latest)): ?>
              <a class="admin-button" href="admin_kycbank.php?user_id=<?= (int)$customer['user_id'] ?>&amp;request=<?= (int)$latest['id'] ?>&amp;view=profile">View KYC Bank profile</a>
            <?php endif; ?>
            <?php if ($admin['can_act'] && $configured && $latest['consent_id'] !== null && $latest['state'] !== 'withdrawn'): ?>
              <form method="post" action="<?= $h($action) ?>">
                <input type="hidden" name="csrf" value="<?= $h($admin['csrf']) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$customer['user_id'] ?>">
                <input type="hidden" name="request_id" value="<?= (int)$latest['id'] ?>">
                <button class="admin-button btn-outline" name="action" value="refresh" type="submit">Refresh status</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if (count($requests) > 1): ?>
      <div class="card">
        <h2>Earlier requests</h2>
        <div style="overflow-x:auto;">
          <table class="list">
            <thead><tr><th>Created</th><th>Consent</th><th>Level</th><th>State</th><th>Staff decision</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($requests, 1) as $r): ?>
              <tr>
                <td><?= $h(kb_fmt_date($r['created_at'])) ?></td>
                <td><?= $h($r['consent_id'] ?? '—') ?></td>
                <td><?= $h($r['level']) ?></td>
                <td><?= kb_state_badge($r['state']) ?><?php if ($r['state'] === 'withdrawn'): ?><div class="mini" style="text-transform:none;">Withdrawn by the customer on <?= $h(kb_fmt_date($r['withdrawn_at'])) ?> — data no longer in use</div><?php endif; ?></td>
                <td><?= $r['review_decision'] ? $h(ucfirst($r['review_decision'])) . ' ' . $h(kb_fmt_date($r['reviewed_at'])) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <aside>
      <?php if ($latest && $latest['state'] === 'not_sent' && $admin['can_act'] && $configured): ?>
      <div class="card" style="margin-bottom:20px;">
        <h2>Send again</h2>
        <div class="mini">The last attempt did not reach KYC Bank</div>
        <form method="post" action="<?= $h($action) ?>">
          <input type="hidden" name="csrf" value="<?= $h($admin['csrf']) ?>">
          <input type="hidden" name="user_id" value="<?= (int)$customer['user_id'] ?>">
          <input type="hidden" name="request_id" value="<?= (int)$latest['id'] ?>">
          <input type="hidden" name="identifier_kind" value="<?= $h($latest['identifier_kind']) ?>">
          <?php if ($latest['identifier_kind'] === 'omang'): ?>
            <label for="kb-resend-omang">Omang number</label>
            <input id="kb-resend-omang" name="omang" inputmode="numeric" pattern="\d{9}" maxlength="9" required placeholder="9 digits">
          <?php else: ?>
            <label for="kb-resend-mobile">Phone (international)</label>
            <input id="kb-resend-mobile" name="mobile" value="<?= $h($prefillMobile) ?>" required placeholder="+267 7X XXX XXX">
          <?php endif; ?>
          <p class="text" style="font-size:0.7rem;">The number is not stored, so enter it again. Level and purpose stay as first sent.</p>
          <button class="admin-button" style="width:100%;" name="action" value="resend" type="submit">Send again</button>
        </form>
      </div>
      <?php endif; ?>

      <div class="card">
        <h2>Request KYC from KYC Bank</h2>
        <div class="mini">The customer approves in the KYC Bank app</div>
        <?php if (!$configured): ?>
          <p class="text">KYC Bank is not configured.</p>
        <?php elseif (!$admin['can_act']): ?>
          <p class="text">Your role can view KYC Bank requests but not send them.</p>
        <?php elseif (!$canRequest): ?>
          <p class="text">This customer already has a request under way. A new one can be made once it is declined, lapsed, withdrawn or expired.</p>
        <?php else: ?>
        <form method="post" action="<?= $h($action) ?>">
          <input type="hidden" name="csrf" value="<?= $h($admin['csrf']) ?>">
          <input type="hidden" name="user_id" value="<?= (int)$customer['user_id'] ?>">
          <label>Identify the customer by</label>
          <div class="choice">
            <label><input type="radio" name="identifier_kind" value="mobile" checked onchange="kbKind(this.value)"> Phone</label>
            <label><input type="radio" name="identifier_kind" value="omang" onchange="kbKind(this.value)"> Omang</label>
          </div>
          <div id="kb-mobile">
            <label for="kb-mobile-input">Phone (international)</label>
            <input id="kb-mobile-input" name="mobile" value="<?= $h($prefillMobile) ?>" placeholder="+267 7X XXX XXX">
          </div>
          <div id="kb-omang">
            <label for="kb-omang-input">Omang number</label>
            <input id="kb-omang-input" name="omang" inputmode="numeric" maxlength="9" placeholder="9 digits">
          </div>
          <label for="kb-purpose">Purpose (shown to the customer)</label>
          <input id="kb-purpose" name="purpose" maxlength="200" required value="<?= $h(KB_DEFAULT_PURPOSE) ?>">
          <label for="kb-level">Level</label>
          <select id="kb-level" name="level">
            <option value="basic">Basic</option>
            <option value="standard">Standard</option>
          </select>
          <button class="admin-button" style="width:100%;" name="action" value="request" type="submit">Request KYC from KYC Bank</button>
        </form>
        <script>
          function kbKind(kind) {
            document.getElementById('kb-mobile').style.display = kind === 'mobile' ? '' : 'none';
            document.getElementById('kb-omang').style.display = kind === 'omang' ? '' : 'none';
          }
          kbKind('mobile');
        </script>
        <?php endif; ?>
      </div>
    </aside>
  </div>
<?php endif; ?>
</main>
</body>
</html>
