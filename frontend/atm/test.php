<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/atm/ATMService.php';

echo "===== ATM CASHOUT TEST =====\n\n";

$atmId   = 1;                 // Make sure this ATM exists
$phone   = '26770000000';     // Must match ewallet_pins.recipient_phone
$pin     = '123456';          // Must match stored PIN
$amount  = 100.00;

$atm = new ATMService();

try {

    echo "🔎 STEP 1: Check ATM balance BEFORE\n";

    $stmt = $pdo->prepare("SELECT * FROM atms WHERE id = ?");
    $stmt->execute([$atmId]);
    $atmBefore = $stmt->fetch(PDO::FETCH_ASSOC);

    print_r($atmBefore);


    echo "\n🔎 STEP 2: Check Ledger balances BEFORE\n";

    $stmt = $pdo->query("
        SELECT account_number, balance 
        FROM ledger_accounts 
        WHERE account_number IN ('ATM-001', 'WALLET-CONTROL')
    ");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));


    echo "\n🚀 STEP 3: Execute Cashout\n";

    $result = $atm->cashoutEwallet($atmId, $phone, $pin, $amount);
    print_r($result);


    echo "\n🔎 STEP 4: Check ATM balance AFTER\n";

    $stmt = $pdo->prepare("SELECT * FROM atms WHERE id = ?");
    $stmt->execute([$atmId]);
    $atmAfter = $stmt->fetch(PDO::FETCH_ASSOC);

    print_r($atmAfter);


    echo "\n🔎 STEP 5: Check Ledger balances AFTER\n";

    $stmt = $pdo->query("
        SELECT account_number, balance 
        FROM ledger_accounts 
        WHERE account_number IN ('ATM-001', 'WALLET-CONTROL')
    ");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));


    echo "\n📜 STEP 6: Check Latest Ledger Entry\n";

    $stmt = $pdo->query("
        SELECT * 
        FROM ledger_entries 
        ORDER BY id DESC 
        LIMIT 1
    ");
    print_r($stmt->fetch(PDO::FETCH_ASSOC));


    echo "\n🏧 STEP 7: Check ATM Transactions\n";

    $stmt = $pdo->query("
        SELECT * 
        FROM atm_transactions 
        ORDER BY id DESC 
        LIMIT 1
    ");
    print_r($stmt->fetch(PDO::FETCH_ASSOC));


    echo "\n===== TEST COMPLETE =====\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
