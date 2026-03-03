<?php

class ExternalPartnerGateway {

    private $pdo;

    public function __construct($pdo){
        $this->pdo = $pdo;
    }

    public function authorize($partnerName, $payload)
    {
        // 1️⃣ Load partner configuration from DB
        $stmt = $this->pdo->prepare("
            SELECT api_endpoint, api_key
            FROM external_partners
            WHERE name = ?
        ");
        $stmt->execute([$partnerName]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$partner) {
            return [
                'status'  => 'ERROR',
                'message' => 'Unknown external partner'
            ];
        }

        // 2️⃣ Send secure request
        $ch = curl_init($partner['api_endpoint']);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-KEY: ' . $partner['api_key']
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            return [
                'status'  => 'ERROR',
                'message' => 'External network unreachable'
            ];
        }

        curl_close($ch);

        $decoded = json_decode($response, true);

        if (!$decoded) {
            return [
                'status'  => 'ERROR',
                'message' => 'Invalid response from external partner'
            ];
        }

        return $decoded;
    }
}
