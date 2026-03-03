<?php
require_once '../../../db.php';                     // load $pdo
require_once '../../../network/MessageRouter.php';
require_once '../../../middleware/Idempotency.php';

header('Content-Type: application/json');

// Decode incoming JSON
$input = json_decode(file_get_contents("php://input"), true);

// Idempotency check
if (isset($input['request_id'])) {
    Idempotency::check($input['request_id']);
}

// Instantiate router with $pdo
$router = new MessageRouter($pdo);

// Route the message
$response = $router->route($input);

// Store response for idempotency (if request_id provided)
if (isset($input['request_id'])) {
    Idempotency::store($input['request_id'], $response);
}

// Return JSON response
echo json_encode($response);
