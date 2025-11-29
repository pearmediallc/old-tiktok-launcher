<?php
/**
 * Smart+ Campaign API
 * Uses TikTok Business API /smart_plus/ endpoints from GitHub SDK
 *
 * Endpoints:
 * - POST /open_api/v1.3/smart_plus/campaign/create/
 * - POST /open_api/v1.3/smart_plus/adgroup/create/
 * - POST /open_api/v1.3/smart_plus/ad/create/
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

// Generate unique request ID (required for Smart+ endpoints)
function generateRequestId() {
    return strval(time() . rand(100000, 999999));
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
    // GET IDENTITIES (CUSTOMIZED_USER)
    // ==========================================
    case 'get_identities':
        $result = makeApiCall('/identity/get/', [
            'advertiser_id' => $advertiserId,
            'identity_type' => 'CUSTOMIZED_USER'
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['identity_list'])) {
            echo json_encode([
                'success' => true,
                'data' => ['list' => $result['data']['identity_list']]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to get identities'
            ]);
        }
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
    // Note: Budget is set at Ad Group level, NOT campaign level
    // ==========================================
    case 'create_campaign':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ CAMPAIGN ===");

        // Validate
        if (empty($data['campaign_name'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign name is required']);
            exit;
        }

        // Smart+ Campaign - budget is at ad group level, not campaign level
        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION'
        ];

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
                'error_code' => $result['code'] ?? null
            ]);
        }
        break;

    // ==========================================
    // CREATE SMART+ AD GROUP
    // POST /open_api/v1.3/smart_plus/adgroup/create/
    // Budget is set here at Ad Group level
    // ==========================================
    case 'create_adgroup':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ AD GROUP ===");

        // Validate
        if (empty($data['campaign_id'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
            exit;
        }
        if (empty($data['adgroup_name'])) {
            echo json_encode(['success' => false, 'message' => 'Ad Group name is required']);
            exit;
        }
        if (empty($data['pixel_id'])) {
            echo json_encode(['success' => false, 'message' => 'Pixel ID is required']);
            exit;
        }

        // Budget at ad group level
        $budget = floatval($data['budget'] ?? 50);
        if ($budget < 20) $budget = 20;
        $budgetMode = $data['budget_mode'] ?? 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET';

        $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $scheduleEnd = date('Y-m-d H:i:s', strtotime('+1 year'));

        // Build targeting spec
        $targetingSpec = [];

        // Location targeting
        $locationIds = $data['location_ids'] ?? ['6252001']; // Default to US
        $targetingSpec['location_ids'] = $locationIds;

        // Age targeting
        $ageGroups = $data['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'];
        if (!empty($ageGroups)) {
            $targetingSpec['age_groups'] = $ageGroups;
        }

        $adGroupParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_id' => $data['campaign_id'],
            'adgroup_name' => $data['adgroup_name'] ?? 'Ad Group',

            // Budget at Ad Group level
            'budget' => $budget,
            'budget_mode' => $budgetMode,

            // Lead Generation settings
            'promotion_type' => 'LEAD_GENERATION',
            'promotion_target_type' => 'EXTERNAL_WEBSITE',
            'optimization_goal' => 'CONVERT',
            'optimization_event' => $data['optimization_event'] ?? 'FORM',

            // Pixel
            'pixel_id' => $data['pixel_id'],

            // Targeting
            'targeting_spec' => $targetingSpec,

            // Schedule
            'schedule_type' => 'SCHEDULE_START_END',
            'schedule_start_time' => $scheduleStart,
            'schedule_end_time' => $scheduleEnd,

            // Billing
            'billing_event' => 'OCPM',
            'bid_type' => 'BID_TYPE_NO_BID'
        ];

        // Add dayparting if provided
        if (!empty($data['dayparting'])) {
            $adGroupParams['dayparting'] = $data['dayparting'];
        }

        logSmartPlus("Ad Group Params: " . json_encode($adGroupParams));

        $result = makeApiCall('/smart_plus/adgroup/create/', $adGroupParams, $accessToken);

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
                'error_code' => $result['code'] ?? null
            ]);
        }
        break;

    // ==========================================
    // CREATE SMART+ AD
    // POST /open_api/v1.3/smart_plus/ad/create/
    // ==========================================
    case 'create_ad':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ AD ===");

        // Validate
        if (empty($data['adgroup_id'])) {
            echo json_encode(['success' => false, 'message' => 'Ad Group ID is required']);
            exit;
        }
        if (empty($data['ad_name'])) {
            echo json_encode(['success' => false, 'message' => 'Ad name is required']);
            exit;
        }
        if (empty($data['identity_id'])) {
            echo json_encode(['success' => false, 'message' => 'Identity is required']);
            exit;
        }
        if (empty($data['landing_page_url'])) {
            echo json_encode(['success' => false, 'message' => 'Landing page URL is required']);
            exit;
        }
        if (empty($data['ad_text'])) {
            echo json_encode(['success' => false, 'message' => 'Ad text is required']);
            exit;
        }

        // Build creative info
        $creativeInfo = [
            'ad_format' => 'SINGLE_VIDEO'
        ];

        // Add video
        if (!empty($data['video_id'])) {
            $creativeInfo['video_info'] = [
                'video_id' => $data['video_id']
            ];
        }

        // Add thumbnail/cover image
        if (!empty($data['image_id'])) {
            $creativeInfo['image_info'] = [
                [
                    'image_id' => $data['image_id']
                ]
            ];
        }

        // Build ad params
        $adParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'adgroup_id' => $data['adgroup_id'],
            'ad_name' => $data['ad_name'],
            'ad_configuration' => [
                'identity_type' => 'CUSTOMIZED_USER',
                'identity_id' => $data['identity_id']
            ],
            'creative_list' => [
                ['creative_info' => $creativeInfo]
            ],
            'ad_text_list' => [
                ['ad_text' => $data['ad_text']]
            ],
            'landing_page_url_list' => [
                ['landing_page_url' => $data['landing_page_url']]
            ]
        ];

        // Add CTA if provided
        if (!empty($data['call_to_action'])) {
            $adParams['ad_configuration']['call_to_action'] = $data['call_to_action'];
        }

        // Add CTA portfolio if provided
        if (!empty($data['call_to_action_id'])) {
            $adParams['ad_configuration']['call_to_action_id'] = $data['call_to_action_id'];
        }

        $result = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

        if ($result['code'] == 0) {
            $adId = $result['data']['smart_plus_ad_id'] ?? $result['data']['ad_id'] ?? null;
            echo json_encode([
                'success' => true,
                'ad_id' => $adId,
                'ad_ids' => $result['data']['ad_ids'] ?? [$adId],
                'message' => 'Smart+ Ad created successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create ad: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null
            ]);
        }
        break;

    // ==========================================
    // CREATE CUSTOM IDENTITY
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
    // CREATE CTA PORTFOLIO
    // ==========================================
    case 'create_cta_portfolio':
        $ctaValues = $input['cta_values'] ?? ['LEARN_MORE'];

        // Map CTA values to TikTok asset IDs
        $ctaAssetMap = [
            'LEARN_MORE' => ['202156', '202150'],
            'GET_QUOTE' => ['201804', '202152'],
            'SIGN_UP' => ['202106', '202011'],
            'CONTACT_US' => ['202142', '201766'],
            'APPLY_NOW' => ['201963', '201489'],
            'DOWNLOAD' => ['201774', '201745'],
            'SHOP_NOW' => ['202020', '201973'],
            'ORDER_NOW' => ['201821', '201820'],
            'BOOK_NOW' => ['202160', '201769'],
            'GET_STARTED' => ['202117', '202158']
        ];

        $portfolioContent = [];
        foreach ($ctaValues as $cta) {
            $ctaKey = strtoupper(str_replace(' ', '_', $cta));
            if (isset($ctaAssetMap[$ctaKey])) {
                $portfolioContent[] = [
                    'asset_content' => ucwords(strtolower(str_replace('_', ' ', $ctaKey))),
                    'asset_ids' => $ctaAssetMap[$ctaKey]
                ];
            }
        }

        if (empty($portfolioContent)) {
            $portfolioContent = [
                ['asset_content' => 'Learn more', 'asset_ids' => ['202156', '202150']]
            ];
        }

        $portfolioParams = [
            'advertiser_id' => $advertiserId,
            'creative_portfolio_type' => 'CTA',
            'portfolio_name' => 'Smart+ CTA - ' . date('M d H:i'),
            'portfolio_content' => $portfolioContent
        ];

        $result = makeApiCall('/creative/portfolio/create/', $portfolioParams, $accessToken);

        if ($result['code'] == 0) {
            $portfolioId = $result['data']['creative_portfolio_id'] ?? $result['data']['portfolio_id'] ?? null;
            echo json_encode([
                'success' => true,
                'portfolio_id' => $portfolioId,
                'message' => 'CTA Portfolio created'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to create CTA portfolio'
            ]);
        }
        break;

    // ==========================================
    // UPLOAD VIDEO
    // ==========================================
    case 'upload_video':
        // Handle video upload - forward to main api.php
        echo json_encode([
            'success' => false,
            'message' => 'Use api.php for media uploads'
        ]);
        break;

    // ==========================================
    // UPLOAD IMAGE
    // ==========================================
    case 'upload_image':
        // Handle image upload - forward to main api.php
        echo json_encode([
            'success' => false,
            'message' => 'Use api.php for media uploads'
        ]);
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
