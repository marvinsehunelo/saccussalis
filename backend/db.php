<?php
// backend/db.php

error_log("Loading db.php");

$host = getenv('PGHOST') ?: 'postgres.railway.internal';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'railway';
$user = getenv('PGUSER') ?: 'postgres';
$pass = getenv('PGPASSWORD') ?: 'jFLFngTLWgRirZVEsoDsyQhTGRtSImjY';

$pdo = null;

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
    // DO NOT echo
    // DO NOT exit
    // Just leave $pdo as null
}
