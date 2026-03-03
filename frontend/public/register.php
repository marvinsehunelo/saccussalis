<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear any previous session
session_unset();
session_destroy();

// Clear frontend localStorage token (if using JS token)
echo "<script>localStorage.removeItem('authToken');</script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Saccussalis Bank - Register</title>
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
    .register-container {
      background: #fff;
      color: #0a2342;
      width: 100%;
      max-width: 400px;
      padding: 25px 20px;
      box-shadow: 0 8px 25px rgba(0,0,0,0.4);
      border-radius: 0;
      text-align: center;
    }
    .register-container h1 {
      font-size: 26px;
      font-weight: 700;
      letter-spacing: 1px;
      margin-bottom: 5px;
    }
    .register-container p {
      font-size: 13px;
      color: #555;
      margin-bottom: 18px;
    }
    .form-group {
      margin-bottom: 12px;
      text-align: left;
    }
    label {
      display: block;
      font-size: 12px;
      margin-bottom: 3px;
      color: #0a2342;
      font-weight: 700;
    }
    input {
      width: 100%;
      padding: 10px;
      border: 1px solid #0a2342;
      border-radius: 0;
      font-size: 14px;
      color: #000;
      background: #f8f8f8;
      box-sizing: border-box;
      transition: border-color 0.2s, background 0.2s;
    }
    input:focus {
      border-color: #1a3c72;
      outline: none;
      background: #fff;
    }
    button {
      width: 100%;
      padding: 11px;
      background: #0a2342;
      color: #fff;
      font-size: 14px;
      font-weight: 700;
      border: none;
      cursor: pointer;
      border-radius: 0;
      text-transform: uppercase;
      letter-spacing: 1px;
      transition: background 0.3s, transform 0.1s;
    }
    button:hover {
      background: #1a3c72;
    }
    button:active {
      transform: scale(0.98);
    }
    .footer {
      margin-top: 12px;
      font-size: 11px;
      color: #777;
    }
    .login-link {
      margin-top: 8px;
      font-size: 12px;
      color: #0a2342;
      text-decoration: none;
      display: inline-block;
    }
    .login-link:hover {
      text-decoration: underline;
    }
    .message {
      margin-top: 10px;
      font-size: 13px;
      font-weight: bold;
    }
    .error { color: red; }
    .success { color: green; }
  </style>
</head>
<body>
  <div class="register-container">
    <h1>SACCUSSALIS BANK</h1>
    <p>Create Your Online Banking Account</p>

    <form id="registerForm" autocomplete="on">
      <div class="form-group">
        <label for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" autocomplete="name" required>
      </div>

      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" placeholder="Enter your email" autocomplete="email" required>
      </div>

      <div class="form-group">
        <label for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" placeholder="Enter your phone number" autocomplete="tel" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Create a password" autocomplete="new-password" required>
      </div>

      <div class="form-group">
        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" autocomplete="new-password" required>
      </div>

      <button type="submit">Register</button>
    </form>

    <div id="message" class="message"></div>

    <a href="login.php" class="login-link">Already have an account? Log in</a>

    <div class="footer">&copy; <?php echo date("Y"); ?> Saccussalis Bank. All Rights Reserved.</div>
  </div>

  <script>
    document.getElementById("registerForm").addEventListener("submit", async function(e) {
      e.preventDefault();

      const full_name = document.getElementById("full_name").value.trim();
      const email = document.getElementById("email").value.trim();
      const phone = document.getElementById("phone").value.trim();
      const password = document.getElementById("password").value;
      const confirm_password = document.getElementById("confirm_password").value;
      const msgBox = document.getElementById("message");

      msgBox.textContent = "";
      msgBox.className = "message";

      if (password !== confirm_password) {
        msgBox.textContent = "Passwords do not match.";
        msgBox.classList.add("error");
        return;
      }

      try {
        const response = await fetch("../../backend/auth/register.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ full_name, email, phone, password })
        });

        const result = await response.json();

        if (result.status === "success") {
          msgBox.textContent = "Registration successful! Redirecting to login...";
          msgBox.classList.add("success");
          setTimeout(() => {
            window.location.href = "./login.php"; // ✅ redirect after 2s
          }, 2000);
        } else {
          msgBox.textContent = result.message || "Registration failed.";
          msgBox.classList.add("error");
        }
      } catch (err) {
        msgBox.textContent = "Error connecting to server.";
        msgBox.classList.add("error");
      }
    });
  </script>
</body>
</html>
