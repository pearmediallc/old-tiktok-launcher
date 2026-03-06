<?php
// Load Security helper first (configures secure session settings)
require_once __DIR__ . '/includes/Security.php';
Security::init();

session_start();

// Load environment variables
require_once 'config.php';

// Log all received parameters
error_log("OAuth Callback: " . print_r($_GET, true));

// Verify state to prevent CSRF
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die('Invalid state parameter. Possible CSRF attack.');
}

// Check for authorization code
if (!isset($_GET['auth_code'])) {
    $error = isset($_GET['error']) ? $_GET['error'] : 'Unknown error';
    die('Authorization failed: ' . htmlspecialchars($error));
}

$auth_code = $_GET['auth_code'];
$config = getConfig();

// Exchange authorization code for access token
$token_url = 'https://business-api.tiktok.com/open_api/v1.3/oauth2/access_token/';
$token_params = [
    'app_id' => $config['app_id'],
    'secret' => $config['app_secret'],
    'auth_code' => $auth_code
];

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($token_params));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("Token Exchange Response Code: " . $httpCode);
error_log("Token Exchange Response: " . $response);

$tokenData = json_decode($response, true);

if ($httpCode !== 200 || !isset($tokenData['data']['access_token'])) {
    $error_message = isset($tokenData['message']) ? $tokenData['message'] : 'Failed to obtain access token';
    die('Token exchange failed: ' . htmlspecialchars($error_message));
}

// Regenerate session ID to prevent session fixation attacks after successful OAuth
Security::regenerateSession();

// Store tokens in session (will be transferred to browser localStorage via JavaScript)
$access_token = $tokenData['data']['access_token'];
$_SESSION['oauth_access_token'] = $access_token;
$_SESSION['oauth_refresh_token'] = $tokenData['data']['refresh_token'] ?? '';
$_SESSION['oauth_advertiser_ids'] = $tokenData['data']['advertiser_ids'] ?? [];
$_SESSION['oauth_expires_in'] = $tokenData['data']['expires_in'] ?? 86400;
$_SESSION['oauth_token_type'] = $tokenData['data']['token_type'] ?? 'Bearer';

error_log("OAuth Success: Token obtained");
error_log("Advertiser IDs: " . json_encode($_SESSION['oauth_advertiser_ids']));

// Fetch advertiser names using correct OAuth2 endpoint with PAGINATION
$advertiser_details = [];
if (!empty($_SESSION['oauth_advertiser_ids'])) {
    try {
        // Use TikTok OAuth2 advertiser endpoint with pagination to get ALL accounts
        $advertiser_info_url = 'https://business-api.tiktok.com/open_api/v1.3/oauth2/advertiser/get/';
        $pageSize = 100; // Max page size for efficiency
        $page = 1;
        $maxPages = 50; // Safety limit (5000 accounts max)

        do {
            $info_params = [
                'app_id' => $config['app_id'],
                'secret' => $config['app_secret'],
                'page' => $page,
                'page_size' => $pageSize
            ];

            $ch = curl_init($advertiser_info_url . '?' . http_build_query($info_params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Access-Token: ' . $access_token
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            error_log("Advertiser Info Page $page - Response Code: " . $httpCode);

            $infoData = json_decode($response, true);

            if ($httpCode === 200 && isset($infoData['data']['list']) && is_array($infoData['data']['list'])) {
                foreach ($infoData['data']['list'] as $advertiser) {
                    $advertiser_details[$advertiser['advertiser_id']] = [
                        'id' => $advertiser['advertiser_id'],
                        'name' => $advertiser['advertiser_name'] ?? 'Account',
                        'status' => $advertiser['status'] ?? 'active',
                        'rejection_reason' => $advertiser['rejection_reason'] ?? null,
                        'company' => $advertiser['company'] ?? null,
                        'contacter' => $advertiser['contacter'] ?? null,
                        'description' => $advertiser['description'] ?? null
                    ];
                }

                // Check if more pages exist
                $totalPages = $infoData['data']['page_info']['total_page'] ?? 1;
                error_log("Advertiser Info: Page $page of $totalPages, found " . count($infoData['data']['list']) . " accounts");
            } else {
                // API error or no more data, stop pagination
                break;
            }

            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        error_log("Total advertiser details fetched: " . count($advertiser_details));
    } catch (Exception $e) {
        error_log("Error fetching advertiser info: " . $e->getMessage());
    }
}

$_SESSION['oauth_advertiser_details'] = $advertiser_details;
error_log("Advertiser Details: " . json_encode($advertiser_details));

// Save token to database so cron job can access it
try {
    require_once __DIR__ . '/database/Database.php';
    $db = Database::getInstance();
    $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');

    // Auto-create users table if it doesn't exist
    if ($driver === 'pgsql') {
        $db->query("CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            full_name VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            status VARCHAR(20) DEFAULT 'active'
        )");
        $db->query("CREATE TABLE IF NOT EXISTS tiktok_connections (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            token_type VARCHAR(50) DEFAULT 'Bearer',
            expires_in INT DEFAULT 86400,
            token_expires_at TIMESTAMP NOT NULL,
            advertiser_id VARCHAR(255),
            advertiser_name VARCHAR(255),
            advertiser_ids JSONB,
            scope TEXT,
            connection_status VARCHAR(20) DEFAULT 'active',
            last_sync_at TIMESTAMP NULL,
            last_refresh_at TIMESTAMP NULL,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
    } else {
        $db->query("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            full_name VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            status VARCHAR(20) DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS tiktok_connections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            token_type VARCHAR(50) DEFAULT 'Bearer',
            expires_in INT DEFAULT 86400,
            token_expires_at TIMESTAMP NOT NULL,
            advertiser_id VARCHAR(255),
            advertiser_name VARCHAR(255),
            advertiser_ids JSON,
            scope TEXT,
            connection_status VARCHAR(20) DEFAULT 'active',
            last_sync_at TIMESTAMP NULL,
            last_refresh_at TIMESTAMP NULL,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Ensure a user record exists for this session username
    $username = $_SESSION['username'] ?? 'admin';
    $existingUser = $db->fetchOne(
        "SELECT id FROM users WHERE username = ?",
        [$username]
    );

    if ($existingUser) {
        $userId = $existingUser['id'];
    } else {
        // Create a user record for the authenticated user
        $userId = $db->insert('users', [
            'username' => $username,
            'password_hash' => 'oauth-managed',
            'email' => $username . '@local',
        ]);
    }

    // Deactivate all previous connections
    $db->query(
        "UPDATE tiktok_connections SET connection_status = 'revoked' WHERE user_id = ? AND connection_status = 'active'",
        [$userId]
    );

    // Insert new connection with token
    $expiresAt = date('Y-m-d H:i:s', time() + ($_SESSION['oauth_expires_in'] ?? 86400));
    $db->insert('tiktok_connections', [
        'user_id' => $userId,
        'access_token' => $access_token,
        'refresh_token' => $_SESSION['oauth_refresh_token'] ?? '',
        'token_type' => $_SESSION['oauth_token_type'] ?? 'Bearer',
        'expires_in' => $_SESSION['oauth_expires_in'] ?? 86400,
        'token_expires_at' => $expiresAt,
        'advertiser_ids' => json_encode($_SESSION['oauth_advertiser_ids'] ?? []),
        'connection_status' => 'active',
        'last_refresh_at' => date('Y-m-d H:i:s'),
    ]);

    error_log("OAuth token saved to database for cron job access (user: $username)");
} catch (Exception $e) {
    // Don't block the OAuth flow if DB save fails
    error_log("Failed to save OAuth token to database: " . $e->getMessage());
}

// Redirect to unified app shell (accounts shown inline, no separate selection page)
header('Location: app-shell.php');
exit;
