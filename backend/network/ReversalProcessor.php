<?php
require_once __DIR__.'/../core/HoldService.php';
class ReversalProcessor {
    private $pdo;
    public function __construct($pdo){ $this->pdo=$pdo; }
    public function process($data){
        $hold = new HoldService($this->pdo);
        $hold->releaseHold($data['auth_code']);
        return ['status'=>'REVERSED'];
    }
}
