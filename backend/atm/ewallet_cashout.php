<?php
require_once 'ATMService.php';

$data = json_decode(file_get_contents("php://input"), true);

$atm = new ATMService();

echo json_encode(
    $atm->cashoutEwallet(
        (int)$data['atm_id'],
        $data['phone'],
        $data['pin'],
        (float)$data['amount']
    )
);
