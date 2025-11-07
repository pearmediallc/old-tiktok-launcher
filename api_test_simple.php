<?php
session_start();

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

echo "<h1>Simple TikTok API Test</h1>";

// Check basic setup
echo "<h2>1. Basic Setup Check</h2>";
echo "Session authenticated: " . (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] ? 'YES' : 'NO') . "<br>";
echo "Access token exists: " . (isset($_ENV['TIKTOK_ACCESS_TOKEN']) ? 'YES' : 'NO') . "<br>";
echo "Access token length: " . (isset($_ENV['TIKTOK_ACCESS_TOKEN']) ? strlen($_ENV['TIKTOK_ACCESS_TOKEN']) : '0') . "<br>";
echo "Selected advertiser ID: " . ($_SESSION['selected_advertiser_id'] ?? 'NOT SET') . "<br>";

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    echo "<p style='color: red;'>❌ Not authenticated - please login first</p>";
    exit;
}

if (!isset($_ENV['TIKTOK_ACCESS_TOKEN'])) {
    echo "<p style='color: red;'>❌ No access token</p>";
    exit;
}

if (!isset($_SESSION['selected_advertiser_id'])) {
    echo "<p style='color: red;'>❌ No advertiser selected</p>";
    exit;
}

$accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'];
$advertiser_id = $_SESSION['selected_advertiser_id'];

echo "<h2>2. TikTok API Test</h2>";

$url = "https://business-api.tiktok.com/open_api/v1.3/file/list/";
$payload = [
    "advertiser_id" => $advertiser_id,
    "file_type" => "IMAGE",
    "page" => 1,
    "page_size" => 5
];

echo "URL: " . $url . "<br>";
echo "Payload: " . json_encode($payload) . "<br>";
echo "Access token (first 20 chars): " . substr($accessToken, 0, 20) . "...<br><br>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        "Access-Token: " . $accessToken,
        "Content-Type: application/json"
    ]
]);

echo "Making API call...<br>";
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "<h3>Results:</h3>";
echo "HTTP Code: " . $httpCode . "<br>";
echo "Curl Error: " . ($curlError ?: 'None') . "<br>";
echo "Raw Response: <pre>" . htmlspecialchars($result) . "</pre>";

if ($httpCode == 200) {
    $response = json_decode($result);
    echo "<h3>Parsed Response:</h3>";
    echo "<pre>" . json_encode($response, JSON_PRETTY_PRINT) . "</pre>";
    
    if (isset($response->data->list)) {
        echo "<h3>Images Found: " . count($response->data->list) . "</h3>";
        foreach ($response->data->list as $index => $image) {
            echo "<h4>Image " . $index . ":</h4>";
            echo "File Name: " . ($image->file_name ?? 'N/A') . "<br>";
            echo "Image ID: " . ($image->image_id ?? 'N/A') . "<br>";
            echo "Image URL: " . ($image->image_url ?? 'MISSING') . "<br>";
            echo "URL: " . ($image->url ?? 'MISSING') . "<br>";
            echo "Displayable: " . ($image->displayable ? 'true' : 'false') . "<br>";
            echo "Preview URL: " . ($image->preview_url ?? 'MISSING') . "<br><br>";
        }
    }
} else {
    echo "<p style='color: red;'>❌ API call failed</p>";
}
?>