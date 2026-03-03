<?php
namespace Services;

class InterbankNetPositions {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function updatePosition($foreignInstitution, $amount) {
        $stmt = $this->pdo->prepare("INSERT INTO net_positions (institution, amount, updated_at) VALUES (?, ?, NOW())
                                     ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount), updated_at=NOW()");
        $stmt->execute([$foreignInstitution, $amount]);
    }

    public function getPositions() {
        $stmt = $this->pdo->query("SELECT * FROM net_positions");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
