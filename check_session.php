<?php
session_start();

echo "=== SESSION DEBUG ===\n";
echo "Authenticated: " . (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] ? 'YES' : 'NO') . "\n";
echo "Selected advertiser ID: " . ($_SESSION['selected_advertiser_id'] ?? 'NOT SET') . "\n";
echo "All session data:\n";
print_r($_SESSION);

// Load .env to check what's configured
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

echo "\n=== ENV CONFIG ===\n";
echo "TIKTOK_ADVERTISER_ID: " . ($_ENV['TIKTOK_ADVERTISER_ID'] ?? 'NOT SET') . "\n";
echo "TIKTOK_ACCESS_TOKEN exists: " . (isset($_ENV['TIKTOK_ACCESS_TOKEN']) ? 'YES' : 'NO') . "\n";
?>