<?php
namespace Services;

class InterbankClaims {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function createClaim($foreignInstitution, $amount, $satCode) {
        $stmt = $this->pdo->prepare("INSERT INTO interbank_claims (foreign_institution, amount, sat_code, status, created_at) VALUES (?, ?, ?, 'OPEN', NOW())");
        $stmt->execute([$foreignInstitution, $amount, $satCode]);
    }

    public function markClaimSettled($claimId) {
        $stmt = $this->pdo->prepare("UPDATE interbank_claims SET status='SETTLED', settled_at=NOW() WHERE id=?");
        $stmt->execute([$claimId]);
    }

    public function getOpenClaims() {
        $stmt = $this->pdo->query("SELECT * FROM interbank_claims WHERE status='OPEN'");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
