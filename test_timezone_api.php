<?php
session_start();

// Load environment variables
if (file_exists('.env')) {
    $env = file_get_contents('.env');
    preg_match_all('/^(.+?)=(.+?)$/m', $env, $matches);
    for ($i = 0; $i < count($matches[1]); $i++) {
        $_ENV[trim($matches[1][$i])] = trim($matches[2][$i]);
    }
}

// Simulate authenticated session with advertiser
$_SESSION['authenticated'] = true;
$_SESSION['selected_advertiser_id'] = '7439066829449699329'; // Example advertiser ID

function logToFile($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[{$timestamp}] {$message}" . PHP_EOL, 3, 'timezone_test.log');
    echo "[{$timestamp}] {$message}\n";
}

echo "<h1>Testing Timezone API</h1>\n";
echo "<pre>\n";

logToFile("============ TIMEZONE API TEST ============");

$accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
if (empty($accessToken)) {
    logToFile('ERROR: Access token not found');
    exit(1);
}

$advertiserId = $_SESSION['selected_advertiser_id'] ?? '';
if (empty($advertiserId)) {
    logToFile('ERROR: Advertiser ID not found');
    exit(1);
}

$url = "https://business-api.tiktok.com/open_api/v1.3/tool/timezone/?advertiser_id=" . $advertiserId;

logToFile("Testing URL: {$url}");
logToFile("Advertiser ID: {$advertiserId}");
logToFile("Access token length: " . strlen($accessToken));

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Access-Token: " . $accessToken,
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT => 30
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

logToFile("HTTP Code: {$httpCode}");
logToFile("Response: " . $result);

if ($httpCode === 200) {
    $response = json_decode($result, true);
    logToFile("Response decoded successfully");
    
    if (isset($response['code']) && $response['code'] === 0) {
        logToFile("✅ TikTok API Success!");
        logToFile("Timezone count: " . count($response['data']['timezone_list'] ?? []));
        
        // Look for Colombia
        $colombiaFound = false;
        if (isset($response['data']['timezone_list'])) {
            foreach ($response['data']['timezone_list'] as $tz) {
                if (stripos($tz['timezone_name'], 'bogota') !== false || 
                    stripos($tz['timezone_name'], 'colombia') !== false) {
                    $colombiaFound = true;
                    logToFile("🇨🇴 Found Colombia timezone: " . $tz['timezone_name'] . " (UTC" . $tz['utc_offset_hour'] . ")");
                }
            }
        }
        
        if (!$colombiaFound) {
            logToFile("⚠️  Colombia timezone not found in API response");
        }
        
    } else {
        logToFile("❌ TikTok API Error:");
        logToFile("Code: " . ($response['code'] ?? 'unknown'));
        logToFile("Message: " . ($response['message'] ?? 'no message'));
    }
} else {
    logToFile("❌ HTTP Error: {$httpCode}");
}

echo "</pre>\n";
echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>\n";
?>