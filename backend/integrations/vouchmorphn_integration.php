<?php
/**
 * VouchMorph Integration
 * Handles communication with VouchMorph API
 * Extended to support Dispense Advice (ISO 8583)
 */

class VouchMorphIntegration
{
    private $pdo;
    private $apiKey;
    private $baseUrl;
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->apiKey = getenv('VOUCHMORPH_API_KEY') ?: '';
        $this->baseUrl = getenv('VOUCHMORPH_BASE_URL') ?: 'https://api.vouchmorph.com/v1';
    }
    
    /**
     * Send Dispense Advice to VouchMorph
     * This is called after cash is dispensed at ATM
     * This is the ONLY method that needs to be added
     */
    public function sendDispenseAdvice(array $data): array
    {
        // Check if VouchMorph is enabled
        if (!getenv('VOUCHMORPH_ENABLED') && !defined('VOUCHMORPH_ENABLED')) {
            error_log("VOUCHMORPH: Notifications disabled");
            return ['success' => false, 'error' => 'VouchMorph disabled'];
        }
        
        if (empty($this->apiKey)) {
            error_log("VOUCHMORPH: API key not configured");
            return ['success' => false, 'error' => 'API key not configured'];
        }
        
        // Build ISO 8583 compliant message
        $payload = [
            'message_type' => '0220', // Financial Transaction Advice
            'sat_number' => $data['sat_number'] ?? null,
            'amount' => $data['amount'] ?? 0,
            'trace_number' => $data['trace_number'] ?? null,
            'auth_code' => $data['auth_code'] ?? null,
            'atm_id' => $data['atm_id'] ?? null,
            'acquirer' => $data['acquirer'] ?? 'SACCUSSALIS',
            'issuer' => $data['issuer'] ?? 'SACCUSSALIS',
            'status' => 'DISPENSED',
            'reference' => $data['reference'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'institution' => 'SACCUSSALIS'
        ];
        
        // Send to VouchMorph
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/sat/dispense-advice');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey,
            'X-Idempotency-Key: ' . ($data['reference'] ?? uniqid())
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("VOUCHMORPH: CURL error - {$curlError}");
            return ['success' => false, 'error' => $curlError];
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("VOUCHMORPH: Dispense advice sent successfully for SAT: {$data['sat_number']}");
            
            // Log the notification in database
            $this->logNotification($data, 'SUCCESS', $response);
            
            return [
                'success' => true,
                'response' => json_decode($response, true),
                'http_code' => $httpCode
            ];
        } else {
            error_log("VOUCHMORPH: Dispense advice failed. HTTP: {$httpCode}, Response: {$response}");
            
            // Log the failure
            $this->logNotification($data, 'FAILED', null, "HTTP {$httpCode}: {$response}");
            
            return [
                'success' => false,
                'error' => "HTTP {$httpCode}: {$response}",
                'http_code' => $httpCode
            ];
        }
    }
    
    /**
     * Log notification to database for tracking
     */
    private function logNotification(array $data, string $status, ?string $response = null, ?string $error = null): void
    {
        try {
            // Check if table exists, if not create it
            $this->ensureTableExists();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO vouchmorph_notifications (
                    transaction_reference,
                    sat_number,
                    amount,
                    trace_number,
                    auth_code,
                    atm_id,
                    status,
                    response,
                    error,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $data['reference'] ?? null,
                $data['sat_number'] ?? null,
                $data['amount'] ?? 0,
                $data['trace_number'] ?? null,
                $data['auth_code'] ?? null,
                $data['atm_id'] ?? null,
                $status,
                $response,
                $error
            ]);
        } catch (Exception $e) {
            error_log("VOUCHMORPH: Failed to log notification - " . $e->getMessage());
        }
    }
    
    /**
     * Ensure the notifications table exists
     */
    private function ensureTableExists(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS vouchmorph_notifications (
                    id SERIAL PRIMARY KEY,
                    transaction_reference VARCHAR(100),
                    sat_number VARCHAR(20),
                    amount DECIMAL(15,2),
                    trace_number VARCHAR(50),
                    auth_code VARCHAR(50),
                    atm_id INT,
                    status VARCHAR(20) DEFAULT 'PENDING',
                    response JSON,
                    error TEXT,
                    created_at DATETIME DEFAULT NOW(),
                    updated_at DATETIME,
                    INDEX idx_transaction_ref (transaction_reference),
                    INDEX idx_status (status),
                    INDEX idx_sat_number (sat_number)
                )
            ");
        } catch (Exception $e) {
            error_log("VOUCHMORPH: Failed to create table - " . $e->getMessage());
        }
    }
}
