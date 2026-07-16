<?php
/**
 * hold.php - SACCUSSALIS VERSION
 * Place a hold on account or wallet funds
 *
 * FIXED: held_balance update + financial_holds insert now run in a single
 * DB transaction. Previously the UPDATE committed immediately and only the
 * INSERT could fail (e.g. duplicate hold_reference), leaving held_balance
 * permanently inflated with no corresponding hold row and no way to release it.
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

$action = $input['action'];
$amount = (float)$input['amount'];
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

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection failed");
    }

    // ============================================================
    // NEW: fail fast on a duplicate hold_reference BEFORE touching
    // any balance. Avoids ever reaching the transaction with a
    // reference we already know will violate the unique constraint.
    // ============================================================
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
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Account is frozen"
            ]);
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

        // ============================================================
        // FIX: single atomic transaction for balance update + hold insert.
        // If the INSERT fails for any reason (duplicate reference, DB
        // error, etc.), the UPDATE is rolled back too — held_balance
        // can never be bumped without a matching financial_holds row.
        // ============================================================
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE accounts
                SET held_balance = COALESCE(held_balance, 0) + :amount
                WHERE account_id = :account_id
            ");
            $stmt->execute([
                ':amount' => $amount,
                ':account_id' => $accountId
            ]);

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
            error_log("HOLD: Transaction rolled back for account_id={$accountId}, reference={$holdReference}: " . $e->getMessage());
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
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
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Wallet not found: {$identifier}"
            ]);
            exit;
        }

        $walletId = $wallet['wallet_id'];
        $balance = (float)$wallet['balance'];
        $heldBalance = (float)($wallet['held_balance'] ?? 0);
        $availableBalance = $balance - $heldBalance;

        error_log("Wallet found: ID={$walletId}, Balance={$balance}, Held={$heldBalance}, Available={$availableBalance}");

        if (!empty($wallet['is_frozen']) && $wallet['is_frozen'] == true) {
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Wallet is frozen"
            ]);
            exit;
        }

        if (isset($wallet['status']) && $wallet['status'] !== 'active') {
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Wallet is not active (status: {$wallet['status']})"
            ]);
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

        // ============================================================
        // FIX: same atomic transaction pattern as the ACCOUNT branch.
        // ============================================================
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE wallets
                SET held_balance = COALESCE(held_balance, 0) + :amount
                WHERE wallet_id = :wallet_id
            ");
            $stmt->execute([
                ':amount' => $amount,
                ':wallet_id' => $walletId
            ]);

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
            error_log("HOLD: Transaction rolled back for wallet_id={$walletId}, reference={$holdReference}: " . $e->getMessage());
            echo json_encode([
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
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
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Hold.php PDO ERROR: " . $e->getMessage());
    error_log("Hold.php TRACE: " . $e->getTraceAsString());
    echo json_encode([
        "status" => "ERROR",
        "hold_placed" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Hold.php ERROR: " . $e->getMessage());
    error_log("Hold.php TRACE: " . $e->getTraceAsString());
    echo json_encode([
        "status" => "ERROR",
        "hold_placed" => false,
        "message" => $e->getMessage()
    ]);
}
