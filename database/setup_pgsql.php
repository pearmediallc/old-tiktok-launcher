<?php
/**
 * PostgreSQL Database Setup Script for Render.com
 * Run this file once to create all tables in your PostgreSQL database
 *
 * Usage: php database/setup_pgsql.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "===========================================\n";
echo "TikTok Campaign Launcher - PostgreSQL Setup\n";
echo "===========================================\n\n";

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

// Check if DATABASE_URL is provided (Render.com style)
if (!empty($_ENV['DB_URL'])) {
    // Parse the DATABASE_URL
    $dbUrl = parse_url($_ENV['DB_URL']);
    $host = $dbUrl['host'] ?? 'localhost';
    $port = $dbUrl['port'] ?? 5432;  // Default PostgreSQL port
    $dbname = ltrim($dbUrl['path'] ?? '/tiktok_launcher', '/');
    $username = $dbUrl['user'] ?? 'root';
    $password = $dbUrl['pass'] ?? '';
} else {
    // Use individual environment variables
    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $dbname = $_ENV['DB_NAME'] ?? 'tiktok_launcher';
    $username = $_ENV['DB_USER'] ?? 'root';
    $password = $_ENV['DB_PASSWORD'] ?? '';
    $port = $_ENV['DB_PORT'] ?? '5432';
}

echo "Configuration:\n";
echo "- Host: $host\n";
echo "- Port: $port\n";
echo "- Database: $dbname\n";
echo "- User: $username\n\n";

try {
    // Connect to PostgreSQL with SSL
    echo "Step 1: Connecting to PostgreSQL...\n";
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to PostgreSQL server\n\n";

    // Read PostgreSQL schema file
    echo "Step 2: Loading PostgreSQL schema...\n";
    $schemaFile = __DIR__ . '/schema_pgsql.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("PostgreSQL schema file not found: $schemaFile");
    }

    $schema = file_get_contents($schemaFile);
    echo "✓ Schema file loaded\n\n";

    // Execute schema (PostgreSQL can execute multiple statements at once)
    echo "Step 3: Creating tables...\n";
    $pdo->exec($schema);
    echo "✓ Schema executed successfully\n\n";

    // Verify tables were created
    echo "Step 4: Verifying tables...\n";
    $stmt = $pdo->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
        AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($tables) > 0) {
        echo "✓ Tables created:\n";
        foreach ($tables as $table) {
            echo "  - $table\n";
        }
        echo "\n";
    } else {
        echo "⚠️  Warning: No tables found\n\n";
    }

    // Verify tool_portfolios table specifically (most critical for portfolio storage)
    echo "Step 5: Verifying tool_portfolios table...\n";
    $stmt = $pdo->query("
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_name = 'tool_portfolios'
        ORDER BY ordinal_position
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($columns) > 0) {
        echo "✓ tool_portfolios table structure:\n";
        foreach ($columns as $column) {
            echo "  - {$column['column_name']} ({$column['data_type']})\n";
        }
        echo "\n";
    } else {
        echo "✗ tool_portfolios table not found!\n\n";
        exit(1);
    }

    // Check if admin user was created
    echo "Step 6: Checking admin user...\n";
    $adminUsername = $_ENV['AUTH_USERNAME'] ?? 'Sunny';
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE username = ?");
    $stmt->execute([$adminUsername]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo "✓ Admin user exists:\n";
        echo "  - ID: {$user['id']}\n";
        echo "  - Username: {$user['username']}\n";
        echo "  - Email: {$user['email']}\n\n";
    } else {
        echo "⚠️  Admin user not found. Creating...\n";
        $adminPassword = $_ENV['AUTH_PASSWORD'] ?? 'Developer';
        $hashedPassword = password_hash($adminPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, email, full_name, status)
            VALUES (?, ?, ?, ?, 'active')
            ON CONFLICT (username) DO NOTHING
        ");
        $stmt->execute([
            $adminUsername,
            $hashedPassword,
            'admin@tiktok-launcher.local',
            'Administrator'
        ]);
        echo "✓ Admin user created\n";
        echo "  - Username: $adminUsername\n";
        echo "  - Password: $adminPassword\n\n";
    }

    echo "===========================================\n";
    echo "Setup completed successfully!\n";
    echo "===========================================\n\n";

    echo "Next Steps:\n";
    echo "1. ✅ Database is ready for use\n";
    echo "2. ✅ Login at your application URL\n";
    echo "3. ✅ Connect TikTok Ads account via OAuth\n";
    echo "4. ✅ Create portfolios - they will be stored permanently\n\n";

    echo "Testing Portfolio Storage:\n";
    echo "- Click 'Use Frequently Used CTAs' to create a test portfolio\n";
    echo "- Click 'Use Existing Portfolio' to verify it appears in the list\n";
    echo "- All portfolios created will now persist in PostgreSQL\n\n";

} catch (PDOException $e) {
    echo "\n✗ Database Error: " . $e->getMessage() . "\n\n";
    echo "Troubleshooting:\n";
    echo "1. Verify Render PostgreSQL is active\n";
    echo "2. Check credentials in .env file:\n";
    echo "   DB_DRIVER=pgsql\n";
    echo "   DB_HOST=$host\n";
    echo "   DB_PORT=$port\n";
    echo "   DB_NAME=$dbname\n";
    echo "   DB_USER=$username\n";
    echo "   DB_PASSWORD=***\n\n";
    echo "3. Ensure your IP is whitelisted in Render (if applicable)\n";
    echo "4. Check if database exists in Render dashboard\n\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
