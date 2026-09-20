<?php
/**
 * lib/hold_release.php — SACCUSSALIS
 *
 * One release path, used by cron/expire_holds.php, cron/expire_codes.php
 * and (optionally) the RELEASE branch of api/v1/hold.php.
 *
 * Rules enforced here:
 *   - the caller must already hold a row lock on the hold (FOR UPDATE)
 *   - release is idempotent: an already-released hold returns released=false
 *     with reason ALREADY_RESOLVED, never an error
 *   - CARD holds release against the linked account, because a card carries
 *     no balance of its own
 *   - every release writes hold_release_log with the held_balance before and
 *     after, which is the only real proof the funds came back
 */

declare(strict_types=1);

/**
 * Release a single hold. Call INSIDE an open transaction, after selecting
 * the hold row FOR UPDATE.
 *
 * @param array  $hold    row from financial_holds (needs id, hold_reference,
 *                        account_id, wallet_id, amount, asset_type, status,
 *                        expires_at)
 * @param string $reason  EXPIRED | CODE_EXPIRED | REQUESTED
 * @param string $actor   e.g. 'cron:expire_holds'
 */
function release_hold(PDO $pdo, array $hold, string $reason, string $actor): array
{
    $ref    = $hold['hold_reference'];
    $status = strtoupper((string)$hold['status']);

    // Idempotency: DEBITED / RELEASED / EXPIRED are all terminal.
    if (!in_array($status, ['ACTIVE', 'HELD'], true)) {
        return [
            'released'       => false,
            'reason'         => 'ALREADY_RESOLVED',
            'hold_reference' => $ref,
            'status'         => $status,
        ];
    }

    $amount    = (float)$hold['amount'];
    $assetType = strtoupper((string)$hold['asset_type']);

    // CARD holds sit on the linked account — same table as ACCOUNT.
    $usesAccount = in_array($assetType, ['ACCOUNT', 'CARD'], true) && !empty($hold['account_id']);
    $usesWallet  = $assetType === 'WALLET' && !empty($hold['wallet_id']);

    if (!$usesAccount && !$usesWallet) {
        throw new RuntimeException("Hold {$ref}: no valid account_id/wallet_id to release against");
    }

    if ($usesAccount) {
        $table = 'accounts';
        $idCol = 'account_id';
        $idVal = (int)$hold['account_id'];
    } else {
        $table = 'wallets';
        $idCol = 'wallet_id';
        $idVal = (int)$hold['wallet_id'];
    }

    // Lock the balance row and capture held_balance before the change.
    $stmt = $pdo->prepare("SELECT held_balance FROM {$table} WHERE {$idCol} = :id FOR UPDATE");
    $stmt->execute([':id' => $idVal]);
    $heldBefore = $stmt->fetchColumn();

    if ($heldBefore === false) {
        throw new RuntimeException("Hold {$ref}: {$table}.{$idCol}={$idVal} not found");
    }
    $heldBefore = (float)$heldBefore;

    $stmt = $pdo->prepare("
        UPDATE {$table}
        SET held_balance = GREATEST(0, COALESCE(held_balance, 0) - :amount)
        WHERE {$idCol} = :id
        RETURNING held_balance
    ");
    $stmt->execute([':amount' => $amount, ':id' => $idVal]);
    $heldAfter = (float)$stmt->fetchColumn();

    // GREATEST(0, ...) silently absorbs an over-release. Log it loudly:
    // it means held_balance and financial_holds have already diverged.
    $expectedAfter = round(max(0.0, $heldBefore - $amount), 2);
    if (round($heldAfter, 2) !== $expectedAfter || $heldBefore < $amount) {
        error_log("HOLD_RELEASE: WARNING ledger drift on {$ref} — held_before={$heldBefore}, "
            . "amount={$amount}, held_after={$heldAfter}");
    }

    $newStatus = ($reason === 'EXPIRED') ? 'EXPIRED' : 'RELEASED';

    $stmt = $pdo->prepare("
        UPDATE financial_holds
        SET status = :status, released_at = NOW(), release_reason = :reason, released_by = :actor
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $newStatus,
        ':reason' => $reason,
        ':actor'  => $actor,
        ':id'     => $hold['id'],
    ]);

    $latency = null;
    if (!empty($hold['expires_at'])) {
        $latency = max(0, time() - strtotime((string)$hold['expires_at']));
    }

    $stmt = $pdo->prepare("
        INSERT INTO hold_release_log
            (hold_reference, hold_id, asset_type, account_id, wallet_id, amount,
             held_before, held_after, reason, released_by, expires_at, latency_seconds)
        VALUES
            (:ref, :hold_id, :asset_type, :account_id, :wallet_id, :amount,
             :held_before, :held_after, :reason, :actor, :expires_at, :latency)
    ");
    $stmt->execute([
        ':ref'         => $ref,
        ':hold_id'     => $hold['id'],
        ':asset_type'  => $assetType,
        ':account_id'  => $hold['account_id'] ?: null,
        ':wallet_id'   => $hold['wallet_id'] ?: null,
        ':amount'      => $amount,
        ':held_before' => $heldBefore,
        ':held_after'  => $heldAfter,
        ':reason'      => $reason,
        ':actor'       => $actor,
        ':expires_at'  => $hold['expires_at'] ?? null,
        ':latency'     => $latency,
    ]);

    return [
        'released'       => true,
        'hold_reference' => $ref,
        'status'         => $newStatus,
        'amount'         => $amount,
        'held_before'    => $heldBefore,
        'held_after'     => $heldAfter,
        'latency_seconds'=> $latency,
    ];
}

/** Void any live cashout code attached to a hold. Call inside the same txn. */
function void_codes_for_hold(PDO $pdo, string $holdReference, string $reason): int
{
    $stmt = $pdo->prepare("
        SELECT sat_id, instrument_id, processing
        FROM sat_tokens
        WHERE hold_reference = :ref AND status = 'ACTIVE'
        FOR UPDATE
    ");
    $stmt->execute([':ref' => $holdReference]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $voided = 0;

    foreach ($tokens as $t) {
        // Mid-dispense: do not void underneath an ATM that is counting notes.
        if (!empty($t['processing'])) {
            error_log("HOLD_RELEASE: sat_id={$t['sat_id']} is PROCESSING — left for manual review");
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

        $voided++;
    }

    if ($voided > 0) {
        error_log("HOLD_RELEASE: voided {$voided} code(s) for hold {$holdReference} ({$reason})");
    }

    return $voided;
}

/** Write one row per cron execution. */
function log_cron_run(PDO $pdo, string $job, float $startedAt, int $processed, int $failed, string $detail = ''): void
{
    $stmt = $pdo->prepare("
        INSERT INTO cron_runs (job, started_at, processed, failed, detail)
        VALUES (:job, to_timestamp(:started), :processed, :failed, :detail)
    ");
    $stmt->execute([
        ':job'       => $job,
        ':started'   => $startedAt,
        ':processed' => $processed,
        ':failed'    => $failed,
        ':detail'    => $detail,
    ]);
}
