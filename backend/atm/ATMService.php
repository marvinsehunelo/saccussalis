<?php
require_once __DIR__ . '/../db.php';

class ATMService
{
    private PDO $pdo;
    
    // ATM denominations/notes available
    private array $denominations = [200, 100, 50, 20, 10];

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

    private function insertAtmTransaction(int $atmId, ?int $userId, string $reference, float $amount, string $status, ?array $notes = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO atm_transactions (
                atm_id, user_id, transaction_reference, amount, status, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $atmId, 
            $userId, 
            $reference, 
            $amount, 
            $status,
            $notes ? json_encode($notes) : null
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

    /**
     * Calculate the optimal note combination for a given amount
     * Returns array of note denominations and count
     */
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
        
        // If there's remaining amount that can't be dispensed with available notes
        if ($remaining > 0) {
            throw new Exception("Amount cannot be dispensed with available denominations. Please use multiples of " . min($this->denominations));
        }
        
        return $notes;
    }

    /**
     * Get wallet balance for a user from wallets table
     * This is the SOURCE OF TRUTH for balance
     */
    private function getWalletBalance(string $phone): array
    {
        // Try with + prefix first, then without
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

    /**
     * Deduct from wallet balance
     */
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

    /**
     * Validate a PIN from ewallet_pins table
     * Returns the PIN record if valid
     */
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

    /**
     * Mark a PIN as redeemed in ewallet_pins table
     */
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

    /**
     * Cashout Ewallet - Validates PIN, checks wallet balance, dispenses cash
     * PIN is just the "key" - balance comes from wallets table
     */
    public function cashoutEwallet(int $atmId, string $phone, string $pin, float $amount): array
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Get and validate ATM
            $atm = $this->getAtmForUpdate($atmId);
            $this->ensureAtmHasCash($atm, $amount);

            // 2. Validate the PIN from ewallet_pins table
            $pinRecord = $this->validatePin($pin, $phone);
            
            // 3. Get wallet balance (SOURCE OF TRUTH)
            $wallet = $this->getWalletBalance($phone);
            
            // 4. Check if wallet has sufficient balance
            $availableBalance = (float)$wallet['balance'] - (float)($wallet['held_balance'] ?? 0);
            if ($availableBalance < $amount) {
                throw new Exception(
                    "Insufficient wallet balance. Available: P" . number_format($availableBalance, 2) . 
                    ", Requested: P" . number_format($amount, 2)
                );
            }

            // 5. Calculate note denominations for the requested amount
            $notes = $this->calculateNotes($amount);
            $noteSummary = [];
            foreach ($notes as $denom => $count) {
                $noteSummary[] = "{$count} x P{$denom}";
            }

            // 6. Generate reference
            $reference = 'EWL-ATM-' . $pinRecord['id'] . '-' . time();

            // 7. Mark PIN as redeemed in ewallet_pins
            $this->markPinAsRedeemed($pinRecord['id'], 'ATM-' . $atmId);

            // 8. Deduct from wallet balance
            $this->deductWalletBalance($wallet['wallet_id'], $amount);

            // 9. Reduce ATM cash
            $this->reduceAtmCash($atmId, $amount);

            // 10. Record ATM transaction
            $this->insertAtmTransaction(
                $atmId, 
                $wallet['user_id'], 
                $reference, 
                $amount, 
                'SUCCESS',
                [
                    'notes' => $notes,
                    'note_summary' => implode(', ', $noteSummary),
                    'pin_id' => $pinRecord['id'],
                    'phone' => $phone,
                    'wallet_id' => $wallet['wallet_id'],
                    'wallet_balance_before' => $wallet['balance'],
                    'wallet_balance_after' => (float)$wallet['balance'] - $amount
                ]
            );

            // 11. Post ledger entries
            $this->postLedgerEntry(
                $reference,
                'WALLET-LIABILITY',
                $atm['atm_code'],
                $amount,
                'ATM_CASHOUT',
                "Ewallet cashout - " . implode(', ', $noteSummary),
                "Phone: {$phone}, PIN ID: {$pinRecord['id']}, Wallet: {$wallet['wallet_id']}"
            );

            $this->updateLedgerAccountBalance('WALLET-LIABILITY', -$amount);
            $this->updateLedgerAccountBalance($atm['atm_code'], -$amount);

            $this->pdo->commit();

            // Get updated balance
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
                "pin_id" => $pinRecord['id']
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
     * Get wallet balance for display
     * Shows balance from wallets table (SOURCE OF TRUTH)
     */
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
                "status" => $wallet['status']
            ];
        } catch (Exception $e) {
            return [
                "status" => "ERROR",
                "message" => $e->getMessage()
            ];
        }
    }

    /**
     * Get available ATM denominations
     */
    public function getDenominations(): array
    {
        return [
            "status" => "SUCCESS",
            "denominations" => $this->denominations
        ];
    }

    /**
     * Place a hold on PIN and wallet
     * This is called BEFORE cashout to reserve funds
     */
    public function placeHold(string $pin, string $phone, float $amount, int $atmId): array
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Validate PIN
            $pinRecord = $this->validatePin($pin, $phone);

            // 2. Get wallet balance
            $wallet = $this->getWalletBalance($phone);
            
            // 3. Check available balance
            $availableBalance = (float)$wallet['balance'] - (float)($wallet['held_balance'] ?? 0);
            if ($availableBalance < $amount) {
                throw new Exception("Insufficient wallet balance. Available: P" . number_format($availableBalance, 2));
            }

            // 4. Generate hold reference
            $holdReference = 'HOLD-' . time() . '-' . rand(1000, 9999);

            // 5. Update PIN hold status
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

            // 6. Update wallet held_balance
            $stmt = $this->pdo->prepare("
                UPDATE wallets 
                SET held_balance = COALESCE(held_balance, 0) + ? 
                WHERE wallet_id = ?
            ");
            $stmt->execute([$amount, $wallet['wallet_id']]);

            // 7. Insert into financial_holds
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

    /**
     * Release a hold (reverses the hold)
     */
    public function releaseHold(string $holdReference): array
    {
        try {
            $this->pdo->beginTransaction();
            
            // Find the PIN hold
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
            
            // Find the financial hold
            $stmt = $this->pdo->prepare("
                SELECT id, wallet_id, amount 
                FROM financial_holds 
                WHERE hold_reference = ? AND status = 'HELD'
                FOR UPDATE
            ");
            $stmt->execute([$holdReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($hold) {
                // Release financial hold
                $stmt = $this->pdo->prepare("
                    UPDATE financial_holds 
                    SET status = 'RELEASED', released_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$hold['id']]);
                
                // Release wallet held_balance
                $stmt = $this->pdo->prepare("
                    UPDATE wallets 
                    SET held_balance = GREATEST(COALESCE(held_balance, 0) - ?, 0) 
                    WHERE wallet_id = ?
                ");
                $stmt->execute([$hold['amount'], $hold['wallet_id']]);
            }
            
            // Release PIN hold
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

    /**
     * Complete cashout with hold (for after hold is placed)
     * This redeems the PIN and deducts from wallet
     */
    public function completeCashoutWithHold(int $atmId, string $holdReference): array
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Get and validate ATM
            $atm = $this->getAtmForUpdate($atmId);

            // 2. Find the held PIN
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

            // 3. Find the financial hold
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

            // 4. Calculate notes
            $notes = $this->calculateNotes($amount);
            $noteSummary = [];
            foreach ($notes as $denom => $count) {
                $noteSummary[] = "{$count} x P{$denom}";
            }

            // 5. Mark PIN as redeemed
            $this->markPinAsRedeemed($pinRecord['id'], 'ATM-' . $atmId);

            // 6. Deduct from wallet balance (held balance was already reserved)
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

            // 7. Update financial hold status
            $stmt = $this->pdo->prepare("
                UPDATE financial_holds 
                SET status = 'DEBITED', debited_at = NOW(), debited_by = ? 
                WHERE id = ?
            ");
            $stmt->execute(['ATM-' . $atmId, $hold['id']]);

            // 8. Reduce ATM cash
            $this->reduceAtmCash($atmId, $amount);

            // 9. Generate reference
            $reference = 'EWL-ATM-HOLD-' . $pinRecord['id'] . '-' . time();

            // 10. Record ATM transaction
            $this->insertAtmTransaction(
                $atmId, 
                $pinRecord['user_id'], 
                $reference, 
                $amount, 
                'SUCCESS',
                [
                    'notes' => $notes,
                    'note_summary' => implode(', ', $noteSummary),
                    'pin_id' => $pinRecord['id'],
                    'phone' => $pinRecord['phone'],
                    'wallet_id' => $pinRecord['wallet_id'],
                    'hold_reference' => $holdReference
                ]
            );

            // 11. Post ledger entries
            $this->postLedgerEntry(
                $reference,
                'WALLET-LIABILITY',
                $atm['atm_code'],
                $amount,
                'ATM_CASHOUT',
                "Ewallet cashout with hold - " . implode(', ', $noteSummary),
                "Hold: {$holdReference}, PIN ID: {$pinRecord['id']}"
            );

            $this->updateLedgerAccountBalance('WALLET-LIABILITY', -$amount);
            $this->updateLedgerAccountBalance($atm['atm_code'], -$amount);

            $this->pdo->commit();

            // Get updated balance
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
                "pin_redeemed" => true
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
    // SAT Methods (unchanged)
    // ============================================================

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

            $notes = $this->calculateNotes($amount);
            $noteSummary = [];
            foreach ($notes as $denom => $count) {
                $noteSummary[] = "{$count} x P{$denom}";
            }

            $dispenseTrace = 'DSP-' . $traceNumber;

            $stmt = $this->pdo->prepare("
                UPDATE sat_tokens
                SET status = 'USED',
                    processing = FALSE,
                    used_at = NOW(),
                    notes = ?
                WHERE sat_id = ?
            ");
            $stmt->execute([
                json_encode(['notes' => $notes, 'note_summary' => implode(', ', $noteSummary)]),
                $sat['sat_id']
            ]);

            $stmt = $this->pdo->prepare("
                UPDATE atm_authorizations
                SET dispense_trace = ?,
                    notes = ?
                WHERE trace_number = ?
            ");
            $stmt->execute([
                $dispenseTrace,
                json_encode(['notes' => $notes, 'note_summary' => implode(', ', $noteSummary)]),
                $traceNumber
            ]);

            $this->reduceAtmCash($atmId, $amount);

            $reference = 'SAT-CASHOUT-' . $sat['sat_id'] . '-' . time();

            $this->insertAtmTransaction(
                $atmId, 
                null, 
                $reference, 
                $amount, 
                'SUCCESS',
                [
                    'notes' => $notes,
                    'note_summary' => implode(', ', $noteSummary),
                    'sat_number' => $satNumber
                ]
            );

            $this->postLedgerEntry(
                $reference,
                'ASSET-INTERBANK-REC',
                $atm['atm_code'],
                $amount,
                'SAT_CASHOUT',
                'SAT cashout completed - ' . implode(', ', $noteSummary),
                'ATM dispensed cash for SAT: ' . $satNumber
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
                    notes,
                    created_at
                ) VALUES (?, ?, ?, 0, ?, 'PENDING', ?, NOW())
            ");
            $stmt->execute([
                $satNumber,
                $sat['issuer_bank'] ?? 'UNKNOWN',
                $amount,
                $amount,
                json_encode(['notes' => $notes, 'note_summary' => implode(', ', $noteSummary)])
            ]);

            $this->pdo->commit();

            return [
                "status" => "COMPLETED",
                "reference" => $reference,
                "dispense_trace" => $dispenseTrace,
                "amount" => $amount,
                "notes" => $notes,
                "note_summary" => implode(', ', $noteSummary)
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
