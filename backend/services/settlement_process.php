<?php
namespace Services;

require_once __DIR__ . '/interbank_claims.php';
require_once __DIR__ . '/interbank_net_positions.php';
require_once __DIR__ . '/settlement_batches.php';
require_once __DIR__ . '/../integrations/vouchmorph_integration.php';

use Services\InterbankClaims;
use Services\InterbankNetPositions;
use Services\SettlementBatches;
use Integrations\VouchMorphIntegration;

class SettlementProcess {
    private $claims;
    private $positions;
    private $batches;
    private $vmIntegration;

    public function __construct($pdo) {
        $this->claims = new InterbankClaims($pdo);
        $this->positions = new InterbankNetPositions($pdo);
        $this->batches = new SettlementBatches($pdo);
        $this->vmIntegration = new VouchMorphIntegration($pdo);
    }

    public function processDailySettlement($batchDate) {
        $batchId = $this->batches->createBatch($batchDate);

        $claims = $this->claims->getOpenClaims();
        foreach ($claims as $claim) {
            // Update net position
            $this->positions->updatePosition($claim['foreign_institution'], $claim['amount']);

            // Notify foreign institution / VouchMorph
            $this->vmIntegration->notifySettlement($claim['foreign_institution'], $claim['amount']);

            // Mark claim as settled
            $this->claims->markClaimSettled($claim['id']);
        }

        $this->batches->markBatchSettled($batchId);
        return ['status' => 'SUCCESS', 'batch_id' => $batchId];
    }
}
