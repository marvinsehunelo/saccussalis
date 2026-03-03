<?php
// CRITICAL: We only use this PHP section to handle immediate redirects
// if the user already has an active PHP session from a previous visit.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// NOTE: This check depends on the backend admin_login.php script *also*
// setting a session variable like 'adminAuthToken' on success.
// If your backend only uses the token returned in JSON, this check is redundant.
if (isset($_SESSION['adminAuthToken'])) {
    header("Location: ../dashboard/admin_dashboard.php");
    exit;
}

// Check for any previous login error (kept for robustness)
$error_message = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Saccussalis Admin Login</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --admin-primary: #800000; /* Deep Burgundy/Maroon */
    --admin-accent: #A9996F; /* Vogue Gold/Bronze accent */
    --background-light: #F2F4F8;
}
body {
    font-family: 'Inter', sans-serif;
    background: var(--background-light);
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    margin: 0;
}
.login-box {
    background: #fff;
    padding: 40px;
    box-shadow: 8px 8px 0 rgba(10, 35, 66, 0.15); 
    border-radius: 0;
    border: 2px solid var(--admin-primary);
    max-width: 400px;
    width: 90%;
}
.admin-button {
    padding: 10px 15px;
    background: var(--admin-primary);
    color: #fff;
    border: 2px solid var(--admin-primary);
    cursor: pointer;
    font-weight: 700; 
    font-size: 14px;
    border-radius: 0;
    text-transform: uppercase;
    letter-spacing: 1px;
    transition: all 0.2s;
    box-shadow: 2px 2px 0 rgba(0, 0, 0, 0.2);
    width: 100%;
}
.admin-button:hover {
    opacity: 0.9;
    box-shadow: 4px 4px 0 rgba(0, 0, 0, 0.2);
}
.form-input {
    padding: 12px;
    border: 1px solid #333;
    border-radius: 0;
    width: 100%;
    box-sizing: border-box;
    margin-top: 5px;
    font-size: 16px;
}
</style>
</head>
<body>
<div class="login-box">
    <h1 class="text-3xl font-extrabold text-admin-primary font-['Cinzel'] mb-6 border-b pb-2 border-admin-accent">
        Admin Access
    </h1>

    <?php if ($error_message): ?>
        <div class="p-3 mb-4 bg-red-100 border border-red-500 text-red-700 font-medium text-sm rounded-none">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <form id="adminLoginForm" class="space-y-4">
        <div class="form-group">
            <label for="loginEmail" class="block text-sm font-bold text-gray-700">Email</label>
            <input type="email" id="loginEmail" name="email" autocomplete="email" required class="form-input">
        </div>
        <div class="form-group">
            <label for="loginPassword" class="block text-sm font-bold text-gray-700">Password</label>
            <input type="password" id="loginPassword" name="password" autocomplete="current-password" required class="form-input">
        </div>
        <p class="text-xs text-left text-gray-500 pt-2">
            Roles:<br>
            <strong class="text-admin-primary">superadmin</strong> / <strong class="text-admin-primary">admin123</strong><br>
            <strong class="text-admin-primary">finance</strong> / <strong class="text-admin-primary">manager456</strong>
        </p>
        <button type="submit" id="loginBtn" class="admin-button mt-4">Login Securely</button>
        <div id="loginResult" class="mt-2 font-medium"></div>
    </form>
</div>

<script>
const API_LOGIN_URL = '../../backend/auth/admin_login.php';
const DASHBOARD_URL = '../dashboard/admin_dashboard.php';

document.getElementById('adminLoginForm').addEventListener('submit', async function(e){
    e.preventDefault();

    const btn = document.getElementById('loginBtn');
    btn.disabled = true;

    const loginResult = document.getElementById('loginResult');
    loginResult.textContent = 'LOGGING IN...';
    loginResult.style.color = '#000';

    const payload = {
        email: document.getElementById('loginEmail').value.trim(), 
        password: document.getElementById('loginPassword').value
    };

    try {
        const res = await fetch(API_LOGIN_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            // 'include' is important for sending session cookies if used
            credentials: 'include' 
        });

        const j = await res.json();

        if(j.status === 'success'){
            // 1. Store the token for subsequent API calls
            localStorage.setItem('auth_token', j.token);
            
            // 2. Store other data (assuming j contains these fields)
            localStorage.setItem('USER_ROLE', j.role);
            
            // Note: Your backend response didn't include full_name, but we assume it might.
            localStorage.setItem('USER_NAME', j.full_name || 'Admin User'); 

            loginResult.textContent = 'LOGIN SUCCESS';
            loginResult.style.color = '#15803d';

            // 3. CRITICAL: Redirect the browser using client-side JavaScript
            setTimeout(() => window.location.href = DASHBOARD_URL, 500);
        } else {
            loginResult.textContent = j.message || 'LOGIN FAILED';
            loginResult.style.color = '#b91c1c';
        }
    } catch(e){
        console.error(e);
        loginResult.textContent = 'NETWORK ERROR: Check console for details.';
        loginResult.style.color = '#b91c1c';
    } finally {
        btn.disabled = false;
    }
});
</script>

</body>
</html>
