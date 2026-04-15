<?php
require_once 'ATMService.php';

$data = json_decode(file_get_contents("php://input"), true);

$atm = new ATMService();

$auth = $atm->authorizeSAT(
    (int)$data['atm_id'],
    $data['sat_number'],
    $data['pin'],
    (float)$data['amount']
);

echo json_encode($auth);
