<?php
// backend/includes/cazacom_db.php - Using CazaCom's pattern

class CazacomDatabase {
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Try Cazacom-specific URL first, fall back to main DATABASE_URL
        $database_url = getenv('CAZACOM_DATABASE_URL') ?: getenv('DATABASE_URL');
        
        if ($database_url) {
            // Parse Railway's DATABASE_URL
            $db = parse_url($database_url);
            
            $this->host = $db['host'] ?? 'localhost';
            $this->port = $db['port'] ?? '5432';
            $this->db_name = ltrim($db['path'] ?? '/cazacom', '/');
            $this->username = $db['user'] ?? 'postgres';
            $this->password = $db['pass'] ?? '';
        } else {
            // Fallback for local development
            $this->host = getenv('CAZACOM_HOST') ?: 'localhost';
            $this->port = getenv('CAZACOM_PORT') ?: '5432';
            $this->db_name = getenv('CAZACOM_DB') ?: 'cazacom';
            $this->username = getenv('CAZACOM_USER') ?: 'swap_admin';
            $this->password = getenv('CAZACOM_PASS') ?: 'StrongPassword123!';
        }
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->db_name}";
            
            error_log("Connecting to Cazacom DB: host={$this->host}, port={$this->port}, dbname={$this->db_name}, user={$this->username}");
            
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]);
            
            error_log("Cazacom DB connection successful");
            
        } catch (PDOException $exception) {
            error_log("Cazacom DB connection failed: " . $exception->getMessage());
            
            // Don't exit - let calling code handle null connection
            return null;
        }

        return $this->conn;
    }
}

// For backward compatibility with existing code
$cazacom_db = new CazacomDatabase();
$cazacom_pdo = $cazacom_db->getConnection();

// If this file is run directly for testing
if (php_sapi_name() === 'cli' && !defined('CONSOLE_MODE')) {
    define('CONSOLE_MODE', true);
    if ($cazacom_pdo) {
        echo "✅ Cazacom DB connection successful!\n";
    } else {
        echo "❌ Cazacom DB connection failed\n";
    }
}
?>
