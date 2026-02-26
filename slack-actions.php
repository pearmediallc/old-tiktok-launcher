<?php
/**
 * Slack Interactive Actions Handler
 * Handles button clicks from Slack messages (e.g., "Resume Campaign")
 *
 * Slack app must have Interactivity enabled with Request URL pointing to this file.
 * Configure at: https://api.slack.com/apps → Interactivity & Shortcuts
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/optimizer-functions.php';
require_once __DIR__ . '/database/Database.php';

// Load environment
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

// ============================================
// VERIFY SLACK REQUEST SIGNATURE
// ============================================

function verifySlackSignature() {
    $signingSecret = getenv('SLACK_SIGNING_SECRET') ?: ($_ENV['SLACK_SIGNING_SECRET'] ?? '');

    if (empty($signingSecret)) {
        logOptimizer("Slack signing secret not configured — skipping verification");
        return true; // Allow if not configured (dev mode)
    }

    $signature = $_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '';

    // Reject requests older than 5 minutes (replay attack prevention)
    if (abs(time() - intval($timestamp)) > 300) {
        logOptimizer("Slack request too old (timestamp: $timestamp)");
        return false;
    }

    $body = file_get_contents('php://input');
    $sigBasestring = "v0:$timestamp:$body";
    $mySignature = 'v0=' . hash_hmac('sha256', $sigBasestring, $signingSecret);

    return hash_equals($mySignature, $signature);
}

// ============================================
// MAIN HANDLER
// ============================================

// Slack sends interactive payloads as application/x-www-form-urlencoded with a 'payload' field
$rawBody = file_get_contents('php://input');

// Store raw body for signature verification, then parse
$_SERVER['SLACK_RAW_BODY'] = $rawBody;

// Parse the payload
if (!empty($_POST['payload'])) {
    $payload = json_decode($_POST['payload'], true);
} else {
    // Try parsing raw body manually
    parse_str($rawBody, $parsed);
    $payload = isset($parsed['payload']) ? json_decode($parsed['payload'], true) : null;
}

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Verify Slack signature
if (!verifySlackSignature()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Handle interactive actions
$actions = $payload['actions'] ?? [];
$responseUrl = $payload['response_url'] ?? '';
$user = $payload['user']['name'] ?? $payload['user']['username'] ?? 'unknown';

foreach ($actions as $action) {
    $actionId = $action['action_id'] ?? '';

    if ($actionId === 'resume_campaign') {
        handleResumeCampaign($action, $responseUrl, $user);
    }
}

// Acknowledge immediately (Slack requires response within 3 seconds)
http_response_code(200);
echo json_encode(['ok' => true]);

// ============================================
// RESUME CAMPAIGN HANDLER
// ============================================

function handleResumeCampaign($action, $responseUrl, $user) {
    $value = json_decode($action['value'] ?? '{}', true);
    $campaignId = $value['campaign_id'] ?? '';
    $advertiserId = $value['advertiser_id'] ?? '';

    if (empty($campaignId) || empty($advertiserId)) {
        logOptimizer("Slack resume: missing campaign_id or advertiser_id");
        updateSlackMessage($responseUrl, "❌ Error: Missing campaign data");
        return;
    }

    logOptimizer("Slack resume requested by $user for campaign $campaignId");

    // Get access token
    $accessToken = getenv('TIKTOK_ACCESS_TOKEN') ?: ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? '');

    if (empty($accessToken)) {
        // Try from database
        try {
            $db = Database::getInstance();
            $conn = $db->fetchOne(
                "SELECT access_token FROM tiktok_connections WHERE connection_status = 'active' ORDER BY updated_at DESC LIMIT 1"
            );
            if ($conn) {
                $accessToken = $conn['access_token'];
            }
        } catch (Exception $e) {
            logOptimizer("Slack resume: Error getting access token: " . $e->getMessage());
        }
    }

    if (empty($accessToken)) {
        logOptimizer("Slack resume: No access token available");
        updateSlackMessage($responseUrl, "❌ Error: No TikTok access token available");
        return;
    }

    // Resume the campaign via TikTok API
    $result = resumeCampaignViaApi($advertiserId, $campaignId, $accessToken);

    // Update database
    $db = Database::getInstance();

    // Get campaign name for the confirmation message
    $mc = $db->fetchOne(
        "SELECT campaign_name FROM optimizer_monitored_campaigns WHERE campaign_id = ? AND advertiser_id = ?",
        [$campaignId, $advertiserId]
    );
    $campaignName = $mc['campaign_name'] ?? $campaignId;

    if ($result['success']) {
        // Clear pause state
        $db->query(
            "UPDATE optimizer_monitored_campaigns SET paused_by_optimizer = 0, paused_at = NULL, resume_at = NULL, review_notified = 0, last_checked_at = NOW(), last_violation_rule = NULL WHERE campaign_id = ? AND advertiser_id = ?",
            [$campaignId, $advertiserId]
        );

        // Log the resume
        $db->insert('optimizer_logs', [
            'campaign_id' => $campaignId,
            'advertiser_id' => $advertiserId,
            'action' => 'resume',
            'rule_key' => null,
            'rule_details' => "Manually resumed via Slack by $user",
            'api_response' => json_encode($result['response']),
            'success' => 1,
        ]);

        $resumeTime = gmdate('g:i A') . ' UTC';
        updateSlackMessage($responseUrl, "✅ *Campaign Resumed*\n$campaignName was resumed by *$user* at $resumeTime");
        logOptimizer("Campaign $campaignId resumed via Slack by $user");
    } else {
        updateSlackMessage($responseUrl, "❌ *Resume Failed*\nCould not resume $campaignName — check TikTok API logs");
        logOptimizer("Slack resume FAILED for campaign $campaignId", $result);
    }
}

// ============================================
// UPDATE SLACK MESSAGE (replace buttons with result)
// ============================================

function updateSlackMessage($responseUrl, $text) {
    if (empty($responseUrl)) return;

    $payload = json_encode([
        'replace_original' => true,
        'blocks' => [
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $text]
            ]
        ],
        'text' => strip_tags($text),
    ]);

    $ch = curl_init($responseUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        logOptimizer("Slack message update CURL Error: " . curl_error($ch));
    }
    curl_close($ch);
}
