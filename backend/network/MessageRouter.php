<?php
require_once __DIR__.'/../core/AuthorizationService.php';
require_once __DIR__.'/AdviceProcessor.php';
require_once __DIR__.'/ReversalProcessor.php';
require_once __DIR__.'/ExternalPartnerGateway.php';

class MessageRouter {
    private $pdo;

    public function __construct($pdo){ 
        $this->pdo = $pdo; 
    }

    public function route($input){

        if (!isset($input['type'])) {
            return ['status'=>'ERROR','message'=>'Missing message type'];
        }

        switch ($input['type']) {

            /* ----------------------------------
             * LOCAL WALLET AUTHORIZATION
             * ---------------------------------- */
            case 'AUTH':
                $service = new AuthorizationService($this->pdo);
                return $service->authorize($input);


            /* ----------------------------------
             * EXTERNAL / SWAP AUTHORIZATION
             * ---------------------------------- */
            case 'SWAP_AUTH':

                if (empty($input['counterparty_bank'])) {
                    return [
                        'status'  => 'ERROR',
                        'message' => 'Missing counterparty_bank'
                    ];
                }

                $gateway = new ExternalPartnerGateway($this->pdo);

                return $gateway->authorize(
                    $input['counterparty_bank'],
                    $input
                );


            /* ----------------------------------
             * ADVICE (CONFIRM DISPENSE)
             * ---------------------------------- */
            case 'ADVICE':
                $service = new AdviceProcessor($this->pdo);
                return $service->process($input);


            /* ----------------------------------
             * REVERSAL
             * ---------------------------------- */
            case 'REVERSAL':
                $service = new ReversalProcessor($this->pdo);
                return $service->process($input);


            default:
                return [
                    'status'=>'ERROR',
                    'message'=>'Unknown message type'
                ];
        }
    }
}
