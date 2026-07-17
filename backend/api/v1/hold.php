<?php
/**
 * hold.php - SACCUSSALIS VERSION
 * Place, release, and debit holds on account or wallet funds
 *
 * CRITICAL FIX vs previous version:
 * The old version NEVER checked $input['action'] - every request, whether
 * it was PLACE_HOLD, RELEASE_HOLD, or DEBIT, ran the same "place a new
 * hold" logic. RELEASE_HOLD/DEBIT payloads don't include 'amount' or
 * 'asset_type' (they identify the target via 'hold_reference' instead),
 * so they failed the required-fields check and returned an error - with
 * NO error_log() call on that path, so it looked like nothing happened.
 * VOUCHMORPH's SwapService marks its own hold_transactions row RELEASED
 * or DEBITED regardless of whether this endpoint actually did anything,
 * so central records could show a hold as resolved while SACCUSSALIS
 * still has it reserved. This version actually dispatches on $action.
 *
 * Reservation model (unchanged, now correctly honored end-to-end):
 *   - HOLD:    held_balance += amount   (balance untouched, funds reserved)
 *   - RELEASE: held_balance -= amount   (un-reserve, balance untouched)
 *   - DEBIT:   balance -= amount AND held_balance -= amount (funds actually leave)
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';

header("Content-Type: application/json");

error_log("=== HOLD.PHP RECEIVED ===" . json_encode($_POST));

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    $input = $_POST;
}

if (empty($input)) {
    echo json_encode([
        "status" => "ERROR",
        "hold_placed" => false,
        "message" => "No input data received"
    ]);
    exit;
}

error_log("HOLD: Input received: " . json_encode($input));

$action = strtoupper(trim($input['action'] ?? 'PLACE_HOLD'));
$isPlaceAction = in_array($action, ['HOLD', 'PLACE', 'PLACE_HOLD']);
$isReleaseAction = in_array($action, ['RELEASE', 'RELEASE_HOLD', 'UNHOLD']);
$isDebitAction = in_array($action, ['DEBIT', 'DEBIT_HOLD', 'DEBIT_FUNDS']);

if (!$isPlaceAction && !$isReleaseAction && !$isDebitAction) {
    error_log("HOLD: Unsupported action: {$action}");
    echo json_encode([
        "status" => "ERROR",
        "hold_placed" => false,
        "message" => "Unsupported action: {$action}"
    ]);
    exit;
}

// ============================================================
// VALIDATE REQUIRED FIELDS - different per action.
// PLACE needs amount + asset_type + an identifier to open a new hold.
// RELEASE/DEBIT act on an EXISTING hold and identify it by
// hold_reference (falling back to 'reference' for backward
// compatibility with any caller still using that key).
// ============================================================
if ($isPlaceAction) {
    $required = ['action', 'amount', 'asset_type'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Missing required field: {$field}"
            ]);
            exit;
        }
    }
} else {
    $targetHoldReference = $input['hold_reference'] ?? $input['reference'] ?? null;
    if (empty($targetHoldReference)) {
        echo json_encode([
            "status" => "ERROR",
            "message" => "Missing required field: hold_reference"
        ]);
        exit;
    }
}

$amount = (float)($input['amount'] ?? 0);
$assetType = strtoupper($input['asset_type'] ?? 'ACCOUNT');
$holdReason = $input['hold_reason'] ?? 'PENDING_SWAP';
$holdReference = $input['reference'] ?? 'HOLD_' . uniqid();
$expiry = $input['expiry'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'));
$requester = $input['requester'] ?? 'VOUCHMORPH';
$destinationInstitution = $input['destination_institution'] ?? null;

$identifier = $input['source_identifier'] ??
              $input['phone'] ??
              $input['account_number'] ??
              $input['wallet_phone'] ??
              $input['email'] ??
              $input['national_id'] ??
              null;

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed");
    }

    // ================================================================
    // RELEASE_HOLD - un-reserve funds against an existing ACTIVE hold.
    // Looked up purely by hold_reference; asset type/account come from
    // the financial_holds row itself, not from the caller.
    // ================================================================
    if ($isReleaseAction) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT id, account_id, wallet_id, amount, asset_type, status
                FROM financial_holds
                WHERE hold_reference = :ref
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':ref' => $targetHoldReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                $pdo->rollBack();
                error_log("HOLD: RELEASE - no hold found for reference {$targetHoldReference}");
                echo json_encode([
                    "status" => "ERROR",
                    "released" => false,
                    "message" => "No hold found for reference: {$targetHoldReference}"
                ]);
                exit;
            }

            // ============================================================
            // FIX: 'HELD' is confirmed legacy terminology for the same
            // "currently reserved" state as 'ACTIVE' - status vocabulary
            // check showed HELD (Feb 28-Jul 14) and ACTIVE (Jul 16 on)
            // never overlap, i.e. one word replaced the other at a
            // deployment cutover. 'COMMITTED' is NOT included here - its
            // date range overlapped WITH 'HELD' rather than following it,
            // meaning it's a different, already-terminal legacy status
            // (old vocabulary for DEBITED), not a reserved state.
            // ============================================================
            if (!in_array($hold['status'], ['ACTIVE', 'HELD'], true)) {
                $pdo->rollBack();
                error_log("HOLD: RELEASE - hold {$targetHoldReference} is not in a releasable state (status: {$hold['status']})");
                echo json_encode([
                    "status" => "ERROR",
                    "released" => false,
                    "message" => "Hold is not active (status: {$hold['status']})"
                ]);
                exit;
            }

            $holdAmount = (float)$hold['amount'];

            if ($hold['asset_type'] === 'ACCOUNT' && $hold['account_id']) {
                $stmt = $pdo->prepare("
                    UPDATE accounts
                    SET held_balance = GREATEST(0, COALESCE(held_balance, 0) - :amount)
                    WHERE account_id = :account_id
                ");
                $stmt->execute([':amount' => $holdAmount, ':account_id' => $hold['account_id']]);
            } elseif ($hold['asset_type'] === 'WALLET' && $hold['wallet_id']) {
                $stmt = $pdo->prepare("
                    UPDATE wallets
                    SET held_balance = GREATEST(0, COALESCE(held_balance, 0) - :amount)
                    WHERE wallet_id = :wallet_id
                ");
                $stmt->execute([':amount' => $holdAmount, ':wallet_id' => $hold['wallet_id']]);
            } else {
                $pdo->rollBack();
                throw new Exception("Hold {$targetHoldReference} has no valid account_id/wallet_id to release against");
            }

            $stmt = $pdo->prepare("UPDATE financial_holds SET status = 'RELEASED' WHERE id = :id");
            $stmt->execute([':id' => $hold['id']]);

            $pdo->commit();

            error_log("HOLD: RELEASED hold_reference={$targetHoldReference}, amount={$holdAmount}");

            $responsePayload = [
                "status" => "SUCCESS",
                "released" => true,
                "hold_placed" => false,
                "hold_reference" => $targetHoldReference,
                "amount" => $holdAmount,
                "asset_type" => $hold['asset_type'],
                "message" => "Hold released successfully"
            ];
            send_signed_response($responsePayload);

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("HOLD: RELEASE PDO ERROR: " . $e->getMessage());
            echo json_encode([
                "status" => "ERROR",
                "released" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        exit;
    }

    // ================================================================
    // DEBIT - finalize an existing ACTIVE hold: funds actually leave
    // the account (balance -= amount) and the reservation clears
    // (held_balance -= amount).
    // ================================================================
    if ($isDebitAction) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT id, account_id, wallet_id, amount, asset_type, status
                FROM financial_holds
                WHERE hold_reference = :ref
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':ref' => $targetHoldReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                $pdo->rollBack();
                error_log("HOLD: DEBIT - no hold found for reference {$targetHoldReference}");
                echo json_encode([
                    "status" => "ERROR",
                    "debited" => false,
                    "message" => "No hold found for reference: {$targetHoldReference}"
                ]);
                exit;
            }

            // Same widened check as RELEASE_HOLD above - see comment there
            // for why HELD is included and COMMITTED deliberately is not.
            if (!in_array($hold['status'], ['ACTIVE', 'HELD'], true)) {
                $pdo->rollBack();
                error_log("HOLD: DEBIT - hold {$targetHoldReference} is not in a debitable state (status: {$hold['status']})");
                echo json_encode([
                    "status" => "ERROR",
                    "debited" => false,
                    "message" => "Hold is not active (status: {$hold['status']})"
                ]);
                exit;
            }

            $holdAmount = (float)$hold['amount'];

            if ($hold['asset_type'] === 'ACCOUNT' && $hold['account_id']) {
                $stmt = $pdo->prepare("
                    UPDATE accounts
                    SET balance = balance - :amount,
                        held_balance = GREATEST(0, COALESCE(held_balance, 0) - :amount2)
                    WHERE account_id = :account_id
                    AND balance >= :amount3
                    RETURNING balance
                ");
                $stmt->execute([
                    ':amount' => $holdAmount,
                    ':amount2' => $holdAmount,
                    ':amount3' => $holdAmount,
                    ':account_id' => $hold['account_id']
                ]);
                $updated = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$updated) {
                    $pdo->rollBack();
                    throw new Exception("Insufficient balance to debit account {$hold['account_id']}");
                }
            } elseif ($hold['asset_type'] === 'WALLET' && $hold['wallet_id']) {
                $stmt = $pdo->prepare("
                    UPDATE wallets
                    SET balance = balance - :amount,
                        held_balance = GREATEST(0, COALESCE(held_balance, 0) - :amount2)
                    WHERE wallet_id = :wallet_id
                    AND balance >= :amount3
                    RETURNING balance
                ");
                $stmt->execute([
                    ':amount' => $holdAmount,
                    ':amount2' => $holdAmount,
                    ':amount3' => $holdAmount,
                    ':wallet_id' => $hold['wallet_id']
                ]);
                $updated = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$updated) {
                    $pdo->rollBack();
                    throw new Exception("Insufficient balance to debit wallet {$hold['wallet_id']}");
                }
            } else {
                $pdo->rollBack();
                throw new Exception("Hold {$targetHoldReference} has no valid account_id/wallet_id to debit against");
            }

            $stmt = $pdo->prepare("UPDATE financial_holds SET status = 'DEBITED' WHERE id = :id");
            $stmt->execute([':id' => $hold['id']]);

            $pdo->commit();

            error_log("HOLD: DEBITED hold_reference={$targetHoldReference}, amount={$holdAmount}");

            $responsePayload = [
                "status" => "SUCCESS",
                "debited" => true,
                "hold_reference" => $targetHoldReference,
                "amount" => $holdAmount,
                "asset_type" => $hold['asset_type'],
                "total_balance" => floatval($updated['balance']),
                "message" => "Hold debited successfully"
            ];
            send_signed_response($responsePayload);

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("HOLD: DEBIT PDO ERROR: " . $e->getMessage());
            echo json_encode([
                "status" => "ERROR",
                "debited" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        exit;
    }

    // ================================================================
    // PLACE_HOLD (original logic, transactional + duplicate-checked)
    // ================================================================
    if (!$identifier) {
        echo json_encode([
            "status" => "ERROR",
            "hold_placed" => false,
            "message" => "No identifier provided. Required: source_identifier, phone, account_number, or wallet_phone"
        ]);
        exit;
    }

    error_log("HOLD: assetType={$assetType}, identifier={$identifier}, amount={$amount}, action={$action}");

    if (isset($input['certificate']) && !empty($input['certificate'])) {
        try {
            if (class_exists('Infrastructure\Crypto\CertificateManager')) {
                $certManager = new CertificateManager('SACCUSSALIS');
                $verification = $certManager->verifySignedRequest($input);
                if (!$verification['valid']) {
                    error_log("HOLD: Certificate verification FAILED: " . ($verification['message'] ?? 'Unknown error'));
                    echo json_encode([
                        "status" => "ERROR",
                        "hold_placed" => false,
                        "message" => "Certificate verification failed: " . ($verification['message'] ?? 'Invalid signature')
                    ]);
                    exit;
                }
                error_log("HOLD: Verified from " . ($verification['requester'] ?? 'unknown'));
            } else {
                error_log("HOLD: CertificateManager class not available, skipping verification");
            }
        } catch (Exception $e) {
            error_log("HOLD: Certificate verification exception: " . $e->getMessage());
        }
    }

    $dupCheck = $pdo->prepare("SELECT 1 FROM financial_holds WHERE hold_reference = :ref LIMIT 1");
    $dupCheck->execute([':ref' => $holdReference]);
    if ($dupCheck->fetchColumn()) {
        error_log("HOLD: Duplicate hold_reference rejected before any balance change: {$holdReference}");
        echo json_encode([
            "status" => "ERROR",
            "hold_placed" => false,
            "message" => "Duplicate hold_reference: {$holdReference} already exists"
        ]);
        exit;
    }

    if ($assetType === 'ACCOUNT') {
        error_log("HOLD: Processing ACCOUNT hold for: {$identifier}");

        $stmt = $pdo->prepare("
            SELECT
                a.account_id, a.account_number, a.account_type, a.currency,
                a.balance, a.held_balance, a.is_frozen, a.created_at,
                u.full_name, u.kyc_status
            FROM accounts a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE a.account_number = :identifier
               OR a.account_id = :identifier_id
            LIMIT 1
        ");
        $stmt->execute([
            ':identifier' => $identifier,
            ':identifier_id' => is_numeric($identifier) ? (int)$identifier : 0
        ]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            error_log("HOLD: Account not found: {$identifier}");
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Account not found: {$identifier}"
            ]);
            exit;
        }

        $accountId = $account['account_id'];
        $balance = (float)$account['balance'];
        $heldBalance = (float)($account['held_balance'] ?? 0);
        $availableBalance = $balance - $heldBalance;

        error_log("Account found: ID={$accountId}, Balance={$balance}, Held={$heldBalance}, Available={$availableBalance}");

        if (!empty($account['is_frozen']) && $account['is_frozen'] == true) {
            echo json_encode(["status" => "ERROR", "hold_placed" => false, "message" => "Account is frozen"]);
            exit;
        }

        if ($availableBalance < $amount) {
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Insufficient balance. Available: {$availableBalance}, Required: {$amount}"
            ]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE accounts
                SET held_balance = COALESCE(held_balance, 0) + :amount
                WHERE account_id = :account_id
            ");
            $stmt->execute([':amount' => $amount, ':account_id' => $accountId]);

            error_log("Account held_balance updated: account_id={$accountId}, amount={$amount}");

            $stmt = $pdo->prepare("
                INSERT INTO financial_holds
                (account_id, amount, hold_reference, session_id, status, expires_at, created_at, asset_type, requester, foreign_bank)
                VALUES
                (:account_id, :amount, :hold_reference, :session_id, 'ACTIVE', :expires_at, NOW(), 'ACCOUNT', :requester, :foreign_bank)
            ");
            $stmt->execute([
                ':account_id' => $accountId,
                ':amount' => $amount,
                ':hold_reference' => $holdReference,
                ':session_id' => $holdReference,
                ':expires_at' => $expiry,
                ':requester' => $requester,
                ':foreign_bank' => $destinationInstitution
            ]);

            $holdId = $pdo->lastInsertId();
            $pdo->commit();

            error_log("ACCOUNT hold placed successfully: hold_id={$holdId}, account_id={$accountId}, reference={$holdReference}");

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("HOLD: PLACE transaction rolled back for account_id={$accountId}, reference={$holdReference}: " . $e->getMessage());
            echo json_encode(["status" => "ERROR", "hold_placed" => false, "message" => "Database error: " . $e->getMessage()]);
            exit;
        }

        $responsePayload = [
            "status" => "SUCCESS",
            "hold_placed" => true,
            "hold_reference" => $holdReference,
            "session_id" => $holdReference,
            "hold_id" => $holdId,
            "asset_type" => "ACCOUNT",
            "account_id" => $accountId,
            "amount" => $amount,
            "available_balance" => $availableBalance - $amount,
            "message" => "Hold placed successfully on ACCOUNT",
            "requester" => $requester
        ];
        send_signed_response($responsePayload);

    } elseif ($assetType === 'WALLET' || $assetType === 'BANK-WALLET') {
        error_log("HOLD: Processing WALLET hold for: {$identifier}");

        $stmt = $pdo->prepare("
            SELECT
                w.wallet_id, w.user_id, w.phone, w.wallet_type, w.currency,
                w.balance, w.held_balance, w.is_frozen, w.status,
                u.full_name, u.kyc_status
            FROM wallets w
            LEFT JOIN users u ON w.user_id = u.user_id
            WHERE w.phone = :identifier
               OR w.wallet_id = :identifier_id
               OR u.email = :identifier
            LIMIT 1
        ");
        $stmt->execute([
            ':identifier' => $identifier,
            ':identifier_id' => is_numeric($identifier) ? (int)$identifier : 0
        ]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            error_log("HOLD: Wallet not found: {$identifier}");
            echo json_encode(["status" => "ERROR", "hold_placed" => false, "message" => "Wallet not found: {$identifier}"]);
            exit;
        }

        $walletId = $wallet['wallet_id'];
        $balance = (float)$wallet['balance'];
        $heldBalance = (float)($wallet['held_balance'] ?? 0);
        $availableBalance = $balance - $heldBalance;

        error_log("Wallet found: ID={$walletId}, Balance={$balance}, Held={$heldBalance}, Available={$availableBalance}");

        if (!empty($wallet['is_frozen']) && $wallet['is_frozen'] == true) {
            echo json_encode(["status" => "ERROR", "hold_placed" => false, "message" => "Wallet is frozen"]);
            exit;
        }

        if (isset($wallet['status']) && $wallet['status'] !== 'active') {
            echo json_encode(["status" => "ERROR", "hold_placed" => false, "message" => "Wallet is not active (status: {$wallet['status']})"]);
            exit;
        }

        if ($availableBalance < $amount) {
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Insufficient balance. Available: {$availableBalance}, Required: {$amount}"
            ]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE wallets
                SET held_balance = COALESCE(held_balance, 0) + :amount
                WHERE wallet_id = :wallet_id
            ");
            $stmt->execute([':amount' => $amount, ':wallet_id' => $walletId]);

            error_log("Wallet held_balance updated: wallet_id={$walletId}, amount={$amount}");

            $stmt = $pdo->prepare("
                INSERT INTO financial_holds
                (wallet_id, amount, hold_reference, session_id, status, expires_at, created_at, asset_type, requester, foreign_bank)
                VALUES
                (:wallet_id, :amount, :hold_reference, :session_id, 'ACTIVE', :expires_at, NOW(), 'WALLET', :requester, :foreign_bank)
            ");
            $stmt->execute([
                ':wallet_id' => $walletId,
                ':amount' => $amount,
                ':hold_reference' => $holdReference,
                ':session_id' => $holdReference,
                ':expires_at' => $expiry,
                ':requester' => $requester,
                ':foreign_bank' => $destinationInstitution
            ]);

            $holdId = $pdo->lastInsertId();
            $pdo->commit();

            error_log("WALLET hold placed successfully: hold_id={$holdId}, wallet_id={$walletId}, reference={$holdReference}");

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("HOLD: PLACE transaction rolled back for wallet_id={$walletId}, reference={$holdReference}: " . $e->getMessage());
            echo json_encode(["status" => "ERROR", "hold_placed" => false, "message" => "Database error: " . $e->getMessage()]);
            exit;
        }

        $responsePayload = [
            "status" => "SUCCESS",
            "hold_placed" => true,
            "hold_reference" => $holdReference,
            "session_id" => $holdReference,
            "hold_id" => $holdId,
            "asset_type" => "WALLET",
            "wallet_id" => $walletId,
            "amount" => $amount,
            "available_balance" => $availableBalance - $amount,
            "message" => "Hold placed successfully on WALLET",
            "requester" => $requester
        ];
        send_signed_response($responsePayload);

    } else {
        echo json_encode([
            "status" => "ERROR",
            "hold_placed" => false,
            "message" => "Unsupported asset_type: {$assetType}. Supported: ACCOUNT, WALLET"
        ]);
        exit;
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Hold.php PDO ERROR: " . $e->getMessage());
    error_log("Hold.php TRACE: " . $e->getTraceAsString());
    echo json_encode(["status" => "ERROR", "hold_placed" => false, "message" => "Database error: " . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Hold.php ERROR: " . $e->getMessage());
    error_log("Hold.php TRACE: " . $e->getTraceAsString());
    echo json_encode(["status" => "ERROR", "hold_placed" => false, "message" => $e->getMessage()]);
}
?>
