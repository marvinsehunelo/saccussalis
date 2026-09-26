<?php
// backend/accounts/dashboard.php
//
// Adds to the original response:
//   wallets[]        — the user's mobile wallet(s): balance, held, available
//   holds[]          — money held right now, and which institution holds it
//   balanceSummary   — totals across accounts and wallets, held and available
//   ewallets[]       — now includes cash SENT and RECEIVED, with direction
//
// Columns differ between deployments, so optional ones are detected first
// rather than assumed: a missing held_balance or destination_institution
// degrades to zero or "—" instead of breaking the whole dashboard.

header("Content-Type: application/json; charset=utf-8");
require_once("../db.php");

const THIS_BANK = 'SaccusSalis Private Bank';

/** Does this table have this column? Cached per request. */
function hasColumn(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = "$table.$column";
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare("
            SELECT 1 FROM information_schema.columns
            WHERE table_name = ? AND column_name = ? LIMIT 1
        ");
        $st->execute([$table, $column]);
        return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function tableExists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1");
        $st->execute([$table]);
        return $cache[$table] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

try {
    // --- token handling ---
    $headers = function_exists('getallheaders') ? getallheaders() : [];
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

    $userId    = $session['user_id'];
    $username  = $session['full_name'];
    $role      = $session['role'];
    $userPhone = $session['phone'];

    // ================================================================
    // ACCOUNTS — with held balance where the column exists
    // ================================================================
    $acctHeld = hasColumn($pdo, 'accounts', 'held_balance');
    $acctStatus = hasColumn($pdo, 'accounts', 'status');
    $stmt = $pdo->prepare("
        SELECT account_number, account_type, balance
               " . ($acctHeld ? ", COALESCE(held_balance,0) AS held_balance" : "") . "
               " . ($acctStatus ? ", status" : "") . "
        FROM accounts WHERE user_id = ?
        ORDER BY account_type
    ");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $accountsTotal = 0.0; $accountsHeld = 0.0;
    foreach ($accounts as &$acc) {
        $acc['balance']      = (float)$acc['balance'];
        $acc['held_balance'] = (float)($acc['held_balance'] ?? 0);
        $acc['available']    = round($acc['balance'] - $acc['held_balance'], 2);
        $acc['institution']  = THIS_BANK;
        $acc['kind']         = 'ACCOUNT';
        $accountsTotal += $acc['balance'];
        $accountsHeld  += $acc['held_balance'];
    }
    unset($acc);

    // ================================================================
    // WALLETS — the piece that was missing from the dashboard
    // ================================================================
    $wallets = [];
    $walletsTotal = 0.0; $walletsHeld = 0.0;
    if (tableExists($pdo, 'wallets')) {
        $wHeld   = hasColumn($pdo, 'wallets', 'held_balance');
        $wStatus = hasColumn($pdo, 'wallets', 'status');
        $wPhone  = hasColumn($pdo, 'wallets', 'phone');

        // by user_id, and by phone as a fallback for wallets not linked to the user row
        $sql = "SELECT wallet_id, balance
                       " . ($wPhone  ? ", phone" : "") . "
                       " . ($wHeld   ? ", COALESCE(held_balance,0) AS held_balance" : "") . "
                       " . ($wStatus ? ", status" : "") . "
                FROM wallets WHERE user_id = ?";
        $params = [$userId];
        if ($wPhone && $userPhone) {
            $sql .= " OR phone = ?";
            $params[] = $userPhone;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $wallets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($wallets as &$w) {
            $w['balance']      = (float)$w['balance'];
            $w['held_balance'] = (float)($w['held_balance'] ?? 0);
            $w['available']    = round($w['balance'] - $w['held_balance'], 2);
            $w['institution']  = THIS_BANK;
            $w['kind']         = 'WALLET';
            $w['phone']        = $w['phone'] ?? $userPhone;
            $walletsTotal += $w['balance'];
            $walletsHeld  += $w['held_balance'];
        }
        unset($w);
    }

    // ================================================================
    // HOLDS — what is held right now, and who is holding it
    // ================================================================
    $holds = [];
    if (tableExists($pdo, 'financial_holds')) {
        $cols = ['id', 'hold_reference', 'amount', 'status', 'asset_type'];
        foreach (['destination_institution', 'participant_name', 'source_institution',
                  'hold_expiry', 'expires_at', 'placed_at', 'created_at',
                  'account_id', 'wallet_id', 'swap_reference'] as $c) {
            if (hasColumn($pdo, 'financial_holds', $c)) $cols[] = $c;
        }
        $select = implode(', ', $cols);

        // tie holds to this user's accounts and wallets
        $where = [];
        $params = [];
        if (hasColumn($pdo, 'financial_holds', 'account_id')) {
            $where[] = "account_id IN (SELECT account_id FROM accounts WHERE user_id = ?)";
            $params[] = $userId;
        }
        if (hasColumn($pdo, 'financial_holds', 'wallet_id') && tableExists($pdo, 'wallets')) {
            $where[] = "wallet_id IN (SELECT wallet_id FROM wallets WHERE user_id = ?)";
            $params[] = $userId;
        }
        if ($where) {
            $stmt = $pdo->prepare("
                SELECT $select FROM financial_holds
                WHERE (" . implode(' OR ', $where) . ")
                  AND UPPER(status) IN ('ACTIVE','HELD','PENDING','PENDING_CASHOUT')
                ORDER BY 1 DESC LIMIT 20
            ");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $holds[] = [
                    'hold_reference' => $h['hold_reference'],
                    'amount'         => (float)$h['amount'],
                    'status'         => strtoupper($h['status']),
                    'asset_type'     => strtoupper($h['asset_type'] ?? ''),
                    'held_by'        => THIS_BANK,
                    'going_to'       => $h['destination_institution'] ?? $h['participant_name'] ?? '—',
                    'swap_reference' => $h['swap_reference'] ?? null,
                    'expires_at'     => $h['hold_expiry'] ?? $h['expires_at'] ?? null,
                    'placed_at'      => $h['placed_at'] ?? $h['created_at'] ?? null,
                ];
            }
        }
    }
    $holdsTotal = array_sum(array_column($holds, 'amount'));

    // ================================================================
    // PENDING WALLET TRANSACTIONS (unchanged)
    // ================================================================
    $stmt = $pdo->prepare("
        SELECT id AS wallet_transaction_id, amount, transaction_type, status, created_at
        FROM wallet_transactions
        WHERE user_id = ? AND status = 'pending'
    ");
    $stmt->execute([$userId]);
    $pendingWallet = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingWallet as &$wt) $wt['amount'] = (float)$wt['amount'];
    unset($wt);
    $totalPending = array_sum(array_column($pendingWallet, 'amount'));

    // --- recent transactions ---
    $stmt = $pdo->prepare("
        SELECT transaction_id, amount, type, created_at
        FROM transactions WHERE user_id = ?
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($transactions as &$tx) $tx['amount'] = (float)$tx['amount'];
    unset($tx);

    // ================================================================
    // E-WALLET PINS — sent AND received, with direction
    // ================================================================
    $ewallets = [];
    if ($userPhone && tableExists($pdo, 'ewallet_pins')) {
        $stmt = $pdo->prepare("
            SELECT id, transaction_id, pin, is_redeemed, sender_phone, recipient_phone, amount,
                   created_at, expires_at, redeemed_at, hold_status, hold_reference, held_at,
                   regenerated_by, regeneration_fee, sat_purchased, sat_expires_at, sat_paid_by,
                   generated_by, redeemed_by
            FROM ewallet_pins
            WHERE sender_phone = ? OR recipient_phone = ?
            ORDER BY created_at DESC LIMIT 20
        ");
        $stmt->execute([$userPhone, $userPhone]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isRedeemed = in_array($row['is_redeemed'], ['true', '1', 1, true], true);
            $ewallets[] = [
                'id'               => (int)$row['id'],
                'direction'        => ($row['sender_phone'] === $userPhone) ? 'SENT' : 'RECEIVED',
                'counterparty'     => ($row['sender_phone'] === $userPhone) ? $row['recipient_phone'] : $row['sender_phone'],
                'institution'      => THIS_BANK,
                'transaction_id'   => $row['transaction_id'],
                'pin'              => ($row['sender_phone'] === $userPhone || !$isRedeemed) ? $row['pin'] : null,
                'is_redeemed'      => $isRedeemed,
                'sender_phone'     => $row['sender_phone'],
                'recipient_phone'  => $row['recipient_phone'],
                'amount'           => (float)$row['amount'],
                'created_at'       => $row['created_at'],
                'expires_at'       => $row['expires_at'],
                'redeemed_at'      => $row['redeemed_at'],
                'hold_status'      => $row['hold_status'],
                'hold_reference'   => $row['hold_reference'],
                'held_at'          => $row['held_at'],
                'regenerated_by'   => $row['regenerated_by'],
                'regeneration_fee' => (float)$row['regeneration_fee'],
                'sat_purchased'    => $row['sat_purchased'],
                'sat_expires_at'   => $row['sat_expires_at'],
                'sat_paid_by'      => $row['sat_paid_by'],
                'generated_by'     => $row['generated_by'],
                'redeemed_by'      => $row['redeemed_by'],
            ];
        }
    }

    // unredeemed cash sent out, still claimable by the recipient
    $unredeemedOut = 0.0;
    foreach ($ewallets as $e) {
        if ($e['direction'] === 'SENT' && !$e['is_redeemed']) $unredeemedOut += $e['amount'];
    }

    // ================================================================
    // TOTALS
    // ================================================================
    $totalBalance     = round($accountsTotal + $walletsTotal, 2);
    $totalHeld        = round($accountsHeld + $walletsHeld, 2);
    $availableBalance = round($totalBalance - $totalHeld - $totalPending, 2);

    echo json_encode([
        "status"     => "success",
        "username"   => $username,
        "role"       => $role,
        "userPhone"  => $userPhone,
        "institution" => THIS_BANK,

        "totalBalance"     => $totalBalance,
        "availableBalance" => $availableBalance,
        "balanceSummary"   => [
            "accounts"        => round($accountsTotal, 2),
            "wallets"         => round($walletsTotal, 2),
            "held"            => $totalHeld,
            "pending"         => round($totalPending, 2),
            "available"       => $availableBalance,
            "unredeemed_sent" => round($unredeemedOut, 2),
        ],

        "accounts"                  => $accounts,
        "wallets"                   => $wallets,
        "holds"                     => $holds,
        "holdsTotal"                => round($holdsTotal, 2),
        "recentTransactions"        => $transactions,
        "pendingWalletTransactions" => $pendingWallet,
        "ewallets"                  => $ewallets,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
