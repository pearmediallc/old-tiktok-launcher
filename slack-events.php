<?php
/**
 * Slack Events API Handler
 * Handles DM messages to the bot + URL verification challenge.
 *
 * Setup:
 *  - Slack app → Event Subscriptions → Enable Events
 *  - Request URL: https://old-tiktok-launcher.onrender.com/slack-events.php
 *  - Subscribe to bot events: message.im
 *  - Scopes needed: chat:write, im:history, im:read, im:write
 */

require_once __DIR__ . '/includes/slack-command-handlers.php';

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

// ── Step 1: Handle Slack URL verification challenge ──────────
if (isset($data['type']) && $data['type'] === 'url_verification') {
    header('Content-Type: application/json');
    echo json_encode(['challenge' => $data['challenge']]);
    exit;
}

// ── Step 2: Verify Slack signature ───────────────────────────
$signingSecret = getenv('SLACK_SIGNING_SECRET') ?: ($_ENV['SLACK_SIGNING_SECRET'] ?? '');
if (!empty($signingSecret)) {
    $timestamp = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '';
    $slackSig  = $_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '';

    if (empty($timestamp) || empty($slackSig) || abs(time() - intval($timestamp)) > 300) {
        http_response_code(401);
        exit;
    }

    $baseString = "v0:$timestamp:$rawBody";
    $myHash = 'v0=' . hash_hmac('sha256', $baseString, $signingSecret);
    if (!hash_equals($myHash, $slackSig)) {
        http_response_code(401);
        exit;
    }
}

// ── Step 3: Respond 200 immediately (Slack requires within 3 sec) ──
http_response_code(200);
echo 'ok';
ignore_user_abort(true);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level()) ob_end_flush();
    flush();
}

// ── Step 4: Process the event ────────────────────────────────
if (!isset($data['event'])) exit;

$event = $data['event'];
$eventType = $event['type'] ?? '';

// Only handle DM messages (message.im)
if ($eventType !== 'message') exit;

// Ignore bot messages, edits, and subtypes (to avoid infinite loops)
if (!empty($event['bot_id']) || !empty($event['subtype'])) exit;

$slackUserId = $event['user'] ?? '';
$messageText = strtolower(trim($event['text'] ?? ''));
$channelId   = $event['channel'] ?? ''; // DM channel ID

if (empty($slackUserId) || empty($messageText) || empty($channelId)) exit;

// ── Step 5: Map Slack user → launcher user ───────────────────
$userId = getUserIdFromSlack($slackUserId);

if (!$userId) {
    sendBotDm($channelId, "You haven't connected your Slack account to the TikTok Launcher yet.\n\nPlease log into the launcher and click *Connect Slack* to link your account.");
    exit;
}

// ── Step 6: Parse command and respond ────────────────────────
$response = routeBotCommand($messageText, $userId);
sendBotDm($channelId, $response['text'] ?? '', $response['blocks'] ?? []);

// ============================================
// HELPER FUNCTIONS
// ============================================

function routeBotCommand($message, $userId) {
    // Normalize message
    $message = preg_replace('/\s+/', ' ', $message);

    // Match commands
    if (preg_match('/\b(active|running|live)\b.*\b(campaign|campaigns)\b/', $message) ||
        preg_match('/\b(show|get|fetch|list)\b.*\b(active|running)\b/', $message) ||
        $message === 'active' || $message === 'active campaigns') {
        return handleActiveCampaigns($userId);
    }

    if (preg_match('/\b(paused|inactive|stopped|disabled)\b/', $message)) {
        return handlePausedCampaigns($userId);
    }

    if (preg_match('/\b(spend|spending|cost|budget)\b/', $message) ||
        preg_match('/\b(summary|overview|dashboard)\b/', $message)) {
        return handleSpendSummary($userId);
    }

    if (preg_match('/\b(top|best|highest)\b/', $message)) {
        return handleTopCampaigns($userId);
    }

    if (preg_match('/\b(help|commands|what can you do|how to use)\b/', $message) ||
        $message === 'hi' || $message === 'hello' || $message === 'hey') {
        return buildBotHelp();
    }

    // Default: show help
    return buildBotHelp("I didn't understand that. Here's what I can do:");
}

function buildBotHelp($intro = null) {
    $text = ($intro ? "$intro\n\n" : "") .
        "*Here's what you can say to me:*\n\n" .
        "• `show active campaigns` — All active campaigns with today's metrics\n" .
        "• `show paused campaigns` — All paused campaigns\n" .
        "• `show spend` — Today's spend summary per ad account\n" .
        "• `show top campaigns` — Top 5 campaigns by spend today\n" .
        "• `help` — Show this message\n\n" .
        "_All data is private — only your connected ad accounts are shown._";

    return ['response_type' => 'ephemeral', 'text' => $text];
}

function sendBotDm($channelId, $text, $blocks = []) {
    // Get bot token — try per-user first, fall back to global
    $db = Database::getInstance();
    $token = '';

    // Try the user's own Slack token
    $row = $db->fetchOne("SELECT access_token FROM user_slack_connections LIMIT 1");
    if ($row && !empty($row['access_token'])) {
        $token = $row['access_token'];
    }

    // Fallback to global bot token
    if (empty($token)) {
        $token = getenv('SLACK_BOT_TOKEN') ?: ($_ENV['SLACK_BOT_TOKEN'] ?? '');
    }

    if (empty($token)) return;

    $payload = [
        'channel' => $channelId,
        'text'    => $text,
    ];
    if (!empty($blocks)) {
        $payload['blocks'] = $blocks;
    }

    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
