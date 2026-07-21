<?php
/**
 * ATMCashout.php
 * 
 * Handles ATM cardless cashout operations for SAT (Swap Authorization Tokens)
 * Notifies VouchMorph when cashout is completed - same pattern as eWallet
 */

declare(strict_types=1);

namespace ATM;

class ATMCashout {
    private $pdo;
    private $vouchMorphUrl;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->vouchMorphUrl = 'https://vouchmorphn-production.up.railway.app/api/v1/swap/cashout_confirm.php';
    }

    /**
     * Process SAT cashout and notify VouchMorph
     * Same pattern as eWallet cashout notification
     */
    public function cashoutSAT(string $satCode, string $atmId, string $pin, float $amount): array {
        try {
            // Start transaction
            $this->pdo->beginTransaction();

            // 1. Validate SAT via VouchMorph (using the same notification endpoint)
            $satValidation = $this->validateSATWithVouchMorph($satCode, $pin, $amount);
            
            if (!$satValidation['valid']) {
                $this->pdo->rollBack();
                return [
                    'status' => 'FAILED',
                    'message' => $satValidation['message'] ?? 'Invalid SAT'
                ];
            }

            // 2. Log cashout attempt
            $stmt = $this->pdo->prepare("
                INSERT INTO atm_cashouts 
                (sat_code, atm_id, issued_to, amount, status, trace_number, created_at) 
                VALUES (?, ?, ?, ?, 'PENDING', ?, NOW())
            ");
            $traceNumber = 'ATM_' . time() . '_' . substr($satCode, -6);
            $stmt->execute([
                $satCode,
                $atmId,
                $satValidation['beneficiary'] ?? 'UNKNOWN',
                $amount,
                $traceNumber
            ]);
            $cashoutId = $this->pdo->lastInsertId();

            // 3. Create interbank claim record (if needed)
            $this->createInterbankClaim($satValidation, $amount, $satCode);

            // 4. Mark SAT as redeemed
            $this->markSATRedeemed($satCode, $atmId);

            // 5. Create transaction record
            $this->createTransactionRecord($satCode, $amount, $atmId, $traceNumber);

            // 6. Commit transaction
            $this->pdo->commit();

            // 7. Generate cashout reference
            $cashoutReference = 'ATM-' . time() . '-' . substr($satCode, -6);

            // ============================================================
            // 8. NOTIFY VOUCHMORPH - SAME PATTERN AS EWALLET
            // ============================================================
            $notificationResult = $this->notifyVouchMorph(
                $satCode,           // voucher_number
                $amount,            // amount
                $cashoutReference,  // cashout_reference
                'ATM_SYSTEM',       // requester
                $atmId,             // atm_id
                $satValidation['swap_reference'] ?? null  // swap_reference (optional)
            );

            if ($notificationResult['success']) {
                error_log("ATM Cashout: ✅ VouchMorph notified successfully for SAT: {$satCode}");
            } else {
                error_log("ATM Cashout: ⚠️ VouchMorph notification failed - " . ($notificationResult['message'] ?? 'Unknown'));
            }

            // ============================================================
            // 9. RESPONSE
            // ============================================================
            return [
                'status' => 'SUCCESS',
                'message' => 'Cash dispensed successfully',
                'amount' => $amount,
                'trace_number' => $traceNumber,
                'cashout_reference' => $cashoutReference,
                'cashout_id' => $cashoutId,
                'vouchmorph_notified' => $notificationResult['success']
            ];

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("ATM Cashout ERROR: " . $e->getMessage());
            return [
                'status' => 'FAILED',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Notify VouchMorph - SAME PATTERN AS EWALLET
     * Uses the same endpoint and payload structure
     */
    private function notifyVouchMorph(
        string $voucherNumber, 
        float $amount, 
        string $cashoutReference, 
        string $requester,
        ?string $atmId = null,
        ?string $swapReference = null
    ): array {
        $payload = [
            'voucher_number' => $voucherNumber,
            'amount' => $amount,
            'currency' => 'BWP',
            'cashout_reference' => $cashoutReference,
            'source' => 'ATM',
            'atm_id' => $atmId,
            'requester' => $requester,
            'swap_reference' => $swapReference,
            'timestamp' => time()
        ];

        // Add signature if function exists
        if (function_exists('generate_signature')) {
            $payload['signature'] = generate_signature($payload, 'ATM');
        }

        $ch = curl_init($this->vouchMorphUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Correlation-ID: ' . uniqid('ATM_', true),
                'X-Source: ATM_CASHOUT',
                'X-ATM-ID: ' . ($atmId ?? 'UNKNOWN')
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'message' => $curlError];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'message' => "HTTP {$httpCode}", 'response' => $response];
        }

        return ['success' => true, 'response' => json_decode($response, true)];
    }

    /**
     * Validate SAT with VouchMorph
     * Calls the same notification endpoint to validate
     */
    private function validateSATWithVouchMorph(string $satCode, string $pin, float $amount): array {
        // First try local validation from database
        $stmt = $this->pdo->prepare("
            SELECT 
                sat_id, sat_code, pin_hash, amount, currency, status,
                beneficiary, origin_bank, swap_reference, expires_at
            FROM sat_tokens
            WHERE sat_code = :sat_code
            AND status IN ('active', 'pending')
            AND expires_at > NOW()
        ");
        $stmt->execute([':sat_code' => $satCode]);
        $sat = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$sat) {
            return ['valid' => false, 'message' => 'SAT not found or expired'];
        }

        // Verify PIN
        if (!password_verify($pin, $sat['pin_hash'])) {
            return ['valid' => false, 'message' => 'Invalid SAT PIN'];
        }

        // Verify amount matches
        if ((float)$sat['amount'] != $amount) {
            return ['valid' => false, 'message' => 'Amount does not match SAT value'];
        }

        return [
            'valid' => true,
            'beneficiary' => $sat['beneficiary'] ?? 'UNKNOWN',
            'origin_bank' => $sat['origin_bank'] ?? 'UNKNOWN',
            'swap_reference' => $sat['swap_reference'] ?? null,
            'amount' => (float)$sat['amount']
        ];
    }

    /**
     * Create interbank claim record
     */
    private function createInterbankClaim(array $satValidation, float $amount, string $satCode): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO interbank_claims 
            (claim_reference, origin_bank, destination_bank, amount, currency, status, sat_code, created_at)
            VALUES (?, ?, 'ATM_' . ?, ?, 'BWP', 'PENDING', ?, NOW())
        ");
        $claimRef = 'CLAIM_' . time() . '_' . substr($satCode, -6);
        $stmt->execute([
            $claimRef,
            $satValidation['origin_bank'] ?? 'UNKNOWN',
            $satCode,
            $amount,
            $satCode
        ]);
    }

    /**
     * Mark SAT as redeemed
     */
    private function markSATRedeemed(string $satCode, string $atmId): void {
        $stmt = $this->pdo->prepare("
            UPDATE sat_tokens 
            SET status = 'redeemed',
                redeemed_at = NOW(),
                redeemed_by = :atm_id
            WHERE sat_code = :sat_code
        ");
        $stmt->execute([
            ':sat_code' => $satCode,
            ':atm_id' => $atmId
        ]);
    }

    /**
     * Create transaction record
     */
    private function createTransactionRecord(string $satCode, float $amount, string $atmId, string $traceNumber): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions 
            (user_id, from_account, to_account, type, amount, reference, description, status, created_at)
            VALUES 
            (NULL, 'SAT:' . :sat_code, 'ATM:' . :atm_id, 'atm_cashout', :amount, :trace, :desc, 'completed', NOW())
        ");
        $stmt->execute([
            ':sat_code' => $satCode,
            ':atm_id' => $atmId,
            ':amount' => $amount,
            ':trace' => $traceNumber,
            ':desc' => "ATM cashout of SAT {$satCode} at ATM {$atmId}"
        ]);
    }
}
