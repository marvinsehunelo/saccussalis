<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header("Content-Type: application/json; charset=utf-8");

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/../db.php";

try {
    // -------------------------
    // FIX START: Read JSON payload into $_POST
    // -------------------------
    $input = json_decode(file_get_contents('php://input'), true);

    if (is_array($input)) {
        $_POST = array_merge($_POST, $input);
    }
    // -------------------------
    // FIX END
    // -------------------------

    // -------------------------
    // AUTHENTICATION
    // -------------------------
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = $headers['Authorization'] ?? ($_POST['token'] ?? null);
    $apiKey = $headers['X-API-Key'] ?? ($_POST['api_key'] ?? null);
    $userId = null;

    if ($apiKey) {
        $validApiKeys = ['SACCUS_LOCAL_KEY_DEF456'];
        if (!in_array($apiKey, $validApiKeys, true)) {
            throw new Exception("Invalid API key");
        }
        $userId = 0; // system/middleman
    } elseif ($token) {
        if (substr($token, 0, 7) === 'Bearer ') {
            $token = trim(substr($token, 7));
        }
        $stmt = $pdo->prepare("
            SELECT s.*, u.full_name, u.user_id
            FROM sessions s
            JOIN users u ON s.user_id = u.user_id
            WHERE s.token = ? AND (s.expires_at IS NULL OR s.expires_at > NOW())
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) throw new Exception("Invalid or expired token");
        $userId = (int)$session['user_id'];
    } else {
        throw new Exception("Authorization required (token or API key)");
    }

    // -------------------------
    // INPUTS
    // -------------------------
    $source = 'MME001'; // middleman escrow
    $amount = (float)($_POST['amount'] ?? 0);
    $targetAcc = $_POST['account_number'] ?? $_POST['recipient_account'] ?? null; 

    if (empty($targetAcc)) {
        if ($userId === 0) {
            throw new Exception("Recipient account number required for system-initiated transfers.");
        }
        $stmt = $pdo->prepare("SELECT account_number FROM accounts WHERE user_id=? AND account_type='current' LIMIT 1");
        $stmt->execute([$userId]);
        $userAcc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$userAcc) throw new Exception("Target account not found for user_id {$userId}");
        $targetAcc = $userAcc['account_number'];
    }

    if (!$source || !$targetAcc || $amount <= 0) {
        throw new Exception("Invalid input: Source Escrow ({$source}), Target Account ({$targetAcc}), and Amount must be provided.");
    }

    // -------------------------
    // TRANSACTION BLOCK
    // -------------------------
    $pdo->beginTransaction();

    // Lock source escrow
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_number=? AND account_type='middleman_escrow' FOR UPDATE");
    $stmt->execute([$source]);
    $srcAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$srcAcc) throw new Exception("Source account not found or not a middleman escrow");

    // Lock target account
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_number=? FOR UPDATE");
    $stmt->execute([$targetAcc]);
    $tgtAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tgtAcc) throw new Exception("Target account not found");

    $targetUserId = (int)$tgtAcc['user_id'];

    // Fee calculation (system applies 0.5% minimum 1)
    $fee = max(1, round($amount * 0.005, 2));
    $totalDebit = $amount + $fee;

    if ($srcAcc['balance'] < $totalDebit) {
        throw new Exception("Insufficient funds in escrow. Required: " . number_format($totalDebit, 2));
    }

    // Update balances
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_number = ?");
    $stmt->execute([$totalDebit, $source]);

    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_number = ?");
    $stmt->execute([$amount, $targetAcc]);

    // Log transactions in `transactions` table
    $stmt = $pdo->prepare("
        INSERT INTO transactions
            (user_id, from_account, to_account, amount, fee_amount, direction, type, status, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // Debit from escrow
    $stmt->execute([
        $userId,                   // initiator
        $srcAcc['account_number'],
        $targetAcc,
        $amount,
        $fee,
        'debit',
        'transfer',
        'completed',
        'Escrow payout to account'
    ]);

    // Credit to recipient
    $stmt->execute([
        $targetUserId,
        $srcAcc['account_number'],
        $targetAcc,
        $amount,
        0,
        'credit',
        'transfer',
        'completed',
        'Received escrow payout'
    ]);

    $pdo->commit();

    // Optional SMS notification
    if (!empty($tgtAcc['phone'])) {
        $smsMessage = "You have received BWP {$amount} from middleman escrow.";
        sendSmsToCazaCom('+' . ltrim($tgtAcc['phone'], '+'), $smsMessage, '+26770010001', $targetUserId);
    }

    echo json_encode([
        "status" => "success",
        "message" => "Escrow Payout of BWP " . number_format($amount, 2) . " completed to account {$targetAcc}. Fee: BWP " . number_format($fee, 2),
        "transaction_ids" => [
            $pdo->lastInsertId() // last insert is recipient
        ]
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

