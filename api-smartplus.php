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
    $logFile = __DIR__ . '/logs/smartplus_' . date('Y-m-d') . '.log';
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
    // GET ACCOUNT DATA (Pixels, Identities, Videos)
    // ==========================================
    case 'get_account_data':
        $data = [
            'advertiser_id' => $advertiserId,
            'pixels' => [],
            'identities' => [],
            'videos' => []
        ];

        // Get Pixels
        $pixelResult = makeApiCall('/pixel/list/', [
            'advertiser_id' => $advertiserId
        ], $accessToken, 'GET');

        if ($pixelResult['code'] == 0 && isset($pixelResult['data']['pixels'])) {
            $data['pixels'] = $pixelResult['data']['pixels'];
        }

        // Get Identities (CUSTOMIZED_USER)
        $identityResult = makeApiCall('/identity/get/', [
            'advertiser_id' => $advertiserId,
            'identity_type' => 'CUSTOMIZED_USER'
        ], $accessToken, 'GET');

        if ($identityResult['code'] == 0 && isset($identityResult['data']['identity_list'])) {
            $data['identities'] = $identityResult['data']['identity_list'];
        }

        // Get Videos from Creative Library
        $videoResult = makeApiCall('/file/video/ad/search/', [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 50
        ], $accessToken, 'GET');

        if ($videoResult['code'] == 0 && isset($videoResult['data']['list'])) {
            $data['videos'] = $videoResult['data']['list'];
        }

        // Get Images for thumbnails
        $imageResult = makeApiCall('/file/image/ad/search/', [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 50
        ], $accessToken, 'GET');

        if ($imageResult['code'] == 0 && isset($imageResult['data']['list'])) {
            $data['images'] = $imageResult['data']['list'];
        }

        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        break;

    // ==========================================
    // CREATE DYNAMIC CTA PORTFOLIO
    // ==========================================
    case 'create_cta_portfolio':
        $ctaValues = $input['cta_values'] ?? ['LEARN_MORE', 'GET_QUOTE', 'CONTACT_US'];

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
                ['asset_content' => 'Learn more', 'asset_ids' => ['202156', '202150']],
                ['asset_content' => 'Get quote', 'asset_ids' => ['201804', '202152']],
                ['asset_content' => 'Contact us', 'asset_ids' => ['202142', '201766']]
            ];
        }

        $portfolioParams = [
            'advertiser_id' => $advertiserId,
            'creative_portfolio_type' => 'CTA',
            'portfolio_name' => 'Smart+ Dynamic CTA - ' . date('M d H:i'),
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
    // LAUNCH SMART+ CAMPAIGN (Full Flow)
    // Uses /smart_plus/ endpoints from GitHub SDK
    // ==========================================
    case 'launch_smartplus_campaign':
        $data = $input;
        $results = [];

        logSmartPlus("=== LAUNCHING SMART+ CAMPAIGN ===");
        logSmartPlus("Input data: " . json_encode($data));

        // Validate required fields
        if (empty($data['campaign_name'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign name is required']);
            exit;
        }
        if (empty($data['pixel_id'])) {
            echo json_encode(['success' => false, 'message' => 'Pixel is required']);
            exit;
        }
        if (empty($data['identity_id'])) {
            echo json_encode(['success' => false, 'message' => 'Identity is required']);
            exit;
        }
        if (empty($data['video_ids']) || !is_array($data['video_ids'])) {
            echo json_encode(['success' => false, 'message' => 'At least one video is required']);
            exit;
        }
        if (empty($data['ad_texts']) || !is_array($data['ad_texts'])) {
            echo json_encode(['success' => false, 'message' => 'At least one ad text is required']);
            exit;
        }
        if (empty($data['landing_page_url'])) {
            echo json_encode(['success' => false, 'message' => 'Landing page URL is required']);
            exit;
        }

        // ============================================
        // STEP 1: CREATE SMART+ CAMPAIGN
        // POST /open_api/v1.3/smart_plus/campaign/create/
        // ============================================
        logSmartPlus("STEP 1: Creating Smart+ Campaign");

        $budget = floatval($data['budget'] ?? 50);
        if ($budget < 20) $budget = 20;

        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION',
            'budget' => $budget,
            'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET'
        ];

        $campaignResult = makeApiCall('/smart_plus/campaign/create/', $campaignParams, $accessToken);

        if ($campaignResult['code'] != 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create campaign: ' . ($campaignResult['message'] ?? 'Unknown error'),
                'step' => 'campaign'
            ]);
            exit;
        }

        $campaignId = $campaignResult['data']['campaign_id'];
        $results['campaign'] = ['campaign_id' => $campaignId];
        logSmartPlus("Campaign created: $campaignId");

        // ============================================
        // STEP 2: CREATE SMART+ AD GROUP
        // POST /open_api/v1.3/smart_plus/adgroup/create/
        // ============================================
        logSmartPlus("STEP 2: Creating Smart+ Ad Group");

        $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $scheduleEnd = date('Y-m-d H:i:s', strtotime('+1 year'));

        $adGroupParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_id' => $campaignId,
            'adgroup_name' => $data['campaign_name'] . ' - Ad Group',

            // Lead Generation settings
            'promotion_type' => 'LEAD_GENERATION',
            'promotion_target_type' => 'EXTERNAL_WEBSITE',
            'optimization_goal' => 'CONVERT',
            'optimization_event' => $data['optimization_event'] ?? 'FORM',

            // Pixel
            'pixel_id' => $data['pixel_id'],

            // Targeting
            'targeting_spec' => [
                'location_ids' => ['6252001'] // US
            ],

            // Schedule
            'schedule_type' => 'SCHEDULE_START_END',
            'schedule_start_time' => $scheduleStart,
            'schedule_end_time' => $scheduleEnd,

            // Billing - Cost Cap with conversion bid
            'billing_event' => 'OCPM',
            'bid_type' => 'BID_TYPE_CUSTOM',
            'conversion_bid_price' => floatval($data['conversion_bid'] ?? $data['conversion_bid_price'] ?? 10)
        ];

        $adGroupResult = makeApiCall('/smart_plus/adgroup/create/', $adGroupParams, $accessToken);

        if ($adGroupResult['code'] != 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create ad group: ' . ($adGroupResult['message'] ?? 'Unknown error'),
                'step' => 'adgroup',
                'partial_results' => $results
            ]);
            exit;
        }

        $adGroupId = $adGroupResult['data']['adgroup_id'];
        $results['adgroup'] = ['adgroup_id' => $adGroupId];
        logSmartPlus("Ad Group created: $adGroupId");

        // ============================================
        // STEP 3: CREATE DYNAMIC CTA PORTFOLIO
        // ============================================
        logSmartPlus("STEP 3: Creating Dynamic CTA Portfolio");

        // Use cta_values from frontend, or fall back to call_to_action, or default
        $ctaValues = $data['cta_values'] ?? [$data['call_to_action'] ?? 'LEARN_MORE'];
        if (empty($ctaValues)) {
            $ctaValues = ['LEARN_MORE', 'GET_QUOTE', 'CONTACT_US'];
        }
        logSmartPlus("CTA Values: " . json_encode($ctaValues));

        $ctaAssetMap = [
            'LEARN_MORE' => ['202156', '202150'],
            'GET_QUOTE' => ['201804', '202152'],
            'SIGN_UP' => ['202106', '202011'],
            'CONTACT_US' => ['202142', '201766'],
            'APPLY_NOW' => ['201963', '201489'],
            'DOWNLOAD' => ['201774', '201745']
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
                ['asset_content' => 'Learn more', 'asset_ids' => ['202156', '202150']],
                ['asset_content' => 'Get quote', 'asset_ids' => ['201804', '202152']],
                ['asset_content' => 'Contact us', 'asset_ids' => ['202142', '201766']]
            ];
        }

        $portfolioParams = [
            'advertiser_id' => $advertiserId,
            'creative_portfolio_type' => 'CTA',
            'portfolio_name' => $data['campaign_name'] . ' - CTAs',
            'portfolio_content' => $portfolioContent
        ];

        $portfolioResult = makeApiCall('/creative/portfolio/create/', $portfolioParams, $accessToken);

        $ctaPortfolioId = null;
        if ($portfolioResult['code'] == 0) {
            $ctaPortfolioId = $portfolioResult['data']['creative_portfolio_id'] ?? null;
            $results['cta_portfolio'] = ['portfolio_id' => $ctaPortfolioId];
            logSmartPlus("CTA Portfolio created: $ctaPortfolioId");
        } else {
            logSmartPlus("Warning: Could not create CTA portfolio: " . ($portfolioResult['message'] ?? ''));
        }

        // ============================================
        // STEP 4: CREATE SMART+ AD
        // POST /open_api/v1.3/smart_plus/ad/create/
        // ============================================
        logSmartPlus("STEP 4: Creating Smart+ Ad");

        // Get video info to match thumbnails
        $videoIds = $data['video_ids'];
        $thumbnailIds = $data['thumbnail_ids'] ?? [];

        // Build creative_list
        $creativeList = [];
        foreach ($videoIds as $index => $videoId) {
            $thumbId = isset($thumbnailIds[$index]) ? $thumbnailIds[$index] : (isset($thumbnailIds[0]) ? $thumbnailIds[0] : null);

            $creativeInfo = [
                'ad_format' => 'SINGLE_VIDEO',
                'video_info' => [
                    'video_id' => $videoId
                ]
            ];

            // Add thumbnail if available
            if ($thumbId) {
                $creativeInfo['image_info'] = [
                    [
                        'image_id' => $thumbId,
                        'web_uri' => $thumbId
                    ]
                ];
            }

            $creativeList[] = ['creative_info' => $creativeInfo];
        }

        // Build ad_text_list
        $adTextList = [];
        foreach ($data['ad_texts'] as $text) {
            if (!empty(trim($text))) {
                $adTextList[] = ['ad_text' => trim($text)];
            }
        }

        // Landing page
        $landingPageList = [
            ['landing_page_url' => $data['landing_page_url']]
        ];

        // Build ad params
        $adParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'adgroup_id' => $adGroupId,
            'ad_name' => $data['campaign_name'] . ' - Ad',
            'ad_configuration' => [
                'identity_type' => 'CUSTOMIZED_USER',
                'identity_id' => $data['identity_id']
            ],
            'creative_list' => $creativeList,
            'ad_text_list' => $adTextList,
            'landing_page_url_list' => $landingPageList
        ];

        // Add CTA portfolio if created
        if ($ctaPortfolioId) {
            $adParams['ad_configuration']['call_to_action_id'] = $ctaPortfolioId;
        }

        $adResult = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

        if ($adResult['code'] != 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create ad: ' . ($adResult['message'] ?? 'Unknown error'),
                'step' => 'ad',
                'partial_results' => $results
            ]);
            exit;
        }

        $adId = $adResult['data']['smart_plus_ad_id'] ?? $adResult['data']['ad_id'] ?? null;
        $results['ad'] = [
            'ad_id' => $adId,
            'ad_ids' => $adResult['data']['ad_ids'] ?? [$adId]
        ];
        logSmartPlus("Ad created: $adId");

        // ============================================
        // SUCCESS
        // ============================================
        logSmartPlus("=== SMART+ CAMPAIGN CREATED SUCCESSFULLY ===");

        echo json_encode([
            'success' => true,
            'message' => 'Smart+ Campaign created successfully!',
            'data' => $results
        ]);
        break;

    // ==========================================
    // GET VIDEO INFO (for thumbnail matching)
    // ==========================================
    case 'get_video_info':
        $videoIds = $input['video_ids'] ?? [];

        if (empty($videoIds)) {
            echo json_encode(['success' => false, 'message' => 'No video IDs provided']);
            exit;
        }

        $result = makeApiCall('/file/video/ad/info/', [
            'advertiser_id' => $advertiserId,
            'video_ids' => json_encode($videoIds)
        ], $accessToken, 'GET');

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'data' => $result['data']['list'] ?? []
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to get video info'
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
