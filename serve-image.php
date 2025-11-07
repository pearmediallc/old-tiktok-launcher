<?php
// Simple image server for uploaded TikTok images
session_start();

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    exit('Unauthorized');
}

// Get the image path from URL parameter
$imagePath = $_GET['path'] ?? '';

if (empty($imagePath)) {
    http_response_code(400);
    exit('No image path provided');
}

// Sanitize the path to prevent directory traversal
$imagePath = str_replace(['../', '.\\'], '', $imagePath);

// Full path to the image
$fullPath = __DIR__ . '/uploads/images/' . basename($imagePath);

// Check if file exists and is readable
if (!file_exists($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    exit('Image not found');
}

// Get file info
$fileInfo = pathinfo($fullPath);
$extension = strtolower($fileInfo['extension']);

// Set appropriate content type
$contentTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml'
];

$contentType = $contentTypes[$extension] ?? 'application/octet-stream';

// Set headers
header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=86400'); // Cache for 1 day
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));

// Output the image
readfile($fullPath);
exit;
?>