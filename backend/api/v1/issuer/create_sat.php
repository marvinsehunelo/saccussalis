<?php
declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";

function respond($status,$msg,$extra=[]){
    echo json_encode(array_merge(["status"=>$status,"message"=>$msg],$extra));
    exit;
}

try {

    $input=json_decode(file_get_contents("php://input"),true);

    $phone=$input['phone']??null;
    $type=$input['type']??null; // WALLET or VOUCHER
    $amount=(float)($input['amount']??0);
    $pin=$input['pin']??null;

    if(!$phone || !$type || $amount<=0)
        respond("error","Invalid request");

    $phone='+' . ltrim(preg_replace('/\D/','',$phone),'+');

    $pdo->beginTransaction();

    /* ==========================
       STEP 1 — CHECK FEE
    ========================== */

    $fee=5.00;

    $stmt=$pdo->prepare("
        SELECT * FROM swap_fee_tracking
        WHERE phone=? AND instrument_type=? AND fee_paid=TRUE
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$phone,$type]);
    $feeRecord=$stmt->fetch();

    if(!$feeRecord){
        // deduct fee from wallet
        $stmt=$pdo->prepare("SELECT wallet_id,balance FROM wallets WHERE phone=? FOR UPDATE");
        $stmt->execute([$phone]);
        $wallet=$stmt->fetch();

        if(!$wallet || $wallet['balance']<$fee)
            throw new Exception("Swap fee unpaid and insufficient balance");

        $pdo->prepare("UPDATE wallets SET balance=balance-? WHERE wallet_id=?")
            ->execute([$fee,$wallet['wallet_id']]);

        $pdo->prepare("
            INSERT INTO swap_fee_tracking(phone,instrument_type,instrument_ref,fee_paid,paid_by,fee_amount)
            VALUES(?,?,?,?,?,?)
        ")->execute([$phone,$type,0,true,'RECEIVER',$fee]);
    }

    /* ==========================
       STEP 2 — LOCK FUNDS
    ========================== */

    if($type==="WALLET"){

        $stmt=$pdo->prepare("SELECT wallet_id,balance FROM wallets WHERE phone=? FOR UPDATE");
        $stmt->execute([$phone]);
        $wallet=$stmt->fetch();

        if(!$wallet || $wallet['balance']<$amount)
            throw new Exception("Insufficient wallet balance");

        // move to suspense
        $pdo->prepare("UPDATE wallets SET balance=balance-? WHERE wallet_id=?")
            ->execute([$amount,$wallet['wallet_id']]);

        $pdo->prepare("
            INSERT INTO cash_instruments(phone,instrument_type,wallet_id,reserved_amount)
            VALUES(?,?,?,?)
        ")->execute([$phone,"WALLET",$wallet['wallet_id'],$amount]);

    }else{

        $stmt=$pdo->prepare("
            SELECT id,amount,is_redeemed FROM ewallet_pins
            WHERE pin=? AND recipient_phone=? FOR UPDATE
        ");
        $stmt->execute([$pin,$phone]);
        $voucher=$stmt->fetch();

        if(!$voucher || $voucher['is_redeemed'])
            throw new Exception("Voucher invalid");

        $pdo->prepare("
            UPDATE ewallet_pins SET is_redeemed=TRUE,redeemed_by='SWAP_LOCK'
            WHERE id=?
        ")->execute([$voucher['id']]);

        $pdo->prepare("
            INSERT INTO cash_instruments(phone,instrument_type,pin_id,reserved_amount)
            VALUES(?,?,?,?)
        ")->execute([$phone,"VOUCHER",$voucher['id'],$voucher['amount']]);

        $amount=$voucher['amount'];
    }

    $instrument_id=$pdo->lastInsertId();

    /* ==========================
       STEP 3 — GENERATE SAT
    ========================== */

    $sat=bin2hex(random_bytes(4));
    $auth=rand(100000,999999);
    $expiry=date("Y-m-d H:i:s",time()+900); // 15 min

    $pdo->prepare("
        INSERT INTO sat_tokens(instrument_id,sat_code,auth_code,expires_at,network)
        VALUES(?,?,?,?,?)
    ")->execute([$instrument_id,$sat,$auth,$expiry,"VOUCHMORPHN"]);

    $pdo->commit();

    respond("approved","SAT created",[
        "sat"=>$sat,
        "auth_code"=>$auth,
        "amount"=>$amount,
        "expires_at"=>$expiry
    ]);

}catch(Exception $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    respond("error",$e->getMessage());
}
