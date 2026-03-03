<?php
require_once "../db.php";
header('Content-Type: application/json');

$d=json_decode(file_get_contents("php://input"),true);
$sat=$d['sat'];
$bank=$d['acquirer'];

$pdo->beginTransaction();

/* mark dispensed */
$pdo->prepare("UPDATE cash_instruments SET status='DISPENSED' WHERE instrument_id=
    (SELECT instrument_id FROM sat_tokens WHERE sat_code=?)")
->execute([$sat]);

/* create clearing obligation */
$pdo->prepare("
INSERT INTO clearing_positions(debtor_bank,creditor_bank,amount,reference)
SELECT issuer_bank,?,amount,? FROM sat_tokens WHERE sat_code=?")
->execute([$bank,$sat,$sat]);

$pdo->commit();

echo json_encode(["status"=>"recorded"]);
