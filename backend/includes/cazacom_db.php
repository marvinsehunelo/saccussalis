<?php
// backend/includes/cazacom_db.php

try {
    $cazacom_pdo = new PDO(
        "pgsql:host=localhost;port=5432;dbname=cazacom;user=swap_admin;password=StrongPassword123!"
    );
    // Optional: set default schema if needed
    // $cazacom_pdo->exec("SET search_path TO public");

    $cazacom_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $cazacom_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Cazacom DB connection failed: " . $e->getMessage()
    ]);
    exit;
}
?>

