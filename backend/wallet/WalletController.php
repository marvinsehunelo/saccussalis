<?php
// backend/wallet/WalletController.php
// Core wallet logic: generate PIN, redeem PIN, reissue PIN, check balance

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php'; // $db (PDO)
require_once __DIR__ . '/../integrations/cazacom_gateway.php';

class WalletController {
    private $db;
    private $pin_validity_hours = 24;    // default PIN validity
    private $pin_reissue_fee = 5.00;     // default reissue fee, change as required

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // helper: fetch wallet by user_id
    public function getWalletByUserId(int $user_id) {
        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Check balance (returns array)
    public function checkBalance(int $user_id) {
        $wallet = $this->getWalletByUserId($user_id);
        if (!$wallet) return ['status'=>'error','message'=>'Wallet not found'];
        return ['status'=>'success','balance'=> number_format((float)$wallet['balance'], 2, '.', '')];
    }

    // Generate ATM PIN (send money): deduct sender, create transaction + pin
    public function generateAtmPin(int $sender_id, string $recipient_phone, float $amount) {
        $this->db->beginTransaction();
        try {
            $wallet = $this->getWalletByUserId($sender_id);
            if (!$wallet) throw new Exception('Sender wallet not found');

            if ((float)$wallet['balance'] < (float)$amount) {
                throw new Exception('Insufficient balance');
            }

            // Deduct from sender wallet immediately
            $stmt = $this->db->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?");
            $stmt->execute([$amount, $sender_id]);

            // Create transactions record
            $from_account = $wallet['wallet_id'];
            $stmt = $this->db->prepare("
                INSERT INTO transactions (user_id, from_account, to_account, amount, type, status, fee_amount, created_at)
                VALUES (?, ?, ?, ?, 'wallet_send', 'pending', 0.00, NOW())
            ");
            $stmt->execute([$sender_id, $from_account, $recipient_phone, $amount]);
            $transaction_id = (int)$this->db->lastInsertId();

            // Generate PIN and insert into ewallet_pins
            $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$this->pin_validity_hours} hours"));

            $stmt = $this->db->prepare("
                INSERT INTO ewallet_pins (transaction_id, pin, is_redeemed, created_at, expires_at, reissue_fee)
                VALUES (?, ?, 0, NOW(), ?, 0)
            ");
            $stmt->execute([$transaction_id, $pin, $expires_at]);

            // Log wallet_transaction
            $stmt = $this->db->prepare("
                INSERT INTO wallet_transactions (user_id, recipient_identifier, transaction_type, amount, status, created_at)
                VALUES (?, ?, 'wallet_send', ?, 'pending', NOW())
            ");
            $stmt->execute([$sender_id, $recipient_phone, $amount]);

            $this->db->commit();

            return [
                'status'=>'success',
                'transaction_id'=>$transaction_id,
                'pin'=>$pin,
                'expires_at'=>$expires_at,
                'amount'=>number_format((float)$amount, 2, '.', '')
            ];
        } catch (Exception $ex) {
            $this->db->rollBack();
            return ['status'=>'error','message'=>$ex->getMessage()];
        }
    }

    // Redeem PIN at ATM (recipient provides phone & pin)
    public function redeemAtmPin(string $recipient_phone, string $pin) {
        $this->db->beginTransaction();
        try {
            // Find matching pin record (unredeemed & not expired)
            $stmt = $this->db->prepare("
                SELECT ep.*, t.amount, t.transaction_id, t.user_id AS sender_id
                FROM ewallet_pins ep
                JOIN transactions t ON t.transaction_id = ep.transaction_id
                WHERE ep.pin = ? AND ep.is_redeemed = 0 AND ep.expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$pin]);
            $pinData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pinData) throw new Exception('Invalid or expired PIN');

            // Optional: validate recipient matches t.to_account (phone). Some implementations allow PIN and phone; check business rule:
            if (isset($pinData['transaction_id'])) {
                // fetch transaction to check to_account
                $stmt2 = $this->db->prepare("SELECT to_account FROM transactions WHERE transaction_id = ?");
                $stmt2->execute([$pinData['transaction_id']]);
                $tx = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($tx && $tx['to_account'] !== $recipient_phone) {
                    // Allow if your business wants any recipient with PIN to withdraw. If you require matching phone, enforce here.
                    // We'll enforce matching phone to reduce fraud:
                    throw new Exception('PIN does not match recipient phone');
                }
            }

            $amount = (float)$pinData['amount'];
            $transaction_id = (int)$pinData['transaction_id'];
            $pin_id = (int)$pinData['id'];

            // Find recipient user by phone (if the recipient is a registered user, credit their wallet; else ATM cash out means funds left already deducted from sender)
            $stmt = $this->db->prepare("SELECT user_id FROM users WHERE phone = ? LIMIT 1");
            $stmt->execute([$recipient_phone]);
            $recipientUser = $stmt->fetch(PDO::FETCH_ASSOC);

            // Mark pin redeemed
            $stmt = $this->db->prepare("UPDATE ewallet_pins SET is_redeemed = 1, redeemed_at = NOW() WHERE id = ?");
            $stmt->execute([$pin_id]);

            // Update transaction status -> completed, and type atm_withdraw
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'completed', type = 'atm_withdraw' WHERE transaction_id = ?");
            $stmt->execute([$transaction_id]);

            // Update wallet_transactions to completed (sender side)
            $stmt = $this->db->prepare("
                UPDATE wallet_transactions
                SET status = 'completed', updated_at = NOW()
                WHERE user_id = ? AND recipient_identifier = ? AND amount = ? AND transaction_type = 'wallet_send'
                LIMIT 1
            ");
            $stmt->execute([$pinData['sender_id'], $recipient_phone, $amount]);

            // If the recipient is a registered wallet user, credit their wallet (if business model requires). Many cardless flows do not double-credit.
            if ($recipientUser && isset($recipientUser['user_id'])) {
                $recipient_id = (int)$recipientUser['user_id'];
                $stmt = $this->db->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?");
                $stmt->execute([$amount, $recipient_id]);

                // Log recipient wallet transaction
                $stmt = $this->db->prepare("
                    INSERT INTO wallet_transactions (user_id, recipient_identifier, transaction_type, amount, status, created_at)
                    VALUES (?, ?, 'wallet_receive', ?, 'completed', NOW())
                ");
                $stmt->execute([$recipient_id, $recipient_phone, $amount]);
            }

            $this->db->commit();

            return ['status'=>'success','amount'=>number_format($amount,2,'.',''),'message'=>'PIN redeemed. Dispense cash at ATM.'];
        } catch (Exception $ex) {
            $this->db->rollBack();
            return ['status'=>'error','message'=>$ex->getMessage()];
        }
    }

    // Reissue PIN (recipient pays fee). old_pin_id is the ewallet_pins.id of the original.
    public function reissuePin(int $old_pin_id, int $recipient_user_id) {
        $this->db->beginTransaction();
        try {
            // fetch old pin + transaction + amount
            $stmt = $this->db->prepare("
                SELECT ep.*, t.amount, t.transaction_id, t.user_id AS sender_id, t.to_account
                FROM ewallet_pins ep
                JOIN transactions t ON t.transaction_id = ep.transaction_id
                WHERE ep.id = ?
                LIMIT 1
            ");
            $stmt->execute([$old_pin_id]);
            $pinRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pinRow) throw new Exception('Original PIN not found');

            if ((int)$pinRow['is_redeemed'] === 1) throw new Exception('Original PIN already redeemed');

            // check expired
            if (new DateTime($pinRow['expires_at']) > new DateTime()) {
                throw new Exception('Original PIN not expired yet; cannot reissue');
            }

            // check recipient wallet for fee
            $stmt = $this->db->prepare("SELECT * FROM wallets WHERE user_id = ?");
            $stmt->execute([$recipient_user_id]);
            $recipientWallet = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$recipientWallet) throw new Exception('Recipient wallet not found');

            if ((float)$recipientWallet['balance'] < (float)$this->pin_reissue_fee) {
                throw new Exception('Insufficient balance to pay reissue fee');
            }

            // Deduct fee from recipient wallet
            $stmt = $this->db->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?");
            $stmt->execute([$this->pin_reissue_fee, $recipient_user_id]);

            // Log fee transaction
            $stmt = $this->db->prepare("
                INSERT INTO wallet_transactions (user_id, recipient_identifier, transaction_type, amount, status, created_at)
                VALUES (?, ?, 'pin_reissue_fee', ?, 'completed', NOW())
            ");
            $stmt->execute([$recipient_user_id, $recipientWallet['phone'], $this->pin_reissue_fee]);

            // Create new PIN record (duplicate transaction_id)
            $new_pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$this->pin_validity_hours} hours"));
            $stmt = $this->db->prepare("
                INSERT INTO ewallet_pins (transaction_id, pin, is_redeemed, created_at, expires_at, reissue_fee)
                SELECT transaction_id, ?, 0, NOW(), ?, ?
                FROM ewallet_pins WHERE id = ?
            ");
            $stmt->execute([$new_pin, $expires_at, $this->pin_reissue_fee, $old_pin_id]);

            $this->db->commit();

            // Optionally notify recipient via CazaCom (SMS)
            $message = "New withdrawal PIN: {$new_pin}. Expires: {$expires_at}. Fee: " . number_format($this->pin_reissue_fee,2);
            if (!empty($recipientWallet['phone'])) {
                sendSmsViaCazacom($recipientWallet['phone'], $message);
            }

            return ['status'=>'success','new_pin'=>$new_pin,'expires_at'=>$expires_at,'fee'=>number_format($this->pin_reissue_fee,2,'.','')];
        } catch (Exception $ex) {
            $this->db->rollBack();
            return ['status'=>'error','message'=>$ex->getMessage()];
        }
    }
}
