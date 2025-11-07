<?php
session_start();

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// ---- CONFIG ----
$accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
$advertiserId = $_SESSION['selected_advertiser_id'] ?? $_ENV['TIKTOK_ADVERTISER_ID'] ?? '';

if (empty($accessToken) || empty($advertiserId)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing access token or advertiser ID',
        'debug' => [
            'has_token' => !empty($accessToken),
            'advertiser_id' => $advertiserId
        ]
    ]);
    exit;
}

// ---- API REQUEST ----
$url = "https://business-api.tiktok.com/open_api/v1.3/file/list/";
$payload = json_encode([
    "advertiser_id" => $advertiserId,
    "file_type" => "IMAGE",
    "page" => 1,
    "page_size" => 50
]);

echo "Testing TikTok Image API with file/list endpoint...\n";
echo "Advertiser ID: " . $advertiserId . "\n";
echo "URL: " . $url . "\n";
echo "Payload: " . $payload . "\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Access-Token: $accessToken",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . $response . "\n\n";

$data = json_decode($response, true);

// ---- EXTRACT IMAGES ----
$images = [];
if (isset($data['data']['list'])) {
    foreach ($data['data']['list'] as $item) {
        if ($item['displayable'] ?? true) { // only show displayable items
            $images[] = [
                "image_id" => $item['image_id'] ?? $item['id'],
                "name" => $item['file_name'],
                "url" => $item['image_url'] ?? '',
                "displayable" => $item['displayable'] ?? true
            ];
        }
    }
}

echo "Found " . count($images) . " displayable images:\n";
foreach ($images as $img) {
    echo "- " . $img['name'] . " (ID: " . $img['image_id'] . ")\n";
    echo "  URL: " . $img['url'] . "\n";
}

// Return JSON response
echo json_encode([
    'success' => true,
    'data' => ['list' => $images],
    'debug' => [
        'http_code' => $httpCode,
        'total_found' => count($images),
        'advertiser_id' => $advertiserId
    ]
]);
?>