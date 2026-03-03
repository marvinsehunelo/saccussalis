<?php
class EwalletService {
    private $db;
    private $expiryMinutes;
    private $regenerationFee;

    public function __construct($db) {
        $this->db = $db;

        // Load settings from DB
        $stmt = $db->query("SELECT expiry_minutes, regeneration_fee FROM ewallet_settings LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->expiryMinutes = $config['expiry_minutes'] ?? 1440;
        $this->regenerationFee = $config['regeneration_fee'] ?? 2.50;
    }

    // Generate a new 6-digit PIN
    public function generatePin($transaction_id) {
        $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$this->expiryMinutes} minutes"));

        $stmt = $this->db->prepare("
            INSERT INTO ewallet_pins (transaction_id, pin, expires_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$transaction_id, $pin, $expires_at]);

        return ['pin' => $pin, 'expires_at' => $expires_at];
    }

    // Check PIN validity
    public function validatePin($pin) {
        $stmt = $this->db->prepare("
            SELECT * FROM ewallet_pins
            WHERE pin = ? AND is_redeemed = 0
        ");
        $stmt->execute([$pin]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            return ['status' => 'error', 'message' => 'Invalid or already used PIN'];
        }

        if (strtotime($data['expires_at']) < time()) {
            return ['status' => 'expired', 'message' => 'PIN expired'];
        }

        return ['status' => 'valid', 'data' => $data];
    }

    // Redeem PIN (ATM withdrawal)
    public function redeemPin($pin) {
        $check = $this->validatePin($pin);
        if ($check['status'] !== 'valid') return $check;

        $this->db->prepare("UPDATE ewallet_pins SET is_redeemed = 1 WHERE pin = ?")->execute([$pin]);
        return ['status' => 'success', 'message' => 'PIN redeemed successfully'];
    }

    // Regenerate expired PIN (by sender or receiver)
    public function regeneratePin($old_pin, $by_user, $user_id) {
        $stmt = $this->db->prepare("SELECT * FROM ewallet_pins WHERE pin = ?");
        $stmt->execute([$old_pin]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$old) return ['status' => 'error', 'message' => 'Old PIN not found'];
        if (strtotime($old['expires_at']) > time()) return ['status' => 'error', 'message' => 'PIN not expired yet'];

        // Generate new
        $newPin = $this->generatePin($old['transaction_id']);

        // Mark regenerated
        $update = $this->db->prepare("
            UPDATE ewallet_pins 
            SET regenerated_by = ?, regeneration_fee = ?, expires_at = ?, pin = ?
            WHERE id = ?
        ");
        $update->execute([$by_user, $this->regenerationFee, $newPin['expires_at'], $newPin['pin'], $old['id']]);

        // Deduct regeneration fee from wallet
        $this->deductFee($user_id, $this->regenerationFee);

        return ['status' => 'success', 'new_pin' => $newPin['pin']];
    }

    private function deductFee($user_id, $amount) {
        $stmt = $this->db->prepare("
            UPDATE wallets SET balance = balance - ? WHERE user_id = ?
        ");
        $stmt->execute([$amount, $user_id]);
    }
}
?>
