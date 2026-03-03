<?php
// backend/api/v1/place_hold.php

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../core/HoldService.php';
require_once __DIR__ . '/../../includes/secure_api_header.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $required = ['reference', 'asset_type', 'asset_id', 'amount', 'expiry', 'hold_reason'];
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }
    
    $pdo = getDBConnection();
    $holdService = new HoldService($pdo);
    
    $holdReference = 'HOLD-' . uniqid() . '-' . rand(1000, 9999);
    
    // Store hold in database
    $stmt = $pdo->prepare("
        INSERT INTO holds 
        (hold_reference, transaction_reference, asset_type, asset_id, amount, 
         expiry, reason, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NOW())
    ");
    
    $stmt->execute([
        $holdReference,
        $input['reference'],
        $input['asset_type'],
        $input['asset_id'],
        $input['amount'],
        $input['expiry'],
        $input['hold_reason']
    ]);
    
    // Call appropriate asset-specific hold method
    $remainingBalance = 0;
    
    switch (strtoupper($input['asset_type'])) {
        case 'ACCOUNT':
            $remainingBalance = $holdService->holdAccount(
                $input['asset_id'],
                $input['amount']
            );
            break;
            
        case 'E-WALLET':
        case 'WALLET':
            $remainingBalance = $holdService->holdWallet(
                $input['asset_id'],
                $input['amount']
            );
            break;
            
        case 'CARD':
            $remainingBalance = $holdService->holdCard(
                $input['asset_id'],
                $input['amount']
            );
            break;
    }
    
    echo json_encode([
        'success' => true,
        'hold_placed' => true,
        'hold_reference' => $holdReference,
        'hold_expiry' => $input['expiry'],
        'remaining_balance' => $remainingBalance
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'hold_placed' => false,
        'message' => $e->getMessage()
    ]);
}
?>
