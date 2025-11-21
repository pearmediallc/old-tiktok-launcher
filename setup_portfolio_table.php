<?php
/**
 * Quick setup script to create just the tool_portfolios table
 * Run this if you're getting "Database connection failed" errors
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "===========================================\n";
echo "Portfolio Table Setup\n";
echo "===========================================\n\n";

// Load environment variables
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

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'tiktok_launcher';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';

echo "Configuration:\n";
echo "- Host: $host\n";
echo "- Database: $dbname\n";
echo "- User: $username\n\n";

try {
    // Step 1: Connect to MySQL (without selecting database)
    echo "Step 1: Connecting to MySQL...\n";
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to MySQL server\n\n";

    // Step 2: Create database if it doesn't exist
    echo "Step 2: Creating database '$dbname'...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✓ Database created/verified\n\n";

    // Step 3: Select the database
    echo "Step 3: Selecting database...\n";
    $pdo->exec("USE `$dbname`");
    echo "✓ Database selected\n\n";

    // Step 4: Create tool_portfolios table
    echo "Step 4: Creating tool_portfolios table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS tool_portfolios (
        id INT PRIMARY KEY AUTO_INCREMENT,
        advertiser_id VARCHAR(255) NOT NULL COMMENT 'TikTok Advertiser ID',
        creative_portfolio_id VARCHAR(255) NOT NULL COMMENT 'Portfolio ID from TikTok API',
        portfolio_name VARCHAR(500) COMMENT 'Name of the portfolio',
        portfolio_type VARCHAR(50) DEFAULT 'CTA' COMMENT 'Portfolio type (CTA, etc)',
        portfolio_content JSON COMMENT 'Full portfolio content data',
        created_by_tool BOOLEAN DEFAULT TRUE COMMENT 'Whether this was created by the launcher',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_portfolio (advertiser_id, creative_portfolio_id),
        INDEX idx_advertiser_id (advertiser_id),
        INDEX idx_portfolio_id (creative_portfolio_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Stores all portfolios created through TikTok Launcher for easy retrieval'";

    $pdo->exec($sql);
    echo "✓ tool_portfolios table created\n\n";

    // Step 5: Verify table exists
    echo "Step 5: Verifying table...\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'tool_portfolios'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Table verified successfully\n\n";

        // Show table structure
        echo "Table Structure:\n";
        $stmt = $pdo->query("DESCRIBE tool_portfolios");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  - {$row['Field']} ({$row['Type']})\n";
        }
        echo "\n";
    } else {
        echo "✗ Table verification failed\n\n";
        exit(1);
    }

    echo "===========================================\n";
    echo "Setup completed successfully!\n";
    echo "===========================================\n\n";

    echo "You can now:\n";
    echo "1. Click 'Use Frequently Used CTAs' to create a portfolio\n";
    echo "2. Click 'Use Existing Portfolio' to see your portfolios\n";
    echo "3. Portfolios will be permanently stored in the database\n\n";

} catch (PDOException $e) {
    echo "\n✗ Database Error: " . $e->getMessage() . "\n\n";
    echo "Troubleshooting:\n";
    echo "1. Check if MySQL/MariaDB is running\n";
    echo "2. Verify credentials in .env file:\n";
    echo "   DB_HOST=$host\n";
    echo "   DB_USER=$username\n";
    echo "   DB_PASSWORD=" . (empty($password) ? '(empty)' : '***') . "\n";
    echo "3. Make sure user has permission to create databases\n\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
