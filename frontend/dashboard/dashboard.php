<?php
// CRITICAL TEMPORARY FIX: Suppress PHP errors that might corrupt JSON output.
error_reporting(0);

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is not logged in, redirect to login
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
        --color-bg-primary: #FFFAF0; /* Floral White / Cream White Background */
        --color-bg-secondary: #FFFFFF; /* Pure White Card Background */
        --color-fg-primary: #000000; /* Pure Black Text/Primary Accent */
        --color-fg-secondary: #444444; /* Dark Grey Muted Text */
        --color-accent: #000000; /* Primary Accent is Black */
        --color-border-subtle: #E8E8E8; /* Very Light Grey Divider */
        --color-positive: #008000; /* Standard Green */
        --color-negative: #CC0000; /* Standard Red */
        --shadow-sharp: 4px 4px 0 #000000; /* Sharp Black Shadow */
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
    
    .dashboard-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 15px;
    }

    header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 3px solid var(--color-accent);
        padding-bottom: 10px;
    }

    header h1 {
        font-size: 28px;
        margin: 0;
        font-weight: 700;
        color: var(--color-fg-primary);
    }
    
    .role-badge {
        font-size: 11px;
        padding: 3px 8px;
        background: var(--color-accent);
        color: var(--color-bg-primary);
        text-transform: uppercase;
        font-weight: 700;
        border-radius: 0;
        border: 1px solid var(--color-accent);
        display: inline-block;
        margin-right: 10px;
    }

    .summary-box {
        background: var(--color-accent);
        color: var(--color-bg-primary);
        padding: 20px 30px;
        margin-bottom: 20px;
        box-shadow: var(--shadow-sharp);
        border-radius: 0;
    }

    .summary-box h2 {
        margin: 0 0 5px 0;
        font-size: 14px;
        font-weight: 400;
        color: var(--color-border-subtle);
    }
    
    .summary-box p {
        margin: 0;
        font-size: 40px;
        font-weight: 700;
        color: var(--color-bg-primary);
    }

    .vogue-button {
        padding: 10px 20px;
        background: var(--color-accent);
        color: var(--color-bg-primary);
        border: 2px solid var(--color-accent);
        cursor: pointer;
        font-weight: 700;
        font-size: 14px;
        border-radius: 0;
        text-transform: uppercase;
        transition: all 0.2s;
        margin-left: 8px;
    }
    
    .vogue-button:hover {
        background: var(--color-bg-primary);
        border-color: var(--color-accent);
        color: var(--color-accent);
    }
    
    .vogue-button.secondary {
        background: none;
        color: var(--color-fg-primary);
        border: 2px solid var(--color-fg-primary);
    }
    
    .vogue-button.secondary:hover {
        background: var(--color-accent);
        color: var(--color-bg-primary);
    }

    .vogue-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 20px;
        padding: 10px;
        background: var(--color-bg-secondary);
        box-shadow: var(--shadow-sharp);
        overflow-x: auto;
        border: 1px solid var(--color-accent);
    }
    
    .vogue-nav button {
        padding: 8px 14px;
        background: none;
        color: var(--color-fg-secondary);
        border: 1px solid transparent;
        font-weight: 400;
        text-transform: capitalize;
        transition: all 0.2s;
        cursor: pointer;
        border-radius: 0;
        flex-shrink: 0;
    }
    
    .vogue-nav button.active {
        color: var(--color-bg-primary);
        background-color: var(--color-accent);
        border: 2px solid var(--color-accent);
        font-weight: 700;
        text-transform: uppercase;
    }
    
    .vogue-nav button:not(.active):hover {
        background-color: var(--color-border-subtle);
        color: var(--color-fg-primary);
    }

    .main-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .card {
        background: var(--color-bg-secondary);
        padding: 25px;
        box-shadow: var(--shadow-sharp);
        border-radius: 0;
        border: 1px solid var(--color-accent);
    }

    .card h2 {
        margin: 0 0 10px 0;
        font-size: 20px;
        font-weight: 700;
        border-bottom: 2px solid var(--color-accent);
        padding-bottom: 5px;
    }

    .list-item {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        font-size: 15px;
        border-bottom: 1px dashed var(--color-border-subtle);
    }

    .list-item:hover {
        background-color: var(--color-border-subtle);
    }
    
    .muted-text {
        color: var(--color-fg-secondary);
        font-size: 13px;
        font-style: italic;
    }

    /* E-Wallet specific styles */
    .ewallet-item {
        display: grid;
        grid-template-columns: 1.5fr 1fr 1fr 1fr 1.2fr;
        gap: 10px;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px dashed var(--color-border-subtle);
    }
    
    .ewallet-item:hover {
        background-color: var(--color-border-subtle);
    }
    
    .ewallet-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    
    .ewallet-phone {
        font-weight: 700;
        font-size: 16px;
    }
    
    .ewallet-pin {
        font-size: 18px;
        color: var(--color-fg-primary);
        font-family: monospace;
        letter-spacing: 3px;
        font-weight: 700;
    }
    
    .ewallet-status {
        padding: 4px 12px;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        border: 1px solid var(--color-accent);
        text-align: center;
        display: inline-block;
        justify-self: start;
    }
    
    .status-active {
        color: var(--color-positive);
        border-color: var(--color-positive);
    }
    
    .status-inactive {
        color: var(--color-negative);
        border-color: var(--color-negative);
    }
    
    .status-pending {
        color: #FF8C00;
        border-color: #FF8C00;
    }
    
    .status-redeemed {
        color: #0066CC;
        border-color: #0066CC;
    }

    .form-group label {
        display: block;
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 5px;
        color: var(--color-fg-primary);
        text-transform: capitalize;
    }

    .form-group input, .form-group select, .vogue-message-box {
        width: 100%;
        padding: 10px;
        margin-bottom: 15px;
        border: 1px solid var(--color-fg-secondary);
        background: var(--color-bg-secondary);
        color: var(--color-fg-primary);
        font-size: 16px;
        box-sizing: border-box;
        border-radius: 0;
    }

    .pin-box {
        background: var(--color-accent);
        border: 2px solid var(--color-fg-primary);
        padding: 15px;
        margin-top: 15px;
        text-align: center;
        font-size: 18px;
        font-weight: 700;
        color: var(--color-bg-primary);
        border-radius: 0;
    }
    
    .pin-number {
        font-size: 28px;
        color: var(--color-bg-primary);
        display: block;
        margin-top: 5px;
        font-family: var(--font-serif);
    }
    
    .statement-section {
        grid-column: 1 / -1;
        margin-top: 20px;
        padding: 20px;
        background: var(--color-bg-secondary);
        border: 1px solid var(--color-accent);
        box-shadow: var(--shadow-sharp);
        border-radius: 0;
    }
    
    .statement-section h3 {
        color: var(--color-fg-primary);
        border-bottom: 2px solid var(--color-accent);
        padding-bottom: 5px;
        margin-top: 0;
        font-family: var(--font-serif);
        text-transform: uppercase;
        font-size: 20px;
    }

    .ewallet-header {
        display: grid;
        grid-template-columns: 1.5fr 1fr 1fr 1fr 1.2fr;
        gap: 10px;
        padding: 10px 0;
        font-weight: 700;
        border-bottom: 2px solid var(--color-accent);
    }

    @media (max-width: 768px) {
        .main-grid { grid-template-columns: 1fr; gap: 15px; }
        .summary-box p { font-size: 30px; }
        .ewallet-item, .ewallet-header {
            grid-template-columns: 1fr;
            gap: 5px;
        }
        .ewallet-status {
            justify-self: start;
        }
    }
</style>
</head>
<body>
<div class="dashboard-container">
    <header>
        <h1 id="username">SACCUSSALIS PRIVATE BANK</h1>
        <div class="header-actions">
            <span class="role-badge" id="userRole">CLIENT</span>
            <button class="vogue-button secondary" onclick="logout()">Logout</button>
        </div>
    </header>

    <div class="summary-box">
        <h2>Total Available Balance</h2>
        <p>$<span id="totalBalance">0.00</span></p>
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
            <div class="card transactions">
                <h2>Recent Transactions</h2>
                <div id="transactionsList"></div>
            </div>
            <!-- E-Wallet Management Card -->
            <div class="card ewallet-management" style="grid-column: 1 / -1; margin-top: 5px;">
                <h2>E-Wallet Management</h2>
                <div id="ewalletList"></div>
            </div>
            <div class="card wallet" style="grid-column: 1 / -1; margin-top: 5px;">
                <h2>Pending Wallet Transactions</h2>
                <div id="walletList"></div>
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
            <p class="muted-text" style="margin-bottom: 20px;">Send cash instantly to a mobile number for ATM/agent redemption using a secure PIN.</p>
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
            <form id="externalTransferForm" onsubmit="submitExternalTransfer(event)">
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
// Use PHP session token
const token = "<?php echo $token; ?>";
const BACKEND_ENDPOINT = '../../backend/accounts/dashboard.php';

if (!token) {
    window.location.href = '../public/login.php';
}

// --- Global State ---
let accountData = [];
let currentView = 'dashboard';

// --- Utility Functions ---
function parseResponse(res) {
    return res.text().then(text => {
        if (!res.ok) {
            try { return JSON.parse(text); } catch (e) {
                console.error("Non-OK response failed JSON parse. Raw text:", text);
                return { status: 'error', message: res.statusText || 'Non-OK server response.' };
            }
        }
        try { return JSON.parse(text); } catch (e) {
            console.error("Failed to parse JSON response. Received raw text:", text);
            return { status: 'error', message: 'Server did not return valid JSON.' };
        }
    });
}

function formatCurrency(amount) {
    return parseFloat(amount).toFixed(2);
}

function showMessage(elementId, message, isSuccess, pin = null) {
    const el = document.getElementById(elementId);
    if (!el) {
        console.warn(`showMessage: element with ID "${elementId}" not found.`);
        return;
    }
    el.innerHTML = '';
    
    const statusClass = isSuccess ? 'success' : 'error';
    el.className = `vogue-message-box ${statusClass}`;
    el.style.border = `1px solid ${isSuccess ? 'var(--color-positive)' : 'var(--color-negative)'}`;
    el.style.backgroundColor = 'var(--color-bg-primary)';
    el.style.color = isSuccess ? 'var(--color-positive)' : 'var(--color-negative)';

    if (isSuccess && pin) {
        el.innerHTML = `
            ${message}
            <div class="pin-box">
                <span class="muted-text" style="font-weight: 400; font-size: 13px;">ONE-TIME PIN:</span>
                <span class="pin-number">${pin}</span>
            </div>
            <p class="muted-text" style="margin-top: 5px; color: var(--color-bg-primary);">The recipient will need this PIN to withdraw cash.</p>
        `;
    } else {
        el.className = 'vogue-message-box ' + (isSuccess ? 'success' : 'error');
        el.textContent = message;
    }

    el.style.display = 'block';
    const timeout = isSuccess && pin ? 15000 : 6000;
    setTimeout(() => { el.style.display = 'none'; }, timeout);
}

// --- Screen Management ---
function showScreen(view) {
    currentView = view;
    document.querySelectorAll('.screen-view').forEach(el => el.style.display = 'none');
    document.getElementById(view + '-view').style.display = 'block';

    document.querySelectorAll('.vogue-nav button').forEach(btn => btn.classList.remove('active'));
    document.getElementById('nav-' + view).classList.add('active');
    
    if (view !== 'dashboard') {
        populateSourceDropdowns(view);
    }
}

function populateSourceDropdowns(view) {
    const sourceId = view + 'Source';
    const destId = view + 'Destination';
    const sourceSelect = document.getElementById(sourceId);
    
    if (!sourceSelect) return;
    
    sourceSelect.innerHTML = '<option value="">Select Account</option>';

    if (view === 'ownTransfer') {
        const destSelect = document.getElementById(destId);
        if (destSelect) {
            destSelect.innerHTML = '<option value="">Select Destination</option>';
        }
    }

    (accountData || []).forEach(acc => {
        const option = document.createElement('option');
        option.value = acc.account_number;
        option.textContent = `${acc.account_type.toUpperCase()} (Balance: $${formatCurrency(acc.balance)})`;
        sourceSelect.appendChild(option);
    });

    if (view === 'ownTransfer') {
        const destSelect = document.getElementById(destId);
        if (destSelect) {
            sourceSelect.onchange = function() {
                destSelect.innerHTML = '<option value="">Select Destination</option>';
                const selectedSource = this.value;
                accountData.forEach(acc => {
                    if (acc.account_number !== selectedSource) {
                        const option = document.createElement('option');
                        option.value = acc.account_number;
                        option.textContent = `${acc.account_type.toUpperCase()} (Balance: $${formatCurrency(acc.balance)})`;
                        destSelect.appendChild(option);
                    }
                });
            };
            if (sourceSelect.value) {
                sourceSelect.onchange();
            }
        }
    }
}

// --- Core Data Fetching ---
function fetchDashboardData() {
    fetch(BACKEND_ENDPOINT + '?token=' + token + '&action=fetch_data')
    .then(res => parseResponse(res))
    .then(data => {
        if (data.status !== 'success') {
            safeShowMessage('totalBalance', data.message || 'Error fetching data.', false);
            console.error("Dashboard Fetch Error:", data.message);
            return;
        }

        accountData = data.accounts || [];

        document.getElementById('username').textContent = 'Saccussalis Private Bank';
        document.getElementById('userRole').textContent = data.role ? data.role.toUpperCase() : 'CLIENT';
        document.getElementById('totalBalance').textContent = formatCurrency(data.totalBalance);

        renderAccounts(data.accounts);
        renderTransactions(data.recentTransactions, 'transactionsList', 'type');
        renderEwallets(data.ewallets || []);
        renderTransactions(data.pendingWalletTransactions, 'walletList', 'id', true);
        loadExternalTransferSources();
    })
    .catch(err => {
        console.error('Fetch error:', err);
        safeShowMessage('totalBalance', 'Critical network error. Check console.', false);
    });
}

function renderAccounts(accounts) {
    const accountsList = document.getElementById('accountsList');
    accountsList.innerHTML = '';
    (accounts || []).forEach(acc => {
        const div = document.createElement('div');
        div.className = 'list-item';
        const type = acc.account_type ? acc.account_type.toUpperCase() : 'UNKNOWN';
        const balance = formatCurrency(acc.balance);
        div.innerHTML = `
            <span>${type} <span class="muted-text">(${acc.account_number})</span></span>
            <span class="amount-positive">$${balance}</span>
        `;
        accountsList.appendChild(div);
    });
    if (!accounts || accounts.length === 0) {
        accountsList.innerHTML = '<div class="muted-text" style="padding: 5px 0;">No accounts found.</div>';
    }
}

// Render E-Wallets from ewallet_pins table
function renderEwallets(ewallets) {
    const ewalletList = document.getElementById('ewalletList');
    ewalletList.innerHTML = '';
    
    if (!ewallets || ewallets.length === 0) {
        ewalletList.innerHTML = '<div class="muted-text" style="padding: 15px 0; text-align: center;">No e-wallet transactions found for your phone number.</div>';
        return;
    }
    
    // Create header
    const header = document.createElement('div');
    header.className = 'ewallet-header';
    header.innerHTML = `
        <span>Recipient</span>
        <span>Sender</span>
        <span>PIN</span>
        <span>Amount</span>
        <span>Status</span>
    `;
    ewalletList.appendChild(header);
    
    (ewallets || []).forEach(wallet => {
        const div = document.createElement('div');
        div.className = 'ewallet-item';
        
        // Determine status - handle boolean and string values
        const isRedeemed = wallet.is_redeemed === true || wallet.is_redeemed === 'true' || wallet.is_redeemed === 1;
        
        let statusClass = 'status-pending';
        let statusText = 'PENDING';
        
        if (isRedeemed) {
            statusClass = 'status-redeemed';
            statusText = 'REDEEMED';
            if (wallet.redeemed_at && wallet.redeemed_at !== '0000-00-00 00:00:00') {
                const redeemedDate = new Date(wallet.redeemed_at).toLocaleDateString();
                statusText += ` ✓ (${redeemedDate})`;
            }
        } else if (wallet.hold_status && wallet.hold_status !== '' && wallet.hold_status !== 'false') {
            statusClass = 'status-inactive';
            statusText = 'ON HOLD';
        } else if (wallet.expires_at && new Date(wallet.expires_at) < new Date()) {
            statusClass = 'status-inactive';
            statusText = 'EXPIRED';
        } else {
            statusClass = 'status-active';
            statusText = 'ACTIVE';
        }
        
        // Format dates
        const createdDate = wallet.created_at ? new Date(wallet.created_at).toLocaleDateString() : '';
        const expiresDate = wallet.expires_at ? new Date(wallet.expires_at).toLocaleDateString() : '';
        
        div.innerHTML = `
            <div class="ewallet-info">
                <span class="ewallet-phone">${wallet.recipient_phone || 'N/A'}</span>
                <span class="muted-text" style="font-size: 11px;">Created: ${createdDate}</span>
                ${expiresDate ? `<span class="muted-text" style="font-size: 10px;">Expires: ${expiresDate}</span>` : ''}
            </div>
            <span style="font-size: 14px;">${wallet.sender_phone || 'N/A'}</span>
            <span class="ewallet-pin">${wallet.pin || 'N/A'}</span>
            <span style="font-weight: 700;">$${formatCurrency(wallet.amount || 0)}</span>
            <span class="ewallet-status ${statusClass}">${statusText}</span>
        `;
        ewalletList.appendChild(div);
    });
}

function renderTransactions(transactions, listId, typeField, isPending = false) {
    const list = document.getElementById(listId);
    list.innerHTML = '';
    (transactions || []).forEach(tx => {
        const div = document.createElement('div');
        div.className = 'list-item';
        const amount = parseFloat(tx.amount);
        const amountClass = isPending ? 'amount-negative' : (amount >= 0 ? 'amount-positive' : 'amount-negative');
        const displayAmount = isPending ? `-$${formatCurrency(amount)}` : (amount >= 0 ? `+$${formatCurrency(amount)}` : `-$${formatCurrency(Math.abs(amount))}`);
        
        div.innerHTML = `
            <span>${tx[typeField] || 'Transaction'}</span>
            <span class="${amountClass}">${displayAmount} <span class="muted-text">(${tx.created_at || new Date().toISOString().split('T')[0]})</span></span>
        `;
        list.appendChild(div);
    });
    if (!transactions || transactions.length === 0) {
        list.innerHTML = `<div class="muted-text" style="padding: 5px 0;">No ${isPending ? 'pending' : 'recent'} transactions.</div>`;
    }
}

function safeShowMessage(elementId, message, isSuccess) {
    const el = document.getElementById(elementId);
    if (!el) {
        console.warn(`safeShowMessage: element with ID "${elementId}" not found.`);
        return;
    }
    showMessage(elementId, message, isSuccess);
}

// --- Initialization ---
document.addEventListener('DOMContentLoaded', fetchDashboardData);

let dashboardInterval = null;

function startDashboardAutoFetch() {
    dashboardInterval = setInterval(fetchDashboardData, 30000);
}

function stopDashboardAutoFetch() {
    if (dashboardInterval) clearInterval(dashboardInterval);
}

function logout() {
    stopDashboardAutoFetch();
    fetch('../../backend/auth/logout.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({token})
    }).finally(() => {
        window.location.href = '../public/login.php';
    });
}

function downloadBankStatement() {
    const start = document.getElementById('startDate').value || '';
    const end = document.getElementById('endDate').value || '';
    window.open(`../../backend/reports/bank_statement.php?start_date=${start}&end_date=${end}`, '_blank');
}

function handleTransfer(event, type) {
    event.preventDefault();
    if (type === 'ownTransfer') submitOwnTransfer(event);
    else if (type === 'internalTransfer') submitInternalTransfer(event);
    else if (type === 'ewalletTransfer') submitEwalletTransfer(event);
}

// ---------------------- Transfer Functions ----------------------
function submitExternalTransfer(event) {
    event.preventDefault();
    const source = document.getElementById('externalTransferSource').value;
    const bankName = document.getElementById('externalRecipientBank').value.trim();
    const target = document.getElementById('externalRecipientAccount').value.trim();
    const amount = parseFloat(document.getElementById('externalTransferAmount').value);
    const msgBox = document.getElementById('externalTransferMessage');
    const pinBox = document.getElementById('externalTransferPIN');

    msgBox.style.display = 'none';
    pinBox.style.display = 'none';

    if (!source || !bankName || !target || !amount || amount <= 0) {
        showMessage('externalTransferMessage', 'Please fill all fields correctly', false);
        return;
    }

    const srcAcc = accountData.find(acc => acc.account_number === source);
    if (!srcAcc) {
        showMessage('externalTransferMessage', 'Source account not found', false);
        return;
    }

    const fee = Math.max(2, parseFloat((amount * 0.015).toFixed(2)));
    const totalDebit = amount + fee;

    if (totalDebit > srcAcc.balance) {
        showMessage('externalTransferMessage', `Insufficient funds. Available: $${formatCurrency(srcAcc.balance)}. Required: $${formatCurrency(totalDebit)} (Amount + Fee)`, false);
        return;
    }

    fetch('../../backend/transactions/external_transfer.php', {
        method: 'POST',
        headers: {
            'Authorization': token,
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            source,
            external_account: target,
            amount,
            bank_name: bankName
        })
    })
    .then(res => res.json())
    .then(data => {
        showMessage('externalTransferMessage', data.message, data.status === 'success');
        if (data.status === 'success' && data.pin) {
            showMessage('externalTransferPIN', 'TRANSFER AUTHORIZED. PIN GENERATED.', true, data.pin);
            fetchDashboardData();
            document.getElementById('externalTransferForm').reset();
        } else if (data.status === 'success') {
            fetchDashboardData();
            document.getElementById('externalTransferForm').reset();
        }
    })
    .catch(err => {
        console.error(err);
        showMessage('externalTransferMessage', 'Network or server error', false);
    });
}

function loadExternalTransferSources() {
    const select = document.getElementById('externalTransferSource');
    if (!select) return;
    select.innerHTML = '';
    accountData.forEach(acc => {
        const opt = document.createElement('option');
        opt.value = acc.account_number;
        opt.textContent = `${acc.account_type.toUpperCase()} ($${formatCurrency(acc.balance)})`;
        select.appendChild(opt);
    });
}

function submitInternalTransfer(event) {
    event.preventDefault();
    const source = document.getElementById('internalTransferSource').value;
    const target = document.getElementById('internalRecipientAccount').value;
    const amount = parseFloat(document.getElementById('internalTransferAmount').value);

    if (!source || !target || !amount) {
        showMessage('internalTransferMessage', 'Please fill all fields', false);
        return;
    }

    const srcAcc = accountData.find(acc => acc.account_number === source);
    if (!srcAcc) {
        showMessage('internalTransferMessage', 'Source account not found', false);
        return;
    }

    const fee = Math.max(1, amount * 0.005);
    const totalDebit = amount + fee;

    if (totalDebit > srcAcc.balance) {
        showMessage('internalTransferMessage', `Insufficient funds. Available: $${formatCurrency(srcAcc.balance)}. Required: $${formatCurrency(totalDebit)} (Amount + Fee)`, false);
        return;
    }

    fetch('../../backend/transactions/internal_transfer.php', {
        method: 'POST',
        headers: { 'Authorization': token, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ source, target_account: target, amount })
    })
    .then(res => parseResponse(res))
    .then(data => {
        showMessage('internalTransferMessage', data.message, data.status === 'success');
        if (data.status === 'success') {
            fetchDashboardData();
            document.getElementById('internalTransferForm').reset();
        }
    })
    .catch(err => {
        console.error(err);
        showMessage('internalTransferMessage', 'Network or server error', false);
    });
}

function submitOwnTransfer(event) {
    event.preventDefault();
    const source = document.getElementById('ownTransferSource').value;
    const target = document.getElementById('ownTransferDestination').value;
    const amount = parseFloat(document.getElementById('ownTransferAmount').value);

    if (!source || !target || !amount) {
        showMessage('ownTransferMessage', 'Please fill all fields', false);
        return;
    }
    if (source === target) {
        showMessage('ownTransferMessage', 'Source and destination cannot be the same', false);
        return;
    }

    const srcAcc = accountData.find(acc => acc.account_number === source);
    if (!srcAcc) {
        showMessage('ownTransferMessage', 'Source account not found', false);
        return;
    }

    if (amount > srcAcc.balance) {
        showMessage('ownTransferMessage', `Insufficient funds. Available: $${formatCurrency(srcAcc.balance)}`, false);
        return;
    }

    fetch('../../backend/transactions/own_transfer.php', {
        method: 'POST',
        headers: { 'Authorization': token, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ source, target, amount })
    })
    .then(res => parseResponse(res))
    .then(data => {
        showMessage('ownTransferMessage', data.message, data.status === 'success');
        if (data.status === 'success') {
            fetchDashboardData();
            document.getElementById('ownTransferForm').reset();
        }
    })
    .catch(err => {
        console.error(err);
        showMessage('ownTransferMessage', 'Network or server error', false);
    });
}

function submitEwalletTransfer(event) {
    event.preventDefault();

    const source = document.getElementById('ewalletTransferSource').value;
    const recipient = document.getElementById('ewalletRecipientPhone').value.trim();
    const amount = parseFloat(document.getElementById('ewalletTransferAmount').value);

    if (!source || !recipient || !amount || amount <= 0) {
        showMessage('ewalletTransferMessage', 'Please fill all fields correctly (source, phone, and valid amount).', false);
        return;
    }

    const srcAcc = accountData.find(acc => acc.account_number === source);
    if (!srcAcc) {
        showMessage('ewalletTransferMessage', 'Source account not found.', false);
        return;
    }

    if (amount > srcAcc.balance) {
        showMessage(
            'ewalletTransferMessage',
            `Insufficient funds. Available balance: $${formatCurrency(srcAcc.balance)}`,
            false
        );
        return;
    }

    fetch('../../backend/wallet/ewallet_transfer.php', {
        method: 'POST',
        headers: {
            'Authorization': token,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            recipient_phone: recipient,
            amount: amount,
            from_account_type: srcAcc.account_type
        })
    })
    .then(res => parseResponse(res))
    .then(data => {
        showMessage('ewalletTransferMessage', data.message, data.status === 'success', data.pin || null);

        if (data.status === 'success') {
            fetchDashboardData();
            document.getElementById('ewalletTransferForm').reset();
        }
    })
    .catch(err => {
        console.error('Transfer error:', err);
        showMessage('ewalletTransferMessage', 'Network or server error. Please try again.', false);
    });
}

// ---------------------- Attach Event Listeners ----------------------
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('externalTransferForm').addEventListener('submit', submitExternalTransfer);
    document.getElementById('ownTransferForm').addEventListener('submit', (e) => handleTransfer(e, 'ownTransfer'));
    document.getElementById('internalTransferForm').addEventListener('submit', (e) => handleTransfer(e, 'internalTransfer'));
    document.getElementById('ewalletTransferForm').addEventListener('submit', (e) => handleTransfer(e, 'ewalletTransfer'));
});
</script>
</body>
</html>
