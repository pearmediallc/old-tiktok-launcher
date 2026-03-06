<?php
/**
 * Optimizer Cron Script
 * Run every 5 minutes via crontab:
 *   every 5 minutes: crontab -e → add: 0/5 * * * * php /path/to/cron-optimizer.php
 *
 * What it does:
 * 1. Sends Slack review notification for campaigns paused 30+ min ago (with metrics + Resume button)
 * 2. Checks active monitored campaigns against rules (CPC, CTR, LP CTR, etc.)
 * 3. Pauses campaigns that violate any rule
 * Note: Campaigns are NOT auto-resumed. User must click Resume in Slack.
 */

// Allow CLI or HTTP with CRON_SECRET authentication
if (php_sapi_name() !== 'cli') {
    $cronSecret = getenv('CRON_SECRET') ?: ($_ENV['CRON_SECRET'] ?? '');
    $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';

    if (empty($cronSecret) || !hash_equals($cronSecret, $providedKey)) {
        http_response_code(403);
        echo "Unauthorized\n";
        exit(1);
    }
}

require_once __DIR__ . '/includes/optimizer-functions.php';
require_once __DIR__ . '/database/Database.php';
require_once __DIR__ . '/includes/ActivityLogger.php';

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

// Cron locking: prevent concurrent runs
$lockFile = __DIR__ . '/cache/optimizer_cron.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) mkdir($lockDir, 0755, true);

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 240) { // Lock is less than 4 minutes old — another run is active
        echo "Another optimizer run is still active (locked {$lockAge}s ago). Skipping.\n";
        logOptimizer("Cron skipped: lock file exists ({$lockAge}s old)");
        exit(0);
    }
    // Lock is stale (>4 min) — previous run may have crashed
    logOptimizer("Removing stale cron lock file ({$lockAge}s old)");
}
file_put_contents($lockFile, date('Y-m-d H:i:s') . ' PID:' . getmypid());

// Register cleanup to remove lock on exit
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) @unlink($lockFile);
});

// Migration: Add review_notified column if missing
try {
    $db->query("ALTER TABLE optimizer_monitored_campaigns ADD COLUMN review_notified SMALLINT DEFAULT 0");
} catch (Exception $e) {
    // Column already exists — ignore
}

// Migration: Add 'review' to optimizer_logs action enum
try {
    $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');
    if ($driver !== 'pgsql') {
        $db->query("ALTER TABLE optimizer_logs MODIFY COLUMN action ENUM('pause','resume','rule_check','review') NOT NULL");
    } else {
        $db->query("ALTER TABLE optimizer_logs DROP CONSTRAINT IF EXISTS optimizer_logs_action_check");
        $db->query("ALTER TABLE optimizer_logs ADD CONSTRAINT optimizer_logs_action_check CHECK (action IN ('pause','resume','rule_check','review'))");
    }
} catch (Exception $e) {}

echo "[" . date('Y-m-d H:i:s') . "] Running optimizer check...\n";

ActivityLogger::log('cron_optimizer_start', 'cron-optimizer.php', [], 'success');
$stats = runOptimizerCheck($db, $accessToken);
ActivityLogger::log('cron_optimizer_complete', 'cron-optimizer.php', $stats, 'success');

echo "Results: Checked {$stats['checked']}, Paused {$stats['paused']}, Resumed {$stats['resumed']}\n";

// Cleanup old activity logs (keep 30 days)
ActivityLogger::cleanup(30);
