<?php
/**
 * Database Setup Script
 * Run this file once to create the database and tables
 *
 * Usage: php database/setup.php
 */

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'tiktok_launcher';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';

echo "===========================================\n";
echo "TikTok Campaign Launcher - Database Setup\n";
echo "===========================================\n\n";

echo "Configuration:\n";
echo "- Host: $host\n";
echo "- Database: $dbname\n";
echo "- User: $username\n\n";

try {
    // Connect without selecting a database
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✓ Connected to MySQL server\n";

    // Create database if it doesn't exist
    echo "Creating database '$dbname'...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✓ Database created\n\n";

    // Select the database
    $pdo->exec("USE `$dbname`");

    // Read schema file
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found: $schemaFile");
    }

    $schema = file_get_contents($schemaFile);

    // Execute schema
    echo "Running schema.sql...\n";
    $pdo->exec($schema);
    echo "✓ Schema executed successfully\n\n";

    // Create default admin user
    echo "Creating default admin user...\n";

    // Get credentials from .env
    $adminUsername = $_ENV['AUTH_USERNAME'] ?? 'Sunny';
    $adminPassword = $_ENV['AUTH_PASSWORD'] ?? 'Developer';

    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$adminUsername]);

    if ($stmt->fetch()) {
        echo "✓ Admin user '$adminUsername' already exists\n";
    } else {
        $hashedPassword = password_hash($adminPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, email, full_name, status)
            VALUES (?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $adminUsername,
            $hashedPassword,
            'admin@tiktok-launcher.local',
            'Administrator'
        ]);
        echo "✓ Admin user created (ID: 1)\n";
        echo "  Username: $adminUsername\n";
        echo "  Password: $adminPassword\n";
    }

    echo "\n===========================================\n";
    echo "Database setup completed successfully!\n";
    echo "===========================================\n\n";

    // Show tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "Created tables:\n";
    foreach ($tables as $table) {
        echo "  - $table\n";
    }

    echo "\nYou can now:\n";
    echo "1. Login at http://localhost:8080\n";
    echo "2. Connect your TikTok Ads account via OAuth\n";
    echo "3. Start creating campaigns!\n\n";

    echo "Next steps:\n";
    echo "- Register OAuth redirect URI in TikTok Developer Portal\n";
    echo "- Implement token refresh mechanism\n";
    echo "- Set up campaign data sync\n";

} catch (PDOException $e) {
    echo "\n✗ Database Error: " . $e->getMessage() . "\n";
    echo "\nPlease check:\n";
    echo "1. MySQL/MariaDB is running\n";
    echo "2. Database credentials in .env are correct\n";
    echo "3. User has permission to create databases\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
