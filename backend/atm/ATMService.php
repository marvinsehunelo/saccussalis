<?php
require_once __DIR__ . '/../db.php';

class ATMService
{
    private PDO $pdo;

    public function __construct()
    {
        global $pdo;
        $this->pdo = $pdo;
    }

    private function getAtmForUpdate(int $atmId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM atms
            WHERE id = ?
            AND status = 'ACTIVE'
            FOR UPDATE
        ");
        $stmt->execute([$atmId]);
        $atm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$atm) {
            throw new Exception("ATM not found or inactive");
        }

        return $atm;
    }

    private function ensureAtmHasCash(array $atm, float $amount): void
    {
        if ((float)$atm['cash_balance'] < $amount) {
            throw new Exception("ATM has insufficient cash");
        }
    }

    private function reduceAtmCash(int $atmId, float $amount): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE atms
            SET cash_balance = cash_balance - ? 
            WHERE id = ?
        ");
        $stmt->execute([$amount, $atmId]);
    }

    private function insertAtmTransaction(int $atmId, ?int $userId, string $reference, float $amount, string $status): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO atm_transactions (
                atm_id, user_id, transaction_reference, amount, status, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$atmId, $userId, $reference, $amount, $status]);
    }

    private function postLedgerEntry(
        string $reference,
        string $debitAccount,
        string $creditAccount,
        float $amount,
        string $referenceType,
        string $narration,
        ?string $notes = null
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO ledger_entries (
                reference,
                debit_account,
                credit_account,
                amount,
                currency,
                notes,
                reference_type,
                narration,
                created_at
            ) VALUES (?, ?, ?, ?, 'BWP', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $reference,
            $debitAccount,
            $creditAccount,
            $amount,
            $notes,
            $referenceType,
            $narration
        ]);
    }

    private function updateLedgerAccountBalance(string $accountNumber, float $delta): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ledger_accounts
            SET balance = balance + ?, updated_at = NOW()
            WHERE account_number = ?
        ");
        $stmt->execute([$delta, $accountNumber]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("Ledger account not found: {$accountNumber}");
        }
    }

    public function cashoutEwallet(int $atmId, string $phone, string $pin, float $amount): array
    {
        try {
            $this->pdo->beginTransaction();

            $atm = $this->getAtmForUpdate($atmId);
            $this->ensureAtmHasCash($atm, $amount);

            $stmt = $this->pdo->prepare("
                SELECT *
                FROM ewallet_pins
                WHERE recipient_phone = ?
                  AND is_redeemed = FALSE
                  AND expires_at > NOW()
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$phone]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception("Invalid or expired PIN");
            }

            // If your pin is stored plain text in DB:
            if ((string)$row['pin'] !== (string)$pin) {
                throw new Exception("Incorrect PIN");
            }

            if ((float)$row['amount'] !== (float)$amount) {
                throw new Exception("Amount mismatch");
            }

            $reference = 'EWL-ATM-' . $row['id'] . '-' . time();

            $stmt = $this->pdo->prepare("
                UPDATE ewallet_pins
                SET is_redeemed = TRUE,
                    redeemed_at = NOW(),
                    redeemed_by = ?
                WHERE id = ?
            ");
            $stmt->execute(['ATM-' . $atmId, $row['id']]);

            $this->reduceAtmCash($atmId, $amount);

            $this->insertAtmTransaction($atmId, null, $reference, $amount, 'SUCCESS');

            // DR wallet liability control / CR ATM cash asset
            $this->postLedgerEntry(
                $reference,
                'WALLET-CONTROL',
                $atm['atm_code'],
                $amount,
                'ATM_CASHOUT',
                'Ewallet cardless cashout',
                'Ewallet PIN redeemed at ATM'
            );

            // Running balances if you maintain them manually
            $this->updateLedgerAccountBalance('WALLET-CONTROL', $amount);
            $this->updateLedgerAccountBalance($atm['atm_code'], -$amount);

            $this->pdo->commit();

            return [
                "status" => "APPROVED",
                "message" => "Dispense Cash",
                "reference" => $reference,
                "amount" => $amount
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                "status" => "DECLINED",
                "message" => $e->getMessage()
            ];
        }
    }

    public function authorizeSAT(int $atmId, string $satNumber, string $pin, float $amount): array
    {
        try {
            $this->pdo->beginTransaction();

            $atm = $this->getAtmForUpdate($atmId);
            $this->ensureAtmHasCash($atm, $amount);

            $stmt = $this->pdo->prepare("
                SELECT *
                FROM sat_tokens
                WHERE sat_number = ?
                  AND status = 'ACTIVE'
                  AND expires_at > NOW()
                  AND processing = FALSE
                FOR UPDATE
            ");
            $stmt->execute([$satNumber]);
            $sat = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sat) {
                throw new Exception("Invalid or expired SAT");
            }

            if ((string)$sat['pin'] !== (string)$pin) {
                throw new Exception("Invalid PIN");
            }

            if ((float)$sat['amount'] !== (float)$amount) {
                throw new Exception("Amount mismatch");
            }

            $trace = 'SAT-AUTH-' . $sat['sat_id'] . '-' . time();

            $stmt = $this->pdo->prepare("
                UPDATE sat_tokens
                SET processing = TRUE,
                    last_attempt_at = NOW(),
                    attempts = attempts + 1
                WHERE sat_id = ?
            ");
            $stmt->execute([$sat['sat_id']]);

            $stmt = $this->pdo->prepare("
                INSERT INTO atm_authorizations (
                    sat_code,
                    trace_number,
                    acquirer_bank,
                    amount,
                    response_code,
                    auth_code,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $satNumber,
                $trace,
                'SACCUSSALIS',
                $amount,
                '00',
                substr(hash('sha256', $trace), 0, 12)
            ]);

            $this->pdo->commit();

            return [
                "status" => "APPROVED",
                "trace_number" => $trace,
                "sat_id" => $sat['sat_id'],
                "amount" => $amount
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                "status" => "DECLINED",
                "message" => $e->getMessage()
            ];
        }
    }

    public function completeSAT(int $atmId, string $satNumber, string $traceNumber): array
    {
        try {
            $this->pdo->beginTransaction();

            $atm = $this->getAtmForUpdate($atmId);

            $stmt = $this->pdo->prepare("
                SELECT *
                FROM sat_tokens
                WHERE sat_number = ?
                  AND status = 'ACTIVE'
                  AND processing = TRUE
                FOR UPDATE
            ");
            $stmt->execute([$satNumber]);
            $sat = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sat) {
                throw new Exception("SAT not available for completion");
            }

            $amount = (float)$sat['amount'];
            $this->ensureAtmHasCash($atm, $amount);

            $dispenseTrace = 'DSP-' . $traceNumber;

            $stmt = $this->pdo->prepare("
                UPDATE sat_tokens
                SET status = 'USED',
                    processing = FALSE,
                    used_at = NOW()
                WHERE sat_id = ?
            ");
            $stmt->execute([$sat['sat_id']]);

            $stmt = $this->pdo->prepare("
                UPDATE atm_authorizations
                SET dispense_trace = ?
                WHERE trace_number = ?
            ");
            $stmt->execute([$dispenseTrace, $traceNumber]);

            $this->reduceAtmCash($atmId, $amount);

            $reference = 'SAT-CASHOUT-' . $sat['sat_id'] . '-' . time();

            $this->insertAtmTransaction($atmId, null, $reference, $amount, 'SUCCESS');

            // DR interbank receivable / CR ATM cash
            $this->postLedgerEntry(
                $reference,
                'ASSET-INTERBANK-REC',
                $atm['atm_code'],
                $amount,
                'SAT_CASHOUT',
                'SAT cashout completed',
                'ATM dispensed cash for SAT'
            );

            $this->updateLedgerAccountBalance('ASSET-INTERBANK-REC', $amount);
            $this->updateLedgerAccountBalance($atm['atm_code'], -$amount);

            $stmt = $this->pdo->prepare("
                INSERT INTO interbank_claims (
                    sat_code,
                    issuer_institution,
                    amount,
                    fee,
                    net_amount,
                    status,
                    created_at
                ) VALUES (?, ?, ?, 0, ?, 'PENDING', NOW())
            ");
            $stmt->execute([
                $satNumber,
                $sat['issuer_bank'] ?? 'UNKNOWN',
                $amount,
                $amount
            ]);

            $this->pdo->commit();

            return [
                "status" => "COMPLETED",
                "reference" => $reference,
                "dispense_trace" => $dispenseTrace,
                "amount" => $amount
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                "status" => "DECLINED",
                "message" => $e->getMessage()
            ];
        }
    }
}
