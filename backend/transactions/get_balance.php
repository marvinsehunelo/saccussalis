<?php
// get_account_balances.php — fetch balances for specific account types
declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config/integration.php';

$db = $pdo;
$config = require __DIR__ . '/../config/integration.php';

function jsonResponse($status, $message, $extra = []): void {
    echo json_encode(array_merge(['status'=>$status,'message'=>$message], $extra));
    exit;
}

// --- API AUTH ---
$headers = function_exists('getallheaders') ? getallheaders() : [];
$apiKey  = $headers['X-API-Key'] ?? ($_POST['api_key'] ?? null);

if (!$apiKey || !in_array($apiKey, ['SACCUS_LOCAL_KEY_DEF456'], true)) {
    jsonResponse('error', 'Invalid API key');
}

try {
    // Optional: accept user_id filter
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) $input = $_POST;

    $user_id_filter = isset($input['user_id']) ? (int)$input['user_id'] : null;

    // --- Fetch balances ---
    $account_types = ['partner_bank_settlement', 'middleman_revenue', 'middleman_escrow'];

    $query = "SELECT account_id, user_id, account_type, account_number, balance, is_frozen, created_at 
              FROM accounts 
              WHERE account_type IN (" . implode(',', array_fill(0, count($account_types), '?')) . ")";
    $params = $account_types;

    if ($user_id_filter) {
        $query .= " AND user_id = ?";
        $params[] = $user_id_filter;
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse('success', 'Account balances retrieved', ['accounts' => $accounts]);

} catch (Exception $e) {
    jsonResponse('error', $e->getMessage());
}

