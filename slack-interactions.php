<?php
/**
 * Slack Interactions Handler
 * Receives button click payloads from Slack (e.g. "Resume Campaign")
 * and calls TikTok API to resume the paused campaign.
 *
 * Setup:
 *  - Slack app → Interactivity & Shortcuts → ON
 *  - Request URL: https://tiktok-launcher.onrender.com/slack-interactions.php
 *  - Env var: SLACK_SIGNING_SECRET (from Slack app → Basic Information → Signing Secret)
 */

require_once __DIR__ . '/database/Database.php';
require_once __DIR__ . '/includes/optimizer-functions.php';

// ── Verify Slack request signature ────────────────────────
function verifySlackSignature($signingSecret, $body) {
    $timestamp  = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '';
    $slackSig   = $_SERVER['HTTP_X_SLACK_SIGNATURE']         ?? '';

    if (empty($timestamp) || empty($slackSig)) {
        return false;
    }
    // Reject requests older than 5 minutes (replay attack protection)
    if (abs(time() - intval($timestamp)) > 300) {
        return false;
    }
    $baseString = "v0:$timestamp:$body";
    $myHash     = 'v0=' . hash_hmac('sha256', $baseString, $signingSecret);
    return hash_equals($myHash, $slackSig);
}

// ── Read raw body (must be done before any output) ────────
$rawBody = file_get_contents('php://input');

$signingSecret = getenv('SLACK_SIGNING_SECRET') ?: ($_ENV['SLACK_SIGNING_SECRET'] ?? '');
if (!empty($signingSecret)) {
    if (!verifySlackSignature($signingSecret, $rawBody)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// ── Parse payload ─────────────────────────────────────────
// Slack sends interactions as application/x-www-form-urlencoded with a "payload" field
parse_str($rawBody, $params);
$payloadJson = $params['payload'] ?? '';

if (empty($payloadJson)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payload']);
    exit;
}

$payload = json_decode($payloadJson, true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$actionId = $payload['actions'][0]['action_id'] ?? '';

// ── Handle Resume Campaign button ─────────────────────────
if ($actionId === 'resume_campaign') {
    $buttonValue = $payload['actions'][0]['value'] ?? '{}';
    $data        = json_decode($buttonValue, true) ?? [];
    $campaignId  = $data['campaign_id']  ?? '';
    $advertiserId = $data['advertiser_id'] ?? '';

    if (empty($campaignId) || empty($advertiserId)) {
        // Respond immediately to Slack (required within 3 seconds)
        http_response_code(200);
        echo json_encode([
            'response_type' => 'ephemeral',
            'text'          => '❌ Invalid button data — campaign_id or advertiser_id missing.'
        ]);
        exit;
    }

    // Get the access token for this advertiser from DB
    $accessToken = '';
    try {
        $db = Database::getInstance();
        $conn = $db->fetchOne(
            "SELECT access_token FROM tiktok_connections
             WHERE advertiser_id = :aid AND connection_status = 'active'
             ORDER BY updated_at DESC LIMIT 1",
            ['aid' => $advertiserId]
        );
        if ($conn) {
            $accessToken = $conn['access_token'];
        }
    } catch (Exception $e) {
        logOptimizer("slack-interactions: DB error fetching token: " . $e->getMessage());
    }

    // Fall back to global env token
    if (empty($accessToken)) {
        $accessToken = getenv('TIKTOK_ACCESS_TOKEN') ?: ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? '');
    }

    if (empty($accessToken)) {
        http_response_code(200);
        echo json_encode([
            'response_type' => 'ephemeral',
            'text'          => '❌ No TikTok access token found for this account. Please reconnect in the launcher.'
        ]);
        exit;
    }

    // Call TikTok API to resume the campaign
    $result = resumeCampaignViaApi($advertiserId, $campaignId, $accessToken);
    $success = !empty($result['success']);

    // Update optimizer DB — clear paused state
    if ($success) {
        try {
            $db = Database::getInstance();
            $db->query(
                "UPDATE optimizer_monitored_campaigns
                 SET paused_by_optimizer = 0,
                     paused_at           = NULL,
                     resume_at           = NULL,
                     review_notified     = 0,
                     last_checked_at     = NOW()
                 WHERE campaign_id = :cid AND advertiser_id = :aid",
                ['cid' => $campaignId, 'aid' => $advertiserId]
            );

            // Log the resume action
            $db->insert('optimizer_logs', [
                'campaign_id'   => $campaignId,
                'advertiser_id' => $advertiserId,
                'action'        => 'resume',
                'rule_key'      => 'slack_button',
                'rule_details'  => 'Resumed via Slack Resume button by ' . ($payload['user']['name'] ?? 'unknown'),
                'success'       => 1,
            ]);
        } catch (Exception $e) {
            logOptimizer("slack-interactions: DB update error: " . $e->getMessage());
        }
    }

    // ── Respond to Slack ───────────────────────────────────
    // Replace the original message with a confirmation
    $userName = $payload['user']['name'] ?? 'Someone';

    if ($success) {
        $responseMsg = [
            'replace_original' => true,
            'text'             => "✅ Campaign resumed by *$userName*.",
            'blocks'           => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "✅ *Campaign Resumed*\nResumed by *$userName* at " . date('Y-m-d H:i:s') . " UTC.\nCampaign ID: `$campaignId`"
                    ]
                ]
            ]
        ];
    } else {
        $errMsg = $result['error'] ?? 'TikTok API returned an error';
        $responseMsg = [
            'replace_original' => false,
            'response_type'    => 'ephemeral',
            'text'             => "❌ Failed to resume campaign `$campaignId`: $errMsg\n\nPlease resume it manually in TikTok Ads Manager.",
        ];
        logOptimizer("slack-interactions: Resume failed for $campaignId / $advertiserId: $errMsg");
    }

    // Respond to Slack's response_url to update the original message
    $responseUrl = $payload['response_url'] ?? '';
    if (!empty($responseUrl)) {
        $ch = curl_init($responseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($responseMsg),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // Always return 200 immediately to Slack
    http_response_code(200);
    echo '';
    exit;
}

// ── Unknown action — just acknowledge ─────────────────────
http_response_code(200);
echo '';
