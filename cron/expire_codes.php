<?php
/**
 * cron/expire_codes.php — SACCUSSALIS
 *
 * Expires SAT cashout codes, and optionally releases the hold behind them.
 *
 * WHY CODES DIE AN HOUR EARLY
 * A code must never outlive its hold. If it does, a customer can present a
 * valid code at an ATM against funds that were already released — the money
 * is dispensed with nothing behind it. The one-hour margin absorbs clock
 * skew between institutions, in-flight dispense, and the settling time of
 * the hold-expiry job. Set CODE_LEAD_SECONDS at issue time, not here:
 * this job only enforces what was written.
 *
 * WHAT IT DOES
 *   1. expires ACTIVE codes past expires_at
 *   2. expires any code whose hold is already gone (orphan)
 *   3. if RELEASE_HOLD_ON_CODE_EXPIRY, releases the hold immediately rather
 *      than leaving the customer's money encumbered for another hour
 *
 * It never touches a code with processing = TRUE. That means an ATM is
 * mid-dispense; those go to manual review, never to an automatic void.
 *
 * Run every minute, one minute BEFORE expire_holds:
 *   * * * * * /usr/bin/php /var/www/saccussalis/cron/expire_codes.php >> /var/log/saccussalis/expire_codes.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../lib/hold_release.php';

const JOB        = 'expire_codes';
const BATCH_SIZE = 200;
const LOCK_KEY   = 8583002;

/** Release the hold as soon as its code dies, instead of waiting the hour. */
const RELEASE_HOLD_ON_CODE_EXPIRY = true;

/** Codes are issued to expire this long before the hold. Used by the audit below. */
const CODE_LEAD_SECONDS = 3600;

$startedAt = microtime(true);
$expired   = 0;
$released  = 0;
$deferred  = 0;
$failed    = 0;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[' . JOB . '] no database connection');
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$gotLock = $pdo->prepare('SELECT pg_try_advisory_lock(:k)');
$gotLock->execute([':k' => LOCK_KEY]);

if (!$gotLock->fetchColumn()) {
    error_log('[' . JOB . '] another instance is running — exiting');
    exit(0);
}

try {
    while (true) {
        $pdo->beginTransaction();

        // Past expiry, or the hold behind it is already resolved.
        $stmt = $pdo->prepare("
            SELECT t.sat_id, t.sat_number, t.instrument_id, t.amount, t.processing,
                   t.expires_at, t.hold_reference,
                   h.id AS hold_id, h.status AS hold_status, h.account_id, h.wallet_id,
                   h.asset_type, h.amount AS hold_amount, h.expires_at AS hold_expires_at,
                   h.hold_reference AS hold_ref
            FROM   sat_tokens t
            LEFT   JOIN financial_holds h ON h.hold_reference = t.hold_reference
            WHERE  t.status = 'ACTIVE'
              AND  ( t.expires_at < NOW()
                     OR (h.hold_reference IS NOT NULL AND h.status NOT IN ('ACTIVE','HELD')) )
            ORDER  BY t.expires_at
            LIMIT  :limit
            FOR UPDATE OF t SKIP LOCKED
        ");
        $stmt->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
        $stmt->execute();

        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$tokens) {
            $pdo->commit();
            break;
        }

        foreach ($tokens as $t) {
            try {
                // Each item is independent: a failure rolls back only this item.
                $pdo->exec('SAVEPOINT expiry_item');
                // Mid-dispense. Never void underneath a machine counting notes.
                if (!empty($t['processing'])) {
                    $deferred++;
                    error_log('[' . JOB . '] sat ' . $t['sat_number']
                        . ' is PROCESSING at expiry — left for manual review');
                    $pdo->exec('RELEASE SAVEPOINT expiry_item');
                    continue;
                }

                $pdo->prepare("
                    UPDATE sat_tokens
                    SET status = 'EXPIRED', expired_at = NOW(), processing = FALSE, updated_at = NOW()
                    WHERE sat_id = :id
                ")->execute([':id' => $t['sat_id']]);

                $pdo->prepare("
                    UPDATE cash_instruments
                    SET status = 'EXPIRED', expired_at = NOW(), updated_at = NOW()
                    WHERE instrument_id = :id AND status = 'ACTIVE'
                ")->execute([':id' => $t['instrument_id']]);

                $expired++;
                error_log(sprintf('[%s] expired sat=%s amount=%.2f hold=%s',
                    JOB, $t['sat_number'], (float)$t['amount'], $t['hold_reference'] ?? 'none'));

                // Give the money back now rather than in an hour.
                if (RELEASE_HOLD_ON_CODE_EXPIRY
                    && !empty($t['hold_id'])
                    && in_array(strtoupper((string)$t['hold_status']), ['ACTIVE','HELD'], true)) {

                    $lock = $pdo->prepare("
                        SELECT id, hold_reference, account_id, wallet_id, amount,
                               asset_type, status, expires_at
                        FROM   financial_holds
                        WHERE  id = :id
                        FOR UPDATE
                    ");
                    $lock->execute([':id' => $t['hold_id']]);
                    $hold = $lock->fetch(PDO::FETCH_ASSOC);

                    if ($hold) {
                        $r = release_hold($pdo, $hold, 'CODE_EXPIRED', 'cron:' . JOB);
                        if ($r['released']) {
                            $released++;
                            error_log(sprintf('[%s] released hold %s (%.2f) after code expiry',
                                JOB, $hold['hold_reference'], $r['amount']));
                        }
                    }
                }
                $pdo->exec('RELEASE SAVEPOINT expiry_item');

            } catch (Throwable $e) {
                try { $pdo->exec('ROLLBACK TO SAVEPOINT expiry_item'); } catch (Throwable $ignore) {}
                $failed++;
                error_log('[' . JOB . '] FAILED sat ' . ($t['sat_number'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        $pdo->commit();

        if (count($tokens) < BATCH_SIZE || $failed >= BATCH_SIZE) {
            break;
        }
    }

    // AUDIT: any live code whose expiry is not comfortably inside its hold's.
    // This catches codes issued without the lead time applied at generation.
    $bad = $pdo->prepare("
        SELECT t.sat_number, t.expires_at, h.expires_at AS hold_expires_at
        FROM   sat_tokens t
        JOIN   financial_holds h ON h.hold_reference = t.hold_reference
        WHERE  t.status = 'ACTIVE'
          AND  h.status IN ('ACTIVE','HELD')
          AND  t.expires_at > (h.expires_at - (:lead || ' seconds')::interval)
    ");
    $bad->bindValue(':lead', (string) CODE_LEAD_SECONDS);
    $bad->execute();
    $badRows = $bad->fetchAll(PDO::FETCH_ASSOC);

    foreach ($badRows as $b) {
        error_log(sprintf('[%s] ALERT code %s expires %s but hold expires %s — lead time not applied at issue',
            JOB, $b['sat_number'], $b['expires_at'], $b['hold_expires_at']));
    }

    $orphanCodes = (int) $pdo->query('SELECT count(*) FROM v_orphaned_codes')->fetchColumn();

    log_cron_run($pdo, JOB, $startedAt, $expired, $failed,
        sprintf('released=%d deferred=%d lead_violations=%d orphan_codes=%d',
            $released, $deferred, count($badRows), $orphanCodes));

    error_log(sprintf('[%s] done: expired=%d released=%d deferred=%d failed=%d in %.2fs',
        JOB, $expired, $released, $deferred, $failed, microtime(true) - $startedAt));

    exit($failed > 0 || $orphanCodes > 0 || $badRows ? 1 : 0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[' . JOB . '] FATAL: ' . $e->getMessage());
    log_cron_run($pdo, JOB, $startedAt, $expired, $failed + 1, 'fatal: ' . $e->getMessage());
    exit(1);

} finally {
    $pdo->prepare('SELECT pg_advisory_unlock(:k)')->execute([':k' => LOCK_KEY]);
}
