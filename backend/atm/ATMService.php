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
     * Get total available balance across all unredeemed pins for a phone
     */
    private function getTotalEwalletBalance(string $phone): float
    {
        $stmt = $this->pdo->prepare("
            SELECT SUM(amount) as total_balance
            FROM ewallet_pins
            WHERE recipient_phone = ?
              AND is_redeemed = FALSE
              AND expires_at > NOW()
              AND (hold_status IS NULL OR hold_status = false OR hold_status = 'false')
        ");
        $stmt->execute([$phone]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (float)($result['total_balance'] ?? 0);
    }

    /**
     * Get a specific PIN by pin number
     */
    private function getPinByNumber(string $pin): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ewallet_pins
            WHERE pin = ?
              AND is_redeemed = FALSE
              AND expires_at > NOW()
              AND (hold_status IS NULL OR hold_status = false OR hold_status = 'false')
            FOR UPDATE
        ");
        $stmt->execute([$pin]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Place a hold on a PIN (calls hold.php via API or internal function)
     */
    public function placeHold(string $pin, string $phone, float $amount, int $atmId, string $holdReference = null): array
    {
        try {
            $this->pdo->beginTransaction();
            
            // Find the PIN
            $pinRecord = $this->getPinByNumber($pin);
            
            if (!$pinRecord) {
                throw new Exception("PIN not found, already redeemed, expired, or on hold");
            }
            
            if ($pinRecord['amount'] < $amount) {
                throw new Exception("PIN has insufficient value. Available: {$pinRecord['amount']}, Requested: $amount");
            }
            
            // Generate hold reference if not provided
            if (!$holdReference) {
                $holdReference = 'HOLD-' . time() . '-' . rand(1000, 9999);
            }
            
            // Update PIN hold status
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
            
            // Find and update wallet
            $targetPhone = $phone;
            if (!str_starts_with($targetPhone, '+')) {
                $targetPhone = '+' . $targetPhone;
            }
            
            $stmt = $this->pdo->prepare("
                SELECT wallet_id, balance, held_balance
                FROM wallets 
                WHERE phone = ? AND status = 'active' AND is_frozen = false
                FOR UPDATE
            ");
            $stmt->execute([$targetPhone]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$wallet) {
                $phoneWithoutPlus = ltrim($targetPhone, '+');
                $stmt = $this->pdo->prepare("
                    SELECT wallet_id, balance, held_balance
                    FROM wallets 
                    WHERE phone = ? AND status = 'active' AND is_frozen = false
                    FOR UPDATE
                ");
                $stmt->execute([$phoneWithoutPlus]);
                $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$wallet) {
                throw new Exception("Wallet not found for phone: $phone");
            }
            
            // Update wallet held_balance
            $stmt = $this->pdo->prepare("
                UPDATE wallets 
                SET held_balance = COALESCE(held_balance, 0) + ? 
                WHERE wallet_id = ?
            ");
            $stmt->execute([$amount, $wallet['wallet_id']]);
            
            // Insert into financial_holds
            $stmt = $this->pdo->prepare("
                INSERT INTO financial_holds 
                    (wallet_id, amount, hold_reference, foreign_bank, session_id, status, 
                     requester, signature_verified, expires_at, created_at)
                VALUES 
                    (?, ?, ?, ?, ?, 'HELD', ?, 1, NOW() + INTERVAL '24 hours', NOW())
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
                SELECT id, hold_reference, amount
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
     * Cashout Ewallet - Redeems a PIN (after hold) or directly if no hold
     * This is the final dispense step
     */
    public function cashoutEwallet(int $atmId, string $phone, string $pin, float $amount, string $holdReference = null): array
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Get and validate ATM
            $atm = $this->getAtmForUpdate($atmId);
            $this->ensureAtmHasCash($atm, $amount);

            // 2. Get total available balance for this phone
            $totalBalance = $this->getTotalEwalletBalance($phone);
            
            if ($totalBalance < $amount) {
                throw new Exception("Insufficient e-wallet balance. Available: P" . number_format($totalBalance, 2) . ", Requested: P" . number_format($amount, 2));
            }

            // 3. Find the PIN record
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
            $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pinRecord) {
                throw new Exception("PIN not found or already redeemed");
            }

            // 4. Check if PIN is on hold (if hold reference provided, verify it matches)
            if ($holdReference) {
                if ($pinRecord['hold_status'] !== true && $pinRecord['hold_status'] !== 'true' && $pinRecord['hold_status'] !== 1) {
                    throw new Exception("PIN is not on hold. Please place a hold first.");
                }
                if ($pinRecord['hold_reference'] !== $holdReference) {
                    throw new Exception("Hold reference mismatch");
                }
            }

            // 5. Check if PIN has sufficient amount
            $pinAmount = (float)$pinRecord['amount'];
            if ($pinAmount < $amount) {
                throw new Exception("PIN amount (P" . number_format($pinAmount, 2) . ") is less than requested amount (P" . number_format($amount, 2) . ")");
            }

            // 6. Calculate note denominations for the requested amount
            $notes = $this->calculateNotes($amount);
            $noteSummary = [];
            foreach ($notes as $denom => $count) {
                $noteSummary[] = "{$count} x P{$denom}";
            }

            // 7. Generate reference
            $reference = 'EWL-ATM-' . $pinRecord['id'] . '-' . time();

            // 8. Mark PIN as redeemed
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
            $stmt->execute([
                'ATM-' . $atmId,
                $pinRecord['id']
            ]);

            // 9. If the PIN amount is greater than requested, create change PIN
            $changePin = null;
            $changeAmount = null;
            if ($pinAmount > $amount) {
                $changeAmount = $pinAmount - $amount;
                $changePin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO ewallet_pins (
                        transaction_id,
                        pin,
                        is_redeemed,
                        created_at,
                        sender_phone,
                        recipient_phone,
                        expires_at,
                        amount,
                        generated_by,
                        hold_status
                    ) VALUES (
                        ?,
                        ?,
                        FALSE,
                        NOW(),
                        ?,
                        ?,
                        DATE_ADD(NOW(), INTERVAL 24 HOUR),
                        ?,
                        'ATM-CHANGE',
                        false
                    )
                ");
                $stmt->execute([
                    $pinRecord['transaction_id'],
                    $changePin,
                    $pinRecord['sender_phone'],
                    $phone,
                    $changeAmount
                ]);
            }

            // 10. If there was a hold, release it
            if ($holdReference) {
                // Release wallet held_balance
                $stmt = $this->pdo->prepare("
                    UPDATE wallets 
                    SET held_balance = GREATEST(COALESCE(held_balance, 0) - ?, 0) 
                    WHERE wallet_id = (
                        SELECT wallet_id FROM wallets WHERE phone = ? AND status = 'active'
                    )
                ");
                $stmt->execute([$amount, $phone]);
                
                // Update financial hold status
                $stmt = $this->pdo->prepare("
                    UPDATE financial_holds 
                    SET status = 'DEBITED', debited_at = NOW(), debited_by = ? 
                    WHERE hold_reference = ?
                ");
                $stmt->execute(['ATM-' . $atmId, $holdReference]);
            }

            // 11. Reduce ATM cash
            $this->reduceAtmCash($atmId, $amount);

            // 12. Record ATM transaction
            $this->insertAtmTransaction(
                $atmId, 
                null, 
                $reference, 
                $amount, 
                'SUCCESS',
                [
                    'notes' => $notes,
                    'note_summary' => implode(', ', $noteSummary),
                    'pin_id' => $pinRecord['id'],
                    'phone' => $phone,
                    'original_pin_amount' => $pinAmount,
                    'hold_reference' => $holdReference,
                    'change_pin' => $changePin,
                    'change_amount' => $changeAmount
                ]
            );

            // 13. Post ledger entries
            $this->postLedgerEntry(
                $reference,
                'WALLET-LIABILITY',
                $atm['atm_code'],
                $amount,
                'ATM_CASHOUT',
                "Ewallet cashout - " . implode(', ', $noteSummary),
                "Phone: {$phone}, PIN ID: {$pinRecord['id']}"
            );

            $this->updateLedgerAccountBalance('WALLET-LIABILITY', -$amount);
            $this->updateLedgerAccountBalance($atm['atm_code'], -$amount);

            $this->pdo->commit();

            // Get remaining balance
            $remainingBalance = $this->getTotalEwalletBalance($phone);

            $response = [
                "status" => "APPROVED",
                "message" => "Cash dispensed successfully",
                "reference" => $reference,
                "amount" => $amount,
                "notes" => $notes,
                "note_summary" => implode(', ', $noteSummary),
                "remaining_balance" => $remainingBalance,
                "phone" => $phone
            ];

            if ($changePin) {
                $response['change_pin'] = $changePin;
                $response['change_amount'] = $changeAmount;
                $response['change_message'] = "Change of P" . number_format($changeAmount, 2) . " returned as new PIN: {$changePin}";
            }

            return $response;

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
     */
    public function getBalance(string $phone): array
    {
        try {
            $totalBalance = $this->getTotalEwalletBalance($phone);
            
            // Get individual pin details
            $stmt = $this->pdo->prepare("
                SELECT id, amount, pin, expires_at, sender_phone, hold_status
                FROM ewallet_pins
                WHERE recipient_phone = ?
                  AND is_redeemed = FALSE
                  AND expires_at > NOW()
                  AND (hold_status IS NULL OR hold_status = false OR hold_status = 'false')
                ORDER BY expires_at ASC
            ");
            $stmt->execute([$phone]);
            $pins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                "status" => "SUCCESS",
                "total_balance" => $totalBalance,
                "phone" => $phone,
                "pin_count" => count($pins),
                "pins" => $pins
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
