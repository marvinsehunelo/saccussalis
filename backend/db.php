<?php
// db.php - PostgreSQL Database connection (PDO)
$host = "localhost";
$port = 5432;                // default PostgreSQL port
$dbname = "saccussalis";     // your database name
$username = "swap_admin";       // change if needed
$password = "StrongPassword!"; // change if needed

try {
    // PostgreSQL DSN
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}
?>

