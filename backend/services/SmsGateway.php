<?php
class SmsGateway {

    // Simulate sending SMS (later can integrate real CazaCom API)
    public static function sendSms($sender_id, $recipient_phone, $message) {
        // For simulation, we just log the SMS to database
        global $pdo;
        $stmt = $pdo->prepare("INSERT INTO sms (user_id, sender_number, target_number, message, cost, created_at)
                               VALUES (?, ?, ?, ?, ?, NOW())");

        // Sender phone number
        $stmtSender = $pdo->prepare("SELECT phone FROM wallets WHERE user_id = ? LIMIT 1");
        $stmtSender->execute([$sender_id]);
        $sender = $stmtSender->fetch(PDO::FETCH_ASSOC);
        $sender_number = $sender['phone'] ?? 'SYSTEM';

        $cost = 0; // set cost if charging SMS
        $stmt->execute([$sender_id, $sender_number, $recipient_phone, $message, $cost]);

        return ["status" => "success", "message" => "SMS sent to $recipient_phone"];
    }
}
