<?php
// backend/api/v1/access_points.php
//
// GET /backend/api/v1/access_points.php
//
// The bank's ATMs and branches that are in service, for VouchMorph's access point locator:
// where they are, when they are open, what they offer and whether an ATM has cash (yes / no /
// not reported, never the amount). Public information, so there is no key; VouchMorph fetches
// it on a schedule and caches it. Read-only, and the query string is ignored.
//
//   200  { "institution": "SACCUSSALIS", "updated_at": "<UTC time read>", "access_points": [...] }
//        An empty list is a valid answer.
//   405  { "error": "..." }  any method other than GET
//   503  { "error": "..." }  the database could not be read (the reason is logged, not sent)
//
// The mapping from rows to the response, and the tables it reads, are in
// backend/helpers/access_points.php.

ini_set('display_errors', '0');
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers/access_points.php';

try {
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('no database connection');
    }

    ap_ensure_schema($pdo);
    [$atmRows, $branchRows] = ap_read_rows($pdo);

    $body = json_encode(
        ap_response($atmRows, $branchRows, time()),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    error_log('SACCUSSALIS access_points: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(503);
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Access points are temporarily unavailable. Try again later.']);
    exit;
}

header('Cache-Control: public, max-age=300');
echo $body;
