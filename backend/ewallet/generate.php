<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . "/../db.php");
header("Content-Type: application/json");

try {
    // Mock logic — later we’ll add PIN generation
    $pin = rand(100000, 999999);
    echo json_encode([
        "status" => "success",
        "pin" => $pin,
        "expires_in_minutes" => 15
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
