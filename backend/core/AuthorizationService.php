<?php
require_once __DIR__.'/HoldService.php';

class AuthorizationService {
    private $pdo;
    public function __construct($pdo){
        $this->pdo = $pdo;
    }

    public function authorize($data){
        $walletId = $data['wallet_id'];
        $amount   = $data['amount'];

        if($amount > 10000){
            return ['status'=>'DECLINED'];
        }

        $holdService = new HoldService($this->pdo);
        $authCode = $holdService->createHold($walletId,$amount);

        return ['status'=>'APPROVED','auth_code'=>$authCode];
    }
}
