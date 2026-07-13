<?php
// backend/atm/ewallet_cashout.php
// Ewallet cashout endpoint - Returns JSON only

// Disable all error output
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set JSON header FIRST
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    require_once __DIR__ . '/ATMService.php';
    
    // Get input
    $rawInput = file_get_contents("php://input");
    
    if (empty($rawInput)) {
        throw new Exception("No input data received");
    }
    
    $data = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }
    
    // Validate required fields
    if (!isset($data['atm_id']) || !isset($data['phone']) || !isset($data['pin']) || !isset($data['amount'])) {
        throw new Exception("Missing required fields: atm_id, phone, pin, amount");
    }
    
    // Create ATM service
    $atm = new ATMService();
    
    // Process cashout
    $result = $atm->cashoutEwallet(
        (int)$data['atm_id'],
        trim($data['phone']),
        trim($data['pin']),
        (float)$data['amount']
    );
    
    // Ensure we have an array
    if (!is_array($result)) {
        throw new Exception("Invalid response from ATMService");
    }
    
    // Send JSON response
    echo json_encode($result);
    exit;
    
} catch (Exception $e) {
    error_log("EWALLET_CASHOUT ERROR: " . $e->getMessage());
    error_log("EWALLET_CASHOUT Input: " . ($rawInput ?? ''));
    
    echo json_encode([
        'status' => 'DECLINED',
        'message' => $e->getMessage()
    ]);
    exit;
}
