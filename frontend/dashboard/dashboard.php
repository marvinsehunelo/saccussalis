<?php
// Errors are logged, never printed: printing them corrupted the JSON the
// page fetches. (This replaces error_reporting(0), which hid the cause.)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['authToken'])) {
    header("Location: ../public/login.php");
    exit;
}

$token = $_SESSION['authToken'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Saccussalis Private Bank - Control Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:wght@400;700&display=swap" rel="stylesheet">
<style>
    /* ------------------------------------ */
    /* 1. BLACK & CREAM WHITE PALETTE */
    /* ------------------------------------ */
    :root {
        --color-bg-primary: #FFFAF0;
        --color-bg-secondary: #FFFFFF;
        --color-fg-primary: #000000;
        --color-fg-secondary: #444444;
        --color-accent: #000000;
        --color-border-subtle: #E8E8E8;
        --color-positive: #008000;
        --color-negative: #CC0000;
        --color-held: #B8860B;
        --shadow-sharp: 4px 4px 0 #000000;
        --font-serif: 'Libre Baskerville', serif;
    }

    body {
        margin: 0;
        font-family: var(--font-serif);
        background: var(--color-bg-primary);
        color: var(--color-fg-primary);
        line-height: 1.6;
        padding-top: 20px;
    }

    .dashboard-container h1, .card h2, .summary-box h2, .vogue-nav button, .vogue-button {
        font-family: var(--font-serif);
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .dashboard-container h1, .card h2, .summary-box h2, .vogue-nav button.active, .role-badge, .vogue-button {
        text-transform: uppercase;
    }

    .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 15px; }

    header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 3px solid var(--color-accent);
        padding-bottom: 10px;
    }

    header h1 { font-size: 28px; margin: 0; font-weight: 700; color: var(--color-fg-primary); }

    .role-badge {
        font-size: 11px; padding: 3px 8px;
        background: var(--color-accent); color: var(--color-bg-primary);
        text-transform: uppercase; font-weight: 700; border-radius: 0;
        border: 1px solid var(--color-accent); display: inline-block; margin-right: 10px;
    }

    .phone-badge {
        font-size: 11px; padding: 3px 8px; background: none;
        color: var(--color-fg-primary); font-weight: 700; border-radius: 0;
        border: 1px solid var(--color-fg-secondary); display: inline-block; margin-right: 10px;
    }

    .summary-box {
        background: var(--color-accent); color: var(--color-bg-primary);
        padding: 20px 30px; margin-bottom: 0; box-shadow: var(--shadow-sharp); border-radius: 0;
    }

    .summary-box h2 { margin: 0 0 5px 0; font-size: 14px; font-weight: 400; color: var(--color-border-subtle); }
    .summary-box p { margin: 0; font-size: 40px; font-weight: 700; color: var(--color-bg-primary); }
    .summary-box .sub { font-size: 13px; color: var(--color-border-subtle); font-style: italic; }

    /* Balance strip: accounts · wallets · held · available */
    .balance-strip {
        display: grid; grid-template-columns: repeat(4, 1fr);
        border: 1px solid var(--color-accent); border-top: 0;
        background: var(--color-bg-secondary); box-shadow: var(--shadow-sharp);
        margin-bottom: 20px;
    }
    .balance-strip div { padding: 12px 18px; border-right: 1px solid var(--color-border-subtle); }
    .balance-strip div:last-child { border-right: 0; }
    .balance-strip span {
        display: block; font-size: 11px; letter-spacing: 1px;
        text-transform: uppercase; color: var(--color-fg-secondary);
    }
    .balance-strip b { font-size: 22px; font-weight: 700; }
    .balance-strip b.held { color: var(--color-held); }
    .balance-strip b.available { color: var(--color-positive); }

    .vogue-button {
        padding: 10px 20px; background: var(--color-accent); color: var(--color-bg-primary);
        border: 2px solid var(--color-accent); cursor: pointer; font-weight: 700; font-size: 14px;
        border-radius: 0; text-transform: uppercase; transition: all 0.2s; margin-left: 8px;
    }
    .vogue-button:hover { background: var(--color-bg-primary); border-color: var(--color-accent); color: var(--color-accent); }
    .vogue-button.secondary { background: none; color: var(--color-fg-primary); border: 2px solid var(--color-fg-primary); }
    .vogue-button.secondary:hover { background: var(--color-accent); color: var(--color-bg-primary); }

    .vogue-nav {
        display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; padding: 10px;
        background: var(--color-bg-secondary); box-shadow: var(--shadow-sharp);
        overflow-x: auto; border: 1px solid var(--color-accent);
    }
    .vogue-nav button {
        padding: 8px 14px; background: none; color: var(--color-fg-secondary);
        border: 1px solid transparent; font-weight: 400; text-transform: capitalize;
        transition: all 0.2s; cursor: pointer; border-radius: 0; flex-shrink: 0;
    }
    .vogue-nav button.active {
        color: var(--color-bg-primary); background-color: var(--color-accent);
        border: 2px solid var(--color-accent); font-weight: 700; text-transform: uppercase;
    }
    .vogue-nav button:not(.active):hover { background-color: var(--color-border-subtle); color: var(--color-fg-primary); }

    .main-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

    .card {
        background: var(--color-bg-secondary); padding: 25px; box-shadow: var(--shadow-sharp);
        border-radius: 0; border: 1px solid var(--color-accent);
    }
    .card h2 {
        margin: 0 0 10px 0; font-size: 20px; font-weight: 700;
        border-bottom: 2px solid var(--color-accent); padding-bottom: 5px;
    }
    .card .card-note { font-size: 12px; color: var(--color-fg-secondary); font-style: italic; margin: -4px 0 10px; }

    .list-item {
        display: flex; justify-content: space-between; padding: 10px 0;
        font-size: 15px; border-bottom: 1px dashed var(--color-border-subtle);
    }
    .list-item:hover { background-color: var(--color-border-subtle); }

    .muted-text { color: var(--color-fg-secondary); font-size: 13px; font-style: italic; }
    .amount-positive { color: var(--color-positive); font-weight: 700; }
    .amount-negative { color: var(--color-negative); font-weight: 700; }
    .amount-held { color: var(--color-held); font-weight: 700; }

    /* Accounts and wallets: balance · held · available */
    .pot-row, .pot-header {
        display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 10px;
        padding: 10px 0; align-items: center; border-bottom: 1px dashed var(--color-border-subtle);
    }
    .pot-header { font-weight: 700; text-transform: uppercase; font-size: 12px;
        letter-spacing: 1px; border-bottom: 2px solid var(--color-accent); }
    .pot-row:hover { background-color: var(--color-border-subtle); }

    /* Where your money is */
    .where-row, .where-header {
        display: grid; grid-template-columns: 1.4fr 1.2fr 1fr 0.9fr 1.5fr; gap: 10px;
        padding: 12px 0; align-items: center; border-bottom: 1px dashed var(--color-border-subtle);
    }
    .where-header { font-weight: 700; text-transform: uppercase; font-size: 12px;
        letter-spacing: 1px; border-bottom: 2px solid var(--color-accent); }

    .tag {
        display: inline-block; padding: 3px 10px; font-size: 11px; font-weight: 700;
        text-transform: uppercase; border: 1px solid var(--color-accent); text-align: center;
    }
    .tag.free { color: var(--color-positive); border-color: var(--color-positive); }
    .tag.held { color: var(--color-held); border-color: var(--color-held); }
    .tag.out  { color: var(--color-negative); border-color: var(--color-negative); }
    .tag.in   { color: #0066CC; border-color: #0066CC; }

    /* E-Wallet */
    .ewallet-item, .ewallet-header {
        display: grid; grid-template-columns: 0.8fr 1.3fr 1fr 1fr 1.2fr; gap: 10px;
        align-items: center; padding: 12px 0; border-bottom: 1px dashed var(--color-border-subtle);
    }
    .ewallet-header { font-weight: 700; border-bottom: 2px solid var(--color-accent);
        text-transform: uppercase; font-size: 12px; letter-spacing: 1px; }
    .ewallet-item:hover { background-color: var(--color-border-subtle); }
    .ewallet-info { display: flex; flex-direction: column; gap: 2px; }
    .ewallet-phone { font-weight: 700; font-size: 16px; }
    .ewallet-pin { font-size: 18px; font-family: monospace; letter-spacing: 3px; font-weight: 700; }
    .ewallet-status { padding: 4px 12px; font-size: 12px; font-weight: 700; text-transform: uppercase;
        border: 1px solid var(--color-accent); text-align: center; display: inline-block; justify-self: start; }
    .status-active   { color: var(--color-positive); border-color: var(--color-positive); }
    .status-inactive { color: var(--color-negative); border-color: var(--color-negative); }
    .status-pending  { color: #FF8C00; border-color: #FF8C00; }
    .status-redeemed { color: #0066CC; border-color: #0066CC; }

    .form-group label {
        display: block; font-size: 14px; font-weight: 700; margin-bottom: 5px;
        color: var(--color-fg-primary); text-transform: capitalize;
    }
    .form-group input, .form-group select, .vogue-message-box {
        width: 100%; padding: 10px; margin-bottom: 15px;
        border: 1px solid var(--color-fg-secondary); background: var(--color-bg-secondary);
        color: var(--color-fg-primary); font-size: 16px; box-sizing: border-box; border-radius: 0;
    }

    .pin-box {
        background: var(--color-accent); border: 2px solid var(--color-fg-primary);
        padding: 15px; margin-top: 15px; text-align: center; font-size: 18px;
        font-weight: 700; color: var(--color-bg-primary); border-radius: 0;
    }
    .pin-number { font-size: 28px; color: var(--color-bg-primary); display: block; margin-top: 5px; font-family: var(--font-serif); }

    .statement-section {
        grid-column: 1 / -1; margin-top: 20px; padding: 20px;
        background: var(--color-bg-secondary); border: 1px solid var(--color-accent);
        box-shadow: var(--shadow-sharp); border-radius: 0;
    }
    .statement-section h3 {
        color: var(--color-fg-primary); border-bottom: 2px solid var(--color-accent);
        padding-bottom: 5px; margin-top: 0; font-family: var(--font-serif);
        text-transform: uppercase; font-size: 20px;
    }

    @media (max-width: 768px) {
        .main-grid { grid-template-columns: 1fr; gap: 15px; }
        .summary-box p { font-size: 30px; }
        .balance-strip { grid-template-columns: 1fr 1fr; }
        .ewallet-item, .ewallet-header, .pot-row, .pot-header, .where-row, .where-header {
            grid-template-columns: 1fr; gap: 5px;
        }
        .ewallet-status { justify-self: start; }
        .pot-header, .where-header, .ewallet-header { display: none; }
    }
</style>
</head>
<body>
<div class="dashboard-container">
    <header>
        <h1 id="username">SACCUSSALIS PRIVATE BANK</h1>
        <div class="header-actions">
            <span class="role-badge" id="userRole">CLIENT</span>
            <span class="phone-badge" id="userPhone">Phone: --</span>
            <button class="vogue-button secondary" onclick="logout()">Logout</button>
        </div>
    </header>

    <div class="summary-box">
        <h2>Available to spend</h2>
        <p>P<span id="availableBalance">0.00</span></p>
        <div class="sub">Total held at this bank: P<span id="totalBalance">0.00</span></div>
    </div>

    <div class="balance-strip">
        <div><span>Accounts</span><b id="stripAccounts">P0.00</b></div>
        <div><span>Wallets</span><b id="stripWallets">P0.00</b></div>
        <div><span>Held</span><b class="held" id="stripHeld">P0.00</b></div>
        <div><span>Available</span><b class="available" id="stripAvailable">P0.00</b></div>
    </div>

    <nav class="vogue-nav">
        <button id="nav-dashboard" class="active" onclick="showScreen('dashboard')">Dashboard</button>
        <button id="nav-ownTransfer" onclick="showScreen('ownTransfer')">Account to Account</button>
        <button id="nav-ewalletTransfer" onclick="showScreen('ewalletTransfer')">E-Wallet (Cardless Cash)</button>
        <button id="nav-internalTransfer" onclick="showScreen('internalTransfer')">Same-Bank User</button>
        <button id="nav-externalTransfer" onclick="showScreen('externalTransfer')">External Transfer</button>
    </nav>

    <div id="content-area">
        <div id="dashboard-view" class="main-grid screen-view">

            <div class="card accounts">
                <h2>Your Accounts</h2>
                <div id="accountsList"></div>
            </div>

            <div class="card wallets">
                <h2>Your Wallets</h2>
                <div class="card-note">Mobile wallets held against your phone number.</div>
                <div id="walletsList"></div>
            </div>

            <div class="card where" style="grid-column: 1 / -1; margin-top: 5px;">
                <h2>Where Your Money Is</h2>
                <div class="card-note">Every pot of money, which institution is holding it, and where it is going.</div>
                <div id="whereList"></div>
            </div>

            <div class="card transactions">
                <h2>Recent Transactions</h2>
                <div id="transactionsList"></div>
            </div>

            <div class="card wallet">
                <h2>Pending Wallet Transactions</h2>
                <div id="walletList"></div>
            </div>

            <div class="card ewallet-management" style="grid-column: 1 / -1; margin-top: 5px;">
                <h2>E-Wallet Management</h2>
                <div class="card-note">Cardless cash you have sent, and cash sent to you.</div>
                <div id="ewalletList"></div>
            </div>
        </div>

        <div id="ownTransfer-view" class="card screen-view" style="display: none; max-width: 600px; margin: 0 auto;">
            <h2>Transfer Between My Accounts</h2>
            <form id="ownTransferForm" onsubmit="handleTransfer(event, 'ownTransfer')">
                <div class="form-group">
                    <label for="ownTransferSource">From Account</label>
                    <select id="ownTransferSource" required></select>
                </div>
                <div class="form-group">
                    <label for="ownTransferDestination">To Account</label>
                    <select id="ownTransferDestination" required></select>
                </div>
                <div class="form-group">
                    <label for="ownTransferAmount">Amount (BWP)</label>
                    <input type="number" id="ownTransferAmount" step="0.01" min="0.01" required>
                </div>
                <button type="submit" class="vogue-button" id="ownTransferBtn">Confirm Transfer</button>
                <div id="ownTransferMessage" class="vogue-message-box" style="display: none;"></div>
            </form>
        </div>

        <div id="ewalletTransfer-view" class="card screen-view" style="display: none; max-width: 600px; margin: 0 auto;">
            <h2>E-Wallet (Cardless Cash) Transfer</h2>
            <p class="muted-text" style="margin-bottom: 20px;">Send cash instantly to a mobile number for ATM or agent redemption using a secure PIN.</p>
            <form id="ewalletTransferForm" onsubmit="handleTransfer(event, 'ewalletTransfer')">
                <div class="form-group">
                    <label for="ewalletTransferSource">Source Account</label>
                    <select id="ewalletTransferSource" required></select>
                </div>
                <div class="form-group">
                    <label for="ewalletRecipientPhone">Recipient Mobile Number</label>
                    <input type="tel" id="ewalletRecipientPhone" placeholder="e.g., +267 71 234 567" required>
                </div>
                <div class="form-group">
                    <label for="ewalletTransferAmount">Amount (BWP)</label>
                    <input type="number" id="ewalletTransferAmount" step="0.01" min="0.01" required>
                </div>
                <button type="submit" class="vogue-button" id="ewalletTransferBtn">Send Cardless Cash</button>
                <div id="ewalletTransferMessage" class="vogue-message-box" style="display: none;"></div>
            </form>
        </div>

        <div id="internalTransfer-view" class="card screen-view" style="display: none; max-width: 600px; margin: 0 auto;">
            <h2>Transfer to Same-Bank User</h2>
            <form id="internalTransferForm" onsubmit="handleTransfer(event, 'internalTransfer')">
                <div class="form-group">
                    <label for="internalTransferSource">From Account</label>
                    <select id="internalTransferSource" required></select>
                </div>
                <div class="form-group">
                    <label for="internalRecipientAccount">Recipient Account Number (Internal)</label>
                    <input type="text" id="internalRecipientAccount" placeholder="e.g., 9876543210" required>
                </div>
                <div class="form-group">
                    <label for="internalTransferAmount">Amount (BWP)</label>
                    <input type="number" id="internalTransferAmount" step="0.01" min="0.01" required>
                </div>
                <button type="submit" class="vogue-button" id="internalTransferBtn">Confirm Transfer</button>
                <div id="internalTransferMessage" class="vogue-message-box" style="display: none;"></div>
            </form>
        </div>

        <div id="externalTransfer-view" class="card screen-view" style="display: none; max-width: 600px; margin: 0 auto;">
            <h2>Transfer to Other Bank</h2>
            <form id="externalTransferForm">
                <div class="form-group">
                    <label for="externalTransferSource">From Account</label>
                    <select id="externalTransferSource" required></select>
                </div>
                <div class="form-group">
                    <label for="externalRecipientBank">Recipient Bank Name</label>
                    <input type="text" id="externalRecipientBank" placeholder="e.g., Global Finance Corp" required>
                </div>
                <div class="form-group">
                    <label for="externalRecipientAccount">Recipient Account Number</label>
                    <input type="text" id="externalRecipientAccount" placeholder="e.g., ABA/SWIFT/IBAN" required>
                </div>
                <div class="form-group">
                    <label for="externalTransferAmount">Amount (BWP)</label>
                    <input type="number" id="externalTransferAmount" step="0.01" min="0.01" required>
                </div>
                <button type="submit" class="vogue-button" id="externalTransferBtn">Confirm Transfer</button>
                <div id="externalTransferMessage" class="vogue-message-box" style="display: none;"></div>
                <div id="externalTransferPIN" class="vogue-message-box" style="display: none;"></div>
            </form>
        </div>
    </div>

    <div class="statement-section">
        <h3>Download Bank Statement</h3>
        <input type="date" id="startDate">
        <input type="date" id="endDate">
        <button onclick="downloadBankStatement()" class="vogue-button">Download PDF</button>
    </div>
</div>

<script>
const token = "<?php echo htmlspecialchars($token, ENT_QUOTES); ?>";
const BACKEND_ENDPOINT = '../../backend/accounts/dashboard.php';
const BANK_NAME = 'SaccusSalis Private Bank';

if (!token) { window.location.href = '../public/login.php'; }

// --- Global state ---
let accountData = [];       // accounts, used by the transfer forms
let walletData = [];
let currentView = 'dashboard';

// --- Utilities ---
function parseResponse(res) {
    return res.text().then(text => {
        try { return JSON.parse(text); }
        catch (e) {
            console.error('Response was not JSON. Raw text:', text.slice(0, 400));
            return { status: 'error', message: 'Server did not return valid JSON.' };
        }
    });
}

function money(amount) { return 'P' + (parseFloat(amount) || 0).toFixed(2); }
function formatCurrency(amount) { return (parseFloat(amount) || 0).toFixed(2); }

function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g,
        c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function shortDate(d) {
    if (!d || d === '0000-00-00 00:00:00') return '';
    const t = new Date(d);
    return isNaN(t) ? '' : t.toLocaleDateString();
}

function showMessage(elementId, message, isSuccess, pin = null) {
    const el = document.getElementById(elementId);
    if (!el) { console.warn('showMessage: missing element ' + elementId); return; }
    el.innerHTML = '';
    el.className = 'vogue-message-box ' + (isSuccess ? 'success' : 'error');
    el.style.border = '1px solid ' + (isSuccess ? 'var(--color-positive)' : 'var(--color-negative)');
    el.style.backgroundColor = 'var(--color-bg-primary)';
    el.style.color = isSuccess ? 'var(--color-positive)' : 'var(--color-negative)';

    if (isSuccess && pin) {
        el.innerHTML = escapeHtml(message) +
            '<div class="pin-box"><span class="muted-text" style="font-weight:400;font-size:13px;color:var(--color-bg-primary)">ONE-TIME PIN:</span>' +
            '<span class="pin-number">' + escapeHtml(pin) + '</span></div>' +
            '<p class="muted-text" style="margin-top:5px">The recipient needs this PIN to withdraw the cash.</p>';
    } else {
        el.textContent = message;
    }
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, isSuccess && pin ? 15000 : 6000);
}

function safeShowMessage(elementId, message, isSuccess) { showMessage(elementId, message, isSuccess); }

// --- Screens ---
function showScreen(view) {
    currentView = view;
    document.querySelectorAll('.screen-view').forEach(el => el.style.display = 'none');
    const target = document.getElementById(view + '-view');
    if (target) target.style.display = view === 'dashboard' ? 'grid' : 'block';
    document.querySelectorAll('.vogue-nav button').forEach(btn => btn.classList.remove('active'));
    const nav = document.getElementById('nav-' + view);
    if (nav) nav.classList.add('active');
    if (view !== 'dashboard') populateSourceDropdowns(view);
}

function populateSourceDropdowns(view) {
    const sourceSelect = document.getElementById(view + 'Source');
    if (!sourceSelect) return;
    sourceSelect.innerHTML = '<option value="">Select Account</option>';

    const destSelect = (view === 'ownTransfer') ? document.getElementById(view + 'Destination') : null;
    if (destSelect) destSelect.innerHTML = '<option value="">Select Destination</option>';

    (accountData || []).forEach(acc => {
        const option = document.createElement('option');
        option.value = acc.account_number;
        // spendable, not gross: held money cannot be transferred
        option.textContent = (acc.account_type || '').toUpperCase() +
            ' (Available: ' + money(acc.available !== undefined ? acc.available : acc.balance) + ')';
        sourceSelect.appendChild(option);
    });

    if (destSelect) {
        sourceSelect.onchange = function () {
            destSelect.innerHTML = '<option value="">Select Destination</option>';
            const selected = this.value;
            accountData.forEach(acc => {
                if (acc.account_number !== selected) {
                    const o = document.createElement('option');
                    o.value = acc.account_number;
                    o.textContent = (acc.account_type || '').toUpperCase() +
                        ' (Available: ' + money(acc.available !== undefined ? acc.available : acc.balance) + ')';
                    destSelect.appendChild(o);
                }
            });
        };
        if (sourceSelect.value) sourceSelect.onchange();
    }
}

// --- Data ---
function fetchDashboardData() {
    fetch(BACKEND_ENDPOINT + '?token=' + encodeURIComponent(token) + '&action=fetch_data')
    .then(parseResponse)
    .then(data => {
        if (data.status !== 'success') {
            console.error('Dashboard fetch error:', data.message);
            return;
        }

        accountData = data.accounts || [];
        walletData = data.wallets || [];

        document.getElementById('username').textContent = data.institution || BANK_NAME;
        document.getElementById('userRole').textContent = (data.role || 'client').toUpperCase();
        document.getElementById('userPhone').textContent = 'Phone: ' + (data.userPhone || 'N/A');

        const s = data.balanceSummary || {};
        document.getElementById('availableBalance').textContent = formatCurrency(data.availableBalance);
        document.getElementById('totalBalance').textContent = formatCurrency(data.totalBalance);
        document.getElementById('stripAccounts').textContent = money(s.accounts);
        document.getElementById('stripWallets').textContent = money(s.wallets);
        document.getElementById('stripHeld').textContent = money(s.held);
        document.getElementById('stripAvailable').textContent = money(s.available);

        renderAccounts(data.accounts);
        renderWallets(data.wallets, data.userPhone, data.institution);
        renderWhere(data);
        renderTransactions(data.recentTransactions, 'transactionsList', 'type');
        renderTransactions(data.pendingWalletTransactions, 'walletList', 'transaction_type', true);
        renderEwallets(data.ewallets || []);
        loadExternalTransferSources();
        if (currentView !== 'dashboard') populateSourceDropdowns(currentView);
    })
    .catch(err => console.error('Fetch error:', err));
}

function renderAccounts(accounts) {
    const list = document.getElementById('accountsList');
    list.innerHTML = '';
    if (!accounts || !accounts.length) {
        list.innerHTML = '<div class="muted-text" style="padding:5px 0">No accounts found.</div>';
        return;
    }
    let html = '<div class="pot-header"><span>Account</span><span>Balance</span><span>Held</span><span>Available</span></div>';
    accounts.forEach(acc => {
        const held = parseFloat(acc.held_balance || 0);
        html += '<div class="pot-row">' +
            '<span><b>' + escapeHtml((acc.account_type || 'Account').toUpperCase()) + '</b><br>' +
                '<span class="muted-text">' + escapeHtml(acc.account_number) + '</span></span>' +
            '<span>' + money(acc.balance) + '</span>' +
            '<span class="' + (held > 0 ? 'amount-held' : 'muted-text') + '">' + money(held) + '</span>' +
            '<span class="amount-positive">' + money(acc.available !== undefined ? acc.available : acc.balance) + '</span>' +
            '</div>';
    });
    list.innerHTML = html;
}

function renderWallets(wallets, phone, institution) {
    const list = document.getElementById('walletsList');
    list.innerHTML = '';
    if (!wallets || !wallets.length) {
        list.innerHTML = '<div class="muted-text" style="padding:5px 0">No mobile wallet is linked to ' +
            escapeHtml(phone || 'this profile') + '.</div>';
        return;
    }
    let html = '<div class="pot-header"><span>Wallet</span><span>Balance</span><span>Held</span><span>Available</span></div>';
    wallets.forEach(w => {
        const held = parseFloat(w.held_balance || 0);
        html += '<div class="pot-row">' +
            '<span><b>' + escapeHtml(w.phone || ('Wallet ' + w.wallet_id)) + '</b><br>' +
                '<span class="muted-text">' + escapeHtml(institution || BANK_NAME) +
                (w.status ? ' · ' + escapeHtml(String(w.status).toUpperCase()) : '') + '</span></span>' +
            '<span>' + money(w.balance) + '</span>' +
            '<span class="' + (held > 0 ? 'amount-held' : 'muted-text') + '">' + money(held) + '</span>' +
            '<span class="amount-positive">' + money(w.available !== undefined ? w.available : w.balance) + '</span>' +
            '</div>';
    });
    list.innerHTML = html;
}

// Every pot of money, who holds it, and where it is going
function renderWhere(data) {
    const list = document.getElementById('whereList');
    const bank = data.institution || BANK_NAME;
    const rows = [];

    (data.accounts || []).forEach(a => rows.push({
        what: (a.account_type || 'Account').toUpperCase() + ' · ' + (a.account_number || ''),
        where: bank, kind: 'Account', cls: (a.held_balance > 0 ? 'held' : 'free'),
        amount: a.balance,
        note: a.held_balance > 0 ? money(a.held_balance) + ' of this is held' : 'Fully available'
    }));

    (data.wallets || []).forEach(w => rows.push({
        what: 'WALLET · ' + (w.phone || w.wallet_id),
        where: bank, kind: 'Wallet', cls: (w.held_balance > 0 ? 'held' : 'free'),
        amount: w.balance,
        note: w.held_balance > 0 ? money(w.held_balance) + ' of this is held' : 'Fully available'
    }));

    (data.holds || []).forEach(h => rows.push({
        what: 'HOLD · ' + (h.hold_reference || ''),
        where: h.held_by || bank,
        kind: 'Held' + (h.asset_type ? ' (' + h.asset_type + ')' : ''),
        cls: 'held', amount: h.amount,
        note: 'Going to ' + (h.going_to && h.going_to !== '—' ? h.going_to : 'a destination not yet named') +
              (h.expires_at ? ' · expires ' + shortDate(h.expires_at) : '')
    }));

    (data.ewallets || []).forEach(e => {
        if (e.is_redeemed) return;
        rows.push({
            what: 'CASH ' + e.direction + ' · ' + (e.counterparty || ''),
            where: e.institution || bank,
            kind: e.direction === 'SENT' ? 'Awaiting collection' : 'Waiting for you',
            cls: e.direction === 'SENT' ? 'out' : 'in',
            amount: e.amount,
            note: 'Collect with the PIN at an ATM or agent' +
                  (e.expires_at ? ' · expires ' + shortDate(e.expires_at) : '')
        });
    });

    if (!rows.length) {
        list.innerHTML = '<div class="muted-text" style="padding:10px 0">Nothing to show yet.</div>';
        return;
    }

    let html = '<div class="where-header"><span>What</span><span>Held by</span><span>Type</span><span>Amount</span><span>Note</span></div>';
    rows.forEach(r => {
        html += '<div class="where-row">' +
            '<span><b>' + escapeHtml(r.what) + '</b></span>' +
            '<span>' + escapeHtml(r.where) + '</span>' +
            '<span><span class="tag ' + r.cls + '">' + escapeHtml(r.kind) + '</span></span>' +
            '<span><b>' + money(r.amount) + '</b></span>' +
            '<span class="muted-text">' + escapeHtml(r.note) + '</span>' +
            '</div>';
    });
    list.innerHTML = html;
}

function renderTransactions(transactions, listId, typeField, isPending = false) {
    const list = document.getElementById(listId);
    list.innerHTML = '';
    if (!transactions || !transactions.length) {
        list.innerHTML = '<div class="muted-text" style="padding:5px 0">No ' +
            (isPending ? 'pending' : 'recent') + ' transactions.</div>';
        return;
    }
    transactions.forEach(tx => {
        const amount = parseFloat(tx.amount) || 0;
        const cls = isPending ? 'amount-held' : (amount >= 0 ? 'amount-positive' : 'amount-negative');
        const shown = isPending ? '-' + money(Math.abs(amount))
                                : (amount >= 0 ? '+' + money(amount) : '-' + money(Math.abs(amount)));
        const div = document.createElement('div');
        div.className = 'list-item';
        div.innerHTML = '<span>' + escapeHtml(tx[typeField] || 'Transaction') + '</span>' +
            '<span class="' + cls + '">' + shown +
            ' <span class="muted-text">(' + escapeHtml(shortDate(tx.created_at)) + ')</span></span>';
        list.appendChild(div);
    });
}

function renderEwallets(ewallets) {
    const list = document.getElementById('ewalletList');
    list.innerHTML = '';
    if (!ewallets || !ewallets.length) {
        list.innerHTML = '<div class="muted-text" style="padding:15px 0;text-align:center">No cardless cash activity on your number.</div>';
        return;
    }

    let html = '<div class="ewallet-header"><span>Direction</span><span>Counterparty</span>' +
               '<span>PIN</span><span>Amount</span><span>Status</span></div>';

    ewallets.forEach(w => {
        const isRedeemed = w.is_redeemed === true || w.is_redeemed === 'true' || w.is_redeemed === 1;
        let statusClass = 'status-pending', statusText = 'PENDING';

        if (isRedeemed) {
            statusClass = 'status-redeemed';
            statusText = 'REDEEMED' + (w.redeemed_at ? ' (' + shortDate(w.redeemed_at) + ')' : '');
        } else if (w.hold_status && w.hold_status !== '' && w.hold_status !== 'false') {
            statusClass = 'status-inactive';
            statusText = 'ON HOLD';
        } else if (w.expires_at && new Date(w.expires_at) < new Date()) {
            statusClass = 'status-inactive';
            statusText = 'EXPIRED';
        } else {
            statusClass = 'status-active';
            statusText = 'ACTIVE';
        }

        const dirTag = w.direction === 'RECEIVED'
            ? '<span class="tag in">RECEIVED</span>'
            : '<span class="tag out">SENT</span>';

        html += '<div class="ewallet-item">' +
            '<span>' + dirTag + '</span>' +
            '<div class="ewallet-info">' +
                '<span class="ewallet-phone">' + escapeHtml(w.counterparty || w.recipient_phone || 'N/A') + '</span>' +
                '<span class="muted-text" style="font-size:11px">Created: ' + escapeHtml(shortDate(w.created_at)) + '</span>' +
                (w.expires_at ? '<span class="muted-text" style="font-size:10px">Expires: ' + escapeHtml(shortDate(w.expires_at)) + '</span>' : '') +
            '</div>' +
            '<span class="ewallet-pin">' + escapeHtml(w.pin || '••••') + '</span>' +
            '<span style="font-weight:700">' + money(w.amount) + '</span>' +
            '<span class="ewallet-status ' + statusClass + '">' + escapeHtml(statusText) + '</span>' +
            '</div>';
    });

    list.innerHTML = html;
}

// --- Auto refresh ---
let dashboardInterval = null;
function startDashboardAutoFetch() {
    stopDashboardAutoFetch();
    dashboardInterval = setInterval(fetchDashboardData, 30000);
}
function stopDashboardAutoFetch() {
    if (dashboardInterval) { clearInterval(dashboardInterval); dashboardInterval = null; }
}

function logout() {
    stopDashboardAutoFetch();
    fetch('../../backend/auth/logout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token })
    }).finally(() => { window.location.href = '../public/login.php'; });
}

function downloadBankStatement() {
    const start = document.getElementById('startDate').value || '';
    const end = document.getElementById('endDate').value || '';
    window.open('../../backend/reports/bank_statement.php?start_date=' + start + '&end_date=' + end, '_blank');
}

// --- Transfers ---
function handleTransfer(event, type) {
    event.preventDefault();
    if (type === 'ownTransfer') submitOwnTransfer(event);
    else if (type === 'internalTransfer') submitInternalTransfer(event);
    else if (type === 'ewalletTransfer') submitEwalletTransfer(event);
}

// spendable balance, so held money is never offered for transfer
function spendable(acc) {
    return parseFloat(acc.available !== undefined ? acc.available : acc.balance) || 0;
}

function loadExternalTransferSources() {
    const select = document.getElementById('externalTransferSource');
    if (!select) return;
    select.innerHTML = '';
    accountData.forEach(acc => {
        const opt = document.createElement('option');
        opt.value = acc.account_number;
        opt.textContent = (acc.account_type || '').toUpperCase() + ' (' + money(spendable(acc)) + ')';
        select.appendChild(opt);
    });
}

function submitExternalTransfer(event) {
    event.preventDefault();
    const source = document.getElementById('externalTransferSource').value;
    const bankName = document.getElementById('externalRecipientBank').value.trim();
    const target = document.getElementById('externalRecipientAccount').value.trim();
    const amount = parseFloat(document.getElementById('externalTransferAmount').value);

    document.getElementById('externalTransferMessage').style.display = 'none';
    document.getElementById('externalTransferPIN').style.display = 'none';

    if (!source || !bankName || !target || !amount || amount <= 0) {
        showMessage('externalTransferMessage', 'Please fill all fields correctly', false);
        return;
    }
    const srcAcc = accountData.find(a => a.account_number === source);
    if (!srcAcc) { showMessage('externalTransferMessage', 'Source account not found', false); return; }

    const fee = Math.max(2, parseFloat((amount * 0.015).toFixed(2)));
    const totalDebit = amount + fee;
    if (totalDebit > spendable(srcAcc)) {
        showMessage('externalTransferMessage',
            'Insufficient available funds. Available: ' + money(spendable(srcAcc)) +
            '. Required: ' + money(totalDebit) + ' (amount plus fee)', false);
        return;
    }

    fetch('../../backend/transactions/external_transfer.php', {
        method: 'POST',
        headers: { 'Authorization': token, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ source, external_account: target, amount, bank_name: bankName })
    })
    .then(parseResponse)
    .then(data => {
        showMessage('externalTransferMessage', data.message, data.status === 'success');
        if (data.status === 'success') {
            if (data.pin) showMessage('externalTransferPIN', 'Transfer authorised. PIN generated.', true, data.pin);
            fetchDashboardData();
            document.getElementById('externalTransferForm').reset();
        }
    })
    .catch(err => { console.error(err); showMessage('externalTransferMessage', 'Network or server error', false); });
}

function submitInternalTransfer(event) {
    event.preventDefault();
    const source = document.getElementById('internalTransferSource').value;
    const target = document.getElementById('internalRecipientAccount').value;
    const amount = parseFloat(document.getElementById('internalTransferAmount').value);

    if (!source || !target || !amount) { showMessage('internalTransferMessage', 'Please fill all fields', false); return; }
    const srcAcc = accountData.find(a => a.account_number === source);
    if (!srcAcc) { showMessage('internalTransferMessage', 'Source account not found', false); return; }

    const fee = Math.max(1, amount * 0.005);
    if (amount + fee > spendable(srcAcc)) {
        showMessage('internalTransferMessage',
            'Insufficient available funds. Available: ' + money(spendable(srcAcc)) +
            '. Required: ' + money(amount + fee) + ' (amount plus fee)', false);
        return;
    }

    fetch('../../backend/transactions/internal_transfer.php', {
        method: 'POST',
        headers: { 'Authorization': token, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ source, target_account: target, amount })
    })
    .then(parseResponse)
    .then(data => {
        showMessage('internalTransferMessage', data.message, data.status === 'success');
        if (data.status === 'success') { fetchDashboardData(); document.getElementById('internalTransferForm').reset(); }
    })
    .catch(err => { console.error(err); showMessage('internalTransferMessage', 'Network or server error', false); });
}

function submitOwnTransfer(event) {
    event.preventDefault();
    const source = document.getElementById('ownTransferSource').value;
    const target = document.getElementById('ownTransferDestination').value;
    const amount = parseFloat(document.getElementById('ownTransferAmount').value);

    if (!source || !target || !amount) { showMessage('ownTransferMessage', 'Please fill all fields', false); return; }
    if (source === target) { showMessage('ownTransferMessage', 'Source and destination cannot be the same', false); return; }

    const srcAcc = accountData.find(a => a.account_number === source);
    if (!srcAcc) { showMessage('ownTransferMessage', 'Source account not found', false); return; }
    if (amount > spendable(srcAcc)) {
        showMessage('ownTransferMessage', 'Insufficient available funds. Available: ' + money(spendable(srcAcc)), false);
        return;
    }

    fetch('../../backend/transactions/own_transfer.php', {
        method: 'POST',
        headers: { 'Authorization': token, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ source, target, amount })
    })
    .then(parseResponse)
    .then(data => {
        showMessage('ownTransferMessage', data.message, data.status === 'success');
        if (data.status === 'success') { fetchDashboardData(); document.getElementById('ownTransferForm').reset(); }
    })
    .catch(err => { console.error(err); showMessage('ownTransferMessage', 'Network or server error', false); });
}

function submitEwalletTransfer(event) {
    event.preventDefault();
    const source = document.getElementById('ewalletTransferSource').value;
    const recipient = document.getElementById('ewalletRecipientPhone').value.trim();
    const amount = parseFloat(document.getElementById('ewalletTransferAmount').value);

    if (!source || !recipient || !amount || amount <= 0) {
        showMessage('ewalletTransferMessage', 'Please fill all fields correctly.', false);
        return;
    }
    const srcAcc = accountData.find(a => a.account_number === source);
    if (!srcAcc) { showMessage('ewalletTransferMessage', 'Source account not found.', false); return; }
    if (amount > spendable(srcAcc)) {
        showMessage('ewalletTransferMessage', 'Insufficient available funds. Available: ' + money(spendable(srcAcc)), false);
        return;
    }

    fetch('../../backend/wallet/ewallet_transfer.php', {
        method: 'POST',
        headers: { 'Authorization': token, 'Content-Type': 'application/json' },
        body: JSON.stringify({ recipient_phone: recipient, amount: amount, from_account_type: srcAcc.account_type })
    })
    .then(parseResponse)
    .then(data => {
        showMessage('ewalletTransferMessage', data.message, data.status === 'success', data.pin || null);
        if (data.status === 'success') { fetchDashboardData(); document.getElementById('ewalletTransferForm').reset(); }
    })
    .catch(err => { console.error('Transfer error:', err); showMessage('ewalletTransferMessage', 'Network or server error.', false); });
}

// --- Start ---
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('externalTransferForm').addEventListener('submit', submitExternalTransfer);
    fetchDashboardData();
    startDashboardAutoFetch();
});
</script>
</body>
</html>
