<?php
header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");
require_once("../auth/admin_session.php");

try {
    $session = admin_validate_token($pdo);
    $adminId = $session['user_id'];

    $action = $_GET['action'] ?? ($_POST['action'] ?? null);
    if (!$action) throw new Exception("Action required");

    // Create account
    if ($action === 'create') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $user_id = $input['user_id'] ?? null;
        $account_number = $input['account_number'] ?? null;
        $account_type = $input['account_type'] ?? 'savings';
        $initial_balance = (float)($input['initial_balance'] ?? 0);
        if (!$user_id || !$account_number) throw new Exception("Missing fields");

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, account_type, balance, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
        $stmt->execute([$user_id, $account_number, $account_type, $initial_balance]);

        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_number, amount, type, fee, created_at, performed_by, note) VALUES (?, ?, ?, 'Account Opening', 0, NOW(), ?, ?)");
        $stmt->execute([$user_id, $account_number, $initial_balance, $adminId, 'Initial balance on account opening']);

        $auditStmt = $pdo->prepare("INSERT INTO audit_log (admin_id, action, target_table, target_id, details, ip_address, user_agent) VALUES (?, 'create_account', 'accounts', ?, ?, ?, ?)");
        $auditStmt->execute([$adminId, $account_number, json_encode(['account_type'=>$account_type,'initial_balance'=>$initial_balance]), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);

        $pdo->commit();
        echo json_encode(["status"=>"success","message"=>"Account created"]);
        exit;
    }

    // Edit account: change account_type or status
    if ($action === 'edit') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $account_number = $input['account_number'] ?? null;
        $updates = [];
        $params = [];
        if (isset($input['account_type'])) { $updates[] = "account_type = ?"; $params[] = $input['account_type']; }
        if (isset($input['status'])) { $updates[] = "status = ?"; $params[] = $input['status']; }
        if (!$account_number || empty($updates)) throw new Exception("Nothing to update");
        $params[] = $account_number;

        $stmt = $pdo->prepare("UPDATE accounts SET ".implode(',', $updates)." WHERE account_number = ?");
        $stmt->execute($params);

        $auditStmt = $pdo->prepare("INSERT INTO audit_log (admin_id, action, target_table, target_id, details) VALUES (?, 'edit_account', 'accounts', ?, ?)");
        $auditStmt->execute([$adminId, $account_number, json_encode($input)]);
        echo json_encode(["status"=>"success","message"=>"Account updated"]);
        exit;
    }

    // Close (archive) account
    if ($action === 'close') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $account_number = $input['account_number'] ?? null;
        if (!$account_number) throw new Exception("Account required");

        $stmt = $pdo->prepare("UPDATE accounts SET status = 'closed' WHERE account_number = ?");
        $stmt->execute([$account_number]);

        $auditStmt = $pdo->prepare("INSERT INTO audit_log (admin_id, action, target_table, target_id, details) VALUES (?, 'close_account', 'accounts', ?, ?)");
        $auditStmt->execute([$adminId, $account_number, json_encode($input)]);
        echo json_encode(["status"=>"success","message"=>"Account closed"]);
        exit;
    }

    throw new Exception("Unknown action");

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
