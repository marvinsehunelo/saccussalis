<?php
// backend/api/v1/settlement/cashout_settlements.php

header('Content-Type: application/json');
require_once '../../../db.php';
require_once '../../../middleware/Idempotency.php';
require_once '../../../helpers/crypto.php';
require_once '../../../helpers/CertificateManager.php';

$input = json_decode(file_get_contents('php://input'), true);

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($input['certificate'])) {
    error_log("SACCUSSALIS SETTLEMENT: No certificate provided");
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('SACCUSSALIS');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("SACCUSSALIS SETTLEMENT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("SACCUSSALIS SETTLEMENT: Requester: {$requester}");

if (!$isValid) {
    error_log("SACCUSSALIS SETTLEMENT: Certificate verification failed");
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("SACCUSSALIS SETTLEMENT: Request verified from {$requester} using certificate");

// ============================================================
// PROCESS SETTLEMENT
// ============================================================

// Idempotency
$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $input['request_id'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Idempotency key required']);
    exit;
}
Idempotency::check($idempotencyKey);

// Initialize variables
$reference = null;
$settlementId = null;

try {
    $pdo->beginTransaction();

    $type = $input['type'] ?? null;
    if (!$type) throw new Exception("Settlement type required");

    $amount = floatval($input['amount'] ?? 0);
    $reference = 'SET' . time() . rand(100, 999);

    switch (strtoupper($type)) {

        case 'SAT_TOKEN':
            $satNumber = $input['sat_number'] ?? null;
            if (!$satNumber) throw new Exception("SAT number required for SAT token settlement");

            // Check if SAT token exists and is valid
            $stmt = $pdo->prepare("
                SELECT s.*, c.status as instrument_status
                FROM sat_tokens s
                JOIN cash_instruments c ON s.instrument_id = c.instrument_id
                WHERE s.sat_code = ? OR s.auth_code = ?
                FOR UPDATE
            ");
            $stmt->execute([$satNumber, $satNumber]);
            $satToken = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$satToken) {
                throw new Exception("SAT token not found: $satNumber");
            }

            if ($satToken['status'] !== 'ACTIVE') {
                throw new Exception("SAT token is not active. Status: " . $satToken['status']);
            }

            // Mark SAT token as SETTLED
            $stmt = $pdo->prepare("
                UPDATE sat_tokens 
                SET status = 'SETTLED', 
                    settled_at = NOW(),
                    settlement_ref = ?,
                    settled_by = ?,
                    settlement_verified = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reference, $requester, $isValid ? 1 : 0, $satToken['id']]);

            $stmt = $pdo->prepare("
                INSERT INTO settlements 
                (settlement_ref, type, sat_number, amount, issuer_bank, acquirer_bank, status, 
                 requester, signature_verified, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([
                $reference,
                $type,
                $satNumber,
                $amount,
                $input['issuer_bank'] ?? $satToken['issuer_bank'] ?? null,
                $input['acquirer_bank'] ?? null,
                $requester,
                $isValid ? 1 : 0
            ]);
            $settlementId = $stmt->fetchColumn();
            
            $message = "SAT token settlement completed successfully";
            $status = 'completed';
            break;

        case 'EWALLET_SWAP':
            $walletId = $input['wallet_id'] ?? null;
            if (!$walletId) throw new Exception("Wallet ID required for eWallet swap settlement");

            // Lock and check wallet balance
            $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ? AND status = 'active' AND is_frozen = false FOR UPDATE");
            $stmt->execute([$walletId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$wallet) throw new Exception("Wallet not found or inactive");
            if ($wallet['balance'] < $amount) throw new Exception("Insufficient wallet balance. Available: {$wallet['balance']}, Required: {$amount}");

            // Debit wallet
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ?, updated_at = NOW() WHERE wallet_id = ?");
            $stmt->execute([$amount, $walletId]);

            // Get updated balance
            $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE wallet_id = ?");
            $stmt->execute([$walletId]);
            $updatedWallet = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                INSERT INTO settlements 
                (settlement_ref, type, wallet_id, amount, status, requester, signature_verified, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([$reference, $type, $walletId, $amount, $requester, $isValid ? 1 : 0]);
            $settlementId = $stmt->fetchColumn();

            $message = "eWallet swap settlement completed successfully";
            $status = 'completed';
            break;

        case 'ATM_CASHOUT':
            $holdReference = $input['hold_reference'] ?? null;
            if (!$holdReference) throw new Exception("Hold reference required for ATM cashout settlement");

            // Find the hold
            $stmt = $pdo->prepare("
                SELECT * FROM financial_holds 
                WHERE hold_reference = ? AND status = 'HELD'
                FOR UPDATE
            ");
            $stmt->execute([$holdReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) throw new Exception("Active hold not found for reference: $holdReference");

            // Mark hold as settled
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'SETTLED', 
                    settlement_ref = ?,
                    settled_by = ?,
                    settlement_verified = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reference, $requester, $isValid ? 1 : 0, $hold['id']]);

            $stmt = $pdo->prepare("
                INSERT INTO settlements 
                (settlement_ref, type, amount, hold_reference, status, requester, signature_verified, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([$reference, $type, $amount, $holdReference, $requester, $isValid ? 1 : 0]);
            $settlementId = $stmt->fetchColumn();

            $message = "ATM cashout settlement completed successfully";
            $status = 'completed';
            break;

        default:
            throw new Exception("Unsupported settlement type: $type");
    }

    $pdo->commit();

    error_log("SACCUSSALIS SETTLEMENT: Settlement completed - Ref: {$reference}, Type: {$type}, Amount: {$amount}");

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'settlement_ref' => $reference,
        'settlement_id' => $settlementId,
        'type' => $type,
        'amount' => $amount,
        'settlement_status' => $status,
        'message' => $message,
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'timestamp' => time()
    ];

    Idempotency::store($idempotencyKey, $responsePayload);
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("SACCUSSALIS SETTLEMENT ERROR: " . $e->getMessage());
    error_log("SACCUSSALIS SETTLEMENT Input: " . json_encode($input ?? []));
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Settlement failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
