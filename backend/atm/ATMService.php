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
     * Get wallet balance for a user from the ewallet_pins table
     * Since the balance is stored in the PIN record
     */
    private function getEwalletBalance(string $phone): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                amount,
                sender_phone,
                recipient_phone,
                transaction_id,
                sat_purchased,
                is_redeemed,
                expires_at
            FROM ewallet_pins
            WHERE recipient_phone = ?
              AND is_redeemed = FALSE
              AND expires_at > NOW()
              AND (hold_status IS NULL OR hold_status != 'HELD')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$phone]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("No active e-wallet balance found for phone: {$phone}");
        }
        
        return $result;
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
              AND (hold_status IS NULL OR hold_status != 'HELD')
        ");
        $stmt->execute([$phone]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (float)($result['total_balance'] ?? 0);
    }

    /**
     * Cashout Ewallet - Validates PIN and processes withdrawal with note denominations
     * Uses the ewallet_pins table where each PIN represents a note/amount
     */
    public function cashoutEwallet(int $atmId, string $phone, string $pin, float $amount): array
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Get and validate ATM
            $atm = $this->getAtmForUpdate($atmId);
            $this->ensureAtmHasCash($atm, $amount);

            // 2. Get total available balance for this phone
            $totalBalance = $this->getTotalEwalletBalance($phone);
            
            // 3. Check if user has sufficient balance
            if ($totalBalance < $amount) {
                throw new Exception("Insufficient e-wallet balance. Available: P" . number_format($totalBalance, 2) . ", Requested: P" . number_format($amount, 2));
            }

            // 4. Validate the specific Ewallet PIN
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM ewallet_pins
                WHERE recipient_phone = ?
                  AND pin = ?
                  AND is_redeemed = FALSE
                  AND expires_at > NOW()
                  AND (hold_status IS NULL OR hold_status != 'HELD')
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$phone, $pin]);
            $pinRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pinRecord) {
                throw new Exception("Invalid PIN or no active PIN found for this phone");
            }

            // 5. Check if the PIN amount matches or is less than requested
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
                    hold_status = 'REDEEMED'
                WHERE id = ?
            ");
            $stmt->execute([
                'ATM-' . $atmId,
                $pinRecord['id']
            ]);

            // 9. If the PIN amount is greater than requested, we need to handle the change
            // Option 1: Create a new PIN for the remaining balance (change)
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
                        'ACTIVE'
                    )
                ");
                $stmt->execute([
                    $pinRecord['transaction_id'],
                    $changePin,
                    $pinRecord['sender_phone'],
                    $phone,
                    $changeAmount
                ]);
                
                $changeNote = "Change of P" . number_format($changeAmount, 2) . " returned as new PIN: {$changePin}";
            } else {
                $changeNote = null;
            }

            // 10. Reduce ATM cash
            $this->reduceAtmCash($atmId, $amount);

            // 11. Record ATM transaction with note details
            $this->insertAtmTransaction(
                $atmId, 
                null, // No user_id in ewallet_pins
                $reference, 
                $amount, 
                'SUCCESS',
                [
                    'notes' => $notes,
                    'note_summary' => implode(', ', $noteSummary),
                    'pin_id' => $pinRecord['id'],
                    'phone' => $phone,
                    'original_pin_amount' => $pinAmount,
                    'change' => $changeNote
                ]
            );

            // 12. Post ledger entries
            $this->postLedgerEntry(
                $reference,
                'WALLET-LIABILITY',
                $atm['atm_code'],
                $amount,
                'ATM_CASHOUT',
                "Ewallet cashout - " . implode(', ', $noteSummary),
                "Phone: {$phone}, PIN ID: {$pinRecord['id']}"
            );

            // 13. Update ledger balances
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

            if ($changeNote) {
                $response['change_pin'] = $changePin ?? null;
                $response['change_amount'] = $changeAmount ?? null;
                $response['change_message'] = $changeNote;
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
     * Get wallet balance for display - shows total across all pins
     */
    public function getBalance(string $phone): array
    {
        try {
            $totalBalance = $this->getTotalEwalletBalance($phone);
            
            // Get individual pin details
            $stmt = $this->pdo->prepare("
                SELECT id, amount, pin, expires_at, sender_phone
                FROM ewallet_pins
                WHERE recipient_phone = ?
                  AND is_redeemed = FALSE
                  AND expires_at > NOW()
                  AND (hold_status IS NULL OR hold_status != 'HELD')
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

    /**
     * Redeem a PIN partially (for partial cashout)
     */
    public function redeemPartialPin(int $pinId, float $amount, int $atmId): array
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT * FROM ewallet_pins
                WHERE id = ?
                AND is_redeemed = FALSE
                AND expires_at > NOW()
                FOR UPDATE
            ");
            $stmt->execute([$pinId]);
            $pin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pin) {
                throw new Exception("PIN not found or already redeemed");
            }

            $pinAmount = (float)$pin['amount'];
            if ($amount > $pinAmount) {
                throw new Exception("Requested amount exceeds PIN value");
            }

            if ($amount == $pinAmount) {
                // Full redemption
                return $this->cashoutEwallet($atmId, $pin['recipient_phone'], $pin['pin'], $amount);
            }

            // Partial redemption - create new PIN for remaining balance
            $remaining = $pinAmount - $amount;
            $newPin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Mark original as redeemed
            $stmt = $this->pdo->prepare("
                UPDATE ewallet_pins
                SET is_redeemed = TRUE,
                    redeemed_at = NOW(),
                    redeemed_by = ?,
                    hold_status = 'PARTIAL_REDEEMED'
                WHERE id = ?
            ");
            $stmt->execute(['ATM-' . $atmId, $pinId]);

            // Create new PIN for remaining balance
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
                ) VALUES (?, ?, FALSE, NOW(), ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?, 'PARTIAL_REDEEM', 'ACTIVE')
            ");
            $stmt->execute([
                $pin['transaction_id'],
                $newPin,
                $pin['sender_phone'],
                $pin['recipient_phone'],
                $remaining
            ]);

            $this->pdo->commit();

            return [
                "status" => "SUCCESS",
                "message" => "Partial redemption completed",
                "redeemed_amount" => $amount,
                "remaining_amount" => $remaining,
                "new_pin" => $newPin,
                "new_pin_id" => $this->pdo->lastInsertId()
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

    // ============================================================
    // SAT Methods (unchanged from your original)
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

            // Calculate notes for SAT as well
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

            // DR interbank receivable / CR ATM cash
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
