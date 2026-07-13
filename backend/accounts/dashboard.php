<?php
header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");

try {
    // --- token handling ---
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? ($_GET['token'] ?? null);
    if (!$token) throw new Exception("Token required");

    // --- validate session ---
    $stmt = $pdo->prepare("
        SELECT s.*, u.full_name, u.role, u.phone 
        FROM sessions s 
        JOIN users u ON s.user_id = u.user_id 
        WHERE s.token = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) throw new Exception("Invalid or expired token");

    $userId = $session['user_id'];
    $username = $session['full_name'];
    $role = $session['role'];
    $userPhone = $session['phone']; // Get the user's phone number

    // --- accounts ---
    $stmt = $pdo->prepare("SELECT account_number, account_type, balance FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as &$acc) $acc['balance'] = (float)$acc['balance'];
    unset($acc);
    $totalBalance = array_sum(array_column($accounts, "balance"));

    // --- pending wallet transactions ---
    $stmt = $pdo->prepare("
        SELECT id AS wallet_transaction_id, amount, transaction_type, status, created_at 
        FROM wallet_transactions 
        WHERE user_id = ? AND status='pending'
    ");
    $stmt->execute([$userId]);
    $pendingWallet = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingWallet as &$wt) $wt['amount'] = (float)$wt['amount'];
    unset($wt);

    $totalPending = array_sum(array_column($pendingWallet, 'amount'));
    $availableBalance = $totalBalance - $totalPending;

    // --- recent transactions ---
    $stmt = $pdo->prepare("
        SELECT transaction_id, amount, type, created_at 
        FROM transactions 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($transactions as &$tx) $tx['amount'] = (float)$tx['amount'];
    unset($tx);

    // --- FETCH E-WALLETS FROM ewallet_pins TABLE ---
    $ewallets = [];
    if ($userPhone) {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                transaction_id,
                pin,
                is_redeemed,
                sender_phone,
                recipient_phone,
                amount,
                created_at,
                expires_at,
                redeemed_at,
                hold_status,
                hold_reference,
                held_at,
                regenerated_by,
                regeneration_fee,
                sat_purchased,
                sat_expires_at,
                sat_paid_by,
                generated_by,
                redeemed_by
            FROM ewallet_pins 
            WHERE sender_phone = ? 
            ORDER BY created_at DESC 
            LIMIT 20
        ");
        $stmt->execute([$userPhone]);
        $ewalletResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($ewalletResults as $row) {
            // Convert is_redeemed to boolean for consistent handling
            $isRedeemed = false;
            if ($row['is_redeemed'] === 'true' || $row['is_redeemed'] === '1' || $row['is_redeemed'] === true) {
                $isRedeemed = true;
            }
            
            $ewallets[] = [
                'id' => (int)$row['id'],
                'transaction_id' => $row['transaction_id'],
                'pin' => $row['pin'],
                'is_redeemed' => $isRedeemed,
                'sender_phone' => $row['sender_phone'],
                'recipient_phone' => $row['recipient_phone'],
                'amount' => (float)$row['amount'],
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'redeemed_at' => $row['redeemed_at'],
                'hold_status' => $row['hold_status'],
                'hold_reference' => $row['hold_reference'],
                'held_at' => $row['held_at'],
                'regenerated_by' => $row['regenerated_by'],
                'regeneration_fee' => (float)$row['regeneration_fee'],
                'sat_purchased' => $row['sat_purchased'],
                'sat_expires_at' => $row['sat_expires_at'],
                'sat_paid_by' => $row['sat_paid_by'],
                'generated_by' => $row['generated_by'],
                'redeemed_by' => $row['redeemed_by']
            ];
        }
    }

    // --- RESPONSE ---
    echo json_encode([
        "status" => "success",
        "username" => $username,
        "role" => $role,
        "totalBalance" => $totalBalance,
        "availableBalance" => $availableBalance,
        "accounts" => $accounts,
        "recentTransactions" => $transactions,
        "pendingWalletTransactions" => $pendingWallet,
        "ewallets" => $ewallets, // Added e-wallets to response
        "userPhone" => $userPhone // Optional: for debugging
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
