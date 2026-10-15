<?php
/**
 * cron/repair_held_balances.php — SACCUSSALIS, one-off repair (2026-10-15)
 *
 * Before the partial-capture fix, a debit for part of a hold closed the whole
 * hold as DEBITED and left the unused part in held_balance for good: the
 * customer saw the money but could not spend it, and release and expiry both
 * skipped the hold (VouchMorph saw "Hold is not active (status: DEBITED)" on
 * card-hook releases every five minutes).
 *
 * held_balance must equal the sum of the account's (or wallet's) live holds.
 * This lists every account and wallet where it does not, and with --apply sets
 * it to that sum and records each change in held_balance_repairs.
 *
 *   php cron/repair_held_balances.php            dry run: lists the differences
 *   php cron/repair_held_balances.php --apply    corrects them
 *
 * A negative difference (less held than live holds need) is listed but never
 * applied: that needs a person.
 */
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$apply = in_array('--apply', $argv ?? [], true);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = [];
foreach ([['accounts', 'account_id', 'account_number'], ['wallets', 'wallet_id', 'phone']] as [$table, $id, $label]) {
    $q = $pdo->query("
        SELECT '{$table}' AS kind, t.{$id} AS id, t.{$label}::text AS label, COALESCE(t.held_balance, 0) AS held_balance,
               COALESCE(h.live, 0) AS live_holds, COALESCE(t.held_balance, 0) - COALESCE(h.live, 0) AS stuck
        FROM {$table} t
        LEFT JOIN (SELECT {$id}, SUM(amount) AS live FROM financial_holds
                   WHERE status IN ('ACTIVE', 'HELD') AND {$id} IS NOT NULL GROUP BY {$id}) h ON h.{$id} = t.{$id}
        WHERE ABS(COALESCE(t.held_balance, 0) - COALESCE(h.live, 0)) > 0.005
        ORDER BY 6 DESC
    ");
    $rows = array_merge($rows, $q->fetchAll(PDO::FETCH_ASSOC));
}

if (!$rows) {
    echo "Every held_balance matches its live holds.\n";
    exit(0);
}

printf("%-9s %-14s %14s %14s %14s\n", 'kind', 'account', 'held_balance', 'live holds', 'stuck');
foreach ($rows as $r) {
    printf("%-9s %-14s %14.2f %14.2f %14.2f%s\n", $r['kind'], $r['label'], $r['held_balance'], $r['live_holds'], $r['stuck'],
        $r['stuck'] < 0 ? '  (less held than live holds: check by hand)' : '');
}
if (!$apply) {
    echo "\nDry run. Run with --apply to set held_balance to the live holds for the positive rows.\n";
    exit(0);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS held_balance_repairs (
    id SERIAL PRIMARY KEY, kind VARCHAR(10), asset_id BIGINT, before_amount NUMERIC(20,4), after_amount NUMERIC(20,4),
    released NUMERIC(20,4), repaired_at TIMESTAMP NOT NULL DEFAULT NOW())");
$fixed = 0;
foreach ($rows as $r) {
    if ($r['stuck'] < 0) continue;
    $id = $r['kind'] === 'accounts' ? 'account_id' : 'wallet_id';
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE {$r['kind']} SET held_balance = ? WHERE {$id} = ?")->execute([$r['live_holds'], $r['id']]);
    $pdo->prepare("INSERT INTO held_balance_repairs (kind, asset_id, before_amount, after_amount, released) VALUES (?, ?, ?, ?, ?)")
        ->execute([$r['kind'], $r['id'], $r['held_balance'], $r['live_holds'], $r['stuck']]);
    $pdo->commit();
    $fixed++;
}
echo "\nCorrected {$fixed}; each change is in held_balance_repairs.\n";
