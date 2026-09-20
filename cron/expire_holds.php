<?php
/**
 * cron/expire_holds.php — SACCUSSALIS
 *
 * Releases every hold whose expires_at has passed, restoring the amount to
 * the customer's available balance.
 *
 * This job is the whole basis of the non-custodial claim: a hold must come
 * off the customer's balance whether or not VouchMorph sends anything, and
 * whether or not VouchMorph is running at all. Nothing here calls out to
 * VouchMorph and nothing here waits for it.
 *
 * Expiry wins: once this job flips a hold to EXPIRED, hold.php's DEBIT
 * branch rejects it, because DEBIT only accepts ACTIVE or HELD. The row
 * lock makes that decision atomic at the boundary second.
 *
 * Run every minute:
 *   * * * * * /usr/bin/php /var/www/saccussalis/cron/expire_holds.php >> /var/log/saccussalis/expire_holds.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../lib/hold_release.php';

const JOB          = 'expire_holds';
const BATCH_SIZE   = 200;
const LOCK_KEY     = 8583001;   // advisory lock: one instance at a time
const GRACE_SECONDS = 0;        // set >0 to leave a settling margin

$startedAt = microtime(true);
$processed = 0;
$failed    = 0;
$skipped   = 0;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[' . JOB . '] no database connection');
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Single instance. A second copy exits quietly rather than fighting for rows.
$gotLock = $pdo->prepare('SELECT pg_try_advisory_lock(:k)');
$gotLock->execute([':k' => LOCK_KEY]);

if (!$gotLock->fetchColumn()) {
    error_log('[' . JOB . '] another instance is running — exiting');
    exit(0);
}

try {
    while (true) {
        // Claim a batch. SKIP LOCKED steps over rows a debit is mid-way
        // through; those get picked up on the next pass, correctly resolved.
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, hold_reference, account_id, wallet_id, amount,
                   asset_type, status, expires_at
            FROM   financial_holds
            WHERE  status IN ('ACTIVE','HELD')
              AND  expires_at < (NOW() - (:grace || ' seconds')::interval)
            ORDER  BY expires_at
            LIMIT  :limit
            FOR UPDATE SKIP LOCKED
        ");
        $stmt->bindValue(':grace', (string) GRACE_SECONDS);
        $stmt->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
        $stmt->execute();

        $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$holds) {
            $pdo->commit();
            break;
        }

        foreach ($holds as $hold) {
            try {
                // A live code against a dead hold would be redeemable with
                // nothing behind it. Void first, release second.
                void_codes_for_hold($pdo, $hold['hold_reference'], 'HOLD_EXPIRED');

                $result = release_hold($pdo, $hold, 'EXPIRED', 'cron:' . JOB);

                if ($result['released']) {
                    $processed++;
                    error_log(sprintf(
                        '[%s] released %s asset=%s amount=%.2f held %.2f->%.2f latency=%ss',
                        JOB, $hold['hold_reference'], $hold['asset_type'], $result['amount'],
                        $result['held_before'], $result['held_after'],
                        $result['latency_seconds'] ?? '?'
                    ));
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                // One bad hold must not abort the batch. Note it, carry on;
                // the transaction still commits the rest.
                $failed++;
                error_log('[' . JOB . '] FAILED ' . $hold['hold_reference'] . ': ' . $e->getMessage());
            }
        }

        $pdo->commit();

        if (count($holds) < BATCH_SIZE) {
            break;
        }
    }

    // Anything still past expiry after a full pass is an orphan: funds are
    // encumbered with no live hold behind them. Alert, do not swallow.
    $orphans = (int) $pdo->query('SELECT count(*) FROM v_orphaned_holds')->fetchColumn();

    if ($orphans > 0) {
        error_log('[' . JOB . '] ALERT ' . $orphans . ' orphaned hold(s) remain past expiry');
    }

    log_cron_run($pdo, JOB, $startedAt, $processed, $failed,
        sprintf('skipped=%d orphans_remaining=%d', $skipped, $orphans));

    error_log(sprintf('[%s] done: released=%d skipped=%d failed=%d orphans=%d in %.2fs',
        JOB, $processed, $skipped, $failed, $orphans, microtime(true) - $startedAt));

    exit($failed > 0 || $orphans > 0 ? 1 : 0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[' . JOB . '] FATAL: ' . $e->getMessage());
    log_cron_run($pdo, JOB, $startedAt, $processed, $failed + 1, 'fatal: ' . $e->getMessage());
    exit(1);

} finally {
    $pdo->prepare('SELECT pg_advisory_unlock(:k)')->execute([':k' => LOCK_KEY]);
}
