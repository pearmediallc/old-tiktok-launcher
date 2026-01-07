<?php
/**
 * Secure Image Server for uploaded TikTok images
 * - Validates authentication
 * - Prevents directory traversal attacks
 * - Only serves allowed image types
 * - Validates file is within allowed directory
 */
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

// Define allowed directory (absolute path)
$allowedDir = realpath(__DIR__ . '/uploads/images');

if ($allowedDir === false) {
    http_response_code(500);
    exit('Upload directory not configured');
}

// Security: Extract only the filename, ignoring any path
// This prevents directory traversal by only using the base filename
$filename = basename($imagePath);

// Whitelist allowed characters in filename (alphanumeric, underscore, hyphen, dot)
if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
    http_response_code(400);
    exit('Invalid filename');
}

// Whitelist allowed extensions
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions)) {
    http_response_code(400);
    exit('File type not allowed');
}

// Build the full path
$fullPath = $allowedDir . DIRECTORY_SEPARATOR . $filename;

// Security: Verify the resolved path is within allowed directory
$realFullPath = realpath($fullPath);

if ($realFullPath === false) {
    http_response_code(404);
    exit('Image not found');
}

// Ensure the file is within the allowed directory (prevents symlink attacks)
if (strpos($realFullPath, $allowedDir) !== 0) {
    http_response_code(403);
    exit('Access denied');
}

// Check if file exists and is readable
if (!is_file($realFullPath) || !is_readable($realFullPath)) {
    http_response_code(404);
    exit('Image not found');
}

// Verify it's actually an image by checking magic bytes
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $realFullPath);
finfo_close($finfo);

$allowedMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp'
];

if (!in_array($mimeType, $allowedMimeTypes)) {
    http_response_code(400);
    exit('Invalid image file');
}

// Set appropriate content type
$contentTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp'
];

$contentType = $contentTypes[$extension] ?? $mimeType;

// Set security headers
header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($realFullPath));
header('Cache-Control: public, max-age=86400'); // Cache for 1 day
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
header('X-Content-Type-Options: nosniff'); // Prevent MIME sniffing
header('Content-Disposition: inline; filename="' . $filename . '"');

// Output the image
readfile($realFullPath);
exit;
?>