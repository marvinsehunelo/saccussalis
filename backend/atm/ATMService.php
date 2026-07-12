<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../network/ISO8583Gateway.php';

class ATMService
{
    private PDO $pdo;
    private ISO8583Gateway $isoGateway;
    
    // ATM denominations/notes available
    private array $denominations = [200, 100, 50, 20, 10];
    
    // Ledger account mappings
    private array $ledgerAccounts = [
        'wallet_liability' => 'WALLET-CONTROL',
        'atm_cash' => 'ATM-001',
        'interbank_rec' => 'ASSET-INTERBANK-REC',
        'atm_fee_income' => 'INC-ATM-FEE',
        'settlement' => 'ASSET-MAIN-SETTLEMENT'
    ];

    public function __construct()
    {
        global $pdo;
        $this->pdo = $pdo;
        $this->isoGateway = new ISO8583Gateway($pdo);
    }

    // ============================================================
    // PRIVATE METHODS
    // ============================================================

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
        $stmt->execute([
            $atmId, 
            $userId, 
            $reference, 
            $amount, 
            $status
        ]);
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

    private function calculateNotes(float $amount): array
    {
        $remaining = (int)$amount;
        $notes = [];
        
        foreach ($this->denominations as $denom) {
            if ($remaining >= $denom) {
                $count = floor($remaining / $denom);
                $notes[$denom] = $count;
                $remaining -= $count * $denom;
            }
        }
        
        if ($remaining > 0) {
            throw new Exception("Amount cannot be dispensed with available denominations. Please use multiples of " . min($this->denominations));
        }
        
        return $notes;
    }

    private function getWalletBalance(string $phone): array
    {
        $stmt = $this->pdo->prepare("
            SELECT wallet_id, user_id, phone, balance, held_balance, status, is_frozen
            FROM wallets 
            WHERE (phone = ? OR phone = ?)
              AND status = 'active'
              AND is_frozen = false
            FOR UPDATE
        ");
        $stmt->execute([$phone, ltrim($phone, '+')]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("Wallet not found for phone: {$phone}");
        }
        
        return $result;
    }

    private function deductWalletBalance(int $walletId, float $amount): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE wallets
            SET balance = balance - ?,
                updated_at = NOW()
            WHERE wallet_id = ?
              AND balance >= ?
        ");
        $stmt->execute([$amount, $walletId, $amount]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception("Insufficient wallet balance");
        }
    }

    private function validatePin(string $pin, string $phone): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ewallet_pins
            WHERE pin = ?
              AND recipient_phone = ?
              AND is_redeemed = FALSE
              AND expires_at > NOW()
            FOR UPDATE
        ");
        $stmt->execute([$pin, $phone]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("Invalid PIN, already redeemed, or expired");
        }
        
        return $result;
    }

    private function markPinAsRedeemed(int $pinId, string $redeemedBy): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ewallet_pins
            SET is_redeemed = TRUE,
                redeemed_at = NOW(),
                redeemed_by = ?,
                hold_status = false,
                hold_reference = NULL,
                held_at = NULL,
                held_by = NULL
            WHERE id = ?
        ");
        $stmt->execute([$redeemedBy, $pinId]);
    }

    // ============================================================
    // ISO 8583 MESSAGE HANDLING FOR SAT CASOUTS
    // ============================================================

    /**
     * Send ISO 8583 dispense advice to VouchMorph
     * This is the standard way banks communicate
     */
    private function sendDispenseAdvice(array $data): void
    {
        try {
            // Build ISO 8583 message
            $isoMessage = [
                'message_type' => '0220', // Financial Transaction Advice
                'sat_number' => $data['sat_number'],
                'amount' => $data['amount'],
                'trace_number' => $data['trace_number'],
                'auth_code' => $data['auth_code'] ?? null,
                'atm_id' => $data['atm_id'],
                'acquirer_institution' => $data['acquirer'] ?? 'SACCUSSALIS',
                'issuer_institution' => $data['issuer'] ?? 'SACCUSSALIS',
                'status' => 'DISPENSED',
                'timestamp' => date('Y-m-d H:i:s'),
                'reference' => $data['reference']
            ];
            
            // Send through ISO 8583 gateway
            $response = $this->isoGateway->sendDispenseAdvice($isoMessage);
            
            // Log the notification
            $stmt = $this->pdo->prepare("
                INSERT INTO vouchmorph_notifications (
                    transaction_reference,
                    sat_number,
                    amount,
                    status,
                    response,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $data['reference'],
                $data['sat_number'],
                $data['amount'],
                $response['success'] ? 'SUCCESS' : 'FAILED',
                json_encode($response)
            ]);
            
            error_log("ISO8583: Dispense advice sent for SAT: {$data['sat_number']}, Success: " . ($response['success'] ? 'YES' : 'NO'));
            
        } catch (Exception $e) {
            error_log("ISO8583: Error sending dispense advice: " . $e->getMessage());
            // Don't throw - we don't want to fail the transaction
        }
    }

    // ============================================================
    // EWALLET CASOUT (INTERNAL - NO EXTERNAL NOTIFICATION)
    // ============================================================

    /**
     * Cashout Ewallet - Internal transaction
     * NO VOUCHMORPH NOTIFICATION
     */
    public function cashoutEwallet(int $atmId, string $phone, string $pin, float $amount): array
    {
        try {
            $this->pdo->beginTransaction();

            $atm = $this->getAtmForUpdate($atmId);
            $this->ensureAtmHasCash($atm, $amount);

            $pinRecord = $this->validatePin($pin, $phone);
            $wallet = $this->getWalletBalance($phone);
            
            $availableBalance = (float)$wallet['balance'] - (float)($wallet['held_balance'] ?? 0);
            if ($availableBalance < $amount) {
                throw new Exception(
                    "Insufficient wallet balance. Available: P" . number_format($availableBalance, 2) . 
                    ", Requested: P" . number_format($amount, 2)
                );
            }

            $notes = $this->calculateNotes($amount);
            $noteSummary = [];
            foreach ($notes as $denom => $count) {
                $noteSummary[] = "{$count} x P{$denom}";
            }

            $reference = 'EWL-ATM-' . $pinRecord['id'] . '-' . time();

            $this->markPinAsRedeemed($pinRecord['id'], 'ATM-' . $atmId);
            $this->deductWalletBalance($wallet['wallet_id'], $amount);
            $this->reduceAtmCash($atmId, $amount);

            $this->insertAtmTransaction(
                $atmId, 
                $wallet['user_id'], 
                $reference, 
                $amount, 
                'SUCCESS'
            );

            $this->postLedgerEntry(
                $reference,
                $this->ledgerAccounts['wallet_liability'],
                $this->ledgerAccounts['atm_cash'],
                $amount,
                'ATM_CASHOUT',
                "Ewallet cashout - " . implode(', ', $noteSummary),
                "Phone: {$phone}, PIN ID: {$pinRecord['id']}, Wallet: {$wallet['wallet_id']}"
            );

            $this->updateLedgerAccountBalance($this->ledgerAccounts['wallet_liability'], -$amount);
            $this->updateLedgerAccountBalance($this->ledgerAccounts['atm_cash'], -$amount);

            $this->pdo->commit();

            $newBalance = (float)$wallet['balance'] - $amount;

            return [
                "status" => "APPROVED",
                "message" => "Cash dispensed successfully",
                "reference" => $reference,
                "amount" => $amount,
                "notes" => $notes,
                "note_summary" => implode(', ', $noteSummary),
                "wallet_balance" => $newBalance,
                "phone" => $phone,
                "pin_redeemed" => true,
                "pin_id" => $pinRecord['id'],
                "atm_id" => $atmId,
                "user_id" => $wallet['user_id'],
                "transaction_type" => "EWALLET",
                "vouchmorph_notified" => false // No external notification
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

    // ============================================================
    // SAT METHODS (EXTERNAL - WITH VOUCHMORPH NOTIFICATION)
    // ============================================================

    /**
     * Authorize SAT - Like Visa authorization request
     */
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
                "amount" => $amount,
                "auth_code" => substr(hash('sha256', $trace), 0, 12)
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

    /**
     * Complete SAT Cashout - Dispenses cash and sends ISO 8583 message
     * This is where VouchMorph gets notified via standard ISO 8583
     */
    public function completeSAT(int $atmId, string $satNumber, string $traceNumber): array
    {
        try {
            $this->pdo->beginTransaction();

            $atm = $this->getAtmForUpdate($atmId);

            $stmt = $this->pdo->prepare("
                SELECT s.*, a.auth_code
                FROM sat_tokens s
                JOIN atm_authorizations a ON s.sat_number = a.sat_code
                WHERE s.sat_number = ?
                  AND s.status = 'ACTIVE'
                  AND s.processing = TRUE
                  AND a.trace_number = ?
                FOR UPDATE
            ");
            $stmt->execute([$satNumber, $traceNumber]);
            $sat = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sat) {
                throw new Exception("SAT not available for completion");
            }

            $amount = (float)$sat['amount'];
            $authCode = $sat['auth_code'] ?? 'AUTH' . strtoupper(substr(uniqid(), -6));
            
            $this->ensureAtmHasCash($atm, $amount);

            $notes = $this->calculateNotes($amount);
            $noteSummary = [];
            foreach ($notes as $denom => $count) {
                $noteSummary[] = "{$count} x P{$denom}";
            }

            $dispenseTrace = 'DSP-' . $traceNumber;
            $reference = 'SAT-CASHOUT-' . $sat['sat_id'] . '-' . time();

            // Mark SAT as used
            $stmt = $this->pdo->prepare("
                UPDATE sat_tokens
                SET status = 'USED',
                    processing = FALSE,
                    used_at = NOW(),
                    auth_code = ?
                WHERE sat_id = ?
            ");
            $stmt->execute([$authCode, $sat['sat_id']]);

            // Update authorization
            $stmt = $this->pdo->prepare("
                UPDATE atm_authorizations
                SET dispense_trace = ?,
                    auth_code = ?,
                    response_code = '00'
                WHERE trace_number = ?
            ");
            $stmt->execute([$dispenseTrace, $authCode, $traceNumber]);

            // Reduce ATM cash
            $this->reduceAtmCash($atmId, $amount);

            // Record transaction
            $this->insertAtmTransaction(
                $atmId, 
                null, 
                $reference, 
                $amount, 
                'SUCCESS'
            );

            // Post ledger entries (interbank settlement)
            $this->postLedgerEntry(
                $reference,
                $this->ledgerAccounts['interbank_rec'],
                $this->ledgerAccounts['atm_cash'],
                $amount,
                'SAT_CASHOUT',
                'SAT cashout completed - ' . implode(', ', $noteSummary),
                'ATM dispensed cash for SAT: ' . $satNumber
            );

            $this->updateLedgerAccountBalance($this->ledgerAccounts['interbank_rec'], $amount);
            $this->updateLedgerAccountBalance($this->ledgerAccounts['atm_cash'], -$amount);

            // Create interbank claim
            $stmt = $this->pdo->prepare("
                INSERT INTO interbank_claims (
                    sat_code,
                    issuer_institution,
                    amount,
                    fee,
                    net_amount,
                    status,
                    trace_number,
                    auth_code,
                    created_at
                ) VALUES (?, ?, ?, 0, ?, 'PENDING', ?, ?, NOW())
            ");
            $stmt->execute([
                $satNumber,
                $sat['issuer_bank'] ?? 'UNKNOWN',
                $amount,
                $amount,
                $traceNumber,
                $authCode
            ]);

            $this->pdo->commit();

            // ============================================================
            // SEND ISO 8583 DISPENSE ADVICE TO VOUCHMORPH
            // This is the standard way to notify external systems
            // ============================================================
            
            $dispenseData = [
                'sat_number' => $satNumber,
                'amount' => $amount,
                'trace_number' => $traceNumber,
                'auth_code' => $authCode,
                'atm_id' => $atmId,
                'reference' => $reference,
                'acquirer' => 'SACCUSSALIS',
                'issuer' => $sat['issuer_bank'] ?? 'UNKNOWN'
            ];
            
            // Send ISO 8583 dispense advice (non-blocking)
            $this->sendDispenseAdviceAsync($dispenseData);

            return [
                "status" => "COMPLETED",
                "reference" => $reference,
                "dispense_trace" => $dispenseTrace,
                "amount" => $amount,
                "notes" => $notes,
                "note_summary" => implode(', ', $noteSummary),
                "auth_code" => $authCode,
                "transaction_type" => "SAT",
                "vouchmorph_notified" => true
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

    /**
     * Async send dispense advice (non-blocking)
     */
    private function sendDispenseAdviceAsync(array $data): void
    {
        // Check if VouchMorph is enabled
        if (!getenv('VOUCHMORPH_ENABLED') && !defined('VOUCHMORPH_ENABLED')) {
            error_log("VOUCHMORPH: Notifications disabled");
            return;
        }
        
        // Try background process
        if (function_exists('exec')) {
            $jsonData = json_encode($data);
            $cmd = sprintf(
                'php %s/../scripts/send_dispense_advice.php "%s" > /dev/null 2>&1 &',
                __DIR__,
                addslashes($jsonData)
            );
            exec($cmd);
            error_log("ISO8583: Dispense advice queued for SAT: {$data['sat_number']}");
        } else {
            // Fallback: synchronous
            $this->sendDispenseAdvice($data);
        }
    }

    // ============================================================
    // OTHER METHODS
    // ============================================================

    public function getBalance(string $phone): array
    {
        try {
            $wallet = $this->getWalletBalance($phone);
            $availableBalance = (float)$wallet['balance'] - (float)($wallet['held_balance'] ?? 0);
            
            return [
                "status" => "SUCCESS",
                "balance" => (float)$wallet['balance'],
                "held_balance" => (float)($wallet['held_balance'] ?? 0),
                "available_balance" => $availableBalance,
                "phone" => $wallet['phone'],
                "wallet_id" => $wallet['wallet_id'],
                "user_id" => $wallet['user_id'],
                "status" => $wallet['status']
            ];
        } catch (Exception $e) {
            return [
                "status" => "ERROR",
                "message" => $e->getMessage()
            ];
        }
    }

    public function getDenominations(): array
    {
        return [
            "status" => "SUCCESS",
            "denominations" => $this->denominations
        ];
    }

    public function placeHold(string $pin, string $phone, float $amount, int $atmId): array
    {
        try {
            $this->pdo->beginTransaction();

            $pinRecord = $this->validatePin($pin, $phone);
            $wallet = $this->getWalletBalance($phone);
            
            $availableBalance = (float)$wallet['balance'] - (float)($wallet['held_balance'] ?? 0);
            if ($availableBalance < $amount) {
                throw new Exception("Insufficient wallet balance. Available: P" . number_format($availableBalance, 2));
            }

            $holdReference = 'HOLD-' . time() . '-' . rand(1000, 9999);

            $stmt = $this->pdo->prepare("
                UPDATE ewallet_pins 
                SET hold_status = true, 
                    hold_reference = ?, 
                    held_at = NOW(), 
                    held_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $holdReference,
                'ATM-' . $atmId,
                $pinRecord['id']
            ]);

            $stmt = $this->pdo->prepare("
                UPDATE wallets 
                SET held_balance = COALESCE(held_balance, 0) + ? 
                WHERE wallet_id = ?
            ");
            $stmt->execute([$amount, $wallet['wallet_id']]);

            $stmt = $this->pdo->prepare("
                INSERT INTO financial_holds 
                    (wallet_id, amount, hold_reference, foreign_bank, session_id, status, 
                     requester, expires_at, created_at)
                VALUES 
                    (?, ?, ?, ?, ?, 'HELD', ?, NOW() + INTERVAL '24 hours', NOW())
                RETURNING id
            ");
            $stmt->execute([
                $wallet['wallet_id'],
                $amount,
                $holdReference,
                'SACCUSSALIS',
                $holdReference,
                'ATM-' . $atmId
            ]);
            $holdId = $stmt->fetchColumn();

            $this->pdo->commit();

            return [
                "status" => "SUCCESS",
                "hold_placed" => true,
                "hold_reference" => $holdReference,
                "amount" => $amount,
                "available_balance" => $availableBalance - $amount,
                "message" => "Hold placed successfully"
            ];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                "status" => "ERROR",
                "hold_placed" => false,
                "message" => $e->getMessage()
            ];
        }
    }

    public function releaseHold(string $holdReference): array
    {
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                SELECT id, hold_reference
                FROM ewallet_pins 
                WHERE hold_reference = ? AND hold_status = true
                FOR UPDATE
            ");
            $stmt->execute([$holdReference]);
            $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pinRecord) {
                throw new Exception("PIN hold not found for reference: $holdReference");
            }
            
            $stmt = $this->pdo->prepare("
                SELECT id, wallet_id, amount 
                FROM financial_holds 
                WHERE hold_reference = ? AND status = 'HELD'
                FOR UPDATE
            ");
            $stmt->execute([$holdReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($hold) {
                $stmt = $this->pdo->prepare("
                    UPDATE financial_holds 
                    SET status = 'RELEASED', released_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$hold['id']]);
                
                $stmt = $this->pdo->prepare("
                    UPDATE wallets 
                    SET held_balance = GREATEST(COALESCE(held_balance, 0) - ?, 0) 
                    WHERE wallet_id = ?
                ");
                $stmt->execute([$hold['amount'], $hold['wallet_id']]);
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE ewallet_pins 
                SET hold_status = false, 
                    hold_reference = NULL, 
                    held_at = NULL, 
                    held_by = NULL
                WHERE id = ?
            ");
            $stmt->execute([$pinRecord['id']]);
            
            $this->pdo->commit();
            
            return [
                "status" => "SUCCESS",
                "message" => "Hold released successfully"
            ];
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                "status" => "ERROR",
                "message" => $e->getMessage()
            ];
        }
    }

    public function completeCashoutWithHold(int $atmId, string $holdReference): array
    {
        try {
            $this->pdo->beginTransaction();

            $atm = $this->getAtmForUpdate($atmId);

            $stmt = $this->pdo->prepare("
                SELECT ep.*, w.wallet_id, w.user_id, w.phone, w.balance
                FROM ewallet_pins ep
                JOIN wallets w ON w.phone = ep.recipient_phone
                WHERE ep.hold_reference = ? 
                  AND ep.hold_status = true
                  AND ep.is_redeemed = FALSE
                FOR UPDATE
            ");
            $stmt->execute([$holdReference]);
            $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pinRecord) {
                throw new Exception("Held PIN not found for reference: $holdReference");
            }

            $stmt = $this->pdo->prepare("
                SELECT id, wallet_id, amount 
                FROM financial_holds 
                WHERE hold_reference = ? AND status = 'HELD'
                FOR UPDATE
            ");
            $stmt->execute([$holdReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                throw new Exception("Financial hold not found for reference: $holdReference");
            }

            $amount = (float)$hold['amount'];
            $this->ensureAtmHasCash($atm, $amount);

            $notes = $this->calculateNotes($amount);
            $noteSummary = [];
            foreach ($notes as $denom => $count) {
                $noteSummary[] = "{$count} x P{$denom}";
            }

            $this->markPinAsRedeemed($pinRecord['id'], 'ATM-' . $atmId);

            $stmt = $this->pdo->prepare("
                UPDATE wallets 
                SET balance = balance - ?,
                    held_balance = GREATEST(COALESCE(held_balance, 0) - ?, 0),
                    updated_at = NOW()
                WHERE wallet_id = ?
                  AND balance >= ?
            ");
            $stmt->execute([$amount, $amount, $pinRecord['wallet_id'], $amount]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Insufficient wallet balance");
            }

            $stmt = $this->pdo->prepare("
                UPDATE financial_holds 
                SET status = 'DEBITED', debited_at = NOW(), debited_by = ? 
                WHERE id = ?
            ");
            $stmt->execute(['ATM-' . $atmId, $hold['id']]);

            $this->reduceAtmCash($atmId, $amount);

            $reference = 'EWL-ATM-HOLD-' . $pinRecord['id'] . '-' . time();

            $this->insertAtmTransaction(
                $atmId, 
                $pinRecord['user_id'], 
                $reference, 
                $amount, 
                'SUCCESS'
            );

            $this->postLedgerEntry(
                $reference,
                $this->ledgerAccounts['wallet_liability'],
                $this->ledgerAccounts['atm_cash'],
                $amount,
                'ATM_CASHOUT_HOLD',
                "Ewallet cashout with hold - " . implode(', ', $noteSummary),
                "Hold: {$holdReference}, PIN ID: {$pinRecord['id']}"
            );

            $this->updateLedgerAccountBalance($this->ledgerAccounts['wallet_liability'], -$amount);
            $this->updateLedgerAccountBalance($this->ledgerAccounts['atm_cash'], -$amount);

            $this->pdo->commit();

            $newBalance = (float)$pinRecord['balance'] - $amount;

            return [
                "status" => "APPROVED",
                "message" => "Cash dispensed successfully",
                "reference" => $reference,
                "hold_reference" => $holdReference,
                "amount" => $amount,
                "notes" => $notes,
                "note_summary" => implode(', ', $noteSummary),
                "wallet_balance" => $newBalance,
                "phone" => $pinRecord['phone'],
                "pin_redeemed" => true,
                "atm_id" => $atmId,
                "user_id" => $pinRecord['user_id'],
                "transaction_type" => "EWALLET_HOLD",
                "vouchmorph_notified" => false
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

    public function getAtmTransactions(int $atmId, int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM atm_transactions
                WHERE atm_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$atmId, $limit]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                "status" => "SUCCESS",
                "atm_id" => $atmId,
                "transaction_count" => count($transactions),
                "transactions" => $transactions
            ];
        } catch (Exception $e) {
            return [
                "status" => "ERROR",
                "message" => $e->getMessage()
            ];
        }
    }
}
