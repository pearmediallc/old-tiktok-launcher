<?php
/**
 * Smart+ Campaign API
 * Uses TikTok Business API /campaign/spc/create/ endpoint
 *
 * Documentation: https://business-api.tiktok.com/portal/docs?id=1802962795549761
 *
 * Smart+ Campaigns (SPC) create campaign, ad group, and ads in ONE API call.
 * CBO is enabled by default - budget MUST be provided.
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
    // CREATE SMART+ CAMPAIGN (ALL-IN-ONE)
    // POST /open_api/v1.3/campaign/spc/create/
    // Creates campaign, ad group, and ads in ONE call
    // ==========================================
    case 'create_spc_campaign':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ SPC CAMPAIGN (ALL-IN-ONE) ===");

        // Validate required fields
        if (empty($data['campaign_name'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign name is required']);
            exit;
        }
        if (empty($data['pixel_id'])) {
            echo json_encode(['success' => false, 'message' => 'Pixel ID is required']);
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

        // CBO Setting
        $cboEnabled = isset($data['cbo_enabled']) ? $data['cbo_enabled'] : true;

        // Budget handling based on CBO
        if ($cboEnabled) {
            // CBO enabled - budget at campaign level
            $campaignBudget = floatval($data['budget'] ?? 50);
            if ($campaignBudget < 20) $campaignBudget = 20;
            $adGroupBudget = null;
            $adGroupBudgetMode = null;
        } else {
            // CBO disabled - budget at ad group level
            $campaignBudget = null;
            $adGroupBudget = floatval($data['adgroup_budget'] ?? 50);
            if ($adGroupBudget < 20) $adGroupBudget = 20;
            $adGroupBudgetMode = $data['adgroup_budget_mode'] ?? 'BUDGET_MODE_DAY';
        }

        logSmartPlus("CBO Enabled: " . ($cboEnabled ? 'true' : 'false') . ", Campaign Budget: $campaignBudget, Ad Group Budget: $adGroupBudget");

        // Schedule
        $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $scheduleEnd = date('Y-m-d H:i:s', strtotime('+1 year'));

        // Build media_info_list from ads
        $mediaInfoList = [];
        $ads = $data['ads'] ?? [];

        foreach ($ads as $ad) {
            $mediaInfo = [
                'identity_type' => 'CUSTOMIZED_USER',
                'identity_id' => $data['identity_id']
            ];

            // Add video info if provided
            if (!empty($ad['video_id'])) {
                $mediaInfo['video_info'] = [
                    'video_id' => $ad['video_id']
                ];
            }

            // Add image info (cover) if provided
            if (!empty($ad['image_id'])) {
                $mediaInfo['image_info'] = [
                    ['image_id' => $ad['image_id']]
                ];
            }

            $mediaInfoList[] = ['media_info' => $mediaInfo];
        }

        // If no ads provided, create one with just the identity
        if (empty($mediaInfoList)) {
            $mediaInfoList[] = [
                'media_info' => [
                    'identity_type' => 'CUSTOMIZED_USER',
                    'identity_id' => $data['identity_id']
                ]
            ];
        }

        // Build title_list from ad texts
        $titleList = [];
        foreach ($ads as $ad) {
            if (!empty($ad['ad_text'])) {
                $titleList[] = ['title' => $ad['ad_text']];
            }
        }
        if (empty($titleList)) {
            $titleList[] = ['title' => $data['ad_text'] ?? ''];
        }

        // Build SPC params
        $spcParams = [
            'advertiser_id' => $advertiserId,

            // Campaign settings
            'objective_type' => 'LEAD_GENERATION',
            'campaign_name' => $data['campaign_name'],

            // Promotion settings
            'promotion_type' => 'LEAD_GENERATION',
            'promotion_target_type' => 'EXTERNAL_WEBSITE',

            // Optimization
            'optimization_goal' => 'CONVERT',
            'optimization_event' => $data['optimization_event'] ?? 'FORM',
            'pixel_id' => $data['pixel_id'],

            // Audience targeting
            'spc_audience_age' => $data['spc_audience_age'] ?? '18+',
            'location_ids' => $data['location_ids'] ?? ['6252001'],

            // Placement
            'placement_type' => 'PLACEMENT_TYPE_NORMAL',
            'placements' => ['PLACEMENT_TIKTOK'],

            // Schedule
            'schedule_type' => 'SCHEDULE_START_END',
            'schedule_start_time' => $scheduleStart,
            'schedule_end_time' => $scheduleEnd,

            // Bid
            'bid_type' => 'BID_TYPE_NO_BID',
            'billing_event' => 'OCPM',
        ];

        // Add budget based on CBO setting
        if ($cboEnabled) {
            // CBO enabled - budget at campaign level
            $spcParams['budget_mode'] = 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET';
            $spcParams['budget'] = $campaignBudget;
        } else {
            // CBO disabled - budget at ad group level
            $spcParams['budget_mode'] = $adGroupBudgetMode;
            $spcParams['budget'] = $adGroupBudget;
            // Note: For SPC endpoint, even with CBO disabled, budget might need to be specified
            // The endpoint may not support CBO toggle - budget is always required
        }

        // Add remaining params
        $spcParams += [

            // Landing page
            'landing_page_urls' => [
                ['landing_page_url' => $data['landing_page_url']]
            ],

            // Creative
            'media_info_list' => $mediaInfoList,
            'title_list' => $titleList
        ];

        // Add CTA if provided
        if (!empty($data['call_to_action_id'])) {
            $spcParams['call_to_action_id'] = $data['call_to_action_id'];
        }

        // Add dayparting if provided
        if (!empty($data['dayparting'])) {
            $spcParams['dayparting'] = $data['dayparting'];
        }

        logSmartPlus("SPC Params: " . json_encode($spcParams));

        $result = makeApiCall('/campaign/spc/create/', $spcParams, $accessToken);

        if ($result['code'] == 0 && isset($result['data']['campaign_id'])) {
            echo json_encode([
                'success' => true,
                'campaign_id' => $result['data']['campaign_id'],
                'data' => $result['data'],
                'message' => 'Smart+ Campaign created successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create Smart+ Campaign: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
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
    // CREATE CTA PORTFOLIO (for Dynamic CTA)
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
    // DEFAULT
    // ==========================================
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Unknown action: ' . $action
        ]);
        break;
}
