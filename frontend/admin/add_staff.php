<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Admin - Vogue Style</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
<style>
    body {
        font-family: 'Playfair Display', serif;
        background-color: #f9f9f9;
        margin: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
    }
    .container {
        background: #ffffff;
        padding: 40px;
        border-radius: 12px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        width: 400px;
    }
    h1 {
        text-transform: uppercase;
        font-size: 24px;
        text-align: center;
        margin-bottom: 30px;
        letter-spacing: 1px;
        color: #333;
    }
    input {
        width: 100%;
        padding: 12px;
        margin-bottom: 20px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 16px;
        font-family: 'Playfair Display', serif;
        color: #000;
        box-sizing: border-box;
    }
    button {
        width: 100%;
        padding: 14px;
        background-color: #222;
        color: #fff;
        border: none;
        border-radius: 8px;
        text-transform: uppercase;
        font-size: 16px;
        cursor: pointer;
        letter-spacing: 1px;
        transition: 0.3s;
    }
    button:hover {
        background-color: #444;
    }
    .message {
        text-align: center;
        margin-top: 15px;
        font-weight: bold;
    }
    .message.success { color: green; }
    .message.error { color: red; }
</style>
</head>
<body>
<div class="container">
    <h1>Add Admin</h1>
    <form id="addAdminForm" autocomplete="on">
        <input type="text" id="username" name="username" placeholder="Username" required autocomplete="username">
        <input type="email" id="email" name="email" placeholder="Email" required autocomplete="email">
        <input type="text" id="full_name" name="full_name" placeholder="Full Name" required autocomplete="name">
        <input type="password" id="password" name="password" placeholder="Password" required autocomplete="new-password">
        <button type="submit">Add Admin</button>
    </form>
    <div class="message" id="message"></div>
</div>

<script>
const form = document.getElementById('addAdminForm');
const messageDiv = document.getElementById('message');

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    messageDiv.textContent = '';
    messageDiv.className = 'message';

    const formData = new FormData(form);

    try {
        const response = await fetch('http://localhost/saccussalisbank/backend/auth/add_staff.php', { // absolute path
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if(result.status === 'success') {
            messageDiv.textContent = result.message;
            messageDiv.classList.add('success');
            form.reset();
        } else {
            messageDiv.textContent = result.message;
            messageDiv.classList.add('error');
        }
    } catch (err) {
        messageDiv.textContent = 'An error occurred';
        messageDiv.classList.add('error');
        console.error(err);
    }
});
</script>
</body>
</html>
