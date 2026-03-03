<?php
// admin_dashboard.php - SACCUSSALIS Admin Panel

// --- PHP Security and Session Management ---

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if session data is missing
if (empty($_SESSION['admin_id']) || empty($_SESSION['userRole'])) {
    // SECURITY: Ensure we destroy potential stale session data before redirecting.
    session_unset();
    session_destroy();
    // Assuming relative path to login page is correct
    header("Location: ../admin/admin_login.php"); 
    exit;
}

// Full Error Reporting (Enabled for development/testing - DISABLE IN PRODUCTION)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session Timeout Logic
$timeout = 1800; // 30 minutes
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $timeout)) {
    session_unset();
    session_destroy();
    // Append timeout flag to URL
    header("Location: ../admin/admin_login.php?timeout=1"); 
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

// --- Role and Access Control ---

// Determine role and normalize
$user_role = strtoupper(trim($_SESSION['userRole']));
$staff_username = $_SESSION['staffUsername'] ?? ($_SESSION['admin_name'] ?? 'STAFF');

// Prepare JS variables
$js_user_role = $user_role;
$js_staff_username = $staff_username;

// Role -> allowed modules map (server authoritative)
$access_map = [
    'USERS'        => ['SUPERADMIN','ADMIN','MANAGER','TELLER','AUDITOR', 'COMPLIANCE'],
    'TRANSACTIONS' => ['SUPERADMIN','ADMIN','MANAGER','AUDITOR', 'TELLER', 'COMPLIANCE'],
    'STAFF'        => ['SUPERADMIN'], // ONLY SUPERADMIN can add staff
    'AUDIT'        => ['SUPERADMIN','AUDITOR', 'COMPLIANCE'],
];

// Helper that ensures in_array doesn't blow up
function role_allows($key, $role, $map) {
    if (!isset($map[$key]) || !is_array($map[$key])) return false;
    return in_array($role, $map[$key]);
}

// Helper for Balance Adjust specific permissions (used in PHP/JS)
function role_can_adjust_balance($role) {
    $adjust_roles = ['SUPERADMIN', 'ADMIN', 'MANAGER', 'TELLER'];
    return in_array($role, $adjust_roles);
}

// Determine visibility based on current user role
$can_view_users = role_allows('USERS', $user_role, $access_map);
$can_view_transactions = role_allows('TRANSACTIONS', $user_role, $access_map);
$can_view_audit = role_allows('AUDIT', $user_role, $access_map);
$can_manage_staff = role_allows('STAFF', $user_role, $access_map);
$can_adjust_balance = role_can_adjust_balance($user_role);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SACCUSSALIS ADMIN PANEL - <?php echo $user_role; ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&display=swap" rel="stylesheet">

<style>
/* --- Design Variables --- */
:root {
  --primary-black: #000000;
  --accent-gold: #A9996F;
  --bg-light: #F6F7F9;
  --card-bg: #ffffff;
  --text-dark: #111111;
}
/* --- Base Styles (unchanged) --- */
body {
  font-family: 'Playfair Display', serif;
  background: var(--bg-light);
  color: var(--text-dark);
  margin: 0;
  padding: 0;
  text-transform: uppercase;
  min-height: 100vh;
}
header {
  background: var(--card-bg);
  border-bottom: 3px solid var(--accent-gold);
  padding: 1rem;
  text-align: center;
}
.brand-title {
  font-family: 'Cinzel', serif;
  font-weight: 900;
  font-size: 2rem;
  color: var(--primary-black);
  margin: 0;
}
.brand-role {
  font-family: 'Playfair Display', serif;
  font-weight: 700;
  font-size: 1.1rem;
  color: var(--accent-gold);
  margin: 2px 0 0;
}
nav {
  margin-top: 12px;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  justify-content: center;
}
nav button {
  font-size: 0.75rem;
  padding: 6px 12px;
  font-weight: 700;
  border-radius: 0;
  border: 1px solid var(--primary-black);
  background: var(--primary-black);
  color: #fff;
  display: flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}
nav button.btn-outline {
  background: transparent;
  color: var(--primary-black);
}
nav button:hover, nav button.active {
  background: var(--accent-gold);
  color: var(--primary-black);
}
.container {
  max-width: 1200px;
  margin: 2rem auto;
  padding: 0 1rem;
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 20px;
}
.card {
  background: var(--card-bg);
  border: 1px solid var(--primary-black);
  padding: 16px;
  box-shadow: 3px 3px 0 rgba(0,0,0,0.08);
  border-radius: 0;
}
h2 {
  font-weight: 700;
  font-size: 1.25rem;
  margin-bottom: 4px;
}
.mini {
  font-size: 0.65rem;
  color: #555;
  margin-bottom: 6px;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.75rem;
}
th, td {
  padding: 6px 8px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
}
th {
  background: var(--primary-black);
  color: #fff;
}
tr:nth-child(even){background:#f8f9fb;}
tr:hover {background:#eee6d9;}
input, select {
  width: 100%;
  padding: 6px;
  font-size: 0.75rem;
  border: 1px solid var(--primary-black);
  border-radius: 0;
  margin: 2px 0;
  background: var(--bg-light);
  color: var(--text-dark);
}
.right .card button, .right .card input, .right .card select {
  font-size: 0.75rem;
  padding: 6px;
  margin-top: 4px;
}
.admin-button {
  font-size: 0.75rem;
  padding: 6px 12px;
  font-weight: 700;
  border-radius: 0;
  border: 1px solid var(--primary-black);
  background: var(--primary-black);
  color: #fff;
  cursor: pointer;
  transition: background 0.2s;
}
.admin-button.btn-outline {
  background: transparent;
  color: var(--primary-black);
}
.admin-button:hover:not(:disabled) {
  background: var(--accent-gold);
  color: var(--primary-black);
}
.admin-button.btn-sm {
    padding: 3px 6px;
    font-size: 0.6rem;
    line-height: 1;
    display: inline-block;
}
/* Utility classes from JS implementation */
.hidden {display: none !important;}
.panel:not(.active-view) {display: none;}

/* Global Message Bar */
#globalMsg {
    position: fixed;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    z-index: 2000;
    padding: 12px 20px;
    border: 1px solid;
    border-top: none;
    text-align: center;
    font-size: 0.8rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    max-width: 90%;
    text-transform: none;
}

/* Modal */
#customDialog {
  position: fixed;
  inset: 0;
  display: none; /* Controlled by JS show/hide */
  background: rgba(0,0,0,0.8);
  justify-content: center;
  align-items: center;
  z-index: 1000;
}
#customDialog .card {
  max-width: 420px;
  width: 90%;
  max-height: 90vh;
  overflow-y:auto;
  padding: 16px;
  text-transform:none;
  display:flex;
  flex-direction:column;
  gap:8px;
}

/* Responsive */
@media (max-width: 768px) {
  .container {grid-template-columns:1fr; margin-top: 1rem;}
  .brand-title {font-size:1.6rem;}
  .brand-role {font-size:1rem;}
  nav button {font-size:0.65rem;padding:5px 10px;}
}
</style>
</head>
<body>

<div id="globalMsg" class="hidden"></div>

<header>
  <div class="brand">
    <div class="brand-title">SACCUSSALIS</div>
    <div class="brand-role"><?php echo $user_role; ?></div>
  </div>
  <div class="mini">SIGNED IN AS <strong><?php echo htmlspecialchars($staff_username); ?></strong></div>
  <nav>
    <?php if ($can_view_users): ?>
    <button id="nav-users" onclick="showScreen('users')"><i data-lucide="users" style="width:14px;"></i> USERS</button>
    <?php endif; ?>
    <?php if ($can_view_transactions): ?>
    <button id="nav-transactions" onclick="showScreen('transactions')"><i data-lucide="list-ordered" style="width:14px;"></i> TRANSACTIONS</button>
    <?php endif; ?>
    <?php if ($can_view_audit): ?>
    <button id="nav-audit" onclick="showScreen('audit')"><i data-lucide="file-check" style="width:14px;"></i> AUDIT</button>
    <?php endif; ?>
    <?php if ($can_manage_staff): ?>
    <button id="nav-staff" onclick="showScreen('staff')"><i data-lucide="user-plus" style="width:14px;"></i> STAFF</button>
    <?php endif; ?>
    <button class="btn-outline" onclick="logout()">LOGOUT</button>
  </nav>
</header>

<div class="container">
  <div class="left">

    <?php if ($can_view_users): ?>
    <div class="card panel active-view" id="users-panel">
      <h2>Registered Users</h2>
      <div class="mini">VIEW AND MANAGE USER ACCOUNTS</div>
      <div style="display:flex; gap:6px; margin:6px 0;">
        <input type="text" id="usersSearch" placeholder="Search name/email/account">
        <button id="refreshUsers" class="admin-button">REFRESH</button>
      </div>
      <div style="overflow-x:auto;">
        <table id="usersTable">
          <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Account</th><th>Balance</th><th>Actions</th></tr></thead>
          <tbody id="usersTableBody"><tr><td colspan="6" style="text-align:center;">LOADING USERS...</td></tr></tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($can_view_transactions): ?>
    <div class="card panel hidden" id="transactions-panel">
      <h2>Transaction History</h2>
      <div class="mini">REVIEW ALL SYSTEM TRANSACTIONS</div>
      <div style="display:flex; gap:6px; margin:6px 0;">
        <input type="text" id="txSearch" placeholder="Search ID/Source/Destination">
        <button id="refreshTxns" class="admin-button">REFRESH</button>
      </div>
      <div style="overflow-x:auto;">
        <table id="txTable">
          <thead>
            <tr>
              <th>Txn ID</th>
              <th>User</th>
              <th>Source Acc</th>
              <th>Dest Acc</th>
              <th>Bank</th>
              <th>Amount</th>
              <th>Fee</th>
              <th>Type</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody><tr><td colspan="10" style="text-align:center;">LOADING TRANSACTIONS...</td></tr></tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($can_view_audit): ?>
    <div class="card panel hidden" id="audit-panel">
      <h2>System Audit Logs</h2>
      <div class="mini">STAFF ACTIONS AND SYSTEM EVENTS</div>
      <div style="margin:6px 0; text-align:right;">
        <button id="refreshAudit" class="admin-button">REFRESH</button>
      </div>
      <div id="auditContainer" style="max-height: 400px; overflow-y: scroll; padding: 4px; border: 1px solid #ccc;">
        <div class="mini">AWAITING AUDIT DATA...</div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <aside class="right">

    <?php if ($can_manage_staff): ?>
    <div class="card panel hidden" id="staff-panel">
      <h2>Staff Management</h2>
      <div class="mini">ADD NEW ADMIN STAFF ACCOUNTS (SUPERADMIN ONLY)</div>
      <form id="addStaffForm">
        <input type="text" id="staffName" placeholder="Staff Name" required>
        <input type="text" id="staffUsername" placeholder="Username" required>
        <input type="password" id="staffPassword" placeholder="Temporary Password" required>
        <select id="staffRole" required>
          <option value="">-- SELECT ROLE --</option>
          <option value="ADMIN">ADMIN</option>
          <option value="MANAGER">MANAGER</option>
          <option value="TELLER">TELLER</option>
          <option value="AUDITOR">AUDITOR</option>
          <option value="COMPLIANCE">COMPLIANCE</option>
          </select>
        <button type="submit" class="admin-button" style="width:100%; margin-top:8px;">ADD STAFF</button>
        <div id="staffResponseMsg" class="mini" style="text-transform:none; margin-top:8px;"></div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($can_adjust_balance): ?>
    <div class="card" id="balance-adjustment-card">
      <h2>Balance Adjust Tool</h2>
      <div class="mini">CREDIT/DEBIT USER ACCOUNT</div>

      <div style="display:flex; gap:4px; margin-bottom: 8px;">
        <input type="text" id="searchEmail" placeholder="User Email" style="flex-grow: 1;">
        <button id="fetchUserBtn" class="admin-button btn-sm" style="width: 80px;">FETCH</button>
      </div>

      <div id="fetchedUserInfo" class="hidden" style="padding: 6px; border: 1px dashed #A9996F; margin-bottom: 8px;">
        </div>
      
      <form id="adjustForm" class="hidden" style="flex-direction: column; gap: 4px;">
        <select id="accountSelect" required></select>
        <input type="number" id="amount" placeholder="Amount (e.g., 5000.00)" step="0.01" required>
        <select id="operation" required>
          <option value="">-- ADJUST TYPE --</option>
          <option value="credit">CREDIT (Add Funds)</option>
          <option value="debit">DEBIT (Remove Funds)</option>
        </select>
        <input type="text" id="reason" placeholder="Reason/Reference" required>
        <button id="submitAdjustBtn" type="submit" class="admin-button" style="width:100%; margin-top:8px;">APPLY ADJUSTMENT</button>
      </form>

      <div id="responseMsg" class="mini" style="text-transform:none; margin-top:8px;">Awaiting user email...</div>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-top: 20px;">
      <h2>General Actions</h2>
      <div class="mini">UTILITY BUTTONS</div>
      <button onclick="vogueAlert('This action is disabled in the demo. Functionality is reserved for SUPERADMIN.', 'DEMO LIMIT');" class="admin-button">RUN SYSTEM CLEANUP</button>
      <button onclick="vogueAlert('This will take you to the user-facing site.', 'REDIRECT');" class="admin-button">VIEW MAIN SITE</button>
      <button onclick="logout()" class="admin-button btn-outline">LOGOUT ADMIN SESSION</button>
    </div>
  </aside>
</div>

<div id="customDialog">
  <div class="card">
    <h2 id="dialogTitle">MODAL TITLE</h2>
    <div id="dialogMessage" style="text-transform:none; font-size: 0.9rem;">This is the message body.</div>
    <div style="display:flex; justify-content:flex-end; gap:6px; margin-top:8px;">
      <button id="dialogCancelBtn" class="admin-button btn-outline">CANCEL</button>
      <button id="dialogOkBtn" class="admin-button">CONFIRM</button>
    </div>
  </div>
</div>

<script>
/* -----------------------------
    CONFIG / ENV (PHP variables injected here)
----------------------------- */
const USER_ROLE = "<?php echo $js_user_role; ?>";
const STAFF_USERNAME = "<?php echo $js_staff_username; ?>";
const CAN_ADJUST_BALANCE = <?php echo $can_adjust_balance ? 'true' : 'false'; ?>;

const API_ADMIN_ROOT = '../../backend/admin/';      // All data/management endpoints
const API_AUTH_ROOT = '../../backend/auth/';        // admin_login, admin_logout, add_staff

/* -----------------------------
    GENERIC API HELPER: fetchData (Updated for Path and Token)
----------------------------- */
/**
 * Generic function to call backend endpoints.
 * @param {string} endpoint - Endpoint filename without .php (e.g., 'admin_get_users' or 'add_staff')
 * @param {string} method - HTTP method ('POST' default)
 * @param {object|null} body - JSON body to send
 * @param {string} query - optional query string (e.g., "?id=123" for GET)
 * @param {string} root - 'admin' (default) or 'auth' to select the base API path
 * @returns {Promise<object>} - Parsed JSON response or an error object
 */
async function fetchData(endpoint, method = 'POST', body = null, query = '', root = 'admin') {
    // 1. DYNAMIC TOKEN RETRIEVAL: Always get the latest token from localStorage
    const currentToken = localStorage.getItem("auth_token");
    const requestedMethod = method.toUpperCase();
    
    // 2. Determine the base URL based on the root parameter
    let baseUrl;
    if (root === 'auth') {
        baseUrl = API_AUTH_ROOT;
    } else {
        baseUrl = API_ADMIN_ROOT;
    }
    
    try {
        const headers = { 'Content-Type': 'application/json' };
        
        // TOKEN INJECTION
        if (currentToken) {
             headers['Authorization'] = 'Bearer ' + currentToken;
        }

        const options = {
            method: requestedMethod,
            headers,
            credentials: 'include' 
        };

        if (body && requestedMethod !== 'GET' && requestedMethod !== 'HEAD') {
            options.body = JSON.stringify(body);
        }

        const url = baseUrl + endpoint + '.php' + (query || ''); // URL constructed using determined base
        const res = await fetch(url, options);

        const responseText = await res.text();

        if (!res.ok) {
            // Check for authentication failures and force logout
            if (res.status === 401 || res.status === 403) {
                 showGlobal("Session expired or token missing. Redirecting to login...", false);
                 localStorage.removeItem("auth_token"); 
                 setTimeout(() => { window.location.href = '../admin/admin_login.php'; }, 2000);
            }
            console.error(`HTTP Error ${res.status} from ${endpoint} (${requestedMethod}):`, responseText);
            return { status: 'error', message: `Server returned status ${res.status} (${res.statusText}).` };
        }

        try {
            if (!responseText) { return { status: 'success', message: 'No content returned.' }; }
            return JSON.parse(responseText);
        } catch (e) {
            console.error(`Invalid JSON from ${endpoint}:`, responseText.substring(0, 200));
            return { status: 'error', message: 'Invalid JSON response from server. Check console for details.' };
        }
    } catch (e) {
        console.error(`Fetch to ${endpoint} failed (Network/Other Error):`, e);
        return { status: 'error', message: 'NETWORK ERROR or unable to reach server.' };
    }
}

/* -----------------------------
    UTILITIES
----------------------------- */

// --- DOM Helpers ---
function el(id){ return document.getElementById(id); }
function show(elm, display='block'){ if(!elm) return; elm.classList.remove('hidden'); elm.style.display = display; }
function hide(elm){ if(!elm) return; elm.classList.add('hidden'); elm.style.display = 'none'; }
function setHTML(id, html){ const e=el(id); if(e) e.innerHTML = html; }
function setText(id, text){ const e=el(id); if(e) e.textContent = text; }

// --- Global Message ---
let globalMsgTimeout;
function showGlobal(msg, ok=true){
    const g = el('globalMsg');
    if(!g) return;
    g.innerHTML = `<div style="font-weight:700;">${ok ? 'SUCCESS' : 'ERROR'}</div>${msg}`;
    show(g);
    g.style.background = ok ? '#ecfccb' : '#fee2e2';
    g.style.borderColor = ok ? '#a3e635' : '#ef4444';
    if(globalMsgTimeout) clearTimeout(globalMsgTimeout);
    globalMsgTimeout = setTimeout(()=> hide(g),7000);
}

// --- Currency & ID Helpers ---
function formatCurrency(v, currency='₦'){ 
    try { return currency + new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v||0));
    } catch(e){ return (Number(v||0)).toFixed(2); } 
}
function shortId(id){ 
    if(!id) return ''; 
    const s = id+'';
    return s.length <= 8 ? s : s.slice(0,4) + '...' + s.slice(-4); 
}

// --- Security ---
function escapeHtml(s){ 
    if(!s && s !== 0) return ''; 
    return String(s).replace(/[&<>"'`=\/]/g, c => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', 
        "'":'&#39;', '/':'&#x2F;', '`':'&#96;', '=':'&#61;'
    }[c])); 
}

// --- Custom Vogue Dialog (Modal logic must exist in the HTML) ---
let currentDialogCallback = null;
const dialog = el('customDialog');
const dialogOkBtn = el('dialogOkBtn');
const dialogCancelBtn = el('dialogCancelBtn');
const dialogTitle = el('dialogTitle');
const dialogMessage = el('dialogMessage');

function showDialog(title, message, callback=null, isConfirm=false, okText='CONFIRM', cancelText='CANCEL') {
    if (!dialog) { /* fallback to native */ return; }

    dialogTitle.textContent = title.toUpperCase();
    dialogMessage.innerHTML = message;
    dialogOkBtn.textContent = okText.toUpperCase();
    dialogCancelBtn.textContent = cancelText.toUpperCase();

    if (isConfirm) {
        show(dialogCancelBtn);
        currentDialogCallback = callback;
    } else {
        hide(dialogCancelBtn);
        currentDialogCallback = null;
    }
    show(dialog, 'flex');
}
function vogueAlert(message, title='NOTIFICATION'){ showDialog(title, message); }
function vogueConfirm(message, callback){ showDialog('ACTION REQUIRED', message, callback, true); }

// Attach dialog button handlers (must be done only once)
if (dialogOkBtn) {
    dialogOkBtn.onclick = () => { if(currentDialogCallback) currentDialogCallback(true); hide(dialog); currentDialogCallback = null; };
}
if (dialogCancelBtn) {
    dialogCancelBtn.onclick = () => { if(currentDialogCallback) currentDialogCallback(false); hide(dialog); currentDialogCallback = null; };
}


/* -----------------------------
    NAV / UI: show/hide panels
----------------------------- */
function showScreen(name){
    const panels = ['users', 'transactions', 'audit', 'staff'];
    
    panels.forEach(k => {
        const panel = el(`${k}-panel`);
        if (panel) {
            hide(panel);
            panel.classList.remove('active-view');
        }
        const btn = el('nav-' + k);
        if(btn) btn.classList.remove('active');
    });

    const panelToShow = el(`${name}-panel`);
    if(panelToShow) {
        show(panelToShow);
        panelToShow.classList.add('active-view');
    }
    const navBtn = el('nav-' + name);
    if(navBtn) navBtn.classList.add('active');
}


/* -----------------------------
    DATA FETCH & RENDER: Users
----------------------------- */
let usersCache = []; 

function renderUsers(list) {
    const tbody = document.querySelector('#usersTable tbody');
    if (!tbody) return;
    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:12px">NO USERS FOUND</td></tr>';
        return;
    }
    tbody.innerHTML = '';

    list.forEach(u => {
        const tr = document.createElement('tr');
        
        // --- Action Buttons setup (View, Adjust, Freeze, KYC) ---
        const actions = document.createElement('td'); 
        
        // VIEW
        const viewBtn = document.createElement('button');
        viewBtn.className = 'admin-button btn-sm';
        viewBtn.textContent = 'VIEW';
        viewBtn.onclick = () => loadClient(u.user_id); 
        actions.appendChild(viewBtn);

        // ADJUST
        if (CAN_ADJUST_BALANCE) {
            const adjustBtn = document.createElement('button');
            adjustBtn.className = 'admin-button btn-sm';
            adjustBtn.style.marginLeft = '6px';
            adjustBtn.textContent = 'ADJUST';
            adjustBtn.onclick = () => {
                el('searchEmail').value = u.email; 
                fetchUserForAdjustment(); 
                showScreen('users'); 
            };
            actions.appendChild(adjustBtn);
        }

        // FREEZE/DEACTIVATE (Based on users.status: 'active'/'inactive')
        const freezeBtn = document.createElement('button');
        freezeBtn.className = 'admin-button btn-sm';
        freezeBtn.style.marginLeft = '6px';
        const isActive = u.status === 'active';
        freezeBtn.textContent = isActive ? 'DEACTIVATE' : 'ACTIVATE';
        
        freezeBtn.onclick = () => vogueConfirm(`ARE YOU SURE YOU WANT TO ${isActive ? 'DEACTIVATE' : 'ACTIVATE'} ${escapeHtml(u.full_name)}?`, (confirmed) => {
            if (confirmed) toggleFreeze(u.user_id, isActive ? 'inactive' : 'active'); // Send string status
        });
        actions.appendChild(freezeBtn);

        // KYC (Based on users.kyc_status: 'pending','approved','rejected')
        const kycBtn = document.createElement('button');
        kycBtn.className = 'admin-button btn-sm';
        kycBtn.style.marginLeft = '6px';
        const isApproved = u.kyc_status === 'approved';
        kycBtn.textContent = isApproved ? 'KYC OK' : 'APPROVE KYC';
        
        if (!isApproved) {
            kycBtn.onclick = () => vogueConfirm(`APPROVE KYC FOR ${escapeHtml(u.full_name)}?`, (confirmed) => {
                if (confirmed) approveKyc(u.user_id);
            });
        } else {
            kycBtn.disabled = true;
            kycBtn.style.opacity = '0.5';
            kycBtn.style.cursor = 'not-allowed';
        }
        actions.appendChild(kycBtn);
        
        // --- End Action Buttons ---

        const balanceColor = Number(u.balance) < 0 ? '#b91c1c' : '#15803d';
        
        tr.innerHTML = `
            <td>${shortId(u.user_id)}</td>
            <td>${escapeHtml(u.full_name)}</td>
            <td>${escapeHtml(u.email)}</td>
            <td>${escapeHtml(u.account_number || 'N/A')}</td>
            <td style="font-weight:700; color:${balanceColor}">${formatCurrency(u.balance)}</td>
        `;
        tr.appendChild(actions);
        tbody.appendChild(tr);
    });

    if (typeof lucide !== 'undefined') { lucide.createIcons(); }
}

async function fetchUsers() {
    const tbody = document.querySelector('#usersTable tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:12px">LOADING USERS...</td></tr>';
    // fetchUsers uses the default 'admin' root
    const result = await fetchData('admin_get_users', 'GET'); 

    if (result && Array.isArray(result.users)) {
        usersCache = result.users; 
        renderUsers(usersCache);
    } else {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-6 text-red-500">${result?.message || 'Failed to load users.'}</td></tr>`;
    }
}


/* -----------------------------
    DATA FETCH & RENDER: Transactions
----------------------------- */
let transactionsCache = [];
async function fetchTransactions(){
    const tbody = document.querySelector('#txTable tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:12px">LOADING TRANSACTIONS...</td></tr>';
    
    // fetchTransactions uses the default 'admin' root
    const json = await fetchData('admin_get_transactions', 'GET');

    if(!json || json.status === 'error'){ 
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:12px">${json?.message||'FAILED TO LOAD TRANSACTIONS'}</td></tr>`; 
        showGlobal(json?.message||'FAILED TO LOAD TRANSACTIONS', false); 
        return; 
    }
    transactionsCache = json.transactions || [];
    renderTransactions(transactionsCache);
}

function renderTransactions(list){
    const tbody = document.querySelector('#txTable tbody');
    if(!tbody) return;
    if(!list || list.length === 0){ tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:12px">NO TRANSACTIONS FOUND</td></tr>'; return; }
    tbody.innerHTML = '';
    
    list.forEach(t => {
        const tr = document.createElement('tr');
        const statusColor = t.status === 'completed' ? '#15803d' : (t.status === 'pending' ? '#d97706' : '#b91c1c');

        tr.innerHTML = `
            <td>${shortId(t.transaction_id)}</td>
            <td>${escapeHtml(t.user_name || 'N/A')}</td>
            <td>${escapeHtml(t.from_account)}</td>
            <td>${escapeHtml(t.to_account)}</td>
            <td>${escapeHtml(t.external_bank_name || 'N/A')}</td>
            <td style="font-weight:700;">${formatCurrency(t.amount)}</td>
            <td>${formatCurrency(t.fee_amount || 0)}</td> 
            <td>${escapeHtml(t.type).toUpperCase()}</td>
            <td style="color:${statusColor}; font-weight:700;">${escapeHtml(t.status).toUpperCase()}</td>
            <td>${escapeHtml(t.created_at || 'N/A')}</td>
        `;
        tbody.appendChild(tr);
    });
}

/* -----------------------------
    DATA FETCH & RENDER: Audit
----------------------------- */
async function fetchAudit(){
    const container = el('auditContainer');
    if (!container) return;
    container.innerHTML = '<div class="mini">LOADING AUDIT LOGS...</div>';
    
    // fetchAudit uses the default 'admin' root
    const json = await fetchData('admin_get_audit', 'GET');

    if(!json || json.status === 'error'){ 
        container.innerHTML = `<div class="mini" style="color:#b91c1c;">${json?.message||'FAILED TO LOAD AUDIT LOGS'}</div>`; 
        showGlobal(json?.message||'FAILED TO LOAD AUDIT LOGS', false); 
        return; 
    }
    renderAudit(json.logs || []);
}

function renderAudit(logs){
    const container = el('auditContainer');
    if(!container) return;
    if(!logs || logs.length === 0){ container.innerHTML = '<div class="mini">NO AUDIT LOGS FOUND</div>'; return; }

    let html = logs.map(log => {
        const timestamp = escapeHtml(log.timestamp || 'N/A');
        const staff = escapeHtml(log.staff_username || 'SYSTEM');
        const action = escapeHtml(log.action || 'UNKNOWN');
        const details = escapeHtml(log.details || '');
        const style = log.action.includes('LOGIN') ? 'font-weight:700; color:#A9996F;' : '';

        return `<div style="border-bottom: 1px dotted #ccc; padding: 6px 0; font-size: 0.7rem; ${style}">
            [${timestamp}] <strong>${staff}</strong>: ${action} - <span class="mini" style="font-weight:400; color:#333;">${details}</span>
        </div>`;
    }).join('');

    container.innerHTML = html;
}

/* -----------------------------
    FETCH USER for Balance Adjustment
----------------------------- */
let currentFetchedUser = null; 

async function fetchUserForAdjustment(){
    const email = el('searchEmail').value.trim();
    const infoBox = el('fetchedUserInfo');
    const adjustForm = el('adjustForm');
    const responseMsg = el("responseMsg");
    const accountSelect = el('accountSelect');

    hide(infoBox);
    hide(adjustForm);
    setText('responseMsg', 'Searching...');
    responseMsg.style.color = "black";
    el('fetchUserBtn').disabled = true;

    if(!email) {
        setText('responseMsg', 'Please enter a user email.');
        el('fetchUserBtn').disabled = false;
        return;
    }

    try {
        // fetchUserForAdjustment uses the default 'admin' root
        const json = await fetchData('admin_get_single_users', 'POST', { email: email });

        if (json.status === 'success' && json.user && json.user.accounts) {
            currentFetchedUser = json.user;

            // Display user info (using status from the user table)
            setHTML('fetchedUserInfo', `
                <div style="font-weight:700;">USER: ${escapeHtml(json.user.full_name)} (${escapeHtml(json.user.email)})</div>
                <div>ID: ${shortId(json.user.user_id)} | Status: <strong>${json.user.status.toUpperCase()}</strong></div>
            `);
            show(infoBox);
            
            // Populate account dropdown (using account_type from the accounts table)
            accountSelect.innerHTML = '<option value="">-- SELECT ACCOUNT --</option>';
            json.user.accounts.forEach(acc => {
                const isFrozenText = acc.is_frozen == 1 ? ' (FROZEN)' : '';
                const option = document.createElement('option');
                option.value = acc.account_id; 
                option.textContent = `${escapeHtml(acc.account_type).toUpperCase()} | Balance: ${formatCurrency(acc.balance)} | Account: ${escapeHtml(acc.account_number)}${isFrozenText}`;
                accountSelect.appendChild(option);
            });
            show(adjustForm, 'flex');
            setText('responseMsg', 'User and accounts loaded. Ready for adjustment.');
            responseMsg.style.color = "green";

        } else {
            currentFetchedUser = null;
            setText('responseMsg', json.message || 'User not found or failed to load data.');
            responseMsg.style.color = "red";
        }

    } catch (e) {
        console.error(e);
        setText('responseMsg', 'Network error during user fetch.');
        responseMsg.style.color = "red";
    } finally {
        el('fetchUserBtn').disabled = false;
    }
}


/* -----------------------------
    BALANCE ADJUSTMENT
----------------------------- */
async function handleBalanceAdjustment(e){
    e.preventDefault();

    if(!currentFetchedUser) {
        setText('responseMsg', 'Error: Please fetch a user first.');
        return;
    }

    const account_id = el('accountSelect').value.trim(); 
    const amount = parseFloat(el('amount').value.trim());
    const operation = el('operation').value.trim(); 
    const reason = el('reason').value.trim();

    const responseMsg = el("responseMsg");
    responseMsg.textContent = "Processing...";
    responseMsg.style.color = "black";
    el('submitAdjustBtn').disabled = true;

    if (!account_id || isNaN(amount) || amount <= 0 || !operation || !reason) {
        setText('responseMsg', 'All fields (Account, Amount, Type, Reason) are required and amount must be positive.');
        responseMsg.style.color = "red";
        el('submitAdjustBtn').disabled = false;
        return;
    }
    
    vogueConfirm(`APPLY ${operation.toUpperCase()} of ${formatCurrency(amount)} to account ${account_id} for user ${currentFetchedUser.full_name}?`, async (confirmed) => {
        if (!confirmed) {
            setText('responseMsg', 'Adjustment cancelled by user.');
            responseMsg.style.color = "red";
            el('submitAdjustBtn').disabled = false;
            return;
        }

        const payload = {
            account_id: account_id,
            user_id: currentFetchedUser.user_id, 
            amount: amount,
            operation: operation,
            reason: reason,
        };

        try {
            // handleBalanceAdjustment uses the default 'admin' root
            const data = await fetchData('admin_adjust_balance', 'POST', payload);

            if (data.status === "success") {
                showGlobal(data.message || "Balance updated successfully!", true);
                
                fetchUsers(); 
                fetchUserForAdjustment();
                
                el('amount').value = ''; 
                el('reason').value = ''; 
                setText('responseMsg', 'Adjustment successful. Balance updated.');
                responseMsg.style.color = "green";
            } else {
                responseMsg.style.color = "red";
                setText('responseMsg', data.message || "Adjustment failed.");
            }
        } catch (error) {
            console.error(error);
            responseMsg.style.color = "red";
            setText('responseMsg', "Network Error: Could not reach adjustment endpoint.");
        } finally {
            el('submitAdjustBtn').disabled = false;
        }
    });
}

/* -----------------------------
    STAFF MANAGEMENT (FIXED PATH)
----------------------------- */
async function handleAddStaff(e){
    e.preventDefault();
    const staffResponseMsg = el('staffResponseMsg');
    const form = e.target;
    
    const staffName = el('staffName').value.trim();
    const staffUsername = el('staffUsername').value.trim();
    const staffPassword = el('staffPassword').value;
    const staffRole = el('staffRole').value.trim();

    staffResponseMsg.textContent = "Processing...";
    staffResponseMsg.style.color = "black";
    form.querySelector('button[type="submit"]').disabled = true;

    if (!staffName || !staffUsername || staffPassword.length < 8 || !staffRole) {
        staffResponseMsg.textContent = 'All fields required. Password must be at least 8 characters.';
        staffResponseMsg.style.color = "red";
        form.querySelector('button[type="submit"]').disabled = false;
        return;
    }

    const payload = {
        full_name: staffName,
        username: staffUsername,
        password: staffPassword,
        role: staffRole
    };
    
    try {
        // *** FIX APPLIED: Uses 'auth' root and 'add_staff' endpoint ***
        const data = await fetchData('add_staff', 'POST', payload, '', 'auth');

        if (data.status === "success") {
            showGlobal(data.message || `Staff ${staffUsername} added successfully!`, true);
            staffResponseMsg.textContent = `Staff added. Temporary password: ${staffPassword}`;
            form.reset();
        } else {
            staffResponseMsg.style.color = "red";
            staffResponseMsg.textContent = data.message || "Failed to add staff member.";
        }
    } catch (error) {
        console.error(error);
        staffResponseMsg.style.color = "red";
        staffResponseMsg.textContent = "Network Error: Could not process request.";
    } finally {
        form.querySelector('button[type="submit"]').disabled = false;
    }
}


/* -----------------------------
    OTHER ACTIONS (Deactivate/Activate, KYC, Client View)
----------------------------- */
async function toggleFreeze(userId, newStatus){
    // toggleFreeze uses the default 'admin' root
    const action = newStatus === 'active' ? 'ACTIVATING' : 'DEACTIVATING';
    const json = await fetchData('admin_toggle_freeze', 'POST', { userId, newStatus });
    if(json.status === 'success'){ showGlobal(json.message || `User ${shortId(userId)} ${action} successful.`, true); fetchUsers(); }
    else showGlobal(json.message || `Failed to ${action} user.`, false);
}

async function approveKyc(userId){
    // approveKyc uses the default 'admin' root
    const json = await fetchData('admin_approve_kyc', 'POST', { userId });
    if(json.status === 'success'){ showGlobal(json.message || 'KYC Approved', true); fetchUsers(); }
    else showGlobal(json.message || 'Failed to approve KYC', false);
}

async function loadClient(userId){
    // loadClient uses the default 'admin' root
    const json = await fetchData('admin_get_client', 'GET', null, `?user_id=${encodeURIComponent(userId)}`);

    if(!json || json.status === 'error'){ vogueAlert(json?.message||'Failed to load client', 'ERROR'); return; }

    const client = json.client;
    const content = `<div style="font-weight:700; font-size:1rem; margin-bottom:4px;">${escapeHtml(client.full_name)}</div>
                 <div class="mini">${escapeHtml(client.email)}</div>
                 <div style="margin-top:12px; border-top:1px solid #eee; padding-top:8px;">
                     <div>ACCOUNT: <strong>${escapeHtml(client.account_number||'N/A')}</strong></div>
                     <div>BALANCE: <strong>${formatCurrency(client.balance)}</strong></div>
                     <div>STATUS: <strong>${client.status.toUpperCase()}</strong></div>
                     <div>KYC: <strong>${escapeHtml(client.kyc_status||'PENDING')}</strong></div>
                 </div>`;

    vogueAlert(content, 'CLIENT DETAILS'); 
}


/* -----------------------------
    LOGOUT (FIXED PATH)
----------------------------- */
async function logout(){
    vogueConfirm("Are you sure you want to log out of the SACCUSSALIS Admin Panel?", async (confirmed) => {
        if (!confirmed) return;
        try{
            // *** FIX APPLIED: Uses 'auth' root and 'admin_logout' endpoint ***
            await fetchData('admin_logout', 'POST', null, '', 'auth');
            localStorage.removeItem("auth_token"); 
        }catch(e){
            console.warn("Logout fetch failed, but proceeding with redirect anyway.", e);
        }
        window.location.href = '../admin/admin_login.php';
    });
}

/* -----------------------------
    INITIAL LOAD & EVENT BINDINGS
----------------------------- */
document.addEventListener('DOMContentLoaded', ()=>{
    // Initial screen selection
    let initialScreen = 'users'; 
    if (el('nav-users')) initialScreen = 'users';
    else if (el('nav-transactions')) initialScreen = 'transactions';
    else if (el('nav-audit')) initialScreen = 'audit';
    else if (el('nav-staff')) initialScreen = 'staff';
    
    showScreen(initialScreen);

    // Lucide initialization (safe for old/new API)
    const initLucide = () => { 
        if(typeof lucide !== 'undefined') {
            if (typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            } else if (typeof lucide.replace === 'function') {
                lucide.replace();
            }
        }
    };
    initLucide(); 
    setTimeout(initLucide, 100);

    // Initial data fetches (only if the panel/table exists)
    if(el('usersTable')) fetchUsers();
    if(el('txTable')) fetchTransactions();
    if(el('auditContainer')) fetchAudit();

    // Bind refresh buttons
    el('refreshUsers')?.addEventListener('click', fetchUsers);
    el('refreshTxns')?.addEventListener('click', fetchTransactions);
    el('refreshAudit')?.addEventListener('click', fetchAudit);

    // Bind search filters (using simple inline logic)
    el('usersSearch')?.addEventListener('input', function(){ 
        const q=this.value.toLowerCase().trim(); 
        if(!q) renderUsers(usersCache); 
        else renderUsers(usersCache.filter(u=> 
            (u.full_name||'').toLowerCase().includes(q) || 
            (u.email||'').toLowerCase().includes(q) || 
            (u.account_number||'').toLowerCase().includes(q) 
        )); 
    });

    el('txSearch')?.addEventListener('input', function(){ 
        const q=this.value.toLowerCase().trim(); 
        if(!q) renderTransactions(transactionsCache);
        else renderTransactions(transactionsCache.filter(t=> 
            (t.transaction_id||'').toLowerCase().includes(q) || 
            (t.from_account||'').toLowerCase().includes(q) || 
            (t.to_account||'').toLowerCase().includes(q) || 
            (t.user_name||'').toLowerCase().includes(q) 
        )); 
    });

    // Bind Balance Adjustment Form
    el('fetchUserBtn')?.addEventListener('click', fetchUserForAdjustment);
    el('adjustForm')?.addEventListener('submit', handleBalanceAdjustment); 

    // Bind Staff Management Form
    el('addStaffForm')?.addEventListener('submit', handleAddStaff);
});
</script>
</body>
</html>