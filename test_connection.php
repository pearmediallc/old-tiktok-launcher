<?php
// Test database connection
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

echo "Testing database connection...\n\n";

// Show what we're using
if (!empty($_ENV['DB_URL'])) {
    echo "Using DB_URL from .env\n";
    echo "DB_URL: " . $_ENV['DB_URL'] . "\n\n";

    $dbUrl = parse_url($_ENV['DB_URL']);
    $host = $dbUrl['host'] ?? 'none';
    $port = $dbUrl['port'] ?? 5432;  // Default PostgreSQL port
    $dbname = ltrim($dbUrl['path'] ?? '/none', '/');
    $username = $dbUrl['user'] ?? 'none';
    $password = $dbUrl['pass'] ?? 'none';

    echo "Parsed values:\n";
    echo "  Host: $host\n";
    echo "  Port: $port\n";
    echo "  Database: $dbname\n";
    echo "  Username: $username\n";
    echo "  Password: " . (strlen($password) > 0 ? str_repeat('*', strlen($password)) : 'EMPTY') . "\n\n";
} else {
    echo "Using individual env variables\n\n";
    $host = $_ENV['DB_HOST'] ?? 'none';
    $port = $_ENV['DB_PORT'] ?? 'none';
    $dbname = $_ENV['DB_NAME'] ?? 'none';
    $username = $_ENV['DB_USER'] ?? 'none';
    $password = $_ENV['DB_PASSWORD'] ?? 'none';
}

// Try to connect
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
    echo "DSN: $dsn\n";
    echo "Username: $username\n\n";

    echo "Attempting connection...\n";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✓ SUCCESS! Connected to PostgreSQL\n";

    // Test query
    $stmt = $pdo->query("SELECT version()");
    $version = $stmt->fetchColumn();
    echo "✓ PostgreSQL version: $version\n";

} catch (PDOException $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
}
?>
