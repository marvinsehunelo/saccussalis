<?php
// saccussalis/api/submit_transfer.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Not logged in']);
    exit;
}

// POST input
$recipient_bank_code = $_POST['recipient_bank_code'] ?? '';
$recipient_account_number = $_POST['recipient_account_number'] ?? '';
$amount = floatval($_POST['amount'] ?? 0);

if(!$recipient_bank_code || !$recipient_account_number || $amount<=0){
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid input']);
    exit;
}

// Sender info
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE user_id = ? AND account_type='checking' LIMIT 1");
$stmt->execute([$user_id]);
$sender = $stmt->fetch();
if(!$sender || $sender['balance'] < $amount){
    echo json_encode(['status'=>'error','message'=>'Insufficient funds']);
    exit;
}

// Deduct locally
$stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?");
$stmt->execute([$amount, $sender['account_id']]);

// Prepare central bank API call
$cb_api = "http://localhost/centralbank/api/submit_transfer.php"; // central bank API
$data = [
    'sender_bank_code' => 'SAC001', // SaccusSalis bank code
    'sender_account_id' => $sender['account_id'],
    'recipient_bank_code' => $recipient_bank_code,
    'recipient_account_number' => $recipient_account_number,
    'amount' => $amount
];

// Use cURL
$ch = curl_init($cb_api);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
$response = curl_exec($ch);
curl_close($ch);

echo $response;
