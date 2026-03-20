<?php
/**
 * Slack Slash Command Handler
 * Receives /campaigns commands from Slack and returns campaign data.
 *
 * Setup:
 *  - Slack app → Slash Commands → /campaigns
 *  - Request URL: https://old-tiktok-launcher.onrender.com/slack-commands.php
 *  - Env var: SLACK_SIGNING_SECRET
 *
 * Commands:
 *  /campaigns active  — Show all active campaigns with metrics
 *  /campaigns paused  — Show all paused campaigns
 *  /campaigns spend   — Today's spend summary per account
 *  /campaigns top     — Top 5 campaigns by spend
 *  /campaigns help    — Show available commands
 */

require_once __DIR__ . '/includes/slack-command-handlers.php';

// ── Verify Slack signature ───────────────────────────────────
$rawBody = file_get_contents('php://input');
$signingSecret = getenv('SLACK_SIGNING_SECRET') ?: ($_ENV['SLACK_SIGNING_SECRET'] ?? '');

if (!empty($signingSecret)) {
    $timestamp = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '';
    $slackSig  = $_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '';

    if (empty($timestamp) || empty($slackSig) || abs(time() - intval($timestamp)) > 300) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired request']);
        exit;
    }

    $baseString = "v0:$timestamp:$rawBody";
    $myHash = 'v0=' . hash_hmac('sha256', $baseString, $signingSecret);
    if (!hash_equals($myHash, $slackSig)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// ── Parse slash command payload ──────────────────────────────
parse_str($rawBody, $payload);

$command     = trim($payload['command'] ?? '');
$text        = strtolower(trim($payload['text'] ?? ''));
$slackUser   = $payload['user_id'] ?? '';
$userName    = $payload['user_name'] ?? '';
$responseUrl = $payload['response_url'] ?? '';

if (empty($slackUser)) {
    header('Content-Type: application/json');
    echo json_encode(['response_type' => 'ephemeral', 'text' => 'Error: Could not identify Slack user.']);
    exit;
}

// ── Quick commands (no API calls, respond immediately) ──────
$subCommand = explode(' ', $text)[0] ?? '';

if ($subCommand === 'help') {
    header('Content-Type: application/json');
    echo json_encode(handleHelp());
    exit;
}

// ── Map Slack user → launcher user ───────────────────────────
$userId = getUserIdFromSlack($slackUser);

if (!$userId) {
    header('Content-Type: application/json');
    echo json_encode([
        'response_type' => 'ephemeral',
        'text' => "You haven't connected your Slack account to the TikTok Launcher yet.\n\nPlease log into the launcher and click *Connect Slack* in the header to link your account."
    ]);
    exit;
}

// ── For data commands: respond immediately, then send data via response_url
// Slack requires a response within 3 seconds. TikTok API calls may take longer.
header('Content-Type: application/json');
echo json_encode(['response_type' => 'ephemeral', 'text' => "Fetching campaign data... one moment."]);

// Flush the response to Slack immediately
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_end_flush();
    flush();
}

// ── Now fetch data (after Slack got the 200 response) ────────
switch ($subCommand) {
    case 'active':
    case '':
        $response = handleActiveCampaigns($userId);
        break;
    case 'paused':
    case 'inactive':
        $response = handlePausedCampaigns($userId);
        break;
    case 'spend':
    case 'summary':
        $response = handleSpendSummary($userId);
        break;
    case 'top':
    case 'best':
        $response = handleTopCampaigns($userId);
        break;
    default:
        $response = [
            'response_type' => 'ephemeral',
            'text' => "Unknown command: `{$text}`\n\nType `/campaigns help` to see available commands."
        ];
        break;
}

// ── Send the real response via response_url ──────────────────
if (!empty($responseUrl)) {
    $ch = curl_init($responseUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($response),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
