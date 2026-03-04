<?php
// db.php - Robust version with error logging but NO EXIT
error_log("Loading db.php");

$host = getenv('PGHOST') ?: 'postgres.railway.internal';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'railway';
$user = getenv('PGUSER') ?: 'postgres';
$pass = getenv('PGPASSWORD') ?: 'jFLFngTLWgRirZVEsoDsyQhTGRtSImjY';

error_log("Connecting to: $host:$port/$dbname as $user");

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    error_log("Database connected successfully");
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $pdo = null; // Set to null instead of exiting
}
?><?php
// db.php - Robust version with error logging
error_log("Loading db.php");

$host = getenv('PGHOST') ?: 'postgres.railway.internal';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'railway';
$user = getenv('PGUSER') ?: 'postgres';
$pass = getenv('PGPASSWORD') ?: 'jFLFngTLWgRirZVEsoDsyQhTGRtSImjY';

error_log("Connecting to: $host:$port/$dbname as $user");

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    error_log("Database connected successfully");
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}
?>

