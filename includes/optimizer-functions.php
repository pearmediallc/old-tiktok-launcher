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
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $logFile = $logDir . '/optimizer_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    if ($data !== null) {
        $logMessage .= "\n" . json_encode($data, JSON_PRETTY_PRINT);
    }
    file_put_contents($logFile, "$logMessage\n", FILE_APPEND);
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

function redtrackApiCall($endpoint, $params = []) {
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

    // IMPORTANT: RedTrack API must only use GET requests — no POST allowed
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        logOptimizer("RedTrack CURL Error: $err");
        return null;
    }
    curl_close($ch);

    return json_decode($response, true);
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

    $result = optimizerTikTokApi('/report/integrated/get/', [
        'advertiser_id' => $advertiserId,
        'service_type' => 'AUCTION',
        'report_type' => 'BASIC',
        'data_level' => 'AUCTION_CAMPAIGN',
        'dimensions' => json_encode(['campaign_id', 'stat_time_day']),
        'metrics' => json_encode(['spend', 'cpc', 'impressions', 'clicks', 'ctr', 'conversions', 'conversion_rate']),
        'start_date' => $today,
        'end_date' => $today,
        'filtering' => json_encode([['field_name' => 'campaign_ids', 'filter_type' => 'IN', 'filter_value' => [$campaignId]]]),
        'page_size' => 10
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
        'conversions' => intval($row['conversions'] ?? 0),
    ];
}

// ============================================
// FETCH REDTRACK METRICS FOR CAMPAIGN
// ============================================

function fetchRedTrackCampaignMetrics($campaignId, $redtrackCampaignName = null) {
    $today = date('Y-m-d');
    $defaultMetrics = ['lp_ctr' => 0, 'ctr' => 0, 'conversions' => 0, 'revenue' => 0, 'lp_clicks' => 0, 'lp_views' => 0];

    // If a RedTrack campaign name is provided, look up by campaign name
    if (!empty($redtrackCampaignName)) {
        $rtCampaignId = lookupRedTrackCampaignId($redtrackCampaignName);
        if ($rtCampaignId) {
            $result = redtrackApiCall('/reports/conversions', [
                'from' => $today,
                'to' => $today,
                'campaign_id' => $rtCampaignId,
            ]);

            if (!empty($result['data']) && is_array($result['data'])) {
                // Use first row or aggregate
                $row = $result['data'][0];
                logOptimizer("RedTrack metrics for campaign '$redtrackCampaignName' (ID: $rtCampaignId)", $row);
                return [
                    'lp_ctr' => floatval($row['lp_ctr'] ?? 0),
                    'ctr' => floatval($row['ctr'] ?? 0),
                    'conversions' => intval($row['conversions'] ?? 0),
                    'revenue' => floatval($row['revenue'] ?? 0),
                    'lp_clicks' => intval($row['lp_clicks'] ?? 0),
                    'lp_views' => intval($row['lp_views'] ?? 0),
                ];
            }
            logOptimizer("No RedTrack data for campaign '$redtrackCampaignName' (ID: $rtCampaignId)");
            return $defaultMetrics;
        }
        logOptimizer("Could not find RedTrack campaign '$redtrackCampaignName', falling back to sub2 lookup");
    }

    // Fallback: RedTrack uses sub2 = TikTok campaign ID
    $result = redtrackApiCall('/reports/conversions', [
        'from' => $today,
        'to' => $today,
        'group_by' => 'sub2',
        'sub2' => $campaignId,
    ]);

    if (empty($result['data'])) {
        logOptimizer("No RedTrack data for campaign $campaignId");
        return $defaultMetrics;
    }

    // Find matching campaign row
    foreach ($result['data'] as $row) {
        if (($row['sub2'] ?? '') == $campaignId) {
            return [
                'lp_ctr' => floatval($row['lp_ctr'] ?? 0),
                'ctr' => floatval($row['ctr'] ?? 0),
                'conversions' => intval($row['conversions'] ?? 0),
                'revenue' => floatval($row['revenue'] ?? 0),
                'lp_clicks' => intval($row['lp_clicks'] ?? 0),
                'lp_views' => intval($row['lp_views'] ?? 0),
            ];
        }
    }

    return $defaultMetrics;
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

function sendSlackPauseNotification($campaignName, $campaignId, $ruleKey, $violationDetails, $ruleGroup, $resumeAt) {
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

    // Format rule key for display
    $ruleKeyDisplay = preg_replace('/^(hi|med)_/', '', $ruleKey);
    $ruleKeyDisplay = ucwords(str_replace('_', ' ', $ruleKeyDisplay));

    $fallbackText = "Campaign Paused: $campaignName ($campaignId) — Rule: $ruleKeyDisplay — $violationDetails";

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
                ['type' => 'mrkdwn', 'text' => "*Rule Group:*\n$ruleGroupDisplay"],
                ['type' => 'mrkdwn', 'text' => "*Rule Violated:*\n$ruleKeyDisplay"],
            ]
        ],
        [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => "*Details:*\n$violationDetails"]
        ],
        ['type' => 'divider'],
        [
            'type' => 'context',
            'elements' => [
                ['type' => 'mrkdwn', 'text' => "⏱ Auto-resume at: *$resumeAt UTC*"]
            ]
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
    logOptimizer("=== Starting optimizer check ===");

    // Get all enabled rules grouped by rule_group
    $allRules = $db->fetchAll("SELECT * FROM optimizer_rules WHERE enabled = 1");
    if (empty($allRules)) {
        logOptimizer("No enabled rules, skipping");
        return ['checked' => 0, 'paused' => 0, 'resumed' => 0];
    }

    // Index rules by rule_group for fast lookup
    $rulesByGroup = [];
    foreach ($allRules as $rule) {
        $group = $rule['rule_group'] ?? 'home_insurance';
        $rulesByGroup[$group][] = $rule;
    }

    $stats = ['checked' => 0, 'paused' => 0, 'resumed' => 0];

    // 1. Check campaigns that need to be RESUMED (paused 30+ min ago)
    $toResume = $db->fetchAll(
        "SELECT * FROM optimizer_monitored_campaigns WHERE paused_by_optimizer = 1 AND resume_at IS NOT NULL AND resume_at <= NOW()"
    );

    foreach ($toResume as $mc) {
        logOptimizer("Resuming campaign {$mc['campaign_id']} (30 min cooldown passed)");
        $result = resumeCampaignViaApi($mc['advertiser_id'], $mc['campaign_id'], $accessToken);

        $db->query(
            "UPDATE optimizer_monitored_campaigns SET paused_by_optimizer = 0, paused_at = NULL, resume_at = NULL, last_checked_at = NOW(), last_violation_rule = NULL WHERE id = ?",
            [$mc['id']]
        );

        $db->insert('optimizer_logs', [
            'campaign_id' => $mc['campaign_id'],
            'advertiser_id' => $mc['advertiser_id'],
            'action' => 'resume',
            'rule_key' => $mc['last_violation_rule'],
            'rule_details' => 'Auto-resumed after 30 min cooldown',
            'api_response' => json_encode($result['response']),
            'success' => $result['success'] ? 1 : 0,
        ]);

        $stats['resumed']++;
    }

    // 2. Check active monitored campaigns against their assigned rule group
    $monitored = $db->fetchAll(
        "SELECT * FROM optimizer_monitored_campaigns WHERE monitoring_enabled = 1 AND paused_by_optimizer = 0"
    );

    foreach ($monitored as $mc) {
        $stats['checked']++;

        // Get rules for this campaign's rule_group
        $campaignGroup = $mc['rule_group'] ?? 'home_insurance';
        $rules = $rulesByGroup[$campaignGroup] ?? [];

        if (empty($rules)) {
            logOptimizer("No enabled rules for group '$campaignGroup', skipping campaign {$mc['campaign_id']}");
            continue;
        }

        // Fetch TikTok metrics
        $tiktokMetrics = fetchTikTokCampaignMetrics($mc['advertiser_id'], $mc['campaign_id'], $accessToken);

        // Fetch RedTrack metrics (use RedTrack campaign name if configured)
        $redtrackMetrics = fetchRedTrackCampaignMetrics($mc['campaign_id'], $mc['redtrack_campaign_name'] ?? null);

        // Combine for snapshot
        $metricsSnapshot = [
            'tiktok' => $tiktokMetrics,
            'redtrack' => $redtrackMetrics,
        ];

        // Evaluate rules for this campaign's group only
        $violations = evaluateCampaignRules($tiktokMetrics, $redtrackMetrics, $rules);

        // Update last checked
        $db->query("UPDATE optimizer_monitored_campaigns SET last_checked_at = NOW() WHERE id = ?", [$mc['id']]);

        if (!empty($violations)) {
            $firstViolation = $violations[0];
            logOptimizer("Campaign {$mc['campaign_id']} [$campaignGroup] violated rule: {$firstViolation['rule_key']}", $violations);

            // Pause the campaign
            $pauseResult = pauseCampaignViaApi($mc['advertiser_id'], $mc['campaign_id'], $accessToken);

            // Set resume_at = now + 30 min
            $resumeAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            $db->query(
                "UPDATE optimizer_monitored_campaigns SET paused_by_optimizer = 1, paused_at = NOW(), resume_at = ?, last_violation_rule = ? WHERE id = ?",
                [$resumeAt, $firstViolation['rule_key'], $mc['id']]
            );

            // Log the pause
            $violationDetails = implode('; ', array_map(fn($v) => $v['details'], $violations));
            $db->insert('optimizer_logs', [
                'campaign_id' => $mc['campaign_id'],
                'advertiser_id' => $mc['advertiser_id'],
                'action' => 'pause',
                'rule_key' => $firstViolation['rule_key'],
                'rule_details' => $violationDetails,
                'metrics_snapshot' => json_encode($metricsSnapshot),
                'api_response' => json_encode($pauseResult['response']),
                'success' => $pauseResult['success'] ? 1 : 0,
            ]);

            // Send Slack notification if pause was successful
            if ($pauseResult['success']) {
                sendSlackPauseNotification(
                    $mc['campaign_name'] ?? $mc['campaign_id'],
                    $mc['campaign_id'],
                    $firstViolation['rule_key'],
                    $violationDetails,
                    $campaignGroup,
                    $resumeAt
                );
            }

            $stats['paused']++;
        } else {
            // Log the check (no violation)
            $db->insert('optimizer_logs', [
                'campaign_id' => $mc['campaign_id'],
                'advertiser_id' => $mc['advertiser_id'],
                'action' => 'rule_check',
                'rule_key' => null,
                'rule_details' => "All $campaignGroup rules passed",
                'metrics_snapshot' => json_encode($metricsSnapshot),
                'success' => 1,
            ]);
        }
    }

    logOptimizer("=== Optimizer check complete ===", $stats);
    return $stats;
}
