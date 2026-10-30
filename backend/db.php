<?php
// backend/db.php

error_log("Loading db.php");

// Connection details come only from the environment: the PG* variables (what this service has
// always used), or DATABASE_URL when PGPASSWORD is not set. There is no password in the code.
$host = getenv('PGHOST') ?: 'postgres.railway.internal';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'railway';
$user = getenv('PGUSER') ?: 'postgres';
$pass = getenv('PGPASSWORD') ?: '';
$databaseUrl = getenv('DATABASE_URL');
if ($pass === '' && $databaseUrl) {
    $parts = parse_url($databaseUrl);
    $host = $parts['host'] ?? $host;
    $port = (string)($parts['port'] ?? $port);
    $dbname = ltrim($parts['path'] ?? '/' . $dbname, '/');
    $user = isset($parts['user']) ? rawurldecode($parts['user']) : $user;
    $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
}

$pdo = null;

try {
    if ($pass === '') {
        throw new PDOException('no database password configured (set DATABASE_URL or PGPASSWORD)');
    }
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);

    error_log("Database connected successfully");

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    // DO NOT echo
    // DO NOT exit
    // Just leave $pdo as null
}
