<?php
/**
 * /backend/health.php
 * Lightweight liveness check for VouchMorph reachability monitoring.
 * Intentionally does not touch the database — a slow DB should not
 * flip this to unhealthy. No auth: this is meant to be pinged freely.
 */

require_once __DIR__ . '/network/untils/Response.php';

header("Content-Type: application/json");
http_response_code(200);

echo json_encode(iso_response(200, "ok", ["service" => "saccussalis"]));
