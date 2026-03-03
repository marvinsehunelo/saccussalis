<?php
require_once __DIR__ . "/../db.php";

$testPin = "253353"; // REPLACE WITH YOUR TEST PIN
$testPhone = "+26770000000"; // REPLACE WITH YOUR TEST PHONE

echo "--- DATABASE DIAGNOSTIC ---\n";

// TEST A: Does the PIN even exist?
$stmt = $pdo->prepare("SELECT * FROM ewallet_pins WHERE pin = ?");
$stmt->execute([$testPin]);
$pinRow = $stmt->fetch();
echo ($pinRow) ? "✅ PIN found in ewallet_pins.\n" : "❌ PIN NOT FOUND. Check for spaces or hashing.\n";

// TEST B: Does the Transaction link work?
if ($pinRow) {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_id = ?");
    $stmt->execute([$pinRow['transaction_id']]);
    echo ($stmt->fetch()) ? "✅ Linked Transaction found.\n" : "❌ TRANSACTION LINK BROKEN. pin.transaction_id does not exist in transactions table.\n";
}

// TEST C: The Phone Number Format
$stmt = $pdo->prepare("SELECT phone FROM wallets WHERE phone = ?");
$stmt->execute([$testPhone]);
if ($stmt->fetch()) {
    echo "✅ Phone matches exactly: $testPhone\n";
} else {
    $stmt = $pdo->prepare("SELECT phone FROM wallets LIMIT 1");
    $stmt->execute();
    $sample = $stmt->fetch();
    echo "❌ PHONE MISMATCH. You sent [$testPhone], but DB sample looks like [".$sample['phone']."]\n";
}
