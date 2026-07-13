<?php
// backend/atm/ewallet_cashout.php
header('Content-Type: application/json');
require_once __DIR__ . '/ATMService.php';

try {
    $data = json_decode(file_get_contents("php://input"), true);
    $atm = new ATMService();
    $result = $atm->cashoutEwallet(
        (int)$data['atm_id'],
        $data['phone'],
        $data['pin'],
        (float)$data['amount']
    );
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'DECLINED',
        'message' => $e->getMessage()
    ]);
}
