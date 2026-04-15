// ============================================================================
// SACCUS ATM SYSTEM - COMPLETE JAVASCRIPT MODULE
// ============================================================================
// Features:
// - Realistic ATM transaction flow
// - E-Wallet cashout with hold management
// - SAT token cashout with authorization + completion
// - Session management and transaction tracking
// - Proper error handling and user feedback
// - Loading states and animations
// ============================================================================

// Global state management
let atmState = {
    sessionId: null,
    currentTransaction: null,
    isProcessing: false,
    retryCount: 0,
    lastTransactionTime: null
};

// ATM configuration
const ATM_CONFIG = {
    atm_id: 1,
    atm_code: 'ATM-001',
    maxWithdrawal: 5000,
    minWithdrawal: 10,
    dailyLimit: 15000,
    sessionTimeout: 300000, // 5 minutes
    retryLimit: 3
};

// Initialize ATM on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeATM();
    startSessionTimer();
    attachEventListeners();
});

// ============================================================================
// INITIALIZATION FUNCTIONS
// ============================================================================

function initializeATM() {
    updateClock();
    setInterval(updateClock, 1000);
    
    // Start heartbeat to keep session alive
    setInterval(sendHeartbeat, 30000);
    
    // Initialize ATM session
    initializeSession();
    
    // Load ATM cash balance
    loadATMBalance();
    
    console.log('ATM initialized at:', new Date().toISOString());
}

async function initializeSession() {
    try {
        const response = await fetch('/backend/atm/init_session.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                atm_id: ATM_CONFIG.atm_id,
                timestamp: new Date().toISOString()
            })
        });
        
        const data = await response.json();
        if (data.session_id) {
            atmState.sessionId = data.session_id;
            console.log('Session initialized:', atmState.sessionId);
        }
    } catch (err) {
        console.error('Session initialization failed:', err);
    }
}

async function loadATMBalance() {
    try {
        const response = await fetch('/backend/atm/get_balance.php?atm_id=' + ATM_CONFIG.atm_id);
        const data = await response.json();
        if (data.cash_balance !== undefined) {
            // Update display if needed
            console.log('ATM cash balance:', data.cash_balance);
        }
    } catch (err) {
        console.error('Failed to load ATM balance:', err);
    }
}

async function sendHeartbeat() {
    if (!atmState.sessionId) return;
    
    try {
        await fetch('/backend/atm/heartbeat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                session_id: atmState.sessionId,
                timestamp: new Date().toISOString()
            })
        });
    } catch (err) {
        console.error('Heartbeat failed:', err);
    }
}

function startSessionTimer() {
    setInterval(() => {
        if (atmState.lastTransactionTime && 
            (Date.now() - atmState.lastTransactionTime) > ATM_CONFIG.sessionTimeout) {
            showResponse('Session expired. Please restart transaction.', 'error');
            resetATM();
        }
    }, 60000);
}

function resetATM() {
    // Clear input fields
    document.querySelectorAll('input').forEach(input => {
        if (input.type !== 'hidden') {
            input.value = '';
        }
    });
    
    // Reset state
    atmState.currentTransaction = null;
    atmState.isProcessing = false;
    document.getElementById('trace_number').value = '';
    
    // Switch to ewallet tab by default
    document.querySelector('.side-btn[data-tab="ewalletTab"]').click();
    showResponse('Ready for transaction.', 'info');
}

// ============================================================================
// UI HELPER FUNCTIONS
// ============================================================================

function showResponse(message, type = "info") {
    const box = document.getElementById("responseBox");
    if (!box) return;
    
    box.className = `response-box ${type}`;
    box.textContent = message;
    
    // Auto-clear success/error messages after 5 seconds
    if (type !== 'info') {
        setTimeout(() => {
            if (box.textContent === message) {
                box.className = 'response-box info';
                box.textContent = 'Ready for transaction.';
            }
        }, 5000);
    }
    
    // Update footer message
    const footerMessage = document.querySelector('.footer-message');
    if (footerMessage) {
        footerMessage.textContent = message.substring(0, 50);
    }
}

function setAmount(fieldId, amount) {
    const field = document.getElementById(fieldId);
    if (field) {
        field.value = amount;
        // Add visual feedback
        field.style.backgroundColor = '#e8f5e9';
        setTimeout(() => {
            field.style.backgroundColor = '';
        }, 200);
    }
}

function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit',
        second: '2-digit',
        hour12: false 
    });
    const clockElement = document.getElementById("screenTime");
    if (clockElement) {
        clockElement.textContent = timeString;
    }
}

function showLoading(message = "Processing...") {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        document.querySelector('.loading-text').textContent = message;
        loadingOverlay.style.display = 'flex';
    }
    atmState.isProcessing = true;
}

function hideLoading() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
    }
    atmState.isProcessing = false;
}

function showModal(title, message, amount = null, type = 'success') {
    const modal = document.getElementById('transactionModal');
    if (!modal) return;
    
    const icon = document.getElementById('modalIcon');
    const modalTitle = document.getElementById('modalTitle');
    const modalMessage = document.getElementById('modalMessage');
    const modalAmount = document.getElementById('modalAmount');
    
    if (type === 'success') {
        icon.innerHTML = '✅';
        modalTitle.style.color = '#2ecc71';
    } else if (type === 'error') {
        icon.innerHTML = '❌';
        modalTitle.style.color = '#e74c3c';
    } else {
        icon.innerHTML = 'ℹ️';
        modalTitle.style.color = '#ffcc00';
    }
    
    modalTitle.textContent = title;
    modalMessage.textContent = message;
    
    if (amount) {
        modalAmount.innerHTML = `P${parseFloat(amount).toFixed(2)}`;
        modalAmount.style.display = 'block';
    } else {
        modalAmount.style.display = 'none';
    }
    
    modal.style.display = 'flex';
}

function closeModal() {
    const modal = document.getElementById('transactionModal');
    if (modal) {
        modal.style.display = 'none';
    }
    resetATM();
}

function printReceipt(transactionData) {
    // Simulate receipt printing
    console.log('Printing receipt:', transactionData);
    const receipt = `
        ═══════════════════════════════
           SACCUS SALIS ATM
           ${new Date().toLocaleString()}
        ═══════════════════════════════
        ATM: ${ATM_CONFIG.atm_code}
        Type: ${transactionData.type}
        Amount: P${transactionData.amount}
        Reference: ${transactionData.reference}
        Status: ${transactionData.status}
        ═══════════════════════════════
        Thank you for banking with us
    `;
    console.log(receipt);
}

// ============================================================================
// TAB NAVIGATION
// ============================================================================

document.querySelectorAll(".side-btn[data-tab]").forEach(button => {
    button.addEventListener("click", () => {
        if (atmState.isProcessing) {
            showResponse("Please wait, transaction in progress...", "error");
            return;
        }
        
        document.querySelectorAll(".side-btn[data-tab]").forEach(btn => btn.classList.remove("active"));
        document.querySelectorAll(".tab-panel").forEach(panel => panel.classList.remove("active"));

        button.classList.add("active");
        const activeTab = document.getElementById(button.dataset.tab);
        if (activeTab) {
            activeTab.classList.add("active");
        }
        
        // Clear previous transaction data
        document.getElementById("trace_number").value = "";
        showResponse("Ready for transaction.", "info");
        atmState.lastTransactionTime = Date.now();
    });
});

// ============================================================================
// E-WALLET CASHOUT
// ============================================================================

async function cashoutEwallet() {
    // Prevent multiple simultaneous transactions
    if (atmState.isProcessing) {
        showResponse("Please wait, completing previous transaction...", "error");
        return;
    }
    
    const phone = document.getElementById("phone").value.trim();
    const pin = document.getElementById("ewallet_pin").value.trim();
    const amount = parseFloat(document.getElementById("ewallet_amount").value);
    
    // Validation
    if (!phone) {
        showResponse("Please enter your phone number.", "error");
        return;
    }
    
    if (!pin) {
        showResponse("Please enter your e-wallet PIN.", "error");
        return;
    }
    
    if (isNaN(amount) || amount < ATM_CONFIG.minWithdrawal) {
        showResponse(`Minimum withdrawal amount is P${ATM_CONFIG.minWithdrawal}.`, "error");
        return;
    }
    
    if (amount > ATM_CONFIG.maxWithdrawal) {
        showResponse(`Maximum withdrawal amount is P${ATM_CONFIG.maxWithdrawal} per transaction.`, "error");
        return;
    }
    
    // Validate phone format (Botswana format)
    const phoneRegex = /^(267)?[0-9]{8}$/;
    if (!phoneRegex.test(phone.replace(/^267/, ''))) {
        showResponse("Invalid phone number format. Use 267XXXXXXXX or XXXXXXXXX.", "error");
        return;
    }
    
    showLoading("Verifying e-wallet credentials...");
    atmState.isProcessing = true;
    
    // Generate unique transaction reference
    const transactionRef = 'EWT' + Date.now() + Math.floor(Math.random() * 10000);
    
    try {
        const response = await fetch("/backend/atm/ewallet_cashout.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-Session-ID": atmState.sessionId || ''
            },
            body: JSON.stringify({ 
                atm_id: ATM_CONFIG.atm_id,
                phone: phone,
                pin: pin,
                amount: amount,
                transaction_ref: transactionRef,
                timestamp: new Date().toISOString()
            })
        });
        
        const data = await response.json();
        
        hideLoading();
        
        if (data.status === "APPROVED" || data.status === "COMPLETED") {
            // Success - show cash dispensed modal
            showModal(
                "CASH DISPENSED", 
                `E-Wallet withdrawal successful.\nPhone: ${phone}\nPlease take your cash.`,
                data.amount || amount,
                "success"
            );
            
            showResponse(`✓ Cashout approved! Amount: P${(data.amount || amount).toFixed(2)}. Reference: ${data.reference || transactionRef}`, "success");
            
            // Print receipt
            printReceipt({
                type: 'E-WALLET CASHOUT',
                amount: data.amount || amount,
                reference: data.reference || transactionRef,
                status: 'COMPLETED',
                phone: phone
            });
            
            // Clear inputs
            document.getElementById("phone").value = "";
            document.getElementById("ewallet_pin").value = "";
            document.getElementById("ewallet_amount").value = "";
            
            // Log transaction
            atmState.lastTransactionTime = Date.now();
            
        } else if (data.status === "HOLD_CREATED") {
            // Hold created but not yet executed (for staged transactions)
            showResponse(`Hold created. Amount reserved: P${data.amount}. Complete transaction at teller.`, "info");
            
        } else {
            // Handle specific error codes
            let errorMessage = data.message || "Ewallet cashout failed.";
            
            switch(data.code) {
                case 'INSUFFICIENT_FUNDS':
                    errorMessage = "Insufficient funds in wallet.";
                    break;
                case 'INVALID_PIN':
                    errorMessage = "Invalid PIN. Please try again.";
                    break;
                case 'DAILY_LIMIT_EXCEEDED':
                    errorMessage = "Daily withdrawal limit exceeded.";
                    break;
                case 'WALLET_FROZEN':
                    errorMessage = "Wallet is frozen. Contact customer support.";
                    break;
                case 'ATM_INSUFFICIENT_CASH':
                    errorMessage = "ATM has insufficient cash. Please try a smaller amount.";
                    break;
            }
            
            showResponse(errorMessage, "error");
            
            // Increment retry count
            atmState.retryCount++;
            if (atmState.retryCount >= ATM_CONFIG.retryLimit) {
                showResponse("Too many failed attempts. Please try again later.", "error");
                atmState.retryCount = 0;
            }
        }
        
    } catch (err) {
        hideLoading();
        showResponse("Network error. Please check your connection and try again.", "error");
        console.error("Ewallet cashout error:", err);
    } finally {
        atmState.isProcessing = false;
    }
}

// ============================================================================
// SAT TOKEN CASHOUT
// ============================================================================

async function authorizeSAT() {
    if (atmState.isProcessing) {
        showResponse("Please wait, completing previous transaction...", "error");
        return;
    }
    
    const sat_number = document.getElementById("sat_number").value.trim();
    const pin = document.getElementById("sat_pin").value.trim();
    const amount = parseFloat(document.getElementById("sat_amount").value);
    
    // Validation
    if (!sat_number) {
        showResponse("Please enter your SAT number.", "error");
        return;
    }
    
    if (!pin) {
        showResponse("Please enter your SAT PIN.", "error");
        return;
    }
    
    if (isNaN(amount) || amount < ATM_CONFIG.minWithdrawal) {
        showResponse(`Minimum withdrawal amount is P${ATM_CONFIG.minWithdrawal}.`, "error");
        return;
    }
    
    if (amount > ATM_CONFIG.maxWithdrawal) {
        showResponse(`Maximum withdrawal amount is P${ATM_CONFIG.maxWithdrawal} per transaction.`, "error");
        return;
    }
    
    showLoading("Authorizing SAT token...");
    atmState.isProcessing = true;
    
    const authRef = 'SATAUTH' + Date.now();
    
    try {
        const response = await fetch("/backend/atm/sat_cashout.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-Session-ID": atmState.sessionId || ''
            },
            body: JSON.stringify({ 
                atm_id: ATM_CONFIG.atm_id,
                sat_number: sat_number,
                pin: pin,
                amount: amount,
                auth_ref: authRef,
                timestamp: new Date().toISOString()
            })
        });
        
        const data = await response.json();
        
        hideLoading();
        
        if (data.status === "APPROVED") {
            // Store trace number for completion
            const traceNumber = data.trace_number || ('TRC' + Date.now());
            document.getElementById("trace_number").value = traceNumber;
            
            // Store transaction data for completion
            atmState.currentTransaction = {
                sat_number: sat_number,
                amount: amount,
                trace_number: traceNumber,
                auth_code: data.auth_code,
                authorized_at: new Date().toISOString()
            };
            
            showResponse(
                `✓ SAT authorized! Trace: ${traceNumber}\nPress "Dispense / Complete" to dispense cash.`, 
                "success"
            );
            
            // Enable completion visually
            const completeBtn = document.querySelector('#satTab .secondary-btn');
            if (completeBtn) {
                completeBtn.style.opacity = '1';
                completeBtn.disabled = false;
            }
            
        } else {
            let errorMessage = data.message || "SAT authorization failed.";
            
            switch(data.code) {
                case 'SAT_NOT_FOUND':
                    errorMessage = "SAT number not found or invalid.";
                    break;
                case 'SAT_EXPIRED':
                    errorMessage = "SAT token has expired.";
                    break;
                case 'SAT_ALREADY_USED':
                    errorMessage = "SAT token has already been used.";
                    break;
                case 'INVALID_PIN':
                    errorMessage = "Invalid SAT PIN.";
                    break;
                case 'AMOUNT_MISMATCH':
                    errorMessage = `Amount exceeds SAT value. Maximum: P${data.max_amount}`;
                    break;
            }
            
            showResponse(errorMessage, "error");
            
            atmState.retryCount++;
            if (atmState.retryCount >= ATM_CONFIG.retryLimit) {
                showResponse("Too many failed attempts. Please try again later.", "error");
                atmState.retryCount = 0;
            }
        }
        
    } catch (err) {
        hideLoading();
        showResponse("Network error during SAT authorization.", "error");
        console.error("SAT authorization error:", err);
    } finally {
        atmState.isProcessing = false;
    }
}

async function completeSAT() {
    if (atmState.isProcessing) {
        showResponse("Please wait, completing previous transaction...", "error");
        return;
    }
    
    const sat_number = document.getElementById("sat_number").value.trim();
    const trace_number = document.getElementById("trace_number").value.trim();
    
    if (!sat_number) {
        showResponse("Please enter SAT number first.", "error");
        return;
    }
    
    if (!trace_number) {
        showResponse("Please authorize SAT first before completion.", "error");
        return;
    }
    
    if (!atmState.currentTransaction || atmState.currentTransaction.trace_number !== trace_number) {
        showResponse("Session mismatch. Please re-authorize SAT.", "error");
        document.getElementById("trace_number").value = "";
        return;
    }
    
    showLoading("Dispensing cash...");
    atmState.isProcessing = true;
    
    try {
        const response = await fetch("/backend/atm/sat_complete.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-Session-ID": atmState.sessionId || ''
            },
            body: JSON.stringify({ 
                atm_id: ATM_CONFIG.atm_id,
                sat_number: sat_number,
                trace_number: trace_number,
                amount: atmState.currentTransaction.amount,
                timestamp: new Date().toISOString()
            })
        });
        
        const data = await response.json();
        
        hideLoading();
        
        if (data.status === "COMPLETED") {
            // Show success modal
            showModal(
                "CASH DISPENSED",
                `SAT withdrawal successful.\nSAT: ${sat_number}\nPlease take your cash.`,
                data.amount || atmState.currentTransaction.amount,
                "success"
            );
            
            showResponse(
                `✓ SAT cashout completed! Amount: P${(data.amount || atmState.currentTransaction.amount).toFixed(2)}. Reference: ${data.reference}`, 
                "success"
            );
            
            // Print receipt
            printReceipt({
                type: 'SAT CASHOUT',
                amount: data.amount || atmState.currentTransaction.amount,
                reference: data.reference,
                sat_number: sat_number,
                status: 'COMPLETED'
            });
            
            // Clear all SAT fields
            document.getElementById("sat_number").value = "";
            document.getElementById("sat_pin").value = "";
            document.getElementById("sat_amount").value = "";
            document.getElementById("trace_number").value = "";
            
            // Clear transaction state
            atmState.currentTransaction = null;
            atmState.lastTransactionTime = Date.now();
            
            // Reset retry count on success
            atmState.retryCount = 0;
            
        } else if (data.status === "HOLD_RELEASED") {
            showResponse("Transaction hold released. Please try again.", "error");
            document.getElementById("trace_number").value = "";
            atmState.currentTransaction = null;
            
        } else {
            let errorMessage = data.message || "SAT completion failed.";
            
            switch(data.code) {
                case 'DISPENSE_FAILED':
                    errorMessage = "Cash dispense failed. Please contact customer support.";
                    break;
                case 'HOLD_EXPIRED':
                    errorMessage = "Authorization hold has expired. Please re-authorize.";
                    document.getElementById("trace_number").value = "";
                    atmState.currentTransaction = null;
                    break;
                case 'ATM_JAM':
                    errorMessage = "Cash dispenser jam. Please contact bank staff.";
                    break;
            }
            
            showResponse(errorMessage, "error");
        }
        
    } catch (err) {
        hideLoading();
        showResponse("Network error during SAT completion.", "error");
        console.error("SAT completion error:", err);
    } finally {
        atmState.isProcessing = false;
    }
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

// Function to format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-BW', { 
        style: 'currency', 
        currency: 'BWP' 
    }).format(amount);
}

// Function to validate amount
function validateAmount(amount, min, max) {
    if (isNaN(amount)) return false;
    if (amount < min) return false;
    if (amount > max) return false;
    return true;
}

// Function to mask sensitive data
function maskPhoneNumber(phone) {
    if (!phone || phone.length < 8) return '***';
    return phone.slice(0, 3) + '****' + phone.slice(-3);
}

// Export for testing (if needed)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        cashoutEwallet,
        authorizeSAT,
        completeSAT,
        setAmount,
        showResponse,
        ATM_CONFIG
    };
}
