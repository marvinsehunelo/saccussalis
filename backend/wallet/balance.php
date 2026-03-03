<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Dummy balance data for demonstration (replace with your DB query)
$walletBalance = 15250.00; // Example value
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saccussalis Bank - Wallet</title>
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

        .wallet-container {
            background: #fff;
            color: #0a2342;
            width: 100%;
            max-width: 400px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            border-radius: 0; /* sharp edges */
            text-align: center;
        }

        h1 {
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }

        p.subtitle {
            font-size: 14px;
            color: #555;
            margin-bottom: 20px;
        }

        .balance {
            font-size: 40px;
            font-weight: 700;
            margin-bottom: 25px;
            color: #0a2342;
        }

        .button-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 20px;
        }

        .button-group button {
            flex: 1;
            padding: 12px;
            background: #0a2342;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            border-radius: 0;
            transition: background 0.3s;
            text-transform: uppercase;
        }

        .button-group button:hover {
            background: #1a3c72;
        }

        .footer {
            font-size: 12px;
            color: #777;
        }

        @media (max-width: 500px) {
            .wallet-container {
                padding: 25px 20px;
            }
            .balance {
                font-size: 32px;
            }
            button {
                font-size: 13px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="wallet-container">
        <h1>SACCUSSALIS BANK</h1>
        <p class="subtitle">Your Wallet Balance</p>
        <div class="balance">R <?php echo number_format($walletBalance, 2); ?></div>
        <div class="button-group">
            <button onclick="alert('Send Money feature coming soon')">Send Money</button>
            <button onclick="location.href='../transactions/list.php'">View Transactions</button>
        </div>
        <div class="footer">&copy; <?php echo date("Y"); ?> Saccussalis Bank. All Rights Reserved.</div>
    </div>
</body>
</html>
