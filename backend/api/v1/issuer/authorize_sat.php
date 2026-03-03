<?php
declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";

function respond($status,$msg,$extra=[]){
    echo json_encode(array_merge(["status"=>$status,"message"=>$msg],$extra));
    exit;
}

try{

    $input=json_decode(file_get_contents("php://input"),true);

    $sat=$input['sat']??null;
    $amount=(float)($input['amount']??0);

    if(!$sat || $amount<=0)
        respond("error","Invalid request");

    $pdo->beginTransaction();

    /* ==========================
       STEP 1 — LOCK SAT ROW
    ========================== */

    $stmt=$pdo->prepare("
        SELECT s.*, c.reserved_amount
        FROM sat_tokens s
        JOIN cash_instruments c
        ON s.instrument_id=c.instrument_id
        WHERE s.auth_code=?
        FOR UPDATE
    ");
    $stmt->execute([$sat]);
    $record=$stmt->fetch();

    if(!$record)
        throw new Exception("Invalid SAT");

    /* ==========================
       STEP 2 — VALIDATIONS
    ========================== */

    if($record['status']!=="ACTIVE")
        throw new Exception("SAT already used or expired");

    if($record['processing'])
        throw new Exception("SAT currently processing");

    if(strtotime($record['expires_at'])<time())
        throw new Exception("SAT expired");

    if($amount!=$record['reserved_amount'])
        throw new Exception("Amount mismatch");

    /* ==========================
       STEP 3 — LOCK FOR DISPENSE
    ========================== */

    $pdo->prepare("
        UPDATE sat_tokens
        SET processing=TRUE
        WHERE sat_id=?
    ")->execute([$record['sat_id']]);

    $pdo->commit();

    respond("approved","Dispense authorized",[
        "auth_code"=>$record['auth_code'],
        "amount"=>$record['reserved_amount']
    ]);

}catch(Exception $e){

    if($pdo->inTransaction())
        $pdo->rollBack();

    respond("declined",$e->getMessage());
}
