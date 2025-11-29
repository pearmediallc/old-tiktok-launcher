<?php
/**
 * Smart+ Campaign API
 * Uses TikTok Business API Smart+ endpoints:
 * - /smart_plus/campaign/create/
 * - /smart_plus/adgroup/create/
 * - /smart_plus/ad/create/
 *
 * Documentation: https://github.com/tiktok/tiktok-business-api-sdk
 */

session_start();
header('Content-Type: application/json');

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

// Get access token and advertiser ID
$accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? $_SESSION['access_token'] ?? '';
$advertiserId = $_SESSION['selected_advertiser_id'] ?? '';

// Log function
function logSmartPlus($message) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/smartplus_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Generate unique request ID (must be numeric string for TikTok API)
function generateRequestId() {
    return (string)(time() . rand(1000, 9999));
}

// Make API call to TikTok
function makeApiCall($endpoint, $params, $accessToken, $method = 'POST') {
    $url = "https://business-api.tiktok.com/open_api/v1.3" . $endpoint;

    logSmartPlus("API Call: $method $endpoint");
    logSmartPlus("Params: " . json_encode($params));

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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    logSmartPlus("Response ($httpCode): " . json_encode($result));

    return $result;
}

// Handle request
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

logSmartPlus("=== Action: $action ===");

switch ($action) {

    // ==========================================
    // GET PIXELS
    // ==========================================
    case 'get_pixels':
        $result = makeApiCall('/pixel/list/', [
            'advertiser_id' => $advertiserId
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['pixels'])) {
            echo json_encode([
                'success' => true,
                'data' => ['pixels' => $result['data']['pixels']]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to get pixels'
            ]);
        }
        break;

    // ==========================================
    // GET IDENTITIES - All types for Smart+
    // ==========================================
    case 'get_identities':
        // For Smart+, we need TT_USER, AUTH_CODE, or BC_AUTH_TT identities
        // NOT CUSTOMIZED_USER (that's only for regular campaigns)
        $allIdentities = [];

        // Get TT_USER identities
        $result = makeApiCall('/identity/get/', [
            'advertiser_id' => $advertiserId
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['identity_list'])) {
            $allIdentities = $result['data']['identity_list'];
        }

        echo json_encode([
            'success' => true,
            'data' => ['list' => $allIdentities]
        ]);
        break;

    // ==========================================
    // GET VIDEOS FROM CREATIVE LIBRARY
    // ==========================================
    case 'get_videos':
        $result = makeApiCall('/file/video/ad/search/', [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 100
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['list'])) {
            echo json_encode([
                'success' => true,
                'data' => $result['data']['list']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to get videos'
            ]);
        }
        break;

    // ==========================================
    // GET IMAGES FROM CREATIVE LIBRARY
    // ==========================================
    case 'get_images':
        $result = makeApiCall('/file/image/ad/search/', [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 100
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['list'])) {
            echo json_encode([
                'success' => true,
                'data' => $result['data']['list']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to get images'
            ]);
        }
        break;

    // ==========================================
    // CREATE SMART+ CAMPAIGN
    // POST /open_api/v1.3/smart_plus/campaign/create/
    // ==========================================
    case 'create_smartplus_campaign':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ CAMPAIGN ===");

        if (empty($data['campaign_name'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign name is required']);
            exit;
        }

        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_name' => $data['campaign_name'],
            'objective_type' => $data['objective_type'] ?? 'LEAD_GENERATION',
            'budget_optimize_on' => $data['budget_optimize_on'] ?? true,
            'operation_status' => 'ENABLE'
        ];

        // Add budget if CBO is enabled
        if (!empty($data['budget'])) {
            $campaignParams['budget'] = floatval($data['budget']);
            $campaignParams['budget_mode'] = $data['budget_mode'] ?? 'BUDGET_MODE_DAY';
        }

        $result = makeApiCall('/smart_plus/campaign/create/', $campaignParams, $accessToken);

        if ($result['code'] == 0 && isset($result['data']['campaign_id'])) {
            echo json_encode([
                'success' => true,
                'campaign_id' => $result['data']['campaign_id'],
                'message' => 'Smart+ Campaign created successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create campaign: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // CREATE SMART+ AD GROUP
    // POST /open_api/v1.3/smart_plus/adgroup/create/
    // ==========================================
    case 'create_smartplus_adgroup':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ AD GROUP ===");

        if (empty($data['campaign_id'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
            exit;
        }

        // Schedule times
        $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $adgroupParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_id' => $data['campaign_id'],
            'adgroup_name' => $data['adgroup_name'] ?? $data['campaign_name'] . ' Ad Group',
            'promotion_type' => $data['promotion_type'] ?? 'LEAD_GENERATION',
            'optimization_goal' => $data['optimization_goal'] ?? 'CONVERT',
            'billing_event' => $data['billing_event'] ?? 'OCPM',
            'schedule_type' => 'SCHEDULE_FROM_NOW',
            'schedule_start_time' => $scheduleStart,
            'operation_status' => 'ENABLE',
            'targeting_spec' => [
                'location_ids' => $data['location_ids'] ?? ['6252001']
            ]
        ];

        // Add pixel if provided
        if (!empty($data['pixel_id'])) {
            $adgroupParams['pixel_id'] = $data['pixel_id'];
            $adgroupParams['optimization_event'] = $data['optimization_event'] ?? 'FORM';
        }

        // Add identity - MUST be TT_USER, AUTH_CODE, or BC_AUTH_TT for Smart+
        if (!empty($data['identity_id'])) {
            $adgroupParams['identity_id'] = $data['identity_id'];
            $adgroupParams['identity_type'] = $data['identity_type'] ?? 'TT_USER';
        }

        // Add budget if CBO is disabled
        if (!empty($data['budget']) && empty($data['budget_optimize_on'])) {
            $adgroupParams['budget'] = floatval($data['budget']);
            $adgroupParams['budget_mode'] = $data['budget_mode'] ?? 'BUDGET_MODE_DAY';
        }

        // Add dayparting if provided
        if (!empty($data['dayparting'])) {
            $adgroupParams['dayparting'] = $data['dayparting'];
        }

        $result = makeApiCall('/smart_plus/adgroup/create/', $adgroupParams, $accessToken);

        if ($result['code'] == 0 && isset($result['data']['adgroup_id'])) {
            echo json_encode([
                'success' => true,
                'adgroup_id' => $result['data']['adgroup_id'],
                'message' => 'Smart+ Ad Group created successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create ad group: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // CREATE SMART+ AD
    // POST /open_api/v1.3/smart_plus/ad/create/
    // ==========================================
    case 'create_smartplus_ad':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ AD ===");

        if (empty($data['adgroup_id'])) {
            echo json_encode(['success' => false, 'message' => 'Ad Group ID is required']);
            exit;
        }

        // Build creative list
        $creativeList = [];
        foreach ($data['creatives'] ?? [] as $creative) {
            $creativeInfo = [
                'ad_format' => 'SINGLE_VIDEO',
                'identity_id' => $data['identity_id'],
                'identity_type' => $data['identity_type'] ?? 'TT_USER'
            ];

            // Add video info
            if (!empty($creative['video_id'])) {
                $creativeInfo['video_info'] = [
                    'video_id' => $creative['video_id']
                ];
            }

            // Add image info (cover image) - uses web_uri
            if (!empty($creative['image_url'])) {
                $creativeInfo['image_info'] = [
                    ['web_uri' => $creative['image_url']]
                ];
            }

            $creativeList[] = ['creative_info' => $creativeInfo];
        }

        // Build ad text list
        $adTextList = [];
        foreach ($data['ad_texts'] ?? [] as $text) {
            if (!empty($text)) {
                $adTextList[] = ['ad_text' => $text];
            }
        }

        // Build landing page list
        $landingPageList = [];
        if (!empty($data['landing_page_url'])) {
            $landingPageList[] = ['landing_page_url' => $data['landing_page_url']];
        }

        // Build CTA list
        $ctaList = [];
        if (!empty($data['call_to_action'])) {
            $ctaList[] = ['call_to_action' => $data['call_to_action']];
        }

        $adParams = [
            'advertiser_id' => $advertiserId,
            'adgroup_id' => $data['adgroup_id'],
            'ad_name' => $data['ad_name'] ?? 'Smart+ Ad',
            'operation_status' => 'ENABLE'
        ];

        if (!empty($creativeList)) {
            $adParams['creative_list'] = $creativeList;
        }
        if (!empty($adTextList)) {
            $adParams['ad_text_list'] = $adTextList;
        }
        if (!empty($landingPageList)) {
            $adParams['landing_page_url_list'] = $landingPageList;
        }
        if (!empty($ctaList)) {
            $adParams['call_to_action_list'] = $ctaList;
        }

        $result = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

        if ($result['code'] == 0 && isset($result['data']['ad_id'])) {
            echo json_encode([
                'success' => true,
                'ad_id' => $result['data']['ad_id'],
                'message' => 'Smart+ Ad created successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create ad: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // CREATE FULL SMART+ CAMPAIGN (Campaign + AdGroup + Ads)
    // Orchestrates all three API calls
    // ==========================================
    case 'create_full_smartplus':
        $data = $input;

        logSmartPlus("=== CREATING FULL SMART+ CAMPAIGN ===");

        // Validate required fields
        if (empty($data['campaign_name'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign name is required']);
            exit;
        }
        if (empty($data['identity_id'])) {
            echo json_encode(['success' => false, 'message' => 'Identity ID is required']);
            exit;
        }
        if (empty($data['landing_page_url'])) {
            echo json_encode(['success' => false, 'message' => 'Landing page URL is required']);
            exit;
        }

        $results = [
            'campaign' => null,
            'adgroup' => null,
            'ads' => []
        ];

        // Step 1: Create Campaign
        logSmartPlus("Step 1: Creating Campaign...");
        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION',
            'budget_optimize_on' => $data['cbo_enabled'] ?? true,
            'operation_status' => 'ENABLE'
        ];

        if (!empty($data['budget']) && ($data['cbo_enabled'] ?? true)) {
            $campaignParams['budget'] = floatval($data['budget']);
            // Smart+ only supports BUDGET_MODE_DYNAMIC_DAILY_BUDGET or BUDGET_MODE_TOTAL
            $campaignParams['budget_mode'] = 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET';
        }

        $campaignResult = makeApiCall('/smart_plus/campaign/create/', $campaignParams, $accessToken);

        if ($campaignResult['code'] != 0 || !isset($campaignResult['data']['campaign_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create campaign: ' . ($campaignResult['message'] ?? 'Unknown error'),
                'step' => 'campaign',
                'details' => $campaignResult
            ]);
            exit;
        }

        $campaignId = $campaignResult['data']['campaign_id'];
        $results['campaign'] = $campaignId;
        logSmartPlus("Campaign created: $campaignId");

        // Step 2: Create Ad Group
        logSmartPlus("Step 2: Creating Ad Group...");
        $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $adgroupParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_id' => $campaignId,
            'adgroup_name' => $data['campaign_name'] . ' - Ad Group',
            'promotion_type' => 'LEAD_GENERATION',
            'promotion_target_type' => 'EXTERNAL_WEBSITE',
            'optimization_goal' => 'CONVERT',
            'billing_event' => 'OCPM',
            'bid_type' => 'BID_TYPE_NO_BID',
            'schedule_type' => 'SCHEDULE_FROM_NOW',
            'schedule_start_time' => $scheduleStart,
            'operation_status' => 'ENABLE',
            'identity_id' => $data['identity_id'],
            'identity_type' => $data['identity_type'] ?? 'TT_USER',
            'targeting_spec' => [
                'location_ids' => $data['location_ids'] ?? ['6252001']
            ]
        ];

        // Add pixel
        if (!empty($data['pixel_id'])) {
            $adgroupParams['pixel_id'] = $data['pixel_id'];
            $adgroupParams['optimization_event'] = $data['optimization_event'] ?? 'FORM';
        }

        // Add budget at ad group level if CBO disabled
        if (!($data['cbo_enabled'] ?? true) && !empty($data['adgroup_budget'])) {
            $adgroupParams['budget'] = floatval($data['adgroup_budget']);
            $adgroupParams['budget_mode'] = $data['adgroup_budget_mode'] ?? 'BUDGET_MODE_DAY';
        }

        // Add dayparting
        if (!empty($data['dayparting'])) {
            $adgroupParams['dayparting'] = $data['dayparting'];
        }

        $adgroupResult = makeApiCall('/smart_plus/adgroup/create/', $adgroupParams, $accessToken);

        if ($adgroupResult['code'] != 0 || !isset($adgroupResult['data']['adgroup_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create ad group: ' . ($adgroupResult['message'] ?? 'Unknown error'),
                'step' => 'adgroup',
                'campaign_id' => $campaignId,
                'details' => $adgroupResult
            ]);
            exit;
        }

        $adgroupId = $adgroupResult['data']['adgroup_id'];
        $results['adgroup'] = $adgroupId;
        logSmartPlus("Ad Group created: $adgroupId");

        // Step 3: Create Ads
        logSmartPlus("Step 3: Creating Ads...");
        $ads = $data['ads'] ?? [];

        foreach ($ads as $index => $ad) {
            $creativeList = [];

            $creativeInfo = [
                'ad_format' => 'SINGLE_VIDEO',
                'identity_id' => $data['identity_id'],
                'identity_type' => $data['identity_type'] ?? 'TT_USER'
            ];

            // Add video
            if (!empty($ad['video_id'])) {
                $creativeInfo['video_info'] = [
                    'video_id' => $ad['video_id']
                ];
            }

            // Add cover image (web_uri required)
            if (!empty($ad['image_url'])) {
                $creativeInfo['image_info'] = [
                    ['web_uri' => $ad['image_url']]
                ];
            }

            $creativeList[] = ['creative_info' => $creativeInfo];

            $adParams = [
                'advertiser_id' => $advertiserId,
                'adgroup_id' => $adgroupId,
                'ad_name' => $ad['name'] ?? 'Ad ' . ($index + 1),
                'operation_status' => 'ENABLE',
                'creative_list' => $creativeList,
                'ad_text_list' => [
                    ['ad_text' => $ad['ad_text'] ?? '']
                ],
                'landing_page_url_list' => [
                    ['landing_page_url' => $data['landing_page_url']]
                ]
            ];

            // Add CTA
            if (!empty($data['call_to_action'])) {
                $adParams['call_to_action_list'] = [
                    ['call_to_action' => $data['call_to_action']]
                ];
            }

            $adResult = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

            if ($adResult['code'] == 0 && isset($adResult['data']['ad_id'])) {
                $results['ads'][] = [
                    'success' => true,
                    'ad_id' => $adResult['data']['ad_id'],
                    'name' => $ad['name'] ?? 'Ad ' . ($index + 1)
                ];
                logSmartPlus("Ad created: " . $adResult['data']['ad_id']);
            } else {
                $results['ads'][] = [
                    'success' => false,
                    'name' => $ad['name'] ?? 'Ad ' . ($index + 1),
                    'error' => $adResult['message'] ?? 'Unknown error'
                ];
                logSmartPlus("Failed to create ad: " . ($adResult['message'] ?? 'Unknown error'));
            }
        }

        // Return results
        $successCount = count(array_filter($results['ads'], fn($a) => $a['success']));
        echo json_encode([
            'success' => true,
            'campaign_id' => $campaignId,
            'adgroup_id' => $adgroupId,
            'ads_created' => $successCount,
            'ads_total' => count($ads),
            'results' => $results,
            'message' => "Smart+ Campaign created: Campaign ID $campaignId, Ad Group ID $adgroupId, $successCount/" . count($ads) . " ads created"
        ]);
        break;

    // ==========================================
    // CREATE CUSTOM IDENTITY (for regular campaigns)
    // ==========================================
    case 'create_identity':
        $data = $input;

        if (empty($data['display_name'])) {
            echo json_encode(['success' => false, 'message' => 'Display name is required']);
            exit;
        }

        $params = [
            'advertiser_id' => $advertiserId,
            'identity_type' => 'CUSTOMIZED_USER',
            'display_name' => $data['display_name']
        ];

        if (!empty($data['profile_image_id'])) {
            $params['profile_image_id'] = $data['profile_image_id'];
        }

        $result = makeApiCall('/identity/create/', $params, $accessToken);

        if ($result['code'] == 0 && isset($result['data']['identity_id'])) {
            echo json_encode([
                'success' => true,
                'identity_id' => $result['data']['identity_id'],
                'message' => 'Identity created successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to create identity'
            ]);
        }
        break;

    // ==========================================
    // DEFAULT
    // ==========================================
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Unknown action: ' . $action
        ]);
        break;
}
