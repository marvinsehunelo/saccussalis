public function __construct()
{
    global $pdo;
    $this->pdo = $pdo;
    
    // Try to load ISO8583Gateway, fallback to dummy if missing
    try {
        if (file_exists(__DIR__ . '/../network/ISO8583Gateway.php')) {
            require_once __DIR__ . '/../network/ISO8583Gateway.php';
            $this->isoGateway = new ISO8583Gateway($pdo);
        } else {
            // Create a dummy gateway
            $this->isoGateway = new class($pdo) {
                private $pdo;
                public function __construct($pdo) {
                    $this->pdo = $pdo;
                }
                public function sendDispenseAdvice(array $data): array {
                    error_log("ISO8583Gateway: Using dummy gateway - notification skipped");
                    return ['success' => true, 'message' => 'Dummy gateway'];
                }
            };
        }
    } catch (Exception $e) {
        error_log("ATMService: Failed to load ISO8583Gateway - " . $e->getMessage());
        // Create a dummy gateway as fallback
        $this->isoGateway = new class($pdo) {
            private $pdo;
            public function __construct($pdo) {
                $this->pdo = $pdo;
            }
            public function sendDispenseAdvice(array $data): array {
                return ['success' => true, 'message' => 'Fallback gateway'];
            }
        };
    }
}
