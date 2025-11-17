<?php
/**
 * Configuration loader for TikTok Campaign Launcher
 * Loads environment variables and provides configuration access
 */

// Load environment variables from .env file
function loadEnv() {
    if (file_exists(__DIR__ . '/.env')) {
        $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) continue;

            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $_ENV[trim($name)] = trim($value);
            }
        }
    }
}

// Load environment variables
loadEnv();

/**
 * Get configuration array for TikTok API
 * @return array Configuration with app_id, app_secret, access_token, advertiser_id
 */
function getConfig() {
    return [
        'app_id'        => $_ENV['TIKTOK_APP_ID'] ?? '',
        'app_secret'    => $_ENV['TIKTOK_APP_SECRET'] ?? '',
        'access_token'  => $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '',
        'advertiser_id' => $_ENV['TIKTOK_ADVERTISER_ID'] ?? '',
        'environment'   => $_ENV['TIKTOK_ENVIRONMENT'] ?? 'production',
        'api_version'   => 'v1.3'
    ];
}
