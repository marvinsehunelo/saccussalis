<?php
namespace ATM;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/interbank_claims.php';
require_once __DIR__ . '/../integrations/vouchmorph_integration.php';

use Services\InterbankClaims;
use Integrations\VouchMorphIntegration;

class ATMCashout {
    private $pdo;
    private $claimsService;
    private $vmIntegration;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->claimsService = new InterbankClaims($pdo);
        $this->vmIntegration = new VouchMorphIntegration($pdo);
    }

    public function cashoutSAT($satCode, $atmId) {
        // 1. Validate SAT via VouchMorph
        $satDetails = $this->vmIntegration->validateSAT($satCode);
        if (!$satDetails['valid']) {
            return ['status' => 'FAILED', 'message' => 'Invalid SAT'];
        }

        // 2. Log cashout attempt
        $stmt = $this->pdo->prepare("INSERT INTO atm_cashouts (sat_code, atm_id, issued_to, amount, status, created_at) VALUES (?, ?, ?, ?, 'PENDING', NOW())");
        $stmt->execute([$satCode, $atmId, $satDetails['beneficiary'], $satDetails['amount']]);

        // 3. Create interbank claim
        $this->claimsService->createClaim($satDetails['origin_bank'], $satDetails['amount'], $satCode);

        return ['status' => 'SUCCESS', 'message' => 'Cash dispensed', 'amount' => $satDetails['amount']];
    }
}
