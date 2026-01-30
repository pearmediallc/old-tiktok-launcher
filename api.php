<?php
// Disable error display to prevent HTML errors in JSON responses
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Clean any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Log full error details for debugging (server-side only)
        error_log("PHP Fatal Error in api.php: [{$error['type']}] {$error['message']} in {$error['file']} on line {$error['line']}");

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        // Don't expose internal error details to client (security)
        echo json_encode([
            'success' => false,
            'message' => 'An internal server error occurred. Please try again later.'
        ]);
        exit;
    }
});

// Start output buffering immediately to catch any stray output
ob_start();

// Set JSON content type header early
header('Content-Type: application/json; charset=utf-8');

// Increase PHP limits for video uploads
// Note: ini_set doesn't work for upload_max_filesize and post_max_size
// Use .htaccess or php.ini file in this directory instead
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');
@ini_set('max_input_time', '300');

session_start();

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Load Security helper for data redaction
require_once __DIR__ . '/includes/Security.php';

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Load TikTok SDK
require_once __DIR__ . '/sdk/vendor/autoload.php';
require_once __DIR__ . '/state_location_mapping.php';

// Load Database helper
require_once __DIR__ . '/database/Database.php';

use TikTokAds\Campaign\Campaign;
use TikTokAds\AdGroup\AdGroup;
use TikTokAds\Ad\Ad;
use TikTokAds\File\File;
use TikTokAds\Identity\Identity;
use TikTokAds\Tools\Tools;

// SDK Configuration
// Priority: Session OAuth token > .env token
$access_token = isset($_SESSION['oauth_access_token']) && !empty($_SESSION['oauth_access_token'])
    ? $_SESSION['oauth_access_token']
    : ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? '');

if (isset($_SESSION['oauth_access_token']) && !empty($_SESSION['oauth_access_token'])) {
    error_log("Using OAuth token from session");
} else {
    error_log("Using token from .env (fallback)");
}

$config = [
    'access_token' => $access_token,
    'app_id'       => $_ENV['TIKTOK_APP_ID'] ?? '',
    'app_secret'   => $_ENV['TIKTOK_APP_SECRET'] ?? '',
    'environment'  => $_ENV['TIKTOK_ENVIRONMENT'] ?? 'production',
    'api_version'  => 'v1.3'
];

// Get advertiser ID from session or environment variable  
$advertiser_id = $_SESSION['selected_advertiser_id'] ?? $_ENV['TIKTOK_ADVERTISER_ID'] ?? '';

// Additional safety check - if still empty, log warning
if (empty($advertiser_id)) {
    logToFile("WARNING: No advertiser_id available. Session: " . json_encode($_SESSION) . ", ENV: " . ($_ENV['TIKTOK_ADVERTISER_ID'] ?? 'NOT SET'));
}

// Debug: Log the exact advertiser ID being used for all API calls
logToFile("Using advertiser_id for API calls: " . $advertiser_id);

// Logging function
function logToFile($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    error_log($logMessage);  // This will appear in Render logs
    file_put_contents(__DIR__ . '/api_debug.log', $logMessage, FILE_APPEND);
}

// Get current datetime in EST timezone (America/New_York)
// All TikTok campaigns are set in EST timezone for consistency
function getESTDateTime($modifier = null) {
    $est = new DateTimeZone('America/New_York');
    $dt = new DateTime('now', $est);
    if ($modifier) {
        $dt->modify($modifier);
    }
    return $dt->format('Y-m-d H:i:s');
}

// Helper function to output clean JSON response
function outputJsonResponse($data) {
    // Clean all output buffers
    while (ob_get_level() > 0) {
        $bufferedOutput = ob_get_clean();
        if (!empty($bufferedOutput)) {
            logToFile("WARNING: Buffered output cleared: " . substr($bufferedOutput, 0, 500));
        }
    }

    // Set JSON header if not already sent
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    // Output JSON directly
    echo json_encode($data);
    exit; // Ensure no more output after JSON
}

// Helper function to make TikTok API calls
function makeApiCall($url, $params, $accessToken, $method = 'POST') {
    logToFile("API Call: {$method} {$url}");
    logToFile("Params: " . json_encode($params, JSON_PRETTY_PRINT));

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

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    logToFile("Response HTTP Code: " . $httpCode);
    logToFile("Response: " . $result);

    if ($curlError) {
        logToFile("CURL Error: " . $curlError);
        return null;
    }

    return json_decode($result, true);
}

// Download and store TikTok images locally (like video thumbnails)
function downloadAndStoreImage($imageUrl, $imageId, $fileName) {
    if (empty($imageUrl)) {
        return '';
    }
    
    // Create uploads directory if it doesn't exist
    $uploadsDir = __DIR__ . '/uploads/images/';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }
    
    // Clean filename and create local filename
    $cleanFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
    $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    $localFileName = $imageId . '_' . $cleanFileName . '.' . $extension;
    $localFilePath = $uploadsDir . $localFileName;
    
    // Check if file already exists
    if (file_exists($localFilePath)) {
        logToFile("Image already exists locally: " . $localFileName);
        return 'serve-image.php?path=' . urlencode($localFileName);
    }
    
    try {
        logToFile("Downloading image from: " . $imageUrl);
        
        // Download the image
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200 && $imageData !== false) {
            // Save the image locally
            file_put_contents($localFilePath, $imageData);
            logToFile("Image downloaded successfully: " . $localFileName);
            return 'serve-image.php?path=' . urlencode($localFileName);
        } else {
            logToFile("Failed to download image: HTTP " . $httpCode);
            return $imageUrl; // Return original URL as fallback
        }
    } catch (Exception $e) {
        logToFile("Exception downloading image: " . $e->getMessage());
        return $imageUrl; // Return original URL as fallback
    }
}

// Process location targeting data
function processLocationTargeting($locationData) {
    if (empty($locationData)) {
        return ['6252001']; // Default to United States
    }
    
    // Process the location data using our mapping function
    $result = processLocationData($locationData);
    
    if (!empty($result['unmatched'])) {
        logToFile("WARNING: Unmatched locations: " . implode(', ', $result['unmatched']));
    }
    
    // Return location IDs or fallback to US if none found
    return !empty($result['location_ids']) ? $result['location_ids'] : ['6252001'];
}

// Handle API requests
$requestData = json_decode(file_get_contents('php://input'), true) ?? [];

// Check if advertiser_id is passed in the request (prevents cross-tab contamination)
// Frontend passes _advertiser_id to ensure correct context even if PHP session changed in another tab
// For FormData uploads (like video/image), check $_POST as well
$requestedAdvertiserId = $requestData['_advertiser_id']
    ?? $requestData['advertiser_id']
    ?? $_POST['_advertiser_id']
    ?? $_POST['advertiser_id']
    ?? null;

if (!empty($requestedAdvertiserId)) {
    // Validate that this advertiser ID is in the user's authorized list
    $authorizedIds = $_SESSION['oauth_advertiser_ids'] ?? [];
    if (in_array($requestedAdvertiserId, $authorizedIds)) {
        $advertiser_id = $requestedAdvertiserId;
        logToFile("Using request advertiser ID: $advertiser_id (overriding session)");
    } else {
        logToFile("WARNING: Requested advertiser ID $requestedAdvertiserId not in authorized list, using session");
    }
}

// Get action from GET, POST, or JSON body
$action = $_GET['action'] ?? $_POST['action'] ?? $requestData['action'] ?? '';

// Log incoming request
logToFile("============ INCOMING REQUEST ============");
logToFile("Action: {$action}");
logToFile("=== API REQUEST RECEIVED ===");
logToFile("Action: " . $action);
logToFile("Advertiser ID: " . $advertiser_id);
// Redact sensitive data before logging
$safeRequestData = Security::redactSensitiveData($requestData);
$headers = function_exists('getallheaders') ? getallheaders() : [];
$safeHeaders = Security::redactSensitiveData($headers);
logToFile("Request Data: " . json_encode($safeRequestData, JSON_PRETTY_PRINT));
logToFile("HTTP Headers: " . json_encode($safeHeaders, JSON_PRETTY_PRINT));
logToFile("==============================");

header('Content-Type: application/json');

// Security: Whitelist of allowed API actions
$allowedActions = [
    'set_oauth_advertiser',
    'test_auth',
    'publish_smart_plus_campaign',
    'create_smart_campaign',
    'create_smart_adgroup',
    'create_smart_ad',
    'get_advertisers',
    'set_advertiser',
    'create_campaign',
    'create_adgroup',
    'get_dynamic_ctas',
    'create_cta_portfolio',
    'get_cta_portfolios',
    'get_portfolio_details',
    'get_or_create_frequently_used_cta_portfolio',
    'create_ad',
    'upload_thumbnail_as_cover',
    'upload_image',
    'upload_video',
    'upload_video_to_advertiser',
    'upload_video_direct',
    'get_identities',
    'get_tiktok_posts',
    'get_video_by_auth_code',
    'get_pixels',
    'get_images',
    'get_videos',
    'get_campaigns',
    'get_adgroups',
    'get_ads',
    'publish_ads',
    'duplicate_ad',
    'duplicate_adgroup',
    'bulk_duplicate_campaign',
    'sync_images_from_tiktok',
    'sync_tiktok_library',
    'add_existing_media',
    'logout',
    'get_selected_advertiser',
    'create_identity',
    'generate_video_thumbnail',
    'get_advertiser_info',
    'get_timezones',
    'auto_crop_and_upload'
];

// Reject unknown actions
if (!empty($action) && !in_array($action, $allowedActions)) {
    logToFile("SECURITY: Blocked unknown action: " . $action);
    outputJsonResponse([
        'success' => false,
        'message' => 'Invalid action'
    ]);
}

try {
    switch ($action) {
        case 'set_oauth_advertiser':
            // Set OAuth advertiser and campaign type from selection page
            $advertiserId = $requestData['advertiser_id'] ?? '';
            $campaignType = $requestData['campaign_type'] ?? 'manual';

            if (empty($advertiserId)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Advertiser ID is required'
                ]);
                exit;
            }

            // Verify this advertiser ID is in the authorized list
            if (!isset($_SESSION['oauth_advertiser_ids']) || !in_array($advertiserId, $_SESSION['oauth_advertiser_ids'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized advertiser ID'
                ]);
                exit;
            }

            // Set selected advertiser and campaign type in session
            $_SESSION['selected_advertiser_id'] = $advertiserId;
            $_SESSION['campaign_type'] = $campaignType;
            $_SESSION['authenticated'] = true;

            error_log("OAuth: Set advertiser ID to " . $advertiserId . " with campaign type: " . $campaignType);

            echo json_encode([
                'success' => true,
                'message' => 'Advertiser and campaign type set successfully',
                'advertiser_id' => $advertiserId
            ]);
            break;

        case 'test_auth':
            // Only return authentication status - no sensitive session data
            echo json_encode([
                'success' => true,
                'authenticated' => isset($_SESSION['authenticated']) && $_SESSION['authenticated'],
                'has_advertiser' => !empty($advertiser_id),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // Smart+ Lead Generation Campaign using /campaign/spc/create/
        // Uses Spark Ads (TT_USER/AUTH_CODE with tiktok_item_id)
        case 'publish_smart_plus_campaign':
            logToFile("============ PUBLISH SMART+ CAMPAIGN (SPC) ============");
            $data = $requestData;

            // Validate required fields for Smart+ with Spark Ads
            $requiredFields = ['campaign_name', 'budget', 'identity_id', 'identity_type', 'tiktok_posts', 'landing_page_url'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Missing required field: {$field}"
                    ]);
                    exit;
                }
            }

            // Validate identity_type is valid for Smart+ Lead Gen (must be Spark Ads)
            $validIdentityTypes = ['TT_USER', 'AUTH_CODE', 'BC_AUTH_TT'];
            if (!in_array($data['identity_type'], $validIdentityTypes)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Smart+ Lead Generation requires Spark Ads. identity_type must be TT_USER, AUTH_CODE, or BC_AUTH_TT'
                ]);
                exit;
            }

            // Get access token
            $accessToken = isset($_SESSION['oauth_access_token']) && !empty($_SESSION['oauth_access_token'])
                ? $_SESSION['oauth_access_token']
                : ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? '');

            // Schedule times - use EST timezone for consistency
            $scheduleStartTime = $data['schedule_start_time'] ?? getESTDateTime('+1 hour');
            $scheduleEndTime = $data['schedule_end_time'] ?? getESTDateTime('+1 year');

            try {
                // Build media_info_list for Spark Ads (TikTok posts)
                $mediaInfoList = [];
                foreach ($data['tiktok_posts'] as $post) {
                    $mediaInfo = [
                        'identity_type' => $data['identity_type'],
                        'identity_id' => $data['identity_id'],
                        'tiktok_item_id' => $post['tiktok_item_id']
                    ];

                    // Add identity_authorized_bc_id if using BC_AUTH_TT
                    if ($data['identity_type'] === 'BC_AUTH_TT' && !empty($data['identity_authorized_bc_id'])) {
                        $mediaInfo['identity_authorized_bc_id'] = $data['identity_authorized_bc_id'];
                    }

                    $mediaInfoList[] = ['media_info' => $mediaInfo];
                }

                // Build Smart+ Campaign payload for /campaign/spc/create/
                $spcParams = [
                    'advertiser_id' => $advertiser_id,
                    'objective_type' => 'LEAD_GENERATION',
                    'campaign_name' => $data['campaign_name'],

                    // Promotion type - Website (EXTERNAL_WEBSITE) or Instant Form (INSTANT_PAGE)
                    'promotion_type' => 'LEAD_GENERATION',
                    'promotion_target_type' => $data['promotion_target_type'] ?? 'EXTERNAL_WEBSITE',

                    // Optimization goal
                    'optimization_goal' => $data['optimization_goal'] ?? 'CONVERT',

                    // Placement
                    'placement_type' => 'PLACEMENT_TYPE_NORMAL',
                    'placements' => ['PLACEMENT_TIKTOK'],

                    // Targeting
                    'location_ids' => $data['location_ids'] ?? ['6252001'],
                    'spc_audience_age' => $data['spc_audience_age'] ?? '25+',

                    // Budget & Schedule
                    'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',
                    'budget' => floatval($data['budget']),
                    'schedule_type' => 'SCHEDULE_START_END',
                    'schedule_start_time' => $scheduleStartTime,
                    'schedule_end_time' => $scheduleEndTime,

                    // Bidding
                    'bid_type' => $data['bid_type'] ?? 'BID_TYPE_NO_BID',
                    'billing_event' => 'OCPM',

                    // Spark Ads media (TikTok posts)
                    'media_info_list' => $mediaInfoList,

                    // Destination
                    'landing_page_urls' => [
                        ['landing_page_url' => $data['landing_page_url']]
                    ],

                    // Dynamic CTA (required for Lead Gen)
                    'call_to_action_id' => $data['call_to_action_id'] ?? null
                ];

                // Add pixel and optimization_event for Website optimization
                if (($data['promotion_target_type'] ?? 'EXTERNAL_WEBSITE') === 'EXTERNAL_WEBSITE') {
                    if (!empty($data['pixel_id'])) {
                        $spcParams['pixel_id'] = $data['pixel_id'];
                        $spcParams['optimization_event'] = $data['optimization_event'] ?? 'FORM';
                    }
                }

                // Add conversion_bid_price if using custom bid
                if (($data['bid_type'] ?? '') === 'BID_TYPE_CUSTOM' && !empty($data['conversion_bid_price'])) {
                    $spcParams['conversion_bid_price'] = floatval($data['conversion_bid_price']);
                }

                // Remove null values
                $spcParams = array_filter($spcParams, function($v) { return $v !== null; });

                logToFile("Smart+ SPC Params: " . json_encode($spcParams, JSON_PRETTY_PRINT));

                // Call /campaign/spc/create/ endpoint
                $result = makeApiCall(
                    'https://business-api.tiktok.com/open_api/v1.3/campaign/spc/create/',
                    $spcParams,
                    $accessToken
                );

                logToFile("Smart+ SPC Response: " . json_encode($result, JSON_PRETTY_PRINT));

                if ($result && isset($result['code']) && $result['code'] == 0) {
                    logToFile("============ SMART+ CAMPAIGN CREATED SUCCESSFULLY ============");
                    echo json_encode([
                        'success' => true,
                        'data' => $result['data'] ?? null,
                        'message' => 'Smart+ Campaign published successfully!'
                    ]);
                } else {
                    throw new Exception($result['message'] ?? 'Failed to create Smart+ campaign');
                }

            } catch (Exception $e) {
                logToFile("Smart+ Campaign Error: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
            }
            exit;

        case 'create_smart_campaign':
            // LEGACY: Old multi-step campaign creation - kept for backward compatibility
            logToFile("Processing Smart+ Campaign Creation (Legacy)...");
            $data = $requestData;

            // Smart+ campaigns using official TikTok API structure
            // Campaigns start in PAUSED state - user must manually enable
            $params = [
                'advertiser_id' => $advertiser_id,
                'operation_status' => 'DISABLE',
                'objective_type' => 'LEAD_GENERATION',
                'campaign_type' => 'REGULAR_CAMPAIGN',
                'campaign_name' => $data['campaign_name'],
                'promotion_type' => 'LEAD_GENERATION',
                'promotion_target_type' => 'EXTERNAL_WEBSITE', // Required for Lead Gen
                'placement_type' => 'PLACEMENT_TYPE_NORMAL', // Required for Lead Gen
                'location_ids' => ['6252001'], // United States
                // Budget will be set based on CBO setting
                'budget_mode' => 'BUDGET_MODE_INFINITE', // Default - budget set at ad group level
                'budget_optimize_on' => false,
                'schedule_type' => 'SCHEDULE_START_END',
                'schedule_start_time' => getESTDateTime(),
                'schedule_end_time' => getESTDateTime('+1 year'),
                'optimization_goal' => 'LEAD_GENERATION',
                'bid_type' => 'BID_TYPE_NO_BID',
                'billing_event' => 'OCPM',
                // Note: identity_type and identity_id are not required for Smart+ Lead Gen campaigns
                // They are only required when creating ads, not campaigns
                'spc_audience_age' => '18+',
                'exclude_age_under_eighteen' => true,
                'gender' => 'GENDER_UNLIMITED',
                'click_attribution_window' => 'SEVEN_DAYS',
                'view_attribution_window' => 'ONE_DAY',
                'attribution_event_count' => 'EVERY'
            ];

            // Override schedule times if provided from frontend
            if (!empty($data['schedule_start_time'])) {
                $params['schedule_start_time'] = $data['schedule_start_time'];

                // If end time is provided, use it; otherwise set a default end time (1 year from start)
                if (!empty($data['schedule_end_time'])) {
                    $params['schedule_end_time'] = $data['schedule_end_time'];
                } else {
                    // Set default end time to 1 year from start time
                    $startTime = new DateTime($data['schedule_start_time']);
                    $endTime = clone $startTime;
                    $endTime->add(new DateInterval('P1Y')); // Add 1 year
                    $params['schedule_end_time'] = $endTime->format('Y-m-d H:i:s');
                }
            }

            // Handle CBO (Campaign Budget Optimization) settings
            if (isset($data['cbo_enabled']) && $data['cbo_enabled'] === true) {
                // CBO enabled - set budget at campaign level
                $params['budget_mode'] = 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET';
                $params['budget_optimize_on'] = true;
                $params['budget'] = floatval($data['campaign_budget'] ?? 50);
                logToFile("CBO Enabled - Campaign budget: " . $params['budget']);
            } else {
                // CBO disabled - budget set at ad group level only
                $params['budget_mode'] = 'BUDGET_MODE_INFINITE';
                $params['budget_optimize_on'] = false;
                // No budget parameter needed for BUDGET_MODE_INFINITE
                logToFile("CBO Disabled - Budget will be set at ad group level");
            }

            // Note: Smart+ Lead Generation campaigns don't require identity_id at campaign level
            // Identity is only required when creating ads within the campaign

            logToFile("=== SMART+ CAMPAIGN API CALL ===");
            logToFile("TikTok API Endpoint: /campaign/spc/create/");
            logToFile("Smart+ Campaign Params: " . json_encode($params, JSON_PRETTY_PRINT));
            logToFile("===============================");

            // Use direct API call for Smart+ Campaign endpoint
            $accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
            $url = "https://business-api.tiktok.com/open_api/v1.3/campaign/spc/create/";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($params),
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . $accessToken,
                    "Content-Type: application/json"
                ],
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logToFile("=== SMART+ API RESPONSE ===");
            logToFile("HTTP Code: " . $httpCode);
            logToFile("Raw Response: " . $result);
            logToFile("===========================");

            if ($httpCode === 200) {
                $response = json_decode($result, true);
                if ($response && isset($response['code']) && $response['code'] == 0) {
                    logToFile("Smart+ Campaign created successfully");
                    $smartResponse = [
                        'success' => true,
                        'data' => $response['data'],
                        'message' => 'Smart+ Campaign created successfully'
                    ];
                } else {
                    logToFile("Smart+ Campaign creation failed: " . json_encode($response));
                    $smartResponse = [
                        'success' => false,
                        'message' => $response['message'] ?? 'Failed to create Smart+ campaign',
                        'error' => $response
                    ];
                }
            } else {
                logToFile("HTTP error: " . $httpCode);
                $smartResponse = [
                    'success' => false,
                    'message' => 'API request failed with HTTP ' . $httpCode,
                    'error' => $result
                ];
            }

            logToFile("=== FINAL RESPONSE ===");
            logToFile("Sending response: " . json_encode($smartResponse, JSON_PRETTY_PRINT));
            echo json_encode($smartResponse);
            logToFile("Smart+ Campaign response sent, exiting...");
            exit;
            
        case 'create_smart_adgroup':
            logToFile("Processing Smart+ Ad Group Creation...");
            $data = $requestData;
            
            // Smart+ Ad Groups use regular ad group endpoint but with Smart+ campaign
            $params = [
                'advertiser_id' => $advertiser_id,
                'campaign_id' => $data['campaign_id'],
                'adgroup_name' => $data['adgroup_name'],
                'promotion_type' => 'LEAD_GENERATION',
                'promotion_target_type' => 'EXTERNAL_WEBSITE',
                'pixel_id' => $data['pixel_id'],
                'optimization_goal' => 'LEAD_GENERATION',
                'optimization_event' => 'FORM',
                'billing_event' => 'OCPM',
                
                // Smart+ campaigns automatically set placement
                'placement_type' => 'PLACEMENT_TYPE_NORMAL',
                'placements' => ['PLACEMENT_TIKTOK'],
                
                // Targeting 
                'location_ids' => processLocationTargeting($data['location_ids'] ?? ['6252001']),
                'age_groups' => $data['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'],
                'gender' => 'GENDER_UNLIMITED',
                
                // Budget and scheduling
                'budget_mode' => 'BUDGET_MODE_DAY',
                'budget' => floatval($data['budget'] ?? 50),
                'schedule_type' => 'SCHEDULE_FROM_NOW',
                'schedule_start_time' => $data['schedule_start_time'],
                
                // Bidding for Smart+
                'bid_type' => 'BID_TYPE_CUSTOM',
                'conversion_bid_price' => floatval($data['conversion_bid_price'] ?? 10),
                'pacing' => 'PACING_MODE_SMOOTH',
                
                // Attribution windows
                'click_attribution_window' => 'SEVEN_DAYS',
                'view_attribution_window' => 'ONE_DAY',
                'attribution_event_count' => 'EVERY'
            ];
            
            logToFile("=== SMART+ AD GROUP API CALL ===");
            logToFile("TikTok API Endpoint: /adgroup/create/");
            logToFile("Smart+ Ad Group Params: " . json_encode($params, JSON_PRETTY_PRINT));
            logToFile("===============================");
            
            $adGroup = new AdGroup($config);
            $result = $adGroup->create($params);
            
            logToFile("=== SMART+ AD GROUP RESPONSE ===");
            logToFile("TikTok Response: " . json_encode($result, JSON_PRETTY_PRINT));
            logToFile("===============================");
            
            $adGroupResponse = null;
            if ($result['code'] == 0) {
                $adGroupResponse = [
                    'success' => true,
                    'data' => $result['data'],
                    'message' => 'Smart+ Ad Group created successfully'
                ];
            } else {
                $adGroupResponse = [
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to create Smart+ ad group',
                    'error' => $result
                ];
            }
            
            logToFile("=== AD GROUP FINAL RESPONSE ===");
            logToFile("Sending response: " . json_encode($adGroupResponse, JSON_PRETTY_PRINT));
            echo json_encode($adGroupResponse);
            logToFile("Smart+ Ad Group response sent, exiting...");
            exit;
            
        case 'create_smart_ad':
            $data = $requestData;
            $mediaList = $data['media_list'] ?? [];
            $textList = $data['ad_texts'] ?? [];
            
            // For now, create a single ad with first media and text
            $params = [
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
            
            logToFile("Smart+ Ad Params: " . json_encode($params, JSON_PRETTY_PRINT));
            
            $ad = new Ad($config);
            $result = $ad->create($params);
            
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
            exit;
        case 'get_advertisers':
            // Get list of advertiser accounts for the authenticated user
            $appId = $_ENV['TIKTOK_APP_ID'] ?? '';
            $secret = $_ENV['TIKTOK_APP_SECRET'] ?? '';
            $accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
            
            if (empty($appId) || empty($secret)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'App ID or Secret not configured'
                ]);
                exit;
            }
            
            $url = "https://business-api.tiktok.com/open_api/v1.3/oauth2/advertiser/get/";
            
            // Add query parameters
            $params = [
                'app_id' => $appId,
                'secret' => $secret
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url . '?' . http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . $accessToken,
                    "Content-Type: application/json"
                ],
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            logToFile("Get Advertisers Response - HTTP Code: {$httpCode}");
            logToFile("Response: " . $result);
            
            if ($httpCode === 200) {
                $response = json_decode($result, true);
                if ($response && isset($response['code']) && $response['code'] == 0) {
                    echo json_encode([
                        'success' => true,
                        'data' => $response['data']['list'] ?? []
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => $response['message'] ?? 'Failed to get advertisers'
                    ]);
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'API request failed with HTTP code: ' . $httpCode
                ]);
            }
            break;
            
        case 'set_advertiser':
            // Set the selected advertiser ID for the session
            $selectedAdvertiserId = $requestData['advertiser_id'] ?? '';
            
            logToFile("Set Advertiser Request - ID: {$selectedAdvertiserId}");
            
            if (empty($selectedAdvertiserId)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Advertiser ID is required'
                ]);
                exit;
            }
            
            // Store in session
            $_SESSION['selected_advertiser_id'] = $selectedAdvertiserId;
            
            logToFile("Advertiser ID stored in session: {$selectedAdvertiserId}");
            
            echo json_encode([
                'success' => true,
                'message' => 'Advertiser selected successfully',
                'advertiser_id' => $selectedAdvertiserId,
                'redirect' => 'campaign-select.php'
            ]);
            exit; // Ensure we exit to prevent any additional output
            
        case 'test_image_search':
            // Direct test of image search API - matching TikTok docs exactly
            header('Content-Type: application/json');
            
            $url = "https://business-api.tiktok.com/open_api/v1.3/file/image/ad/search/?" . 
                   "advertiser_id={$advertiser_id}&" .
                   "page=1&" .
                   "page_size=10";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? '')
                ]
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $response = json_decode($result);
            
            echo json_encode([
                'success' => $httpCode == 200 && isset($response->data),
                'http_code' => $httpCode,
                'image_count' => isset($response->data->list) ? count($response->data->list) : 0,
                'images' => $response->data->list ?? [],
                'raw' => $response
            ], JSON_PRETTY_PRINT);
            exit;
            
        case 'create_campaign':
            $campaign = new Campaign($config);
            $data = $requestData;

            // Base parameters
            $params = [
                'advertiser_id' => $advertiser_id,
                'campaign_name' => $data['campaign_name'],
                'objective_type' => 'LEAD_GENERATION',
                'operation_status' => 'DISABLE' // Start paused - user must manually enable
            ];

            // Handle CBO (Campaign Budget Optimization) settings
            if (isset($data['cbo_enabled']) && $data['cbo_enabled'] === true) {
                // CBO enabled - set budget at campaign level
                $params['budget_mode'] = $data['budget_mode'] ?? 'BUDGET_MODE_DAY';
                $params['budget'] = floatval($data['budget'] ?? 20);
                $params['budget_optimize_on'] = true;
                logToFile("Manual Campaign CBO Enabled - Campaign budget: " . $params['budget']);
            } else {
                // CBO disabled - budget set at ad group level only
                $params['budget_mode'] = 'BUDGET_MODE_INFINITE';
                $params['budget_optimize_on'] = false;
                // No budget parameter needed for BUDGET_MODE_INFINITE
                logToFile("Manual Campaign CBO Disabled - Budget will be set at ad group level");
            }

            // Schedule times are optional
            if (!empty($data['schedule_start_time'])) {
                $params['schedule_start_time'] = $data['schedule_start_time'];
            }
            if (!empty($data['schedule_end_time'])) {
                $params['schedule_end_time'] = $data['schedule_end_time'];
            }

            logToFile("TikTok API: POST /open_api/v1.3/campaign/create/");
            logToFile("Campaign Params: " . json_encode($params, JSON_PRETTY_PRINT));

            $response = $campaign->create($params);

            logToFile("Campaign Response: " . json_encode($response, JSON_PRETTY_PRINT));
            logToFile("Response Code: " . ($response->code ?? 'null'));
            logToFile("Response Message: " . ($response->message ?? 'null'));

            echo json_encode([
                'success' => empty($response->code),
                'data' => $response->data ?? null,
                'message' => $response->message ?? 'Campaign created successfully'
            ]);
            break;

        case 'create_adgroup':
            $adGroup = new AdGroup($config);
            $data = $requestData;

            function is_valid_datetime($s) {
                return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s);
            }

            function generate_time_series($startHour, $endHour, $days = [0,1,2,3,4,5,6]) {
                // Generate 336 character string (7 days × 48 half-hour slots)
                $ts = '';
                for ($d = 0; $d < 7; $d++) {
                    for ($h = 0; $h < 24; $h++) {
                        // Each hour has 2 half-hour slots
                        if (in_array($d, $days) && $h >= $startHour && $h < $endHour) {
                            $ts .= '11'; // Both half-hour slots enabled
                        } else {
                            $ts .= '00'; // Both half-hour slots disabled
                        }
                    }
                }
                return $ts;
            }

            $required_fields = ['campaign_id', 'adgroup_name', 'placement_type', 'placements',
                                'promotion_type', 'optimization_goal', 'billing_event', 'budget_mode', 'budget'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Required field missing: {$field}"]);
                    exit;
                }
            }

            if ($data['budget_mode'] === 'BUDGET_MODE_TOTAL') {
                if (empty($data['schedule_end_time'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'schedule_end_time is required when budget_mode is BUDGET_MODE_TOTAL']);
                    exit;
                }
            }

            if (in_array($data['optimization_goal'], ['CONVERT', 'VALUE'])) {
                if (empty($data['pixel_id'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'pixel_id is required when optimization_goal is CONVERT or VALUE']);
                    exit;
                }
            }
            
            // For Lead Generation via website forms (CONVERT + FORM event)
            if ($data['optimization_goal'] === 'CONVERT' && 
                isset($data['optimization_event']) && $data['optimization_event'] === 'FORM') {
                if (empty($data['pixel_id'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'pixel_id is required for Lead Generation campaigns with FORM optimization']);
                    exit;
                }
            }
            
            // For instant form Lead Generation (if using lead_gen_form_id)
            if ($data['optimization_goal'] === 'LEAD_GENERATION' && 
                (!isset($data['promotion_target_type']) || $data['promotion_target_type'] === 'INSTANT_PAGE')) {
                if (empty($data['lead_gen_form_id'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'lead_gen_form_id is required for Lead Generation campaigns with Instant Forms']);
                    exit;
                }
            }

            if ($data['promotion_type'] === 'LEAD_GEN_CLICK_TO_SOCIAL_MEDIA_APP_MESSAGE') {
                if (empty($data['messaging_app_type'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'messaging_app_type is required for LEAD_GEN_CLICK_TO_SOCIAL_MEDIA_APP_MESSAGE']);
                    exit;
                }

                if ($data['optimization_goal'] === 'CONVERSATION') {
                    if (in_array($data['messaging_app_type'], ['ZALO', 'LINE', 'IM_URL'])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'When optimization_goal is CONVERSATION, messaging_app_type cannot be ZALO, LINE, or IM_URL']);
                        exit;
                    }

                    if (empty($data['message_event_set_id']) && empty($data['messaging_app_account_id'])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'message_event_set_id or messaging_app_account_id is required when optimization_goal is CONVERSATION']);
                        exit;
                    }
                }
            }

            $params = [
                'advertiser_id'      => $advertiser_id,
                'campaign_id'        => $data['campaign_id'],
                'adgroup_name'       => $data['adgroup_name'],
                'promotion_type'     => $data['promotion_type'],
                'optimization_goal'  => $data['optimization_goal'],
                'billing_event'      => $data['billing_event'],
                'placement_type'     => $data['placement_type'],
                'placements'         => $data['placements'],
                'budget_mode'        => $data['budget_mode'],
                'budget'             => floatval($data['budget']),
                'bid_type'           => $data['bid_type'],
                'location_ids'       => $data['location_ids'] ?? ['6252001'],
            ];

            if (!empty($data['conversion_bid_price']) && floatval($data['conversion_bid_price']) > 0) {
                $params['conversion_bid_price'] = floatval($data['conversion_bid_price']);
            } elseif (!empty($data['bid']) && floatval($data['bid']) > 0) {
                $params['bid'] = floatval($data['bid']);
            }

            if (!empty($data['lead_gen_form_id'])) {
                $params['lead_gen_form_id'] = $data['lead_gen_form_id'];
            }
            if (!empty($data['pixel_id'])) {
                $params['pixel_id'] = strval($data['pixel_id']);
            }
            if (!empty($data['promotion_target_type'])) {
                $params['promotion_target_type'] = $data['promotion_target_type'];
            }
            if (!empty($data['optimization_event'])) {
                $params['optimization_event'] = $data['optimization_event'];
            }
            if (!empty($data['custom_conversion_id'])) {
                $params['custom_conversion_id'] = $data['custom_conversion_id'];
            }
            if (!empty($data['click_attribution_window'])) {
                $params['click_attribution_window'] = $data['click_attribution_window'];
            }
            if (!empty($data['view_attribution_window'])) {
                $params['view_attribution_window'] = $data['view_attribution_window'];
            }
            if (!empty($data['attribution_event_count'])) {
                $params['attribution_event_count'] = $data['attribution_event_count'];
            }
            if (!empty($data['age_groups'])) {
                $params['age_groups'] = $data['age_groups'];
            }
            if (!empty($data['gender'])) {
                $params['gender'] = $data['gender'];
            }
            if (!empty($data['pacing'])) {
                $params['pacing'] = $data['pacing'];
            }
            if (!empty($data['messaging_app_type'])) {
                $params['messaging_app_type'] = $data['messaging_app_type'];
            }
            if (!empty($data['messaging_app_account_id'])) {
                $params['messaging_app_account_id'] = $data['messaging_app_account_id'];
            }
            if (!empty($data['message_event_set_id'])) {
                $params['message_event_set_id'] = $data['message_event_set_id'];
            }
            if (!empty($data['deep_funnel_optimization_status'])) {
                $params['deep_funnel_optimization_status'] = $data['deep_funnel_optimization_status'];
            }
            if (isset($data['search_result_enabled'])) {
                $params['search_result_enabled'] = (bool)$data['search_result_enabled'];
            }
            if (isset($data['share_disabled'])) {
                $params['share_disabled'] = (bool)$data['share_disabled'];
            }
            if (!empty($data['purchase_intention_keyword_ids'])) {
                $params['purchase_intention_keyword_ids'] = $data['purchase_intention_keyword_ids'];
            }
            if (!empty($data['category_exclusion_ids'])) {
                $params['category_exclusion_ids'] = $data['category_exclusion_ids'];
            }

            if (!empty($data['schedule_type'])) {
                $params['schedule_type'] = $data['schedule_type'];
            }

            // NOTE: Timezone is NOT set at ad group level - it's set at advertiser account level
            // TikTok API will use the advertiser's account timezone (should be America/Bogota for Colombia)
            // We use Unix timestamps (start_time and end_time) in UTC
            // TikTok interprets them according to the advertiser's timezone setting
            if (!empty($data['timezone'])) {
                logToFile("⚠️  Note: Timezone '{$data['timezone']}' is sent by frontend but will be ignored by TikTok API");
                logToFile("    TikTok uses the advertiser account timezone instead (check with get_advertiser_info endpoint)");
            }

            // TikTok API expects datetime strings in format: YYYY-MM-DD HH:MM:SS (UTC+0)
            // Documentation: schedule_start_time is type "datetime" not Unix timestamp
            // Send datetime string directly - NO conversion to timestamp
            if (!empty($data['schedule_start_time'])) {
                if (!is_valid_datetime($data['schedule_start_time'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'schedule_start_time must be YYYY-MM-DD HH:MM:SS (UTC)']);
                    exit;
                }
                // Send datetime string directly to TikTok API (format: YYYY-MM-DD HH:MM:SS)
                $params['schedule_start_time'] = $data['schedule_start_time'];
                logToFile("📅 Schedule start time (UTC datetime string): {$data['schedule_start_time']}");
            }

            if (!empty($data['schedule_end_time'])) {
                if (!is_valid_datetime($data['schedule_end_time'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'schedule_end_time must be YYYY-MM-DD HH:MM:SS (UTC)']);
                    exit;
                }
                // Send datetime string directly to TikTok API (format: YYYY-MM-DD HH:MM:SS)
                $params['schedule_end_time'] = $data['schedule_end_time'];
                logToFile("📅 Schedule end time (UTC datetime string): {$data['schedule_end_time']}");
            }

            // Handle dayparting
            if (!empty($data['dayparting'])) {
                logToFile("Dayparting received: length=" . strlen($data['dayparting']));
                logToFile("First 48 chars (Monday 00:00-23:59): " . substr($data['dayparting'], 0, 48));
                
                // TikTok expects 336 characters (7 days × 48 half-hour slots)
                if (strlen($data['dayparting']) !== 336) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'dayparting must be 336-char binary string (7 days × 48 half-hour slots, got ' . strlen($data['dayparting']) . ' chars)']);
                    exit;
                }
                
                // Validate that it only contains 0s and 1s
                if (!preg_match('/^[01]{336}$/', $data['dayparting'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'dayparting must contain only 0s and 1s']);
                    exit;
                }
                
                // Only set dayparting if at least one slot is selected
                if (strpos($data['dayparting'], '1') !== false) {
                    $params['dayparting'] = $data['dayparting'];
                    logToFile("Dayparting will be sent to TikTok API (336 chars)");
                } else {
                    logToFile("Dayparting string has no selected time slots, skipping");
                }
            } elseif (isset($data['daypart_start_hour']) && isset($data['daypart_end_hour'])) {
                $params['dayparting'] = generate_time_series(
                    intval($data['daypart_start_hour']),
                    intval($data['daypart_end_hour']),
                    $data['daypart_days'] ?? [0,1,2,3,4,5,6]
                );
                logToFile("Generated dayparting from hours: " . $params['dayparting']);
            }

            logToFile("TikTok API: POST /open_api/v1.3/adgroup/create/");
            logToFile("AdGroup Params: " . json_encode($params, JSON_PRETTY_PRINT));

            $response = $adGroup->create($params);

            logToFile("AdGroup Response: " . json_encode($response, JSON_PRETTY_PRINT));
            logToFile("Response Code: " . ($response->code ?? 'null'));
            logToFile("Response Message: " . ($response->message ?? 'null'));

            echo json_encode([
                'success' => empty($response->code),
                'data'    => $response->data ?? null,
                'message' => $response->message ?? 'Ad group created',
                'code'    => $response->code ?? null
            ]);
            break;

        case 'get_dynamic_ctas':
            // GET request to TikTok API to get dynamic CTA recommendations
            $content_type = $_GET['content_type'] ?? 'APP_DOWNLOAD';

            // Validate access_token
            if (empty($config['access_token'])) {
                logToFile("ERROR: access_token is empty in config");
                echo json_encode([
                    'success' => false,
                    'message' => 'The access_token is empty. Please check your environment configuration.',
                    'code' => null
                ]);
                exit;
            }

            $url = "https://business-api.tiktok.com/open_api/v1.3/creative/cta/recommend/?" . http_build_query([
                'advertiser_id' => $advertiser_id,
                'asset_type' => 'CTA_AUTO_OPTIMIZED',
                'content_type' => $content_type
            ]);

            logToFile("GET Dynamic CTAs Request:");
            logToFile("  URL: " . $url);
            logToFile("  Content Type: " . $content_type);
            logToFile("  Advertiser ID: " . $advertiser_id);
            logToFile("  Access Token: " . (empty($config['access_token']) ? 'EMPTY' : 'SET (length: ' . strlen($config['access_token']) . ')'));

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Access-Token: ' . $config['access_token']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logToFile("Dynamic CTAs Response Code: " . $httpCode);
            logToFile("Dynamic CTAs Response: " . $response);

            $responseData = json_decode($response, true);

            echo json_encode([
                'success' => $httpCode === 200 && isset($responseData['code']) && $responseData['code'] === 0,
                'data' => $responseData['data'] ?? null,
                'message' => $responseData['message'] ?? 'Failed to fetch dynamic CTAs',
                'code' => $responseData['code'] ?? null
            ]);
            break;

        case 'create_cta_portfolio':
            // POST request to create CTA portfolio with portfolio_content structure
            $portfolio_content = $requestData['portfolio_content'] ?? [];

            if (empty($portfolio_content)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'portfolio_content is required'
                ]);
                exit;
            }

            // Validate access_token
            if (empty($config['access_token'])) {
                logToFile("ERROR: access_token is empty in config");
                echo json_encode([
                    'success' => false,
                    'message' => 'The access_token is empty. Please check your environment configuration.',
                    'code' => null
                ]);
                exit;
            }

            $params = [
                'advertiser_id' => $advertiser_id,
                'creative_portfolio_type' => 'CTA',
                'portfolio_content' => $portfolio_content
            ];

            logToFile("======= CREATE CTA PORTFOLIO REQUEST =======");
            logToFile("Advertiser ID: " . $advertiser_id);
            logToFile("Portfolio Type: CTA");
            logToFile("Portfolio Content Count: " . count($portfolio_content));
            logToFile("Access Token: " . (empty($config['access_token']) ? 'EMPTY' : 'SET (length: ' . strlen($config['access_token']) . ')'));
            logToFile("Request Payload (JSON): " . json_encode($params));
            logToFile("Full Request Params:");
            foreach ($portfolio_content as $idx => $item) {
                logToFile("  CTA " . ($idx + 1) . ":");
                logToFile("    asset_content: " . $item['asset_content']);
                logToFile("    asset_ids: " . json_encode($item['asset_ids']));
                logToFile("    asset_ids types: " . implode(', ', array_map('gettype', $item['asset_ids'])));
            }

            $url = "https://business-api.tiktok.com/open_api/v1.3/creative/portfolio/create/";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Access-Token: ' . $config['access_token'],
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            logToFile("======= CREATE CTA PORTFOLIO RESPONSE =======");
            logToFile("HTTP Status Code: " . $httpCode);
            if ($curlError) {
                logToFile("CURL Error: " . $curlError);
            }
            logToFile("Raw Response: " . $response);

            $responseData = json_decode($response, true);
            logToFile("Parsed Response:");
            logToFile("  code: " . ($responseData['code'] ?? 'NULL'));
            logToFile("  message: " . ($responseData['message'] ?? 'NULL'));
            logToFile("  request_id: " . ($responseData['request_id'] ?? 'NULL'));

            // Normalize the response: TikTok returns creative_portfolio_id, but we want portfolio_id
            if (isset($responseData['data']['creative_portfolio_id']) && !isset($responseData['data']['portfolio_id'])) {
                $responseData['data']['portfolio_id'] = $responseData['data']['creative_portfolio_id'];
                logToFile("Normalized creative_portfolio_id to portfolio_id: " . $responseData['data']['portfolio_id']);
            }

            // Enhanced error logging
            if (!isset($responseData['code']) || $responseData['code'] !== 0) {
                logToFile("======= ERROR: Portfolio creation failed =======");
                logToFile("  API Response Code: " . ($responseData['code'] ?? 'NULL'));
                logToFile("  API Response Message: " . ($responseData['message'] ?? 'NULL'));
                if (isset($responseData['data'])) {
                    logToFile("  Response Data: " . json_encode($responseData['data'], JSON_PRETTY_PRINT));
                }
                logToFile("  Full Response: " . json_encode($responseData, JSON_PRETTY_PRINT));
            } else {
                logToFile("======= SUCCESS: Portfolio created =======");
                logToFile("  Portfolio ID: " . ($responseData['data']['portfolio_id'] ?? 'NULL'));
                logToFile("  Creative Portfolio ID: " . ($responseData['data']['creative_portfolio_id'] ?? 'NULL'));

                // Save to database for permanent storage
                try {
                    $db = Database::getInstance();
                    $creative_portfolio_id = $responseData['data']['creative_portfolio_id'] ?? ($responseData['data']['portfolio_id'] ?? null);

                    if ($creative_portfolio_id) {
                        // Extract portfolio name from content if available
                        $portfolio_name = $requestData['portfolio_name'] ?? 'CTA Portfolio';

                        $portfolioData = [
                            'advertiser_id' => $advertiser_id,
                            'creative_portfolio_id' => $creative_portfolio_id,
                            'portfolio_name' => $portfolio_name,
                            'portfolio_type' => 'CTA',
                            'portfolio_content' => json_encode($portfolio_content),
                            'created_by_tool' => 1
                        ];

                        // Use database-agnostic upsert (works with both MySQL and PostgreSQL)
                        $db->upsert('tool_portfolios', $portfolioData, ['advertiser_id', 'creative_portfolio_id']);
                        logToFile("✓ Portfolio saved to database (ID: $creative_portfolio_id)");
                    }
                } catch (Exception $e) {
                    logToFile("⚠️  Warning: Failed to save portfolio to database: " . $e->getMessage());
                    // Don't fail the request if database save fails
                }
            }

            // Better error message
            $errorMessage = 'Failed to create CTA portfolio';
            if (isset($responseData['message']) && $responseData['message'] !== 'OK') {
                $errorMessage = $responseData['message'];
            } elseif (isset($responseData['code']) && $responseData['code'] !== 0) {
                $errorMessage = 'API Error Code: ' . $responseData['code'];
                // Add specific error details if available
                if (isset($responseData['data']['errors'])) {
                    $errorMessage .= ' - ' . json_encode($responseData['data']['errors']);
                }
            }

            // Extract portfolio_id from response
            $portfolioId = null;
            if (isset($responseData['data']['creative_portfolio_id'])) {
                $portfolioId = $responseData['data']['creative_portfolio_id'];
            } elseif (isset($responseData['data']['portfolio_id'])) {
                $portfolioId = $responseData['data']['portfolio_id'];
            }

            echo json_encode([
                'success' => $httpCode === 200 && isset($responseData['code']) && $responseData['code'] === 0,
                'portfolio_id' => $portfolioId,
                'data' => $responseData['data'] ?? null,
                'message' => isset($responseData['code']) && $responseData['code'] === 0 ? 'Portfolio created successfully' : $errorMessage,
                'code' => $responseData['code'] ?? null
            ]);
            break;

        case 'create_portfolio':
            // Simple portfolio creation for bulk launch
            $portfolioName = $requestData['portfolio_name'] ?? '';
            $callToAction = $requestData['call_to_action'] ?? 'LEARN_MORE';
            $landingPageUrl = $requestData['landing_page_url'] ?? '';

            if (empty($portfolioName)) {
                outputJsonResponse(['success' => false, 'message' => 'Portfolio name is required']);
                exit;
            }
            if (empty($landingPageUrl)) {
                outputJsonResponse(['success' => false, 'message' => 'Landing page URL is required']);
                exit;
            }

            logToFile("======= CREATE PORTFOLIO (Simple) =======");
            logToFile("Advertiser ID: " . $advertiser_id);
            logToFile("Portfolio Name: " . $portfolioName);
            logToFile("CTA: " . $callToAction);
            logToFile("Landing URL: " . $landingPageUrl);

            // Build portfolio_content in TikTok's expected format
            $portfolioContent = [
                [
                    'asset_content' => json_encode([
                        'call_to_action' => $callToAction,
                        'landing_page_urls' => [$landingPageUrl]
                    ]),
                    'asset_ids' => []
                ]
            ];

            $params = [
                'advertiser_id' => $advertiser_id,
                'creative_portfolio_type' => 'CTA',
                'portfolio_content' => $portfolioContent
            ];

            $url = "https://business-api.tiktok.com/open_api/v1.3/creative/portfolio/create/";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Access-Token: ' . $config['access_token'],
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logToFile("Response: " . $response);

            $responseData = json_decode($response, true);

            if ($httpCode === 200 && isset($responseData['code']) && $responseData['code'] === 0) {
                $portfolioId = $responseData['data']['creative_portfolio_id'] ?? $responseData['data']['portfolio_id'] ?? null;

                // Save to database
                try {
                    $db = Database::getInstance();
                    if ($portfolioId) {
                        $db->execute(
                            "INSERT INTO tool_portfolios (advertiser_id, creative_portfolio_id, portfolio_name, portfolio_type, portfolio_content, created_at)
                             VALUES (:advertiser_id, :portfolio_id, :name, :type, :content, NOW())
                             ON DUPLICATE KEY UPDATE portfolio_name = :name, portfolio_content = :content",
                            [
                                'advertiser_id' => $advertiser_id,
                                'portfolio_id' => $portfolioId,
                                'name' => $portfolioName,
                                'type' => 'CTA',
                                'content' => json_encode($portfolioContent)
                            ]
                        );
                    }
                } catch (Exception $e) {
                    logToFile("DB Error saving portfolio: " . $e->getMessage());
                }

                outputJsonResponse([
                    'success' => true,
                    'data' => ['portfolio_id' => $portfolioId],
                    'message' => 'Portfolio created successfully'
                ]);
            } else {
                outputJsonResponse([
                    'success' => false,
                    'message' => $responseData['message'] ?? 'Failed to create portfolio'
                ]);
            }
            break;

        case 'get_cta_portfolios':
            // Fetch CTA portfolios ONLY from database (portfolios created by this tool)
            // NO TikTok API call - we only show what's in our database
            logToFile("======= Fetching CTA Portfolios from Database =======");
            logToFile("  Advertiser ID: " . $advertiser_id);

            $ctaPortfolios = [];
            $success = false;
            $message = 'Failed to fetch portfolios';

            try {
                $db = Database::getInstance();

                // Fetch all portfolios for this advertiser from database with full content
                $dbPortfolios = $db->fetchAll(
                    "SELECT
                        creative_portfolio_id,
                        portfolio_name,
                        portfolio_type,
                        portfolio_content,
                        created_at
                     FROM tool_portfolios
                     WHERE advertiser_id = :advertiser_id
                     AND portfolio_type = 'CTA'
                     AND created_by_tool = TRUE
                     ORDER BY created_at DESC",
                    ['advertiser_id' => $advertiser_id]
                );

                logToFile("  CTA Portfolios Found in Database: " . count($dbPortfolios));

                // Format portfolios for frontend
                foreach ($dbPortfolios as $dbPortfolio) {
                    // Decode JSON portfolio_content
                    $portfolioContent = json_decode($dbPortfolio['portfolio_content'], true);

                    $ctaPortfolios[] = [
                        'creative_portfolio_id' => $dbPortfolio['creative_portfolio_id'],
                        'portfolio_id' => $dbPortfolio['creative_portfolio_id'], // Alias for compatibility
                        'portfolio_name' => $dbPortfolio['portfolio_name'],
                        'creative_portfolio_type' => 'CTA',
                        'portfolio_content' => $portfolioContent ?: [], // Array of CTAs
                        'created_by_tool' => true,
                        'from_database' => true,
                        'create_time' => strtotime($dbPortfolio['created_at']) // Unix timestamp
                    ];

                    logToFile("  Portfolio: " . $dbPortfolio['portfolio_name'] . " (ID: " . $dbPortfolio['creative_portfolio_id'] . ")");
                }

                $success = true;
                $message = count($ctaPortfolios) > 0
                    ? 'Found ' . count($ctaPortfolios) . ' portfolio(s)'
                    : 'No portfolios found. Create one using "Use Frequently Used CTAs" or "Create New Portfolio".';

                logToFile("  SUCCESS: Returning " . count($ctaPortfolios) . " portfolios from database");

            } catch (Exception $e) {
                logToFile("  ERROR: Database query failed: " . $e->getMessage());
                $message = 'Database error: ' . $e->getMessage();
                $success = false;
            }

            echo json_encode([
                'success' => $success,
                'data' => [
                    'portfolios' => $ctaPortfolios,
                    'page_info' => null // No pagination - showing all from database
                ],
                'message' => $message,
                'code' => $success ? 0 : -1
            ]);
            break;

        case 'get_portfolio_details':
            // GET request to fetch specific portfolio details by ID
            $portfolio_id = $_GET['portfolio_id'] ?? $requestData['portfolio_id'] ?? '';

            if (empty($portfolio_id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'portfolio_id is required'
                ]);
                break;
            }

            logToFile("======= Fetching Portfolio Details =======");
            logToFile("  Advertiser ID: " . $advertiser_id);
            logToFile("  Portfolio ID: " . $portfolio_id);

            $url = "https://business-api.tiktok.com/open_api/v1.3/creative/portfolio/get/";
            $params = [
                'advertiser_id' => $advertiser_id,
                'creative_portfolio_id' => $portfolio_id
            ];

            $url .= '?' . http_build_query($params);
            logToFile("  Request URL: " . $url);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Access-Token: ' . $config['access_token']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $responseData = json_decode($response, true);

            logToFile("  HTTP Code: " . $httpCode);
            logToFile("  Response: " . json_encode($responseData, JSON_PRETTY_PRINT));

            echo json_encode([
                'success' => $httpCode === 200 && isset($responseData['code']) && $responseData['code'] === 0,
                'data' => $responseData['data'] ?? null,
                'message' => $responseData['message'] ?? 'Failed to fetch portfolio details',
                'code' => $responseData['code'] ?? null,
                'raw_response' => $responseData
            ]);
            break;

        case 'get_or_create_frequently_used_cta_portfolio':
            // Check if portfolio already exists for this advertiser, if not create it
            logToFile("======= GET OR CREATE FREQUENTLY USED CTA PORTFOLIO =======");
            logToFile("  Advertiser ID: " . $advertiser_id);

            // Check database for existing frequently used CTA portfolio
            try {
                $db = Database::getInstance();
                $existingPortfolio = $db->fetchOne(
                    "SELECT creative_portfolio_id, portfolio_name
                     FROM tool_portfolios
                     WHERE advertiser_id = :advertiser_id
                     AND portfolio_name = 'Frequently Used CTAs'
                     ORDER BY created_at DESC
                     LIMIT 1",
                    ['advertiser_id' => $advertiser_id]
                );

                $existingPortfolioId = $existingPortfolio['creative_portfolio_id'] ?? null;
            } catch (Exception $e) {
                logToFile("  Warning: Database query failed: " . $e->getMessage());
                $existingPortfolioId = null;
            }

            if ($existingPortfolioId) {
                logToFile("  Found existing portfolio ID: " . $existingPortfolioId);

                // Verify it still exists in TikTok
                $verifyUrl = "https://business-api.tiktok.com/open_api/v1.3/creative/portfolio/list/?advertiser_id=" . $advertiser_id . "&page=1&page_size=100";

                $ch = curl_init($verifyUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Access-Token: ' . $config['access_token']
                ]);

                $verifyResponse = curl_exec($ch);
                $verifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $verifyData = json_decode($verifyResponse, true);

                // Check if our portfolio still exists
                $portfolioStillExists = false;
                if (isset($verifyData['data']['portfolios']) && is_array($verifyData['data']['portfolios'])) {
                    foreach ($verifyData['data']['portfolios'] as $portfolio) {
                        if ($portfolio['creative_portfolio_id'] == $existingPortfolioId) {
                            $portfolioStillExists = true;
                            logToFile("  Portfolio verified in TikTok");
                            break;
                        }
                    }
                }

                if ($portfolioStillExists) {
                    // Return existing portfolio ID
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'portfolio_id' => $existingPortfolioId,
                            'created_new' => false
                        ],
                        'message' => 'Using existing frequently used CTA portfolio'
                    ]);
                    break;
                } else {
                    logToFile("  Portfolio no longer exists in TikTok, will create new one");
                }
            }

            // Create new portfolio with frequently used CTAs
            logToFile("  Creating new frequently used CTA portfolio");

            $frequentlyUsedCTAs = [
                [
                    "asset_content" => "Learn more",
                    "asset_ids" => ["201781", "201535"]
                ],
                [
                    "asset_content" => "Check it out",
                    "asset_ids" => ["202156", "202150"]
                ],
                [
                    "asset_content" => "View now",
                    "asset_ids" => ["202001", "201529"]
                ],
                [
                    "asset_content" => "Read more",
                    "asset_ids" => ["201829", "201621"]
                ],
                [
                    "asset_content" => "Apply now",
                    "asset_ids" => ["201963", "201489"]
                ]
            ];

            $createParams = [
                'advertiser_id' => $advertiser_id,
                'creative_portfolio_type' => 'CTA',
                'portfolio_name' => 'Frequently Used CTAs',
                'portfolio_content' => $frequentlyUsedCTAs
            ];

            logToFile("  Portfolio Content: " . json_encode($frequentlyUsedCTAs, JSON_PRETTY_PRINT));

            $createUrl = "https://business-api.tiktok.com/open_api/v1.3/creative/portfolio/create/";

            $ch = curl_init($createUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($createParams));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Access-Token: ' . $config['access_token'],
                'Content-Type: application/json'
            ]);

            $createResponse = curl_exec($ch);
            $createHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $createData = json_decode($createResponse, true);

            logToFile("  Create Response Code: " . $createHttpCode);
            logToFile("  Create Response: " . json_encode($createData, JSON_PRETTY_PRINT));

            if ($createHttpCode === 200 && isset($createData['code']) && $createData['code'] === 0) {
                $newPortfolioId = $createData['data']['creative_portfolio_id'];
                logToFile("  SUCCESS: Portfolio created with ID: " . $newPortfolioId);

                // Save portfolio ID to database
                try {
                    $db = Database::getInstance();
                    $portfolioData = [
                        'advertiser_id' => $advertiser_id,
                        'creative_portfolio_id' => $newPortfolioId,
                        'portfolio_name' => 'Frequently Used CTAs',
                        'portfolio_type' => 'CTA',
                        'portfolio_content' => json_encode($frequentlyUsedCTAs),
                        'created_by_tool' => 1
                    ];

                    // Use database-agnostic upsert (works with both MySQL and PostgreSQL)
                    $db->upsert('tool_portfolios', $portfolioData, ['advertiser_id', 'creative_portfolio_id']);
                    logToFile("  Portfolio ID saved to database");
                } catch (Exception $e) {
                    logToFile("  Warning: Failed to save to database: " . $e->getMessage());
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'portfolio_id' => $newPortfolioId,
                        'created_new' => true
                    ],
                    'message' => 'Created and saved frequently used CTA portfolio'
                ]);
            } else {
                logToFile("  ERROR: Failed to create portfolio");
                echo json_encode([
                    'success' => false,
                    'message' => $createData['message'] ?? 'Failed to create frequently used CTA portfolio',
                    'code' => $createData['code'] ?? null,
                    'raw_response' => $createData
                ]);
            }
            break;

        case 'create_ad':
            $ad = new Ad($config);
            $data = $requestData;

            // TikTok API expects creatives array structure
            // According to docs: identity_type and identity_id are REQUIRED
            
            // Validate required fields
            if (empty($data['identity_id'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Identity is required for ad creation. Please select an identity or create one in TikTok Ads Manager.',
                    'code' => 40001
                ]);
                exit;
            }
            
            // Check if this is a Lead Generation campaign
            $isLeadGen = isset($data['is_lead_gen']) && $data['is_lead_gen'];
            
            // Build creative object according to TikTok documentation
            $creative = [
                'ad_name' => $data['ad_name'],
                'ad_format' => $data['ad_format'] ?? 'SINGLE_VIDEO',
                'ad_text' => $data['ad_text'],
                'identity_type' => $data['identity_type'] ?? 'CUSTOMIZED_USER',
                'identity_id' => $data['identity_id']
            ];

            // Handle CTA: Either call_to_action_id (Dynamic CTA) OR call_to_action (Static CTA)
            // According to TikTok docs: If call_to_action_id is specified, DO NOT pass call_to_action
            if (!empty($data['call_to_action_id'])) {
                // Using Dynamic CTA portfolio
                $creative['call_to_action_id'] = $data['call_to_action_id'];
                logToFile("Using Dynamic CTA Portfolio ID: " . $data['call_to_action_id']);
            } else {
                // Using Static CTA - required field when not using portfolio
                if (!empty($data['call_to_action'])) {
                    $creative['call_to_action'] = $data['call_to_action'];
                } else {
                    // Default CTAs based on campaign type
                    $creative['call_to_action'] = $isLeadGen ? 'SIGN_UP' : 'LEARN_MORE';
                }
                logToFile("Using Static CTA: " . $creative['call_to_action']);
            }

            // According to TikTok docs: landing_page_url is REQUIRED when promotion_type is WEBSITE
            // This applies to Lead Generation campaigns with WEBSITE promotion_type
            if (!empty($data['landing_page_url'])) {
                // Validate URL format
                if (!filter_var($data['landing_page_url'], FILTER_VALIDATE_URL)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid landing page URL format. Please provide a valid URL.',
                        'code' => 40003
                    ]);
                    exit;
                }
                $creative['landing_page_url'] = $data['landing_page_url'];
            } else if ($data['promotion_type'] === 'WEBSITE' || !isset($data['promotion_type'])) {
                // For WEBSITE promotion type (including Lead Gen), landing_page_url is required
                echo json_encode([
                    'success' => false,
                    'message' => 'Landing page URL is required for WEBSITE promotion type.',
                    'code' => 40002
                ]);
                exit;
            }
            
            logToFile("Campaign type: " . ($isLeadGen ? "Lead Generation" : "Standard"));
            logToFile("CTA Type: " . (isset($creative['call_to_action_id']) ? "Dynamic (Portfolio ID: " . $creative['call_to_action_id'] . ")" : "Static (" . ($creative['call_to_action'] ?? 'N/A') . ")"));
            logToFile("Landing page URL included: " . (isset($creative['landing_page_url']) ? "Yes - " . $creative['landing_page_url'] : "No"));

            // Add video_id and/or image_ids based on format
            if ($data['ad_format'] === 'SINGLE_VIDEO') {
                if (!empty($data['video_id'])) {
                    $creative['video_id'] = $data['video_id'];
                }
                // For video ads, image_ids is required as the video cover (thumbnail)
                // If not provided, use a default placeholder or generate one
                if (!empty($data['image_ids'])) {
                    $creative['image_ids'] = is_array($data['image_ids']) ? $data['image_ids'] : [$data['image_ids']];
                } else {
                    // You should upload a default image to TikTok and use its ID here
                    // For now, we'll require the frontend to provide it
                    logToFile("Warning: No image_ids provided for video ad - this may cause the ad creation to fail");
                }
            } elseif ($data['ad_format'] === 'SINGLE_IMAGE' && !empty($data['image_ids'])) {
                $creative['image_ids'] = is_array($data['image_ids']) ? $data['image_ids'] : [$data['image_ids']];
            }

            $params = [
                'advertiser_id' => $advertiser_id,
                'adgroup_id' => $data['adgroup_id'],
                'creatives' => [$creative]
            ];

            logToFile("============ CREATE AD REQUEST ============");
            logToFile("Create Ad Request: " . json_encode($params, JSON_PRETTY_PRINT));

            $response = $ad->create($params);

            logToFile("============ CREATE AD RESPONSE ============");
            logToFile("Create Ad Response: " . json_encode($response, JSON_PRETTY_PRINT));
            logToFile("Response Code: " . ($response->code ?? 'null'));
            logToFile("Response Message: " . ($response->message ?? 'null'));
            if (isset($response->errors)) {
                logToFile("Response Errors: " . json_encode($response->errors, JSON_PRETTY_PRINT));
            }

            // Check for success
            $isSuccess = (empty($response->code) || $response->code == 0) && isset($response->data);
            
            // Get error details if failed
            $errorMessage = 'Ad created successfully';
            if (!$isSuccess) {
                $errorMessage = $response->message ?? 'Unknown error occurred';
                if (isset($response->errors) && is_array($response->errors)) {
                    $errorDetails = [];
                    foreach ($response->errors as $error) {
                        $errorDetails[] = $error->field . ': ' . $error->message;
                    }
                    $errorMessage .= ' - ' . implode(', ', $errorDetails);
                }
            }

            echo json_encode([
                'success' => $isSuccess,
                'data' => $response->data ?? null,
                'message' => $isSuccess ? 'Ad created successfully' : $errorMessage,
                'code' => $response->code ?? null,
                'debug' => [
                    'request' => $params,
                    'response' => $response
                ]
            ]);
            break;

        case 'upload_thumbnail_as_cover':
            // Upload video thumbnail URL as cover image to TikTok
            $data = $requestData;
            
            if (empty($data['thumbnail_url']) || empty($data['video_id'])) {
                throw new Exception('thumbnail_url and video_id are required');
            }
            
            $thumbnailUrl = $data['thumbnail_url'];
            $videoId = $data['video_id'];
            
            logToFile("============ THUMBNAIL UPLOAD REQUEST ============");
            logToFile("Video ID: " . $videoId);
            logToFile("Thumbnail URL: " . $thumbnailUrl);
            
            // Download thumbnail from URL
            $tempFile = tempnam(sys_get_temp_dir(), 'thumbnail_');
            $imageData = file_get_contents($thumbnailUrl);
            
            if ($imageData === false) {
                throw new Exception('Failed to download thumbnail from URL: ' . $thumbnailUrl);
            }
            
            file_put_contents($tempFile, $imageData);
            
            // Get image info for filename
            $imageInfo = getimagesize($tempFile);
            if (!$imageInfo) {
                unlink($tempFile);
                throw new Exception('Invalid image format');
            }
            
            $mimeType = $imageInfo['mime'] ?? 'image/jpeg';
            $extension = $mimeType === 'image/png' ? '.png' : '.jpg';
            // Add timestamp to make filename unique and avoid duplicate material errors
            $fileName = 'video_' . substr($videoId, -8) . '_thumb_' . time() . '_' . rand(1000, 9999) . $extension;
            
            $imageSignature = md5_file($tempFile);
            
            logToFile("Image signature: " . $imageSignature);
            logToFile("File name: " . $fileName);
            logToFile("MIME type: " . $mimeType);
            
            $file = new File($config);
            
            $params = [
                'advertiser_id' => $advertiser_id,
                'file_name' => $fileName,
                'image_file' => new CURLFile($tempFile, $mimeType, $fileName),
                'image_signature' => $imageSignature
            ];
            
            $response = $file->uploadImage($params);
            
            // Clean up temp file
            unlink($tempFile);
            
            logToFile("Thumbnail upload response: " . json_encode($response, JSON_PRETTY_PRINT));
            
            $success = empty($response->code) || $response->code == 0;
            
            if ($success && isset($response->data->image_id)) {
                // Store in persistent storage
                $storageFile = __DIR__ . '/media_storage.json';
                $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];
                
                $storage['images'][] = [
                    'image_id' => $response->data->image_id,
                    'file_name' => $fileName,
                    'upload_time' => time(),
                    'url' => $response->data->url ?? $thumbnailUrl,
                    'advertiser_id' => $advertiser_id,
                    'source' => 'video_thumbnail',
                    'video_id' => $videoId
                ];
                
                file_put_contents($storageFile, json_encode($storage, JSON_PRETTY_PRINT));
                
                logToFile("Thumbnail uploaded successfully with ID: " . $response->data->image_id);
            }
            
            echo json_encode([
                'success' => $success,
                'data' => $response->data ?? null,
                'message' => $success ? 'Video thumbnail uploaded as cover image' : ($response->message ?? 'Upload failed'),
                'code' => $response->code ?? null
            ]);
            break;
            
        case 'upload_image':
            $file = new File($config);

            logToFile("============ IMAGE UPLOAD REQUEST ============");
            logToFile("Upload Image Request - FILES: " . json_encode($_FILES, JSON_PRETTY_PRINT));

            if (!isset($_FILES['image'])) {
                throw new Exception('No image file provided');
            }

            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                    UPLOAD_ERR_EXTENSION => 'PHP extension stopped upload'
                ];
                $errorMsg = $uploadErrors[$_FILES['image']['error']] ?? 'Unknown error: ' . $_FILES['image']['error'];
                throw new Exception($errorMsg);
            }

            $fileName = $_FILES['image']['name'];
            $tmpPath = $_FILES['image']['tmp_name'];

            if (!file_exists($tmpPath)) {
                throw new Exception('Uploaded file not found at: ' . $tmpPath);
            }

            // Security: Validate image file (type, size, extension)
            $validation = Security::validateImageUpload($tmpPath, $fileName);
            if (!$validation['valid']) {
                logToFile("Image validation failed: " . $validation['error']);
                throw new Exception('Invalid image file: ' . $validation['error']);
            }

            $imageSignature = md5_file($tmpPath);

            logToFile("Image Upload - File: " . $fileName);
            logToFile("Image Upload - Advertiser ID: " . $advertiser_id);
            logToFile("Image Upload - Signature: " . $imageSignature);

            $params = [
                'advertiser_id' => $advertiser_id,
                'file_name' => $fileName,
                'image_file' => new CURLFile($tmpPath, $validation['mime'], $fileName),
                'image_signature' => $imageSignature
            ];

            $response = $file->uploadImage($params);

            logToFile("Image Upload Response: " . json_encode($response, JSON_PRETTY_PRINT));

            // Consider success if we got an image_id OR if code is 0/empty
            $success = (empty($response->code) || $response->code == 0) || 
                      (isset($response->data->image_id) && !empty($response->data->image_id));
            
            // If upload successful, store the image ID for later retrieval
            if ($success && isset($response->data->image_id)) {
                // Store in persistent storage
                $storageFile = __DIR__ . '/media_storage.json';
                $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];
                
                $storage['images'][] = [
                    'image_id' => $response->data->image_id,
                    'file_name' => $fileName,
                    'upload_time' => time(),
                    'url' => $response->data->url ?? null,
                    'advertiser_id' => $advertiser_id
                ];
                
                file_put_contents($storageFile, json_encode($storage, JSON_PRETTY_PRINT));
                
                logToFile("Image uploaded successfully with ID: " . $response->data->image_id);
            }
            
            echo json_encode([
                'success' => $success,
                'data' => $response->data ?? null,
                'message' => $response->message ?? 'Image uploaded successfully',
                'code' => $response->code ?? null
            ]);
            break;

        case 'upload_video':
            try {
                // Add memory and execution time limits for large video uploads
                ini_set('memory_limit', '512M');
                ini_set('max_execution_time', '300'); // 5 minutes

                // Allow override of advertiser_id via POST for bulk uploads to specific accounts
                $upload_advertiser_id = $advertiser_id; // Default from session
                if (!empty($_POST['advertiser_id'])) {
                    $upload_advertiser_id = $_POST['advertiser_id'];
                    logToFile("Using advertiser_id from POST data: " . $upload_advertiser_id);
                }

                logToFile("============ VIDEO UPLOAD REQUEST ============");
                logToFile("Upload Video Request - Advertiser: " . $upload_advertiser_id);
                logToFile("Upload Video Request - FILES: " . json_encode($_FILES, JSON_PRETTY_PRINT));
                logToFile("PHP Memory Limit: " . ini_get('memory_limit'));
                logToFile("PHP Max Execution Time: " . ini_get('max_execution_time'));
                logToFile("PHP Upload Max Filesize: " . ini_get('upload_max_filesize'));
                logToFile("PHP Post Max Size: " . ini_get('post_max_size'));

                if (!isset($_FILES['video'])) {
                    throw new Exception('No video file provided');
                }

                if ($_FILES['video']['error'] !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                        UPLOAD_ERR_EXTENSION => 'PHP extension stopped upload'
                    ];
                    $errorMsg = $uploadErrors[$_FILES['video']['error']] ?? 'Unknown error: ' . $_FILES['video']['error'];
                    logToFile("Upload Error: " . $errorMsg);
                    throw new Exception($errorMsg);
                }

                $fileName = $_FILES['video']['name'];
                $tmpPath = $_FILES['video']['tmp_name'];
                $fileSize = $_FILES['video']['size'];

                if (!file_exists($tmpPath)) {
                    throw new Exception('Uploaded file not found at: ' . $tmpPath);
                }

                // Security: Validate video file (type, size, extension)
                $validation = Security::validateVideoUpload($tmpPath, $fileName);
                if (!$validation['valid']) {
                    logToFile("Video validation failed: " . $validation['error']);
                    throw new Exception('Invalid video file: ' . $validation['error']);
                }

                // Use validated MIME type
                $mimeType = $validation['mime'];

                $videoSignature = md5_file($tmpPath);

                logToFile("Video Upload - File: " . $fileName);
                logToFile("Video Upload - Size: " . $fileSize . " bytes");
                logToFile("Video Upload - MIME Type: " . $mimeType);
                logToFile("Video Upload - Advertiser ID: " . $upload_advertiser_id);
                logToFile("Video Upload - Signature: " . $videoSignature);
                logToFile("Video Upload - Access Token Present: " . (!empty($config['access_token']) ? 'Yes' : 'No'));

                // Use direct cURL for more reliable upload
                $url = 'https://business-api.tiktok.com/open_api/v1.3/file/video/ad/upload/';

                $postData = [
                    'advertiser_id' => $upload_advertiser_id,
                    'upload_type' => 'UPLOAD_BY_FILE',
                    'video_file' => new CURLFile($tmpPath, $mimeType, $fileName),
                    'video_signature' => $videoSignature,
                    'flaw_detect' => 'true',
                    'auto_fix_enabled' => 'true',
                    'auto_bind_enabled' => 'true'
                ];

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_HTTPHEADER => [
                        'Access-Token: ' . $config['access_token']
                    ],
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2
                ]);

                $curlResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_close($ch);

                logToFile("cURL HTTP Code: " . $httpCode);
                logToFile("cURL Response: " . substr($curlResponse ?? '', 0, 1000));
                if ($curlError) {
                    logToFile("cURL Error: " . $curlError . " (errno: " . $curlErrno . ")");
                }

                // Check for cURL errors
                if ($curlErrno) {
                    throw new Exception("cURL error: " . $curlError);
                }

                // Parse response
                $response = json_decode($curlResponse);
                if ($response === null && json_last_error() !== JSON_ERROR_NONE) {
                    logToFile("JSON Parse Error: " . json_last_error_msg());
                    throw new Exception("Invalid JSON response from TikTok API: " . json_last_error_msg());
                }

                // Extract video data safely - convert objects to arrays
                $videoId = null;
                $responseData = null;
                $processingStatus = null;

                // Log complete response structure for debugging
                logToFile("Upload Response Structure: " . json_encode($response));

                if (isset($response->data)) {
                    // Convert response data to array for safe JSON encoding
                    $responseData = json_decode(json_encode($response->data), true);

                    // Check multiple possible locations for video_id
                    // TikTok API can return it in different places depending on version/endpoint
                    $videoId = $responseData['video_id']
                            ?? $responseData['video']['video_id']
                            ?? $responseData['file_id']
                            ?? $responseData['material_id']
                            ?? $responseData['id']
                            ?? null;

                    // Check for async processing status
                    $processingStatus = $responseData['status'] ?? $responseData['processing_status'] ?? null;

                    logToFile("Extracted - video_id: " . ($videoId ?? 'null') .
                              ", status: " . ($processingStatus ?? 'null') .
                              ", response data: " . json_encode($responseData));
                }

                // For video upload, success REQUIRES a video_id to be returned
                // TikTok may return code 0 but no video_id if processing async
                $apiCodeSuccess = (empty($response->code) || $response->code == 0);
                $hasVideoId = !empty($videoId);
                $isPending = ($processingStatus === 'pending' || $processingStatus === 'processing');

                // True success requires BOTH: API code success AND video_id returned
                $success = $apiCodeSuccess && $hasVideoId;

                logToFile("Upload result - API Code Success: " . ($apiCodeSuccess ? 'true' : 'false') .
                          ", Has Video ID: " . ($hasVideoId ? 'true' : 'false') .
                          ", Is Pending: " . ($isPending ? 'true' : 'false') .
                          ", Video ID: " . ($videoId ?? 'null') .
                          ", Response Code: " . ($response->code ?? 'none'));

                // If upload successful, store the video ID for later retrieval
                if ($success && $videoId) {
                    // Store in persistent storage
                    $storageFile = __DIR__ . '/media_storage.json';
                    $storage = [];
                    if (file_exists($storageFile)) {
                        $storageContent = file_get_contents($storageFile);
                        if ($storageContent) {
                            $storage = json_decode($storageContent, true) ?? [];
                        }
                    }
                    if (!isset($storage['images'])) $storage['images'] = [];
                    if (!isset($storage['videos'])) $storage['videos'] = [];

                    $storage['videos'][] = [
                        'video_id' => $videoId,
                        'file_name' => $fileName,
                        'upload_time' => time(),
                        'advertiser_id' => $upload_advertiser_id
                    ];

                    file_put_contents($storageFile, json_encode($storage, JSON_PRETTY_PRINT));

                    logToFile("Video uploaded successfully with ID: " . $videoId);
                }

                // Build response - ensure all data is JSON-serializable
                // Include error code in message for better debugging
                $errorMessage = 'Upload failed';

                if (!$success) {
                    // Determine the specific error
                    if ($apiCodeSuccess && !$hasVideoId && $isPending) {
                        // Video is being processed asynchronously by TikTok
                        $errorMessage = 'Video is being processed by TikTok. It will appear in your library within 1-2 minutes.';
                        logToFile("INFO: Video upload pending async processing by TikTok.");
                    } elseif ($apiCodeSuccess && !$hasVideoId) {
                        // API said OK but no video_id and not pending - might still be processing
                        $errorMessage = 'Upload accepted but video is still processing. Check your video library in 1-2 minutes - the video should appear there.';
                        logToFile("WARNING: API returned success but no video_id. Video may be processing async.");
                    } elseif (isset($response->code) && $response->code != 0) {
                        // TikTok returned an error code
                        $tiktokMsg = isset($response->message) ? (string)$response->message : 'Unknown error';
                        $errorMessage = "TikTok Error [{$response->code}]: {$tiktokMsg}";

                        // Common TikTok error codes with better descriptions
                        $errorCodes = [
                            40001 => 'Access token invalid or expired - please re-login',
                            40002 => 'Advertiser not authorized - this account was not included in OAuth authorization',
                            40100 => 'Permission denied for this advertiser - check account permissions',
                            40105 => 'Access token does not match advertiser - re-authorize this specific account',
                            50001 => 'Video upload failed - try a different video format',
                            50002 => 'Video format not supported - use MP4, MOV, or WebM',
                            50003 => 'Video too short or too long for ads',
                        ];
                        if (isset($errorCodes[$response->code])) {
                            $errorMessage .= " - " . $errorCodes[$response->code];
                        }
                    } elseif (isset($response->message) && $response->message) {
                        $errorMessage = (string)$response->message;
                    }
                }

                // Ensure video_id is at the top level of data for frontend compatibility
                // The frontend expects result.data.video_id to exist
                if ($videoId && is_array($responseData)) {
                    $responseData['video_id'] = $videoId;
                } elseif ($videoId && !is_array($responseData)) {
                    $responseData = ['video_id' => $videoId];
                }

                $jsonResponse = [
                    'success' => $success,
                    'data' => $responseData,
                    'message' => $success ? 'Video uploaded successfully' : $errorMessage,
                    'code' => isset($response->code) ? (int)$response->code : 0
                ];

                // Log what we're about to return
                logToFile("Returning JSON response: " . json_encode($jsonResponse, JSON_PRETTY_PRINT));

                outputJsonResponse($jsonResponse);

            } catch (Exception $videoError) {
                logToFile("Video Upload Exception: " . $videoError->getMessage());
                logToFile("Video Upload Stack Trace: " . $videoError->getTraceAsString());

                outputJsonResponse([
                    'success' => false,
                    'message' => 'Video upload failed: ' . $videoError->getMessage(),
                    'error' => $videoError->getMessage()
                ]);
            }
            break;

        case 'upload_video_to_advertiser':
            // Upload video to a SPECIFIC advertiser account (for bulk launch)
            // This validates the target advertiser is in the authorized list
            try {
                ini_set('memory_limit', '512M');
                ini_set('max_execution_time', '300');

                logToFile("============ UPLOAD VIDEO TO SPECIFIC ADVERTISER ============");

                // Get target advertiser ID from POST
                $targetAdvertiserId = $_POST['target_advertiser_id'] ?? '';
                if (empty($targetAdvertiserId)) {
                    throw new Exception('Target advertiser ID is required');
                }

                // Validate target advertiser is in the authorized list
                $authorizedAdvertisers = $_SESSION['oauth_advertiser_ids'] ?? [];
                if (!in_array($targetAdvertiserId, $authorizedAdvertisers)) {
                    logToFile("ERROR: Advertiser $targetAdvertiserId is not in authorized list: " . json_encode($authorizedAdvertisers));
                    throw new Exception('You do not have permission to upload to this advertiser account. Please re-authorize with this account included.');
                }

                logToFile("Target Advertiser ID: " . $targetAdvertiserId);
                logToFile("Authorized Advertisers: " . json_encode($authorizedAdvertisers));

                if (!isset($_FILES['video'])) {
                    throw new Exception('No video file provided');
                }

                if ($_FILES['video']['error'] !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                        UPLOAD_ERR_EXTENSION => 'PHP extension stopped upload'
                    ];
                    $errorMsg = $uploadErrors[$_FILES['video']['error']] ?? 'Unknown error';
                    throw new Exception($errorMsg);
                }

                $fileName = $_FILES['video']['name'];
                $tmpPath = $_FILES['video']['tmp_name'];
                $fileSize = $_FILES['video']['size'];

                // Validate video file
                $validation = Security::validateVideoUpload($tmpPath, $fileName);
                if (!$validation['valid']) {
                    throw new Exception('Invalid video file: ' . $validation['error']);
                }

                $mimeType = $validation['mime'];
                $videoSignature = md5_file($tmpPath);

                logToFile("Video Upload to $targetAdvertiserId - File: $fileName, Size: $fileSize bytes");

                // Upload to TikTok
                $url = 'https://business-api.tiktok.com/open_api/v1.3/file/video/ad/upload/';

                $postData = [
                    'advertiser_id' => $targetAdvertiserId,
                    'upload_type' => 'UPLOAD_BY_FILE',
                    'video_file' => new CURLFile($tmpPath, $mimeType, $fileName),
                    'video_signature' => $videoSignature,
                    'flaw_detect' => 'true',
                    'auto_fix_enabled' => 'true',
                    'auto_bind_enabled' => 'true'
                ];

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_HTTPHEADER => [
                        'Access-Token: ' . $config['access_token']
                    ],
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2
                ]);

                $curlResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                logToFile("cURL HTTP Code: " . $httpCode);
                logToFile("cURL Response: " . substr($curlResponse ?? '', 0, 1000));

                if ($curlError) {
                    throw new Exception("cURL error: " . $curlError);
                }

                $response = json_decode($curlResponse);
                if ($response === null) {
                    throw new Exception("Invalid JSON response from TikTok API");
                }

                // Extract video_id - check multiple possible locations
                $videoId = null;
                $responseData = null;
                $processingStatus = null;

                // Log complete response structure for debugging
                logToFile("Upload to $targetAdvertiserId - Response Structure: " . json_encode($response));

                if (isset($response->data)) {
                    $responseData = json_decode(json_encode($response->data), true);

                    // Check multiple possible locations for video_id
                    $videoId = $responseData['video_id']
                            ?? $responseData['video']['video_id']
                            ?? $responseData['file_id']
                            ?? $responseData['material_id']
                            ?? $responseData['id']
                            ?? null;

                    // Check for async processing status
                    $processingStatus = $responseData['status'] ?? $responseData['processing_status'] ?? null;

                    logToFile("Extracted - video_id: " . ($videoId ?? 'null') .
                              ", status: " . ($processingStatus ?? 'null') .
                              ", response data: " . json_encode($responseData));
                }

                // Check for success - REQUIRE video_id
                $apiCodeSuccess = (empty($response->code) || $response->code == 0);
                $hasVideoId = !empty($videoId);
                $isPending = ($processingStatus === 'pending' || $processingStatus === 'processing');
                $success = $apiCodeSuccess && $hasVideoId;

                logToFile("Upload to $targetAdvertiserId - API Success: " . ($apiCodeSuccess ? 'yes' : 'no') .
                          ", Video ID: " . ($videoId ?? 'null') .
                          ", Is Pending: " . ($isPending ? 'yes' : 'no') .
                          ", TikTok Code: " . ($response->code ?? 'none'));

                // Build error message if failed
                $errorMessage = 'Upload failed';
                if (!$success) {
                    if ($apiCodeSuccess && !$hasVideoId && $isPending) {
                        $errorMessage = 'Video is processing. It will appear in your library within 1-2 minutes.';
                    } elseif ($apiCodeSuccess && !$hasVideoId) {
                        $errorMessage = 'Upload accepted but video is still processing. Check library in 1-2 minutes.';
                    } elseif (isset($response->code) && $response->code != 0) {
                        $tiktokMsg = $response->message ?? 'Unknown error';
                        $errorMessage = "TikTok Error [{$response->code}]: {$tiktokMsg}";

                        // Add helpful descriptions for common errors
                        $errorDescriptions = [
                            40001 => 'Access token invalid or expired',
                            40002 => 'Advertiser not authorized',
                            40100 => 'Permission denied for this advertiser',
                            40105 => 'Token does not match advertiser'
                        ];
                        if (isset($errorDescriptions[$response->code])) {
                            $errorMessage .= " - " . $errorDescriptions[$response->code];
                        }
                    }
                }

                // Store video if successful
                if ($success) {
                    $storageFile = __DIR__ . '/media_storage.json';
                    $storage = file_exists($storageFile) ? json_decode(file_get_contents($storageFile), true) ?? [] : [];
                    if (!isset($storage['videos'])) $storage['videos'] = [];

                    $storage['videos'][] = [
                        'video_id' => $videoId,
                        'file_name' => $fileName,
                        'upload_time' => time(),
                        'advertiser_id' => $targetAdvertiserId
                    ];
                    file_put_contents($storageFile, json_encode($storage, JSON_PRETTY_PRINT));

                    logToFile("Video uploaded successfully to $targetAdvertiserId with ID: $videoId");
                }

                // Ensure video_id is at the top level of data for frontend compatibility
                if ($videoId && is_array($responseData)) {
                    $responseData['video_id'] = $videoId;
                } elseif ($videoId && !is_array($responseData)) {
                    $responseData = ['video_id' => $videoId];
                }

                outputJsonResponse([
                    'success' => $success,
                    'data' => $responseData,
                    'message' => $success ? 'Video uploaded successfully' : $errorMessage,
                    'code' => isset($response->code) ? (int)$response->code : 0,
                    'target_advertiser_id' => $targetAdvertiserId
                ]);

            } catch (Exception $e) {
                logToFile("Upload to advertiser exception: " . $e->getMessage());
                outputJsonResponse([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage()
                ]);
            }
            break;

        case 'upload_video_direct':
            // Direct cURL implementation - fallback if SDK fails
            logToFile("============ DIRECT VIDEO UPLOAD REQUEST ============");
            
            if (!isset($_FILES['video'])) {
                throw new Exception('No video file provided');
            }

            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'PHP extension stopped upload'
            ];

            if ($_FILES['video']['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = $uploadErrors[$_FILES['video']['error']] ?? 'Unknown error';
                throw new Exception($errorMsg);
            }

            $fileName = $_FILES['video']['name'];
            $tmpPath = $_FILES['video']['tmp_name'];
            $fileSize = $_FILES['video']['size'];
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            
            $videoSignature = md5_file($tmpPath);
            
            logToFile("Direct Upload - File: $fileName, Size: $fileSize bytes, MIME: $mimeType");

            $url = 'https://business-api.tiktok.com/open_api/v1.3/file/video/ad/upload/';
            
            $postFields = [
                'advertiser_id' => $advertiser_id,
                'file_name' => $fileName,
                'upload_type' => 'UPLOAD_BY_FILE',
                'video_file' => new CURLFile($tmpPath, $mimeType, $fileName),
                'video_signature' => $videoSignature,
                'flaw_detect' => 'true',
                'auto_fix_enabled' => 'true',
                'auto_bind_enabled' => 'true'
            ];

            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_HTTPHEADER => [
                    'Access-Token: ' . $config['access_token']
                ],
                CURLOPT_TIMEOUT => 300,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            logToFile("Executing direct cURL request...");
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            curl_close($ch);

            logToFile("HTTP Status: $httpCode");
            logToFile("cURL Error: " . ($curlError ?: 'None'));
            logToFile("Response: " . $response);

            if ($curlError) {
                throw new Exception('cURL Error: ' . $curlError);
            }

            $responseData = json_decode($response, true);

            echo json_encode([
                'success' => isset($responseData['code']) && $responseData['code'] == 0,
                'data' => $responseData['data'] ?? null,
                'message' => $responseData['message'] ?? 'Video upload completed',
                'code' => $responseData['code'] ?? null,
                'http_code' => $httpCode
            ]);
            break;

        case 'get_identities':
            $identity = new Identity($config);
            
            logToFile("Get Identities - Advertiser ID: " . $advertiser_id);
            
            // Try to get both TT_USER and CUSTOMIZED_USER identities
            $allIdentities = [];
            
            // First get TT_USER identities (TikTok accounts)
            $params = [
                'advertiser_id' => $advertiser_id,
                'identity_type' => 'TT_USER',
                'page' => 1,
                'page_size' => 100
            ];
            
            $response = $identity->getSelf($params);
            logToFile("Get TT_USER Identities Response: " . json_encode($response, JSON_PRETTY_PRINT));
            
            // Format the identities properly for frontend - check both list and identity_list
            $identityList = $response->data->identity_list ?? $response->data->list ?? null;
            
            if (empty($response->code) && $identityList) {
                foreach ($identityList as $id) {
                    $allIdentities[] = [
                        'identity_id' => $id->identity_id,
                        'identity_name' => $id->identity_name ?? $id->display_name ?? 'TikTok User',
                        'display_name' => $id->display_name ?? $id->identity_name ?? 'TikTok User',
                        'identity_type' => $id->identity_type ?? 'TT_USER'
                    ];
                }
            }
            
            // Also try to get CUSTOMIZED_USER identities
            $params['identity_type'] = 'CUSTOMIZED_USER';
            $response = $identity->getSelf($params);
            logToFile("Get CUSTOMIZED_USER Identities Response: " . json_encode($response, JSON_PRETTY_PRINT));
            
            $identityList = $response->data->identity_list ?? $response->data->list ?? null;
            if (empty($response->code) && $identityList) {
                foreach ($identityList as $id) {
                    $allIdentities[] = [
                        'identity_id' => $id->identity_id,
                        'identity_name' => $id->identity_name ?? $id->display_name ?? 'Custom User',
                        'display_name' => $id->display_name ?? $id->identity_name ?? 'Custom User',
                        'identity_type' => $id->identity_type ?? 'CUSTOMIZED_USER'
                    ];
                }
            }
            
            // If still no identities, try without identity_type filter
            if (empty($allIdentities)) {
                unset($params['identity_type']);
                $response = $identity->getSelf($params);
                logToFile("Get ALL Identities Response: " . json_encode($response, JSON_PRETTY_PRINT));
                
                $identityList = $response->data->identity_list ?? $response->data->list ?? null;
                if (empty($response->code) && $identityList) {
                    foreach ($identityList as $id) {
                        $allIdentities[] = [
                            'identity_id' => $id->identity_id,
                            'identity_name' => $id->identity_name ?? $id->display_name ?? 'Identity',
                            'display_name' => $id->display_name ?? $id->identity_name ?? 'Identity',
                            'identity_type' => $id->identity_type ?? 'UNKNOWN'
                        ];
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => ['list' => $allIdentities],
                'message' => empty($allIdentities) ? 'No identities found - Create one in TikTok Ads Manager' : null
            ]);
            break;

        // Get TikTok posts from linked TT_USER account for Spark Ads
        case 'get_tiktok_posts':
            logToFile("============ GET TIKTOK POSTS FOR SPARK ADS ============");

            $identityId = $requestData['identity_id'] ?? '';
            $identityType = $requestData['identity_type'] ?? 'TT_USER';

            if (empty($identityId)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'identity_id is required'
                ]);
                break;
            }

            $accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';

            // Use /identity/video/get/ endpoint to get videos from linked TikTok account
            $url = "https://business-api.tiktok.com/open_api/v1.3/identity/video/get/";
            $queryParams = [
                'advertiser_id' => $advertiser_id,
                'identity_id' => $identityId,
                'identity_type' => $identityType,
                'page' => $requestData['page'] ?? 1,
                'page_size' => $requestData['page_size'] ?? 20
            ];

            $queryString = http_build_query($queryParams);
            $fullUrl = $url . '?' . $queryString;

            logToFile("Fetching TikTok posts from: " . $fullUrl);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . $accessToken,
                    "Content-Type: application/json"
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logToFile("TikTok Posts Response (HTTP $httpCode): " . $result);

            $response = json_decode($result, true);

            if ($response && isset($response['code']) && $response['code'] == 0) {
                echo json_encode([
                    'success' => true,
                    'data' => $response['data'] ?? [],
                    'message' => 'TikTok posts retrieved successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $response['message'] ?? 'Failed to get TikTok posts',
                    'code' => $response['code'] ?? null
                ]);
            }
            break;

        // Get video info using AUTH_CODE
        case 'get_video_by_auth_code':
            logToFile("============ GET VIDEO BY AUTH CODE ============");

            $authCode = $requestData['auth_code'] ?? '';

            if (empty($authCode)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'auth_code is required'
                ]);
                break;
            }

            $accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';

            // Use /identity/video/info/ endpoint to get video info from auth code
            $url = "https://business-api.tiktok.com/open_api/v1.3/identity/video/info/";
            $params = [
                'advertiser_id' => $advertiser_id,
                'auth_code' => $authCode
            ];

            logToFile("Getting video info for auth_code: " . $authCode);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($params),
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . $accessToken,
                    "Content-Type: application/json"
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logToFile("Auth Code Video Response (HTTP $httpCode): " . $result);

            $response = json_decode($result, true);

            if ($response && isset($response['code']) && $response['code'] == 0) {
                echo json_encode([
                    'success' => true,
                    'data' => $response['data'] ?? [],
                    'message' => 'Video info retrieved successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $response['message'] ?? 'Failed to get video info from auth code',
                    'code' => $response['code'] ?? null
                ]);
            }
            break;

        case 'get_pixels':
            $tools = new Tools($config);
            $response = $tools->getPixels([
                'advertiser_id' => $advertiser_id
            ]);

            logToFile("Get Pixels Response: " . json_encode($response, JSON_PRETTY_PRINT));

            echo json_encode([
                'success' => empty($response->code),
                'data' => $response->data ?? null,
                'message' => $response->message ?? null,
                'code' => $response->code ?? null
            ]);
            break;

        case 'get_images':
            logToFile("============ GET IMAGES REQUEST ============");
            logToFile("Get Images - Advertiser ID: " . $advertiser_id);
            
            // CRITICAL FIX: Define access token (was missing!)
            $accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
            
            logToFile("Access Token: " . (!empty($accessToken) ? "Present (length: " . strlen($accessToken) . ")" : "MISSING"));
            logToFile("Session advertiser_id: " . ($_SESSION['selected_advertiser_id'] ?? 'NOT SET'));
            logToFile("Environment advertiser_id: " . ($_ENV['TIKTOK_ADVERTISER_ID'] ?? 'NOT SET'));
            
            $images = [];
            
            // Always try TikTok API first for fresh images
            logToFile("Fetching images from TikTok API using correct endpoint...");
                // Use the CORRECT endpoint: /file/image/ad/search/ with GET method
                try {
                $page = 1;
                $pageSize = 50;
                $hasMore = true;
                
                while ($hasMore && $page <= 10) { // Limit to 10 pages for safety
                    // CORRECTED: Use the working file/image/ad/search endpoint with GET method
                    $url = "https://business-api.tiktok.com/open_api/v1.3/file/image/ad/search/";
                    $queryParams = [
                        "advertiser_id" => $advertiser_id,
                        "page" => $page,
                        "page_size" => $pageSize
                    ];
                    
                    $queryString = http_build_query($queryParams);
                    $getUrl = $url . '?' . $queryString;
                    
                    logToFile("Fetching images from: " . $getUrl);
                    logToFile("Query params: " . json_encode($queryParams));
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $getUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTPHEADER => [
                            "Access-Token: " . $accessToken
                        ]
                    ]);
                    
                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    
                    logToFile("Image search HTTP Code: " . $httpCode);
                    if ($curlError) {
                        logToFile("CURL Error: " . $curlError);
                    }
                    
                    logToFile("Image list HTTP response code: " . $httpCode);
                    logToFile("Raw response (first 500 chars): " . substr($result, 0, 500));
                    
                    if ($httpCode == 200) {
                        $response = json_decode($result);
                        logToFile("============ DEEP DEBUGGING: RAW TIKTOK RESPONSE ============");
                        logToFile("Raw JSON response structure: " . json_encode($response, JSON_PRETTY_PRINT));
                        
                        // Check response structure step by step
                        logToFile("Response object type: " . gettype($response));
                        if ($response) {
                            logToFile("Response->data exists: " . (isset($response->data) ? 'YES' : 'NO'));
                            if (isset($response->data)) {
                                logToFile("Response->data->list exists: " . (isset($response->data->list) ? 'YES' : 'NO'));
                                logToFile("Response->data->list type: " . (isset($response->data->list) ? gettype($response->data->list) : 'N/A'));
                                logToFile("Response->data->list is_array: " . (isset($response->data->list) && is_array($response->data->list) ? 'YES' : 'NO'));
                            }
                        }
                        
                        if (isset($response->data->list) && is_array($response->data->list)) {
                            logToFile("============ PROCESSING IMAGES FROM TIKTOK ============");
                            logToFile("Found " . count($response->data->list) . " images in API response");
                            
                            foreach ($response->data->list as $index => $image) {
                                logToFile("--- PROCESSING IMAGE INDEX {$index} ---");
                                
                                // Log complete image object structure
                                logToFile("Complete image object: " . json_encode($image, JSON_PRETTY_PRINT));
                                logToFile("Image object type: " . gettype($image));
                                
                                // Check all possible URL fields that TikTok might provide
                                $possibleUrlFields = ['image_url', 'url', 'preview_url', 'thumbnail_url', 'download_url', 'display_url', 'src', 'link'];
                                logToFile("Checking all possible URL fields:");
                                foreach ($possibleUrlFields as $field) {
                                    $value = isset($image->$field) ? $image->$field : 'NOT_SET';
                                    logToFile("  {$field}: " . ($value !== 'NOT_SET' ? $value : 'NOT_SET'));
                                }
                                
                                // Check all object properties
                                logToFile("All object properties:");
                                if (is_object($image)) {
                                    $properties = get_object_vars($image);
                                    foreach ($properties as $prop => $value) {
                                        logToFile("  Property '{$prop}': " . (is_string($value) || is_numeric($value) ? $value : gettype($value)));
                                    }
                                } else {
                                    logToFile("  Image is not an object! Type: " . gettype($image));
                                }
                                
                                // Check displayable flag
                                $displayable = $image->displayable ?? true;
                                logToFile("Displayable flag: " . ($displayable ? 'TRUE' : 'FALSE'));
                                
                                // IMPORTANT: Process ALL images regardless of displayable flag
                                // TikTok seems to return displayable=false for all images, but they still have valid URLs
                                logToFile("🔧 PROCESSING IMAGE REGARDLESS OF DISPLAYABLE FLAG");
                                if (true) { // Always process images
                                    logToFile("Processing displayable image {$index}: " . ($image->file_name ?? 'NO_FILENAME'));
                                    
                                    // Try multiple extraction methods for URL
                                    $originalImageUrl = '';
                                    
                                    // Method 1: Check image_url field
                                    if (isset($image->image_url) && !empty($image->image_url)) {
                                        $originalImageUrl = $image->image_url;
                                        logToFile("✅ Found URL via image_url field: " . $originalImageUrl);
                                    }
                                    // Method 2: Check url field
                                    elseif (isset($image->url) && !empty($image->url)) {
                                        $originalImageUrl = $image->url;
                                        logToFile("✅ Found URL via url field: " . $originalImageUrl);
                                    }
                                    // Method 3: Check preview_url field
                                    elseif (isset($image->preview_url) && !empty($image->preview_url)) {
                                        $originalImageUrl = $image->preview_url;
                                        logToFile("✅ Found URL via preview_url field: " . $originalImageUrl);
                                    }
                                    // Method 4: Check thumbnail_url field  
                                    elseif (isset($image->thumbnail_url) && !empty($image->thumbnail_url)) {
                                        $originalImageUrl = $image->thumbnail_url;
                                        logToFile("✅ Found URL via thumbnail_url field: " . $originalImageUrl);
                                    }
                                    else {
                                        logToFile("❌ NO URL FOUND! Checking if any field contains URL-like string:");
                                        foreach ($properties as $prop => $value) {
                                            if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '//') !== false)) {
                                                logToFile("  Potential URL in '{$prop}': " . $value);
                                                if (empty($originalImageUrl)) {
                                                    $originalImageUrl = $value;
                                                    logToFile("  ⚠️  Using this as fallback URL");
                                                }
                                            }
                                        }
                                    }
                                    
                                    logToFile("FINAL SELECTED URL: " . ($originalImageUrl ?: 'EMPTY/NULL'));
                                    
                                    // Test URL validity if found
                                    if (!empty($originalImageUrl)) {
                                        logToFile("Testing URL validity...");
                                        if (filter_var($originalImageUrl, FILTER_VALIDATE_URL)) {
                                            logToFile("✅ URL is valid format");
                                        } else {
                                            logToFile("❌ URL failed validation: " . $originalImageUrl);
                                        }
                                    }
                                    
                                    // Build final image array
                                    $finalImageArray = [
                                        'image_id' => $image->image_id ?? $image->id ?? 'NO_ID',
                                        'url' => $originalImageUrl,
                                        'image_url' => $originalImageUrl,
                                        'preview_url' => $originalImageUrl,
                                        'thumbnail_url' => $originalImageUrl,
                                        'file_name' => $image->file_name ?? 'NO_FILENAME',
                                        'width' => $image->width ?? null,
                                        'height' => $image->height ?? null,
                                        'format' => $image->format ?? null,
                                        'size' => $image->size ?? null,
                                        'create_time' => $image->create_time ?? null,
                                        'modify_time' => $image->modify_time ?? null,
                                        'displayable' => $displayable,
                                        'type' => 'image'
                                    ];
                                    
                                    logToFile("FINAL IMAGE ARRAY TO BE ADDED: " . json_encode($finalImageArray, JSON_PRETTY_PRINT));
                                    
                                    $images[] = $finalImageArray;
                                }
                                // Note: We process ALL images now, regardless of displayable flag
                                
                                logToFile("--- END PROCESSING IMAGE INDEX {$index} ---");
                            }
                            
                            // Check if there are more pages
                            if (isset($response->data->page_info)) {
                                $totalNumber = $response->data->page_info->total_number ?? 0;
                                $totalPage = $response->data->page_info->total_page ?? 1;
                                $currentPage = $response->data->page_info->page ?? $page;
                                
                                logToFile("Page {$currentPage} of {$totalPage}, Total images: {$totalNumber}");
                                
                                $hasMore = $currentPage < $totalPage;
                                $page++;
                            } else {
                                // No page info, assume no more pages
                                $hasMore = false;
                            }
                        } else {
                            logToFile("No images found in response or invalid response structure");
                            $hasMore = false;
                        }
                    } else {
                        logToFile("Failed to fetch images: HTTP {$httpCode}, Response: " . $result);
                        
                        // Try to decode response to get error details
                        $errorResponse = json_decode($result);
                        if ($errorResponse && isset($errorResponse->message)) {
                            logToFile("TikTok API Error: " . $errorResponse->message);
                        }
                        
                        // If search fails, try local storage as fallback
                        $storageFile = __DIR__ . '/media_storage.json';
                        if (file_exists($storageFile)) {
                            $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];
                            
                            $advertiserImages = array_filter($storage['images'] ?? [], function($img) use ($advertiser_id) {
                                return $img['advertiser_id'] === $advertiser_id;
                            });
                            
                            logToFile("Using storage fallback, found " . count($advertiserImages) . " images for advertiser");
                            
                            foreach ($advertiserImages as $img) {
                                $images[] = [
                                    'image_id' => $img['image_id'],
                                    'url' => $img['url'] ?? '',
                                    'file_name' => $img['file_name'] ?? 'Image',
                                    'type' => 'image'
                                ];
                            }
                        } else {
                            logToFile("Storage file does not exist, no fallback available");
                        }
                        
                        $hasMore = false;
                    }
                }
                
            } catch (Exception $e) {
                logToFile("Exception searching images: " . $e->getMessage());
                logToFile("Exception stack trace: " . $e->getTraceAsString());
                
                // Fallback to local storage
                $storageFile = __DIR__ . '/media_storage.json';
                if (file_exists($storageFile)) {
                    $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];
                    
                    $advertiserImages = array_filter($storage['images'] ?? [], function($img) use ($advertiser_id) {
                        return $img['advertiser_id'] === $advertiser_id;
                    });
                    
                    logToFile("Using storage fallback, found " . count($advertiserImages) . " images for advertiser: " . $advertiser_id);
                    
                    foreach ($advertiserImages as $img) {
                        $images[] = [
                            'image_id' => $img['image_id'],
                            'url' => $img['url'] ?? '',
                            'file_name' => $img['file_name'] ?? 'Image',
                            'type' => 'image'
                        ];
                    }
                } else {
                    logToFile("Storage file does not exist: " . $storageFile);
                }
            }
            
            // If no images from TikTok API, fallback to local storage
            if (empty($images)) {
                logToFile("No images from TikTok API, falling back to local storage...");
                $storageFile = __DIR__ . '/media_storage.json';
                if (file_exists($storageFile)) {
                    $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];
                    
                    $advertiserImages = array_filter($storage['images'] ?? [], function($img) use ($advertiser_id) {
                        return $img['advertiser_id'] === $advertiser_id;
                    });
                    
                    logToFile("Using storage fallback, found " . count($advertiserImages) . " images for advertiser: " . $advertiser_id);
                    
                    foreach ($advertiserImages as $img) {
                        $images[] = [
                            'image_id' => $img['image_id'],
                            'url' => $img['url'] ?? '',
                            'image_url' => $img['url'] ?? '', // Add explicit image_url field
                            'file_name' => $img['file_name'] ?? 'Image',
                            'width' => $img['width'] ?? null,
                            'height' => $img['height'] ?? null,
                            'format' => $img['format'] ?? null,
                            'type' => 'image'
                        ];
                    }
                } else {
                    logToFile("Storage file does not exist: " . $storageFile);
                }
            }
            
            logToFile("============ FINAL RESPONSE PREPARATION ============");
            logToFile("Total images found: " . count($images));
            
            // Log details of each image being returned to frontend
            if (!empty($images)) {
                logToFile("IMAGES TO BE RETURNED TO FRONTEND:");
                foreach ($images as $index => $img) {
                    logToFile("Image {$index}: " . json_encode($img, JSON_PRETTY_PRINT));
                    // Check if URL fields are populated
                    $urlsCheck = [
                        'url' => $img['url'] ?? 'MISSING',
                        'image_url' => $img['image_url'] ?? 'MISSING',
                        'preview_url' => $img['preview_url'] ?? 'MISSING', 
                        'thumbnail_url' => $img['thumbnail_url'] ?? 'MISSING'
                    ];
                    logToFile("  URL fields check: " . json_encode($urlsCheck));
                    
                    // Warn about empty URLs
                    if (empty($img['url']) && empty($img['image_url'])) {
                        logToFile("  ⚠️  WARNING: Image has no usable URLs!");
                    }
                }
            } else {
                logToFile("❌ NO IMAGES TO RETURN - EMPTY ARRAY");
            }
            
            $finalResponse = [
                'success' => true,
                'data' => ['list' => $images],
                'message' => count($images) > 0 ? null : 'No images found in TikTok library. Please upload images first.',
                'debug_info' => [
                    'total_images_found' => count($images),
                    'api_calls_made' => $page - 1,
                    'storage_fallback_used' => false,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            
            logToFile("COMPLETE FINAL RESPONSE: " . json_encode($finalResponse, JSON_PRETTY_PRINT));
            logToFile("============ END GET_IMAGES REQUEST ============");
            
            echo json_encode($finalResponse);
            break;

        case 'get_videos':
            logToFile("============ GET VIDEOS REQUEST ============");
            logToFile("Get Videos - Advertiser ID: " . $advertiser_id);

            $videos = [];
            $storageFile = __DIR__ . '/media_storage.json';

            // FIRST: Try to fetch directly from TikTok API (most reliable source of truth)
            // This ensures newly uploaded videos always appear immediately
            try {
                $url = 'https://business-api.tiktok.com/open_api/v1.3/file/video/ad/search/';
                $params = http_build_query([
                    'advertiser_id' => $advertiser_id,
                    'page' => 1,
                    'page_size' => 100
                ]);

                $ch = curl_init($url . '?' . $params);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => [
                        'Access-Token: ' . $config['access_token']
                    ]
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                logToFile("TikTok Video Search HTTP Code: " . $httpCode);

                if ($curlError) {
                    logToFile("CURL Error: " . $curlError);
                    throw new Exception("Network error: " . $curlError);
                }

                $result = json_decode($response, true);

                if ($httpCode == 200 && isset($result['data']['list']) && is_array($result['data']['list'])) {
                    logToFile("Found " . count($result['data']['list']) . " videos from TikTok API");

                    // Also sync to local storage for caching
                    $storage = [];
                    if (file_exists($storageFile)) {
                        $storage = json_decode(file_get_contents($storageFile), true) ?? [];
                    }
                    if (!isset($storage['videos'])) $storage['videos'] = [];
                    if (!isset($storage['images'])) $storage['images'] = [];

                    foreach ($result['data']['list'] as $video) {
                        // Extract thumbnail URL
                        $thumbnailUrl = $video['video_cover_url'] ??
                                       $video['poster_url'] ??
                                       $video['cover_image_url'] ??
                                       $video['preview_url'] ?? '';

                        $videos[] = [
                            'video_id' => $video['video_id'],
                            'url' => $video['video_url'] ?? $video['preview_url'] ?? '',
                            'preview_url' => $thumbnailUrl,
                            'poster_url' => $thumbnailUrl,
                            'thumbnail_url' => $thumbnailUrl,
                            'video_cover_url' => $video['video_cover_url'] ?? '',
                            'file_name' => $video['video_name'] ?? $video['file_name'] ?? $video['displayable_name'] ?? 'Video',
                            'duration' => $video['duration'] ?? null,
                            'width' => $video['width'] ?? null,
                            'height' => $video['height'] ?? null,
                            'size' => $video['size'] ?? null,
                            'type' => 'video',
                            'has_thumbnail' => !empty($thumbnailUrl)
                        ];

                        // Sync to local storage if not exists
                        $exists = false;
                        foreach ($storage['videos'] as $stored) {
                            if ($stored['video_id'] === $video['video_id'] && ($stored['advertiser_id'] ?? '') === $advertiser_id) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $storage['videos'][] = [
                                'video_id' => $video['video_id'],
                                'file_name' => $video['video_name'] ?? $video['file_name'] ?? 'Video',
                                'duration' => $video['duration'] ?? null,
                                'size' => $video['size'] ?? null,
                                'upload_time' => time(),
                                'advertiser_id' => $advertiser_id
                            ];
                        }
                    }

                    // Save updated storage
                    file_put_contents($storageFile, json_encode($storage, JSON_PRETTY_PRINT));

                } else {
                    // API returned error or no data
                    $errorMsg = $result['message'] ?? 'Unknown error';
                    logToFile("TikTok API error: " . $errorMsg . " (code: " . ($result['code'] ?? 'none') . ")");
                    throw new Exception("TikTok API error: " . $errorMsg);
                }

            } catch (Exception $e) {
                // FALLBACK: Use local storage if TikTok API fails
                logToFile("TikTok API failed, falling back to local storage: " . $e->getMessage());

                if (file_exists($storageFile)) {
                    $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];

                    $advertiserVideos = array_filter($storage['videos'] ?? [], function($vid) use ($advertiser_id) {
                        return ($vid['advertiser_id'] ?? '') === $advertiser_id;
                    });

                    logToFile("Found " . count($advertiserVideos) . " videos in local storage for advertiser " . $advertiser_id);

                    foreach ($advertiserVideos as $vid) {
                        $videos[] = [
                            'video_id' => $vid['video_id'],
                            'file_name' => $vid['file_name'] ?? 'Video',
                            'duration' => $vid['duration'] ?? null,
                            'size' => $vid['size'] ?? null,
                            'type' => 'video',
                            'preview_url' => '',
                            'thumbnail_url' => '',
                            'has_thumbnail' => false
                        ];
                    }
                }
            }

            logToFile("Returning " . count($videos) . " videos");
            logToFile("============ END GET VIDEOS REQUEST ============");

            echo json_encode([
                'success' => true,
                'data' => ['list' => $videos],
                'message' => count($videos) > 0 ? null : 'Upload videos to see them in library'
            ]);
            break;

        case 'get_campaigns':
            $campaign = new Campaign($config);
            $response = $campaign->getSelf([
                'advertiser_id' => $advertiser_id
            ]);

            echo json_encode([
                'success' => empty($response->code),
                'data' => $response->data ?? null
            ]);
            break;

        case 'get_adgroups':
            $adGroup = new AdGroup($config);
            $data = $requestData;

            $response = $adGroup->getSelf([
                'advertiser_id' => $advertiser_id,
                'campaign_ids' => $data['campaign_ids'] ?? []
            ]);

            echo json_encode([
                'success' => empty($response->code),
                'data' => $response->data ?? null
            ]);
            break;

        case 'get_ads':
            $ad = new Ad($config);
            $data = $requestData;

            $response = $ad->getSelf([
                'advertiser_id' => $advertiser_id,
                'adgroup_ids' => $data['adgroup_ids'] ?? []
            ]);

            echo json_encode([
                'success' => empty($response->code),
                'data' => $response->data ?? null
            ]);
            break;

        case 'publish_ads':
            $ad = new Ad($config);
            $data = $requestData;

            $response = $ad->statusUpdate([
                'advertiser_id' => $advertiser_id,
                'ad_ids' => $data['ad_ids'],
                'operation_status' => 'ENABLE'
            ]);

            echo json_encode([
                'success' => empty($response->code),
                'data' => $response->data ?? null,
                'message' => $response->message ?? 'Ads published successfully'
            ]);
            break;

        case 'duplicate_ad':
            $ad = new Ad($config);
            $data = $requestData;

            $originalAd = $ad->getSelf([
                'advertiser_id' => $advertiser_id,
                'ad_ids' => [$data['ad_id']]
            ]);

            if ($originalAd->hasErrors() || empty($originalAd->data->list)) {
                throw new Exception('Original ad not found');
            }

            $originalAdData = $originalAd->data->list[0];

            $params = [
                'advertiser_id' => $advertiser_id,
                'adgroup_id' => $originalAdData->adgroup_id,
                'ad_name' => $data['new_ad_name'] ?? $originalAdData->ad_name . ' (Copy)',
                'ad_format' => $originalAdData->ad_format,
                'ad_text' => $data['ad_text'] ?? $originalAdData->ad_text,
                'call_to_action' => $originalAdData->call_to_action,
                'landing_page_url' => $originalAdData->landing_page_url,
                'identity_id' => $originalAdData->identity_id,
                'identity_type' => $originalAdData->identity_type
            ];

            if (!empty($originalAdData->video_id)) {
                $params['video_id'] = $data['video_id'] ?? $originalAdData->video_id;
            }
            if (!empty($originalAdData->image_ids)) {
                $params['image_ids'] = $data['image_ids'] ?? $originalAdData->image_ids;
            }

            $response = $ad->create($params);

            echo json_encode([
                'success' => empty($response->code),
                'data' => $response->data ?? null,
                'message' => $response->message ?? 'Ad duplicated successfully'
            ]);
            break;

        case 'duplicate_adgroup':
            $adGroup = new AdGroup($config);
            $data = $requestData;

            $originalAdGroup = $adGroup->getSelf([
                'advertiser_id' => $advertiser_id,
                'adgroup_ids' => [$data['adgroup_id']]
            ]);

            if ($originalAdGroup->hasErrors() || empty($originalAdGroup->data->list)) {
                throw new Exception('Original ad group not found');
            }

            $originalData = $originalAdGroup->data->list[0];

            $params = [
                'advertiser_id' => $advertiser_id,
                'campaign_id' => $originalData->campaign_id,
                'adgroup_name' => $data['new_adgroup_name'] ?? $originalData->adgroup_name . ' (Copy)',
                'placement_type' => $originalData->placement_type,
                'placements' => $originalData->placements,
                'location_ids' => $originalData->location_ids,
                'optimization_goal' => $originalData->optimization_goal,
                'billing_event' => $originalData->billing_event,
                'bid_type' => $originalData->bid_type,
                'bid_price' => $data['bid_price'] ?? $originalData->bid_price,
                'budget_mode' => $originalData->budget_mode,
                'budget' => $data['budget'] ?? $originalData->budget,
                'schedule_type' => $originalData->schedule_type,
                'timezone' => $originalData->timezone
            ];

            if (!empty($originalData->dayparting)) {
                $params['dayparting'] = $data['dayparting'] ?? $originalData->dayparting;
            }

            $response = $adGroup->create($params);

            echo json_encode([
                'success' => empty($response->code),
                'data' => $response->data ?? null,
                'message' => $response->message ?? 'Ad group duplicated successfully'
            ]);
            break;

        case 'bulk_duplicate_campaign':
            // Duplicate campaign to a different ad account
            logToFile("============ BULK DUPLICATE CAMPAIGN ============");
            $data = $requestData;

            $targetAdvertiserId = $data['target_advertiser_id'] ?? '';
            if (empty($targetAdvertiserId)) {
                throw new Exception('Target advertiser ID is required');
            }

            logToFile("Target Advertiser: " . $targetAdvertiserId);
            logToFile("Request Data: " . json_encode($data, JSON_PRETTY_PRINT));

            // Create campaign in target account
            // NOTE: Budget is set at ad group level (not campaign level) for compatibility
            $campaignParams = [
                'advertiser_id' => $targetAdvertiserId,
                'campaign_name' => $data['campaign_name'] ?? 'Duplicated Campaign',
                'objective_type' => strtoupper($data['objective'] ?? 'TRAFFIC'),
                'budget_mode' => 'BUDGET_MODE_INFINITE', // Budget set at ad group level
                'operation_status' => 'DISABLE' // Start paused - user must manually enable
            ];

            logToFile("Creating campaign: " . json_encode($campaignParams));

            $campaignResult = makeApiCall(
                'https://business-api.tiktok.com/open_api/v1.3/campaign/create/',
                $campaignParams,
                $config['access_token'],
                'POST'
            );

            if (empty($campaignResult) || (!empty($campaignResult['code']) && $campaignResult['code'] != 0)) {
                $errorMsg = $campaignResult['message'] ?? 'Failed to create campaign';
                logToFile("Campaign creation failed: " . $errorMsg);
                throw new Exception("Campaign creation failed: " . $errorMsg);
            }

            $newCampaignId = $campaignResult['data']['campaign_id'] ?? null;
            if (!$newCampaignId) {
                throw new Exception('No campaign ID returned');
            }

            logToFile("Campaign created: " . $newCampaignId);

            // Determine schedule based on input
            $scheduleType = $data['schedule_type'] ?? 'start_now';
            if ($scheduleType === 'start_now') {
                // Start now, run continuously (1 year)
                $scheduleStartTime = getESTDateTime();
                $scheduleEndTime = getESTDateTime('+1 year');
            } else {
                // Use provided dates
                $scheduleStartTime = !empty($data['schedule_start'])
                    ? date('Y-m-d H:i:s', strtotime($data['schedule_start']))
                    : getESTDateTime();
                $scheduleEndTime = !empty($data['schedule_end'])
                    ? date('Y-m-d H:i:s', strtotime($data['schedule_end']))
                    : getESTDateTime('+30 days');
            }

            // Create ad group in target account
            $adgroupParams = [
                'advertiser_id' => $targetAdvertiserId,
                'campaign_id' => $newCampaignId,
                'adgroup_name' => ($data['campaign_name'] ?? 'Campaign') . ' - Ad Group',
                'promotion_type' => strtoupper($data['objective'] ?? 'TRAFFIC') === 'LEAD_GENERATION' ? 'LEAD_GENERATION' : 'WEBSITE',
                'promotion_target_type' => 'EXTERNAL_WEBSITE',
                'placement_type' => 'PLACEMENT_TYPE_AUTOMATIC',
                'location_ids' => ['6252001'], // Default USA
                'optimization_goal' => strtoupper($data['optimization_goal'] ?? 'CLICK'),
                'bid_type' => $data['bid_type'] ?? 'BID_TYPE_NO_BID',
                'billing_event' => $data['billing_event'] ?? 'CPC',
                'budget_mode' => 'BUDGET_MODE_DAY', // Daily budget at ad group level
                'budget' => floatval($data['budget'] ?? 50), // Budget moved from campaign to ad group
                'schedule_type' => 'SCHEDULE_START_END',
                'schedule_start_time' => $scheduleStartTime,
                'schedule_end_time' => $scheduleEndTime,
                'pixel_id' => $data['pixel_id'],
                'identity_type' => 'CUSTOMIZED_USER',
                'identity_id' => $data['identity_id']
            ];

            logToFile("Creating ad group: " . json_encode($adgroupParams));

            $adgroupResult = makeApiCall(
                'https://business-api.tiktok.com/open_api/v1.3/adgroup/create/',
                $adgroupParams,
                $config['access_token'],
                'POST'
            );

            if (empty($adgroupResult) || (!empty($adgroupResult['code']) && $adgroupResult['code'] != 0)) {
                $errorMsg = $adgroupResult['message'] ?? 'Failed to create ad group';
                logToFile("Ad group creation failed: " . $errorMsg);
                throw new Exception("Ad group creation failed: " . $errorMsg);
            }

            $newAdgroupId = $adgroupResult['data']['adgroup_id'] ?? null;
            if (!$newAdgroupId) {
                throw new Exception('No ad group ID returned');
            }

            logToFile("Ad group created: " . $newAdgroupId);

            // Support multiple videos - create an ad for each video
            $videoIds = [];
            if (!empty($data['video_ids']) && is_array($data['video_ids'])) {
                $videoIds = $data['video_ids'];
            } elseif (!empty($data['video_id'])) {
                $videoIds = [$data['video_id']];
            }

            if (empty($videoIds)) {
                throw new Exception('At least one video is required');
            }

            $landingPageUrl = $data['landing_page_url'] ?? '';
            $createdAdIds = [];

            // Create an ad for each video
            foreach ($videoIds as $index => $videoId) {
                $adName = $data['ad_name'] ?? ($data['campaign_name'] ?? 'Ad') . ' - Ad';
                // Add number suffix if multiple videos
                if (count($videoIds) > 1) {
                    $adName .= ' ' . ($index + 1);
                }

                $adParams = [
                    'advertiser_id' => $targetAdvertiserId,
                    'adgroup_id' => $newAdgroupId,
                    'ad_name' => $adName,
                    'ad_format' => 'SINGLE_VIDEO',
                    'ad_text' => is_array($data['ad_texts']) ? ($data['ad_texts'][0] ?? 'Learn More') : ($data['ad_texts'] ?? 'Learn More'),
                    'video_id' => $videoId,
                    'call_to_action' => $data['call_to_action'] ?? 'LEARN_MORE',
                    'identity_type' => 'CUSTOMIZED_USER',
                    'identity_id' => $data['identity_id'],
                    'landing_page_url' => $landingPageUrl
                ];

                // Add portfolio if provided
                if (!empty($data['portfolio_id'])) {
                    $adParams['creative_portfolio_id'] = $data['portfolio_id'];
                }

                logToFile("Creating ad " . ($index + 1) . "/" . count($videoIds) . ": " . json_encode($adParams));

                $adResult = makeApiCall(
                    'https://business-api.tiktok.com/open_api/v1.3/ad/create/',
                    $adParams,
                    $config['access_token'],
                    'POST'
                );

                if (!empty($adResult) && (empty($adResult['code']) || $adResult['code'] == 0)) {
                    $newAdId = $adResult['data']['ad_id'] ?? null;
                    if ($newAdId) {
                        $createdAdIds[] = $newAdId;
                    }
                    logToFile("Ad created: " . ($newAdId ?? 'N/A'));
                } else {
                    $errorMsg = $adResult['message'] ?? 'Failed to create ad';
                    logToFile("Ad creation failed for video $videoId: " . $errorMsg);
                }
            }

            outputJsonResponse([
                'success' => true,
                'data' => [
                    'campaign_id' => $newCampaignId,
                    'adgroup_id' => $newAdgroupId,
                    'ad_ids' => $createdAdIds,
                    'ad_id' => $createdAdIds[0] ?? null // Backward compatibility
                ],
                'message' => 'Campaign duplicated successfully with ' . count($createdAdIds) . ' ad(s)'
            ]);
            break;

        case 'sync_images_from_tiktok':
            logToFile("Syncing images from TikTok - Advertiser ID: " . $advertiser_id);
            
            $allImages = [];
            $syncedCount = 0;
            
            try {
                // Use the search endpoint to fetch all images from TikTok
                $page = 1;
                $pageSize = 100;
                $hasMore = true;
                
                while ($hasMore && $page <= 20) {
                    $url = "https://business-api.tiktok.com/open_api/v1.3/file/image/ad/search/?" . 
                           "advertiser_id={$advertiser_id}&" .
                           "page={$page}&" .
                           "page_size={$pageSize}";
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => "",
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => "GET",
                        CURLOPT_HTTPHEADER => [
                            "Access-Token: " . $accessToken
                        ]
                    ]);
                    
                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode == 200) {
                        $response = json_decode($result);
                        
                        if (isset($response->data->list) && is_array($response->data->list)) {
                            foreach ($response->data->list as $image) {
                                $allImages[] = [
                                    'image_id' => $image->image_id,
                                    'url' => $image->image_url ?? '',  // image_url is the correct field
                                    'file_name' => $image->file_name ?? $image->material_name ?? 'Image',
                                    'width' => $image->width ?? null,
                                    'height' => $image->height ?? null,
                                    'format' => $image->format ?? null,
                                    'displayable' => $image->displayable ?? true,
                                    'type' => 'image'
                                ];
                                $syncedCount++;
                            }
                            
                            // Check pagination
                            if (isset($response->data->page_info)) {
                                $totalPage = $response->data->page_info->total_page ?? 1;
                                $currentPage = $response->data->page_info->page ?? $page;
                                $hasMore = $currentPage < $totalPage;
                                $page++;
                            } else {
                                $hasMore = false;
                            }
                        } else {
                            $hasMore = false;
                        }
                    } else {
                        throw new Exception("Failed to fetch images: HTTP {$httpCode}");
                    }
                }
                
                logToFile("Synced {$syncedCount} images from TikTok");
                
                echo json_encode([
                    'success' => true,
                    'data' => ['images' => $allImages, 'count' => $syncedCount],
                    'message' => "Found {$syncedCount} images in TikTok library"
                ]);
                
            } catch (Exception $e) {
                logToFile("Error syncing images: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to sync images: ' . $e->getMessage()
                ]);
            }
            break;

        case 'sync_tiktok_library':
            // Sync with TikTok's actual media library
            logToFile("Syncing TikTok media library for advertiser: " . $advertiser_id);
            
            $storageFile = __DIR__ . '/media_storage.json';
            $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];
            
            // Search for videos using TikTok's search endpoint
            $url = 'https://business-api.tiktok.com/open_api/v1.3/file/video/ad/search/';
            $params = http_build_query([
                'advertiser_id' => $advertiser_id,
                'page' => 1,
                'page_size' => 100
            ]);
            
            $ch = curl_init($url . '?' . $params);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Access-Token: ' . $config['access_token']
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $result = json_decode($response, true);
            $videoCount = 0;
            
            if ($httpCode == 200 && isset($result['data']['list'])) {
                foreach ($result['data']['list'] as $video) {
                    // Check if already exists
                    $exists = false;
                    foreach ($storage['videos'] as $stored) {
                        if ($stored['video_id'] === $video['video_id'] && $stored['advertiser_id'] === $advertiser_id) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $storage['videos'][] = [
                            'video_id' => $video['video_id'],
                            'file_name' => $video['video_name'] ?? $video['file_name'] ?? 'Video',
                            'duration' => $video['duration'] ?? null,
                            'size' => $video['size'] ?? null,
                            'upload_time' => time(),
                            'advertiser_id' => $advertiser_id
                        ];
                        $videoCount++;
                    }
                }
            }
            
            // Now search for images using TikTok's image search endpoint
            $imageUrl = 'https://business-api.tiktok.com/open_api/v1.3/file/image/ad/search/';
            $imageParams = http_build_query([
                'advertiser_id' => $advertiser_id,
                'page' => 1,
                'page_size' => 100
            ]);
            
            $ch = curl_init($imageUrl . '?' . $imageParams);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Access-Token: ' . $config['access_token']
                ]
            ]);
            
            $imageResponse = curl_exec($ch);
            $imageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $imageResult = json_decode($imageResponse, true);
            $imageCount = 0;
            
            logToFile("Image search HTTP code: " . $imageHttpCode);
            logToFile("Image search response: " . $imageResponse);
            
            if ($imageHttpCode == 200 && isset($imageResult['data']['list'])) {
                foreach ($imageResult['data']['list'] as $image) {
                    // Check if already exists
                    $exists = false;
                    foreach ($storage['images'] as $stored) {
                        if ($stored['image_id'] === $image['image_id'] && $stored['advertiser_id'] === $advertiser_id) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $storage['images'][] = [
                            'image_id' => $image['image_id'],
                            'file_name' => $image['image_name'] ?? $image['file_name'] ?? 'Image',
                            'width' => $image['width'] ?? null,
                            'height' => $image['height'] ?? null,
                            'size' => $image['size'] ?? null,
                            'upload_time' => time(),
                            'advertiser_id' => $advertiser_id
                        ];
                        $imageCount++;
                    }
                }
            }
            
            file_put_contents($storageFile, json_encode($storage, JSON_PRETTY_PRINT));
            
            echo json_encode([
                'success' => true,
                'message' => "Synced $videoCount new videos and $imageCount new images from TikTok",
                'total_videos' => count(array_filter($storage['videos'], function($v) use ($advertiser_id) {
                    return $v['advertiser_id'] === $advertiser_id;
                })),
                'total_images' => count(array_filter($storage['images'], function($i) use ($advertiser_id) {
                    return $i['advertiser_id'] === $advertiser_id;
                }))
            ]);
            break;
            
        case 'add_existing_media':
            // Allow manual addition of existing TikTok media IDs
            $data = $requestData;
            
            $storageFile = __DIR__ . '/media_storage.json';
            $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];
            
            if (!empty($data['video_ids'])) {
                foreach ($data['video_ids'] as $video_id) {
                    $storage['videos'][] = [
                        'video_id' => $video_id,
                        'file_name' => $data['file_names'][$video_id] ?? 'Video',
                        'upload_time' => time(),
                        'advertiser_id' => $advertiser_id
                    ];
                }
            }
            
            if (!empty($data['image_ids'])) {
                foreach ($data['image_ids'] as $image_id) {
                    $storage['images'][] = [
                        'image_id' => $image_id,
                        'file_name' => $data['file_names'][$image_id] ?? 'Image',
                        'upload_time' => time(),
                        'advertiser_id' => $advertiser_id
                    ];
                }
            }
            
            file_put_contents($storageFile, json_encode($storage, JSON_PRETTY_PRINT));
            
            echo json_encode([
                'success' => true,
                'message' => 'Media IDs added successfully'
            ]);
            break;
            
        case 'logout':
            session_destroy();
            echo json_encode(['success' => true]);
            break;

        case 'get_selected_advertiser':
            // Return the currently selected advertiser from session
            $selectedAdvertiserId = $_SESSION['selected_advertiser_id'] ?? '';
            
            logToFile("Get Selected Advertiser - Session ID: {$selectedAdvertiserId}");
            
            if (empty($selectedAdvertiserId)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No advertiser selected in session'
                ]);
                exit;
            }
            
            // Get the advertiser details from TikTok API
            $accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
            $url = "https://business-api.tiktok.com/open_api/v1.3/oauth2/advertiser/get/?" . 
                   "app_id=" . ($_ENV['TIKTOK_APP_ID'] ?? '') . "&" .
                   "secret=" . ($_ENV['TIKTOK_APP_SECRET'] ?? '');
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . $accessToken,
                    "Content-Type: application/json"
                ],
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $response = json_decode($result, true);
                if ($response && isset($response['code']) && $response['code'] == 0) {
                    // Find the selected advertiser in the list
                    $selectedAdvertiser = null;
                    foreach ($response['data']['list'] as $advertiser) {
                        if ($advertiser['advertiser_id'] == $selectedAdvertiserId) {
                            $selectedAdvertiser = $advertiser;
                            break;
                        }
                    }
                    
                    if ($selectedAdvertiser) {
                        logToFile("Selected advertiser found: " . json_encode($selectedAdvertiser));
                        echo json_encode([
                            'success' => true,
                            'advertiser' => $selectedAdvertiser
                        ]);
                    } else {
                        logToFile("Selected advertiser ID not found in available advertisers");
                        echo json_encode([
                            'success' => false,
                            'message' => 'Selected advertiser not found'
                        ]);
                    }
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => $response['message'] ?? 'Failed to get advertisers'
                    ]);
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'API request failed'
                ]);
            }
            exit;

        case 'get_debug_logs':
            // Return recent log entries for debugging
            $logFile = __DIR__ . '/api_debug.log';
            if (file_exists($logFile)) {
                $logs = file_get_contents($logFile);
                // Get last 50 lines
                $lines = explode("\n", $logs);
                $lastLines = array_slice($lines, -50);
                echo json_encode([
                    'success' => true,
                    'logs' => implode("\n", $lastLines)
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'No log file found'
                ]);
            }
            exit;
            
        case 'create_identity':
            $identity = new Identity($config);
            $data = $requestData;
            
            logToFile("============ CREATE IDENTITY REQUEST ============");
            logToFile("Create Identity Request: " . json_encode($data, JSON_PRETTY_PRINT));
            
            // Validate required fields
            if (empty($data['display_name'])) {
                throw new Exception('Display name is required');
            }
            
            // For identity creation, we need to either:
            // 1. Use an image_id if provided
            // 2. Upload a default avatar and get its image_id
            // 3. Or create without image_uri (TikTok will use default)
            
            $params = [
                'advertiser_id' => $advertiser_id,
                'display_name' => $data['display_name']
            ];
            
            // Only add image_uri if we have a valid image_id
            if (!empty($data['image_id'])) {
                $params['image_uri'] = $data['image_id'];
                logToFile("Using provided image_id: " . $data['image_id']);
            } else {
                logToFile("No image_id provided, TikTok will use default avatar");
            }
            
            logToFile("Identity Creation Params: " . json_encode($params, JSON_PRETTY_PRINT));
            
            $response = $identity->create($params);
            
            logToFile("Identity Creation Response: " . json_encode($response, JSON_PRETTY_PRINT));
            
            $success = empty($response->code) || $response->code == 0;
            
            echo json_encode([
                'success' => $success,
                'data' => $response->data ?? null,
                'message' => $success ? 'Identity created successfully' : ($response->message ?? 'Failed to create identity'),
                'code' => $response->code ?? null
            ]);
            break;

        case 'test_image_api':
            // Test endpoint to check image API functionality
            $file = new File($config);
            
            try {
                // Test basic image search
                $url = "https://business-api.tiktok.com/open_api/v1.3/file/image/ad/search/?" . 
                       "advertiser_id={$advertiser_id}&" .
                       "page=1&" .
                       "page_size=1";
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => [
                        "Access-Token: " . $accessToken
                    ]
                ]);
                
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                echo json_encode([
                    'success' => $httpCode === 200,
                    'http_code' => $httpCode,
                    'api_response' => json_decode($result),
                    'message' => $httpCode === 200 ? 'Image API working' : 'Image API failed'
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Test failed: ' . $e->getMessage(),
                    'error' => $e->getMessage()
                ]);
            }
            break;

        case 'debug_storage':
            // Debug endpoint to check media storage
            $storageFile = __DIR__ . '/media_storage.json';
            if (file_exists($storageFile)) {
                $storage = json_decode(file_get_contents($storageFile), true) ?? ['images' => [], 'videos' => []];
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'storage_file_exists' => true,
                        'total_images' => count($storage['images'] ?? []),
                        'total_videos' => count($storage['videos'] ?? []),
                        'advertiser_images' => count(array_filter($storage['images'] ?? [], function($img) use ($advertiser_id) {
                            return $img['advertiser_id'] === $advertiser_id;
                        })),
                        'advertiser_videos' => count(array_filter($storage['videos'] ?? [], function($vid) use ($advertiser_id) {
                            return $vid['advertiser_id'] === $advertiser_id;
                        })),
                        'recent_images' => array_slice($storage['images'] ?? [], -5),
                        'recent_videos' => array_slice($storage['videos'] ?? [], -5)
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Storage file does not exist',
                    'data' => ['storage_file_exists' => false]
                ]);
            }
            break;

        case 'generate_video_thumbnail':
            logToFile("Generate Video Thumbnail - Video ID: " . ($requestData['video_id'] ?? 'not provided'));
            
            if (!isset($requestData['video_id'])) {
                throw new Exception('Video ID is required');
            }
            
            $videoId = $requestData['video_id'];
            
            // For now, we'll create a placeholder thumbnail generation
            // In a production environment, you might want to:
            // 1. Extract a frame from the video using ffmpeg
            // 2. Use TikTok's thumbnail generation API if available
            // 3. Generate a custom thumbnail from video metadata
            
            // Try to get video info to check if thumbnail already exists
            $file = new File($config);
            
            try {
                $response = $file->getVideoInfo([
                    'advertiser_id' => $advertiser_id,
                    'video_ids' => [$videoId]
                ]);
                
                logToFile("Video Info Response: " . json_encode($response, JSON_PRETTY_PRINT));
            } catch (Exception $videoInfoError) {
                logToFile("Get video info failed: " . $videoInfoError->getMessage());
                
                echo json_encode([
                    'success' => false,
                    'message' => 'Get video info failed: ' . $videoInfoError->getMessage(),
                    'error' => 'VIDEO_INFO_FAILED'
                ]);
                break;
            }
            
            if (!empty($response->data->list) && !empty($response->data->list[0])) {
                $videoData = $response->data->list[0];
                
                // Check for existing thumbnail URLs
                $thumbnailUrl = $videoData->video_cover_url ?? 
                               $videoData->cover_url ?? 
                               $videoData->preview_url ?? 
                               $videoData->thumbnail_url ?? 
                               null;
                
                if ($thumbnailUrl) {
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'thumbnail_url' => $thumbnailUrl,
                            'video_id' => $videoId
                        ],
                        'message' => 'Thumbnail found'
                    ]);
                } else {
                    // Generate a fallback thumbnail URL based on TikTok's patterns
                    // This is a best-effort approach
                    $fallbackThumbnail = "https://p16-ad-sg.ibyteimg.com/obj/ad-pattern-sg/{$videoId}.jpeg";
                    
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'thumbnail_url' => $fallbackThumbnail,
                            'video_id' => $videoId,
                            'fallback' => true
                        ],
                        'message' => 'Generated fallback thumbnail'
                    ]);
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unable to generate thumbnail - video not found'
                ]);
            }
            break;

        case 'get_advertiser_info':
            logToFile("============ GET ADVERTISER INFO REQUEST ============");

            $advertiserId = $_ENV['TIKTOK_ADVERTISER_ID'] ?? '';
            $accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';

            if (empty($advertiserId) || empty($accessToken)) {
                throw new Exception('Advertiser ID and Access token are required');
            }

            $url = "https://business-api.tiktok.com/open_api/v1.3/advertiser/info/?advertiser_id=" . $advertiserId;

            logToFile("Fetching advertiser info from TikTok API: {$url}");

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . $accessToken,
                    "Content-Type: application/json"
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logToFile("Advertiser Info API Response Code: {$httpCode}");
            logToFile("Advertiser Info API Response: " . $result);

            if ($httpCode === 200) {
                $response = json_decode($result);

                if (isset($response->data)) {
                    $advertiserData = $response->data;

                    // Check if timezone is UTC-5 (Colombia/Mexico/Eastern Time all work)
                    $timezone = $advertiserData->timezone ?? 'Unknown';
                    $timezoneOffset = $advertiserData->timezone_offset ?? 0;

                    // Accept any timezone with UTC-5 offset
                    // Common UTC-5 zones: America/Bogota, America/Mexico_City, America/Lima, etc.
                    $isUTC5 = ($timezoneOffset == -5);

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'advertiser_id' => $advertiserData->advertiser_id ?? $advertiserId,
                            'advertiser_name' => $advertiserData->name ?? 'Unknown',
                            'timezone' => $timezone,
                            'timezone_offset' => $timezoneOffset,
                            'is_colombia' => $isUTC5, // Renamed but still checking UTC-5
                            'currency' => $advertiserData->currency ?? 'Unknown',
                            'status' => $advertiserData->status ?? 'Unknown'
                        ],
                        'message' => 'Advertiser info fetched successfully'
                    ]);
                } else {
                    throw new Exception('Invalid advertiser info API response format');
                }
            } else {
                throw new Exception("Advertiser info API request failed with code: {$httpCode}");
            }
            break;

        case 'get_timezones':
            logToFile("============ GET TIMEZONES REQUEST ============");

            $accessToken = $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
            if (empty($accessToken)) {
                throw new Exception('Access token is required');
            }

            $url = "https://business-api.tiktok.com/open_api/v1.3/tool/timezone/";

            logToFile("Fetching timezones from TikTok API: {$url}");

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Access-Token: " . $accessToken,
                    "Content-Type: application/json"
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logToFile("Timezone API Response Code: {$httpCode}");
            logToFile("Timezone API Response: " . $result);

            if ($httpCode === 200) {
                $response = json_decode($result);

                if (isset($response->data->timezone_list)) {
                    // Filter and format timezones for easier use
                    $timezones = [];
                    foreach ($response->data->timezone_list as $tz) {
                        $timezones[] = [
                            'timezone_id' => $tz->timezone_id,
                            'timezone_name' => $tz->timezone_name,
                            'utc_offset_hour' => $tz->utc_offset_hour
                        ];
                    }

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'timezones' => $timezones,
                            'total' => count($timezones)
                        ],
                        'message' => 'Timezones fetched successfully'
                    ]);
                } else {
                    throw new Exception('Invalid timezone API response format');
                }
            } else {
                throw new Exception("Timezone API request failed with code: {$httpCode}");
            }
            break;

        case 'auto_crop_and_upload':
            logToFile("============ AUTO CROP AND UPLOAD REQUEST ============");
            
            // Check if GD extension is loaded
            if (!extension_loaded('gd')) {
                throw new Exception('GD extension is not enabled. Please enable php-gd extension.');
            }
            
            // Get image details from request
            $imageId = $requestData['image_id'] ?? '';
            $imageUrl = $requestData['image_url'] ?? '';
            $fileName = $requestData['file_name'] ?? 'cropped_image';
            
            logToFile("Processing image: ID={$imageId}, URL={$imageUrl}, FileName={$fileName}");
            logToFile("GD extension enabled: " . (extension_loaded('gd') ? 'YES' : 'NO'));
            
            if (empty($imageUrl)) {
                throw new Exception('Image URL is required');
            }
            
            // Create uploads directory if it doesn't exist
            $uploadsDir = __DIR__ . '/uploads';
            if (!is_dir($uploadsDir)) {
                if (!mkdir($uploadsDir, 0755, true)) {
                    throw new Exception('Failed to create uploads directory');
                }
                logToFile("Created uploads directory: {$uploadsDir}");
            }
            
            // Download the original image with better error handling
            logToFile("Downloading image from: {$imageUrl}");
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'TikTok-Campaign-Launcher/1.0'
                ]
            ]);
            $imageContent = file_get_contents($imageUrl, false, $context);
            if ($imageContent === false) {
                throw new Exception('Failed to download image from URL: ' . $imageUrl);
            }
            logToFile("Downloaded image, size: " . strlen($imageContent) . " bytes");
            
            // Create image from downloaded content
            $src = imagecreatefromstring($imageContent);
            if ($src === false) {
                throw new Exception('Failed to create image from downloaded content. Invalid image format.');
            }
            
            $width = imagesx($src);
            $height = imagesy($src);
            logToFile("Original image dimensions: {$width}x{$height}");
            
            // If already square, use original dimensions for target size
            if ($width === $height) {
                logToFile("Image is already square: {$width}x{$height}");
                $targetSize = min(max($width, 200), 500); // Between 200-500px for good quality
                $final = imagecreatetruecolor($targetSize, $targetSize);
                imagecopyresampled($final, $src, 0, 0, 0, 0, $targetSize, $targetSize, $width, $height);
                logToFile("Resized square image to: {$targetSize}x{$targetSize}");
            } else {
                // Crop to square (center crop)
                $minSide = min($width, $height);
                $x = intval(($width - $minSide) / 2);
                $y = intval(($height - $minSide) / 2);
                
                logToFile("Cropping to square: {$minSide}x{$minSide} from position ({$x}, {$y})");
                
                // Use manual cropping instead of imagecrop for better compatibility
                $square = imagecreatetruecolor($minSide, $minSide);
                if (!imagecopy($square, $src, 0, 0, $x, $y, $minSide, $minSide)) {
                    imagedestroy($src);
                    throw new Exception('Failed to crop image to square');
                }
                
                // Resize to good quality size (300x300 for perfect square)
                $targetSize = 300;
                $final = imagecreatetruecolor($targetSize, $targetSize);
                imagecopyresampled($final, $square, 0, 0, 0, 0, $targetSize, $targetSize, $minSide, $minSide);
                logToFile("Cropped and resized to: {$targetSize}x{$targetSize}");
                
                // Clean up intermediate image
                imagedestroy($square);
            }
            
            // Get final dimensions before saving
            $finalWidth = imagesx($final);
            $finalHeight = imagesy($final);
            
            // Save the cropped image temporarily
            $tempFile = $uploadsDir . '/' . time() . '_cropped_' . preg_replace('/[^a-zA-Z0-9.]/', '_', $fileName);
            if (!imagejpeg($final, $tempFile, 95)) {
                imagedestroy($src);
                if (isset($square)) imagedestroy($square);
                imagedestroy($final);
                throw new Exception('Failed to save cropped image');
            }
            
            logToFile("Cropped image saved temporarily: {$tempFile}");
            logToFile("Final cropped dimensions: {$finalWidth}x{$finalHeight}");
            
            // Upload to TikTok
            $files = new File($config);
            
            // Prepare upload data
            $finalFileName = pathinfo($fileName, PATHINFO_FILENAME) . "_{$finalWidth}x{$finalHeight}.jpg";
            $uploadData = [
                'advertiser_id' => $advertiser_id,
                'file_name' => $finalFileName,
                'file' => new CURLFile($tempFile, 'image/jpeg', $finalFileName)
            ];
            
            logToFile("Uploading cropped image to TikTok...");
            
            $response = $files->upload($uploadData);
            
            logToFile("TikTok Upload Response: " . json_encode($response, JSON_PRETTY_PRINT));
            
            // Cleanup temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            imagedestroy($src);
            imagedestroy($final);
            
            $success = empty($response->code) || $response->code == 0;
            
            if ($success && isset($response->data->image_id)) {
                echo json_encode([
                    'success' => true,
                    'image_id' => $response->data->image_id,
                    'file_name' => pathinfo($fileName, PATHINFO_FILENAME) . "_{$finalWidth}x{$finalHeight}.jpg",
                    'width' => $finalWidth,
                    'height' => $finalHeight,
                    'message' => "Image automatically cropped to {$finalWidth}x{$finalHeight} and uploaded to TikTok",
                    'original_image_id' => $imageId
                ]);
            } else {
                throw new Exception($response->message ?? 'Failed to upload cropped image to TikTok');
            }
            break;

        default:
            logToFile("Unknown action received: " . $action);
            outputJsonResponse([
                'success' => false,
                'message' => 'Invalid action: ' . $action
            ]);
    }

} catch (Exception $e) {
    logToFile("EXCEPTION: " . $e->getMessage());
    logToFile("Stack Trace: " . $e->getTraceAsString());

    http_response_code(500);
    outputJsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
