<?php
/**
 * Slack Slash Command Handlers
 * Fetches TikTok campaign data for the requesting user's accounts.
 *
 * Flow: Slack user_id → user_slack_connections.authed_user_id → user_id
 *       → tiktok_connections (all accounts for that user) → TikTok API
 */

require_once __DIR__ . '/../database/Database.php';

/**
 * Make a TikTok Business API call (standalone version for slash commands)
 */
function tiktokApiCall($endpoint, $params, $accessToken, $method = 'GET') {
    $url = "https://business-api.tiktok.com/open_api/v1.3" . $endpoint;
    $ch = curl_init();

    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Access-Token: $accessToken", "Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) { curl_close($ch); return null; }
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Get the launcher user_id from a Slack user ID
 */
function getUserIdFromSlack($slackUserId) {
    $db = Database::getInstance();

    // Try matching by authed_user_id (set during OAuth)
    $row = $db->fetchOne(
        "SELECT user_id FROM user_slack_connections WHERE authed_user_id = :sid LIMIT 1",
        ['sid' => $slackUserId]
    );
    if ($row) return intval($row['user_id']);

    // Fallback: if only one Slack connection exists, use it (single-user setup)
    $row = $db->fetchOne(
        "SELECT user_id FROM user_slack_connections LIMIT 1"
    );
    return $row ? intval($row['user_id']) : null;
}

/**
 * Get all active TikTok connections for a user.
 * Falls back to ALL active connections if none found for the specific user_id
 * (handles pre-multi-user connections that may have user_id=0 or user_id=1).
 */
function getUserTikTokAccounts($userId) {
    $db = Database::getInstance();

    // Try user's own connections first
    $accounts = $db->fetchAll(
        "SELECT advertiser_id, advertiser_name, access_token
         FROM tiktok_connections
         WHERE user_id = :uid AND connection_status = 'active'
         ORDER BY advertiser_name ASC",
        ['uid' => $userId]
    );

    if (!empty($accounts)) return $accounts;

    // Fallback: try connections with user_id=0 (pre-multi-user legacy)
    $accounts = $db->fetchAll(
        "SELECT advertiser_id, advertiser_name, access_token
         FROM tiktok_connections
         WHERE user_id = 0 AND connection_status = 'active'
         ORDER BY advertiser_name ASC"
    );

    if (!empty($accounts)) return $accounts;

    // Fallback 2: get any active connection (single-user setup)
    return $db->fetchAll(
        "SELECT advertiser_id, advertiser_name, access_token
         FROM tiktok_connections
         WHERE connection_status = 'active'
         ORDER BY advertiser_name ASC
         LIMIT 50"
    );
}

/**
 * Fetch campaigns for one advertiser with today's metrics
 */
function fetchCampaignsForAdvertiser($advertiserId, $accessToken, $statusFilter = null) {
    $today = date('Y-m-d');

    // Get campaigns
    $params = ['advertiser_id' => $advertiserId, 'page' => 1, 'page_size' => 100];
    if ($statusFilter) {
        $params['filtering'] = json_encode(['operation_status' => $statusFilter]);
    }
    $result = tiktokApiCall('/campaign/get/', $params, $accessToken, 'GET');
    if (!$result || ($result['code'] ?? -1) != 0) return [];

    $campaigns = $result['data']['list'] ?? [];
    if (empty($campaigns)) return [];

    // Get metrics
    $campaignIds = array_column($campaigns, 'campaign_id');
    $reportParams = [
        'advertiser_id' => $advertiserId,
        'report_type'   => 'BASIC',
        'dimensions'    => json_encode(['campaign_id']),
        'metrics'       => json_encode(['spend', 'impressions', 'clicks', 'ctr', 'cpc', 'conversion', 'cost_per_conversion']),
        'data_level'    => 'AUCTION_CAMPAIGN',
        'start_date'    => $today,
        'end_date'      => $today,
        'page'          => 1,
        'page_size'     => 100,
        'filters'       => json_encode([[
            'field_name'  => 'campaign_ids',
            'filter_type' => 'IN',
            'filter_value' => json_encode($campaignIds)
        ]])
    ];
    $reportResult = tiktokApiCall('/report/integrated/get/', $reportParams, $accessToken, 'GET');
    $metricsMap = [];
    if ($reportResult && ($reportResult['code'] ?? -1) == 0) {
        foreach ($reportResult['data']['list'] ?? [] as $row) {
            $cid = $row['dimensions']['campaign_id'] ?? '';
            if ($cid) $metricsMap[$cid] = $row['metrics'] ?? [];
        }
    }

    // Merge
    $formatted = [];
    foreach ($campaigns as $c) {
        $cid = $c['campaign_id'];
        $m = $metricsMap[$cid] ?? [];
        $formatted[] = [
            'campaign_name' => $c['campaign_name'] ?? 'Unknown',
            'campaign_id'   => $cid,
            'status'        => $c['operation_status'] ?? 'UNKNOWN',
            'budget'        => floatval($c['budget'] ?? 0),
            'spend'         => floatval($m['spend'] ?? 0),
            'impressions'   => intval($m['impressions'] ?? 0),
            'clicks'        => intval($m['clicks'] ?? 0),
            'ctr'           => floatval($m['ctr'] ?? 0),
            'cpc'           => floatval($m['cpc'] ?? 0),
            'conversions'   => intval($m['conversion'] ?? 0),
            'cost_per_conv' => floatval($m['cost_per_conversion'] ?? 0),
        ];
    }

    // Sort by spend descending
    usort($formatted, fn($a, $b) => $b['spend'] <=> $a['spend']);
    return $formatted;
}

// ============================================
// COMMAND HANDLERS
// ============================================

/**
 * /campaigns active — Show all active campaigns
 */
function handleActiveCampaigns($userId) {
    $accounts = getUserTikTokAccounts($userId);
    if (empty($accounts)) {
        return buildTextResponse("You don't have any TikTok ad accounts connected. Please connect in the launcher first.");
    }

    $blocks = [
        ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "Active Campaigns — Today's Data"]],
    ];

    $totalSpend = 0;
    $totalConv = 0;

    foreach ($accounts as $account) {
        $campaigns = fetchCampaignsForAdvertiser($account['advertiser_id'], $account['access_token'], 'ENABLE');
        $acctName = $account['advertiser_name'] ?: $account['advertiser_id'];

        if (empty($campaigns)) {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*{$acctName}*\nNo active campaigns"]];
            $blocks[] = ['type' => 'divider'];
            continue;
        }

        $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*{$acctName}* — " . count($campaigns) . " active"]];

        $lines = [];
        foreach ($campaigns as $c) {
            $spend = number_format($c['spend'], 2);
            $conv = $c['conversions'];
            $cpc = number_format($c['cpc'], 2);
            $ctr = number_format($c['ctr'] * 100, 2);
            $totalSpend += $c['spend'];
            $totalConv += $conv;

            $lines[] = "`\${$spend}` spend · {$conv} conv · \${$cpc} CPC · {$ctr}% CTR — {$c['campaign_name']}";
        }

        // Slack blocks have 3000 char limit per text field, chunk if needed
        $chunk = '';
        foreach ($lines as $line) {
            if (strlen($chunk) + strlen($line) + 1 > 2800) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $chunk]];
                $chunk = '';
            }
            $chunk .= $line . "\n";
        }
        if (!empty($chunk)) {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $chunk]];
        }
        $blocks[] = ['type' => 'divider'];
    }

    // Summary
    $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' =>
        "*Total Today:* `\$" . number_format($totalSpend, 2) . "` spend · {$totalConv} conversions"
    ]];

    return ['response_type' => 'ephemeral', 'text' => 'Active campaigns', 'blocks' => $blocks];
}

/**
 * /campaigns paused — Show all paused campaigns
 */
function handlePausedCampaigns($userId) {
    $accounts = getUserTikTokAccounts($userId);
    if (empty($accounts)) {
        return buildTextResponse("You don't have any TikTok ad accounts connected.");
    }

    $blocks = [
        ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "Paused Campaigns"]],
    ];

    foreach ($accounts as $account) {
        $campaigns = fetchCampaignsForAdvertiser($account['advertiser_id'], $account['access_token'], 'DISABLE');
        $acctName = $account['advertiser_name'] ?: $account['advertiser_id'];

        if (empty($campaigns)) {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*{$acctName}*\nNo paused campaigns"]];
            continue;
        }

        $lines = "*{$acctName}* — " . count($campaigns) . " paused\n";
        foreach ($campaigns as $c) {
            $budget = number_format($c['budget'], 2);
            $lines .= "• {$c['campaign_name']} (budget: \${$budget})\n";
        }
        $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $lines]];
        $blocks[] = ['type' => 'divider'];
    }

    return ['response_type' => 'ephemeral', 'text' => 'Paused campaigns', 'blocks' => $blocks];
}

/**
 * /campaigns spend — Today's spend summary per account
 */
function handleSpendSummary($userId) {
    $accounts = getUserTikTokAccounts($userId);
    if (empty($accounts)) {
        return buildTextResponse("You don't have any TikTok ad accounts connected.");
    }

    $blocks = [
        ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "Today's Spend Summary"]],
    ];

    $grandTotal = 0;
    $grandConv = 0;

    foreach ($accounts as $account) {
        $campaigns = fetchCampaignsForAdvertiser($account['advertiser_id'], $account['access_token']);
        $acctName = $account['advertiser_name'] ?: $account['advertiser_id'];

        $acctSpend = 0;
        $acctConv = 0;
        $activeCnt = 0;
        foreach ($campaigns as $c) {
            $acctSpend += $c['spend'];
            $acctConv += $c['conversions'];
            if ($c['status'] === 'ENABLE') $activeCnt++;
        }
        $grandTotal += $acctSpend;
        $grandConv += $acctConv;

        $blocks[] = ['type' => 'section', 'fields' => [
            ['type' => 'mrkdwn', 'text' => "*{$acctName}*"],
            ['type' => 'mrkdwn', 'text' => "`\$" . number_format($acctSpend, 2) . "` spend"],
            ['type' => 'mrkdwn', 'text' => "{$acctConv} conversions"],
            ['type' => 'mrkdwn', 'text' => "{$activeCnt} active / " . count($campaigns) . " total"],
        ]];
    }

    $blocks[] = ['type' => 'divider'];
    $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' =>
        "*Grand Total:* `\$" . number_format($grandTotal, 2) . "` spent · {$grandConv} conversions across " . count($accounts) . " accounts"
    ]];

    return ['response_type' => 'ephemeral', 'text' => 'Spend summary', 'blocks' => $blocks];
}

/**
 * /campaigns top — Top 5 campaigns by spend today
 */
function handleTopCampaigns($userId) {
    $accounts = getUserTikTokAccounts($userId);
    if (empty($accounts)) {
        return buildTextResponse("You don't have any TikTok ad accounts connected.");
    }

    $allCampaigns = [];
    $acctNames = [];
    foreach ($accounts as $account) {
        $acctNames[$account['advertiser_id']] = $account['advertiser_name'] ?: $account['advertiser_id'];
        $campaigns = fetchCampaignsForAdvertiser($account['advertiser_id'], $account['access_token']);
        foreach ($campaigns as $c) {
            $c['account_name'] = $acctNames[$account['advertiser_id']];
            $allCampaigns[] = $c;
        }
    }

    // Sort all by spend desc, take top 5
    usort($allCampaigns, fn($a, $b) => $b['spend'] <=> $a['spend']);
    $top = array_slice($allCampaigns, 0, 5);

    if (empty($top) || $top[0]['spend'] == 0) {
        return buildTextResponse("No spend recorded today across your accounts.");
    }

    $blocks = [
        ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "Top 5 Campaigns by Spend Today"]],
    ];

    $lines = '';
    foreach ($top as $i => $c) {
        $rank = $i + 1;
        $spend = number_format($c['spend'], 2);
        $conv = $c['conversions'];
        $cpc = number_format($c['cpc'], 2);
        $ctr = number_format($c['ctr'] * 100, 2);
        $cpConv = $c['conversions'] > 0 ? '$' . number_format($c['cost_per_conv'], 2) : '-';
        $lines .= "*{$rank}.* `\${$spend}` · {$conv} conv · \${$cpc} CPC · {$ctr}% CTR · {$cpConv}/conv\n     _{$c['campaign_name']}_ ({$c['account_name']})\n";
    }
    $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $lines]];

    return ['response_type' => 'ephemeral', 'text' => 'Top campaigns', 'blocks' => $blocks];
}

/**
 * /campaigns help — Show available commands
 */
function handleHelp() {
    return buildTextResponse(
        "*Available Commands:*\n" .
        "• `/campaigns active` — Show all active campaigns with today's metrics\n" .
        "• `/campaigns paused` — Show all paused campaigns\n" .
        "• `/campaigns spend` — Today's spend summary per ad account\n" .
        "• `/campaigns top` — Top 5 campaigns by spend today\n" .
        "• `/campaigns help` — Show this help message\n\n" .
        "_Data shown is for today only. Only your connected ad accounts are visible._"
    );
}

function buildTextResponse($text) {
    return ['response_type' => 'ephemeral', 'text' => $text];
}
