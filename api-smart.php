<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/state_location_mapping.php';

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get advertiser ID from session
$advertiser_id = $_SESSION['selected_advertiser_id'] ?? '';

// Include SDK files
require_once __DIR__ . '/sdk/Campaign.php';
require_once __DIR__ . '/sdk/AdGroup.php';
require_once __DIR__ . '/sdk/Ad.php';
require_once __DIR__ . '/sdk/TikTokBusinessSDK.php';

// Load SDK configuration
// Use TikTok access token from environment for API calls
$config = [
    'access_token' => $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '',
    'advertiser_id' => $advertiser_id
];

// Function to log to file
function logToFile($message) {
    $logFile = __DIR__ . '/logs/smart-api-' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
}

// Process location targeting data for Smart+
function processSmartLocationTargeting($locationData) {
    if (empty($locationData)) {
        return ['6252001']; // Default to United States
    }
    
    // Process the location data using our mapping function
    $result = processLocationData($locationData);
    
    if (!empty($result['unmatched'])) {
        logToFile("WARNING: Smart+ Unmatched locations: " . implode(', ', $result['unmatched']));
    }
    
    // Return location IDs or fallback to US if none found
    return !empty($result['location_ids']) ? $result['location_ids'] : ['6252001'];
}

// Get request data
$requestData = json_decode(file_get_contents('php://input'), true);
$action = $requestData['action'] ?? '';

logToFile("Smart+ API Request - Action: {$action}");

try {
    switch ($action) {
        case 'create_smart_campaign':
            $campaign = new Campaign($config);
            $data = $requestData;
            
            // Smart+ Campaign parameters
            $params = [
                'advertiser_id' => $advertiser_id,
                'campaign_name' => $data['campaign_name'],
                'campaign_type' => 'REGULAR', // TikTok uses REGULAR for Smart+ too
                'objective_type' => 'LEAD_GENERATION',
                'budget_mode' => $data['budget_mode'] ?? 'BUDGET_MODE_DAY',
                'budget' => floatval($data['budget'] ?? 50),
                'operation_status' => 'ENABLE',
                
                // Smart+ specific features
                'smart_bid_type' => 'SMART_BID_TYPE_CONSERVATIVE', // Conservative smart bidding
                'is_smart_performance_campaign' => true, // Flag for Smart+ campaign
            ];
            
            // Add smart features as custom parameters
            if (isset($data['smart_features'])) {
                $params['deep_bid_type'] = $data['smart_features']['auto_targeting'] ? 'AUTO_BID' : 'MANUAL_BID';
                $params['placement_optimization'] = $data['smart_features']['auto_placement'] ?? true;
                $params['creative_optimization'] = $data['smart_features']['creative_optimization'] ?? true;
            }
            
            // Schedule times if provided
            if (!empty($data['schedule_start_time'])) {
                $params['schedule_start_time'] = $data['schedule_start_time'];
            }
            if (!empty($data['schedule_end_time'])) {
                $params['schedule_end_time'] = $data['schedule_end_time'];
            }
            
            logToFile("Smart+ Campaign Params: " . json_encode($params, JSON_PRETTY_PRINT));
            
            $result = $campaign->create($params);
            
            if ($result['code'] == 0) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['data'],
                    'message' => 'Smart+ Campaign created successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to create Smart+ campaign',
                    'error' => $result
                ]);
            }
            break;
            
        case 'create_smart_adgroup':
            $adGroup = new AdGroup($config);
            $data = $requestData;
            
            // Smart+ Ad Group parameters
            $params = [
                'advertiser_id' => $advertiser_id,
                'campaign_id' => $data['campaign_id'],
                'adgroup_name' => $data['adgroup_name'],
                
                // Optimization settings for Smart+
                'promotion_type' => 'LEAD_GENERATION',
                'promotion_target_type' => 'EXTERNAL_WEBSITE',
                'pixel_id' => $data['pixel_id'],
                'optimization_goal' => 'CONVERT',
                'optimization_event' => 'FORM',
                'billing_event' => 'OCPM',
                
                // Smart+ automatic placement
                'placement_type' => 'PLACEMENT_TYPE_AUTOMATIC',
                'automatic_placement' => true,
                
                // Smart targeting (broader for AI optimization)
                'location_ids' => processSmartLocationTargeting($data['location_ids'] ?? ['6252001']),
                'age_groups' => $data['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'],
                'gender' => 'GENDER_UNLIMITED',
                
                // Broader targeting for Smart+ to optimize
                'interest_category_v2' => [], // Let AI determine interests
                'targeting_expansion' => true, // Enable audience expansion
                
                // Budget and schedule
                'budget_mode' => $data['budget_mode'] ?? 'BUDGET_MODE_DAY',
                'budget' => floatval($data['budget'] ?? 50),
                'schedule_type' => 'SCHEDULE_FROM_NOW',
                'schedule_start_time' => $data['schedule_start_time'],
                
                // Smart bidding
                'bid_type' => 'BID_TYPE_CUSTOM',
                'conversion_bid_price' => floatval($data['conversion_bid_price'] ?? 10),
                'deep_bid_type' => 'SMART_BID_CONSERVATIVE', // Smart bidding
                'pacing' => 'PACING_MODE_SMOOTH',
                
                // Attribution
                'click_attribution_window' => 'SEVEN_DAYS',
                'view_attribution_window' => 'ONE_DAY',
                'attribution_event_count' => 'EVERY'
            ];
            
            logToFile("Smart+ Ad Group Params: " . json_encode($params, JSON_PRETTY_PRINT));
            
            $result = $adGroup->create($params);
            
            if ($result['code'] == 0) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['data'],
                    'message' => 'Smart+ Ad Group created successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to create Smart+ ad group',
                    'error' => $result
                ]);
            }
            break;
            
        case 'create_smart_ad':
            $ad = new Ad($config);
            $data = $requestData;
            
            // Smart+ supports multiple creatives and texts
            $mediaList = $data['media_list'] ?? [];
            $textList = $data['ad_texts'] ?? [];
            
            // Create multiple ad variations for Smart+ optimization
            $results = [];
            
            // For Smart+, we create one ad with multiple creatives
            $params = [
                'advertiser_id' => $advertiser_id,
                'adgroup_id' => $data['adgroup_id'],
                'creatives' => []
            ];
            
            // Build creatives array with all combinations
            foreach ($mediaList as $mediaId) {
                foreach ($textList as $text) {
                    $creative = [
                        'ad_name' => $data['ad_name'] . ' - ' . substr(md5($mediaId . $text), 0, 6),
                        'ad_text' => $text,
                        'ad_format' => 'SINGLE_VIDEO', // Will be determined by media type
                        'video_id' => $mediaId, // or image_ids for images
                        'identity_id' => $data['identity_id'],
                        'identity_type' => 'CUSTOMIZED',
                        'call_to_action' => $data['call_to_action'] ?? 'LEARN_MORE',
                        'landing_page_url' => $data['landing_page_url'],
                        'creative_type' => 'SMART_CREATIVE', // Smart+ creative type
                        'dynamic_creative' => true, // Enable dynamic creative optimization
                    ];
                    
                    $params['creatives'][] = $creative;
                }
            }
            
            // Enable Smart+ features
            $params['shopping_ads_fallback_type'] = 'AUTOMATIC'; // Auto-optimize creative display
            $params['creative_optimization'] = true;
            $params['dynamic_creative'] = true;
            
            logToFile("Smart+ Ad Params: " . json_encode($params, JSON_PRETTY_PRINT));
            
            // For Smart+, create a batch of ads
            $batchResult = $ad->createBatch($params);
            
            if ($batchResult['code'] == 0) {
                echo json_encode([
                    'success' => true,
                    'data' => $batchResult['data'],
                    'message' => 'Smart+ Ads created successfully'
                ]);
            } else {
                // Fallback to single ad creation if batch fails
                $singleParams = [
                    'advertiser_id' => $advertiser_id,
                    'adgroup_id' => $data['adgroup_id'],
                    'ad_name' => $data['ad_name'],
                    'ad_text' => $textList[0] ?? 'Ad text',
                    'ad_format' => 'SINGLE_VIDEO',
                    'video_id' => $mediaList[0] ?? '',
                    'identity_id' => $data['identity_id'],
                    'identity_type' => 'CUSTOMIZED',
                    'call_to_action' => $data['call_to_action'] ?? 'LEARN_MORE',
                    'landing_page_url' => $data['landing_page_url']
                ];
                
                $result = $ad->create($singleParams);
                
                if ($result['code'] == 0) {
                    echo json_encode([
                        'success' => true,
                        'data' => $result['data'],
                        'message' => 'Smart+ Ad created successfully'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => $result['message'] ?? 'Failed to create Smart+ ad',
                        'error' => $result
                    ]);
                }
            }
            break;
            
        case 'get_smart_campaign_insights':
            // Get performance insights for Smart+ campaigns
            $campaignId = $requestData['campaign_id'] ?? '';
            
            $url = "https://business-api.tiktok.com/open_api/v1.3/report/integrated/get/?" .
                   "advertiser_id={$advertiser_id}&" .
                   "report_type=BASIC&" .
                   "dimensions=[\"campaign_id\"]&" .
                   "metrics=[\"spend\",\"impressions\",\"clicks\",\"conversions\",\"cost_per_conversion\"]&" .
                   "filters=[{\"field_name\":\"campaign_id\",\"filter_type\":\"IN\",\"filter_value\":[\"$campaignId\"]}]&" .
                   "start_date=" . date('Y-m-d', strtotime('-7 days')) . "&" .
                   "end_date=" . date('Y-m-d');
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? ''),
                    "Content-Type: application/json"
                ],
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $response = json_decode($result, true);
                if ($response && isset($response['code']) && $response['code'] == 0) {
                    echo json_encode([
                        'success' => true,
                        'data' => $response['data']
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => $response['message'] ?? 'Failed to get insights'
                    ]);
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to get campaign insights'
                ]);
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action for Smart+ API'
            ]);
    }
    
} catch (Exception $e) {
    logToFile("Smart+ API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>