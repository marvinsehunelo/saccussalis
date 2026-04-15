<?php
require_once 'ATMService.php';

$data = json_decode(file_get_contents("php://input"), true);

$atm = new ATMService();

$result = $atm->completeSAT(
    (int)$data['atm_id'],
    $data['sat_number'],
    $data['trace_number']
);

echo json_encode($result);
