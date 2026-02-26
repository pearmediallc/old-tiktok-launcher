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

// Disable HTML error output - this is an API endpoint, only return JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('html_errors', '0');

// Custom error handler to convert PHP errors to JSON
set_error_handler(function($severity, $message, $file, $line) {
    // Log the error for debugging
    error_log("PHP Error in api-smartplus.php: [$severity] $message in $file on line $line");
    // Don't output anything - let the script continue or fail gracefully
    return true;
});

// Custom exception handler to return JSON on uncaught exceptions
set_exception_handler(function($exception) {
    // Log full error details server-side
    error_log("Uncaught Exception in api-smartplus.php: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    http_response_code(500);
    // Don't expose internal error details to client (security)
    echo json_encode([
        'success' => false,
        'message' => 'An internal server error occurred. Please try again later.'
    ]);
    exit;
});

session_start();
header('Content-Type: application/json');

// Load Cache system
require_once __DIR__ . '/includes/Cache.php';
$cache = Cache::getInstance();

// Load Security helper for data redaction
require_once __DIR__ . '/includes/Security.php';

// Load Database class for portfolio storage
require_once __DIR__ . '/database/Database.php';

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
// OAuth flow stores token in 'oauth_access_token', legacy stores in 'access_token'
$accessToken = $_SESSION['oauth_access_token'] ?? $_ENV['TIKTOK_ACCESS_TOKEN'] ?? $_SESSION['access_token'] ?? '';
$advertiserId = $_SESSION['selected_advertiser_id'] ?? '';

// Log function with optional data redaction
function logSmartPlus($message, $data = null) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/smartplus_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');

    $logMessage = "[$timestamp] $message";
    if ($data !== null) {
        // Redact sensitive data before logging
        $safeData = Security::redactSensitiveData($data);
        $logMessage .= "\n" . json_encode($safeData, JSON_PRETTY_PRINT);
    }

    file_put_contents($logFile, "$logMessage\n", FILE_APPEND);
}

// Generate unique request ID (must be numeric string for TikTok API)
function generateRequestId() {
    return (string)(time() . rand(1000, 9999));
}

// Get current datetime in EST timezone (America/New_York)
// Used for display purposes and local time references
function getESTDateTime($modifier = null) {
    $est = new DateTimeZone('America/New_York');
    $dt = new DateTime('now', $est);
    if ($modifier) {
        $dt->modify($modifier);
    }
    return $dt->format('Y-m-d H:i:s');
}

// Get current datetime in UTC timezone
// TikTok API requires schedule times in UTC+0 format
function getUTCDateTime($modifier = null) {
    $utc = new DateTimeZone('UTC');
    $dt = new DateTime('now', $utc);
    if ($modifier) {
        $dt->modify($modifier);
    }
    return $dt->format('Y-m-d H:i:s');
}

// Convert user's scheduled time to UTC
// Accepts the user's browser timezone (e.g., "Asia/Kolkata", "America/New_York")
// Parses the time in that timezone and converts to UTC for TikTok API
function convertScheduledTimeToUTC($scheduledTimeString, $userTimezone = 'America/New_York') {
    $utc = new DateTimeZone('UTC');

    // Validate the timezone string; fall back to America/New_York if invalid
    try {
        $tz = new DateTimeZone($userTimezone);
    } catch (Exception $e) {
        logSmartPlus("Invalid timezone '$userTimezone', falling back to America/New_York");
        $tz = new DateTimeZone('America/New_York');
    }

    // Parse the time in the user's actual browser timezone
    $userTime = new DateTime($scheduledTimeString, $tz);

    // Convert to UTC
    $userTime->setTimezone($utc);
    $result = $userTime->format('Y-m-d H:i:s');

    // Log for debugging
    logSmartPlus("Schedule conversion: Input: $scheduledTimeString ($userTimezone) -> UTC: $result");

    return $result;
}

// Legacy function - passes through timezone parameter
function convertESTtoUTC($estTimeString, $userTimezone = 'America/New_York') {
    return convertScheduledTimeToUTC($estTimeString, $userTimezone);
}

// Get fresh image URL by searching the image library
// This returns a fresh signed URL that won't be expired
function getFreshImageUrl($imageId, $advertiserId, $accessToken) {
    logSmartPlus("Getting fresh URL for image: $imageId");

    // Search for the image in the library to get fresh signed URL
    $result = makeApiCall('/file/image/ad/search/', [
        'advertiser_id' => $advertiserId,
        'page' => 1,
        'page_size' => 100
    ], $accessToken, 'GET');

    if ($result['code'] == 0 && isset($result['data']['list'])) {
        foreach ($result['data']['list'] as $image) {
            if ($image['image_id'] === $imageId) {
                logSmartPlus("Found fresh URL for image: " . $image['image_url']);
                return [
                    'success' => true,
                    'image_id' => $image['image_id'],
                    'image_url' => $image['image_url']
                ];
            }
        }
    }

    logSmartPlus("Image not found in library: $imageId");
    return ['success' => false, 'error' => 'Image not found'];
}

// Upload image by URL to get accessible image for Smart+ ads
function uploadImageByUrl($imageUrl, $advertiserId, $accessToken) {
    logSmartPlus("Uploading image by URL: $imageUrl");

    $result = makeApiCall('/file/image/ad/upload/', [
        'advertiser_id' => $advertiserId,
        'upload_type' => 'UPLOAD_BY_URL',
        'image_url' => $imageUrl
    ], $accessToken);

    if ($result['code'] == 0 && isset($result['data']['image_id'])) {
        logSmartPlus("Image uploaded: " . $result['data']['image_id']);
        return [
            'success' => true,
            'image_id' => $result['data']['image_id'],
            'image_url' => $result['data']['image_url'] ?? ''
        ];
    }

    logSmartPlus("Image upload failed: " . ($result['message'] ?? 'Unknown error'));
    return ['success' => false, 'error' => $result['message'] ?? 'Unknown error'];
}

// ==========================================
// OPTIMIZED BATCH FUNCTIONS - Reduce API calls to prevent timeouts
// ==========================================

// Global cache for image library (populated once, used for all videos)
$GLOBALS['imageLibraryCache'] = null;
$GLOBALS['videoInfoCache'] = [];

// Batch fetch video info for multiple videos in ONE API call
function batchGetVideoInfo($videoIds, $advertiserId, $accessToken) {
    if (empty($videoIds)) {
        return [];
    }

    logSmartPlus("BATCH: Fetching info for " . count($videoIds) . " videos in single call");

    $result = makeApiCall('/file/video/ad/info/', [
        'advertiser_id' => $advertiserId,
        'video_ids' => json_encode($videoIds)
    ], $accessToken, 'GET');

    $videoInfoMap = [];
    if ($result['code'] == 0 && !empty($result['data']['list'])) {
        foreach ($result['data']['list'] as $video) {
            $videoId = $video['video_id'] ?? null;
            if ($videoId) {
                $videoInfoMap[$videoId] = $video;
                $GLOBALS['videoInfoCache'][$videoId] = $video;
            }
        }
        logSmartPlus("BATCH: Got info for " . count($videoInfoMap) . " videos");
    } else {
        logSmartPlus("BATCH: Failed to get video info - code: " . ($result['code'] ?? 'null'));
    }

    return $videoInfoMap;
}

// Cache image library search results (ONE API call for entire session)
function getCachedImageLibrary($advertiserId, $accessToken) {
    if ($GLOBALS['imageLibraryCache'] !== null) {
        logSmartPlus("CACHE HIT: Using cached image library (" . count($GLOBALS['imageLibraryCache']) . " images)");
        return $GLOBALS['imageLibraryCache'];
    }

    logSmartPlus("CACHE MISS: Fetching image library (single call for all videos)");

    $result = makeApiCall('/file/image/ad/search/', [
        'advertiser_id' => $advertiserId,
        'page' => 1,
        'page_size' => 100
    ], $accessToken, 'GET');

    if ($result['code'] == 0 && !empty($result['data']['list'])) {
        $GLOBALS['imageLibraryCache'] = $result['data']['list'];
        logSmartPlus("CACHE: Stored " . count($GLOBALS['imageLibraryCache']) . " images in cache");
    } else {
        $GLOBALS['imageLibraryCache'] = [];
        logSmartPlus("CACHE: No images found or error");
    }

    return $GLOBALS['imageLibraryCache'];
}

// Find existing cover image by pattern in CACHED library (no API call)
function findExistingCoverInCache($coverFilePattern, $imageLibrary) {
    if (empty($coverFilePattern) || empty($imageLibrary)) {
        return null;
    }

    foreach ($imageLibrary as $image) {
        $fileName = $image['file_name'] ?? '';
        if (strpos($fileName, $coverFilePattern) === 0) {
            logSmartPlus("CACHE MATCH: Found existing cover by pattern: " . $image['image_id']);
            return $image['image_id'];
        }
    }

    return null;
}

// Extract cover file pattern from cover URL
function extractCoverPattern($coverUrl) {
    if (empty($coverUrl)) {
        return null;
    }
    if (preg_match('/\/([a-zA-Z0-9]+)~tplv/', $coverUrl, $matches)) {
        return $matches[1];
    }
    return null;
}

// OPTIMIZED: Get cover images for ALL videos with minimal API calls
// Instead of N×3 calls (per video: get_info + search + upload), we do:
// - 1 batch video info call
// - 1 image library search (cached)
// - Only upload if not found in cache
function batchGetVideoCoverImages($videoIds, $advertiserId, $accessToken) {
    logSmartPlus("=== OPTIMIZED BATCH: Processing " . count($videoIds) . " videos ===");

    $coverMap = []; // videoId => imageId

    // Step 1: Batch fetch ALL video info in ONE call
    $videoInfoMap = batchGetVideoInfo($videoIds, $advertiserId, $accessToken);

    // Step 2: Get cached image library (ONE call, reused)
    $imageLibrary = getCachedImageLibrary($advertiserId, $accessToken);

    // Step 3: For each video, try to find existing cover first
    $videosNeedingUpload = [];

    foreach ($videoIds as $videoId) {
        $videoInfo = $videoInfoMap[$videoId] ?? null;
        if (!$videoInfo) {
            logSmartPlus("WARNING: No info for video $videoId");
            continue;
        }

        $coverUrl = $videoInfo['video_cover_url'] ?? null;
        $coverPattern = extractCoverPattern($coverUrl);

        // Try to find existing cover in cache FIRST (no API call)
        if ($coverPattern) {
            $existingImageId = findExistingCoverInCache($coverPattern, $imageLibrary);
            if ($existingImageId) {
                $coverMap[$videoId] = $existingImageId;
                logSmartPlus("FOUND EXISTING: Video $videoId -> Image $existingImageId");
                continue;
            }
        }

        // Mark for upload if not found
        if ($coverUrl) {
            $videosNeedingUpload[$videoId] = $coverUrl;
        }
    }

    // Step 4: Upload only the covers that don't exist yet
    logSmartPlus("UPLOADS NEEDED: " . count($videosNeedingUpload) . " videos need cover upload");

    foreach ($videosNeedingUpload as $videoId => $coverUrl) {
        logSmartPlus("Uploading cover for video $videoId");
        $uploadResult = uploadImageByUrl($coverUrl, $advertiserId, $accessToken);

        if ($uploadResult['success']) {
            $coverMap[$videoId] = $uploadResult['image_id'];
            logSmartPlus("UPLOADED: Video $videoId -> Image " . $uploadResult['image_id']);
        } else {
            // If upload failed (duplicate), refresh cache and search again
            logSmartPlus("Upload failed for $videoId, refreshing cache and searching");
            $GLOBALS['imageLibraryCache'] = null; // Clear cache
            $imageLibrary = getCachedImageLibrary($advertiserId, $accessToken);
            $coverPattern = extractCoverPattern($coverUrl);

            $existingImageId = findExistingCoverInCache($coverPattern, $imageLibrary);
            if ($existingImageId) {
                $coverMap[$videoId] = $existingImageId;
                logSmartPlus("FOUND AFTER REFRESH: Video $videoId -> Image $existingImageId");
            }
        }
    }

    // Step 5: For any remaining videos without covers, use fallback
    foreach ($videoIds as $videoId) {
        if (!isset($coverMap[$videoId]) || empty($coverMap[$videoId])) {
            logSmartPlus("WARNING: No cover for video $videoId, using fallback from cache");
            $coverMap[$videoId] = findAnyValidImageFromCache($imageLibrary);

            // If cache fallback failed, try API-based fallback
            if (empty($coverMap[$videoId])) {
                logSmartPlus("WARNING: Cache fallback failed for $videoId, trying API fallback");
                $coverMap[$videoId] = findAnyValidImage($advertiserId, $accessToken);
            }

            // If still no cover, try to upload the video's cover URL directly as last resort
            if (empty($coverMap[$videoId])) {
                $videoInfo = $videoInfoMap[$videoId] ?? null;
                $coverUrl = $videoInfo['video_cover_url'] ?? null;
                if ($coverUrl) {
                    logSmartPlus("LAST RESORT: Attempting direct cover upload for $videoId");
                    $uploadResult = uploadImageByUrl($coverUrl, $advertiserId, $accessToken);
                    if ($uploadResult['success']) {
                        $coverMap[$videoId] = $uploadResult['image_id'];
                        logSmartPlus("LAST RESORT SUCCESS: Video $videoId -> Image " . $uploadResult['image_id']);
                    }
                }
            }
        }
    }

    logSmartPlus("=== BATCH COMPLETE: " . count($coverMap) . "/" . count($videoIds) . " videos have covers ===");
    return $coverMap;
}

// Find any valid image from cached library (no API call)
function findAnyValidImageFromCache($imageLibrary) {
    foreach ($imageLibrary as $image) {
        $imgWidth = $image['width'] ?? 0;
        $imgHeight = $image['height'] ?? 0;
        if ($imgWidth >= 540 && $imgHeight >= 540) {
            return $image['image_id'];
        }
    }
    // Return first image as last resort
    if (!empty($imageLibrary[0]['image_id'])) {
        return $imageLibrary[0]['image_id'];
    }
    return null;
}

// Legacy function - now uses optimized batch internally for single video
// Kept for backwards compatibility
function getVideoCoverImage($videoId, $advertiserId, $accessToken) {
    logSmartPlus("Getting cover image for video: $videoId (using optimized path)");

    // Check if already in cache
    if (isset($GLOBALS['videoInfoCache'][$videoId])) {
        $videoInfo = $GLOBALS['videoInfoCache'][$videoId];
    } else {
        // Single video - still use batch function for consistency
        $videoInfoMap = batchGetVideoInfo([$videoId], $advertiserId, $accessToken);
        $videoInfo = $videoInfoMap[$videoId] ?? null;
    }

    if (!$videoInfo) {
        logSmartPlus("Failed to get video info for: $videoId");
        return findAnyValidImage($advertiserId, $accessToken);
    }

    $coverUrl = $videoInfo['video_cover_url'] ?? null;
    $coverPattern = extractCoverPattern($coverUrl);

    // Get cached image library
    $imageLibrary = getCachedImageLibrary($advertiserId, $accessToken);

    // Try to find existing cover first
    if ($coverPattern) {
        $existingImageId = findExistingCoverInCache($coverPattern, $imageLibrary);
        if ($existingImageId) {
            return $existingImageId;
        }
    }

    // Upload if not found
    if (!empty($coverUrl)) {
        $uploadResult = uploadImageByUrl($coverUrl, $advertiserId, $accessToken);
        if ($uploadResult['success']) {
            return $uploadResult['image_id'];
        }

        // Refresh cache and try again
        $GLOBALS['imageLibraryCache'] = null;
        $imageLibrary = getCachedImageLibrary($advertiserId, $accessToken);

        if ($coverPattern) {
            $existingImageId = findExistingCoverInCache($coverPattern, $imageLibrary);
            if ($existingImageId) {
                return $existingImageId;
            }
        }
    }

    // Fallback
    logSmartPlus("WARNING: No unique cover found for video $videoId, using fallback");
    return findAnyValidImageFromCache($imageLibrary) ?? findAnyValidImage($advertiserId, $accessToken);
}

// Find any valid image in the library as a fallback
function findAnyValidImage($advertiserId, $accessToken) {
    $imagesResult = makeApiCall('/file/image/ad/search/', [
        'advertiser_id' => $advertiserId,
        'page' => 1,
        'page_size' => 50
    ], $accessToken, 'GET');

    if ($imagesResult['code'] == 0 && !empty($imagesResult['data']['list'])) {
        foreach ($imagesResult['data']['list'] as $image) {
            $imgWidth = $image['width'] ?? 0;
            $imgHeight = $image['height'] ?? 0;

            // Find any reasonably sized image
            if ($imgWidth >= 540 && $imgHeight >= 540) {
                logSmartPlus("Using fallback image (any valid): " . $image['image_id'] . " ({$imgWidth}x{$imgHeight})");
                return $image['image_id'];
            }
        }
        // If no large enough images, use the first one
        if (!empty($imagesResult['data']['list'][0]['image_id'])) {
            logSmartPlus("Using first available image as last resort: " . $imagesResult['data']['list'][0]['image_id']);
            return $imagesResult['data']['list'][0]['image_id'];
        }
    }

    logSmartPlus("CRITICAL: No valid image found in library!");
    return null;
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

    // Check for curl errors
    if ($response === false) {
        $curlError = curl_error($ch);
        curl_close($ch);
        logSmartPlus("CURL Error: " . $curlError);
        return [
            'code' => -1,
            'message' => 'Network error: ' . $curlError,
            'data' => null
        ];
    }

    curl_close($ch);

    $result = json_decode($response, true);

    // Check for JSON decode errors
    if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
        logSmartPlus("JSON decode error: " . json_last_error_msg());
        logSmartPlus("Raw response: " . substr($response, 0, 500));
        return [
            'code' => -1,
            'message' => 'Invalid JSON response from TikTok API',
            'data' => null
        ];
    }

    logSmartPlus("Response ($httpCode): " . json_encode($result));

    return $result ?? ['code' => -1, 'message' => 'Empty response', 'data' => null];
}

// Build call_to_action_list from portfolio content for Smart+ ad creation
// TikTok Smart+ API requires call_to_action_list at the top level (array of {call_to_action: "LEARN_MORE"})
// This is separate from call_to_action_id in ad_configuration (portfolio ID)
function buildCtaListFromPortfolio($portfolioId, $advertiserId) {
    if (empty($portfolioId)) return [];

    try {
        require_once __DIR__ . '/database/Database.php';
        $db = Database::getInstance();
        $portfolio = $db->fetchOne(
            "SELECT portfolio_content FROM tool_portfolios WHERE creative_portfolio_id = :pid AND advertiser_id = :aid",
            ['pid' => $portfolioId, 'aid' => $advertiserId]
        );

        if ($portfolio && !empty($portfolio['portfolio_content'])) {
            $content = json_decode($portfolio['portfolio_content'], true);
            if (is_array($content)) {
                $ctaList = [];
                foreach ($content as $item) {
                    if (!empty($item['asset_content'])) {
                        $ctaList[] = ['call_to_action' => $item['asset_content']];
                    }
                }
                // TikTok Smart+ API allows maxItems: 3 for call_to_action_list
                $ctaList = array_slice($ctaList, 0, 3);
                if (!empty($ctaList)) {
                    logSmartPlus("Built call_to_action_list from DB portfolio: " . json_encode($ctaList));
                    return $ctaList;
                }
            }
        }
    } catch (Exception $e) {
        logSmartPlus("Warning: Could not look up portfolio content from DB: " . $e->getMessage());
    }

    // Fallback: return default CTAs so ads always have CTA values
    // TikTok Smart+ API allows maxItems: 3 for call_to_action_list
    logSmartPlus("Using default call_to_action_list (portfolio content not found in DB)");
    return [
        ['call_to_action' => 'LEARN_MORE'],
        ['call_to_action' => 'SIGN_UP'],
        ['call_to_action' => 'CONTACT_US']
    ];
}

// Build call_to_action_list directly from portfolio content array (no DB lookup needed)
function buildCtaListFromContent($portfolioContent) {
    $ctaList = [];
    foreach ($portfolioContent as $item) {
        if (!empty($item['asset_content'])) {
            $ctaList[] = ['call_to_action' => $item['asset_content']];
        }
    }
    return $ctaList;
}

// Handle request
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// Check if advertiser_id is passed in the request (prevents cross-tab contamination)
// Frontend passes _advertiser_id to ensure correct context even if PHP session changed in another tab
if (!empty($input['_advertiser_id'])) {
    $requestedAdvertiserId = $input['_advertiser_id'];
    // Validate that this advertiser ID is in the user's authorized list
    $authorizedIds = $_SESSION['oauth_advertiser_ids'] ?? [];
    if (in_array($requestedAdvertiserId, $authorizedIds)) {
        $advertiserId = $requestedAdvertiserId;
        logSmartPlus("Using request advertiser ID: $advertiserId (overriding session)");
    } else {
        logSmartPlus("WARNING: Requested advertiser ID $requestedAdvertiserId not in authorized list, using session");
    }
}

logSmartPlus("=== Action: $action ===");

switch ($action) {

    // ==========================================
    // GET ADVERTISER TIMEZONE
    // Used to convert user's EST time to advertiser's account timezone
    // ==========================================
    case 'get_advertiser_timezone':
        logSmartPlus("=== GETTING ADVERTISER TIMEZONE ===");
        logSmartPlus("Advertiser ID: $advertiserId");

        $result = makeApiCall('/advertiser/info/', [
            'advertiser_id' => $advertiserId
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data'])) {
            $advertiserData = $result['data'];
            $timezone = $advertiserData['timezone'] ?? 'UTC';
            $timezoneOffset = 0;

            // Parse timezone to get offset
            // TikTok returns timezone in various formats, try to determine offset
            $tzMap = [
                'UTC' => 0,
                'America/New_York' => -5,
                'America/Chicago' => -6,
                'America/Denver' => -7,
                'America/Los_Angeles' => -8,
                'America/Bogota' => -5,
                'America/Lima' => -5,
                'America/Mexico_City' => -6,
                'Europe/London' => 0,
                'Europe/Paris' => 1,
                'Asia/Shanghai' => 8,
                'Asia/Tokyo' => 9,
            ];

            if (isset($tzMap[$timezone])) {
                $timezoneOffset = $tzMap[$timezone];
            } elseif (preg_match('/UTC([+-]\d+)/', $timezone, $matches)) {
                $timezoneOffset = intval($matches[1]);
            }

            logSmartPlus("Advertiser timezone: $timezone (offset: $timezoneOffset)");

            echo json_encode([
                'success' => true,
                'data' => [
                    'timezone' => $timezone,
                    'timezone_offset' => $timezoneOffset,
                    'advertiser_name' => $advertiserData['name'] ?? 'Unknown'
                ]
            ]);
        } else {
            logSmartPlus("Failed to get advertiser info: " . ($result['message'] ?? 'Unknown error'));
            echo json_encode([
                'success' => false,
                'message' => 'Failed to get advertiser timezone',
                'data' => [
                    'timezone' => 'UTC',
                    'timezone_offset' => 0
                ]
            ]);
        }
        break;

    // ==========================================
    // GET ACCOUNT BALANCE / FUND STATUS
    // ==========================================
    case 'get_account_balance':
        logSmartPlus("=== GET ACCOUNT BALANCE ===");
        logSmartPlus("Advertiser ID: $advertiserId");

        // Release session lock early — balance is read-only, no session writes needed
        // This allows parallel balance requests to execute concurrently
        session_write_close();

        $balanceFound = false;

        // Try /advertiser/fund/get/ first (works for prepay accounts)
        $result = makeApiCall('/advertiser/fund/get/', [
            'advertiser_id' => $advertiserId
        ], $accessToken, 'GET');

        logSmartPlus("Fund API Response: " . json_encode($result));

        // Extract fund data — handle nested response formats
        $fundData = null;
        if (isset($result['code']) && $result['code'] == 0 && isset($result['data'])) {
            $d = $result['data'];
            if (isset($d['balance'])) {
                $fundData = $d;
            } elseif (isset($d['list'][0]['balance'])) {
                $fundData = $d['list'][0];
            }
        }

        if ($fundData) {
            $balance = floatval($fundData['balance'] ?? 0);
            $grantBalance = floatval($fundData['grant_balance'] ?? 0);
            $totalBalance = $balance + $grantBalance;
            $currency = $fundData['currency'] ?? 'USD';
            $totalCost = floatval($fundData['total_cost'] ?? 0);

            echo json_encode([
                'success' => true,
                'data' => [
                    'balance' => $balance,
                    'grant_balance' => $grantBalance,
                    'total_balance' => $totalBalance,
                    'total_cost' => $totalCost,
                    'currency' => $currency,
                    'source' => 'fund'
                ]
            ]);
            $balanceFound = true;
        } else {
            $fundError = $result['message'] ?? 'unknown error';
            $fundCode = $result['code'] ?? -1;
            logSmartPlus("Fund API failed (code: $fundCode): $fundError — trying /advertiser/info/ fallback");
        }

        // Fallback: /advertiser/info/ (works for all account types)
        if (!$balanceFound) {
            // Use advertiser_ids (JSON array) — the documented parameter format
            $infoResult = makeApiCall('/advertiser/info/', [
                'advertiser_ids' => json_encode([$advertiserId])
            ], $accessToken, 'GET');

            logSmartPlus("Advertiser Info Fallback Response: " . json_encode($infoResult));

            // Extract advertiser data — handle all response formats:
            // Format 1: data.list[0] (documented format with advertiser_ids)
            // Format 2: data.advertiser_info (some API versions)
            // Format 3: data directly (when using advertiser_id singular)
            $advData = null;
            if (isset($infoResult['code']) && $infoResult['code'] == 0 && isset($infoResult['data'])) {
                $d = $infoResult['data'];
                if (isset($d['list'][0])) {
                    $advData = $d['list'][0];
                } elseif (isset($d['advertiser_info'])) {
                    $advData = $d['advertiser_info'];
                } elseif (isset($d['balance']) || isset($d['currency'])) {
                    $advData = $d;
                }
            }

            if ($advData) {
                $balance = floatval($advData['balance'] ?? 0);
                $currency = $advData['currency'] ?? 'USD';

                logSmartPlus("Info fallback success — balance: $balance, currency: $currency");

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'balance' => $balance,
                        'grant_balance' => 0,
                        'total_balance' => $balance,
                        'total_cost' => 0,
                        'currency' => $currency,
                        'source' => 'info'
                    ]
                ]);
            } else {
                $errorMessage = $infoResult['message'] ?? $result['message'] ?? 'Unable to fetch account balance';
                logSmartPlus("Both fund and info APIs failed: $errorMessage");
                logSmartPlus("Fund raw: " . json_encode($result));
                logSmartPlus("Info raw: " . json_encode($infoResult));

                echo json_encode([
                    'success' => false,
                    'message' => $errorMessage,
                    'debug' => [
                        'fund_code' => $result['code'] ?? null,
                        'fund_msg' => $result['message'] ?? null,
                        'info_code' => $infoResult['code'] ?? null,
                        'info_msg' => $infoResult['message'] ?? null
                    ]
                ]);
            }
        }
        break;

    // ==========================================
    // GET PIXELS (Cached - 10 min TTL)
    // ==========================================
    case 'get_pixels':
        $cacheKey = $cache->generateKey('pixels', $advertiserId);
        $forceRefresh = isset($input['force_refresh']) && $input['force_refresh'];

        // Check cache unless force refresh
        if (!$forceRefresh) {
            $cachedData = $cache->get($cacheKey);
            if ($cachedData !== null) {
                logSmartPlus("Cache HIT for pixels - Advertiser: $advertiserId");
                echo json_encode([
                    'success' => true,
                    'data' => ['pixels' => $cachedData],
                    'cached' => true
                ]);
                break;
            }
        } else {
            logSmartPlus("Force refresh pixels - clearing cache");
            $cache->delete($cacheKey);
        }

        logSmartPlus("Cache MISS for pixels - Advertiser: $advertiserId");

        // Note: /pixel/list/ endpoint doesn't support pagination parameters
        $result = makeApiCall('/pixel/list/', [
            'advertiser_id' => $advertiserId
        ], $accessToken, 'GET');

        logSmartPlus("Pixel response code: " . ($result['code'] ?? 'null'));

        if ($result['code'] == 0 && isset($result['data']['pixels'])) {
            $pixels = $result['data']['pixels'];
            logSmartPlus("Found " . count($pixels) . " pixels");

            // Cache the pixels for 10 minutes
            $cache->set($cacheKey, $pixels, Cache::TTL_LONG);
            echo json_encode([
                'success' => true,
                'data' => ['pixels' => $pixels],
                'total_count' => count($pixels)
            ]);
        } else {
            logSmartPlus("Pixel API error: " . json_encode($result));
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to get pixels'
            ]);
        }
        break;

    // ==========================================
    // GET IDENTITIES - All types with FULL PAGINATION (Cached - 10 min TTL)
    // ==========================================
    case 'get_identities':
        $cacheKey = $cache->generateKey('identities', $advertiserId);
        $forceRefresh = isset($input['force_refresh']) && $input['force_refresh'];

        // Check cache unless force refresh
        if (!$forceRefresh) {
            $cachedData = $cache->get($cacheKey);
            if ($cachedData !== null) {
                logSmartPlus("Cache HIT for identities - Advertiser: $advertiserId");
                // Handle both old format (array) and new format (object with identities/pages)
                if (isset($cachedData['identities'])) {
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'list' => $cachedData['identities'],
                            'identities' => $cachedData['identities'],
                            'pages' => $cachedData['pages'] ?? []
                        ],
                        'cached' => true
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'data' => ['list' => $cachedData, 'identities' => $cachedData, 'pages' => []],
                        'cached' => true
                    ]);
                }
                break;
            }
        } else {
            logSmartPlus("Force refresh identities - clearing cache");
            $cache->delete($cacheKey);
        }

        logSmartPlus("Cache MISS for identities - Advertiser: $advertiserId - fetching ALL pages");

        $customIdentities = [];
        $bcAuthIdentities = [];
        $ttUserIdentities = [];
        $pageSize = 100;

        // 1. Fetch ALL pages of CUSTOMIZED_USER identities
        $page = 1;
        do {
            $result = makeApiCall('/identity/get/', [
                'advertiser_id' => $advertiserId,
                'identity_type' => 'CUSTOMIZED_USER',
                'page' => $page,
                'page_size' => $pageSize
            ], $accessToken, 'GET');

            logSmartPlus("CUSTOMIZED_USER page $page response code: " . ($result['code'] ?? 'null'));

            if ($result['code'] == 0 && isset($result['data']['identity_list'])) {
                foreach ($result['data']['identity_list'] as $identity) {
                    $identity['identity_type'] = 'CUSTOMIZED_USER';
                    $identity['source_type'] = 'custom_identity';
                    $customIdentities[] = $identity;
                }
            }

            $totalPages = $result['data']['page_info']['total_page'] ?? 1;
            $page++;
        } while ($page <= $totalPages && $page <= 20); // Safety limit 20 pages

        logSmartPlus("Found " . count($customIdentities) . " CUSTOMIZED_USER identities");

        // 2. Fetch ALL Business Centers first, then fetch BC_AUTH_TT identities for each
        logSmartPlus("Fetching Business Centers for BC_AUTH_TT identities...");

        $businessCenters = [];
        $bcResult = makeApiCall('/bc/get/', [], $accessToken, 'GET');

        if ($bcResult['code'] == 0 && isset($bcResult['data']['list'])) {
            $businessCenters = $bcResult['data']['list'];
            logSmartPlus("Found " . count($businessCenters) . " Business Centers: " . json_encode(array_column($businessCenters, 'bc_id')));
        } else {
            logSmartPlus("No Business Centers found or API error: " . json_encode($bcResult));
        }

        // 2a. First try fetching BC_AUTH_TT without specific BC ID (gets all accessible)
        $page = 1;
        do {
            $result = makeApiCall('/identity/get/', [
                'advertiser_id' => $advertiserId,
                'identity_type' => 'BC_AUTH_TT',
                'page' => $page,
                'page_size' => $pageSize
            ], $accessToken, 'GET');

            logSmartPlus("BC_AUTH_TT (general) page $page response code: " . ($result['code'] ?? 'null'));

            if ($result['code'] == 0 && isset($result['data']['identity_list'])) {
                foreach ($result['data']['identity_list'] as $identity) {
                    // Log full identity structure on first one for debugging
                    if (count($bcAuthIdentities) === 0) {
                        logSmartPlus("BC_AUTH_TT sample identity structure: " . json_encode($identity));
                    }
                    $identity['identity_type'] = 'BC_AUTH_TT';
                    $identity['source_type'] = 'bc_auth_tt';
                    // Log if identity_authorized_bc_id is present
                    if (isset($identity['identity_authorized_bc_id'])) {
                        logSmartPlus("BC_AUTH_TT identity has identity_authorized_bc_id: " . $identity['identity_authorized_bc_id']);
                    } else {
                        logSmartPlus("WARNING: BC_AUTH_TT identity missing identity_authorized_bc_id - identity_id: " . ($identity['identity_id'] ?? 'unknown'));
                    }
                    $bcAuthIdentities[] = $identity;
                }
            } else if ($result['code'] != 0) {
                logSmartPlus("BC_AUTH_TT API error: " . json_encode($result));
            }

            $totalPages = $result['data']['page_info']['total_page'] ?? 1;
            $page++;
        } while ($page <= $totalPages && $page <= 20);

        logSmartPlus("Found " . count($bcAuthIdentities) . " BC_AUTH_TT identities (general query)");

        // 2b. Also fetch BC_AUTH_TT identities for each specific Business Center
        // This ensures we get identities that might only be accessible via specific BC
        $existingIdentityIds = array_column($bcAuthIdentities, 'identity_id');

        foreach ($businessCenters as $bc) {
            $bcId = $bc['bc_id'] ?? null;
            if (!$bcId) continue;

            logSmartPlus("Fetching BC_AUTH_TT identities for BC: $bcId");
            $page = 1;
            do {
                $result = makeApiCall('/identity/get/', [
                    'advertiser_id' => $advertiserId,
                    'identity_type' => 'BC_AUTH_TT',
                    'identity_authorized_bc_id' => $bcId,
                    'page' => $page,
                    'page_size' => $pageSize
                ], $accessToken, 'GET');

                logSmartPlus("BC_AUTH_TT (BC: $bcId) page $page response code: " . ($result['code'] ?? 'null'));

                if ($result['code'] == 0 && isset($result['data']['identity_list'])) {
                    foreach ($result['data']['identity_list'] as $identity) {
                        // Skip if already added from general query
                        if (in_array($identity['identity_id'], $existingIdentityIds)) {
                            continue;
                        }
                        $identity['identity_type'] = 'BC_AUTH_TT';
                        $identity['source_type'] = 'bc_auth_tt';
                        // Ensure BC ID is set
                        if (!isset($identity['identity_authorized_bc_id'])) {
                            $identity['identity_authorized_bc_id'] = $bcId;
                        }
                        logSmartPlus("Added BC_AUTH_TT identity from BC $bcId: " . ($identity['identity_id'] ?? 'unknown'));
                        $bcAuthIdentities[] = $identity;
                        $existingIdentityIds[] = $identity['identity_id'];
                    }
                }

                $totalPages = $result['data']['page_info']['total_page'] ?? 1;
                $page++;
            } while ($page <= $totalPages && $page <= 10); // Lower limit per BC
        }

        logSmartPlus("Total BC_AUTH_TT identities after BC-specific queries: " . count($bcAuthIdentities));

        // 3. Fetch ALL pages of TT_USER identities (direct TikTok accounts)
        $page = 1;
        do {
            $result = makeApiCall('/identity/get/', [
                'advertiser_id' => $advertiserId,
                'identity_type' => 'TT_USER',
                'page' => $page,
                'page_size' => $pageSize
            ], $accessToken, 'GET');

            logSmartPlus("TT_USER page $page response code: " . ($result['code'] ?? 'null'));

            if ($result['code'] == 0 && isset($result['data']['identity_list'])) {
                foreach ($result['data']['identity_list'] as $identity) {
                    $identity['identity_type'] = 'TT_USER';
                    $identity['source_type'] = 'tt_user';
                    $ttUserIdentities[] = $identity;
                }
            }

            $totalPages = $result['data']['page_info']['total_page'] ?? 1;
            $page++;
        } while ($page <= $totalPages && $page <= 20);

        logSmartPlus("Found " . count($ttUserIdentities) . " TT_USER identities");

        // Combine custom + TT_USER for main list, BC_AUTH_TT as pages (TikTok Pages)
        $allIdentities = array_merge($customIdentities, $ttUserIdentities);

        // Cache combined data
        $combinedData = [
            'identities' => $allIdentities,
            'pages' => $bcAuthIdentities
        ];
        $cache->set($cacheKey, $combinedData, Cache::TTL_LONG);

        $totalCount = count($allIdentities) + count($bcAuthIdentities);
        logSmartPlus("Total identities found: $totalCount (custom+tt_user: " . count($allIdentities) . ", bc_auth: " . count($bcAuthIdentities) . ")");

        echo json_encode([
            'success' => true,
            'data' => [
                'list' => $allIdentities,
                'identities' => $allIdentities,
                'pages' => $bcAuthIdentities
            ],
            'total_count' => $totalCount
        ]);
        break;

    // ==========================================
    // GET VIDEOS FROM CREATIVE LIBRARY (Cached - 5 min TTL)
    // ==========================================
    case 'get_videos':
        $cacheKey = $cache->generateKey('videos', $advertiserId);
        $forceRefresh = isset($input['force_refresh']) && $input['force_refresh'];

        // Check cache unless force_refresh is true
        if (!$forceRefresh) {
            $cachedData = $cache->get($cacheKey);
            if ($cachedData !== null) {
                logSmartPlus("Cache HIT for videos - Advertiser: $advertiserId");
                echo json_encode([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true
                ]);
                break;
            }
        } else {
            logSmartPlus("Force refresh for videos - Advertiser: $advertiserId");
            $cache->delete($cacheKey);  // Clear existing cache
        }

        logSmartPlus("Cache MISS for videos - Advertiser: $advertiserId");
        $result = makeApiCall('/file/video/ad/search/', [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 100
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['list'])) {
            $videos = $result['data']['list'];

            // Sort videos by create_time descending (latest first)
            usort($videos, function($a, $b) {
                $timeA = strtotime($a['create_time'] ?? '0');
                $timeB = strtotime($b['create_time'] ?? '0');
                return $timeB - $timeA; // Descending order (newest first)
            });

            // Cache sorted videos for 5 minutes (shorter TTL as videos may be added)
            $cache->set($cacheKey, $videos, Cache::TTL_MEDIUM);
            echo json_encode([
                'success' => true,
                'data' => $videos
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to get videos'
            ]);
        }
        break;

    // ==========================================
    // GET VIDEO DOWNLOAD URL (for Use Original Video feature)
    // ==========================================
    case 'get_video_download_url':
        $videoId = $input['video_id'] ?? null;
        $sourceAdvertiserId = $input['_advertiser_id'] ?? $advertiserId;

        if (!$videoId) {
            echo json_encode(['success' => false, 'message' => 'Missing video_id']);
            break;
        }

        logSmartPlus("Getting video download URL for video: $videoId from advertiser: $sourceAdvertiserId");

        $result = makeApiCall('/file/video/ad/info/', [
            'advertiser_id' => $sourceAdvertiserId,
            'video_ids' => json_encode([$videoId])
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && !empty($result['data']['list'][0])) {
            $videoInfo = $result['data']['list'][0];
            $videoUrl = $videoInfo['video_url'] ?? $videoInfo['preview_url'] ?? null;

            if ($videoUrl) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'video_url' => $videoUrl,
                        'video_id' => $videoInfo['video_id'] ?? $videoId,
                        'file_name' => $videoInfo['file_name'] ?? '',
                        'duration' => $videoInfo['duration'] ?? 0,
                        'width' => $videoInfo['width'] ?? 0,
                        'height' => $videoInfo['height'] ?? 0
                    ]
                ]);
            } else {
                logSmartPlus("Video info returned but no URL found: " . json_encode($videoInfo));
                echo json_encode([
                    'success' => false,
                    'message' => 'Video URL not available from API',
                    'data' => ['video_info' => $videoInfo]
                ]);
            }
        } else {
            logSmartPlus("Failed to get video info: " . json_encode($result));
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to get video info'
            ]);
        }
        break;

    // ==========================================
    // GET IMAGES FROM CREATIVE LIBRARY (Cached - 5 min TTL)
    // ==========================================
    case 'get_images':
        $cacheKey = $cache->generateKey('images', $advertiserId);
        $forceRefresh = isset($input['force_refresh']) && $input['force_refresh'];

        // Check cache unless force_refresh is true
        if (!$forceRefresh) {
            $cachedData = $cache->get($cacheKey);
            if ($cachedData !== null) {
                logSmartPlus("Cache HIT for images - Advertiser: $advertiserId");
                echo json_encode([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true
                ]);
                break;
            }
        } else {
            logSmartPlus("Force refresh for images - Advertiser: $advertiserId");
            $cache->delete($cacheKey);
        }

        logSmartPlus("Cache MISS for images - Advertiser: $advertiserId");
        $result = makeApiCall('/file/image/ad/search/', [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 100
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['list'])) {
            // Cache images for 5 minutes
            $cache->set($cacheKey, $result['data']['list'], Cache::TTL_MEDIUM);
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
    // Creates campaign as DISABLED by default for safety
    // ==========================================
    case 'create_smartplus_campaign':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ CAMPAIGN ===");

        if (empty($data['campaign_name'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign name is required']);
            exit;
        }

        // Smart+ Lead Generation Campaign - exact parameters from TikTok docs
        // Create as DISABLED by default - user must explicitly enable
        // Smart+ campaigns REQUIRE: BUDGET_MODE_DYNAMIC_DAILY_BUDGET + budget_optimize_on at campaign level
        // AdGroup will use BUDGET_MODE_INFINITE (no adgroup budget)
        $budget = floatval($data['budget'] ?? 50);
        if ($budget < 20) $budget = 50;  // Minimum $20, default $50

        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION',
            'request_id' => generateRequestId(),
            'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',  // Required for Smart+ campaigns
            'budget' => $budget,
            'budget_optimize_on' => true,  // CBO enabled - TikTok optimizes across ad groups
            'operation_status' => 'DISABLE'  // Create as DISABLED - safer default
        ];

        logSmartPlus("Campaign params: " . json_encode($campaignParams));

        $result = makeApiCall('/smart_plus/campaign/create/', $campaignParams, $accessToken);
        logSmartPlus("Campaign API response: " . json_encode($result));

        if ($result['code'] == 0 && isset($result['data']['campaign_id'])) {
            echo json_encode([
                'success' => true,
                'campaign_id' => $result['data']['campaign_id'],
                'message' => 'Smart+ Campaign created successfully (disabled)',
                'operation_status' => 'DISABLE'
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

        // Schedule handling - support both continuous and scheduled options
        // IMPORTANT: TikTok API requires schedule_start_time in UTC+0 format
        // schedule_start_time is ALWAYS required - cannot be omitted
        $scheduleType = $data['schedule_type'] ?? 'SCHEDULE_FROM_NOW';
        $scheduleStart = null;
        $scheduleEnd = null;
        $userTimezone = $data['user_timezone'] ?? 'America/New_York';
        $timesAlreadyUTC = !empty($data['times_already_utc']); // For duplicating: times from TikTok API are already UTC

        logSmartPlus("Schedule: user_timezone=$userTimezone, timesAlreadyUTC=" . ($timesAlreadyUTC ? 'true' : 'false'));

        if ($scheduleType === 'SCHEDULE_START_END' && !empty($data['schedule_start_time']) && !empty($data['schedule_end_time'])) {
            if ($timesAlreadyUTC) {
                // Times from TikTok API are already in UTC - use directly
                $scheduleStart = $data['schedule_start_time'];
                $scheduleEnd = $data['schedule_end_time'];
                logSmartPlus("Using SCHEDULE_START_END (already UTC): $scheduleStart to $scheduleEnd");

                // If start time is in the past, adjust to now+5min (for duplicate campaigns)
                $nowUTC = new DateTime('now', new DateTimeZone('UTC'));
                $startUTC = new DateTime($scheduleStart, new DateTimeZone('UTC'));
                if ($startUTC < $nowUTC) {
                    $scheduleStart = getUTCDateTime('+5 minutes');
                    logSmartPlus("Original start time was in the past, adjusted to: $scheduleStart");
                }
            } else {
                // User specified start and end times — convert from user's browser timezone to UTC
                $scheduleStart = convertESTtoUTC($data['schedule_start_time'], $userTimezone);
                $scheduleEnd = convertESTtoUTC($data['schedule_end_time'], $userTimezone);
                logSmartPlus("Using SCHEDULE_START_END: $scheduleStart to $scheduleEnd (UTC)");
            }
        } elseif ($scheduleType === 'SCHEDULE_FROM_NOW' && !empty($data['schedule_start_time'])) {
            if ($timesAlreadyUTC) {
                $scheduleStart = $data['schedule_start_time'];
                logSmartPlus("Using SCHEDULE_FROM_NOW (already UTC) with start: $scheduleStart");
                // If start time is in the past, adjust to now+5min
                $nowUTC = new DateTime('now', new DateTimeZone('UTC'));
                $startUTC = new DateTime($scheduleStart, new DateTimeZone('UTC'));
                if ($startUTC < $nowUTC) {
                    $scheduleStart = getUTCDateTime('+5 minutes');
                    logSmartPlus("Original start time was in the past, adjusted to: $scheduleStart");
                }
            } else {
                // User specified a future start time — convert from user's browser timezone to UTC
                $scheduleStart = convertESTtoUTC($data['schedule_start_time'], $userTimezone);
                logSmartPlus("Using SCHEDULE_FROM_NOW with scheduled start: $scheduleStart (UTC)");
            }
        } else {
            // Default: Run continuously from now (start immediately)
            // TikTok API REQUIRES schedule_start_time to be in the future
            // Add 5-minute buffer so the time is still in the future when TikTok validates it
            $scheduleType = 'SCHEDULE_FROM_NOW';
            $scheduleStart = getUTCDateTime('+5 minutes');
            logSmartPlus("Using SCHEDULE_FROM_NOW - starting with UTC time (now+5min): $scheduleStart");
        }

        $adgroupParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_id' => $data['campaign_id'],
            'adgroup_name' => $data['adgroup_name'] ?? $data['campaign_name'] . ' Ad Group',
            'promotion_type' => 'LEAD_GENERATION',
            'promotion_target_type' => 'EXTERNAL_WEBSITE',  // Required for Website destination (not Instant Form)
            'optimization_goal' => 'CONVERT',  // Use CONVERT for Lead Gen with External Website
            'billing_event' => 'OCPM',
            'bid_type' => 'BID_TYPE_NO_BID',  // Lowest Cost strategy - no target CPA required
            'schedule_type' => $scheduleType,
            'operation_status' => 'ENABLE',
            'targeting_spec' => [
                'location_ids' => $data['location_ids'] ?? ['6252001'],
                'age_groups' => $data['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100']
            ]
        ];

        // schedule_start_time is REQUIRED - always add it (in UTC format)
        $adgroupParams['schedule_start_time'] = $scheduleStart;

        // Add schedule_end_time only for SCHEDULE_START_END type
        if ($scheduleType === 'SCHEDULE_START_END' && $scheduleEnd) {
            $adgroupParams['schedule_end_time'] = $scheduleEnd;
        }

        // Add pixel if provided
        if (!empty($data['pixel_id'])) {
            $adgroupParams['pixel_id'] = $data['pixel_id'];
            $adgroupParams['optimization_event'] = $data['optimization_event'] ?? 'FORM';
        }

        // Note: Identity is set at AD level for Smart+, not at adgroup level

        // For Smart+ campaigns with CBO (budget_optimize_on=true at campaign level):
        // AdGroup should use BUDGET_MODE_INFINITE with no budget
        // Budget is managed at campaign level, TikTok optimizes across ad groups
        $adgroupParams['budget_mode'] = 'BUDGET_MODE_INFINITE';
        logSmartPlus("Using BUDGET_MODE_INFINITE at AdGroup level (budget set at Campaign level)");

        // Optional: Add Target CPA only if provided and using Cost Cap strategy
        // If user provides a target CPA, switch to BID_TYPE_CUSTOM (Cost Cap)
        if (!empty($data['conversion_bid_price'])) {
            $adgroupParams['bid_type'] = 'BID_TYPE_CUSTOM';  // Cost Cap - requires target CPA
            $adgroupParams['conversion_bid_price'] = floatval($data['conversion_bid_price']);
            logSmartPlus("Using Cost Cap with target CPA: " . $data['conversion_bid_price']);
        }

        // Add dayparting if provided
        if (!empty($data['dayparting'])) {
            $adgroupParams['dayparting'] = $data['dayparting'];
        }

        logSmartPlus("AdGroup params: " . json_encode($adgroupParams));

        $result = makeApiCall('/smart_plus/adgroup/create/', $adgroupParams, $accessToken);
        logSmartPlus("AdGroup API response: " . json_encode($result));

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
    // Format: media_info_list = [{media_info: {video_info: {video_id}}}]
    // ==========================================
    case 'create_smartplus_ad':
        $data = $input;

        logSmartPlus("=== CREATING SMART+ AD (OPTIMIZED) ===");

        // Log incoming identity info FIRST for debugging
        logSmartPlus("=== IDENTITY INFO RECEIVED FROM FRONTEND ===");
        logSmartPlus("identity_id: " . ($data['identity_id'] ?? 'NULL'));
        logSmartPlus("identity_type: " . ($data['identity_type'] ?? 'NOT SET'));
        logSmartPlus("identity_authorized_bc_id: " . ($data['identity_authorized_bc_id'] ?? 'NULL/NOT PROVIDED'));

        if (empty($data['adgroup_id'])) {
            echo json_encode(['success' => false, 'message' => 'Ad Group ID is required']);
            exit;
        }

        // Log incoming creatives to verify uniqueness
        logSmartPlus("Incoming creatives from frontend: " . json_encode($data['creatives'] ?? []));

        // OPTIMIZED: Collect all video IDs first, then batch fetch covers
        $videoIds = [];
        $videoIdToCreative = [];
        foreach ($data['creatives'] ?? [] as $index => $creative) {
            if (!empty($creative['video_id'])) {
                $videoId = $creative['video_id'];
                // Reject processing_* temporary IDs — video not yet available in TikTok
                if (strpos($videoId, 'processing_') === 0) {
                    logSmartPlus("REJECTED: Video still processing (temporary ID): $videoId");
                    echo json_encode([
                        'success' => false,
                        'message' => 'Video "' . ($creative['name'] ?? $videoId) . '" is still processing. Please wait a moment and refresh your video library before launching.',
                        'error_code' => 'VIDEO_STILL_PROCESSING'
                    ]);
                    exit;
                }
                // Only add if no image_id provided (needs auto-fetch)
                if (empty($creative['image_id'])) {
                    $videoIds[] = $videoId;
                }
                $videoIdToCreative[$videoId] = $creative;
            }
        }

        // OPTIMIZED: Batch fetch ALL cover images in minimal API calls
        $coverMap = [];
        if (!empty($videoIds)) {
            logSmartPlus("OPTIMIZED: Batch fetching covers for " . count($videoIds) . " videos");
            $coverMap = batchGetVideoCoverImages($videoIds, $advertiserId, $accessToken);
        }

        // Build creative_list with proper format for Smart+ Ads
        // Format: creative_list = [{creative_info: {video_info: {video_id}, image_info: [{web_uri}], ad_format}}]
        // For BC_AUTH_TT identities, identity info goes INSIDE each creative_info (not in ad_configuration)
        $creativeList = [];

        // Get identity info for BC_AUTH_TT (needs to be added to each creative)
        $identityId = $data['identity_id'] ?? null;
        $identityType = $data['identity_type'] ?? 'CUSTOMIZED_USER';
        $identityAuthorizedBcId = $data['identity_authorized_bc_id'] ?? null;

        // Additional check: if BC_AUTH_TT but no bc_id, log a critical warning
        if ($identityType === 'BC_AUTH_TT' && empty($identityAuthorizedBcId)) {
            logSmartPlus("!!! CRITICAL WARNING: BC_AUTH_TT identity type without identity_authorized_bc_id !!!");
            logSmartPlus("This is REQUIRED by TikTok API. The ad creation will likely fail with:");
            logSmartPlus("'You no longer have access to the TikTok account used in this ad'");
        }

        foreach ($data['creatives'] ?? [] as $index => $creative) {
            logSmartPlus("Processing creative $index: video_id=" . ($creative['video_id'] ?? 'null'));
            if (!empty($creative['video_id'])) {
                $videoId = $creative['video_id'];
                $creativeInfo = [
                    'video_info' => [
                        'video_id' => $videoId
                    ],
                    'ad_format' => 'SINGLE_VIDEO'
                ];

                // For BC_AUTH_TT, add identity info to each creative_info
                // CRITICAL: BC_AUTH_TT REQUIRES identity_bc_id (or identity_authorized_bc_id) according to TikTok API
                // TikTok error says 'identity_bc_id' is required, so we use that parameter name
                if ($identityType === 'BC_AUTH_TT' && !empty($identityId)) {
                    $creativeInfo['identity_id'] = $identityId;
                    $creativeInfo['identity_type'] = 'BC_AUTH_TT';
                    if (!empty($identityAuthorizedBcId)) {
                        // TikTok API expects 'identity_bc_id' for Smart+ ads (based on error message)
                        $creativeInfo['identity_bc_id'] = $identityAuthorizedBcId;
                        // Also include identity_authorized_bc_id for compatibility with other endpoints
                        $creativeInfo['identity_authorized_bc_id'] = $identityAuthorizedBcId;
                        logSmartPlus("Added BC_AUTH_TT identity to creative: identity_id=$identityId, identity_bc_id=$identityAuthorizedBcId");
                    } else {
                        // WARNING: Missing BC ID - this will likely cause TikTok API error
                        logSmartPlus("WARNING: BC_AUTH_TT identity missing identity_bc_id! identity_id=$identityId");
                        logSmartPlus("This is REQUIRED by TikTok API - ad creation will likely fail.");
                    }
                }

                // Get cover image - use provided, or from batch result
                $imageId = $creative['image_id'] ?? $coverMap[$videoId] ?? null;

                // If still no image_id, try to fetch it directly for this video (duplication scenario)
                if (empty($imageId)) {
                    logSmartPlus("No cached cover for video $videoId, attempting direct fetch for duplication");
                    $singleCoverMap = batchGetVideoCoverImages([$videoId], $advertiserId, $accessToken);
                    $imageId = $singleCoverMap[$videoId] ?? null;
                }

                if (!empty($imageId)) {
                    // Per TikTok SDK docs: image_info only requires web_uri (not image_id)
                    $creativeInfo['image_info'] = [[
                        'web_uri' => $imageId
                    ]];
                    logSmartPlus("Added image_info for video $videoId: web_uri=$imageId");
                } else {
                    // CRITICAL: Smart+ Ads require image_info for video covers
                    logSmartPlus("CRITICAL ERROR: No cover image found for video: $videoId");
                    echo json_encode([
                        'success' => false,
                        'message' => 'Failed to find or create video cover image for video: ' . $videoId . '. Please upload an image to your media library first.',
                        'error_code' => 'NO_COVER_IMAGE'
                    ]);
                    exit;
                }

                $creativeList[] = ['creative_info' => $creativeInfo];
                logSmartPlus("Added to creativeList: video_id=$videoId");
            }
        }

        // Log final creative list to verify all videos are unique
        logSmartPlus("Final creative_list count: " . count($creativeList));
        foreach ($creativeList as $idx => $item) {
            $vid = $item['creative_info']['video_info']['video_id'] ?? 'unknown';
            logSmartPlus("Final creative $idx: video_id=$vid");
        }

        // Build landing_page_urls OR page_list depending on what's provided
        // landing_page_url = Website destination
        // page_id = Instant Form (Lead Gen)
        $landingPageList = [];
        $pageList = [];
        $hasDestination = false;

        if (!empty($data['landing_page_url'])) {
            $landingPageList[] = ['landing_page_url' => $data['landing_page_url']];
            $hasDestination = true;
            logSmartPlus("Using landing_page_url: " . $data['landing_page_url']);
        }

        // Check for page_id (Instant Form for Lead Gen)
        if (!empty($data['page_id'])) {
            $pageList[] = ['page_id' => $data['page_id']];
            $hasDestination = true;
            logSmartPlus("Using page_id (Instant Form): " . $data['page_id']);
        }

        // If neither landing_page_url nor page_id provided, this is an error
        if (!$hasDestination) {
            logSmartPlus("ERROR: No destination provided (neither landing_page_url nor page_id)");
            echo json_encode([
                'success' => false,
                'message' => 'Please enter the landing page URL or select an Instant Form.',
                'error_code' => 'NO_DESTINATION'
            ]);
            exit;
        }

        // For Lead Gen Smart+ Ads: use call_to_action_id (Dynamic CTA Portfolio) in ad_configuration
        // AND call_to_action_list at top level with actual CTA values
        $callToActionId = $data['call_to_action_id'] ?? null;

        if (empty($callToActionId)) {
            logSmartPlus("ERROR: call_to_action_id (portfolio ID) is required for Lead Gen Smart+ Ads");
            echo json_encode([
                'success' => false,
                'message' => 'Dynamic CTA Portfolio is required for Lead Generation ads. Please select or create a CTA portfolio.',
                'error_code' => 'CTA_PORTFOLIO_REQUIRED'
            ]);
            exit;
        }

        logSmartPlus("Using call_to_action_id (portfolio): $callToActionId");

        // Build ad_text_list - DEDUPLICATE to avoid "duplicate titles" error
        // Option 1: Use ad_texts array if provided (from new UI with single text field)
        // Option 2: Extract unique texts from creatives (fallback for compatibility)
        $adTextList = [];
        $uniqueTexts = []; // Track unique texts to avoid duplicates

        if (!empty($data['ad_texts']) && is_array($data['ad_texts'])) {
            // New approach: Use ad_texts array directly from frontend
            logSmartPlus("Using ad_texts array from frontend: " . json_encode($data['ad_texts']));
            foreach ($data['ad_texts'] as $text) {
                $text = trim($text);
                if (!empty($text) && !in_array($text, $uniqueTexts)) {
                    $uniqueTexts[] = $text;
                    $adTextList[] = ['ad_text' => $text];
                }
            }
        } else {
            // Fallback: Extract from creatives but DEDUPLICATE
            logSmartPlus("Extracting ad texts from creatives (with deduplication)");
            foreach ($data['creatives'] ?? [] as $creative) {
                if (!empty($creative['ad_text'])) {
                    $text = trim($creative['ad_text']);
                    if (!in_array($text, $uniqueTexts)) {
                        $uniqueTexts[] = $text;
                        $adTextList[] = ['ad_text' => $text];
                    }
                }
            }
        }

        // Ensure at least one ad_text
        if (empty($adTextList)) {
            $adTextList[] = ['ad_text' => 'Check it out!'];
        }

        logSmartPlus("Final ad_text_list (deduplicated): " . json_encode($adTextList));

        $adParams = [
            'advertiser_id' => $advertiserId,
            'adgroup_id' => $data['adgroup_id'],
            'ad_name' => $data['ad_name'] ?? 'Smart+ Ad',
            'creative_list' => $creativeList,
            'ad_text_list' => $adTextList
        ];

        // Add landing page or instant form destination
        if (!empty($landingPageList)) {
            $adParams['landing_page_url_list'] = $landingPageList;
        }
        if (!empty($pageList)) {
            $adParams['page_list'] = $pageList;
        }

        // Build ad_configuration - per SDK docs, call_to_action_id belongs INSIDE ad_configuration
        $adConfig = [
            'call_to_action_id' => $callToActionId  // Dynamic CTA Portfolio ID goes here
        ];

        // Add identity configuration to ad_configuration ONLY for CUSTOMIZED_USER
        // For BC_AUTH_TT, identity info goes in each creative_info (already added above)
        if (!empty($data['identity_id']) && $identityType === 'CUSTOMIZED_USER') {
            $adConfig['identity_id'] = $data['identity_id'];
            $adConfig['identity_type'] = 'CUSTOMIZED_USER';
            logSmartPlus("Using CUSTOMIZED_USER identity in ad_configuration");
        } elseif ($identityType === 'BC_AUTH_TT') {
            // BC_AUTH_TT identity is already added to each creative_info
            logSmartPlus("BC_AUTH_TT identity added to creative_info (not ad_configuration)");
        }

        $adParams['ad_configuration'] = $adConfig;

        // Per TikTok docs: when using call_to_action_id (portfolio), call_to_action_list should NOT be passed.
        // Docs say: call_to_action_id = "Specify a valid value", call_to_action_list = "Not passed"
        logSmartPlus("Using call_to_action_id in ad_configuration (portfolio: $callToActionId). NOT sending call_to_action_list per TikTok API docs.");

        logSmartPlus("Ad params: " . json_encode($adParams));
        logSmartPlus("=== SENDING TO TIKTOK API ===");
        logSmartPlus("Number of videos in creative_list: " . count($creativeList));
        logSmartPlus("Number of ad texts in ad_text_list: " . count($adTextList));

        $result = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

        // Log FULL TikTok response for debugging
        logSmartPlus("=== TIKTOK API RESPONSE ===");
        logSmartPlus("Full response: " . json_encode($result, JSON_PRETTY_PRINT));

        if ($result['code'] == 0 && isset($result['data']['smart_plus_ad_id'])) {
            logSmartPlus("SUCCESS: Smart+ Ad created with ID: " . $result['data']['smart_plus_ad_id']);
            logSmartPlus("Videos submitted: " . count($creativeList) . ", Ad texts: " . count($adTextList));
            echo json_encode([
                'success' => true,
                'smart_plus_ad_id' => $result['data']['smart_plus_ad_id'],
                'message' => 'Smart+ Ad created successfully',
                'videos_count' => count($creativeList),
                'texts_count' => count($adTextList)
            ]);
        } else {
            logSmartPlus("ERROR: Failed to create ad");
            logSmartPlus("Error code: " . ($result['code'] ?? 'unknown'));
            logSmartPlus("Error message: " . ($result['message'] ?? 'unknown'));
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
        // Smart+ Lead Generation Campaign - exact parameters from TikTok docs
        // Create as DISABLED by default - safer, user can enable when ready
        logSmartPlus("Step 1: Creating Campaign (as DISABLED)...");

        // Smart+ campaigns REQUIRE: BUDGET_MODE_DYNAMIC_DAILY_BUDGET + budget_optimize_on at campaign level
        // AdGroup will use BUDGET_MODE_INFINITE (no adgroup budget)
        $budget = floatval($data['budget'] ?? 50);
        if ($budget < 20) $budget = 50;  // Minimum $20, default $50

        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION',
            'request_id' => generateRequestId(),
            'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',  // Required for Smart+ campaigns
            'budget' => $budget,
            'budget_optimize_on' => true,  // CBO enabled - TikTok optimizes across ad groups
            'operation_status' => 'DISABLE'  // Create as DISABLED - safer default
        ];

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
        // NOTE: Identity is NOT set at ad group level for Smart+
        // Identity is set at AD level
        logSmartPlus("Step 2: Creating Ad Group...");
        // TikTok API requires UTC+0 format for schedule times
        $scheduleStart = getUTCDateTime('+5 minutes');  // Buffer so time is future when TikTok validates

        $adgroupParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_id' => $campaignId,
            'adgroup_name' => $data['campaign_name'] . ' - Ad Group',
            'promotion_type' => 'LEAD_GENERATION',
            'promotion_target_type' => 'EXTERNAL_WEBSITE',  // Required for Website destination
            'optimization_goal' => 'CONVERT',  // Use CONVERT for Lead Gen with External Website
            'billing_event' => 'OCPM',
            'bid_type' => 'BID_TYPE_NO_BID',  // Lowest Cost strategy - no target CPA required
            'schedule_type' => 'SCHEDULE_FROM_NOW',
            'schedule_start_time' => $scheduleStart,
            'operation_status' => 'ENABLE',
            'targeting_spec' => [
                'location_ids' => $data['location_ids'] ?? ['6252001'],
                'age_groups' => $data['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100']
            ]
        ];

        // Add pixel
        if (!empty($data['pixel_id'])) {
            $adgroupParams['pixel_id'] = $data['pixel_id'];
            $adgroupParams['optimization_event'] = $data['optimization_event'] ?? 'FORM';
        }

        // For Smart+ campaigns with CBO (budget_optimize_on=true at campaign level):
        // AdGroup should use BUDGET_MODE_INFINITE with no budget
        // Budget is managed at campaign level, TikTok optimizes across ad groups
        $adgroupParams['budget_mode'] = 'BUDGET_MODE_INFINITE';
        logSmartPlus("Using BUDGET_MODE_INFINITE at AdGroup level (budget set at Campaign level)");

        // Optional: Add Target CPA only if provided and using Cost Cap strategy
        if (!empty($data['conversion_bid_price'])) {
            $adgroupParams['bid_type'] = 'BID_TYPE_CUSTOM';  // Cost Cap - requires target CPA
            $adgroupParams['conversion_bid_price'] = floatval($data['conversion_bid_price']);
            logSmartPlus("Using Cost Cap with target CPA: " . $data['conversion_bid_price']);
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

        // Step 3: Create Ad with media_info_list
        // Format: media_info_list = [{media_info: {video_info: {video_id}}}]
        logSmartPlus("Step 3: Creating Ad with creatives...");

        // Get creative_list directly from frontend (or from ads for backwards compat)
        $creativeList = $data['creative_list'] ?? [];

        // Backwards compatibility: if no creative_list, try to build from ads
        if (empty($creativeList) && !empty($data['ads'])) {
            foreach ($data['ads'] as $ad) {
                if (!empty($ad['video_id'])) {
                    $creativeList[] = [
                        'video_id' => $ad['video_id'],
                        'ad_text' => $ad['ad_text'] ?? 'Check it out!'
                    ];
                }
            }
        }

        // Ensure at least one creative
        if (empty($creativeList)) {
            logSmartPlus("Error: No creatives provided");
            echo json_encode([
                'success' => false,
                'message' => 'At least one video is required',
                'step' => 'ad'
            ]);
            exit;
        }

        logSmartPlus("creative_list input: " . json_encode($creativeList));

        // OPTIMIZED: Collect all video IDs first, then batch fetch covers
        $videoIds = [];
        foreach ($creativeList as $creative) {
            if (!empty($creative['video_id'])) {
                // Reject processing_* temporary IDs
                if (strpos($creative['video_id'], 'processing_') === 0) {
                    logSmartPlus("REJECTED: Video still processing: " . $creative['video_id']);
                    echo json_encode([
                        'success' => false,
                        'message' => 'A video is still processing. Please wait a moment and refresh your video library before launching.',
                        'error_code' => 'VIDEO_STILL_PROCESSING'
                    ]);
                    exit;
                }
                if (empty($creative['image_id'])) {
                    $videoIds[] = $creative['video_id'];
                }
            }
        }

        // OPTIMIZED: Batch fetch ALL cover images in minimal API calls
        $coverMap = [];
        if (!empty($videoIds)) {
            logSmartPlus("OPTIMIZED: Batch fetching covers for " . count($videoIds) . " videos");
            $coverMap = batchGetVideoCoverImages($videoIds, $advertiserId, $accessToken);
        }

        // Build creative_list with proper format for Smart+ Ads
        // Format: creative_list = [{creative_info: {video_info: {video_id}, image_info: [{web_uri}], ad_format}}]
        // For BC_AUTH_TT identities, identity info goes INSIDE each creative_info
        $creativeListFormatted = [];

        // Get identity info for BC_AUTH_TT (needs to be added to each creative)
        $identityId = $data['identity_id'] ?? null;
        $identityType = $data['identity_type'] ?? 'CUSTOMIZED_USER';
        $identityAuthorizedBcId = $data['identity_authorized_bc_id'] ?? null;

        foreach ($creativeList as $creative) {
            if (!empty($creative['video_id'])) {
                $videoId = $creative['video_id'];
                $creativeInfo = [
                    'video_info' => [
                        'video_id' => $videoId
                    ],
                    'ad_format' => 'SINGLE_VIDEO'
                ];

                // For BC_AUTH_TT, add identity info to each creative_info
                if ($identityType === 'BC_AUTH_TT' && !empty($identityId)) {
                    $creativeInfo['identity_id'] = $identityId;
                    $creativeInfo['identity_type'] = 'BC_AUTH_TT';
                    if (!empty($identityAuthorizedBcId)) {
                        // TikTok API expects 'identity_bc_id' for Smart+ ads
                        $creativeInfo['identity_bc_id'] = $identityAuthorizedBcId;
                        $creativeInfo['identity_authorized_bc_id'] = $identityAuthorizedBcId;
                    }
                }

                // Get cover image - use provided, or from batch result
                $imageId = $creative['image_id'] ?? $coverMap[$videoId] ?? null;

                if (!empty($imageId)) {
                    // Per TikTok SDK docs: image_info only requires web_uri (not image_id)
                    $creativeInfo['image_info'] = [[
                        'web_uri' => $imageId
                    ]];
                    logSmartPlus("Added image_info for video $videoId: web_uri=$imageId");
                } else {
                    logSmartPlus("WARNING: No cover image found for video: $videoId");
                }

                $creativeListFormatted[] = ['creative_info' => $creativeInfo];
            }
        }

        logSmartPlus("creative_list formatted: " . json_encode($creativeListFormatted));

        // Build landing_page_urls as array of OBJECTS with landing_page_url key
        // Per TikTok docs: landing_page_urls for Website destination (not page_list)
        $landingPageUrlList = [];
        if (!empty($data['landing_page_url'])) {
            $landingPageUrlList[] = ['landing_page_url' => $data['landing_page_url']];
        }

        // For Lead Gen Smart+ Ads: Use call_to_action_id (portfolio ID) in ad_configuration
        // AND call_to_action_list at top level with actual CTA values
        $callToActionId = $data['call_to_action_id'] ?? null;

        if (empty($callToActionId)) {
            logSmartPlus("ERROR: call_to_action_id (portfolio ID) is required for Lead Gen Smart+ Ads");
            echo json_encode([
                'success' => false,
                'message' => 'Dynamic CTA Portfolio is required for Lead Generation ads. Please select or create a CTA portfolio.',
                'step' => 'ad'
            ]);
            exit;
        }

        // Build ad_text_list from creatives - each ad_text becomes a separate item
        $adTextList = [];
        foreach ($creativeList as $creative) {
            if (!empty($creative['ad_text'])) {
                $adTextList[] = ['ad_text' => $creative['ad_text']];
            }
        }
        // Ensure at least one ad_text
        if (empty($adTextList)) {
            $adTextList[] = ['ad_text' => 'Check it out!'];
        }

        // Create Smart+ Ad with creative_list structure
        $adParams = [
            'advertiser_id' => $advertiserId,
            'adgroup_id' => $adgroupId,
            'ad_name' => $data['campaign_name'] . ' - Ad',
            'creative_list' => $creativeListFormatted,
            'landing_page_url_list' => $landingPageUrlList,
            'ad_text_list' => $adTextList
        ];

        // Build ad_configuration - per SDK docs, call_to_action_id belongs INSIDE ad_configuration
        $adConfig = [
            'call_to_action_id' => $callToActionId  // Dynamic CTA Portfolio ID goes here
        ];

        // Add identity configuration to ad_configuration ONLY for CUSTOMIZED_USER
        // For BC_AUTH_TT, identity info goes in each creative_info (already added above)
        if (!empty($identityId) && $identityType === 'CUSTOMIZED_USER') {
            $adConfig['identity_id'] = $identityId;
            $adConfig['identity_type'] = 'CUSTOMIZED_USER';
            logSmartPlus("Using CUSTOMIZED_USER identity in ad_configuration");
        } elseif ($identityType === 'BC_AUTH_TT') {
            logSmartPlus("BC_AUTH_TT identity added to creative_info (not ad_configuration)");
        }

        $adParams['ad_configuration'] = $adConfig;

        // Per TikTok docs: when using call_to_action_id (portfolio), call_to_action_list should NOT be passed.
        // Docs say: call_to_action_id = "Specify a valid value", call_to_action_list = "Not passed"
        logSmartPlus("Using call_to_action_id in ad_configuration (portfolio: $callToActionId). NOT sending call_to_action_list per TikTok API docs.");

        logSmartPlus("Ad params: " . json_encode($adParams));

        $adResult = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

        if ($adResult['code'] == 0 && isset($adResult['data']['smart_plus_ad_id'])) {
            $smartPlusAdId = $adResult['data']['smart_plus_ad_id'];
            $results['ads'][] = [
                'success' => true,
                'smart_plus_ad_id' => $smartPlusAdId,
                'name' => $data['campaign_name'] . ' - Ad'
            ];
            logSmartPlus("Smart+ Ad created: $smartPlusAdId with " . count($creativeList) . " creatives");

            // Campaign was created as DISABLED in Step 1, so no need to disable again
            // User can enable it manually when ready
            $results['campaign_disabled'] = true;
            logSmartPlus("Campaign was created as DISABLED - no post-creation disable needed");
        } else {
            $results['ads'][] = [
                'success' => false,
                'name' => $data['campaign_name'] . ' - Ad',
                'error' => $adResult['message'] ?? 'Unknown error',
                'details' => $adResult
            ];
            logSmartPlus("Failed to create ad: " . ($adResult['message'] ?? 'Unknown error'));
        }

        // Return results
        $creativesCount = count($creativeList);
        echo json_encode([
            'success' => !empty($results['ads'][0]['success']),
            'campaign_id' => $campaignId,
            'adgroup_id' => $adgroupId,
            'smart_plus_ad_id' => $results['ads'][0]['smart_plus_ad_id'] ?? null,
            'creatives_count' => $creativesCount,
            'results' => $results,
            'campaign_status' => 'DISABLED',  // Campaign is created as disabled
            'message' => !empty($results['ads'][0]['success'])
                ? "Smart+ Campaign created (PAUSED): Campaign $campaignId, AdGroup $adgroupId, Ad with $creativesCount creatives. Enable in TikTok Ads Manager when ready."
                : "Failed to create ad: " . ($results['ads'][0]['error'] ?? 'Unknown error')
        ]);
        break;

    // ==========================================
    // GET CTA PORTFOLIOS (from database)
    // ==========================================
    case 'get_cta_portfolios':
        logSmartPlus("=== Fetching CTA Portfolios from Database ===");

        try {
            require_once __DIR__ . '/database/Database.php';
            $db = Database::getInstance();

            // Fetch all portfolios for this advertiser from database
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
                ['advertiser_id' => $advertiserId]
            );

            logSmartPlus("CTA Portfolios Found: " . count($dbPortfolios));

            // Format portfolios for frontend
            $ctaPortfolios = [];
            foreach ($dbPortfolios as $dbPortfolio) {
                $portfolioContent = json_decode($dbPortfolio['portfolio_content'], true);

                $ctaPortfolios[] = [
                    'creative_portfolio_id' => $dbPortfolio['creative_portfolio_id'],
                    'portfolio_id' => $dbPortfolio['creative_portfolio_id'],
                    'portfolio_name' => $dbPortfolio['portfolio_name'],
                    'creative_portfolio_type' => 'CTA',
                    'portfolio_content' => $portfolioContent ?: [],
                    'created_by_tool' => true
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'portfolios' => $ctaPortfolios,
                    'total' => count($ctaPortfolios)
                ],
                'message' => count($ctaPortfolios) > 0
                    ? 'Found ' . count($ctaPortfolios) . ' portfolio(s)'
                    : 'No portfolios found'
            ]);
        } catch (Exception $e) {
            logSmartPlus("Error fetching portfolios: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch portfolios: ' . $e->getMessage()
            ]);
        }
        break;

    // ==========================================
    // CREATE CTA PORTFOLIO
    // ==========================================
    case 'create_cta_portfolio':
        $data = $input;
        $portfolioContent = $data['portfolio_content'] ?? [];
        $portfolioName = $data['portfolio_name'] ?? 'CTA Portfolio';

        logSmartPlus("=== Creating CTA Portfolio ===");
        logSmartPlus("Portfolio Name: $portfolioName");
        logSmartPlus("Portfolio Content: " . json_encode($portfolioContent));

        if (empty($portfolioContent)) {
            echo json_encode([
                'success' => false,
                'message' => 'portfolio_content is required'
            ]);
            exit;
        }

        $params = [
            'advertiser_id' => $advertiserId,
            'creative_portfolio_type' => 'CTA',
            'portfolio_content' => $portfolioContent
        ];

        $result = makeApiCall('/creative/portfolio/create/', $params, $accessToken);

        if ($result['code'] == 0 && isset($result['data']['creative_portfolio_id'])) {
            $portfolioId = $result['data']['creative_portfolio_id'];
            logSmartPlus("Portfolio created: $portfolioId");

            // Save to database
            try {
                require_once __DIR__ . '/database/Database.php';
                $db = Database::getInstance();

                $portfolioData = [
                    'advertiser_id' => $advertiserId,
                    'creative_portfolio_id' => $portfolioId,
                    'portfolio_name' => $portfolioName,
                    'portfolio_type' => 'CTA',
                    'portfolio_content' => json_encode($portfolioContent),
                    'created_by_tool' => 1
                ];

                $db->upsert('tool_portfolios', $portfolioData, ['advertiser_id', 'creative_portfolio_id']);
                logSmartPlus("Portfolio saved to database");
            } catch (Exception $e) {
                logSmartPlus("Warning: Failed to save portfolio to database: " . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'portfolio_id' => $portfolioId,
                'data' => $result['data'],
                'message' => 'Portfolio created successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create portfolio: ' . ($result['message'] ?? 'Unknown error'),
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // GET OR CREATE FREQUENTLY USED CTA PORTFOLIO
    // ==========================================
    case 'get_or_create_frequently_used_cta_portfolio':
        logSmartPlus("=== Get or Create Frequently Used CTA Portfolio ===");

        try {
            require_once __DIR__ . '/database/Database.php';
            $db = Database::getInstance();

            // Check if portfolio already exists
            $existingPortfolio = $db->fetchOne(
                "SELECT creative_portfolio_id, portfolio_name
                 FROM tool_portfolios
                 WHERE advertiser_id = :advertiser_id
                 AND portfolio_name = 'Frequently Used CTAs'
                 AND portfolio_type = 'CTA'",
                ['advertiser_id' => $advertiserId]
            );

            if ($existingPortfolio) {
                $existingPortfolioId = $existingPortfolio['creative_portfolio_id'];
                logSmartPlus("Found existing portfolio: $existingPortfolioId");

                // Verify it still exists in TikTok
                $verifyResult = makeApiCall('/creative/portfolio/list/', [
                    'advertiser_id' => $advertiserId,
                    'page' => 1,
                    'page_size' => 100
                ], $accessToken, 'GET');

                $portfolioStillExists = false;
                if (isset($verifyResult['data']['portfolios'])) {
                    foreach ($verifyResult['data']['portfolios'] as $portfolio) {
                        if ($portfolio['creative_portfolio_id'] == $existingPortfolioId) {
                            $portfolioStillExists = true;
                            break;
                        }
                    }
                }

                if ($portfolioStillExists) {
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'portfolio_id' => $existingPortfolioId,
                            'portfolio_name' => 'Frequently Used CTAs'
                        ],
                        'message' => 'Using existing frequently used CTA portfolio'
                    ]);
                    exit;
                }
            }

            // Create new portfolio - default to LEARN_MORE only
            logSmartPlus("Creating new CTA portfolio (LEARN_MORE only)");

            $frequentlyUsedCTAs = [
                ['asset_content' => 'LEARN_MORE', 'asset_ids' => ["0"]]
            ];

            $params = [
                'advertiser_id' => $advertiserId,
                'creative_portfolio_type' => 'CTA',
                'portfolio_content' => $frequentlyUsedCTAs
            ];

            $result = makeApiCall('/creative/portfolio/create/', $params, $accessToken);

            if ($result['code'] == 0 && isset($result['data']['creative_portfolio_id'])) {
                $newPortfolioId = $result['data']['creative_portfolio_id'];
                logSmartPlus("Created new portfolio: $newPortfolioId");

                // Save to database (non-blocking — portfolio already exists in TikTok)
                try {
                    $portfolioData = [
                        'advertiser_id' => $advertiserId,
                        'creative_portfolio_id' => $newPortfolioId,
                        'portfolio_name' => 'Frequently Used CTAs',
                        'portfolio_type' => 'CTA',
                        'portfolio_content' => json_encode($frequentlyUsedCTAs),
                        'created_by_tool' => 1
                    ];

                    $db->upsert('tool_portfolios', $portfolioData, ['advertiser_id', 'creative_portfolio_id']);
                } catch (Exception $dbErr) {
                    logSmartPlus("Warning: DB save failed for portfolio: " . $dbErr->getMessage());
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'portfolio_id' => $newPortfolioId,
                        'portfolio_name' => 'Frequently Used CTAs'
                    ],
                    'message' => 'Created new frequently used CTA portfolio'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to create portfolio: ' . ($result['message'] ?? 'Unknown error')
                ]);
            }
        } catch (Exception $e) {
            logSmartPlus("Error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
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
            // Invalidate identity cache so the new identity shows up immediately
            $cacheKey = $cache->generateKey('identities', $advertiserId);
            $cache->delete($cacheKey);
            logSmartPlus("Identity cache invalidated for advertiser: $advertiserId");

            echo json_encode([
                'success' => true,
                'identity_id' => $result['data']['identity_id'],
                'message' => 'Identity created successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to create identity',
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // BULK LAUNCH: Create Identity for Specific Account
    // ==========================================
    case 'create_identity_for_account':
        $targetAdvertiserId = $input['target_advertiser_id'] ?? '';
        $displayName = $input['display_name'] ?? '';
        $profileImageId = $input['profile_image_id'] ?? null;

        if (empty($targetAdvertiserId)) {
            echo json_encode(['success' => false, 'message' => 'target_advertiser_id is required']);
            exit;
        }

        if (empty($displayName)) {
            echo json_encode(['success' => false, 'message' => 'display_name is required']);
            exit;
        }

        // Validate advertiser is authorized
        $allAdvertiserIds = $_SESSION['oauth_advertiser_ids'] ?? [];
        if (!in_array($targetAdvertiserId, $allAdvertiserIds)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized advertiser ID']);
            exit;
        }

        logSmartPlus("=== Creating Identity for Account: $targetAdvertiserId ===");
        logSmartPlus("Display Name: $displayName");

        $params = [
            'advertiser_id' => $targetAdvertiserId,
            'identity_type' => 'CUSTOMIZED_USER',
            'display_name' => $displayName
        ];

        if (!empty($profileImageId)) {
            $params['profile_image_id'] = $profileImageId;
        }

        $result = makeApiCall('/identity/create/', $params, $accessToken);

        if ($result['code'] == 0 && isset($result['data']['identity_id'])) {
            // Invalidate identity cache for the target account
            $cacheKey = $cache->generateKey('identities', $targetAdvertiserId);
            $cache->delete($cacheKey);
            logSmartPlus("Identity cache invalidated for target advertiser: $targetAdvertiserId");

            echo json_encode([
                'success' => true,
                'identity_id' => $result['data']['identity_id'],
                'display_name' => $displayName,
                'message' => 'Identity created successfully'
            ]);
        } else {
            logSmartPlus("Identity creation failed: " . json_encode($result));
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to create identity',
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // BULK LAUNCH: Get Available Accounts for Bulk Launch
    // ==========================================
    case 'get_bulk_accounts':
        logSmartPlus("=== Getting Available Accounts for Bulk Launch ===");

        // Get all advertiser IDs from session (these are all accounts user has OAuth access to)
        $allAdvertiserIds = $_SESSION['oauth_advertiser_ids'] ?? [];
        $currentAdvertiserId = $_SESSION['selected_advertiser_id'] ?? '';
        $advertiserDetails = $_SESSION['oauth_advertiser_details'] ?? [];

        if (empty($allAdvertiserIds)) {
            echo json_encode([
                'success' => false,
                'message' => 'No advertiser accounts found in session'
            ]);
            exit;
        }

        $accounts = [];
        foreach ($allAdvertiserIds as $advId) {
            $details = $advertiserDetails[$advId] ?? null;
            $accounts[] = [
                'advertiser_id' => $advId,
                'advertiser_name' => $details['name'] ?? 'Account ' . $advId,
                'is_current' => ($advId === $currentAdvertiserId),
                'status' => $details['status'] ?? 'active'
            ];
        }

        logSmartPlus("Found " . count($accounts) . " accounts for bulk launch");

        echo json_encode([
            'success' => true,
            'data' => [
                'accounts' => $accounts,
                'current_advertiser_id' => $currentAdvertiserId,
                'total' => count($accounts)
            ]
        ]);
        break;

    // ==========================================
    // MY CAMPAIGNS: Get All Advertisers for Bulk Duplicate
    // ==========================================
    case 'get_all_advertisers':
        logSmartPlus("=== Getting All Advertisers for Bulk Duplicate ===");

        // Get all advertiser IDs from session (these are all accounts user has OAuth access to)
        $allAdvertiserIds = $_SESSION['oauth_advertiser_ids'] ?? [];
        $currentAdvertiserId = $_SESSION['selected_advertiser_id'] ?? '';
        $advertiserDetails = $_SESSION['oauth_advertiser_details'] ?? [];

        if (empty($allAdvertiserIds)) {
            echo json_encode([
                'success' => false,
                'message' => 'No advertiser accounts found in session. Please re-authenticate.'
            ]);
            exit;
        }

        $advertiserList = [];
        foreach ($allAdvertiserIds as $advId) {
            $details = $advertiserDetails[$advId] ?? null;
            $advertiserList[] = [
                'advertiser_id' => $advId,
                'advertiser_name' => $details['name'] ?? 'Account ' . $advId,
                'is_current' => ($advId === $currentAdvertiserId),
                'status' => $details['status'] ?? 'active',
                'company' => $details['company'] ?? null,
                'contacter' => $details['contacter'] ?? null
            ];
        }

        logSmartPlus("Found " . count($advertiserList) . " advertisers for bulk duplicate");

        // Return in format expected by loadDuplicateBulkAccounts(): result.data.list
        echo json_encode([
            'success' => true,
            'data' => [
                'list' => $advertiserList,
                'current_advertiser_id' => $currentAdvertiserId,
                'total' => count($advertiserList)
            ]
        ]);
        break;

    // ==========================================
    // BULK LAUNCH: Get Account Assets (Pixels, Identities, Videos)
    // ==========================================
    case 'get_account_assets':
        $targetAdvertiserId = $input['target_advertiser_id'] ?? '';

        if (empty($targetAdvertiserId)) {
            logSmartPlus("ERROR: get_account_assets - target_advertiser_id is required");
            echo json_encode(['success' => false, 'message' => 'target_advertiser_id is required']);
            exit;
        }

        // Check if session exists and has required data
        if (empty($_SESSION)) {
            logSmartPlus("ERROR: get_account_assets - Session is empty or expired");
            echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh the page and try again.']);
            exit;
        }

        // Check if access token exists
        if (empty($accessToken)) {
            logSmartPlus("ERROR: get_account_assets - No access token available");
            echo json_encode(['success' => false, 'message' => 'No access token. Please reconnect your TikTok account.']);
            exit;
        }

        // Validate advertiser is authorized
        $allAdvertiserIds = $_SESSION['oauth_advertiser_ids'] ?? [];

        if (empty($allAdvertiserIds)) {
            logSmartPlus("ERROR: get_account_assets - No advertiser IDs in session");
            echo json_encode(['success' => false, 'message' => 'No advertiser accounts found. Please reconnect your TikTok account.']);
            exit;
        }

        if (!in_array($targetAdvertiserId, $allAdvertiserIds)) {
            logSmartPlus("ERROR: get_account_assets - Unauthorized: $targetAdvertiserId not in " . json_encode($allAdvertiserIds));
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized advertiser ID. This account may not be linked to your OAuth session.',
                'debug' => [
                    'requested_id' => $targetAdvertiserId,
                    'authorized_count' => count($allAdvertiserIds)
                ]
            ]);
            exit;
        }

        logSmartPlus("=== Getting Assets for Account: $targetAdvertiserId ===");
        logSmartPlus("Access token length: " . strlen($accessToken) . ", Authorized accounts: " . count($allAdvertiserIds));

        try {
        $assets = [
            'advertiser_id' => $targetAdvertiserId,
            'pixels' => [],
            'identities' => [],
            'videos' => [],
            'images' => [],
            'errors' => []  // Track any API errors for debugging
        ];

        // Get Pixels
        $pixelResult = makeApiCall('/pixel/list/', [
            'advertiser_id' => $targetAdvertiserId
        ], $accessToken, 'GET');

        if ($pixelResult['code'] == 0 && isset($pixelResult['data']['pixels'])) {
            $assets['pixels'] = $pixelResult['data']['pixels'];
            logSmartPlus("Found " . count($assets['pixels']) . " pixels");
        } else {
            $errorMsg = "Pixel API error: " . ($pixelResult['message'] ?? 'Unknown') . " (code: " . ($pixelResult['code'] ?? 'N/A') . ")";
            logSmartPlus($errorMsg);
            $assets['errors']['pixels'] = $errorMsg;
        }

        // Get Identities
        $identityResult = makeApiCall('/identity/get/', [
            'advertiser_id' => $targetAdvertiserId
        ], $accessToken, 'GET');

        if ($identityResult['code'] == 0 && isset($identityResult['data']['identity_list'])) {
            $assets['identities'] = $identityResult['data']['identity_list'];
            logSmartPlus("Found " . count($assets['identities']) . " identities");
        } else {
            $errorMsg = "Identity API error: " . ($identityResult['message'] ?? 'Unknown') . " (code: " . ($identityResult['code'] ?? 'N/A') . ")";
            logSmartPlus($errorMsg);
            $assets['errors']['identities'] = $errorMsg;
        }

        // Get Videos
        $videoResult = makeApiCall('/file/video/ad/search/', [
            'advertiser_id' => $targetAdvertiserId,
            'page' => 1,
            'page_size' => 100
        ], $accessToken, 'GET');

        if ($videoResult['code'] == 0 && isset($videoResult['data']['list'])) {
            $assets['videos'] = $videoResult['data']['list'];
            logSmartPlus("Found " . count($assets['videos']) . " videos");
        } else {
            $errorMsg = "Video API error: " . ($videoResult['message'] ?? 'Unknown') . " (code: " . ($videoResult['code'] ?? 'N/A') . ")";
            logSmartPlus($errorMsg);
            $assets['errors']['videos'] = $errorMsg;
        }

        // Get Images (optional - not critical for bulk launch)
        try {
            $imageResult = makeApiCall('/file/image/ad/get/', [
                'advertiser_id' => $targetAdvertiserId,
                'page' => 1,
                'page_size' => 100
            ], $accessToken, 'GET');

            if ($imageResult['code'] == 0 && isset($imageResult['data']['list'])) {
                $assets['images'] = $imageResult['data']['list'];
                logSmartPlus("Found " . count($assets['images']) . " images");
            } else if ($imageResult['code'] == 0) {
                // No images but no error
                $assets['images'] = [];
                logSmartPlus("No images found in library (this is OK)");
            } else {
                // Only log as warning, don't treat as blocking error
                logSmartPlus("Warning: Image API returned code " . ($imageResult['code'] ?? 'N/A') . " - " . ($imageResult['message'] ?? 'Unknown'));
                $assets['images'] = [];
            }
        } catch (Exception $e) {
            logSmartPlus("Warning: Could not load images: " . $e->getMessage());
            $assets['images'] = [];
        }

        // Get Portfolios (CTA portfolios for bulk launch)
        $assets['portfolios'] = [];
        try {
            // First check database for tool-created portfolios
            $dbPortfolios = [];
            try {
                require_once __DIR__ . '/database/Database.php';
                $db = Database::getInstance();
                $dbPortfolios = $db->fetchAll(
                    "SELECT creative_portfolio_id, portfolio_name FROM tool_portfolios WHERE advertiser_id = :advertiser_id ORDER BY created_at DESC",
                    ['advertiser_id' => $targetAdvertiserId]
                );
                logSmartPlus("Found " . count($dbPortfolios) . " portfolios in database for $targetAdvertiserId");
            } catch (Exception $e) {
                logSmartPlus("Warning: Could not query tool_portfolios: " . $e->getMessage());
            }

            // Then get from TikTok API
            $portfolioResult = makeApiCall('/creative/portfolio/list/', [
                'advertiser_id' => $targetAdvertiserId,
                'page' => 1,
                'page_size' => 100
            ], $accessToken, 'GET');

            if ($portfolioResult['code'] == 0 && !empty($portfolioResult['data']['portfolios'])) {
                foreach ($portfolioResult['data']['portfolios'] as $portfolio) {
                    // Only include CTA portfolios
                    if (isset($portfolio['creative_portfolio_type']) && $portfolio['creative_portfolio_type'] === 'CTA') {
                        $assets['portfolios'][] = [
                            'portfolio_id' => $portfolio['creative_portfolio_id'],
                            'portfolio_name' => $portfolio['portfolio_name'] ?? 'CTA Portfolio',
                            'source' => 'tiktok_api'
                        ];
                    }
                }
                logSmartPlus("Found " . count($assets['portfolios']) . " CTA portfolios from TikTok API");
            }

            // Add database portfolios that aren't already in the list
            foreach ($dbPortfolios as $dbPortfolio) {
                $exists = false;
                foreach ($assets['portfolios'] as $p) {
                    if ($p['portfolio_id'] === $dbPortfolio['creative_portfolio_id']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $assets['portfolios'][] = [
                        'portfolio_id' => $dbPortfolio['creative_portfolio_id'],
                        'portfolio_name' => $dbPortfolio['portfolio_name'] ?? 'Tool Portfolio',
                        'source' => 'database'
                    ];
                }
            }
        } catch (Exception $e) {
            logSmartPlus("Warning: Could not load portfolios: " . $e->getMessage());
            $assets['errors']['portfolios'] = $e->getMessage();
        }

        // Log summary
        logSmartPlus("Assets summary for $targetAdvertiserId: " .
            count($assets['pixels']) . " pixels, " .
            count($assets['identities']) . " identities, " .
            count($assets['videos']) . " videos, " .
            count($assets['images']) . " images, " .
            count($assets['portfolios']) . " portfolios");

        // Remove empty errors array if no errors
        if (empty($assets['errors'])) {
            unset($assets['errors']);
        }

        echo json_encode([
            'success' => true,
            'data' => $assets
        ]);
        } catch (Exception $e) {
            logSmartPlus("EXCEPTION in get_account_assets: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Server error while loading assets: ' . $e->getMessage()
            ]);
        }
        break;

    // ==========================================
    // BULK LAUNCH: Create Portfolio for Specific Account
    // ==========================================
    case 'create_portfolio_for_account':
        $targetAdvertiserId = $input['target_advertiser_id'] ?? '';
        $portfolioName = $input['portfolio_name'] ?? 'Frequently Used CTAs';
        $portfolioContent = $input['portfolio_content'] ?? [];

        if (empty($targetAdvertiserId)) {
            echo json_encode(['success' => false, 'message' => 'target_advertiser_id is required']);
            exit;
        }

        // Validate advertiser is authorized
        $allAdvertiserIds = $_SESSION['oauth_advertiser_ids'] ?? [];
        if (!in_array($targetAdvertiserId, $allAdvertiserIds)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized advertiser ID']);
            exit;
        }

        logSmartPlus("=== Creating Portfolio for Account: $targetAdvertiserId ===");
        logSmartPlus("Portfolio Name: $portfolioName");

        // Default CTAs if none provided
        if (empty($portfolioContent)) {
            $portfolioContent = [
                ['asset_content' => 'LEARN_MORE', 'asset_ids' => ["0"]]
            ];
            logSmartPlus("No CTA selections provided for account portfolio, defaulting to LEARN_MORE only");
        }

        try {
            $createParams = [
                'advertiser_id' => $targetAdvertiserId,
                'creative_portfolio_type' => 'CTA',
                'portfolio_content' => $portfolioContent
            ];

            logSmartPlus("Creating portfolio with params: " . json_encode($createParams));

            $result = makeApiCall('/creative/portfolio/create/', $createParams, $accessToken);

            if ($result['code'] == 0 && isset($result['data']['creative_portfolio_id'])) {
                $portfolioId = $result['data']['creative_portfolio_id'];

                // Save to database
                try {
                    $db = Database::getInstance();
                    $db->upsert('tool_portfolios', [
                        'advertiser_id' => $targetAdvertiserId,
                        'creative_portfolio_id' => $portfolioId,
                        'portfolio_name' => $portfolioName,
                        'portfolio_type' => 'CTA',
                        'portfolio_content' => json_encode($portfolioContent),
                        'created_by_tool' => true
                    ], ['advertiser_id', 'creative_portfolio_id']);
                    logSmartPlus("Portfolio saved to database: $portfolioId");
                } catch (Exception $e) {
                    logSmartPlus("Warning: Could not save portfolio to DB: " . $e->getMessage());
                }

                echo json_encode([
                    'success' => true,
                    'portfolio_id' => $portfolioId,
                    'portfolio_name' => $portfolioName,
                    'message' => 'Portfolio created successfully'
                ]);
            } else {
                logSmartPlus("Portfolio creation failed: " . json_encode($result));
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to create portfolio: ' . ($result['message'] ?? 'Unknown error'),
                    'details' => $result
                ]);
            }
        } catch (Exception $e) {
            logSmartPlus("Exception creating portfolio: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error creating portfolio: ' . $e->getMessage()
            ]);
        }
        break;

    // ==========================================
    // BULK LAUNCH: Match Videos by Filename
    // ==========================================
    case 'match_videos_by_filename':
        $targetAdvertiserId = $input['target_advertiser_id'] ?? '';
        $sourceVideos = $input['source_videos'] ?? []; // Array of {video_id, file_name}

        if (empty($targetAdvertiserId) || empty($sourceVideos)) {
            echo json_encode(['success' => false, 'message' => 'target_advertiser_id and source_videos are required']);
            exit;
        }

        logSmartPlus("=== Matching Videos for Account: $targetAdvertiserId ===");

        // Get videos from target account
        $videoResult = makeApiCall('/file/video/ad/search/', [
            'advertiser_id' => $targetAdvertiserId,
            'page' => 1,
            'page_size' => 100
        ], $accessToken, 'GET');

        $targetVideos = [];
        if ($videoResult['code'] == 0 && isset($videoResult['data']['list'])) {
            $targetVideos = $videoResult['data']['list'];
        }

        // Build filename to video_id map for target account
        $targetVideoMap = [];
        foreach ($targetVideos as $video) {
            $fileName = $video['file_name'] ?? '';
            if ($fileName) {
                $targetVideoMap[$fileName] = $video;
            }
        }

        // Match source videos to target videos
        $matched = [];
        $unmatched = [];

        foreach ($sourceVideos as $sourceVideo) {
            $sourceFileName = $sourceVideo['file_name'] ?? '';
            $sourceVideoId = $sourceVideo['video_id'] ?? '';

            if (isset($targetVideoMap[$sourceFileName])) {
                $targetVideo = $targetVideoMap[$sourceFileName];
                $matched[] = [
                    'source_video_id' => $sourceVideoId,
                    'source_file_name' => $sourceFileName,
                    'target_video_id' => $targetVideo['video_id'],
                    'target_file_name' => $targetVideo['file_name']
                ];
            } else {
                $unmatched[] = [
                    'source_video_id' => $sourceVideoId,
                    'source_file_name' => $sourceFileName
                ];
            }
        }

        logSmartPlus("Matched: " . count($matched) . ", Unmatched: " . count($unmatched));

        echo json_encode([
            'success' => true,
            'data' => [
                'matched' => $matched,
                'unmatched' => $unmatched,
                'total_source' => count($sourceVideos),
                'match_rate' => count($sourceVideos) > 0 ? round(count($matched) / count($sourceVideos) * 100) : 0
            ]
        ]);
        break;

    // ==========================================
    // BULK LAUNCH: Upload Video to Account
    // ==========================================
    case 'upload_video_to_account':
        $targetAdvertiserId = $input['target_advertiser_id'] ?? '';
        $videoUrl = $input['video_url'] ?? '';
        $fileName = $input['file_name'] ?? '';

        if (empty($targetAdvertiserId) || empty($videoUrl)) {
            echo json_encode(['success' => false, 'message' => 'target_advertiser_id and video_url are required']);
            exit;
        }

        logSmartPlus("=== Uploading Video to Account: $targetAdvertiserId ===");

        $uploadResult = makeApiCall('/file/video/ad/upload/', [
            'advertiser_id' => $targetAdvertiserId,
            'upload_type' => 'UPLOAD_BY_URL',
            'video_url' => $videoUrl,
            'file_name' => $fileName
        ], $accessToken);

        if ($uploadResult['code'] == 0 && isset($uploadResult['data']['video_id'])) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'video_id' => $uploadResult['data']['video_id'],
                    'advertiser_id' => $targetAdvertiserId
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $uploadResult['message'] ?? 'Failed to upload video',
                'details' => $uploadResult
            ]);
        }
        break;

    // ==========================================
    // BULK LAUNCH: Execute Bulk Campaign Launch
    // ==========================================
    case 'execute_bulk_launch':
        $data = $input;

        logSmartPlus("=== EXECUTING BULK LAUNCH ===");

        // Validate required fields
        if (empty($data['campaign_config'])) {
            echo json_encode(['success' => false, 'message' => 'campaign_config is required']);
            exit;
        }
        if (empty($data['accounts'])) {
            echo json_encode(['success' => false, 'message' => 'accounts array is required']);
            exit;
        }

        $campaignConfig = $data['campaign_config'];
        $accounts = $data['accounts'];
        $primaryAdvertiserId = $data['primary_advertiser_id'] ?? $advertiserId;
        $duplicateCount = intval($data['duplicate_count'] ?? $campaignConfig['duplicate_count'] ?? 1);

        // Validate duplicate count
        if ($duplicateCount < 1) $duplicateCount = 1;
        if ($duplicateCount > 10) $duplicateCount = 10;

        // Generate job ID
        $jobId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        logSmartPlus("Bulk Job ID: $jobId");
        logSmartPlus("Received " . count($accounts) . " accounts with $duplicateCount campaign copies each");

        // IMPORTANT: Deduplicate accounts by advertiser_id to prevent duplicate campaigns
        $uniqueAccounts = [];
        $seenIds = [];
        foreach ($accounts as $account) {
            $advId = $account['advertiser_id'];
            if (!in_array($advId, $seenIds)) {
                $seenIds[] = $advId;
                $uniqueAccounts[] = $account;
            } else {
                logSmartPlus("WARNING: Duplicate account detected and skipped: $advId");
            }
        }
        $accounts = $uniqueAccounts;
        logSmartPlus("Processing " . count($accounts) . " unique accounts after deduplication");

        $results = [
            'job_id' => $jobId,
            'total' => count($accounts) * $duplicateCount,
            'duplicate_count' => $duplicateCount,
            'success' => [],
            'failed' => []
        ];

        // Process each account (skip primary advertiser — already handled by single-account flow)
        foreach ($accounts as $index => $account) {
            $targetAdvertiserId = $account['advertiser_id'];
            $accountName = $account['advertiser_name'] ?? 'Account ' . ($index + 1);

            if ($targetAdvertiserId === $primaryAdvertiserId) {
                logSmartPlus("Skipping primary advertiser $targetAdvertiserId in bulk loop (already launched)");
                continue;
            }
            $pixelId = $account['pixel_id'] ?? null;
            $identityId = $account['identity_id'] ?? null;
            $identityType = $account['identity_type'] ?? 'CUSTOMIZED_USER';
            $identityAuthorizedBcId = $account['identity_authorized_bc_id'] ?? null;
            $videoMapping = $account['video_mapping'] ?? [];
            $accountLandingPageUrl = $account['landing_page_url'] ?? null;  // Optional per-account landing URL override
            $accountCampaignName = $account['campaign_name'] ?? null;  // Optional per-account campaign name override

            if ($accountCampaignName) {
                logSmartPlus("Using per-account campaign name: $accountCampaignName");
            }

            logSmartPlus("--- Processing Account: $accountName ($targetAdvertiserId) ---");

            // Rate limiting: 200ms delay between accounts
            if ($index > 0) {
                usleep(200000);
            }

            // Clear caches for this advertiser (outside the duplicate loop)
            $GLOBALS['imageLibraryCache'] = null;
            $GLOBALS['videoInfoCache'] = [];

            // CTA Portfolio - use selected portfolio or get/create one
            $ctaPortfolioId = null;
            $selectedPortfolioId = $account['portfolio_id'] ?? null;

            try {
                require_once __DIR__ . '/database/Database.php';
                $db = Database::getInstance();

                // If user selected a specific portfolio, use it
                if (!empty($selectedPortfolioId) && $selectedPortfolioId !== 'auto_create') {
                    $ctaPortfolioId = $selectedPortfolioId;
                    logSmartPlus("Using user-selected portfolio: $ctaPortfolioId");
                } else {
                    // Auto-create: First check for existing portfolio in database
                    $existingPortfolio = $db->fetchOne(
                        "SELECT creative_portfolio_id FROM tool_portfolios
                         WHERE advertiser_id = :advertiser_id
                         AND portfolio_type = 'CTA'
                         ORDER BY created_at DESC LIMIT 1",
                        ['advertiser_id' => $targetAdvertiserId]
                    );

                    if ($existingPortfolio) {
                        $ctaPortfolioId = $existingPortfolio['creative_portfolio_id'];
                        logSmartPlus("Using existing portfolio from DB: $ctaPortfolioId");
                    } else {
                        // Create new portfolio using the primary account's CTA selections if available
                        logSmartPlus("Creating new CTA portfolio for account: $targetAdvertiserId");
                        $ctaSelections = $account['cta_selections'] ?? null;
                        $frequentlyUsedCTAs = [];
                        if (!empty($ctaSelections) && is_array($ctaSelections)) {
                            // Use the user's actual CTA selections from the primary account
                            foreach ($ctaSelections as $cta) {
                                if (is_array($cta) && isset($cta['asset_content'])) {
                                    $frequentlyUsedCTAs[] = $cta;
                                } elseif (is_string($cta)) {
                                    $frequentlyUsedCTAs[] = ['asset_content' => $cta, 'asset_ids' => ["0"]];
                                }
                            }
                            logSmartPlus("Using user's CTA selections: " . json_encode($frequentlyUsedCTAs));
                        }
                        if (empty($frequentlyUsedCTAs)) {
                            // Default to LEARN_MORE only (not 5 CTAs)
                            $frequentlyUsedCTAs = [
                                ['asset_content' => 'LEARN_MORE', 'asset_ids' => ["0"]]
                            ];
                            logSmartPlus("No CTA selections provided, defaulting to LEARN_MORE only");
                        }

                        $portfolioResult = makeApiCall('/creative/portfolio/create/', [
                            'advertiser_id' => $targetAdvertiserId,
                            'creative_portfolio_type' => 'CTA',
                            'portfolio_content' => $frequentlyUsedCTAs
                        ], $accessToken);

                        if ($portfolioResult['code'] == 0 && isset($portfolioResult['data']['creative_portfolio_id'])) {
                            $ctaPortfolioId = $portfolioResult['data']['creative_portfolio_id'];
                            logSmartPlus("Portfolio created: $ctaPortfolioId");

                            $db->insert('tool_portfolios', [
                                'advertiser_id' => $targetAdvertiserId,
                                'creative_portfolio_id' => $ctaPortfolioId,
                                'portfolio_name' => 'Auto-Generated CTAs',
                                'portfolio_type' => 'CTA',
                                'portfolio_content' => json_encode($frequentlyUsedCTAs),
                                'created_by_tool' => 1
                            ]);
                        } else {
                            logSmartPlus("Warning: Portfolio creation failed: " . json_encode($portfolioResult));
                        }
                    }
                }
            } catch (Exception $e) {
                logSmartPlus("Warning: Could not get/create CTA portfolio: " . $e->getMessage());
            }

            // If we still don't have a portfolio, try to get one from TikTok API
            if (empty($ctaPortfolioId)) {
                logSmartPlus("Attempting to find existing portfolio from TikTok API...");
                try {
                    $portfolioListResult = makeApiCall('/creative/portfolio/list/', [
                        'advertiser_id' => $targetAdvertiserId,
                        'page' => 1,
                        'page_size' => 10
                    ], $accessToken, 'GET');

                    if ($portfolioListResult['code'] == 0 && !empty($portfolioListResult['data']['portfolios'])) {
                        foreach ($portfolioListResult['data']['portfolios'] as $portfolio) {
                            if (isset($portfolio['creative_portfolio_type']) && $portfolio['creative_portfolio_type'] === 'CTA') {
                                $ctaPortfolioId = $portfolio['creative_portfolio_id'];
                                logSmartPlus("Found existing CTA portfolio from API: $ctaPortfolioId");
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    logSmartPlus("Warning: Could not fetch portfolios from API: " . $e->getMessage());
                }
            }

            // Loop for creating duplicate campaigns
            for ($copyNum = 1; $copyNum <= $duplicateCount; $copyNum++) {
                // Use per-account campaign name if set, otherwise use config default
                $baseCampaignName = !empty($account['campaign_name'])
                    ? $account['campaign_name']
                    : $campaignConfig['campaign_name'];

                // Generate campaign name with copy number (if duplicates > 1)
                $campaignName = $duplicateCount > 1
                    ? $baseCampaignName . ' (' . $copyNum . ')'
                    : $baseCampaignName;

                logSmartPlus("Creating campaign copy $copyNum/$duplicateCount: $campaignName");

            try {
                // 1. CREATE CAMPAIGN
                // Smart+ campaigns REQUIRE: BUDGET_MODE_DYNAMIC_DAILY_BUDGET + budget_optimize_on at campaign level
                $budget = floatval($campaignConfig['budget'] ?? 50);
                if ($budget < 20) $budget = 50;  // Minimum $20, default $50

                $campaignParams = [
                    'advertiser_id' => $targetAdvertiserId,
                    'campaign_name' => $campaignName,
                    'objective_type' => 'LEAD_GENERATION',
                    'request_id' => generateRequestId(),
                    'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',  // Required for Smart+ campaigns
                    'budget' => $budget,
                    'budget_optimize_on' => true,  // CBO enabled - TikTok optimizes across ad groups
                    'operation_status' => 'DISABLE'  // Create as DISABLED - safer default
                ];

                $campaignResult = makeApiCall('/smart_plus/campaign/create/', $campaignParams, $accessToken);

                if ($campaignResult['code'] != 0 || !isset($campaignResult['data']['campaign_id'])) {
                    throw new Exception('Campaign creation failed: ' . ($campaignResult['message'] ?? 'Unknown error'));
                }

                $campaignId = $campaignResult['data']['campaign_id'];
                logSmartPlus("Campaign created (DISABLED): $campaignId");

                // 2. CREATE AD GROUP
                // TikTok API requires UTC+0 format for schedule times
                // Use schedule data from campaign config if provided, otherwise start immediately
                $scheduleType = $campaignConfig['schedule_type'] ?? 'SCHEDULE_FROM_NOW';
                $scheduleStart = null;
                $scheduleEnd = null;
                $bulkUserTimezone = $campaignConfig['user_timezone'] ?? 'America/New_York';

                if (!empty($campaignConfig['schedule_start_time'])) {
                    $rawStartTime = $campaignConfig['schedule_start_time'];
                    logSmartPlus("Raw schedule_start_time received: " . (is_string($rawStartTime) ? $rawStartTime : "timestamp: $rawStartTime") . " (tz: $bulkUserTimezone)");

                    // Check if it's a string (new format "YYYY-MM-DD HH:MM:SS") or number (legacy timestamp)
                    if (is_string($rawStartTime) && strpos($rawStartTime, '-') !== false) {
                        // String format - convert from user's timezone to UTC
                        $scheduleStart = convertESTtoUTC($rawStartTime, $bulkUserTimezone);
                        logSmartPlus("Converted to UTC: $scheduleStart");
                    } else {
                        // Legacy timestamp format - convert to UTC datetime string
                        $scheduleStart = gmdate('Y-m-d H:i:s', intval($rawStartTime));
                        logSmartPlus("Converted timestamp to UTC: $scheduleStart");
                    }
                } else {
                    // Start immediately — add 5-min buffer so time is still in the future when TikTok validates
                    $scheduleStart = getUTCDateTime('+5 minutes');
                    logSmartPlus("Using immediate start time (UTC, now+5min): $scheduleStart");
                }

                if (!empty($campaignConfig['schedule_end_time'])) {
                    $rawEndTime = $campaignConfig['schedule_end_time'];
                    logSmartPlus("Raw schedule_end_time received: " . (is_string($rawEndTime) ? $rawEndTime : "timestamp: $rawEndTime"));

                    // Check if it's a string or number
                    if (is_string($rawEndTime) && strpos($rawEndTime, '-') !== false) {
                        // String format - convert from user's timezone to UTC
                        $scheduleEnd = convertESTtoUTC($rawEndTime, $bulkUserTimezone);
                        logSmartPlus("Converted end time to UTC: $scheduleEnd");
                    } else {
                        // Legacy timestamp format
                        $scheduleEnd = gmdate('Y-m-d H:i:s', intval($rawEndTime));
                        logSmartPlus("Converted timestamp end time to UTC: $scheduleEnd");
                    }
                }

                $adgroupParams = [
                    'advertiser_id' => $targetAdvertiserId,
                    'request_id' => generateRequestId(),
                    'campaign_id' => $campaignId,
                    'adgroup_name' => $campaignName . ' - Ad Group',
                    'promotion_type' => 'LEAD_GENERATION',
                    'promotion_target_type' => 'EXTERNAL_WEBSITE',
                    'optimization_goal' => 'CONVERT',
                    'billing_event' => 'OCPM',
                    'bid_type' => 'BID_TYPE_NO_BID',  // Lowest Cost strategy - no target CPA required
                    'schedule_type' => $scheduleType,
                    'schedule_start_time' => $scheduleStart,
                    'operation_status' => 'ENABLE',
                    'targeting_spec' => [
                        'location_ids' => $campaignConfig['location_ids'] ?? ['6252001'],
                        'age_groups' => $campaignConfig['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100']
                    ]
                ];

                // Add schedule_end_time if provided
                if (!empty($scheduleEnd)) {
                    $adgroupParams['schedule_end_time'] = $scheduleEnd;
                }

                // Add pixel if provided for this account
                if (!empty($pixelId)) {
                    $adgroupParams['pixel_id'] = $pixelId;
                    $adgroupParams['optimization_event'] = $campaignConfig['optimization_event'] ?? 'FORM';
                }

                // For Smart+ campaigns with CBO (budget_optimize_on=true at campaign level):
                // AdGroup should use BUDGET_MODE_INFINITE with no budget
                $adgroupParams['budget_mode'] = 'BUDGET_MODE_INFINITE';

                // Optional: Add Target CPA only if provided
                if (!empty($campaignConfig['conversion_bid_price'])) {
                    $adgroupParams['bid_type'] = 'BID_TYPE_CUSTOM';  // Cost Cap - requires target CPA
                    $adgroupParams['conversion_bid_price'] = floatval($campaignConfig['conversion_bid_price']);
                }

                // Add dayparting if provided
                if (!empty($campaignConfig['dayparting'])) {
                    $adgroupParams['dayparting'] = $campaignConfig['dayparting'];
                }

                $adgroupResult = makeApiCall('/smart_plus/adgroup/create/', $adgroupParams, $accessToken);

                if ($adgroupResult['code'] != 0 || !isset($adgroupResult['data']['adgroup_id'])) {
                    throw new Exception('Ad Group creation failed: ' . ($adgroupResult['message'] ?? 'Unknown error'));
                }

                $adgroupId = $adgroupResult['data']['adgroup_id'];
                logSmartPlus("Ad Group created: $adgroupId");

                // 3. CREATE AD
                // Map videos from source to target
                logSmartPlus("Video mapping received: " . json_encode($videoMapping));
                logSmartPlus("Creatives config: " . json_encode($campaignConfig['creatives'] ?? []));

                // First, collect all target video IDs for batch processing
                $targetVideoIds = [];
                foreach ($campaignConfig['creatives'] ?? [] as $creative) {
                    $sourceVideoId = $creative['video_id'];
                    $targetVideoId = $videoMapping[$sourceVideoId] ?? $sourceVideoId;
                    // Reject processing_* temporary IDs
                    if (strpos($targetVideoId, 'processing_') === 0) {
                        throw new Exception('Video still processing (ID: ' . $targetVideoId . '). Please refresh video library and retry.');
                    }
                    $targetVideoIds[] = $targetVideoId;
                }
                logSmartPlus("Target video IDs for cover batch: " . json_encode($targetVideoIds));

                // Batch fetch cover images for ALL videos at once (more efficient)
                $coverMap = batchGetVideoCoverImages($targetVideoIds, $targetAdvertiserId, $accessToken);
                logSmartPlus("Cover map result: " . json_encode($coverMap));

                // Build creative list with cover images
                $creativeList = [];
                foreach ($campaignConfig['creatives'] ?? [] as $creative) {
                    $sourceVideoId = $creative['video_id'];
                    logSmartPlus("Processing source video: $sourceVideoId");

                    // Get target video ID from mapping
                    $targetVideoId = $videoMapping[$sourceVideoId] ?? $sourceVideoId;
                    logSmartPlus("Target video ID (after mapping): $targetVideoId");

                    // Get cover image from batch result
                    $coverImageId = $coverMap[$targetVideoId] ?? null;

                    // If no cover from batch, try direct fetch as fallback
                    if (empty($coverImageId)) {
                        logSmartPlus("No cover in batch for $targetVideoId, trying direct fetch");
                        $coverImageId = getVideoCoverImage($targetVideoId, $targetAdvertiserId, $accessToken);
                    }

                    // If still no cover, this is a critical error
                    if (empty($coverImageId)) {
                        throw new Exception("Video cover image required for video $targetVideoId. Please ensure the video has a cover or upload an image to the account's media library.");
                    }

                    logSmartPlus("Using cover image for $targetVideoId: $coverImageId");

                    $creativeInfo = [
                        'video_info' => ['video_id' => $targetVideoId],
                        'ad_format' => 'SINGLE_VIDEO',
                        'image_info' => [['web_uri' => $coverImageId]]  // Always include image_info
                    ];

                    // For BC_AUTH_TT, add identity info to each creative_info
                    if ($identityType === 'BC_AUTH_TT' && !empty($identityId)) {
                        $creativeInfo['identity_id'] = $identityId;
                        $creativeInfo['identity_type'] = 'BC_AUTH_TT';
                        if (!empty($identityAuthorizedBcId)) {
                            // TikTok API expects 'identity_bc_id' for Smart+ ads
                            $creativeInfo['identity_bc_id'] = $identityAuthorizedBcId;
                            $creativeInfo['identity_authorized_bc_id'] = $identityAuthorizedBcId;
                        }
                    }

                    $creativeList[] = ['creative_info' => $creativeInfo];
                }

                // Check that CTA portfolio was created earlier
                if (!$ctaPortfolioId) {
                    throw new Exception('CTA portfolio not available for this account');
                }

                // Build ad text list
                $adTextList = [];
                foreach ($campaignConfig['ad_texts'] ?? [] as $text) {
                    $adTextList[] = ['ad_text' => $text];
                }
                if (empty($adTextList)) {
                    $adTextList[] = ['ad_text' => 'Check it out!'];
                }

                // Build landing page URL list (use account-specific URL if provided, otherwise campaign default)
                $landingPageList = [];
                $effectiveLandingUrl = !empty($accountLandingPageUrl) ? $accountLandingPageUrl : ($campaignConfig['landing_page_url'] ?? null);
                if (!empty($effectiveLandingUrl)) {
                    $landingPageList[] = ['landing_page_url' => $effectiveLandingUrl];
                    logSmartPlus("Using landing page URL: $effectiveLandingUrl" . (!empty($accountLandingPageUrl) ? " (account override)" : " (campaign default)"));
                }

                // Build ad_configuration
                // For BC_AUTH_TT, identity goes in creative_info (added above), not here
                $adConfig = [
                    'call_to_action_id' => $ctaPortfolioId
                ];

                // Only add identity to ad_configuration for CUSTOMIZED_USER
                if ($identityType === 'CUSTOMIZED_USER' && !empty($identityId)) {
                    $adConfig['identity_id'] = $identityId;
                    $adConfig['identity_type'] = 'CUSTOMIZED_USER';
                } elseif ($identityType === 'BC_AUTH_TT') {
                    logSmartPlus("BC_AUTH_TT identity added to creative_info (not ad_configuration)");
                }

                $adParams = [
                    'advertiser_id' => $targetAdvertiserId,
                    'adgroup_id' => $adgroupId,
                    'ad_name' => $campaignName . ' - Ad',
                    'creative_list' => $creativeList,
                    'landing_page_url_list' => $landingPageList,
                    'ad_text_list' => $adTextList,
                    'ad_configuration' => $adConfig
                ];

                // Per TikTok docs: when using call_to_action_id (portfolio), call_to_action_list should NOT be passed.
                logSmartPlus("Using call_to_action_id in ad_configuration (portfolio: $ctaPortfolioId). NOT sending call_to_action_list per TikTok API docs.");

                $adResult = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

                if ($adResult['code'] != 0 || !isset($adResult['data']['smart_plus_ad_id'])) {
                    throw new Exception('Ad creation failed: ' . ($adResult['message'] ?? 'Unknown error'));
                }

                $adId = $adResult['data']['smart_plus_ad_id'];
                logSmartPlus("Ad created: $adId");

                // Campaign was created as DISABLED in step 1, no need to disable again
                logSmartPlus("Campaign already DISABLED - no post-creation disable needed");

                // Success!
                $results['success'][] = [
                    'advertiser_id' => $targetAdvertiserId,
                    'advertiser_name' => $accountName,
                    'campaign_name' => $campaignName,
                    'copy_number' => $copyNum,
                    'campaign_id' => $campaignId,
                    'adgroup_id' => $adgroupId,
                    'ad_id' => $adId
                ];

                // Small delay between duplicate creations
                if ($copyNum < $duplicateCount) {
                    usleep(100000); // 100ms between copies
                }

            } catch (Exception $e) {
                logSmartPlus("ERROR for $targetAdvertiserId (copy $copyNum): " . $e->getMessage());
                $results['failed'][] = [
                    'advertiser_id' => $targetAdvertiserId,
                    'advertiser_name' => $accountName,
                    'campaign_name' => $campaignName,
                    'copy_number' => $copyNum,
                    'error' => $e->getMessage()
                ];
            }
            } // End of duplicate loop (for $copyNum)
        } // End of accounts loop

        // Summary
        $results['success_count'] = count($results['success']);
        $results['failed_count'] = count($results['failed']);

        logSmartPlus("=== BULK LAUNCH COMPLETE ===");
        logSmartPlus("Success: " . $results['success_count'] . ", Failed: " . $results['failed_count']);

        echo json_encode([
            'success' => true,
            'data' => $results
        ]);
        break;

    // ==========================================
    // BULK DUPLICATE CAMPAIGN (Smart+ API)
    // Duplicates a campaign to a target account using Smart+ endpoints
    // Called once per target account from frontend loop
    // ==========================================
    case 'bulk_duplicate_smartplus':
        $data = $input;

        logSmartPlus("============ BULK DUPLICATE SMART+ ============");
        logSmartPlus("Target Advertiser: " . $advertiserId);
        logSmartPlus("Request Data: " . json_encode($data, JSON_PRETTY_PRINT));

        // Validate required fields
        $campaignName = $data['campaign_name'] ?? '';
        if (empty($campaignName)) {
            echo json_encode(['success' => false, 'message' => 'Campaign name is required']);
            exit;
        }

        $budget = floatval($data['budget'] ?? 50);
        if ($budget < 20) $budget = 50;

        $pixelId = $data['pixel_id'] ?? null;
        $identityId = $data['identity_id'] ?? null;
        $identityType = $data['identity_type'] ?? 'CUSTOMIZED_USER';
        $identityAuthorizedBcId = $data['identity_authorized_bc_id'] ?? null;
        $videoIds = $data['video_ids'] ?? [];
        $landingPageUrl = $data['landing_page_url'] ?? '';
        $adTexts = $data['ad_texts'] ?? [];
        $adName = $data['ad_name'] ?? $campaignName . ' - Ad';

        if (empty($videoIds)) {
            echo json_encode(['success' => false, 'message' => 'At least one video is required']);
            exit;
        }
        if (empty($identityId)) {
            echo json_encode(['success' => false, 'message' => 'Identity is required']);
            exit;
        }
        if (empty($landingPageUrl)) {
            echo json_encode(['success' => false, 'message' => 'Landing page URL is required']);
            exit;
        }

        try {
            // ---- 1. CTA PORTFOLIO ----
            $ctaPortfolioId = null;
            $selectedPortfolioId = $data['portfolio_id'] ?? null;

            require_once __DIR__ . '/database/Database.php';
            $db = Database::getInstance();

            if (!empty($selectedPortfolioId) && $selectedPortfolioId !== 'auto_create') {
                $ctaPortfolioId = $selectedPortfolioId;
                logSmartPlus("Using user-selected portfolio: $ctaPortfolioId");
            } else {
                // Check DB for existing portfolio
                $existingPortfolio = $db->fetchOne(
                    "SELECT creative_portfolio_id FROM tool_portfolios
                     WHERE advertiser_id = :advertiser_id
                     AND portfolio_type = 'CTA'
                     ORDER BY created_at DESC LIMIT 1",
                    ['advertiser_id' => $advertiserId]
                );

                if ($existingPortfolio) {
                    $ctaPortfolioId = $existingPortfolio['creative_portfolio_id'];
                    logSmartPlus("Using existing portfolio from DB: $ctaPortfolioId");
                } else {
                    // Auto-create CTA portfolio - default to LEARN_MORE only
                    logSmartPlus("Creating new CTA portfolio for account: $advertiserId (LEARN_MORE only)");
                    $frequentlyUsedCTAs = [
                        ['asset_content' => 'LEARN_MORE', 'asset_ids' => ["0"]]
                    ];

                    $portfolioResult = makeApiCall('/creative/portfolio/create/', [
                        'advertiser_id' => $advertiserId,
                        'creative_portfolio_type' => 'CTA',
                        'portfolio_name' => 'Auto-Generated CTAs',
                        'portfolio_content' => $frequentlyUsedCTAs
                    ], $accessToken);

                    if ($portfolioResult['code'] == 0 && isset($portfolioResult['data']['creative_portfolio_id'])) {
                        $ctaPortfolioId = $portfolioResult['data']['creative_portfolio_id'];
                        logSmartPlus("Portfolio created: $ctaPortfolioId");

                        $db->insert('tool_portfolios', [
                            'advertiser_id' => $advertiserId,
                            'creative_portfolio_id' => $ctaPortfolioId,
                            'portfolio_name' => 'Auto-Generated CTAs',
                            'portfolio_type' => 'CTA',
                            'portfolio_content' => json_encode($frequentlyUsedCTAs),
                            'created_by_tool' => 1
                        ]);
                    } else {
                        logSmartPlus("Portfolio creation failed: " . json_encode($portfolioResult));
                    }
                }
            }

            // Fallback: try TikTok API
            if (empty($ctaPortfolioId)) {
                $portfolioListResult = makeApiCall('/creative/portfolio/list/', [
                    'advertiser_id' => $advertiserId,
                    'page' => 1,
                    'page_size' => 10
                ], $accessToken, 'GET');

                if ($portfolioListResult['code'] == 0 && !empty($portfolioListResult['data']['portfolios'])) {
                    foreach ($portfolioListResult['data']['portfolios'] as $portfolio) {
                        if (isset($portfolio['creative_portfolio_type']) && $portfolio['creative_portfolio_type'] === 'CTA') {
                            $ctaPortfolioId = $portfolio['creative_portfolio_id'];
                            logSmartPlus("Found existing CTA portfolio from API: $ctaPortfolioId");
                            break;
                        }
                    }
                }
            }

            if (empty($ctaPortfolioId)) {
                throw new Exception('CTA portfolio not available. Please try again.');
            }

            // ---- 2. CREATE SMART+ CAMPAIGN ----
            $campaignParams = [
                'advertiser_id' => $advertiserId,
                'campaign_name' => $campaignName,
                'objective_type' => 'LEAD_GENERATION',
                'request_id' => generateRequestId(),
                'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',
                'budget' => $budget,
                'budget_optimize_on' => true,
                'operation_status' => 'DISABLE'
            ];

            logSmartPlus("Creating Smart+ campaign: " . json_encode($campaignParams));
            $campaignResult = makeApiCall('/smart_plus/campaign/create/', $campaignParams, $accessToken);

            if ($campaignResult['code'] != 0 || !isset($campaignResult['data']['campaign_id'])) {
                throw new Exception('Campaign creation failed: ' . ($campaignResult['message'] ?? 'Unknown error'));
            }

            $newCampaignId = $campaignResult['data']['campaign_id'];
            logSmartPlus("Smart+ Campaign created (DISABLED): $newCampaignId");

            // ---- 3. CREATE SMART+ AD GROUP ----
            $scheduleType = $data['schedule_type'] ?? 'start_now';
            $scheduleStart = null;
            $scheduleEnd = null;
            $dupBulkTimezone = $data['user_timezone'] ?? 'America/New_York';

            if ($scheduleType === 'start_now' || $scheduleType === 'SCHEDULE_FROM_NOW') {
                $scheduleType = 'SCHEDULE_FROM_NOW';
                $scheduleStart = getUTCDateTime('+5 minutes');
            } else {
                if (!empty($data['schedule_start'])) {
                    $scheduleStart = convertESTtoUTC(date('Y-m-d H:i:s', strtotime($data['schedule_start'])), $dupBulkTimezone);
                } else {
                    $scheduleStart = getUTCDateTime('+5 minutes');
                }
                if (!empty($data['schedule_end'])) {
                    $scheduleEnd = convertESTtoUTC(date('Y-m-d H:i:s', strtotime($data['schedule_end'])), $dupBulkTimezone);
                    $scheduleType = 'SCHEDULE_START_END';
                } else {
                    $scheduleType = 'SCHEDULE_FROM_NOW';
                }
            }

            $adgroupParams = [
                'advertiser_id' => $advertiserId,
                'request_id' => generateRequestId(),
                'campaign_id' => $newCampaignId,
                'adgroup_name' => $campaignName . ' - Ad Group',
                'promotion_type' => 'LEAD_GENERATION',
                'promotion_target_type' => 'EXTERNAL_WEBSITE',
                'optimization_goal' => 'CONVERT',
                'billing_event' => 'OCPM',
                'bid_type' => 'BID_TYPE_NO_BID',
                'schedule_type' => $scheduleType,
                'schedule_start_time' => $scheduleStart,
                'operation_status' => 'ENABLE',
                'budget_mode' => 'BUDGET_MODE_INFINITE',
                'targeting_spec' => [
                    'location_ids' => ['6252001'],
                    'age_groups' => ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100']
                ]
            ];

            if ($scheduleType === 'SCHEDULE_START_END' && $scheduleEnd) {
                $adgroupParams['schedule_end_time'] = $scheduleEnd;
            }

            if (!empty($pixelId)) {
                $adgroupParams['pixel_id'] = $pixelId;
                $adgroupParams['optimization_event'] = $data['optimization_event'] ?? 'FORM';
            }

            // Add dayparting if provided (carried over from original campaign)
            if (!empty($data['dayparting'])) {
                $adgroupParams['dayparting'] = $data['dayparting'];
                logSmartPlus("Adding dayparting from original campaign");
            }

            logSmartPlus("Creating Smart+ ad group: " . json_encode($adgroupParams));
            $adgroupResult = makeApiCall('/smart_plus/adgroup/create/', $adgroupParams, $accessToken);

            if ($adgroupResult['code'] != 0 || !isset($adgroupResult['data']['adgroup_id'])) {
                throw new Exception('Ad Group creation failed: ' . ($adgroupResult['message'] ?? 'Unknown error'));
            }

            $newAdgroupId = $adgroupResult['data']['adgroup_id'];
            logSmartPlus("Smart+ Ad Group created: $newAdgroupId");

            // ---- 4. FETCH COVER IMAGES ----
            // Reject any processing_ temporary IDs
            foreach ($videoIds as $vid) {
                if (strpos($vid, 'processing_') === 0) {
                    throw new Exception('Video still processing (ID: ' . $vid . '). Please refresh and retry.');
                }
            }

            $coverMap = batchGetVideoCoverImages($videoIds, $advertiserId, $accessToken);
            logSmartPlus("Cover map: " . json_encode($coverMap));

            // ---- 5. CREATE SMART+ AD ----
            $creativeList = [];
            foreach ($videoIds as $videoId) {
                $coverImageId = $coverMap[$videoId] ?? null;

                // Fallback: direct fetch
                if (empty($coverImageId)) {
                    logSmartPlus("No cover in batch for $videoId, trying direct fetch");
                    $singleCoverMap = batchGetVideoCoverImages([$videoId], $advertiserId, $accessToken);
                    $coverImageId = $singleCoverMap[$videoId] ?? null;
                }

                if (empty($coverImageId)) {
                    throw new Exception("Video cover image required for video $videoId. Please ensure the video has a cover.");
                }

                $creativeInfo = [
                    'video_info' => ['video_id' => $videoId],
                    'ad_format' => 'SINGLE_VIDEO',
                    'image_info' => [['web_uri' => $coverImageId]]
                ];

                // For BC_AUTH_TT, add identity to each creative_info
                if ($identityType === 'BC_AUTH_TT' && !empty($identityId)) {
                    $creativeInfo['identity_id'] = $identityId;
                    $creativeInfo['identity_type'] = 'BC_AUTH_TT';
                    if (!empty($identityAuthorizedBcId)) {
                        $creativeInfo['identity_bc_id'] = $identityAuthorizedBcId;
                        $creativeInfo['identity_authorized_bc_id'] = $identityAuthorizedBcId;
                    }
                }

                $creativeList[] = ['creative_info' => $creativeInfo];
            }

            // Build ad text list (deduplicated)
            $adTextList = [];
            $uniqueTexts = [];
            foreach ($adTexts as $text) {
                $text = trim($text);
                if (!empty($text) && !in_array($text, $uniqueTexts)) {
                    $uniqueTexts[] = $text;
                    $adTextList[] = ['ad_text' => $text];
                }
            }
            if (empty($adTextList)) {
                $adTextList[] = ['ad_text' => 'Check it out!'];
            }

            // Build landing page URL list
            $landingPageList = [['landing_page_url' => $landingPageUrl]];

            // Build ad_configuration
            $adConfig = [
                'call_to_action_id' => $ctaPortfolioId
            ];

            if ($identityType === 'CUSTOMIZED_USER' && !empty($identityId)) {
                $adConfig['identity_id'] = $identityId;
                $adConfig['identity_type'] = 'CUSTOMIZED_USER';
            }

            $adParams = [
                'advertiser_id' => $advertiserId,
                'adgroup_id' => $newAdgroupId,
                'ad_name' => $adName,
                'creative_list' => $creativeList,
                'landing_page_url_list' => $landingPageList,
                'ad_text_list' => $adTextList,
                'ad_configuration' => $adConfig
            ];

            // Per TikTok docs: when using call_to_action_id (portfolio), call_to_action_list should NOT be passed.
            logSmartPlus("Using call_to_action_id in ad_configuration (portfolio: $ctaPortfolioId). NOT sending call_to_action_list per TikTok API docs.");

            logSmartPlus("Creating Smart+ ad: " . json_encode($adParams));
            $adResult = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

            if ($adResult['code'] != 0 || !isset($adResult['data']['smart_plus_ad_id'])) {
                throw new Exception('Ad creation failed: ' . ($adResult['message'] ?? 'Unknown error'));
            }

            $newAdId = $adResult['data']['smart_plus_ad_id'];
            logSmartPlus("Smart+ Ad created: $newAdId");

            echo json_encode([
                'success' => true,
                'data' => [
                    'campaign_id' => $newCampaignId,
                    'adgroup_id' => $newAdgroupId,
                    'ad_id' => $newAdId
                ],
                'message' => 'Smart+ campaign duplicated successfully'
            ]);

        } catch (Exception $e) {
            logSmartPlus("BULK DUPLICATE ERROR: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        break;

    // ==========================================
    // UPDATE SMART+ CAMPAIGN
    // POST /open_api/v1.3/smart_plus/campaign/update/
    // ==========================================
    case 'update_smartplus_campaign':
        $data = $input;

        logSmartPlus("=== UPDATING SMART+ CAMPAIGN ===");

        if (empty($data['campaign_id'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
            exit;
        }

        $updateParams = [
            'advertiser_id' => $advertiserId,
            'campaign_id' => $data['campaign_id']
        ];

        // Add optional update fields
        if (!empty($data['campaign_name'])) {
            $updateParams['campaign_name'] = $data['campaign_name'];
        }
        if (!empty($data['budget'])) {
            $updateParams['budget'] = floatval($data['budget']);
        }

        logSmartPlus("Update params: " . json_encode($updateParams));

        $result = makeApiCall('/smart_plus/campaign/update/', $updateParams, $accessToken);
        logSmartPlus("Update response: " . json_encode($result));

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'campaign_id' => $data['campaign_id'],
                'message' => 'Campaign updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update campaign: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // UPDATE SMART+ AD GROUP
    // POST /open_api/v1.3/smart_plus/adgroup/update/
    // NOTE: pixel_id and optimization_event CANNOT be updated!
    // ==========================================
    case 'update_smartplus_adgroup':
        $data = $input;

        logSmartPlus("=== UPDATING SMART+ AD GROUP ===");

        if (empty($data['adgroup_id'])) {
            echo json_encode(['success' => false, 'message' => 'AdGroup ID is required']);
            exit;
        }

        $updateParams = [
            'advertiser_id' => $advertiserId,
            'adgroup_id' => $data['adgroup_id']
        ];

        // Add optional update fields (NOTE: pixel_id cannot be updated!)
        if (!empty($data['adgroup_name'])) {
            $updateParams['adgroup_name'] = $data['adgroup_name'];
        }
        if (!empty($data['budget'])) {
            $updateParams['budget'] = floatval($data['budget']);
        }
        if (!empty($data['dayparting'])) {
            $updateParams['dayparting'] = $data['dayparting'];
        }
        if (!empty($data['targeting_spec'])) {
            $updateParams['targeting_spec'] = $data['targeting_spec'];
        }
        if (!empty($data['schedule_start_time'])) {
            $updateParams['schedule_start_time'] = $data['schedule_start_time'];
        }
        if (!empty($data['schedule_end_time'])) {
            $updateParams['schedule_end_time'] = $data['schedule_end_time'];
        }
        if (!empty($data['conversion_bid_price'])) {
            $updateParams['conversion_bid_price'] = floatval($data['conversion_bid_price']);
        }

        logSmartPlus("Update params: " . json_encode($updateParams));

        $result = makeApiCall('/smart_plus/adgroup/update/', $updateParams, $accessToken);
        logSmartPlus("Update response: " . json_encode($result));

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'adgroup_id' => $data['adgroup_id'],
                'message' => 'Ad Group updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update ad group: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // UPDATE SMART+ AD
    // POST /open_api/v1.3/smart_plus/ad/update/
    // ==========================================
    case 'update_smartplus_ad':
        $data = $input;

        logSmartPlus("=== UPDATING SMART+ AD ===");

        if (empty($data['smart_plus_ad_id'])) {
            echo json_encode(['success' => false, 'message' => 'Smart+ Ad ID is required']);
            exit;
        }

        $updateParams = [
            'advertiser_id' => $advertiserId,
            'smart_plus_ad_id' => $data['smart_plus_ad_id']
        ];

        // Add optional update fields
        if (!empty($data['ad_name'])) {
            $updateParams['ad_name'] = $data['ad_name'];
        }
        if (!empty($data['ad_text_list'])) {
            $updateParams['ad_text_list'] = $data['ad_text_list'];
        }
        if (!empty($data['creative_list'])) {
            $updateParams['creative_list'] = $data['creative_list'];
        }
        if (!empty($data['landing_page_url_list'])) {
            $updateParams['landing_page_url_list'] = $data['landing_page_url_list'];
        }
        if (!empty($data['ad_configuration'])) {
            $updateParams['ad_configuration'] = $data['ad_configuration'];
        }

        logSmartPlus("Update params: " . json_encode($updateParams));

        $result = makeApiCall('/smart_plus/ad/update/', $updateParams, $accessToken);
        logSmartPlus("Update response: " . json_encode($result));

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'smart_plus_ad_id' => $data['smart_plus_ad_id'],
                'message' => 'Ad updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update ad: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // DELETE SMART+ AD GROUP
    // Used when pixel/optimization_event needs to change
    // ==========================================
    case 'delete_smartplus_adgroup':
        $data = $input;

        logSmartPlus("=== DELETING SMART+ AD GROUP ===");

        if (empty($data['adgroup_id'])) {
            echo json_encode(['success' => false, 'message' => 'AdGroup ID is required']);
            exit;
        }

        // Use the adgroup status update endpoint with DELETE
        $result = makeApiCall('/adgroup/status/update/', [
            'advertiser_id' => $advertiserId,
            'adgroup_ids' => [$data['adgroup_id']],
            'operation_status' => 'DELETE'
        ], $accessToken);

        logSmartPlus("Delete response: " . json_encode($result));

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'adgroup_id' => $data['adgroup_id'],
                'message' => 'Ad Group deleted successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to delete ad group: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // ENABLE SMART+ CAMPAIGN
    // Used when user wants to launch the campaign
    // ==========================================
    case 'enable_smartplus_campaign':
        $data = $input;

        logSmartPlus("=== ENABLING SMART+ CAMPAIGN ===");

        if (empty($data['campaign_id'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
            exit;
        }

        // Use the campaign status update endpoint
        $result = makeApiCall('/campaign/status/update/', [
            'advertiser_id' => $advertiserId,
            'campaign_ids' => [$data['campaign_id']],
            'operation_status' => 'ENABLE'
        ], $accessToken);

        logSmartPlus("Enable response: " . json_encode($result));

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'campaign_id' => $data['campaign_id'],
                'message' => 'Campaign enabled successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to enable campaign: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // GET CAMPAIGNS LIST
    // Fetches all campaigns for the current advertiser
    // ==========================================
    case 'get_campaigns':
        logSmartPlus("=== FETCHING CAMPAIGNS LIST ===");
        logSmartPlus("Advertiser ID: $advertiserId");

        // Build request params
        $params = [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 100
        ];

        // Optional filtering by status
        $statusFilter = $input['status_filter'] ?? null;
        if ($statusFilter && in_array($statusFilter, ['ENABLE', 'DISABLE'])) {
            $params['filtering'] = json_encode([
                'operation_status' => $statusFilter
            ]);
        }

        // Call TikTok API to get campaigns
        $result = makeApiCall('/campaign/get/', $params, $accessToken, 'GET');

        logSmartPlus("Get campaigns response code: " . ($result['code'] ?? 'null'));

        if ($result['code'] == 0) {
            $campaigns = $result['data']['list'] ?? [];
            $pageInfo = $result['data']['page_info'] ?? [];

            // Log campaign count
            logSmartPlus("Found " . count($campaigns) . " campaigns");

            // Format campaigns for frontend
            $formattedCampaigns = [];
            foreach ($campaigns as $campaign) {
                $formattedCampaigns[] = [
                    'campaign_id' => $campaign['campaign_id'] ?? '',
                    'campaign_name' => $campaign['campaign_name'] ?? 'Unnamed Campaign',
                    'operation_status' => $campaign['operation_status'] ?? 'UNKNOWN',
                    'budget' => $campaign['budget'] ?? 0,
                    'budget_mode' => $campaign['budget_mode'] ?? '',
                    'objective_type' => $campaign['objective_type'] ?? '',
                    'is_smart_performance_campaign' => $campaign['is_smart_performance_campaign'] ?? false,
                    'create_time' => $campaign['create_time'] ?? '',
                    'modify_time' => $campaign['modify_time'] ?? ''
                ];
            }

            echo json_encode([
                'success' => true,
                'campaigns' => $formattedCampaigns,
                'total_count' => $pageInfo['total_number'] ?? count($formattedCampaigns),
                'page' => $pageInfo['page'] ?? 1,
                'page_size' => $pageInfo['page_size'] ?? 100
            ]);
        } else {
            logSmartPlus("Failed to get campaigns: " . ($result['message'] ?? 'Unknown error'));
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch campaigns: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'campaigns' => []
            ]);
        }
        break;

    // ==========================================
    // GET CAMPAIGNS WITH METRICS (for expandable view)
    // ==========================================
    case 'get_campaigns_with_metrics':
        logSmartPlus("=== FETCHING CAMPAIGNS WITH METRICS ===");
        logSmartPlus("Advertiser ID: $advertiserId");

        // Get date range from request (default to today)
        $startDate = $input['start_date'] ?? date('Y-m-d');
        $endDate = $input['end_date'] ?? date('Y-m-d');
        logSmartPlus("Date range: $startDate to $endDate");

        // Build request params for campaigns
        $params = [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 100
        ];

        // Call TikTok API to get campaigns
        $result = makeApiCall('/campaign/get/', $params, $accessToken, 'GET');

        if ($result['code'] != 0) {
            logSmartPlus("Failed to get campaigns: " . ($result['message'] ?? 'Unknown error'));
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch campaigns: ' . ($result['message'] ?? 'Unknown error'),
                'campaigns' => []
            ]);
            break;
        }

        $campaigns = $result['data']['list'] ?? [];
        logSmartPlus("Found " . count($campaigns) . " campaigns");

        // Now fetch metrics for these campaigns using integrated reports
        $campaignIds = array_column($campaigns, 'campaign_id');

        $metricsData = [];
        if (!empty($campaignIds)) {
            // Build filters array in TikTok's required format
            $filters = [
                [
                    'field_name' => 'campaign_ids',
                    'filter_type' => 'IN',
                    'filter_value' => json_encode($campaignIds)
                ]
            ];

            $reportParams = [
                'advertiser_id' => $advertiserId,
                'report_type' => 'BASIC',
                'dimensions' => json_encode(['campaign_id']),
                'metrics' => json_encode([
                    'spend', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm',
                    'conversion', 'cost_per_conversion', 'conversion_rate'
                ]),
                'data_level' => 'AUCTION_CAMPAIGN',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'page' => 1,
                'page_size' => 100,
                'filters' => json_encode($filters)
            ];

            logSmartPlus("Report params: " . json_encode($reportParams));
            $reportResult = makeApiCall('/report/integrated/get/', $reportParams, $accessToken, 'GET');
            logSmartPlus("Report result code: " . ($reportResult['code'] ?? 'null'));
            logSmartPlus("Report result: " . json_encode($reportResult));

            if ($reportResult['code'] == 0) {
                $reportData = $reportResult['data']['list'] ?? [];
                foreach ($reportData as $row) {
                    $cid = $row['dimensions']['campaign_id'] ?? null;
                    if ($cid) {
                        $metricsData[$cid] = $row['metrics'] ?? [];
                    }
                }
                logSmartPlus("Got metrics for " . count($metricsData) . " campaigns");
            } else {
                logSmartPlus("Failed to get campaign metrics: " . ($reportResult['message'] ?? 'Unknown error'));
                logSmartPlus("Error details: " . json_encode($reportResult));
            }
        }

        // Format campaigns with metrics
        $formattedCampaigns = [];
        foreach ($campaigns as $campaign) {
            $cid = $campaign['campaign_id'] ?? '';
            $metrics = $metricsData[$cid] ?? [];

            $formattedCampaigns[] = [
                'campaign_id' => $cid,
                'campaign_name' => $campaign['campaign_name'] ?? 'Unnamed Campaign',
                'operation_status' => $campaign['operation_status'] ?? 'UNKNOWN',
                'budget' => $campaign['budget'] ?? 0,
                'budget_mode' => $campaign['budget_mode'] ?? '',
                'objective_type' => $campaign['objective_type'] ?? '',
                'is_smart_performance_campaign' => $campaign['is_smart_performance_campaign'] ?? false,
                'create_time' => $campaign['create_time'] ?? '',
                'modify_time' => $campaign['modify_time'] ?? '',
                // Metrics - use string values from API
                'spend' => $metrics['spend'] ?? '0.00',
                'impressions' => $metrics['impressions'] ?? '0',
                'clicks' => $metrics['clicks'] ?? '0',
                'ctr' => $metrics['ctr'] ?? '0.00',
                'cpc' => $metrics['cpc'] ?? '0.00',
                'cpm' => $metrics['cpm'] ?? '0.00',
                'conversions' => $metrics['conversion'] ?? '0',
                'cost_per_conversion' => $metrics['cost_per_conversion'] ?? '0.00',
                'conversion_rate' => $metrics['conversion_rate'] ?? '0.00',
                'results' => $metrics['conversion'] ?? '0',  // Use conversion as results for now
                'cost_per_result' => $metrics['cost_per_conversion'] ?? '0.00'
            ];
        }

        echo json_encode([
            'success' => true,
            'campaigns' => $formattedCampaigns,
            'total_count' => count($formattedCampaigns),
            'debug_metrics_count' => count($metricsData),
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ]);
        break;

    // ==========================================
    // GET AD GROUPS FOR CAMPAIGN (for expansion)
    // ==========================================
    case 'get_adgroups_for_campaign':
        $campaignId = $input['campaign_id'] ?? null;
        // Get date range from request (inherit from campaigns view)
        $startDate = $input['start_date'] ?? date('Y-m-d');
        $endDate = $input['end_date'] ?? date('Y-m-d');

        if (!$campaignId) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
            break;
        }

        logSmartPlus("=== FETCHING AD GROUPS FOR CAMPAIGN $campaignId ===");
        logSmartPlus("Date range: $startDate to $endDate");

        // Get ad groups
        $adgroupParams = [
            'advertiser_id' => $advertiserId,
            'filtering' => json_encode(['campaign_ids' => [$campaignId]]),
            'page' => 1,
            'page_size' => 100
        ];

        $adgroupResult = makeApiCall('/adgroup/get/', $adgroupParams, $accessToken, 'GET');

        if ($adgroupResult['code'] != 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch ad groups: ' . ($adgroupResult['message'] ?? 'Unknown error'),
                'adgroups' => []
            ]);
            break;
        }

        $adgroups = $adgroupResult['data']['list'] ?? [];
        logSmartPlus("Found " . count($adgroups) . " ad groups");

        // Get metrics for ad groups
        $adgroupIds = array_column($adgroups, 'adgroup_id');
        $metricsData = [];

        if (!empty($adgroupIds)) {
            // Use date range from request (already set above)
            // Build filters array in TikTok's required format
            $filters = [
                [
                    'field_name' => 'adgroup_ids',
                    'filter_type' => 'IN',
                    'filter_value' => json_encode($adgroupIds)
                ]
            ];

            $reportParams = [
                'advertiser_id' => $advertiserId,
                'report_type' => 'BASIC',
                'dimensions' => json_encode(['adgroup_id']),
                'metrics' => json_encode([
                    'spend', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm',
                    'conversion', 'cost_per_conversion', 'conversion_rate'
                ]),
                'data_level' => 'AUCTION_ADGROUP',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'page' => 1,
                'page_size' => 100,
                'filters' => json_encode($filters)
            ];

            $reportResult = makeApiCall('/report/integrated/get/', $reportParams, $accessToken, 'GET');

            if ($reportResult['code'] == 0) {
                $reportData = $reportResult['data']['list'] ?? [];
                foreach ($reportData as $row) {
                    $aid = $row['dimensions']['adgroup_id'] ?? null;
                    if ($aid) {
                        $metricsData[$aid] = $row['metrics'] ?? [];
                    }
                }
            }
        }

        // Format ad groups with metrics
        $formattedAdgroups = [];
        foreach ($adgroups as $adgroup) {
            $aid = $adgroup['adgroup_id'] ?? '';
            $metrics = $metricsData[$aid] ?? [];

            $formattedAdgroups[] = [
                'adgroup_id' => $aid,
                'adgroup_name' => $adgroup['adgroup_name'] ?? 'Unnamed Ad Group',
                'operation_status' => $adgroup['operation_status'] ?? 'UNKNOWN',
                'budget' => $adgroup['budget'] ?? 0,
                'bid' => $adgroup['bid'] ?? null,
                'schedule_type' => $adgroup['schedule_type'] ?? '',
                'schedule_start_time' => $adgroup['schedule_start_time'] ?? '',
                'schedule_end_time' => $adgroup['schedule_end_time'] ?? '',
                // Metrics
                'spend' => $metrics['spend'] ?? '0.00',
                'impressions' => $metrics['impressions'] ?? '0',
                'clicks' => $metrics['clicks'] ?? '0',
                'ctr' => $metrics['ctr'] ?? '0.00',
                'cpc' => $metrics['cpc'] ?? '0.00',
                'cpm' => $metrics['cpm'] ?? '0.00',
                'conversions' => $metrics['conversion'] ?? '0',
                'cost_per_conversion' => $metrics['cost_per_conversion'] ?? '0.00',
                'conversion_rate' => $metrics['conversion_rate'] ?? '0.00',
                'results' => $metrics['conversion'] ?? '0',
                'cost_per_result' => $metrics['cost_per_conversion'] ?? '0.00'
            ];
        }

        echo json_encode([
            'success' => true,
            'adgroups' => $formattedAdgroups,
            'campaign_id' => $campaignId
        ]);
        break;

    // ==========================================
    // GET ADS FOR AD GROUP (for expansion)
    // ==========================================
    case 'get_ads_for_adgroup':
        $adgroupId = $input['adgroup_id'] ?? null;
        // Get date range from request (inherit from campaigns view)
        $startDate = $input['start_date'] ?? date('Y-m-d');
        $endDate = $input['end_date'] ?? date('Y-m-d');

        if (!$adgroupId) {
            echo json_encode(['success' => false, 'message' => 'Ad Group ID is required']);
            break;
        }

        logSmartPlus("=== FETCHING ADS FOR AD GROUP $adgroupId ===");
        logSmartPlus("Date range: $startDate to $endDate");

        // Get ads
        // Note: /ad/get/ returns all fields by default including primary_status,
        // secondary_status, reject_reason. Do NOT pass a 'fields' parameter as it
        // may cause the API to reject unrecognized field names.
        $adParams = [
            'advertiser_id' => $advertiserId,
            'filtering' => json_encode(['adgroup_ids' => [$adgroupId]]),
            'page' => 1,
            'page_size' => 100
        ];

        $adResult = makeApiCall('/ad/get/', $adParams, $accessToken, 'GET');

        if ($adResult['code'] != 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch ads: ' . ($adResult['message'] ?? 'Unknown error'),
                'ads' => []
            ]);
            break;
        }

        $ads = $adResult['data']['list'] ?? [];
        logSmartPlus("Found " . count($ads) . " ads");
        if (!empty($ads)) {
            $sampleAd = $ads[0];
            logSmartPlus("Sample ad keys: " . implode(', ', array_keys($sampleAd)));
            logSmartPlus("Sample ad primary_status: " . ($sampleAd['primary_status'] ?? 'NOT_PRESENT'));
        }

        // Get metrics for ads
        $adIds = array_column($ads, 'ad_id');
        $metricsData = [];

        if (!empty($adIds)) {
            // Use date range from request (already set above)
            // Build filters array in TikTok's required format
            $filters = [
                [
                    'field_name' => 'ad_ids',
                    'filter_type' => 'IN',
                    'filter_value' => json_encode($adIds)
                ]
            ];

            $reportParams = [
                'advertiser_id' => $advertiserId,
                'report_type' => 'BASIC',
                'dimensions' => json_encode(['ad_id']),
                'metrics' => json_encode([
                    'spend', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm',
                    'conversion', 'cost_per_conversion', 'conversion_rate'
                ]),
                'data_level' => 'AUCTION_AD',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'page' => 1,
                'page_size' => 100,
                'filters' => json_encode($filters)
            ];

            $reportResult = makeApiCall('/report/integrated/get/', $reportParams, $accessToken, 'GET');

            if ($reportResult['code'] == 0) {
                $reportData = $reportResult['data']['list'] ?? [];
                foreach ($reportData as $row) {
                    $adId = $row['dimensions']['ad_id'] ?? null;
                    if ($adId) {
                        $metricsData[$adId] = $row['metrics'] ?? [];
                    }
                }
            }
        }

        // Format ads with metrics
        $formattedAds = [];
        foreach ($ads as $ad) {
            $adId = $ad['ad_id'] ?? '';
            $metrics = $metricsData[$adId] ?? [];

            $formattedAds[] = [
                'ad_id' => $adId,
                'smart_plus_ad_id' => $ad['smart_plus_ad_id'] ?? '',
                'advertiser_id' => $advertiserId,
                'ad_name' => $ad['ad_name'] ?? 'Unnamed Ad',
                'operation_status' => $ad['operation_status'] ?? 'UNKNOWN',
                'ad_format' => $ad['ad_format'] ?? '',
                'primary_status' => $ad['primary_status'] ?? '',
                'secondary_status' => $ad['secondary_status'] ?? '',
                'reject_reason' => $ad['reject_reason'] ?? '',
                // Metrics
                'spend' => $metrics['spend'] ?? '0.00',
                'impressions' => $metrics['impressions'] ?? '0',
                'clicks' => $metrics['clicks'] ?? '0',
                'ctr' => $metrics['ctr'] ?? '0.00',
                'cpc' => $metrics['cpc'] ?? '0.00',
                'cpm' => $metrics['cpm'] ?? '0.00',
                'conversions' => $metrics['conversion'] ?? '0',
                'cost_per_conversion' => $metrics['cost_per_conversion'] ?? '0.00',
                'conversion_rate' => $metrics['conversion_rate'] ?? '0.00',
                'results' => $metrics['conversion'] ?? '0',
                'cost_per_result' => $metrics['cost_per_conversion'] ?? '0.00'
            ];
        }

        echo json_encode([
            'success' => true,
            'ads' => $formattedAds,
            'adgroup_id' => $adgroupId
        ]);
        break;

    // ==========================================
    // APPEAL REJECTED AD
    // ==========================================
    case 'appeal_ad':
        $adId = $input['ad_id'] ?? '';
        $appealReason = $input['appeal_reason'] ?? '';

        if (empty($adId) || empty($appealReason)) {
            echo json_encode(['success' => false, 'message' => 'ad_id and appeal_reason are required']);
            exit;
        }

        logSmartPlus("=== APPEALING SMART+ AD: $adId ===");
        logSmartPlus("Appeal reason: $appealReason");

        $result = makeApiCall('/smart_plus/ad/appeal/', [
            'advertiser_id' => $advertiserId,
            'smart_plus_ad_id' => $adId,
            'appeal_reason' => $appealReason
        ], $accessToken);

        logSmartPlus("Appeal response: " . json_encode($result));

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Appeal submitted successfully. TikTok will review within 24 hours.',
                'data' => $result['data'] ?? null
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to submit appeal',
                'code' => $result['code'] ?? -1
            ]);
        }
        break;

    // ==========================================
    // GET REJECTED ADS FOR ACCOUNT
    // ==========================================
    case 'get_rejected_ads':
        logSmartPlus("=== FETCHING REJECTED ADS (Smart+ Review Info) ===");
        logSmartPlus("Advertiser ID: $advertiserId");

        // Step 1: Get ALL ads for this advertiser to collect smart_plus_ad_ids
        $adParams = [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 100
        ];
        $adResult = makeApiCall('/ad/get/', $adParams, $accessToken, 'GET');

        if (!$adResult || ($adResult['code'] ?? -1) != 0) {
            logSmartPlus("Failed to fetch ads: " . json_encode($adResult));
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch ads: ' . ($adResult['message'] ?? 'Unknown error'),
                'ads' => [],
                'count' => 0
            ]);
            break;
        }

        $ads = $adResult['data']['list'] ?? [];
        logSmartPlus("Found " . count($ads) . " total ads");

        // Build map of smart_plus_ad_id -> ad info
        $smartPlusIds = [];
        $adMap = [];
        foreach ($ads as $ad) {
            $spId = $ad['smart_plus_ad_id'] ?? '';
            if ($spId) {
                $smartPlusIds[] = $spId;
                $adMap[$spId] = $ad;
            }
        }

        logSmartPlus("Found " . count($smartPlusIds) . " Smart+ ad IDs");

        if (empty($smartPlusIds)) {
            echo json_encode(['success' => true, 'ads' => [], 'count' => 0]);
            break;
        }

        // Step 2: Get review info for all Smart+ ads
        // Build URL manually since smart_plus_ad_ids needs JSON array format
        $reviewUrl = "https://business-api.tiktok.com/open_api/v1.3/smart_plus/ad/review_info/";
        $reviewQueryParams = [
            'advertiser_id' => $advertiserId,
            'smart_plus_ad_ids' => json_encode($smartPlusIds),
            'extra_info_setting' => json_encode(['include_reject_info' => true])
        ];
        $reviewUrl .= '?' . http_build_query($reviewQueryParams);

        logSmartPlus("Review info URL: $reviewUrl");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $reviewUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                "Access-Token: " . $accessToken,
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 60
        ]);
        $reviewResponse = curl_exec($ch);
        $reviewHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $reviewResult = json_decode($reviewResponse, true);
        logSmartPlus("Review info response code: " . ($reviewResult['code'] ?? 'N/A'));
        logSmartPlus("Review info response: " . substr($reviewResponse, 0, 1000));

        if (!$reviewResult || ($reviewResult['code'] ?? -1) != 0) {
            logSmartPlus("Failed to fetch review info: " . json_encode($reviewResult));
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch review info: ' . ($reviewResult['message'] ?? 'Unknown error'),
                'ads' => [],
                'count' => 0
            ]);
            break;
        }

        // Step 3: Filter for rejected ads (UNAVAILABLE or PART_AVAILABLE)
        $reviewInfos = $reviewResult['data']['smart_plus_ad_review_infos'] ?? [];
        logSmartPlus("Got review info for " . count($reviewInfos) . " ads");

        $rejectedAds = [];
        foreach ($reviewInfos as $info) {
            $reviewStatus = $info['review_status'] ?? '';
            // UNAVAILABLE = fully rejected, PART_AVAILABLE = partially rejected
            if ($reviewStatus === 'UNAVAILABLE' || $reviewStatus === 'PART_AVAILABLE') {
                $spId = $info['smart_plus_ad_id'] ?? '';
                $ad = $adMap[$spId] ?? [];
                $rejectReasons = $info['reject_info']['reasons'] ?? [];
                $suggestion = $info['reject_info']['suggestion'] ?? '';
                $appealStatus = $info['appeal_status'] ?? 'NOT_APPEALED';

                $rejectedAds[] = [
                    'ad_id' => $ad['ad_id'] ?? '',
                    'smart_plus_ad_id' => $spId,
                    'advertiser_id' => $advertiserId,
                    'ad_name' => $ad['ad_name'] ?? 'Unnamed Ad',
                    'campaign_id' => $ad['campaign_id'] ?? '',
                    'campaign_name' => $ad['campaign_name'] ?? '',
                    'review_status' => $reviewStatus,
                    'appeal_status' => $appealStatus,
                    'reject_reason' => !empty($rejectReasons) ? implode('; ', $rejectReasons) : 'Policy violation',
                    'suggestion' => $suggestion
                ];
            }
        }

        logSmartPlus("Found " . count($rejectedAds) . " rejected/partially rejected ads");

        echo json_encode([
            'success' => true,
            'ads' => $rejectedAds,
            'count' => count($rejectedAds)
        ]);
        break;

    // ==========================================
    // UPDATE CAMPAIGN STATUS (ON/OFF)
    // ==========================================
    case 'update_campaign_status':
        $data = $input;

        logSmartPlus("=== UPDATING CAMPAIGN STATUS ===");

        if (empty($data['campaign_id'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
            exit;
        }

        $newStatus = $data['status'] ?? 'ENABLE';
        if (!in_array($newStatus, ['ENABLE', 'DISABLE'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid status. Use ENABLE or DISABLE']);
            exit;
        }

        logSmartPlus("Campaign ID: " . $data['campaign_id'] . ", New Status: " . $newStatus);

        // Call TikTok API to update campaign status
        $result = makeApiCall('/campaign/status/update/', [
            'advertiser_id' => $advertiserId,
            'campaign_ids' => [$data['campaign_id']],
            'operation_status' => $newStatus
        ], $accessToken);

        logSmartPlus("Update status response: " . json_encode($result));

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'campaign_id' => $data['campaign_id'],
                'new_status' => $newStatus,
                'message' => 'Campaign status updated to ' . $newStatus
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update status: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // UPDATE CAMPAIGN BUDGET
    // Handles both Smart+ and regular campaigns
    // ==========================================
    case 'update_campaign_budget':
        $data = $input;

        logSmartPlus("=== UPDATING CAMPAIGN BUDGET ===");

        if (empty($data['campaign_id'])) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
            exit;
        }

        if (!isset($data['budget']) || floatval($data['budget']) < 20) {
            echo json_encode(['success' => false, 'message' => 'Budget must be at least $20']);
            exit;
        }

        $newBudget = floatval($data['budget']);
        $isSmartPlus = !empty($data['is_smart_plus']);
        logSmartPlus("Campaign ID: " . $data['campaign_id'] . ", New Budget: $" . $newBudget . ", Smart+: " . ($isSmartPlus ? 'Yes' : 'No'));

        // Use different endpoint for Smart+ vs regular campaigns
        if ($isSmartPlus) {
            // Smart+ campaigns use /smart_plus/campaign/update/
            $result = makeApiCall('/smart_plus/campaign/update/', [
                'advertiser_id' => $advertiserId,
                'campaign_id' => $data['campaign_id'],
                'budget' => $newBudget
            ], $accessToken);
        } else {
            // Regular campaigns use /campaign/update/
            $result = makeApiCall('/campaign/update/', [
                'advertiser_id' => $advertiserId,
                'campaign_id' => $data['campaign_id'],
                'budget' => $newBudget
            ], $accessToken);
        }

        logSmartPlus("Update budget response: " . json_encode($result));

        if ($result['code'] == 0) {
            echo json_encode([
                'success' => true,
                'campaign_id' => $data['campaign_id'],
                'new_budget' => $newBudget,
                'message' => 'Campaign budget updated to $' . number_format($newBudget, 2)
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update budget: ' . ($result['message'] ?? 'Unknown error'),
                'error_code' => $result['code'] ?? null,
                'details' => $result
            ]);
        }
        break;

    // ==========================================
    // GET CAMPAIGN DETAILS (Campaign + AdGroup + Ad)
    // For duplicating existing campaigns
    // ==========================================
    case 'get_campaign_details':
        $campaignId = $input['campaign_id'] ?? '';

        logSmartPlus("=== FETCHING CAMPAIGN DETAILS FOR DUPLICATION ===");
        logSmartPlus("Campaign ID: $campaignId");

        if (empty($campaignId)) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
            exit;
        }

        $response = [
            'success' => true,
            'campaign' => null,
            'adgroup' => null,
            'ad' => null
        ];

        // Step 1: Get Campaign Details
        logSmartPlus("Step 1: Fetching campaign details...");
        $campaignResult = makeApiCall('/campaign/get/', [
            'advertiser_id' => $advertiserId,
            'filtering' => json_encode(['campaign_ids' => [$campaignId]])
        ], $accessToken, 'GET');

        if ($campaignResult['code'] == 0 && !empty($campaignResult['data']['list'])) {
            $campaign = $campaignResult['data']['list'][0];
            $response['campaign'] = [
                'campaign_id' => $campaign['campaign_id'] ?? '',
                'campaign_name' => $campaign['campaign_name'] ?? '',
                'objective_type' => $campaign['objective_type'] ?? '',
                'budget' => $campaign['budget'] ?? 0,
                'budget_mode' => $campaign['budget_mode'] ?? '',
                'operation_status' => $campaign['operation_status'] ?? '',
                'is_smart_performance_campaign' => $campaign['is_smart_performance_campaign'] ?? false
            ];
            logSmartPlus("Campaign found: " . ($campaign['campaign_name'] ?? 'Unknown'));
        } else {
            logSmartPlus("Failed to get campaign: " . ($campaignResult['message'] ?? 'Not found'));
            echo json_encode([
                'success' => false,
                'message' => 'Campaign not found or error: ' . ($campaignResult['message'] ?? 'Unknown')
            ]);
            exit;
        }

        // Step 2: Get Ad Groups for this campaign
        logSmartPlus("Step 2: Fetching ad groups...");
        $adgroupResult = makeApiCall('/adgroup/get/', [
            'advertiser_id' => $advertiserId,
            'filtering' => json_encode(['campaign_ids' => [$campaignId]]),
            'page_size' => 100
        ], $accessToken, 'GET');

        if ($adgroupResult['code'] == 0 && !empty($adgroupResult['data']['list'])) {
            $adgroup = $adgroupResult['data']['list'][0]; // Get first adgroup
            $response['adgroup'] = [
                'adgroup_id' => $adgroup['adgroup_id'] ?? '',
                'adgroup_name' => $adgroup['adgroup_name'] ?? '',
                'pixel_id' => $adgroup['pixel_id'] ?? '',
                'optimization_event' => $adgroup['optimization_event'] ?? '',
                'optimization_goal' => $adgroup['optimization_goal'] ?? '',
                'billing_event' => $adgroup['billing_event'] ?? '',
                'bid_type' => $adgroup['bid_type'] ?? '',
                'budget' => $adgroup['budget'] ?? 0,
                'budget_mode' => $adgroup['budget_mode'] ?? '',
                'schedule_type' => $adgroup['schedule_type'] ?? '',
                'schedule_start_time' => $adgroup['schedule_start_time'] ?? '',
                'schedule_end_time' => $adgroup['schedule_end_time'] ?? '',
                'dayparting' => $adgroup['dayparting'] ?? null,
                'location_ids' => $adgroup['location_ids'] ?? [],
                'age_groups' => $adgroup['age_groups'] ?? [],
                'gender' => $adgroup['gender'] ?? '',
                'placement_type' => $adgroup['placement_type'] ?? '',
                'placements' => $adgroup['placements'] ?? [],
                'is_smart_performance_campaign' => $adgroup['is_smart_performance_campaign'] ?? false,
                'smart_creative_enabled' => $adgroup['smart_creative_enabled'] ?? false
            ];
            logSmartPlus("Ad Group found: " . ($adgroup['adgroup_name'] ?? 'Unknown'));

            // Step 3: Get Ads for this ad group using Smart+ Ad Get endpoint
            logSmartPlus("Step 3: Fetching Smart+ ads using /smart_plus/ad/get/...");
            $adResult = makeApiCall('/smart_plus/ad/get/', [
                'advertiser_id' => $advertiserId,
                'filtering' => json_encode(['adgroup_ids' => [$adgroup['adgroup_id']]]),
                'page_size' => 100
            ], $accessToken, 'GET');

            logSmartPlus("Smart+ Ad API Response: " . json_encode($adResult, JSON_PRETTY_PRINT));

            if ($adResult['code'] == 0 && !empty($adResult['data']['list'])) {
                $ad = $adResult['data']['list'][0]; // Get first ad

                // Log the full ad response to see all available fields
                logSmartPlus("Full Smart+ ad response: " . json_encode($ad, JSON_PRETTY_PRINT));

                // Extract call_to_action_id from ad_configuration (Smart+ format)
                $callToActionId = '';
                if (isset($ad['ad_configuration']['call_to_action_id'])) {
                    $callToActionId = $ad['ad_configuration']['call_to_action_id'];
                    logSmartPlus("Found call_to_action_id in ad_configuration: $callToActionId");
                }

                // Extract landing_page_url from landing_page_url_list (Smart+ format)
                $landingPageUrl = '';
                if (!empty($ad['landing_page_url_list']) && is_array($ad['landing_page_url_list'])) {
                    if (isset($ad['landing_page_url_list'][0]['landing_page_url'])) {
                        $landingPageUrl = $ad['landing_page_url_list'][0]['landing_page_url'];
                    } elseif (is_string($ad['landing_page_url_list'][0])) {
                        $landingPageUrl = $ad['landing_page_url_list'][0];
                    }
                    logSmartPlus("Found landing_page_url from landing_page_url_list: $landingPageUrl");
                }

                // Extract creatives from creative_list (Smart+ format)
                $creativeList = $ad['creative_list'] ?? [];
                $videoIds = [];
                $imageIds = [];
                if (!empty($creativeList)) {
                    foreach ($creativeList as $creative) {
                        if (isset($creative['creative_info']['video_info']['video_id'])) {
                            $videoIds[] = $creative['creative_info']['video_info']['video_id'];
                        }
                        if (isset($creative['creative_info']['image_info']) && is_array($creative['creative_info']['image_info'])) {
                            foreach ($creative['creative_info']['image_info'] as $imgInfo) {
                                if (isset($imgInfo['web_uri'])) {
                                    $imageIds[] = $imgInfo['web_uri'];
                                }
                            }
                        }
                    }
                    logSmartPlus("Extracted " . count($videoIds) . " video(s) and " . count($imageIds) . " image(s) from creative_list");
                }

                // Extract ad_text_list (Smart+ format)
                $adTexts = [];
                if (!empty($ad['ad_text_list']) && is_array($ad['ad_text_list'])) {
                    foreach ($ad['ad_text_list'] as $textItem) {
                        if (isset($textItem['ad_text'])) {
                            $adTexts[] = $textItem['ad_text'];
                        }
                    }
                    logSmartPlus("Extracted " . count($adTexts) . " ad text(s)");
                }

                // Extract identity - check multiple locations in TikTok API response
                $identityId = '';
                $identityType = 'CUSTOMIZED_USER';
                $identityAuthorizedBcId = '';

                // Helper to extract identity fields from an array
                $extractIdentity = function($source, $label) use (&$identityId, &$identityType, &$identityAuthorizedBcId) {
                    if (empty($identityId) && !empty($source['identity_id'])) {
                        $identityId = $source['identity_id'];
                        $identityType = $source['identity_type'] ?? 'CUSTOMIZED_USER';
                        $identityAuthorizedBcId = $source['identity_authorized_bc_id'] ?? $source['identity_bc_id'] ?? '';
                        logSmartPlus("Found identity in $label: id=$identityId, type=$identityType, bc_id=$identityAuthorizedBcId");
                        return true;
                    }
                    return false;
                };

                // 1. Check ad_configuration
                $extractIdentity($ad['ad_configuration'] ?? [], 'ad_configuration');
                // 2. Check ad root level
                $extractIdentity($ad, 'ad root level');
                // 3. Check creative_list -> creative_info
                if (empty($identityId) && !empty($creativeList)) {
                    foreach ($creativeList as $creative) {
                        $cInfo = $creative['creative_info'] ?? [];
                        if ($extractIdentity($cInfo, 'creative_info')) break;
                        if ($extractIdentity($cInfo['media_info'] ?? [], 'creative_info.media_info')) break;
                    }
                }
                // 4. Check media_info_list at ad level
                if (empty($identityId) && !empty($ad['media_info_list'])) {
                    foreach ($ad['media_info_list'] as $mediaItem) {
                        $mInfo = $mediaItem['media_info'] ?? $mediaItem;
                        if ($extractIdentity($mInfo, 'media_info_list')) break;
                    }
                }

                if (empty($identityId)) {
                    logSmartPlus("WARNING: No identity_id found in any location for this ad");
                }
                if ($identityType === 'BC_AUTH_TT' && empty($identityAuthorizedBcId)) {
                    logSmartPlus("WARNING: BC_AUTH_TT identity found but missing identity_authorized_bc_id");
                }

                // Check page_id for instant forms (Lead Gen)
                $pageId = '';
                if (!empty($ad['page_list']) && is_array($ad['page_list'])) {
                    $pageId = $ad['page_list'][0]['page_id'] ?? '';
                }

                logSmartPlus("Extracted: landing_page_url='$landingPageUrl', call_to_action_id='$callToActionId', identity_id='$identityId', page_id='$pageId'");

                $response['ad'] = [
                    'ad_id' => $ad['smart_plus_ad_id'] ?? $ad['ad_id'] ?? '',
                    'smart_plus_ad_id' => $ad['smart_plus_ad_id'] ?? '',
                    'ad_name' => $ad['ad_name'] ?? '',
                    'identity_id' => $identityId,
                    'identity_type' => $identityType,
                    'identity_authorized_bc_id' => $identityAuthorizedBcId,
                    'ad_format' => $ad['ad_format'] ?? '',
                    'ad_texts' => $adTexts,
                    'ad_text_list' => $ad['ad_text_list'] ?? [],
                    'call_to_action_id' => $callToActionId,
                    'landing_page_url' => $landingPageUrl,
                    'landing_page_url_list' => $ad['landing_page_url_list'] ?? [],
                    'page_id' => $pageId,
                    'page_list' => $ad['page_list'] ?? [],
                    'creative_type' => $ad['creative_type'] ?? '',
                    'video_ids' => $videoIds,
                    'image_ids' => $imageIds,
                    'creative_list' => $creativeList,
                    'ad_configuration' => $ad['ad_configuration'] ?? null,
                    'operation_status' => $ad['operation_status'] ?? ''
                ];
                logSmartPlus("Smart+ Ad found: " . ($ad['ad_name'] ?? 'Unknown') . ", call_to_action_id: $callToActionId, landing_page_url: $landingPageUrl");
            } else {
                logSmartPlus("No Smart+ ads found or error: " . ($adResult['message'] ?? 'Empty'));
            }
        } else {
            logSmartPlus("No ad groups found for campaign or error: " . ($adgroupResult['message'] ?? 'Empty'));
        }

        // If ad exists but has no call_to_action_id, try to get a default CTA portfolio
        if ($response['ad'] && empty($response['ad']['call_to_action_id'])) {
            logSmartPlus("Ad has no call_to_action_id, fetching default CTA portfolio");
            logSmartPlus("Advertiser ID for portfolio lookup: $advertiserId");

            $foundPortfolio = false;

            // Step 1: Try to get from database first
            try {
                require_once __DIR__ . '/database/Database.php';
                $db = Database::getInstance();

                $defaultPortfolio = $db->fetchOne(
                    "SELECT creative_portfolio_id, portfolio_name
                     FROM tool_portfolios
                     WHERE advertiser_id = :advertiser_id
                     AND portfolio_type = 'CTA'
                     ORDER BY created_at DESC
                     LIMIT 1",
                    ['advertiser_id' => $advertiserId]
                );

                if ($defaultPortfolio && !empty($defaultPortfolio['creative_portfolio_id'])) {
                    $response['ad']['call_to_action_id'] = $defaultPortfolio['creative_portfolio_id'];
                    $response['default_cta_portfolio'] = [
                        'id' => $defaultPortfolio['creative_portfolio_id'],
                        'name' => $defaultPortfolio['portfolio_name']
                    ];
                    logSmartPlus("Using CTA portfolio from database: " . $defaultPortfolio['portfolio_name'] . " (ID: " . $defaultPortfolio['creative_portfolio_id'] . ")");
                    $foundPortfolio = true;
                }
            } catch (Exception $e) {
                logSmartPlus("Database lookup failed: " . $e->getMessage());
            }

            // Step 2: If not found in database, try TikTok API
            if (!$foundPortfolio) {
                logSmartPlus("No portfolio in database, fetching from TikTok API...");
                try {
                    $portfolioResult = makeApiCall('/creative/portfolio/list/', [
                        'advertiser_id' => $advertiserId,
                        'page' => 1,
                        'page_size' => 100
                    ], $accessToken, 'GET');

                    logSmartPlus("TikTok portfolio API response: " . json_encode($portfolioResult));

                    if ($portfolioResult['code'] == 0 && !empty($portfolioResult['data']['portfolios'])) {
                        // Find a CTA portfolio
                        foreach ($portfolioResult['data']['portfolios'] as $portfolio) {
                            if (isset($portfolio['creative_portfolio_type']) && $portfolio['creative_portfolio_type'] === 'CTA') {
                                $response['ad']['call_to_action_id'] = $portfolio['creative_portfolio_id'];
                                $response['default_cta_portfolio'] = [
                                    'id' => $portfolio['creative_portfolio_id'],
                                    'name' => $portfolio['portfolio_name'] ?? 'CTA Portfolio'
                                ];
                                logSmartPlus("Using CTA portfolio from TikTok API: " . ($portfolio['portfolio_name'] ?? 'CTA Portfolio') . " (ID: " . $portfolio['creative_portfolio_id'] . ")");
                                $foundPortfolio = true;
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    logSmartPlus("TikTok API portfolio lookup failed: " . $e->getMessage());
                }
            }

            // Step 3: If still not found, create a new CTA portfolio
            if (!$foundPortfolio) {
                logSmartPlus("No CTA portfolio found, creating one...");
                try {
                    $frequentlyUsedCTAs = [
                        ['asset_content' => 'LEARN_MORE', 'asset_ids' => ["0"]]
                    ];

                    $createParams = [
                        'advertiser_id' => $advertiserId,
                        'creative_portfolio_type' => 'CTA',
                        'portfolio_name' => 'Auto-Generated CTAs',
                        'portfolio_content' => $frequentlyUsedCTAs
                    ];

                    $createResult = makeApiCall('/creative/portfolio/create/', $createParams, $accessToken);

                    if ($createResult['code'] == 0 && isset($createResult['data']['creative_portfolio_id'])) {
                        $newPortfolioId = $createResult['data']['creative_portfolio_id'];
                        $response['ad']['call_to_action_id'] = $newPortfolioId;
                        $response['default_cta_portfolio'] = [
                            'id' => $newPortfolioId,
                            'name' => 'Auto-Generated CTAs'
                        ];
                        logSmartPlus("Created new CTA portfolio: $newPortfolioId");
                        $foundPortfolio = true;

                        // Save to database for future use
                        try {
                            $db = Database::getInstance();
                            $db->upsert('tool_portfolios', [
                                'advertiser_id' => $advertiserId,
                                'creative_portfolio_id' => $newPortfolioId,
                                'portfolio_name' => 'Auto-Generated CTAs',
                                'portfolio_type' => 'CTA',
                                'portfolio_content' => json_encode($frequentlyUsedCTAs),
                                'created_by_tool' => 1
                            ], ['advertiser_id', 'creative_portfolio_id']);
                        } catch (Exception $dbE) {
                            logSmartPlus("Warning: Could not save portfolio to database: " . $dbE->getMessage());
                        }
                    } else {
                        logSmartPlus("Failed to create CTA portfolio: " . json_encode($createResult));
                    }
                } catch (Exception $e) {
                    logSmartPlus("Failed to create CTA portfolio: " . $e->getMessage());
                }
            }

            if (!$foundPortfolio) {
                logSmartPlus("WARNING: Could not find or create CTA portfolio for advertiser $advertiserId");
                $response['missing_cta_portfolio'] = true;
            }
        }

        logSmartPlus("Campaign details fetch complete");
        logSmartPlus("Has campaign: " . ($response['campaign'] ? 'Yes' : 'No'));
        logSmartPlus("Has adgroup: " . ($response['adgroup'] ? 'Yes' : 'No'));
        logSmartPlus("Has ad: " . ($response['ad'] ? 'Yes' : 'No'));
        logSmartPlus("Ad call_to_action_id: " . ($response['ad']['call_to_action_id'] ?? 'None'));

        echo json_encode($response);
        break;

    // ==========================================
    // CLEAR CACHE - For refreshing data
    // ==========================================
    case 'clear_cache':
        $type = $input['type'] ?? 'all';
        logSmartPlus("=== CLEARING CACHE ===");
        logSmartPlus("Type: $type, Advertiser: $advertiserId");

        switch ($type) {
            case 'pixels':
                $cacheKey = $cache->generateKey('pixels', $advertiserId);
                $cache->delete($cacheKey);
                $message = 'Pixels cache cleared';
                break;
            case 'identities':
                $cacheKey = $cache->generateKey('identities', $advertiserId);
                $cache->delete($cacheKey);
                $message = 'Identities cache cleared';
                break;
            case 'videos':
                $cacheKey = $cache->generateKey('videos', $advertiserId);
                $cache->delete($cacheKey);
                $message = 'Videos cache cleared';
                break;
            case 'images':
                $cacheKey = $cache->generateKey('images', $advertiserId);
                $cache->delete($cacheKey);
                $message = 'Images cache cleared';
                break;
            case 'campaigns':
                $cacheKey = $cache->generateKey('campaigns', $advertiserId);
                $cache->delete($cacheKey);
                $message = 'Campaigns cache cleared';
                break;
            case 'advertiser':
                // Clear all cache for this advertiser
                $cache->delete($cache->generateKey('pixels', $advertiserId));
                $cache->delete($cache->generateKey('identities', $advertiserId));
                $cache->delete($cache->generateKey('videos', $advertiserId));
                $cache->delete($cache->generateKey('images', $advertiserId));
                $cache->delete($cache->generateKey('campaigns', $advertiserId));
                $message = 'All cache cleared for advertiser';
                break;
            case 'all':
            default:
                $count = $cache->clearAll();
                $message = "All cache cleared ($count files)";
                break;
        }

        logSmartPlus("Cache cleared: $message");
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        break;

    // ==========================================
    // GET BUSINESS CENTER BALANCES
    // ==========================================
    case 'get_bc_balances':
        logSmartPlus("=== GET BC BALANCES ===");

        // Release session lock early — read-only operation
        session_write_close();

        // Step 1: Fetch all Business Centers the user has access to
        $bcResult = makeApiCall('/bc/get/', [
            'page_size' => 100,
            'page' => 1
        ], $accessToken, 'GET');

        if (!isset($bcResult['code']) || $bcResult['code'] != 0 || !isset($bcResult['data']['list'])) {
            logSmartPlus("No Business Centers found: " . json_encode($bcResult));
            echo json_encode([
                'success' => true,
                'data' => [],
                'message' => 'No Business Centers found',
                'debug' => $bcResult['message'] ?? 'No BC list in response'
            ]);
            break;
        }

        $businessCenters = $bcResult['data']['list'];
        logSmartPlus("Found " . count($businessCenters) . " Business Centers");

        // Step 2: Fetch balance for each BC
        $bcBalances = [];
        foreach ($businessCenters as $bc) {
            $bcId = $bc['bc_id'] ?? null;
            $bcName = $bc['bc_name'] ?? ('BC ' . $bcId);
            if (!$bcId) continue;

            $balanceResult = makeApiCall('/bc/balance/get/', [
                'bc_id' => (string)$bcId
            ], $accessToken, 'GET');

            logSmartPlus("BC Balance for $bcId ($bcName): " . json_encode($balanceResult));

            if (isset($balanceResult['code']) && $balanceResult['code'] == 0 && isset($balanceResult['data'])) {
                $d = $balanceResult['data'];
                // Handle both flat and nested list response formats
                if (isset($d['list'][0])) {
                    $d = $d['list'][0];
                }
                $bcBalances[] = [
                    'bc_id' => (string)$bcId,
                    'bc_name' => $bcName,
                    'balance' => floatval($d['balance'] ?? $d['cash_balance'] ?? 0),
                    'grant_balance' => floatval($d['grant_balance'] ?? 0),
                    'total_balance' => floatval($d['balance'] ?? $d['cash_balance'] ?? 0) + floatval($d['grant_balance'] ?? 0),
                    'currency' => $d['currency'] ?? 'USD',
                    'transfer_balance' => floatval($d['transfer_balance'] ?? 0)
                ];
            } else {
                logSmartPlus("Failed to fetch balance for BC $bcId (code: " . ($balanceResult['code'] ?? 'null') . "): " . ($balanceResult['message'] ?? 'unknown'));
            }
        }

        logSmartPlus("BC Balances result: " . count($bcBalances) . " centers with balance data");
        echo json_encode([
            'success' => true,
            'data' => $bcBalances
        ]);
        break;

    // ==========================================
    // CACHE STATS - Get cache statistics
    // ==========================================
    case 'cache_stats':
        $stats = $cache->getStats();
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        break;

    // ==========================================
    // REDTRACK LP CTR MAPPING
    // ==========================================

    case 'save_redtrack_mapping':
        $db = Database::getInstance();

        // Auto-create table if missing
        try {
            $db->query("SELECT 1 FROM campaign_redtrack_map LIMIT 1");
        } catch (Exception $e) {
            $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');
            if ($driver === 'pgsql') {
                $db->query("CREATE TABLE IF NOT EXISTS campaign_redtrack_map (
                    id SERIAL PRIMARY KEY,
                    campaign_id VARCHAR(64) NOT NULL,
                    advertiser_id VARCHAR(64) NOT NULL,
                    redtrack_campaign_name VARCHAR(500) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(campaign_id, advertiser_id)
                )");
            } else {
                $db->query("CREATE TABLE IF NOT EXISTS campaign_redtrack_map (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    campaign_id VARCHAR(64) NOT NULL,
                    advertiser_id VARCHAR(64) NOT NULL,
                    redtrack_campaign_name VARCHAR(500) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_campaign_rt (campaign_id, advertiser_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        }

        $campaignId = $input['campaign_id'] ?? '';
        $rtName = trim($input['redtrack_campaign_name'] ?? '');

        if (empty($campaignId) || empty($rtName)) {
            echo json_encode(['success' => false, 'message' => 'campaign_id and redtrack_campaign_name are required']);
            break;
        }

        $db->upsert('campaign_redtrack_map', [
            'campaign_id' => $campaignId,
            'advertiser_id' => $advertiserId,
            'redtrack_campaign_name' => $rtName,
        ], ['campaign_id', 'advertiser_id']);

        echo json_encode(['success' => true]);
        break;

    case 'get_redtrack_mappings':
        $db = Database::getInstance();

        // Auto-create table if missing
        try {
            $db->query("SELECT 1 FROM campaign_redtrack_map LIMIT 1");
        } catch (Exception $e) {
            echo json_encode(['success' => true, 'mappings' => []]);
            break;
        }

        $rows = $db->fetchAll(
            "SELECT campaign_id, redtrack_campaign_name FROM campaign_redtrack_map WHERE advertiser_id = ?",
            [$advertiserId]
        );

        $mappings = [];
        foreach ($rows as $row) {
            $mappings[$row['campaign_id']] = $row['redtrack_campaign_name'];
        }

        echo json_encode(['success' => true, 'mappings' => $mappings]);
        break;

    case 'fetch_redtrack_lpctr':
        $rtName = trim($input['redtrack_campaign_name'] ?? '');
        if (empty($rtName)) {
            echo json_encode(['success' => false, 'message' => 'redtrack_campaign_name is required']);
            break;
        }

        require_once __DIR__ . '/includes/optimizer-functions.php';

        $metrics = fetchRedTrackCampaignMetrics('', $rtName);
        echo json_encode([
            'success' => true,
            'lp_ctr' => $metrics['lp_ctr'] ?? 0,
            'lp_clicks' => $metrics['lp_clicks'] ?? 0,
            'lp_views' => $metrics['lp_views'] ?? 0,
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
