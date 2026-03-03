<?php
require_once 'ATMService.php';

$data = json_decode(file_get_contents("php://input"), true);

$atm = new ATMService();

$auth = $atm->authorizeSAT(
    $data['sat'],
    $data['amount']
);

if ($auth['status'] === 'APPROVED') {
    $atm->completeSAT($data['sat']);
}

echo json_encode($auth);
