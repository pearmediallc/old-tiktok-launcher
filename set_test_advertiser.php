<?php
session_start();

// Set the working advertiser ID for testing
$_SESSION['authenticated'] = true;
$_SESSION['selected_advertiser_id'] = '7546384313781125137';

echo "Test advertiser session set successfully!\n";
echo "Authenticated: " . ($_SESSION['authenticated'] ? 'YES' : 'NO') . "\n";
echo "Selected advertiser ID: " . ($_SESSION['selected_advertiser_id'] ?? 'NOT SET') . "\n";
echo "\nNow test the images API at: debug_test.html or refresh your app\n";
?>