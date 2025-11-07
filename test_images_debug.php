<?php
session_start();

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
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

echo "<h2>Image API Debug Test</h2>";

// Make request to our own API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost' . $_SERVER['REQUEST_URI'] . 'api.php?action=get_images');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>API Response (HTTP {$httpCode}):</h3>";
echo "<pre>";
$data = json_decode($response, true);
print_r($data);
echo "</pre>";

if ($data && isset($data['data']['list'])) {
    echo "<h3>Image URLs Test:</h3>";
    foreach ($data['data']['list'] as $index => $image) {
        echo "<div style='margin: 20px; padding: 10px; border: 1px solid #ccc;'>";
        echo "<h4>Image {$index}: {$image['file_name']}</h4>";
        echo "<p><strong>Image ID:</strong> {$image['image_id']}</p>";
        echo "<p><strong>Available URLs:</strong></p>";
        echo "<ul>";
        foreach (['url', 'image_url', 'preview_url', 'thumbnail_url'] as $field) {
            if (isset($image[$field]) && !empty($image[$field])) {
                echo "<li><strong>{$field}:</strong> " . substr($image[$field], 0, 100) . "...</li>";
            }
        }
        echo "</ul>";
        
        // Test the image URL
        $testUrl = $image['image_url'] ?? $image['url'] ?? '';
        if ($testUrl) {
            echo "<div style='margin: 10px 0;'>";
            echo "<strong>Testing image display:</strong><br>";
            echo "<img src='{$testUrl}' alt='{$image['file_name']}' style='max-width: 200px; max-height: 200px; border: 2px solid #4fc3f7;' onload=\"console.log('Image loaded:', '{$image['file_name']}')\" onerror=\"console.error('Image failed:', '{$image['file_name']}'); this.style.border='2px solid red';\">";
            echo "</div>";
        }
        echo "</div>";
    }
} else {
    echo "<p>No images found in response.</p>";
}
?>