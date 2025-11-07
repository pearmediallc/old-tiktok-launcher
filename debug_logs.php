<?php
session_start();

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    echo "Unauthorized - Please login first";
    exit;
}

// Set content type
header('Content-Type: text/plain');

// Read the log file
$logFile = __DIR__ . '/api.log';

if (!file_exists($logFile)) {
    echo "Log file does not exist: $logFile\n";
    exit;
}

$logContent = file_get_contents($logFile);

if ($logContent === false) {
    echo "Failed to read log file\n";
    exit;
}

// Show last 1000 lines for performance
$lines = explode("\n", $logContent);
$totalLines = count($lines);
$showLines = min(1000, $totalLines);
$startLine = max(0, $totalLines - $showLines);

echo "=== RECENT API LOGS (Last $showLines lines of $totalLines total) ===\n\n";

for ($i = $startLine; $i < $totalLines; $i++) {
    if (!empty(trim($lines[$i]))) {
        echo $lines[$i] . "\n";
    }
}
?>