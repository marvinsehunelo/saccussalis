<?php
require_once __DIR__.'/../core/HoldService.php';
class AdviceProcessor {
    private $pdo;
    public function __construct($pdo){ $this->pdo=$pdo; }
    public function process($data){
        $hold = new HoldService($this->pdo);
        $hold->commitHold($data['auth_code']);
        return ['status'=>'SUCCESS'];
    }
}
