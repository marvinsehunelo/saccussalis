<?php
require_once __DIR__ . "/../../backend/db.php";
require_once "EwalletService.php";

class EwalletController {
    private $db;
    private $service;

    public function __construct($db) {
        $this->db = $db;
        $this->service = new EwalletService($db);
    }

    // Generate a new PIN (sender initiates)
    public function generate($transaction_id) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $user_id = $_SESSION['user_id'] ?? null;
        if (!$user_id) return ["status" => "error", "message" => "User not logged in"];

        return $this->service->generatePin($transaction_id);
    }

    // Redeem PIN at ATM
    public function redeem($pin) {
        return $this->service->redeemPin($pin);
    }

    // Regenerate expired PIN
    public function regenerate($old_pin) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $user_id = $_SESSION['user_id'] ?? null;
        if (!$user_id) return ["status" => "error", "message" => "User not logged in"];

        return $this->service->regeneratePin($old_pin, 'recipient', $user_id);
    }
}
?>
