<?php
header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");
require_once("../auth/admin_session.php");

try {
    $session = admin_validate_token($pdo);
    $adminId = $session['user_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("POST required");
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $type = $input['type'] ?? ''; // 'external','internal','own'
    $source = $input['source'] ?? '';
    $target = $input['target'] ?? ($input['target_account'] ?? '');
    $amount = (float)($input['amount'] ?? 0);
    $waive_fee = !empty($input['waive_fee']); // admin can waive fees
    $note = $input['note'] ?? '';

    if (!$type || !$source || !$target || $amount <= 0) throw new Exception("Invalid input");

    $pdo->beginTransaction();

    // Lock source
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_number = ? FOR UPDATE");
    $stmt->execute([$source]);
    $src = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$src) throw new Exception("Source account not found");

    // Lock target
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_number = ? FOR UPDATE");
    $stmt->execute([$target]);
    $tgt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tgt) throw new Exception("Target account not found");

    // Determine fee rules
    $fee = 0.0;
    if (!$waive_fee) {
        if ($type === 'external') {
            $fee = max(2, round($amount * 0.015, 2));
        } elseif ($type === 'internal') {
            $fee = max(1, round($amount * 0.005, 2));
        } else { // own
            $fee = 0;
        }
    }

    $totalDebit = $amount + $fee;
    if ($src['balance'] < $totalDebit) throw new Exception("Insufficient funds in source account");

    // Debit source, credit target
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_number = ?");
    $stmt->execute([$totalDebit, $source]);

    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_number = ?");
    $stmt->execute([$amount, $target]);

    // Insert transactions (source: negative total, target: positive amount)
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_number, amount, type, fee, created_at, performed_by, note) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
    $stmt->execute([$src['user_id'], $source, -$totalDebit, 'Admin Transfer Out', $fee, $adminId, $note]);
    $stmt->execute([$tgt['user_id'], $target, $amount, 'Admin Transfer In', 0, $adminId, $note]);

    // Audit
    $auditStmt = $pdo->prepare("INSERT INTO audit_log (admin_id, action, target_table, target_id, details, ip_address, user_agent) VALUES (?, 'admin_transfer', 'accounts', ?, ?, ?, ?)");
    $auditStmt->execute([$adminId, $source . '->' . $target, json_encode(['type'=>$type,'amount'=>$amount,'fee'=>$fee,'waive_fee'=>$waive_fee,'note'=>$note]), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);

    $pdo->commit();

    echo json_encode(["status"=>"success","message"=>"Transfer of $".$amount." completed. Fee: $".$fee]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
