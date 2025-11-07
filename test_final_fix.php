<?php
session_start();

echo "<h1>Final API Test</h1>";

// Make API call to our fixed endpoint
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://localhost/api.php?action=get_images',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_COOKIE => session_name() . '=' . session_id()
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h2>API Response:</h2>";
echo "<p>HTTP Code: $httpCode</p>";

if ($response) {
    $data = json_decode($response, true);
    echo "<pre>" . json_encode($data, JSON_PRETTY_PRINT) . "</pre>";
    
    if ($data['success'] && isset($data['data']['list'])) {
        echo "<h2>Images with URLs:</h2>";
        foreach ($data['data']['list'] as $index => $image) {
            $hasUrl = !empty($image['image_url']);
            echo "<div style='border: 1px solid " . ($hasUrl ? 'green' : 'red') . "; margin: 10px; padding: 10px;'>";
            echo "<h3>Image " . ($index + 1) . ": " . $image['file_name'] . "</h3>";
            echo "<p><strong>Image ID:</strong> " . $image['image_id'] . "</p>";
            echo "<p><strong>Has image_url:</strong> " . ($hasUrl ? 'YES ✅' : 'NO ❌') . "</p>";
            if ($hasUrl) {
                echo "<p><strong>URL:</strong> " . substr($image['image_url'], 0, 80) . "...</p>";
                echo "<img src='" . $image['image_url'] . "' style='max-width: 200px; max-height: 200px; border: 2px solid green;' onload=\"console.log('✅ Image loaded')\" onerror=\"console.error('❌ Image failed')\">";
            } else {
                echo "<p style='color: red;'>❌ No URL available</p>";
            }
            echo "</div>";
        }
    }
} else {
    echo "<p style='color: red;'>❌ No response from API</p>";
}
?>