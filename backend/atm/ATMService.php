<?php
require_once __DIR__ . '/../db.php';

class ATMService
{
    private $pdo;

    public function __construct()
    {
        global $pdo;
        $this->pdo = $pdo;
    }

    /* ============================================
       1️⃣ EWALLET CARDLESS CASHOUT
    ============================================ */

    public function cashoutEwallet($phone, $pin, $amount)
    {
        try {

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT * FROM ewallet_pins
                WHERE recipient_phone = ?
                AND is_redeemed = 0
                AND expires_at > NOW()
                FOR UPDATE
            ");
            $stmt->execute([$phone]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception("Invalid or expired PIN");
            }

            if (!password_verify($pin, $row['pin'])) {
                throw new Exception("Incorrect PIN");
            }

            if ($row['amount'] != $amount) {
                throw new Exception("Amount mismatch");
            }

            // mark redeemed
            $update = $this->pdo->prepare("
                UPDATE ewallet_pins
                SET is_redeemed = 1,
                    redeemed_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$row['id']]);

            $this->pdo->commit();

            return [
                "status" => "APPROVED",
                "message" => "Dispense Cash"
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                "status" => "DECLINED",
                "message" => $e->getMessage()
            ];
        }
    }

    /* ============================================
       2️⃣ SWAP SAT CASHOUT
    ============================================ */

    public function authorizeSAT($satCode, $amount)
    {
        try {

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT * FROM sat_tokens
                WHERE sat_code = ?
                AND status = 'ACTIVE'
                AND expires_at > NOW()
                FOR UPDATE
            ");
            $stmt->execute([$satCode]);
            $sat = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sat) {
                throw new Exception("Invalid or expired SAT");
            }

            if ($sat['amount'] != $amount) {
                throw new Exception("Amount mismatch");
            }

            // Mark processing
            $this->pdo->prepare("
                UPDATE sat_tokens
                SET processing = 1
                WHERE sat_code = ?
            ")->execute([$satCode]);

            $this->pdo->commit();

            return [
                "status" => "APPROVED",
                "auth_code" => $sat['auth_code']
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                "status" => "DECLINED",
                "message" => $e->getMessage()
            ];
        }
    }

    public function completeSAT($satCode)
    {
        $stmt = $this->pdo->prepare("
            UPDATE sat_tokens
            SET status='USED',
                processing=0
            WHERE sat_code=?
        ");
        $stmt->execute([$satCode]);

        return [
            "status" => "COMPLETED"
        ];
    }
}
