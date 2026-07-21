<?php
/**
 * sat_cashout.php
 * ATM SAT Cashout API Endpoint
 * 
 * This endpoint handles SAT cashout and notifies VouchMorph
 */

declare(strict_types=1);

// Load database
require_once __DIR__ . '/../db.php';

// Load the ATMCashout class
require_once __DIR__ . '/../atm/ATMcashout.php';

use atm\ATMCashout;

header('Content-Type: application/json');

try {
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception("Invalid JSON input");
    }
    
    $atmId = $input['atm_id'] ?? 'ATM001';
    $satNumber = trim($input['sat_number'] ?? '');
    $pin = trim($input['pin'] ?? '');
    $amount = (float)($input['amount'] ?? 0);
    
    // Validate required fields
    if (!$satNumber) {
        throw new Exception("SAT number required");
    }
    if (!$pin) {
        throw new Exception("SAT PIN required");
    }
    if ($amount <= 0) {
        throw new Exception("Invalid amount");
    }
    
    // Initialize the ATMCashout class (NOT ATMService)
    $atmCashout = new ATMCashout($pdo);
    
    // Process the cashout - THIS WILL NOTIFY VOUCHMORPH
    $result = $atmCashout->cashoutSAT($satNumber, $atmId, $pin, $amount);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("SAT Cashout Error: " . $e->getMessage());
    echo json_encode([
        'status' => 'FAILED',
        'message' => $e->getMessage()
    ]);
}
