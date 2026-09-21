<?php
// saccussalis/backend/api/submit_transfer.php
//
// Retired. It debited the customer before calling the central bank, sent
// the request unsigned to localhost, and never refunded on failure. The
// dashboard uses backend/transactions/external_transfer.php.
header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Retired. Use backend/transactions/external_transfer.php.']);
