<?php
// Direct TikTok API test without session dependencies

echo "=== DIRECT TIKTOK API TEST ===\n";

// Use hardcoded values from .env for testing
$accessToken = 'e285e1288dbb1d9eff2e1924431917edbebfad31';
$advertiser_id = '7546384313781125137'; // Using numeric advertiser ID that worked before

echo "Access Token: " . substr($accessToken, 0, 10) . "...\n";
echo "Advertiser ID: " . $advertiser_id . "\n";

$url = "https://business-api.tiktok.com/open_api/v1.3/file/image/ad/search/";
$payload = [
    "advertiser_id" => $advertiser_id,
    "file_type" => "IMAGE", 
    "page" => 1,
    "page_size" => 5
];

echo "Making API call to: " . $url . "\n";
echo "Payload: " . json_encode($payload) . "\n\n";

// Try GET method with query parameters
$queryString = http_build_query($payload);
$getUrl = $url . '?' . $queryString;

echo "GET URL: " . $getUrl . "\n\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $getUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        "Access-Token: " . $accessToken
    ],
    CURLOPT_VERBOSE => true,
    CURLOPT_STDERR => fopen('php://output', 'w')
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlInfo = curl_getinfo($ch);
$curlError = curl_error($ch);
curl_close($ch);

echo "\n=== RESULTS ===\n";
echo "HTTP Code: " . $httpCode . "\n";
echo "Curl Error: " . ($curlError ?: 'None') . "\n";
echo "Content Type: " . $curlInfo['content_type'] . "\n";
echo "Total Time: " . $curlInfo['total_time'] . "s\n";

echo "\n=== RAW RESPONSE ===\n";
echo $result . "\n";

if ($httpCode == 200) {
    $response = json_decode($result);
    if ($response && isset($response->data->list)) {
        echo "\n=== SUCCESS! ===\n";
        echo "Found " . count($response->data->list) . " images\n";
        
        foreach ($response->data->list as $index => $image) {
            echo "\nImage " . $index . ":\n";
            echo "  File: " . ($image->file_name ?? 'N/A') . "\n";
            echo "  ID: " . ($image->image_id ?? 'N/A') . "\n";
            echo "  image_url: " . ($image->image_url ?? 'MISSING') . "\n";
            echo "  displayable: " . ($image->displayable ? 'true' : 'false') . "\n";
        }
    }
} else {
    echo "\n=== API FAILED ===\n";
    $errorResponse = json_decode($result);
    if ($errorResponse) {
        echo "Error Code: " . ($errorResponse->code ?? 'N/A') . "\n";
        echo "Error Message: " . ($errorResponse->message ?? 'N/A') . "\n";
    }
}
?>