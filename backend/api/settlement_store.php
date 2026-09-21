<?php
// saccussalis/backend/api/settlement_store.php
// How the settlement desk reaches SaccusSalis' books. Internal accounts
// belong to the bank's operational user.
require_once __DIR__ . '/settlement_desk.php';

function saccussalis_settlement_store(PDO $pdo): array {
    return [
        'ensure_account' => function (string $no, string $name) use ($pdo): void {
            $pdo->prepare("
                INSERT INTO accounts (user_id, account_number, account_type, currency, balance)
                SELECT COALESCE((SELECT user_id FROM users WHERE full_name = 'Saccus Operational Account' ORDER BY user_id LIMIT 1),
                                (SELECT MIN(user_id) FROM users)), ?, 'internal', 'BWP', 0
                ON CONFLICT (account_number) DO NOTHING
            ")->execute([$no]);
        },
        'move' => function (string $no, float $delta) use ($pdo): void {
            $s = $pdo->prepare("SELECT account_id FROM accounts WHERE account_number = ? FOR UPDATE");
            $s->execute([$no]);
            $id = $s->fetchColumn();
            if ($id === false) throw new RuntimeException("Account {$no} not found at SaccusSalis");
            $pdo->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE account_id = ?")->execute([$delta, $id]);
        },
        'log' => function (array $e) use ($pdo): void {
            $pdo->prepare("
                INSERT INTO transactions (user_id, reference, from_account, to_account, amount, fee_amount, type, direction, channel, status, notes)
                VALUES ((SELECT user_id FROM accounts WHERE account_number = ?), ?, ?, ?, ?, 0, 'vm_settlement', ?, 'central_bank', ?, ?)
            ")->execute([$e['from'], $e['reference'], $e['from'], $e['to'], $e['amount'], $e['direction'] === 'out' ? 'out' : 'internal', $e['status'], $e['note']]);
        },
    ];
}

function saccussalis_desk(PDO $pdo): SettlementDesk {
    $base = rtrim(getenv('SACCUSSALIS_PUBLIC_URL') ?: 'https://saccussalis-production.up.railway.app', '/');
    return new SettlementDesk($pdo, 'SACCUSSALIS', saccussalis_settlement_store($pdo), (string)getenv('CENTRAL_BANK_API_SECRET'),
        $base . '/backend/api/bank_callback.php');
}
