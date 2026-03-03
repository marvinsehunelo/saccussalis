<?php
header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");
require_once("../auth/admin_session.php");

try {
    $session = admin_validate_token($pdo);
    $adminId = $session['user_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("POST required");
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $walletId = $input['wallet_transaction_id'] ?? null;
    $action = strtolower($input['action'] ?? '');

    if (!$walletId || !in_array($action, ['approve','reject'])) throw new Exception("Invalid input");

    $pdo->beginTransaction();

    // Lock wallet transaction
    $stmt = $pdo->prepare("SELECT * FROM wallet_transactions WHERE id = ? FOR UPDATE");
    $stmt->execute([$walletId]);
    $wt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$wt) throw new Exception("Wallet transaction not found");
    if ($wt['status'] !== 'pending') throw new Exception("Wallet transaction not pending");

    if ($action === 'approve') {
        // If deposit: credit account; if withdraw: debit account
        if ($wt['transaction_type'] === 'deposit') {
            // Credit user's default account or specified account_number column
            $accountNumber = $wt['account_number'] ?? null;
            if (!$accountNumber) {
                // fallback: pick first account for user
                $stmt = $pdo->prepare("SELECT account_number FROM accounts WHERE user_id = ? LIMIT 1");
                $stmt->execute([$wt['user_id']]);
                $accRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$accRow) throw new Exception("User has no account to credit");
                $accountNumber = $accRow['account_number'];
            }
            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_number = ?");
            $stmt->execute([(float)$wt['amount'], $accountNumber]);

            // Update wallet transaction
            $stmt = $pdo->prepare("UPDATE wallet_transactions SET status='approved', processed_by=?, processed_at=NOW() WHERE id=?");
            $stmt->execute([$adminId, $walletId]);

            // Insert transaction record
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_number, amount, type, fee, created_at, performed_by, note) VALUES (?, ?, ?, 'Wallet Deposit', 0, NOW(), ?, ?)");
            $stmt->execute([$wt['user_id'], $accountNumber, (float)$wt['amount'], $adminId, "Wallet deposit approved"]);
        } elseif ($wt['transaction_type'] === 'withdrawal') {
            // For withdrawals, ensure the user has enough balance in account to debit
            $accountNumber = $wt['account_number'] ?? null;
            if (!$accountNumber) {
                $stmt = $pdo->prepare("SELECT account_number FROM accounts WHERE user_id = ? LIMIT 1");
                $stmt->execute([$wt['user_id']]);
                $accRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$accRow) throw new Exception("User has no account to debit");
                $accountNumber = $accRow['account_number'];
            }
            // lock account
            $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_number = ? FOR UPDATE");
            $stmt->execute([$accountNumber]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$acc) throw new Exception("Account not found");
            if ($acc['balance'] < $wt['amount']) throw new Exception("Insufficient funds for withdrawal");

            // deduct
            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_number = ?");
            $stmt->execute([(float)$wt['amount'], $accountNumber]);

            $stmt = $pdo->prepare("UPDATE wallet_transactions SET status='approved', processed_by=?, processed_at=NOW() WHERE id=?");
            $stmt->execute([$adminId, $walletId]);

            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_number, amount, type, fee, created_at, performed_by, note) VALUES (?, ?, ?, 'Wallet Withdrawal', 0, NOW(), ?, ?)");
            $stmt->execute([$wt['user_id'], $accountNumber, -(float)$wt['amount'], $adminId, "Wallet withdrawal approved"]);
        } else {
            throw new Exception("Unrecognized wallet transaction type");
        }

    } else { // reject
        $stmt = $pdo->prepare("UPDATE wallet_transactions SET status='rejected', processed_by=?, processed_at=NOW(), admin_note=? WHERE id=?");
        $stmt->execute([$adminId, $input['note'] ?? 'Rejected by admin', $walletId]);
    }

    // Audit
    $auditStmt = $pdo->prepare("INSERT INTO audit_log (admin_id, action, target_table, target_id, details, ip_address, user_agent) VALUES (?, 'approve_wallet', 'wallet_transactions', ?, ?, ?, ?)");
    $auditStmt->execute([$adminId, $walletId, json_encode(['action'=>$action,'wallet'=>$wt]), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);

    $pdo->commit();

    echo json_encode(["status"=>"success","message"=>"Wallet transaction {$action}d"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
