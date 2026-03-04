<?php
// db.php - PostgreSQL Database connection (PDO) for Railway

// Use Railway environment variables with fallbacks for local development
$host = getenv('PGHOST') ?: 'localhost';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'saccussalis';
$username = getenv('PGUSER') ?: 'swap_admin';
$password = getenv('PGPASSWORD') ?: 'StrongPassword!';

// Optional: Log connection attempt (remove in production)
error_log("Connecting to database: host=$host, port=$port, dbname=$dbname, user=$username");

try {
    // PostgreSQL DSN
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    // Optional: Test the connection
    $pdo->query("SELECT 1");
    
} catch (PDOException $e) {
    // Log the error but don't expose details to client in production
    error_log("Database connection failed: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "error" => "Database connection failed",
        "message" => "Unable to connect to database. Please try again later."
    ]);
    exit;
}
?>
