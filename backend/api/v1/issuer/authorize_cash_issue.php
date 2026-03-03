<?php
require_once "../db.php";
header('Content-Type: application/json');

$d=json_decode(file_get_contents("php://input"),true);

$sat=$d['sat'];
$pin=$d['pin'];
$trace=$d['trace'];
$bank=$d['acquirer'];

$pdo->beginTransaction();

/* Idempotency */
$chk=$pdo->prepare("SELECT * FROM atm_authorizations WHERE trace_number=? AND acquirer_bank=?");
$chk->execute([$trace,$bank]);
if($chk->fetch()){
    echo json_encode(["response"=>"00","msg"=>"duplicate approved"]);
    exit;
}

/* Find SAT */
$stmt=$pdo->prepare("SELECT * FROM sat_tokens WHERE sat_code=? AND status='ACTIVE' FOR UPDATE");
$stmt->execute([$sat]);
$row=$stmt->fetch();

if(!$row || strtotime($row['expires_at'])<time()){
    echo json_encode(["response"=>"54"]); exit;
}

/* Verify PIN */
if(!password_verify($pin,$row['pin_hash'])){
    echo json_encode(["response"=>"55"]); exit;
}

/* Authorize */
$auth=rand(100000,999999);

$pdo->prepare("INSERT INTO atm_authorizations(sat_code,trace_number,acquirer_bank,amount,response_code,auth_code)
VALUES(?,?,?,?,?,?)")
->execute([$sat,$trace,$bank,$row['amount'],'00',$auth]);

$pdo->prepare("UPDATE cash_instruments SET status='AUTHORIZED' WHERE instrument_id=?")
    ->execute([$row['instrument_id']]);

$pdo->commit();

echo json_encode([
 "response"=>"00",
 "auth_code"=>$auth,
 "amount"=>$row['amount']
]);
