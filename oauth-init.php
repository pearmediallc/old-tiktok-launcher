<?php
session_start();

// Load environment variables
require_once 'config.php';

// TikTok OAuth configuration
$config = getConfig();
$app_id = $config['app_id'];
$redirect_uri = $_ENV['OAUTH_REDIRECT_URI'] ?? 'http://localhost:8080/oauth-callback.php';

// Generate state for CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

// Build TikTok OAuth authorization URL
$auth_url = 'https://business-api.tiktok.com/open_api/v1.3/oauth2/authorize/';
$params = [
    'app_id' => $app_id,
    'state' => $state,
    'redirect_uri' => $redirect_uri,
    'rid' => uniqid() // Optional request ID for tracking
];

$authorization_url = $auth_url . '?' . http_build_query($params);

// Log the OAuth initiation
error_log("OAuth Init: Redirecting to TikTok OAuth");
error_log("Redirect URI: " . $redirect_uri);
error_log("State: " . $state);

// Redirect to TikTok OAuth page
header('Location: ' . $authorization_url);
exit;
