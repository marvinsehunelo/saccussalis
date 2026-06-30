<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/api/v1/transactions/credit_funds.php
header('Content-Type: application/json');
require_once '../../../db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

$input = json_decode(file_get_contents("php://input"), true);

error_log("=== SACCUSSALIS credit_funds.php received ===");
error_log("Input payload: " . json_encode($input));

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($input['certificate'])) {
    error_log("SACCUSSALIS CREDIT_FUNDS: No certificate provided");
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('SACCUSSALIS');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("SACCUSSALIS CREDIT_FUNDS: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("SACCUSSALIS CREDIT_FUNDS: Requester: {$requester}");

if (!$isValid) {
    error_log("SACCUSSALIS CREDIT_FUNDS: Certificate verification failed");
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("SACCUSSALIS CREDIT_FUNDS: Request verified from {$requester} using certificate");

// ============================================================
// PROCESS CREDIT
// ============================================================

// Map fields from different possible sources
$amount = $input['amount'] ?? $input['value'] ?? null;

// Determine source bank
$fromBank = $input['from_bank'] ?? $input['source_institution'] ?? $input['institution'] ?? $input['from_institution'] ?? null;

// ✅ Extract destination asset type - default to ACCOUNT for safety
$destinationAssetType = strtoupper($input['destination_asset_type'] ?? $input['asset_type'] ?? $input['destination_type'] ?? 'ACCOUNT');

error_log("SACCUSSALIS CREDIT_FUNDS: Destination Asset Type from payload: " . ($input['destination_asset_type'] ?? 'NOT SET'));
error_log("SACCUSSALIS CREDIT_FUNDS: Normalized Asset Type: {$destinationAssetType}");

// Determine destination based on asset type
$phone = $input['phone'] ?? $input['destination_phone'] ?? $input['beneficiary_phone'] ?? $input['wallet_phone'] ?? null;
$accountNumber = $input['account_number'] ?? $input['destination_account'] ?? $input['account'] ?? null;

// Also check in nested structures
if (!$phone && isset($input['destination']['phone'])) {
    $phone = $input['destination']['phone'];
}
if (!$accountNumber && isset($input['destination']['account'])) {
    $accountNumber = $input['destination']['account'];
}

// ✅ Validate based on asset type
if (!$amount || !$fromBank) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Missing required fields. Need amount and from_bank/source_institution.',
        'debug_received' => $input
    ]);
    exit;
}

// ✅ Validate destination based on asset type
if ($destinationAssetType === 'ACCOUNT') {
    if (!$accountNumber) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'processed' => false,
            'message' => "Destination asset type is ACCOUNT but no account_number provided.",
            'debug_received' => $input
        ]);
        exit;
    }
    error_log("SACCUSSALIS CREDIT_FUNDS: ✅ Valid ACCOUNT deposit with account_number: {$accountNumber}");
} else {
    // WALLET
    if (!$phone) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'processed' => false,
            'message' => "Destination asset type is WALLET but no phone provided.",
            'debug_received' => $input
        ]);
        exit;
    }
    error_log("SACCUSSALIS CREDIT_FUNDS: ✅ Valid WALLET deposit with phone: {$phone}");
}

error_log("SACCUSSALIS CREDIT_FUNDS: Processing: Asset Type={$destinationAssetType}, Amount={$amount}, From={$fromBank}");

// Initialize variables
$recipientType = null;
$recipientId = null;
$updatedBalance = null;
$userId = null;
$pin = null;
$ewalletPinId = null;
$transactionId = null;

try {
    $pdo->beginTransaction();

    // --- 1. Find or create recipient based on asset type ---
    
    if ($destinationAssetType === 'ACCOUNT') {
        // ============================================================
        // ✅ CREDIT TO ACCOUNT - NO eWallet PIN
        // ============================================================
        error_log("SACCUSSALIS CREDIT_FUNDS: Processing ACCOUNT deposit for: {$accountNumber}");
        
        // Find the account
        $stmt = $pdo->prepare("
            SELECT account_id, balance, user_id 
            FROM accounts 
            WHERE account_number = :account_number AND status = 'active'
        ");
        $stmt->execute([':account_number' => $accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($account) {
            // Lock the account for update
            $stmt = $pdo->prepare("
                SELECT account_id, balance, user_id 
                FROM accounts 
                WHERE account_id = :account_id 
                FOR UPDATE
            ");
            $stmt->execute([':account_id' => $account['account_id']]);
            $lockedAccount = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $recipientType = 'ACCOUNT';
            $recipientId = $lockedAccount['account_id'];
            $userId = $lockedAccount['user_id'];
            
            // Credit account balance
            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET balance = balance + :amount, updated_at = NOW() 
                WHERE account_id = :account_id
            ");
            $stmt->execute([
                ':amount' => $amount,
                ':account_id' => $recipientId
            ]);
            
            // Get updated balance
            $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_id = :account_id");
            $stmt->execute([':account_id' => $recipientId]);
            $updatedAccount = $stmt->fetch(PDO::FETCH_ASSOC);
            $updatedBalance = $updatedAccount['balance'];
            
            error_log("SACCUSSALIS CREDIT_FUNDS: ✅ Credited {$amount} to existing account {$recipientId}, new balance: {$updatedBalance}");
            
        } else {
            // ✅ Create new user and account
            error_log("SACCUSSALIS CREDIT_FUNDS: Creating new user and account for: {$accountNumber}");
            
            // Generate random password
            $randomPassword = bin2hex(random_bytes(16));
            $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);
            
            // Create user
            $fullName = $input['account_name'] ?? $input['beneficiary_name'] ?? 'Account Holder';
            $email = $input['email'] ?? $accountNumber . '@saccussalis.bw';
            $userPhone = $input['beneficiary_phone'] ?? $input['phone'] ?? $accountNumber;
            
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, email, phone, password_hash, status, created_at)
                VALUES (:full_name, :email, :phone, :password_hash, 'active', NOW())
                RETURNING user_id
            ");
            $stmt->execute([
                ':full_name' => $fullName,
                ':email' => $email,
                ':phone' => $userPhone,
                ':password_hash' => $hashedPassword
            ]);
            $userId = $stmt->fetchColumn();
            
            // Create account
            $stmt = $pdo->prepare("
                INSERT INTO accounts (user_id, account_number, account_type, currency, balance, status, created_at)
                VALUES (:user_id, :account_number, 'checking', 'BWP', :amount, 'active', NOW())
                RETURNING account_id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':account_number' => $accountNumber,
                ':amount' => $amount
            ]);
            $recipientId = $stmt->fetchColumn();
            $recipientType = 'ACCOUNT';
            $updatedBalance = $amount;
            
            error_log("SACCUSSALIS CREDIT_FUNDS: ✅ Created new user {$userId}, account {$recipientId} with balance {$updatedBalance}");
        }
        
        // ✅ IMPORTANT: NO eWallet PIN for ACCOUNT deposits
        // Skip PIN generation entirely
        
    } else {
        // ============================================================
        // ✅ CREDIT TO WALLET - WITH eWallet PIN
        // ============================================================
        error_log("SACCUSSALIS CREDIT_FUNDS: Processing WALLET deposit for phone: {$phone}");
        
        // Find wallet by phone
        $stmt = $pdo->prepare("
            SELECT wallet_id, balance, user_id 
            FROM wallets 
            WHERE phone = :phone AND status = 'active'
        ");
        $stmt->execute([':phone' => $phone]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $walletId = null;
        $recipientPhone = $phone;
        
        if ($wallet) {
            // Lock the wallet for update
            $stmt = $pdo->prepare("
                SELECT wallet_id, balance, user_id 
                FROM wallets 
                WHERE wallet_id = :wallet_id 
                FOR UPDATE
            ");
            $stmt->execute([':wallet_id' => $wallet['wallet_id']]);
            $lockedWallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $walletId = $lockedWallet['wallet_id'];
            $userId = $lockedWallet['user_id'];
            
            // Get user phone
            $stmt = $pdo->prepare("SELECT phone FROM users WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $recipientPhone = $user['phone'] ?? $phone;
            
            error_log("SACCUSSALIS CREDIT_FUNDS: Found existing wallet {$walletId} for phone {$phone}");
            
        } else {
            // ✅ Create new user and wallet
            error_log("SACCUSSALIS CREDIT_FUNDS: Creating new user and wallet for phone: {$phone}");
            
            // Generate random password
            $randomPassword = bin2hex(random_bytes(16));
            $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);
            
            // Create user
            $fullName = $input['account_name'] ?? $input['beneficiary_name'] ?? 'Wallet User';
            $email = $input['email'] ?? $phone . '@saccussalis.bw';
            
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, email, phone, password_hash, status, created_at)
                VALUES (:full_name, :email, :phone, :password_hash, 'active', NOW())
                RETURNING user_id
            ");
            $stmt->execute([
                ':full_name' => $fullName,
                ':email' => $email,
                ':phone' => $phone,
                ':password_hash' => $hashedPassword
            ]);
            $userId = $stmt->fetchColumn();
            
            // Create wallet
            $stmt = $pdo->prepare("
                INSERT INTO wallets (user_id, phone, wallet_type, currency, balance, status, created_at)
                VALUES (:user_id, :phone, 'EWALLET', 'BWP', 0, 'active', NOW())
                RETURNING wallet_id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':phone' => $phone
            ]);
            $walletId = $stmt->fetchColumn();
            
            error_log("SACCUSSALIS CREDIT_FUNDS: Created new user {$userId}, wallet {$walletId}");
        }
        
        // --- Credit the wallet balance ---
        $stmt = $pdo->prepare("
            UPDATE wallets 
            SET balance = balance + :amount, updated_at = NOW() 
            WHERE wallet_id = :wallet_id
        ");
        $stmt->execute([
            ':amount' => $amount,
            ':wallet_id' => $walletId
        ]);
        
        // Get updated balance
        $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE wallet_id = :wallet_id");
        $stmt->execute([':wallet_id' => $walletId]);
        $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedBalance = $updatedWallet['balance'];
        
        $recipientType = 'WALLET';
        $recipientId = $walletId;
        
        error_log("SACCUSSALIS CREDIT_FUNDS: ✅ Credited {$amount} to wallet {$walletId}, new balance: {$updatedBalance}");
        
        // --- Generate eWallet PIN for withdrawal (ONLY FOR WALLET) ---
        $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $senderPhone = $input['sender_phone'] ?? $fromBank . '_DEPOSIT';
        $reference = $input['reference'] ?? ('DEP_' . time() . '_' . bin2hex(random_bytes(4)));
        
        // ✅ STEP 1: Create transaction record FIRST (for foreign key)
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                user_id,
                reference,
                amount,
                type,
                direction,
                status,
                description,
                requester,
                signature_verified,
                verification_method,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :reference,
                :amount,
                'CREDIT',
                'in',
                'completed',
                :description,
                :requester,
                :sig_verified,
                :verification_method,
                NOW(),
                NOW()
            ) RETURNING transaction_id
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':reference' => $reference,
            ':amount' => $amount,
            ':description' => "eWallet deposit of {$amount} BWP from {$fromBank}. PIN: {$pin}",
            ':requester' => $requester,
            ':sig_verified' => $isValid ? 1 : 0,
            ':verification_method' => 'certificate'
        ]);
        $transactionId = $stmt->fetchColumn();
        
        error_log("SACCUSSALIS CREDIT_FUNDS: Created transaction {$transactionId} for wallet deposit");
        
        // ✅ STEP 2: Insert eWallet PIN with transaction_id (foreign key now satisfied)
        $stmt = $pdo->prepare("
            INSERT INTO ewallet_pins (
                transaction_id,
                recipient_phone,
                generated_by,
                pin,
                is_redeemed,
                created_at,
                expires_at,
                sender_phone,
                amount,
                sat_purchased,
                hold_status
            ) VALUES (
                :transaction_id,
                :recipient_phone,
                :generated_by,
                :pin,
                FALSE,
                NOW(),
                :expires_at,
                :sender_phone,
                :amount,
                FALSE,
                :hold_status
            ) RETURNING id
        ");
        $stmt->execute([
            ':transaction_id' => $transactionId,
            ':recipient_phone' => $recipientPhone,
            ':generated_by' => $userId ?? 1,
            ':pin' => $pin,
            ':expires_at' => $expiresAt,
            ':sender_phone' => $senderPhone,
            ':amount' => $amount,
            ':hold_status' => true  // ✅ BOOLEAN - TRUE means active hold
        ]);
        $ewalletPinId = $stmt->fetchColumn();
        
        error_log("SACCUSSALIS CREDIT_FUNDS: ✅ Generated eWallet PIN {$pin} for phone {$recipientPhone}, expires at {$expiresAt}, linked to transaction {$transactionId}");
    }

    // --- 2. Deduct from settlement account (internal liquidity) ---
    $settlementAccountNumber = '10000001';
    $stmt = $pdo->prepare("
        UPDATE accounts 
        SET balance = balance - :amount, updated_at = NOW()
        WHERE account_number = :acc_num AND balance >= :amount
        RETURNING account_id, balance
    ");
    $stmt->execute([
        ':amount' => $amount,
        ':acc_num' => $settlementAccountNumber
    ]);
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settlement) {
        throw new Exception("Settlement account not found or insufficient funds.");
    }

    error_log("SACCUSSALIS CREDIT_FUNDS: Settlement account {$settlementAccountNumber} new balance: {$settlement['balance']}");

    // --- 3. Create a settlement record with requester info ---
    $settlementRef = $input['reference'] ?? $input['settlement_ref'] ?? ('SET' . round(microtime(true) * 1000));

    // Ensure settlements table exists with proper columns
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settlements (
            id BIGSERIAL PRIMARY KEY,
            settlement_ref VARCHAR(100) UNIQUE NOT NULL,
            type VARCHAR(50) NOT NULL,
            issuer_bank VARCHAR(100) NOT NULL,
            recipient_type VARCHAR(50) NOT NULL,
            recipient_id BIGINT NOT NULL,
            amount DECIMAL(20,4) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            requester VARCHAR(100),
            signature_verified BOOLEAN DEFAULT FALSE,
            destination_asset_type VARCHAR(50) DEFAULT 'ACCOUNT',
            ewallet_pin_id BIGINT,
            transaction_id BIGINT,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO settlements 
            (settlement_ref, type, issuer_bank, recipient_type, recipient_id, amount, status, 
             requester, signature_verified, destination_asset_type, ewallet_pin_id, transaction_id, created_at, updated_at) 
        VALUES 
            (?, 'SWAP_CREDIT', ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $settlementRef,
        $fromBank,
        $recipientType,
        $recipientId,
        $amount,
        $requester,
        $isValid ? 1 : 0,
        $destinationAssetType,
        $ewalletPinId,
        $transactionId
    ]);

    $pdo->commit();

    error_log("SACCUSSALIS CREDIT_FUNDS: ✅ Credit completed successfully - Ref: {$settlementRef}");

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'processed' => true,
        'transaction_reference' => $settlementRef,
        'settlement_ref' => $settlementRef,
        'from_bank' => $fromBank,
        'recipient_type' => $recipientType,
        'recipient_id' => $recipientId,
        'destination_asset_type' => $destinationAssetType,
        'amount' => $amount,
        'new_balance' => $updatedBalance,
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'timestamp' => time()
    ];
    
    // ✅ Only include PIN for WALLET deposits
    if ($destinationAssetType === 'WALLET' && $pin) {
        $responsePayload['pin'] = $pin;
        $responsePayload['pin_expires_at'] = $expiresAt ?? date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $responsePayload['recipient_phone'] = $phone;
        $responsePayload['message'] = "eWallet deposit successful. PIN: {$pin} - Valid for 15 minutes.";
        $responsePayload['ewallet_pin_id'] = $ewalletPinId;
        $responsePayload['transaction_id'] = $transactionId;
    } else {
        $responsePayload['message'] = "Funds credited successfully to {$recipientType}";
    }
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS credit_funds.php ERROR: " . $e->getMessage());
    error_log("SACCUSSALIS credit_funds.php Input: " . json_encode($input ?? []));
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'processed' => false,
        'message' => 'Credit failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
