<?php
echo "=== PHP Extension Check ===\n\n";

echo "PDO Drivers:\n";
print_r(PDO::getAvailableDrivers());

echo "\n\nPostgreSQL Extensions:\n";
echo "pdo_pgsql: " . (extension_loaded('pdo_pgsql') ? '✅ LOADED' : '❌ NOT LOADED') . "\n";
echo "pgsql: " . (extension_loaded('pgsql') ? '✅ LOADED' : '❌ NOT LOADED') . "\n";

echo "\nAll Loaded Extensions (PostgreSQL related):\n";
$all = get_loaded_extensions();
$pgsql_exts = array_filter($all, function($ext) {
    return strpos($ext, 'pgsql') !== false || strpos($ext, 'pdo') !== false;
});
print_r($pgsql_exts);

echo "\n\nEnvironment:\n";
echo "PGHOST: " . (getenv('PGHOST') ?: 'not set') . "\n";
echo "PGDATABASE: " . (getenv('PGDATABASE') ?: 'not set') . "\n";
?>
