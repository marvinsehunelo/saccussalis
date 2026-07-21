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
     */
    public function cashoutSAT(string $satNumber, string $atmId, string $pin, float $amount): array {
        try {
            $this->pdo->beginTransaction();

            // 1. Validate SAT from database
            $satValidation = $this->validateSAT($satNumber, $pin, $amount);
            
            if (!$satValidation['valid']) {
                $this->pdo->rollBack();
                return [
                    'status' => 'FAILED',
                    'message' => $satValidation['message'] ?? 'Invalid SAT'
                ];
            }

            // 2. Mark SAT as used (redeemed)
            $this->markSATUsed($satNumber, $atmId);

            // 3. Create transaction record
            $this->createTransactionRecord($satNumber, $amount, $atmId);

            // 4. Commit transaction
            $this->pdo->commit();

            // 5. Generate cashout reference
            $traceNumber = 'ATM_' . time() . '_' . substr($satNumber, -6);
            $cashoutReference = 'ATM-' . time() . '-' . substr($satNumber, -6);

            // ============================================================
            // 6. NOTIFY VOUCHMORPH
            // ============================================================
            $notificationResult = $this->notifyVouchMorph(
                $satNumber,
                $amount,
                $cashoutReference,
                'ATM_SYSTEM',
                $atmId,
                null
            );

            if ($notificationResult['success']) {
                error_log("ATM Cashout: ✅ VouchMorph notified successfully for SAT: {$satNumber}");
            } else {
                error_log("ATM Cashout: ⚠️ VouchMorph notification failed - " . ($notificationResult['message'] ?? 'Unknown'));
            }

            return [
                'status' => 'SUCCESS',
                'message' => 'Cash dispensed successfully',
                'amount' => $amount,
                'trace_number' => $traceNumber,
                'cashout_reference' => $cashoutReference,
                'sat_id' => $satValidation['sat_id'],
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
     * Notify VouchMorph
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
     * Validate SAT - matches your EXACT table schema
     */
    private function validateSAT(string $satNumber, string $pin, float $amount): array {
        $stmt = $this->pdo->prepare("
            SELECT 
                sat_id, sat_number, amount, status, 
                issuer_bank, acquirer_network, expires_at, used_at,
                pin, code_hash, requester, instrument_id
            FROM sat_tokens
            WHERE sat_number = :sat_number
            AND LOWER(status) IN ('active', 'pending')
            AND expires_at > NOW()
        ");
        $stmt->execute([':sat_number' => $satNumber]);
        $sat = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$sat) {
            return ['valid' => false, 'message' => 'SAT not found or expired'];
        }

        // Check if already used
        if (!empty($sat['used_at'])) {
            return ['valid' => false, 'message' => 'SAT has already been used'];
        }

        // Verify PIN - check both 'pin' column and 'code_hash' (hashed)
        $pinValid = false;
        
        // Check plain text pin column (bpchar) - trim whitespace
        if (!empty($sat['pin']) && trim($sat['pin']) === $pin) {
            $pinValid = true;
        }
        
        // Check hashed pin (code_hash)
        if (!$pinValid && !empty($sat['code_hash']) && password_verify($pin, $sat['code_hash'])) {
            $pinValid = true;
        }

        if (!$pinValid) {
            return ['valid' => false, 'message' => 'Invalid SAT PIN'];
        }

        // Verify amount matches
        if ((float)$sat['amount'] != $amount) {
            return ['valid' => false, 'message' => 'Amount does not match SAT value'];
        }

        return [
            'valid' => true,
            'requester' => $sat['requester'] ?? 'UNKNOWN',
            'issuer_bank' => $sat['issuer_bank'] ?? 'UNKNOWN',
            'acquirer_network' => $sat['acquirer_network'] ?? 'UNKNOWN',
            'instrument_id' => $sat['instrument_id'] ?? null,
            'sat_id' => (int)$sat['sat_id'],
            'amount' => (float)$sat['amount']
        ];
    }

    /**
     * Mark SAT as used (redeemed)
     */
    private function markSATUsed(string $satNumber, string $atmId): void {
        $stmt = $this->pdo->prepare("
            UPDATE sat_tokens 
            SET status = 'used',
                used_at = NOW(),
                processing = NULL,
                verification_method = 'atm_cashout',
                updated_at = NOW()
            WHERE sat_number = :sat_number
        ");
        $stmt->execute([':sat_number' => $satNumber]);
    }

    /**
     * Create transaction record
     */
    private function createTransactionRecord(string $satNumber, float $amount, string $atmId): void {
        // Use user_id = 1 (system admin) since user_id is required
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions 
            (user_id, from_account, to_account, type, amount, description, status, created_at)
            VALUES 
            (1, :from_account, :to_account, 'atm_cashout', :amount, :desc, 'completed', NOW())
        ");
        $stmt->execute([
            ':from_account' => 'SAT:' . $satNumber,
            ':to_account' => 'ATM:' . $atmId,
            ':amount' => $amount,
            ':desc' => "ATM cashout of SAT {$satNumber} at ATM {$atmId}"
        ]);
    }

    /**
     * Get SAT status
     */
    public function getSATStatus(string $satNumber): array {
        $stmt = $this->pdo->prepare("
            SELECT sat_number, amount, status, expires_at, used_at
            FROM sat_tokens
            WHERE sat_number = :sat_number
        ");
        $stmt->execute([':sat_number' => $satNumber]);
        $sat = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$sat) {
            return ['status' => 'not_found', 'message' => 'SAT not found'];
        }

        return [
            'status' => $sat['status'],
            'amount' => (float)$sat['amount'],
            'expires_at' => $sat['expires_at'],
            'used_at' => $sat['used_at']
        ];
    }
}
