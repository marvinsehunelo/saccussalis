<?php
require_once __DIR__ . '/../db.php';

/*
This prevents duplicate transaction processing.
Every network message must contain request_id (STAN / RRN equivalent)
If same request arrives again → return previous response
*/

class Idempotency
{
    public static function check($request_id)
    {
        global $pdo;

        $stmt = $pdo->prepare("
            SELECT response
            FROM network_request_log
            WHERE request_id = ?
        ");
        $stmt->execute([$request_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // return previous response (network retry)
            header('Content-Type: application/json');
            echo $row['response'];
            exit;
        }
    }

    public static function store($request_id, $response)
    {
        global $pdo;

        $stmt = $pdo->prepare("
            INSERT INTO network_request_log (request_id, response)
            VALUES (?, ?)
        ");

        $stmt->execute([
            $request_id,
            json_encode($response)
        ]);
    }
}
