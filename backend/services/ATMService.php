<?php
// /opt/lampp/htdocs/SaccusSalisbank/backend/services/ATMService.php
class ATMService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function validateAndDispense($satCode, $pin, $atmId) {
        try {
            $this->pdo->beginTransaction();

            // Get SAT token with related data
            $stmt = $this->pdo->prepare("
                SELECT s.*, c.phone, c.wallet_id, c.pin_id, c.instrument_type,
                       ep.pin as stored_pin, ep.is_redeemed, ep.amount as pin_amount
                FROM sat_tokens s
                JOIN cash_instruments c ON s.instrument_id = c.instrument_id
                LEFT JOIN ewallet_pins ep ON c.pin_id = ep.id
                WHERE s.sat_code = ? AND s.status = 'ACTIVE'
                AND s.expires_at > NOW()
                FOR UPDATE
            ");
            $stmt->execute([$satCode]);
            $sat = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sat) {
                throw new Exception("Invalid or expired SAT token");
            }

            // Verify PIN if this is a PIN-based token
            if ($sat['pin_id'] && !password_verify($pin, $sat['stored_pin'])) {
                throw new Exception("Invalid PIN");
            }

            // Check if PIN already redeemed
            if ($sat['pin_id'] && $sat['is_redeemed']) {
                throw new Exception("PIN already redeemed");
            }

            // Mark SAT as used
            $stmt = $this->pdo->prepare("
                UPDATE sat_tokens SET status = 'USED', processing = false 
                WHERE sat_id = ?
            ");
            $stmt->execute([$sat['sat_id']]);

            // Mark ewallet_pin as redeemed if applicable
            if ($sat['pin_id']) {
                $stmt = $this->pdo->prepare("
                    UPDATE ewallet_pins SET is_redeemed = true, redeemed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$sat['pin_id']]);
            }

            // Update cash instrument
            $stmt = $this->pdo->prepare("
                UPDATE cash_instruments SET status = 'DISPENSED' 
                WHERE instrument_id = ?
            ");
            $stmt->execute([$sat['instrument_id']]);

            // Create ATM authorization record
            $stmt = $this->pdo->prepare("
                INSERT INTO atm_authorizations (
                    sat_code, trace_number, acquirer_bank, amount, 
                    response_code, auth_code, dispense_trace, created_at
                ) VALUES (?, ?, ?, ?, '00', ?, ?, NOW())
            ");
            $traceNumber = 'ATM' . time() . rand(100, 999);
            $authCode = rand(100000, 999999);
            $stmt->execute([
                $satCode, $traceNumber, $atmId, $sat['amount'], 
                $authCode, $traceNumber
            ]);

            // Create clearing position
            $stmt = $this->pdo->prepare("
                INSERT INTO clearing_positions (
                    debtor_bank, creditor_bank, amount, reference, status, created_at
                ) VALUES (?, ?, ?, ?, 'PENDING', NOW())
            ");
            $stmt->execute(['SACCUSSALIS', $atmId, $sat['amount'], $traceNumber]);

            $this->pdo->commit();

            return [
                'status' => 'success',
                'message' => 'Cash dispensed successfully',
                'amount' => $sat['amount'],
                'trace_number' => $traceNumber,
                'auth_code' => $authCode
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
