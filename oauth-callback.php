<?php
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

// Store tokens in session (will be transferred to browser localStorage via JavaScript)
$access_token = $tokenData['data']['access_token'];
$_SESSION['oauth_access_token'] = $access_token;
$_SESSION['oauth_refresh_token'] = $tokenData['data']['refresh_token'] ?? '';
$_SESSION['oauth_advertiser_ids'] = $tokenData['data']['advertiser_ids'] ?? [];
$_SESSION['oauth_expires_in'] = $tokenData['data']['expires_in'] ?? 86400;
$_SESSION['oauth_token_type'] = $tokenData['data']['token_type'] ?? 'Bearer';

error_log("OAuth Success: Token obtained");
error_log("Advertiser IDs: " . json_encode($_SESSION['oauth_advertiser_ids']));

// Fetch advertiser details (names) from TikTok API
$advertiser_details = [];
if (!empty($_SESSION['oauth_advertiser_ids'])) {
    // Use the advertiser/info endpoint to get advertiser details
    $advertiser_info_url = 'https://business-api.tiktok.com/open_api/v1.3/advertiser/info/';

    foreach ($_SESSION['oauth_advertiser_ids'] as $advertiser_id) {
        $ch = curl_init($advertiser_info_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'advertiser_id' => $advertiser_id,
            'fields' => ['advertiser_id', 'advertiser_name', 'status']
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Access-Token: ' . $access_token
        ]);

        $advertiser_response = curl_exec($ch);
        $advertiser_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("Advertiser Info for $advertiser_id - Response Code: " . $advertiser_http_code);
        error_log("Advertiser Info Response: " . $advertiser_response);

        if ($advertiser_http_code === 200) {
            $advertiser_data = json_decode($advertiser_response, true);
            if (isset($advertiser_data['data'])) {
                $data = $advertiser_data['data'];
                $advertiser_details[$advertiser_id] = [
                    'id' => $advertiser_id,
                    'name' => $data['advertiser_name'] ?? 'Unnamed Account',
                    'status' => $data['status'] ?? 'unknown'
                ];
            }
        } else {
            // If API call fails, use a fallback name
            $advertiser_details[$advertiser_id] = [
                'id' => $advertiser_id,
                'name' => 'Advertiser ' . $advertiser_id,
                'status' => 'unknown'
            ];
        }
    }
}

// Store advertiser details in session
$_SESSION['oauth_advertiser_details'] = $advertiser_details;

error_log("Advertiser Details: " . json_encode($advertiser_details));

// Redirect to advertiser selection page where JavaScript will store token in localStorage
header('Location: select-advertiser-oauth.php');
exit;
