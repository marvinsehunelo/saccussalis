<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/deposit/direct/index.php
header('Content-Type: application/json');
require_once '../../../db.php';
require_once '../../../middleware/Idempotency.php';

$input = json_decode(file_get_contents("php://input"), true);

$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $input['request_id'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Idempotency key required']);
    exit;
}

Idempotency::check($idempotencyKey);

if (!isset($input['depositRef']) || !isset($input['amount']) || !isset($input['account_number'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Find account
    $stmt = $pdo->prepare("SELECT account_id, user_id FROM accounts WHERE account_number = ?");
    $stmt->execute([$input['account_number']]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("Account not found");
    }

    // Credit account
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_number = ?");
    $stmt->execute([$input['amount'], $input['account_number']]);

    // Create transaction record
    $reference = 'DEP' . time() . rand(100, 999);
    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, reference, to_account, amount, type, status, created_at)
        VALUES (?, ?, ?, ?, 'deposit', 'completed', NOW())
    ");
    $stmt->execute([$account['user_id'], $reference, $input['account_number'], $input['amount']]);

    // Create ledger entry
    $stmt = $pdo->prepare("
        INSERT INTO ledger_entries (reference, debit_account, credit_account, amount, notes)
        VALUES (?, 'SETTLEMENT_SUSPENSE', ?, ?, 'Direct deposit')
    ");
    $stmt->execute([$reference, $input['account_number'], $input['amount']]);

    $pdo->commit();

    // Get new balance
    $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_number = ?");
    $stmt->execute([$input['account_number']]);
    $newBalance = $stmt->fetchColumn();

    $response = [
        'status' => 'success',
        'transaction_ref' => $reference,
        'credited' => true,
        'amount' => $input['amount'],
        'new_balance' => $newBalance
    ];

    Idempotency::store($idempotencyKey, $response);
    echo json_encode($response);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Deposit failed: ' . $e->getMessage()]);
}
