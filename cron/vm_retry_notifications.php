<?php
// Resends cash-dispensed notifications VouchMorph has not yet confirmed (every 5 minutes via run_expiry.php).
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers/vouchmorph_webhook.php';
echo json_encode(vm_retry_notifications($pdo, 'SACCUSSALIS')) . PHP_EOL;
