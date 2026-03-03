<?php
declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";

function respond($s,$m,$x=[]){
    echo json_encode(array_merge(["status"=>$s,"message"=>$m],$x));
    exit;
}

try{

$input=json_decode(file_get_contents("php://input"),true);

$sat=$input['sat']??null;
$trace=$input['trace']??null;
$acquirer=$input['acquirer_bank']??null;

if(!$sat || !$trace || !$acquirer)
    respond("error","Invalid request");

$pdo->beginTransaction();

/* =====================================
   STEP 1 — Prevent Duplicate Posting
===================================== */

$exists=$pdo->prepare("
    SELECT 1 FROM atm_authorizations
    WHERE dispense_trace=?
");
$exists->execute([$trace]);

if($exists->fetch()){
    $pdo->commit();
    respond("ok","Already processed");
}

/* =====================================
   STEP 2 — Lock SAT
===================================== */

$stmt=$pdo->prepare("
SELECT s.*,c.*
FROM sat_tokens s
JOIN cash_instruments c ON c.instrument_id=s.instrument_id
WHERE s.sat_code=?
FOR UPDATE
");
$stmt->execute([$sat]);
$row=$stmt->fetch();

if(!$row)
    throw new Exception("SAT not found");

if($row['status']!=='ACTIVE' || !$row['processing'])
    throw new Exception("SAT not authorized");

/* =====================================
   STEP 3 — Mark Used
===================================== */

$pdo->prepare("
UPDATE sat_tokens
SET status='USED'
WHERE sat_id=?
")->execute([$row['sat_id']]);

$pdo->prepare("
UPDATE cash_instruments
SET status='DISPENSED'
WHERE instrument_id=?
")->execute([$row['instrument_id']]);

/* =====================================
   STEP 4 — Accounting Movement
===================================== */

$amount=$row['amount'];
$reference="ATM-".$trace;

/* Customer liability leaves holding */
$pdo->prepare("
INSERT INTO ledger_entries(reference,debit_account,credit_account,amount,notes)
VALUES(?,?,?,?,?)
")->execute([
    $reference,
    'SWAP_SUSPENSE',
    'INTERBANK_CLEARING',
    $amount,
    'ATM withdrawal via swap'
]);

/* =====================================
   STEP 5 — Create Settlement Obligation
===================================== */

$pdo->prepare("
INSERT INTO clearing_positions
(debtor_bank,creditor_bank,amount,reference,status)
VALUES(?,?,?,?, 'PENDING')
")->execute([
    'SACCUSSALIS',
    $acquirer,
    $amount,
    $reference
]);

/* =====================================
   STEP 6 — Save ATM Record
===================================== */

$pdo->prepare("
INSERT INTO atm_authorizations
(sat_code,trace_number,acquirer_bank,amount,response_code,dispense_trace)
VALUES(?,?,?,?, '00',?)
")->execute([
    $sat,$trace,$acquirer,$amount,$trace
]);

$pdo->commit();

respond("ok","Withdrawal settled");

}catch(Exception $e){

    if($pdo->inTransaction())
        $pdo->rollBack();

    respond("error",$e->getMessage());
}
