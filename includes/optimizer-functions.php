<?php
/**
 * Optimizer Core Functions
 * - TikTok metrics fetching
 * - RedTrack API integration
 * - Rule evaluation engine
 * - Pause/Resume campaign actions
 */

require_once __DIR__ . '/../database/Database.php';

// ============================================
// LOGGING
// ============================================

function logOptimizer($message, $data = null) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/optimizer_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    if ($data !== null) {
        $logMessage .= "\n" . json_encode($data, JSON_PRETTY_PRINT);
    }
    @file_put_contents($logFile, "$logMessage\n", FILE_APPEND);
    // Also log to stderr for Render.com log viewer
    @file_put_contents('php://stderr', "[optimizer] $logMessage\n");
}

// ============================================
// TIKTOK API HELPER
// ============================================

function optimizerTikTokApi($endpoint, $params, $accessToken, $method = 'GET') {
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
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Access-Token: " . $accessToken,
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        logOptimizer("TikTok API CURL Error: $err");
        return ['code' => -1, 'message' => 'Network error: ' . $err, 'data' => null];
    }
    curl_close($ch);

    $result = json_decode($response, true);
    return $result ?? ['code' => -1, 'message' => 'Empty response', 'data' => null];
}

// ============================================
// REDTRACK API HELPER
// ============================================

function redtrackApiCall($endpoint, $params = [], $method = 'GET') {
    // SAFETY: RedTrack is READ-ONLY — only GET requests allowed
    if (strtoupper($method) !== 'GET') {
        logOptimizer("BLOCKED: RedTrack only allows GET requests. Attempted: $method $endpoint");
        return null;
    }

    $apiToken = getenv('REDTRACK_API_TOKEN') ?: ($_ENV['REDTRACK_API_TOKEN'] ?? '');
    $apiUrl = getenv('REDTRACK_API_URL') ?: ($_ENV['REDTRACK_API_URL'] ?? 'https://api.redtrack.io');

    if (empty($apiToken)) {
        logOptimizer("RedTrack API token not configured");
        return null;
    }

    // RedTrack uses api_key as a query parameter for authentication
    $params['api_key'] = $apiToken;

    $url = rtrim($apiUrl, '/') . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    // GET only — no POST/PUT/DELETE/PATCH allowed
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        logOptimizer("RedTrack CURL Error: $err");
        return null;
    }
    curl_close($ch);

    $decoded = json_decode($response, true);

    // Log non-200 responses or API errors for debugging
    if ($httpCode !== 200) {
        logOptimizer("RedTrack API HTTP $httpCode for $endpoint", ['response' => substr($response, 0, 500)]);
    }

    return $decoded;
}

// Look up a RedTrack campaign ID by campaign name/title
function lookupRedTrackCampaignId($campaignName) {
    if (empty($campaignName)) return null;

    $result = redtrackApiCall('/campaigns', [
        'title' => $campaignName,
    ]);

    if (empty($result)) {
        logOptimizer("RedTrack: No response when looking up campaign '$campaignName'");
        return null;
    }

    // Response can be array of campaigns or {items: [...]}
    $campaigns = isset($result['items']) ? $result['items'] : (is_array($result) ? $result : []);

    foreach ($campaigns as $campaign) {
        $title = $campaign['title'] ?? $campaign['name'] ?? '';
        if (strcasecmp($title, $campaignName) === 0) {
            $id = $campaign['id'] ?? null;
            logOptimizer("RedTrack: Found campaign '$campaignName' with ID: $id");
            return $id;
        }
    }

    // If exact match not found, use first result (partial match)
    if (!empty($campaigns[0]['id'])) {
        $id = $campaigns[0]['id'];
        $title = $campaigns[0]['title'] ?? $campaigns[0]['name'] ?? 'unknown';
        logOptimizer("RedTrack: Using closest match for '$campaignName': '$title' (ID: $id)");
        return $id;
    }

    logOptimizer("RedTrack: Campaign '$campaignName' not found");
    return null;
}

// ============================================
// FETCH TIKTOK CAMPAIGN METRICS (today)
// ============================================

function fetchTikTokCampaignMetrics($advertiserId, $campaignId, $accessToken) {
    $today = date('Y-m-d');

    // Match the exact format used by the working campaigns view API call
    // Key fixes: removed service_type (excluded Smart+ campaigns), fixed filter format,
    // removed stat_time_day dimension, fixed metric name conversion vs conversions
    $filters = [
        [
            'field_name' => 'campaign_ids',
            'filter_type' => 'IN',
            'filter_value' => json_encode([$campaignId])
        ]
    ];

    $result = optimizerTikTokApi('/report/integrated/get/', [
        'advertiser_id' => $advertiserId,
        'report_type' => 'BASIC',
        'data_level' => 'AUCTION_CAMPAIGN',
        'dimensions' => json_encode(['campaign_id']),
        'metrics' => json_encode(['spend', 'cpc', 'impressions', 'clicks', 'ctr', 'conversion', 'conversion_rate']),
        'start_date' => $today,
        'end_date' => $today,
        'page' => 1,
        'page_size' => 10,
        'filters' => json_encode($filters)
    ], $accessToken, 'GET');

    if (($result['code'] ?? -1) != 0 || empty($result['data']['list'])) {
        logOptimizer("No TikTok metrics for campaign $campaignId", $result);
        return [
            'spend' => 0, 'cpc' => 0, 'impressions' => 0,
            'clicks' => 0, 'ctr' => 0, 'conversions' => 0
        ];
    }

    $row = $result['data']['list'][0]['metrics'] ?? [];
    return [
        'spend' => floatval($row['spend'] ?? 0),
        'cpc' => floatval($row['cpc'] ?? 0),
        'impressions' => intval($row['impressions'] ?? 0),
        'clicks' => intval($row['clicks'] ?? 0),
        'ctr' => floatval($row['ctr'] ?? 0),
        'conversions' => intval($row['conversion'] ?? $row['conversions'] ?? 0),
    ];
}

// ============================================
// FETCH REDTRACK METRICS FOR CAMPAIGN
// ============================================

function fetchRedTrackCampaignMetrics($campaignId, $redtrackCampaignName = null) {
    $today = date('Y-m-d');
    $defaultMetrics = ['lp_ctr' => 0, 'ctr' => 0, 'conversions' => 0, 'revenue' => 0, 'cost' => 0, 'profit' => 0, 'lp_clicks' => 0, 'lp_views' => 0];

    // If a RedTrack campaign name is provided, look up by campaign name
    if (!empty($redtrackCampaignName)) {
        $rtCampaignId = lookupRedTrackCampaignId($redtrackCampaignName);
        if ($rtCampaignId) {
            // Use /campaigns with total_stat to get aggregated campaign metrics (LP CTR, clicks, etc.)
            $result = redtrackApiCall('/campaigns', [
                'ids' => $rtCampaignId,
                'total_stat' => 'true',
                'date_from' => $today,
                'date_to' => $today,
            ]);

            logOptimizer("RedTrack /campaigns response for '$redtrackCampaignName' (ID: $rtCampaignId)", $result);

            // total_stat=true returns {total, items} format
            $items = $result['items'] ?? $result ?? [];
            if (!empty($items) && is_array($items)) {
                $campaign = is_array($items[0] ?? null) ? $items[0] : $items;
                // RedTrack may use "stat" (singular) or "stats" (plural) or metrics at root level
                $stats = $campaign['stat'] ?? $campaign['stats'] ?? $campaign;
                $lp_clicks = intval($stats['lp_clicks'] ?? 0);
                $lp_views = intval($stats['lp_views'] ?? 0);
                $lp_ctr = ($lp_views > 0) ? round(($lp_clicks / $lp_views) * 100, 2) : 0;

                return [
                    'lp_ctr' => $lp_ctr,
                    'ctr' => floatval($stats['ctr'] ?? 0),
                    'conversions' => intval($stats['conversions'] ?? 0),
                    'revenue' => floatval($stats['revenue'] ?? 0),
                    'cost' => floatval($stats['cost'] ?? 0),
                    'profit' => floatval($stats['profit'] ?? 0),
                    'lp_clicks' => $lp_clicks,
                    'lp_views' => $lp_views,
                ];
            }

            // Fallback: try /conversions endpoint for conversion-level data
            $convResult = redtrackApiCall('/conversions', [
                'date_from' => $today,
                'date_to' => $today,
                'campaign_id' => $rtCampaignId,
            ]);

            logOptimizer("RedTrack /conversions response for '$redtrackCampaignName' (ID: $rtCampaignId)", $convResult);

            $convItems = $convResult['items'] ?? $convResult['data'] ?? [];
            if (!empty($convItems) && is_array($convItems)) {
                // Aggregate conversion data
                $totalConversions = count($convItems);
                $totalRevenue = 0;
                $totalCost = 0;
                foreach ($convItems as $conv) {
                    $totalRevenue += floatval($conv['revenue'] ?? $conv['amount'] ?? 0);
                    $totalCost += floatval($conv['cost'] ?? 0);
                }
                return array_merge($defaultMetrics, [
                    'conversions' => $totalConversions,
                    'revenue' => $totalRevenue,
                    'cost' => $totalCost,
                    'profit' => $totalRevenue - $totalCost,
                ]);
            }

            logOptimizer("No RedTrack data for campaign '$redtrackCampaignName' (ID: $rtCampaignId)");
            return $defaultMetrics;
        }
        logOptimizer("Could not find RedTrack campaign '$redtrackCampaignName', falling back to sub2 lookup");
    }

    // Fallback: try /conversions filtered by sub2 = TikTok campaign ID
    $result = redtrackApiCall('/conversions', [
        'date_from' => $today,
        'date_to' => $today,
        'sub2' => $campaignId,
    ]);

    $items = $result['items'] ?? $result['data'] ?? [];
    if (empty($items)) {
        logOptimizer("No RedTrack data for campaign $campaignId (sub2 fallback)");
        return $defaultMetrics;
    }

    // Aggregate conversion data from sub2 fallback
    $totalConversions = count($items);
    $totalRevenue = 0;
    $totalCost = 0;
    foreach ($items as $conv) {
        $totalRevenue += floatval($conv['revenue'] ?? $conv['amount'] ?? 0);
        $totalCost += floatval($conv['cost'] ?? 0);
    }

    return array_merge($defaultMetrics, [
        'conversions' => $totalConversions,
        'revenue' => $totalRevenue,
        'cost' => $totalCost,
        'profit' => $totalRevenue - $totalCost,
    ]);
}

// ============================================
// EVALUATE RULES FOR A CAMPAIGN
// ============================================

function evaluateCampaignRules($tiktokMetrics, $redtrackMetrics, $rules) {
    $violations = [];

    foreach ($rules as $rule) {
        if (!$rule['enabled']) continue;

        $source = $rule['metric_source'];
        $metrics = ($source === 'tiktok') ? $tiktokMetrics : $redtrackMetrics;
        $field = $rule['metric_field'];
        $value = floatval($metrics[$field] ?? 0);
        $threshold = floatval($rule['threshold']);
        $operator = $rule['operator'];

        // Primary condition check
        $primaryViolated = checkCondition($value, $operator, $threshold);

        // Secondary condition (e.g., spend >= 30 AND conversions == 0)
        if ($primaryViolated && !empty($rule['secondary_metric'])) {
            $secField = $rule['secondary_metric'];
            $secValue = floatval($metrics[$secField] ?? 0);
            $secThreshold = floatval($rule['secondary_threshold']);
            $secOperator = $rule['secondary_operator'];

            if (!checkCondition($secValue, $secOperator, $secThreshold)) {
                $primaryViolated = false; // Secondary not met, so rule not violated
            }
        }

        if ($primaryViolated) {
            $violations[] = [
                'rule_key' => $rule['rule_key'],
                'rule_name' => $rule['rule_name'],
                'metric_value' => $value,
                'threshold' => $threshold,
                'operator' => $operator,
                'details' => buildRuleDetails($rule, $metrics),
            ];
        }
    }

    return $violations;
}

function checkCondition($value, $operator, $threshold) {
    switch ($operator) {
        case 'gt':  return $value > $threshold;
        case 'lt':  return $value < $threshold;
        case 'gte': return $value >= $threshold;
        case 'lte': return $value <= $threshold;
        case 'eq':  return $value == $threshold;
        default: return false;
    }
}

function buildRuleDetails($rule, $metrics) {
    $opLabels = ['gt' => '>', 'lt' => '<', 'gte' => '>=', 'lte' => '<=', 'eq' => '='];
    $field = $rule['metric_field'];
    $value = round(floatval($metrics[$field] ?? 0), 2);
    $op = $opLabels[$rule['operator']] ?? '?';
    $threshold = $rule['threshold'];

    $detail = "{$rule['rule_name']}: {$field} is {$value} ({$op} {$threshold})";

    if (!empty($rule['secondary_metric'])) {
        $secField = $rule['secondary_metric'];
        $secValue = round(floatval($metrics[$secField] ?? 0), 2);
        $secOp = $opLabels[$rule['secondary_operator']] ?? '?';
        $secThreshold = $rule['secondary_threshold'];
        $detail .= " AND {$secField} is {$secValue} ({$secOp} {$secThreshold})";
    }

    return $detail;
}

// ============================================
// PAUSE CAMPAIGN VIA TIKTOK API
// ============================================

function pauseCampaignViaApi($advertiserId, $campaignId, $accessToken) {
    $result = optimizerTikTokApi('/campaign/status/update/', [
        'advertiser_id' => $advertiserId,
        'campaign_ids' => [$campaignId],
        'operation_status' => 'DISABLE',
    ], $accessToken, 'POST');

    $success = ($result['code'] ?? -1) == 0;
    logOptimizer("Pause campaign $campaignId: " . ($success ? 'SUCCESS' : 'FAILED'), $result);
    return ['success' => $success, 'response' => $result];
}

// ============================================
// SEND SLACK PAUSE NOTIFICATION
// ============================================

function sendSlackPauseNotification($campaignName, $campaignId, $ruleKey, $violationDetails, $ruleGroup, $resumeAt, $advertiserId = '', $metricsSnapshot = null) {
    $slackToken = getenv('SLACK_BOT_TOKEN') ?: ($_ENV['SLACK_BOT_TOKEN'] ?? '');
    $channelId = 'C0AC3899K6C';

    if (empty($slackToken)) {
        logOptimizer("Slack notification skipped: SLACK_BOT_TOKEN not configured");
        return false;
    }

    // Format rule group for display
    $ruleGroupDisplay = match($ruleGroup) {
        'home_insurance' => 'Home Insurance',
        'medicare' => 'Medicare',
        default => ucwords(str_replace('_', ' ', $ruleGroup))
    };

    // Format phase for display
    $phase = $metricsSnapshot['phase'] ?? 'phase1';
    $phaseDisplay = ($phase === 'phase2') ? 'Phase 2 (Profitability)' : 'Phase 1 (Qualification)';

    // Format rule key for display
    $ruleKeyDisplay = match($ruleKey) {
        'phase1_no_conversion' => 'No Conversion + High CPC',
        'phase2_loss_limit' => 'Loss Limit Reached (-$30)',
        default => ucwords(str_replace('_', ' ', preg_replace('/^(hi|med)_/', '', $ruleKey)))
    };

    $fallbackText = "Campaign Paused: $campaignName ($campaignId) — $phaseDisplay — $violationDetails";

    // Resume button value
    $buttonValue = json_encode([
        'campaign_id' => $campaignId,
        'advertiser_id' => $advertiserId,
    ]);

    // Build metrics display from snapshot
    $metricsText = '';
    if ($metricsSnapshot) {
        $tt = $metricsSnapshot['tiktok'] ?? [];
        $rt = $metricsSnapshot['redtrack'] ?? [];
        $profit = $metricsSnapshot['profit'] ?? 0;
        $profitFormatted = number_format($profit, 2);
        $profitEmoji = $profit >= 0 ? '🟢' : '🔴';

        $metricsText = "*📊 Campaign Metrics:*\n"
            . "• Spend: *\$" . number_format(floatval($tt['spend'] ?? 0), 2) . "*\n"
            . "• CPC: *\$" . number_format(floatval($tt['cpc'] ?? 0), 2) . "*\n"
            . "• CTR: *" . number_format(floatval($tt['ctr'] ?? 0), 2) . "%*\n"
            . "• Clicks: *" . intval($tt['clicks'] ?? 0) . "*\n"
            . "• Conversions (TikTok): *" . intval($tt['conversions'] ?? 0) . "*\n"
            . "• Revenue (RedTrack): *\$" . number_format(floatval($rt['revenue'] ?? 0), 2) . "*\n"
            . "• $profitEmoji Profit: *\${$profitFormatted}*";
    }

    $blocks = [
        [
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => '🚨 Campaign Paused by Optimizer']
        ],
        [
            'type' => 'section',
            'fields' => [
                ['type' => 'mrkdwn', 'text' => "*Campaign:*\n$campaignName"],
                ['type' => 'mrkdwn', 'text' => "*Campaign ID:*\n$campaignId"],
            ]
        ],
        [
            'type' => 'section',
            'fields' => [
                ['type' => 'mrkdwn', 'text' => "*Phase:*\n$phaseDisplay"],
                ['type' => 'mrkdwn', 'text' => "*Rule Violated:*\n$ruleKeyDisplay"],
            ]
        ],
        [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => "*Reason:*\n$violationDetails"]
        ],
    ];

    // Add metrics block if available
    if (!empty($metricsText)) {
        $blocks[] = [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $metricsText]
        ];
    }

    $blocks[] = ['type' => 'divider'];
    $blocks[] = [
        'type' => 'actions',
        'elements' => [
            [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => '▶️ Resume Campaign'],
                'style' => 'primary',
                'action_id' => 'resume_campaign',
                'value' => $buttonValue,
                'confirm' => [
                    'title' => ['type' => 'plain_text', 'text' => 'Resume Campaign?'],
                    'text' => ['type' => 'mrkdwn', 'text' => "This will re-enable *$campaignName* on TikTok."],
                    'confirm' => ['type' => 'plain_text', 'text' => 'Resume'],
                    'deny' => ['type' => 'plain_text', 'text' => 'Cancel'],
                ]
            ]
        ]
    ];
    $blocks[] = [
        'type' => 'context',
        'elements' => [
            ['type' => 'mrkdwn', 'text' => "⏱ Review notification will be sent at *$resumeAt UTC*"]
        ]
    ];

    $payload = json_encode([
        'channel' => $channelId,
        'text' => $fallbackText,
        'blocks' => $blocks,
    ]);

    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $slackToken",
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        logOptimizer("Slack CURL Error: $err");
        return false;
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (empty($result['ok'])) {
        logOptimizer("Slack API Error: " . ($result['error'] ?? 'unknown'), $result);
        return false;
    }

    logOptimizer("Slack notification sent for campaign $campaignId");
    return true;
}

// ============================================
// SEND SLACK PAUSE FAILURE NOTIFICATION
// ============================================

function sendSlackPauseFailureNotification($campaignName, $campaignId, $ruleKey, $violationDetails) {
    $slackToken = getenv('SLACK_BOT_TOKEN') ?: ($_ENV['SLACK_BOT_TOKEN'] ?? '');
    $channelId = 'C0AC3899K6C';

    if (empty($slackToken)) {
        logOptimizer("Slack failure notification skipped: SLACK_BOT_TOKEN not configured");
        return false;
    }

    $ruleKeyDisplay = preg_replace('/^(hi|med)_/', '', $ruleKey);
    $ruleKeyDisplay = ucwords(str_replace('_', ' ', $ruleKeyDisplay));

    $fallbackText = "FAILED TO PAUSE: $campaignName ($campaignId) — Rule: $ruleKeyDisplay — TikTok API rejected the pause request. Manual action required.";

    $blocks = [
        [
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => "⚠️ PAUSE FAILED — Manual Action Required"]
        ],
        [
            'type' => 'section',
            'fields' => [
                ['type' => 'mrkdwn', 'text' => "*Campaign:*\n$campaignName"],
                ['type' => 'mrkdwn', 'text' => "*Campaign ID:*\n$campaignId"],
            ]
        ],
        [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => "*Rule Violated:*\n$ruleKeyDisplay\n\n*Details:*\n$violationDetails"]
        ],
        ['type' => 'divider'],
        [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => "❗ *The TikTok API failed to pause this campaign.* Please pause it manually in TikTok Ads Manager."]
        ],
    ];

    $payload = json_encode([
        'channel' => $channelId,
        'text' => $fallbackText,
        'blocks' => $blocks,
    ]);

    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $slackToken",
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        logOptimizer("Slack failure notification CURL Error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (empty($result['ok'])) {
        logOptimizer("Slack failure notification API Error: " . ($result['error'] ?? 'unknown'), $result);
        return false;
    }

    logOptimizer("Slack PAUSE FAILURE notification sent for campaign $campaignId");
    return true;
}

// ============================================
// SEND SLACK REVIEW NOTIFICATION (after 30 min cooldown)
// Includes fresh metrics + interactive Resume button
// ============================================

function sendSlackReviewNotification($campaign, $tiktokMetrics, $redtrackMetrics) {
    $slackToken = getenv('SLACK_BOT_TOKEN') ?: ($_ENV['SLACK_BOT_TOKEN'] ?? '');
    $channelId = 'C0AC3899K6C';

    if (empty($slackToken)) {
        logOptimizer("Slack review notification skipped: SLACK_BOT_TOKEN not configured");
        return false;
    }

    $campaignName = $campaign['campaign_name'] ?? $campaign['campaign_id'];
    $campaignId = $campaign['campaign_id'];
    $advertiserId = $campaign['advertiser_id'];
    $ruleGroup = $campaign['rule_group'] ?? 'home_insurance';
    $lastRule = $campaign['last_violation_rule'] ?? 'unknown';

    // Format rule group for display
    $ruleGroupDisplay = match($ruleGroup) {
        'home_insurance' => 'Home Insurance',
        'medicare' => 'Medicare',
        default => ucwords(str_replace('_', ' ', $ruleGroup))
    };

    // Format rule key for display
    $ruleKeyDisplay = preg_replace('/^(hi|med)_/', '', $lastRule);
    $ruleKeyDisplay = ucwords(str_replace('_', ' ', $ruleKeyDisplay));

    // Format TikTok metrics
    $spend = number_format($tiktokMetrics['spend'] ?? 0, 2);
    $cpc = number_format($tiktokMetrics['cpc'] ?? 0, 2);
    $ctr = number_format($tiktokMetrics['ctr'] ?? 0, 2);
    $impressions = number_format($tiktokMetrics['impressions'] ?? 0);
    $clicks = number_format($tiktokMetrics['clicks'] ?? 0);
    $conversions = $tiktokMetrics['conversions'] ?? 0;

    // Build blocks
    $blocks = [
        [
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => "📊 Campaign Review — Ready to Resume?"]
        ],
        [
            'type' => 'section',
            'fields' => [
                ['type' => 'mrkdwn', 'text' => "*Campaign:*\n$campaignName"],
                ['type' => 'mrkdwn', 'text' => "*Campaign ID:*\n$campaignId"],
            ]
        ],
        [
            'type' => 'section',
            'fields' => [
                ['type' => 'mrkdwn', 'text' => "*Rule Group:*\n$ruleGroupDisplay"],
                ['type' => 'mrkdwn', 'text' => "*Paused For:*\n$ruleKeyDisplay"],
            ]
        ],
        ['type' => 'divider'],
        [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => "*📈 TikTok Metrics (Today)*"]
        ],
        [
            'type' => 'section',
            'fields' => [
                ['type' => 'mrkdwn', 'text' => "*Spend:*\n\$$spend"],
                ['type' => 'mrkdwn', 'text' => "*CPC:*\n\$$cpc"],
                ['type' => 'mrkdwn', 'text' => "*CTR:*\n{$ctr}%"],
                ['type' => 'mrkdwn', 'text' => "*Conversions:*\n$conversions"],
            ]
        ],
        [
            'type' => 'section',
            'fields' => [
                ['type' => 'mrkdwn', 'text' => "*Impressions:*\n$impressions"],
                ['type' => 'mrkdwn', 'text' => "*Clicks:*\n$clicks"],
            ]
        ],
    ];

    // Add RedTrack metrics if available
    $hasRedtrack = !empty($campaign['redtrack_campaign_name']);
    $rtConversions = $redtrackMetrics['conversions'] ?? 0;
    $rtLpCtr = number_format($redtrackMetrics['lp_ctr'] ?? 0, 2);
    $rtLpClicks = $redtrackMetrics['lp_clicks'] ?? 0;
    $rtLpViews = $redtrackMetrics['lp_views'] ?? 0;
    $rtRevenue = number_format($redtrackMetrics['revenue'] ?? 0, 2);

    if ($hasRedtrack) {
        $blocks[] = ['type' => 'divider'];
        $blocks[] = [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => "*🔗 RedTrack Metrics (Today)* — _{$campaign['redtrack_campaign_name']}_"]
        ];
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                ['type' => 'mrkdwn', 'text' => "*LP CTR:*\n{$rtLpCtr}%"],
                ['type' => 'mrkdwn', 'text' => "*LP Clicks:*\n$rtLpClicks"],
                ['type' => 'mrkdwn', 'text' => "*Conversions:*\n$rtConversions"],
                ['type' => 'mrkdwn', 'text' => "*Revenue:*\n\$$rtRevenue"],
            ]
        ];
    }

    $blocks[] = ['type' => 'divider'];

    // Add interactive Resume button
    $buttonValue = json_encode([
        'campaign_id' => $campaignId,
        'advertiser_id' => $advertiserId,
    ]);

    $blocks[] = [
        'type' => 'actions',
        'elements' => [
            [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => '▶️ Resume Campaign'],
                'style' => 'primary',
                'action_id' => 'resume_campaign',
                'value' => $buttonValue,
                'confirm' => [
                    'title' => ['type' => 'plain_text', 'text' => 'Resume Campaign?'],
                    'text' => ['type' => 'mrkdwn', 'text' => "This will re-enable *$campaignName* on TikTok."],
                    'confirm' => ['type' => 'plain_text', 'text' => 'Resume'],
                    'deny' => ['type' => 'plain_text', 'text' => 'Cancel'],
                ]
            ]
        ]
    ];

    $blocks[] = [
        'type' => 'context',
        'elements' => [
            ['type' => 'mrkdwn', 'text' => "⏱ Campaign will stay paused until you click Resume"]
        ]
    ];

    $fallbackText = "Campaign Review: $campaignName — Spend: \$$spend, CPC: \$$cpc, CTR: {$ctr}%, Conv: $conversions — Click to resume";

    $payload = json_encode([
        'channel' => $channelId,
        'text' => $fallbackText,
        'blocks' => $blocks,
    ]);

    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $slackToken",
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        logOptimizer("Slack review CURL Error: $err");
        return false;
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (empty($result['ok'])) {
        logOptimizer("Slack review API Error: " . ($result['error'] ?? 'unknown'), $result);
        return false;
    }

    logOptimizer("Slack review notification sent for campaign $campaignId");
    return true;
}

// ============================================
// RESUME CAMPAIGN VIA TIKTOK API
// ============================================

function resumeCampaignViaApi($advertiserId, $campaignId, $accessToken) {
    $result = optimizerTikTokApi('/campaign/status/update/', [
        'advertiser_id' => $advertiserId,
        'campaign_ids' => [$campaignId],
        'operation_status' => 'ENABLE',
    ], $accessToken, 'POST');

    $success = ($result['code'] ?? -1) == 0;
    logOptimizer("Resume campaign $campaignId: " . ($success ? 'SUCCESS' : 'FAILED'), $result);
    return ['success' => $success, 'response' => $result];
}

// ============================================
// RUN OPTIMIZER CHECK (called by cron)
// ============================================

function runOptimizerCheck($db, $accessToken) {
    logOptimizer("=== Starting optimizer check (Two-Phase System) ===");

    // Auto-add optimizer_phase column if it doesn't exist
    try {
        $db->query("ALTER TABLE optimizer_monitored_campaigns ADD COLUMN optimizer_phase VARCHAR(20) DEFAULT 'phase1'");
        logOptimizer("Added optimizer_phase column to optimizer_monitored_campaigns");
    } catch (Exception $e) {
        // Column already exists — ignore
    }

    $stats = ['checked' => 0, 'paused' => 0, 'resumed' => 0];

    // 1. Check campaigns that need REVIEW (paused 30+ min ago, not yet notified)
    // Instead of auto-resuming, fetch fresh metrics and send Slack review notification
    $toReview = $db->fetchAll(
        "SELECT * FROM optimizer_monitored_campaigns WHERE paused_by_optimizer = 1 AND resume_at IS NOT NULL AND resume_at <= NOW() AND review_notified = 0"
    );

    foreach ($toReview as $mc) {
        logOptimizer("Sending review notification for campaign {$mc['campaign_id']} (30 min cooldown passed)");

        // Fetch fresh metrics for the review
        $tiktokMetrics = fetchTikTokCampaignMetrics($mc['advertiser_id'], $mc['campaign_id'], $accessToken);

        // Use campaign-level RT name, fall back to per-campaign map, then account-level
        $reviewRtName = $mc['redtrack_campaign_name'] ?? null;
        if (empty($reviewRtName)) {
            try {
                $rtMap = $db->fetchOne(
                    "SELECT redtrack_campaign_name FROM campaign_redtrack_map WHERE campaign_id = ? AND advertiser_id = ?",
                    [$mc['campaign_id'], $mc['advertiser_id']]
                );
                $reviewRtName = $rtMap['redtrack_campaign_name'] ?? null;
            } catch (Exception $e) {}
        }
        if (empty($reviewRtName)) {
            try {
                $accountRt = $db->fetchOne(
                    "SELECT setting_value FROM optimizer_settings WHERE advertiser_id = ? AND setting_key = 'default_redtrack_campaign'",
                    [$mc['advertiser_id']]
                );
                $reviewRtName = $accountRt['setting_value'] ?? null;
            } catch (Exception $e) {}
        }
        $redtrackMetrics = fetchRedTrackCampaignMetrics($mc['campaign_id'], $reviewRtName);

        // Send Slack review notification with Resume button
        $sent = sendSlackReviewNotification($mc, $tiktokMetrics, $redtrackMetrics);

        // Mark as notified so we don't send again
        $db->query(
            "UPDATE optimizer_monitored_campaigns SET review_notified = 1, last_checked_at = NOW() WHERE id = ?",
            [$mc['id']]
        );

        // Log the review notification
        $db->insert('optimizer_logs', [
            'campaign_id' => $mc['campaign_id'],
            'advertiser_id' => $mc['advertiser_id'],
            'action' => 'review',
            'rule_key' => $mc['last_violation_rule'],
            'rule_details' => 'Review notification sent to Slack (awaiting manual resume)',
            'metrics_snapshot' => json_encode(['tiktok' => $tiktokMetrics, 'redtrack' => $redtrackMetrics]),
            'success' => $sent ? 1 : 0,
        ]);

        $stats['reviewed'] = ($stats['reviewed'] ?? 0) + 1;
    }

    // 2. Check active monitored campaigns using TWO-PHASE system
    // Phase 1: Wait until $30 spent → check conversions + CPC → pause or promote to Phase 2
    // Phase 2: Track profit (Revenue - Cost) → pause if loss reaches -$30
    $monitored = $db->fetchAll(
        "SELECT * FROM optimizer_monitored_campaigns WHERE monitoring_enabled = 1 AND paused_by_optimizer = 0"
    );

    foreach ($monitored as $mc) {
        $stats['checked']++;
        $campaignId = $mc['campaign_id'];
        $advertiserId = $mc['advertiser_id'];
        $campaignName = $mc['campaign_name'] ?? $campaignId;
        $phase = $mc['optimizer_phase'] ?? 'phase1';
        $campaignGroup = $mc['rule_group'] ?? 'home_insurance';

        // Fetch TikTok metrics
        $tiktokMetrics = fetchTikTokCampaignMetrics($advertiserId, $campaignId, $accessToken);

        // Fetch RedTrack metrics (use campaign-level RT name, fall back to per-campaign map, then account-level)
        $rtCampaignName = $mc['redtrack_campaign_name'] ?? null;
        if (empty($rtCampaignName)) {
            try {
                $rtMap = $db->fetchOne(
                    "SELECT redtrack_campaign_name FROM campaign_redtrack_map WHERE campaign_id = ? AND advertiser_id = ?",
                    [$campaignId, $advertiserId]
                );
                $rtCampaignName = $rtMap['redtrack_campaign_name'] ?? null;
            } catch (Exception $e) {}
        }
        if (empty($rtCampaignName)) {
            try {
                $accountRt = $db->fetchOne(
                    "SELECT setting_value FROM optimizer_settings WHERE advertiser_id = ? AND setting_key = 'default_redtrack_campaign'",
                    [$advertiserId]
                );
                $rtCampaignName = $accountRt['setting_value'] ?? null;
            } catch (Exception $e) {}
        }
        $redtrackMetrics = fetchRedTrackCampaignMetrics($campaignId, $rtCampaignName);

        $currentSpend = floatval($tiktokMetrics['spend'] ?? 0);
        $currentCpc = floatval($tiktokMetrics['cpc'] ?? 0);
        $tiktokConversions = intval($tiktokMetrics['conversions'] ?? 0);
        $revenue = floatval($redtrackMetrics['revenue'] ?? 0);
        $profit = $revenue - $currentSpend;

        $metricsSnapshot = [
            'tiktok' => $tiktokMetrics,
            'redtrack' => $redtrackMetrics,
            'profit' => $profit,
            'phase' => $phase,
        ];

        // ========================================
        // PHASE 1: Qualification ($30 spend gate)
        // ========================================
        if ($phase === 'phase1') {

            if ($currentSpend < 30) {
                // Not enough spend yet — skip evaluation
                logOptimizer("[$campaignId] Phase 1: Spend \${$currentSpend} < \$30 — waiting");
                $db->query("UPDATE optimizer_monitored_campaigns SET last_checked_at = NOW() WHERE id = ?", [$mc['id']]);
                $db->insert('optimizer_logs', [
                    'campaign_id' => $campaignId,
                    'advertiser_id' => $advertiserId,
                    'action' => 'rule_check',
                    'rule_key' => null,
                    'rule_details' => "Phase 1: Spend \${$currentSpend} / \$30 — waiting for \$30 threshold",
                    'metrics_snapshot' => json_encode($metricsSnapshot),
                    'success' => 1,
                ]);
                continue;
            }

            // $30+ spent — evaluate Phase 1 gate
            // PAUSE if: 0 conversions AND CPC > $0.80 (both conditions must be true)
            if ($tiktokConversions == 0 && $currentCpc > 0.80) {
                $ruleKey = 'phase1_no_conversion';
                $details = "Phase 1 FAIL: \${$currentSpend} spent, 0 conversions, CPC \${$currentCpc} > \$0.80";
                logOptimizer("[$campaignId] $details");

                $pauseResult = pauseCampaignViaApi($advertiserId, $campaignId, $accessToken);
                $resumeAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                $db->query(
                    "UPDATE optimizer_monitored_campaigns SET paused_by_optimizer = 1, paused_at = NOW(), resume_at = ?, last_violation_rule = ?, last_checked_at = NOW() WHERE id = ?",
                    [$resumeAt, $ruleKey, $mc['id']]
                );

                $db->insert('optimizer_logs', [
                    'campaign_id' => $campaignId,
                    'advertiser_id' => $advertiserId,
                    'action' => 'pause',
                    'rule_key' => $ruleKey,
                    'rule_details' => $details,
                    'metrics_snapshot' => json_encode($metricsSnapshot),
                    'api_response' => json_encode($pauseResult['response']),
                    'success' => $pauseResult['success'] ? 1 : 0,
                ]);

                if ($pauseResult['success']) {
                    sendSlackPauseNotification($campaignName, $campaignId, $ruleKey, $details, $campaignGroup, $resumeAt, $advertiserId, $metricsSnapshot);
                    $stats['paused']++;
                } else {
                    $db->query("UPDATE optimizer_monitored_campaigns SET paused_by_optimizer = 0, paused_at = NULL, resume_at = NULL WHERE id = ?", [$mc['id']]);
                    sendSlackPauseFailureNotification($campaignName, $campaignId, $ruleKey, $details);
                }
            } else {
                // Phase 1 passed — promote to Phase 2
                logOptimizer("[$campaignId] Phase 1 PASSED: \${$currentSpend} spent, {$tiktokConversions} conversions, CPC \${$currentCpc} — promoting to Phase 2");

                $db->query(
                    "UPDATE optimizer_monitored_campaigns SET optimizer_phase = 'phase2', last_checked_at = NOW() WHERE id = ?",
                    [$mc['id']]
                );

                $db->insert('optimizer_logs', [
                    'campaign_id' => $campaignId,
                    'advertiser_id' => $advertiserId,
                    'action' => 'rule_check',
                    'rule_key' => null,
                    'rule_details' => "Phase 1 PASSED → Phase 2: {$tiktokConversions} conversions, CPC \${$currentCpc}, Spend \${$currentSpend}",
                    'metrics_snapshot' => json_encode($metricsSnapshot),
                    'success' => 1,
                ]);
            }
            continue;
        }

        // ========================================
        // PHASE 2: Profitability (loss limit -$30)
        // ========================================
        if ($phase === 'phase2') {
            $profitFormatted = number_format($profit, 2);
            $revenueFormatted = number_format($revenue, 2);
            $spendFormatted = number_format($currentSpend, 2);

            if ($profit <= -30) {
                $ruleKey = 'phase2_loss_limit';
                $details = "Phase 2 FAIL: Profit \${$profitFormatted} (Revenue \${$revenueFormatted} - Cost \${$spendFormatted}) <= -\$30";
                logOptimizer("[$campaignId] $details");

                $pauseResult = pauseCampaignViaApi($advertiserId, $campaignId, $accessToken);
                $resumeAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                $db->query(
                    "UPDATE optimizer_monitored_campaigns SET paused_by_optimizer = 1, paused_at = NOW(), resume_at = ?, last_violation_rule = ?, last_checked_at = NOW() WHERE id = ?",
                    [$resumeAt, $ruleKey, $mc['id']]
                );

                $db->insert('optimizer_logs', [
                    'campaign_id' => $campaignId,
                    'advertiser_id' => $advertiserId,
                    'action' => 'pause',
                    'rule_key' => $ruleKey,
                    'rule_details' => $details,
                    'metrics_snapshot' => json_encode($metricsSnapshot),
                    'api_response' => json_encode($pauseResult['response']),
                    'success' => $pauseResult['success'] ? 1 : 0,
                ]);

                if ($pauseResult['success']) {
                    sendSlackPauseNotification($campaignName, $campaignId, $ruleKey, $details, $campaignGroup, $resumeAt, $advertiserId, $metricsSnapshot);
                    $stats['paused']++;
                } else {
                    $db->query("UPDATE optimizer_monitored_campaigns SET paused_by_optimizer = 0, paused_at = NULL, resume_at = NULL WHERE id = ?", [$mc['id']]);
                    sendSlackPauseFailureNotification($campaignName, $campaignId, $ruleKey, $details);
                }
            } else {
                // Phase 2 OK — campaign still profitable enough
                logOptimizer("[$campaignId] Phase 2 OK: Profit \${$profitFormatted} (Revenue \${$revenueFormatted} - Cost \${$spendFormatted})");

                $db->query("UPDATE optimizer_monitored_campaigns SET last_checked_at = NOW() WHERE id = ?", [$mc['id']]);

                $db->insert('optimizer_logs', [
                    'campaign_id' => $campaignId,
                    'advertiser_id' => $advertiserId,
                    'action' => 'rule_check',
                    'rule_key' => null,
                    'rule_details' => "Phase 2 OK: Profit \${$profitFormatted} (Revenue \${$revenueFormatted} - Cost \${$spendFormatted})",
                    'metrics_snapshot' => json_encode($metricsSnapshot),
                    'success' => 1,
                ]);
            }
            continue;
        }
    }

    logOptimizer("=== Optimizer check complete ===", $stats);
    return $stats;
}
