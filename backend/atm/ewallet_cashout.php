<?php
// backend/atm/ewallet_cashout.php
// Ewallet cashout endpoint - returns JSON only

// Disable error display - we'll log errors instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Clear any output buffers
if (ob_get_level()) {
    ob_end_clean();
}

// Set JSON header FIRST
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/ATMService.php';

try {
    // Get JSON input
    $rawInput = file_get_contents("php://input");
    
    if (empty($rawInput)) {
        throw new Exception("No input data received");
    }
    
    $data = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON input: " . json_last_error_msg());
    }
    
    // Validate required fields
    if (!isset($data['atm_id']) || !isset($data['phone']) || !isset($data['pin']) || !isset($data['amount'])) {
        throw new Exception("Missing required fields: atm_id, phone, pin, amount");
    }
    
    // Create ATM service instance
    $atm = new ATMService();
    
    // Process the cashout
    $result = $atm->cashoutEwallet(
        (int)$data['atm_id'],
        trim($data['phone']),
        trim($data['pin']),
        (float)$data['amount']
    );
    
    // Ensure we have a valid response
    if (!is_array($result)) {
        throw new Exception("Invalid response from ATMService");
    }
    
    // Send JSON response
    echo json_encode($result);
    exit;
    
} catch (Exception $e) {
    // Log the error
    error_log("EWALLET_CASHOUT ERROR: " . $e->getMessage());
    error_log("EWALLET_CASHOUT Input: " . $rawInput ?? '');
    
    // Return error as JSON
    echo json_encode([
        'status' => 'DECLINED',
        'message' => $e->getMessage()
    ]);
    exit;
}
