<?php
/**
 * Slack OAuth Callback — per-user Slack bot integration
 * Uses bot token (chat:write) so the bot can post to any channel.
 * No channel picker during OAuth — channel is set via SLACK_CHANNEL env var.
 *
 * Slack app setup:
 *  - Bot Token Scopes: chat:write
 *  - Redirect URI: https://yourdomain.com/slack-oauth-callback.php
 *  - Env vars: SLACK_CLIENT_ID, SLACK_CLIENT_SECRET, SLACK_CHANNEL
 */
require_once __DIR__ . '/includes/Security.php';
Security::init();
Security::enforceHttps();
session_start();

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/database/Database.php';
require_once __DIR__ . '/includes/ActivityLogger.php';

$clientId     = getenv('SLACK_CLIENT_ID')     ?: ($_ENV['SLACK_CLIENT_ID'] ?? '');
$clientSecret = getenv('SLACK_CLIENT_SECRET') ?: ($_ENV['SLACK_CLIENT_SECRET'] ?? '');
$userId       = intval($_SESSION['user_id'] ?? 0);

// ── Disconnect action ──────────────────────────────────────
if (($_GET['action'] ?? '') === 'disconnect') {
    if ($userId) {
        try {
            $db = Database::getInstance();
            $db->delete('user_slack_connections', 'user_id = :uid', ['uid' => $userId]);
            ActivityLogger::log('slack_disconnect', 'slack-oauth-callback.php', [], 'success');
        } catch (Exception $e) {
            error_log("Slack disconnect error: " . $e->getMessage());
        }
    }
    header('Location: app-shell.php?slack=disconnected');
    exit;
}

// ── Initiate OAuth ─────────────────────────────────────────
if (empty($_GET['code'])) {
    if (empty($clientId)) {
        die('SLACK_CLIENT_ID is not configured. Please set it in your environment variables.');
    }
    // Generate state token to prevent CSRF
    $state = bin2hex(random_bytes(16));
    $_SESSION['slack_oauth_state'] = $state;

    $redirectUri = (Security::isHttps() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/slack-oauth-callback.php';
    $params = http_build_query([
        'client_id'    => $clientId,
        'scope'        => 'chat:write',
        'redirect_uri' => $redirectUri,
        'state'        => $state,
    ]);
    header('Location: https://slack.com/oauth/v2/authorize?' . $params);
    exit;
}

// ── Handle OAuth callback ──────────────────────────────────
$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

// Validate state
if ($state !== ($_SESSION['slack_oauth_state'] ?? '')) {
    die('Invalid state parameter. Please try connecting again.');
}
unset($_SESSION['slack_oauth_state']);

if (empty($clientId) || empty($clientSecret)) {
    die('Slack credentials (SLACK_CLIENT_ID, SLACK_CLIENT_SECRET) are not configured.');
}

$redirectUri = (Security::isHttps() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/slack-oauth-callback.php';

// Exchange code for bot access token
$ch = curl_init('https://slack.com/api/oauth.v2.access');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
    ]),
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (empty($data['ok'])) {
    $errMsg = $data['error'] ?? 'unknown_error';
    ActivityLogger::log('slack_connect_failed', 'slack-oauth-callback.php', ['error' => $errMsg], 'error');
    header('Location: app-shell.php?slack=error&msg=' . urlencode($errMsg));
    exit;
}

// Bot token flow — access_token is the bot token
$accessToken = $data['access_token']                     ?? '';
$teamId      = $data['team']['id']                       ?? '';
$teamName    = $data['team']['name']                     ?? '';
$botUserId   = $data['bot_user_id']                      ?? '';
$scope       = $data['scope']                            ?? '';
$authedUser  = $data['authed_user']['id']                ?? '';

// Channel from env var (bot posts to this channel)
$channel     = getenv('SLACK_CHANNEL') ?: ($_ENV['SLACK_CHANNEL'] ?? '');

if (empty($accessToken)) {
    ActivityLogger::log('slack_connect_failed', 'slack-oauth-callback.php', ['error' => 'no_access_token'], 'error');
    header('Location: app-shell.php?slack=error&msg=no_access_token');
    exit;
}

// Save to DB
try {
    $db = Database::getInstance();
    $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');

    if ($driver === 'pgsql') {
        $db->query(
            "INSERT INTO user_slack_connections
                (user_id, team_id, team_name, webhook_url, channel, channel_id, bot_user_id, scope, authed_user_id, access_token)
             VALUES (:uid, :tid, :tn, :wh, :ch, :chid, :buid, :sc, :auid, :at)
             ON CONFLICT (user_id) DO UPDATE SET
                team_id=EXCLUDED.team_id, team_name=EXCLUDED.team_name,
                webhook_url=EXCLUDED.webhook_url, channel=EXCLUDED.channel,
                channel_id=EXCLUDED.channel_id, bot_user_id=EXCLUDED.bot_user_id,
                scope=EXCLUDED.scope, authed_user_id=EXCLUDED.authed_user_id,
                access_token=EXCLUDED.access_token, updated_at=CURRENT_TIMESTAMP",
            ['uid'=>$userId,'tid'=>$teamId,'tn'=>$teamName,'wh'=>'',
             'ch'=>$channel,'chid'=>'','buid'=>$botUserId,
             'sc'=>$scope,'auid'=>$authedUser,'at'=>$accessToken]
        );
    } else {
        $db->query(
            "INSERT INTO user_slack_connections
                (user_id, team_id, team_name, webhook_url, channel, channel_id, bot_user_id, scope, authed_user_id, access_token)
             VALUES (:uid, :tid, :tn, :wh, :ch, :chid, :buid, :sc, :auid, :at)
             ON DUPLICATE KEY UPDATE
                team_id=VALUES(team_id), team_name=VALUES(team_name),
                webhook_url=VALUES(webhook_url), channel=VALUES(channel),
                channel_id=VALUES(channel_id), bot_user_id=VALUES(bot_user_id),
                scope=VALUES(scope), authed_user_id=VALUES(authed_user_id),
                access_token=VALUES(access_token), updated_at=CURRENT_TIMESTAMP",
            ['uid'=>$userId,'tid'=>$teamId,'tn'=>$teamName,'wh'=>'',
             'ch'=>$channel,'chid'=>'','buid'=>$botUserId,
             'sc'=>$scope,'auid'=>$authedUser,'at'=>$accessToken]
        );
    }
    ActivityLogger::log('slack_connected', 'slack-oauth-callback.php', ['team' => $teamName], 'success');
} catch (Exception $e) {
    error_log("Slack DB save error: " . $e->getMessage());
    ActivityLogger::log('slack_connect_failed', 'slack-oauth-callback.php', ['error' => $e->getMessage()], 'error');
    header('Location: app-shell.php?slack=error&msg=' . urlencode('db_save_failed'));
    exit;
}

header('Location: app-shell.php?slack=connected&team=' . urlencode($teamName));
exit;
