<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to dashboard if already logged in
if (isset($_SESSION['authToken'])) {
    header("Location: ../dashboard/dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Saccussalis Bank - Login</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700;900&display=swap" rel="stylesheet">
<style>
body {
    margin: 0;
    font-family: 'Cinzel', serif;
    background: linear-gradient(135deg, #0a2342, #1a3c72);
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

.login-container {
    background: #fff;
    color: #0a2342;
    width: 100%;
    max-width: 400px;
    padding: 35px 30px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.4);
    border-radius: 0;
    text-align: center;
}

.login-container h1 {
    font-size: 28px;
    margin-bottom: 5px;
    font-weight: 700;
    letter-spacing: 1px;
}

.login-container p {
    font-size: 14px;
    color: #555;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
    text-align: left;
}

label {
    display: block;
    font-size: 13px;
    margin-bottom: 6px;
    color: #0a2342;
    font-weight: 700;
}

input {
    width: 100%;
    padding: 12px;
    border: 1px solid #0a2342;
    border-radius: 0;
    font-size: 14px;
    color: #000;
    box-sizing: border-box;
}

input:focus {
    border-color: #1a3c72;
    outline: none;
    background-color: #fff;
}

button {
    width: 100%;
    padding: 14px;
    background: #0a2342;
    color: #fff;
    font-size: 16px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    border-radius: 0;
    transition: background 0.3s, transform 0.1s;
    text-transform: uppercase;
}

button:hover {
    background: #1a3c72;
}

button:active {
    transform: scale(0.98);
}

.footer {
    margin-top: 20px;
    font-size: 12px;
    color: #777;
}

.error, .success {
    padding: 10px;
    margin-bottom: 15px;
    font-size: 13px;
    text-align: left;
}

.error {
    background-color: #ffe5e5;
    border-left: 4px solid #d33;
    color: #a00;
}

.success {
    background-color: #e5ffe5;
    border-left: 4px solid #3a3;
    color: #060;
}
</style>
</head>
<body>
<div class="login-container">
    <h1>SACCUSSALIS BANK</h1>
    <p>Secure Online Banking Access</p>

    <div id="message"></div>

    <form id="loginForm">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" placeholder="Enter your Email" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Enter your Password" required>
        </div>
        <button type="submit">Login</button>
    </form>

    <div class="footer">&copy; <?php echo date("Y"); ?> Saccussalis Bank. All Rights Reserved.</div>
</div>

<script>
document.getElementById("loginForm").addEventListener("submit", async function(e){
    e.preventDefault();
    const email = document.getElementById("email").value;
    const password = document.getElementById("password").value;
    const msgBox = document.getElementById("message");
    msgBox.innerHTML = "";

    try {
        const response = await fetch("../../backend/auth/login.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email, password })
        });

        const text = await response.text();
        console.log("Raw response:", text);
        let result;
        try {
            result = JSON.parse(text);
        } catch(err) {
            msgBox.innerHTML = '<div class="error">Server did not return valid JSON.</div>';
            return;
        }

        if(result.status === "success") {
            localStorage.setItem("authToken", result.token); // save token for dashboard
            msgBox.innerHTML = '<div class="success">' + result.message + '</div>';
            setTimeout(() => {
                window.location.href = "../dashboard/dashboard.php";
            }, 500);
        } else {
            msgBox.innerHTML = '<div class="error">' + (result.message || "Login failed.") + '</div>';
        }
    } catch(err) {
        msgBox.innerHTML = '<div class="error">Could not connect to server: ' + err + '</div>';
    }
});
</script>
</body>
</html>
