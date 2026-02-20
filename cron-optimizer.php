<?php
/**
 * Optimizer Cron Script
 * Run every 5 minutes via crontab:
 *   */5 * * * * php /path/to/cron-optimizer.php
 *
 * What it does:
 * 1. Resumes campaigns paused 30+ minutes ago
 * 2. Checks active monitored campaigns against 4 rules
 * 3. Pauses campaigns that violate any rule
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/includes/optimizer-functions.php';
require_once __DIR__ . '/database/Database.php';

// Load environment
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

// Get access token
$accessToken = getenv('TIKTOK_ACCESS_TOKEN') ?: ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? '');

if (empty($accessToken)) {
    // Try to get from database (most recent active connection)
    try {
        $db = Database::getInstance();
        $conn = $db->fetchOne(
            "SELECT access_token FROM tiktok_connections WHERE connection_status = 'active' ORDER BY updated_at DESC LIMIT 1"
        );
        if ($conn) {
            $accessToken = $conn['access_token'];
        }
    } catch (Exception $e) {
        echo "Error getting access token from DB: " . $e->getMessage() . "\n";
    }
}

if (empty($accessToken)) {
    echo "No access token available. Set TIKTOK_ACCESS_TOKEN in .env or connect via the app.\n";
    logOptimizer("Cron aborted: no access token");
    exit(1);
}

$db = Database::getInstance();

echo "[" . date('Y-m-d H:i:s') . "] Running optimizer check...\n";

$stats = runOptimizerCheck($db, $accessToken);

echo "Results: Checked {$stats['checked']}, Paused {$stats['paused']}, Resumed {$stats['resumed']}\n";
