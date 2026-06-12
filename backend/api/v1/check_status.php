<?php
// backend/api/v1/check_status.php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $swapRef = trim($input['swap_reference'] ?? '');

    if (!$swapRef) {
        throw new Exception("Missing swap_reference");
    }

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION (Optional but recommended)
    // ============================================================
    $signatureVerified = false;
    $requester = $input['requester'] ?? 'UNKNOWN';
    $certificate = $input['certificate'] ?? null;

    // Try certificate verification first
    if ($certificate) {
        $certManager = new CertificateManager('SACCUSSALIS');
        $verification = $certManager->verifySignedRequest($input);
        $signatureVerified = $verification['verified'];
        $requester = $verification['requester'];
        
        if ($signatureVerified) {
            error_log("SACCUSSALIS STATUS: Certificate verified from {$requester}");
        } else {
            error_log("SACCUSSALIS STATUS: Certificate verification failed from {$requester}");
        }
    } 
    // Fallback to legacy signature verification (for backward compatibility)
    else {
        $signature = $input['signature'] ?? null;
        $timestamp = $input['timestamp'] ?? null;
        $legacyRequester = $input['requester'] ?? 'VOUCHMORPH';

        if ($signature) {
            $publicKey = get_requester_public_key($legacyRequester, $pdo);
            if ($publicKey) {
                $payloadToVerify = ['swap_reference' => $swapRef];
                $signatureVerified = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);
                if ($signatureVerified) {
                    $requester = $legacyRequester;
                    error_log("SACCUSSALIS STATUS: Legacy signature verified from {$requester}");
                } else {
                    error_log("SACCUSSALIS STATUS: Invalid legacy signature from {$legacyRequester}");
                }
            }
        }
    }

    // --- 1. Fetch main swap ledger record ---
    $stmt = $pdo->prepare("
        SELECT ledger_id, from_participant, to_participant, original_amount, final_amount, swap_fee, status, created_at
        FROM swap_ledgers
        WHERE swap_reference = ?
    ");
    $stmt->execute([$swapRef]);
    $swap = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$swap) {
        throw new Exception("Swap not found for reference: $swapRef");
    }

    // --- 2. Fetch associated transactions ---
    $stmt = $pdo->prepare("
        SELECT id, source, reference, amount, type, description, created_at
        FROM swap_transactions
        WHERE ledger_id = ?
    ");
    $stmt->execute([$swap['ledger_id']]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- 3. Fetch any settlements ---
    $stmt = $pdo->prepare("
        SELECT settlement_ref, type, wallet_id, amount, status, created_at
        FROM settlements
        WHERE settlement_ref = ?
    ");
    $stmt->execute([$swapRef]);
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- 4. Check financial holds if any ---
    $stmt = $pdo->prepare("
        SELECT wallet_id, amount, status, expires_at, foreign_bank, requester
        FROM financial_holds
        WHERE hold_reference = ?
    ");
    $stmt->execute([$swapRef]);
    $hold = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- 5. Determine overall status ---
    $status = $swap['status'];
    if ($hold && $hold['status'] === 'HELD') {
        $status = 'pending_hold';
    } elseif ($settlement && $settlement['status'] === 'pending') {
        $status = 'cashout_pending';
    } elseif ($settlement && $settlement['status'] === 'completed') {
        $status = 'completed';
    }

    // Get wallet balance if hold exists
    $walletBalance = null;
    if ($hold && isset($hold['wallet_id'])) {
        $stmt = $pdo->prepare("SELECT balance, held_balance FROM wallets WHERE wallet_id = ?");
        $stmt->execute([$hold['wallet_id']]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($wallet) {
            $walletBalance = [
                'balance' => $wallet['balance'],
                'held_balance' => $wallet['held_balance'],
                'available' => $wallet['balance'] - ($wallet['held_balance'] ?? 0)
            ];
        }
    }

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'swap_reference' => $swapRef,
        'status' => $status,
        'swap' => [
            'from_participant' => $swap['from_participant'],
            'to_participant' => $swap['to_participant'],
            'original_amount' => $swap['original_amount'],
            'final_amount' => $swap['final_amount'],
            'swap_fee' => $swap['swap_fee'],
            'status' => $swap['status'],
            'created_at' => $swap['created_at']
        ],
        'transactions' => $transactions,
        'settlement' => $settlement,
        'hold' => $hold,
        'wallet_balance' => $walletBalance,
        'requester' => $requester,
        'signature_verified' => $signatureVerified,
        'verification_method' => $certificate ? 'certificate' : ($signature ? 'legacy_signature' : 'none')
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    error_log("SACCUSSALIS STATUS ERROR: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
