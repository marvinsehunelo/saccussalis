<?php
namespace Services;

class SettlementBatches {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function createBatch($batchDate) {
        $stmt = $this->pdo->prepare("INSERT INTO settlement_batches (batch_date, status, created_at) VALUES (?, 'PENDING', NOW())");
        $stmt->execute([$batchDate]);
        return $this->pdo->lastInsertId();
    }

    public function markBatchSettled($batchId) {
        $stmt = $this->pdo->prepare("UPDATE settlement_batches SET status='SETTLED', settled_at=NOW() WHERE id=?");
        $stmt->execute([$batchId]);
    }

    public function getPendingBatches() {
        $stmt = $this->pdo->query("SELECT * FROM settlement_batches WHERE status='PENDING'");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
