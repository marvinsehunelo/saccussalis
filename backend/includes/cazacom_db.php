<?php
// backend/includes/cazacom_db.php - Railway compatible version

// Initialize as null
$cazacom_pdo = null;

// Try Cazacom-specific database URL first, fall back to main DATABASE_URL
$database_url = getenv('CAZACOM_DATABASE_URL') ?: getenv('DATABASE_URL');

if ($database_url) {
    // Parse Railway DATABASE_URL
    $parts = parse_url($database_url);
    $host = $parts['host'] ?? null;
    $port = $parts['port'] ?? 5432;
    $user = $parts['user'] ?? null;
    $pass = $parts['pass'] ?? null;
    $dbname = ltrim($parts['path'] ?? '', '/');
    
    error_log("Cazacom DB: Attempting connection to $host:$port/$dbname as $user");
    
    try {
        $cazacom_pdo = new PDO(
            "pgsql:host=$host;port=$port;dbname=$dbname",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]
        );
        error_log("Cazacom DB: Connection successful");
    } catch (PDOException $e) {
        error_log("Cazacom DB: Connection failed - " . $e->getMessage());
        // Don't echo or exit - just let $cazacom_pdo remain null
    }
} else {
    error_log("Cazacom DB: No DATABASE_URL found");
}

// If this file is run directly for testing
if (php_sapi_name() === 'cli' && !isset($argv[0]) && !defined('PHPUNIT_RUNNING')) {
    if ($cazacom_pdo) {
        echo "✅ Cazacom DB connected successfully\n";
    } else {
        echo "❌ Cazacom DB connection failed\n";
    }
}
?>
