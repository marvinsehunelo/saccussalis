<?php
require_once 'ATMService.php';

$data = json_decode(file_get_contents("php://input"), true);

$atm = new ATMService();
echo json_encode(
    $atm->cashoutEwallet(
        $data['phone'],
        $data['pin'],
        $data['amount']
    )
);
