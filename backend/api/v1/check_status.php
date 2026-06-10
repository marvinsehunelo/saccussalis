<?php
// backend/api/v1/check_status.php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/crypto.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $swapRef = trim($input['swap_reference'] ?? '');

    if (!$swapRef) {
        throw new Exception("Missing swap_reference");
    }

    // ============================================================
    // VERIFY INCOMING SIGNATURE (Optional but recommended for queries)
    // ============================================================
    $signature = $input['signature'] ?? null;
    $timestamp = $input['timestamp'] ?? null;
    $requester = $input['requester'] ?? 'VOUCHMORPH';

    $payloadToVerify = ['swap_reference' => $swapRef];
    $signatureVerified = false;

    if ($signature) {
        $publicKey = get_requester_public_key($requester, $pdo);
        if ($publicKey) {
            $signatureVerified = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);
            if ($signatureVerified) {
                error_log("SACCUSSALIS STATUS: Signature verified from {$requester}");
            } else {
                error_log("SACCUSSALIS STATUS: Invalid signature from {$requester}");
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
        SELECT wallet_id, amount, status, expires_at
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

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'swap_reference' => $swapRef,
        'status' => $status,
        'swap' => $swap,
        'transactions' => $transactions,
        'settlement' => $settlement,
        'hold' => $hold,
        'requester' => $requester,
        'signature_verified' => $signatureVerified
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
