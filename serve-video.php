<?php
/**
 * Token-Based Video Server for TikTok UPLOAD_BY_URL
 *
 * TikTok pulls videos from this endpoint after we call UPLOAD_BY_URL.
 * Uses token-based auth (NOT session) since TikTok servers can't have sessions.
 * Tokens expire after 30 minutes and files are cleaned up automatically.
 */

// No session needed — token-based auth for TikTok's servers

$token = $_GET['token'] ?? '';
$file = $_GET['file'] ?? '';

if (empty($token) || empty($file)) {
    http_response_code(400);
    exit('Missing parameters');
}

// Validate token
$tokenFile = __DIR__ . '/cache/video_tokens.json';
if (!file_exists($tokenFile)) {
    http_response_code(403);
    exit('Invalid token');
}

$tokens = json_decode(file_get_contents($tokenFile), true);
if (!$tokens || !isset($tokens[$token])) {
    http_response_code(403);
    exit('Invalid token');
}

$tokenData = $tokens[$token];

// Check expiry
if ($tokenData['expires'] < time()) {
    // Clean up expired token
    unset($tokens[$token]);
    file_put_contents($tokenFile, json_encode($tokens));
    http_response_code(403);
    exit('Token expired');
}

// Verify the file matches the token
if ($tokenData['file'] !== $file) {
    http_response_code(403);
    exit('Token/file mismatch');
}

// Security: only allow safe filename characters
$filename = basename($file);
if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
    http_response_code(400);
    exit('Invalid filename');
}

// Whitelist allowed video extensions
$allowedExtensions = ['mp4', 'mov', 'avi', 'wmv', 'webm', '3gp', 'mkv', 'mpeg', 'mpg'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($extension, $allowedExtensions)) {
    http_response_code(400);
    exit('File type not allowed');
}

// Build full path and validate
$videosDir = realpath(__DIR__ . '/uploads/videos');
if ($videosDir === false) {
    http_response_code(500);
    exit('Videos directory not found');
}

$fullPath = $videosDir . DIRECTORY_SEPARATOR . $filename;
$realFullPath = realpath($fullPath);

if ($realFullPath === false || !is_file($realFullPath) || !is_readable($realFullPath)) {
    http_response_code(404);
    exit('Video not found');
}

// Prevent directory traversal
if (strpos($realFullPath, $videosDir) !== 0) {
    http_response_code(403);
    exit('Access denied');
}

// Verify MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $realFullPath);
finfo_close($finfo);

$allowedMimeTypes = [
    'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-ms-wmv',
    'video/webm', 'video/3gpp', 'video/x-matroska', 'video/mpeg'
];

if (!in_array($mimeType, $allowedMimeTypes)) {
    http_response_code(400);
    exit('Invalid video file');
}

// Serve the video
$fileSize = filesize($realFullPath);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');

// Use readfile for efficient streaming
readfile($realFullPath);
exit;
