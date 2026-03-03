<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once("../db.php");

if (isset($_SESSION['authToken'])) {
    $token = $_SESSION['authToken'];

    $stmt = $pdo->prepare("DELETE FROM sessions WHERE token = ?");
    $stmt->execute([$token]);
}

// Destroy PHP session
session_unset();
session_destroy();

// Redirect to login
header("Location: ../../frontend/public/login.php");
exit;
?>

