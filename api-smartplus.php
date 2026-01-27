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
    error_log("Uncaught Exception in api-smartplus.php: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $exception->getMessage()
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
        if (!isset($coverMap[$videoId])) {
            logSmartPlus("WARNING: No cover for video $videoId, using fallback");
            $coverMap[$videoId] = findAnyValidImageFromCache($imageLibrary);
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
    curl_close($ch);

    $result = json_decode($response, true);
    logSmartPlus("Response ($httpCode): " . json_encode($result));

    return $result;
}

// Handle request
$input = json_decode(file_get_contents('php://input'), true);
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
    // GET PIXELS (Cached - 10 min TTL)
    // ==========================================
    case 'get_pixels':
        $cacheKey = $cache->generateKey('pixels', $advertiserId);
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

        logSmartPlus("Cache MISS for pixels - Advertiser: $advertiserId");
        $result = makeApiCall('/pixel/list/', [
            'advertiser_id' => $advertiserId
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['pixels'])) {
            // Cache the pixels for 10 minutes
            $cache->set($cacheKey, $result['data']['pixels'], Cache::TTL_LONG);
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
    // GET IDENTITIES - All types for Smart+ (Cached - 10 min TTL)
    // ==========================================
    case 'get_identities':
        $cacheKey = $cache->generateKey('identities', $advertiserId);
        $cachedData = $cache->get($cacheKey);

        if ($cachedData !== null) {
            logSmartPlus("Cache HIT for identities - Advertiser: $advertiserId");
            // Handle both old format (array) and new format (object with identities/pages)
            if (isset($cachedData['identities'])) {
                // New format
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
                // Old format - just identities array
                echo json_encode([
                    'success' => true,
                    'data' => ['list' => $cachedData, 'identities' => $cachedData, 'pages' => []],
                    'cached' => true
                ]);
            }
            break;
        }

        logSmartPlus("Cache MISS for identities - Advertiser: $advertiserId");
        // Smart+ campaigns support CUSTOMIZED_USER and BC_AUTH_TT identity types
        // BC_AUTH_TT requires identity_authorized_bc_id parameter when creating ads
        $customIdentities = [];
        $bcAuthIdentities = [];

        // Get all identities (no filter - returns all types)
        $result = makeApiCall('/identity/get/', [
            'advertiser_id' => $advertiserId
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['identity_list'])) {
            foreach ($result['data']['identity_list'] as $identity) {
                $identityType = $identity['identity_type'] ?? 'CUSTOMIZED_USER';

                if ($identityType === 'CUSTOMIZED_USER' || !isset($identity['identity_type'])) {
                    $identity['identity_type'] = 'CUSTOMIZED_USER';  // Ensure it's set
                    $identity['source_type'] = 'custom_identity';
                    $customIdentities[] = $identity;
                } elseif ($identityType === 'BC_AUTH_TT') {
                    // BC_AUTH_TT - TikTok account authorized via Business Center
                    $identity['identity_type'] = 'BC_AUTH_TT';  // Ensure it's set
                    $identity['source_type'] = 'bc_auth_tt';
                    $bcAuthIdentities[] = $identity;
                }
            }
            logSmartPlus("Found " . count($customIdentities) . " custom identities, " . count($bcAuthIdentities) . " BC_AUTH_TT identities");
        }

        // Also try to get BC_AUTH_TT identities specifically (some may not show in general call)
        $bcAuthResult = makeApiCall('/identity/get/', [
            'advertiser_id' => $advertiserId,
            'identity_type' => 'BC_AUTH_TT'
        ], $accessToken, 'GET');

        // Log full BC_AUTH_TT response for debugging
        logSmartPlus("BC_AUTH_TT identity response: " . json_encode($bcAuthResult));

        if ($bcAuthResult['code'] == 0 && isset($bcAuthResult['data']['identity_list'])) {
            foreach ($bcAuthResult['data']['identity_list'] as $bcIdentity) {
                // Log each identity to see what fields are returned
                logSmartPlus("BC_AUTH_TT identity details: " . json_encode($bcIdentity));

                // Check if not already in list
                $exists = false;
                foreach ($bcAuthIdentities as $existing) {
                    if ($existing['identity_id'] === $bcIdentity['identity_id']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    // IMPORTANT: Explicitly set identity_type to BC_AUTH_TT
                    // TikTok may not always return this field in the response
                    $bcIdentity['identity_type'] = 'BC_AUTH_TT';
                    $bcIdentity['source_type'] = 'bc_auth_tt';
                    $bcAuthIdentities[] = $bcIdentity;
                }
            }
            logSmartPlus("After BC_AUTH_TT specific call: " . count($bcAuthIdentities) . " BC_AUTH_TT identities total");
        }

        // Combine data for caching
        $combinedData = [
            'identities' => $customIdentities,
            'pages' => $bcAuthIdentities  // BC_AUTH_TT identities (TikTok Pages)
        ];

        // Cache identities for 10 minutes
        $cache->set($cacheKey, $combinedData, Cache::TTL_LONG);

        echo json_encode([
            'success' => true,
            'data' => [
                'list' => $customIdentities,  // Keep for backward compatibility
                'identities' => $customIdentities,
                'pages' => $bcAuthIdentities
            ]
        ]);
        break;

    // ==========================================
    // GET VIDEOS FROM CREATIVE LIBRARY (Cached - 5 min TTL)
    // ==========================================
    case 'get_videos':
        $cacheKey = $cache->generateKey('videos', $advertiserId);
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

        logSmartPlus("Cache MISS for videos - Advertiser: $advertiserId");
        $result = makeApiCall('/file/video/ad/search/', [
            'advertiser_id' => $advertiserId,
            'page' => 1,
            'page_size' => 100
        ], $accessToken, 'GET');

        if ($result['code'] == 0 && isset($result['data']['list'])) {
            // Cache videos for 5 minutes (shorter TTL as videos may be added)
            $cache->set($cacheKey, $result['data']['list'], Cache::TTL_MEDIUM);
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
    // GET IMAGES FROM CREATIVE LIBRARY (Cached - 5 min TTL)
    // ==========================================
    case 'get_images':
        $cacheKey = $cache->generateKey('images', $advertiserId);
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
        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION',
            'request_id' => generateRequestId(),
            'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',
            'operation_status' => 'DISABLE'  // Create as DISABLED - safer default
        ];

        // Only add budget if provided
        if (!empty($data['budget'])) {
            $campaignParams['budget'] = floatval($data['budget']);
        }

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
        $scheduleType = $data['schedule_type'] ?? 'SCHEDULE_FROM_NOW';
        $scheduleStart = null;
        $scheduleEnd = null;

        if ($scheduleType === 'SCHEDULE_START_END' && !empty($data['schedule_start_time']) && !empty($data['schedule_end_time'])) {
            // User specified start and end times
            $scheduleStart = $data['schedule_start_time'];
            $scheduleEnd = $data['schedule_end_time'];
            logSmartPlus("Using SCHEDULE_START_END: $scheduleStart to $scheduleEnd");
        } elseif ($scheduleType === 'SCHEDULE_FROM_NOW' && !empty($data['schedule_start_time'])) {
            // User specified a future start time but wants to run continuously (no end time)
            $scheduleStart = $data['schedule_start_time'];
            logSmartPlus("Using SCHEDULE_FROM_NOW with scheduled start: $scheduleStart");
        } else {
            // Default: Run continuously from now (start immediately)
            $scheduleType = 'SCHEDULE_FROM_NOW';
            $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));
            logSmartPlus("Using SCHEDULE_FROM_NOW starting immediately at: $scheduleStart");
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
            'schedule_start_time' => $scheduleStart,
            'operation_status' => 'ENABLE',
            'targeting_spec' => [
                'location_ids' => $data['location_ids'] ?? ['6252001'],
                'age_groups' => $data['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100']
            ]
        ];

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

        // For LEAD_GENERATION objective: budget is ALWAYS at AdGroup level (not campaign)
        // TikTok API requires budget at adgroup level for this objective
        if (!empty($data['budget'])) {
            $budget = floatval($data['budget']);
            if ($budget >= 20) {
                $adgroupParams['budget'] = $budget;
                logSmartPlus("Setting budget at AdGroup level: $budget");
            } else {
                logSmartPlus("WARNING: Budget $budget is less than minimum $20");
            }
        }

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

        // For Lead Gen Smart+ Ads: use call_to_action_id (Dynamic CTA Portfolio)
        // Lead Gen objective REQUIRES portfolio-based CTA, not call_to_action_list
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

        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION',
            'request_id' => generateRequestId(),
            'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',
            'operation_status' => 'DISABLE'  // Create as DISABLED - safer default
        ];

        // Only add budget if provided
        if (!empty($data['budget'])) {
            $campaignParams['budget'] = floatval($data['budget']);
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
        // NOTE: Identity is NOT set at ad group level for Smart+
        // Identity is set at AD level
        logSmartPlus("Step 2: Creating Ad Group...");
        $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $adgroupParams = [
            'advertiser_id' => $advertiserId,
            'request_id' => generateRequestId(),
            'campaign_id' => $campaignId,
            'adgroup_name' => $data['campaign_name'] . ' - Ad Group',
            'promotion_type' => 'LEAD_GENERATION',
            'optimization_goal' => 'LEAD_GENERATION',  // Must be LEAD_GENERATION for lead gen
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

        // For LEAD_GENERATION objective: budget is ALWAYS at AdGroup level (not campaign)
        // Check for adgroup_budget first, then fall back to budget
        $adgroupBudget = $data['adgroup_budget'] ?? $data['budget'] ?? null;
        if (!empty($adgroupBudget)) {
            $adgroupParams['budget'] = floatval($adgroupBudget);
            logSmartPlus("Setting budget at AdGroup level: " . $adgroupParams['budget']);
        }

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
            if (!empty($creative['video_id']) && empty($creative['image_id'])) {
                $videoIds[] = $creative['video_id'];
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

        // For Lead Gen Smart+ Ads: Use call_to_action_id (portfolio ID) instead of call_to_action_list
        // This is REQUIRED - call_to_action_list is NOT supported for Lead Gen objective
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

            // Create new portfolio with frequently used CTAs
            logSmartPlus("Creating new frequently used CTA portfolio");

            $frequentlyUsedCTAs = [
                ['asset_content' => 'LEARN_MORE', 'asset_ids' => ["0"]],
                ['asset_content' => 'GET_QUOTE', 'asset_ids' => ["0"]],
                ['asset_content' => 'SIGN_UP', 'asset_ids' => ["0"]],
                ['asset_content' => 'CONTACT_US', 'asset_ids' => ["0"]],
                ['asset_content' => 'APPLY_NOW', 'asset_ids' => ["0"]]
            ];

            $params = [
                'advertiser_id' => $advertiserId,
                'creative_portfolio_type' => 'CTA',
                'portfolio_name' => 'Frequently Used CTAs',
                'portfolio_content' => $frequentlyUsedCTAs
            ];

            $result = makeApiCall('/creative/portfolio/create/', $params, $accessToken);

            if ($result['code'] == 0 && isset($result['data']['creative_portfolio_id'])) {
                $newPortfolioId = $result['data']['creative_portfolio_id'];
                logSmartPlus("Created new portfolio: $newPortfolioId");

                // Save to database
                $portfolioData = [
                    'advertiser_id' => $advertiserId,
                    'creative_portfolio_id' => $newPortfolioId,
                    'portfolio_name' => 'Frequently Used CTAs',
                    'portfolio_type' => 'CTA',
                    'portfolio_content' => json_encode($frequentlyUsedCTAs),
                    'created_by_tool' => 1
                ];

                $db->upsert('tool_portfolios', $portfolioData, ['advertiser_id', 'creative_portfolio_id']);

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
                'advertiser_name' => $details['name'] ?? 'Account ' . substr($advId, -6),
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

        // Log summary
        logSmartPlus("Assets summary for $targetAdvertiserId: " .
            count($assets['pixels']) . " pixels, " .
            count($assets['identities']) . " identities, " .
            count($assets['videos']) . " videos, " .
            count($assets['images']) . " images");

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
        logSmartPlus("Processing " . count($accounts) . " accounts with $duplicateCount campaign copies each");

        $results = [
            'job_id' => $jobId,
            'total' => count($accounts) * $duplicateCount,
            'duplicate_count' => $duplicateCount,
            'success' => [],
            'failed' => []
        ];

        // Process each account
        foreach ($accounts as $index => $account) {
            $targetAdvertiserId = $account['advertiser_id'];
            $accountName = $account['advertiser_name'] ?? 'Account ' . ($index + 1);
            $pixelId = $account['pixel_id'] ?? null;
            $identityId = $account['identity_id'] ?? null;
            $identityType = $account['identity_type'] ?? 'CUSTOMIZED_USER';
            $identityAuthorizedBcId = $account['identity_authorized_bc_id'] ?? null;
            $videoMapping = $account['video_mapping'] ?? [];

            logSmartPlus("--- Processing Account: $accountName ($targetAdvertiserId) ---");

            // Rate limiting: 200ms delay between accounts
            if ($index > 0) {
                usleep(200000);
            }

            // Clear caches for this advertiser (outside the duplicate loop)
            $GLOBALS['imageLibraryCache'] = null;
            $GLOBALS['videoInfoCache'] = [];

            // CTA Portfolio - get or create once per account
            $ctaPortfolioId = null;
            try {
                require_once __DIR__ . '/database/Database.php';
                $db = Database::getInstance();

                $existingPortfolio = $db->fetchOne(
                    "SELECT creative_portfolio_id FROM tool_portfolios
                     WHERE advertiser_id = :advertiser_id
                     AND portfolio_name = 'Frequently Used CTAs'
                     AND portfolio_type = 'CTA'",
                    ['advertiser_id' => $targetAdvertiserId]
                );

                if ($existingPortfolio) {
                    $ctaPortfolioId = $existingPortfolio['creative_portfolio_id'];
                } else {
                    // Create new portfolio
                    $frequentlyUsedCTAs = [
                        ['asset_content' => 'LEARN_MORE', 'asset_ids' => ["0"]],
                        ['asset_content' => 'GET_QUOTE', 'asset_ids' => ["0"]],
                        ['asset_content' => 'SIGN_UP', 'asset_ids' => ["0"]],
                        ['asset_content' => 'CONTACT_US', 'asset_ids' => ["0"]],
                        ['asset_content' => 'APPLY_NOW', 'asset_ids' => ["0"]]
                    ];

                    $portfolioResult = makeApiCall('/creative/portfolio/create/', [
                        'advertiser_id' => $targetAdvertiserId,
                        'creative_portfolio_type' => 'CTA',
                        'portfolio_content' => $frequentlyUsedCTAs
                    ], $accessToken);

                    if ($portfolioResult['code'] == 0 && isset($portfolioResult['data']['creative_portfolio_id'])) {
                        $ctaPortfolioId = $portfolioResult['data']['creative_portfolio_id'];

                        $db->insert('tool_portfolios', [
                            'advertiser_id' => $targetAdvertiserId,
                            'creative_portfolio_id' => $ctaPortfolioId,
                            'portfolio_name' => 'Frequently Used CTAs',
                            'portfolio_type' => 'CTA',
                            'portfolio_content' => json_encode($frequentlyUsedCTAs),
                            'created_by_tool' => 1
                        ]);
                    }
                }
            } catch (Exception $e) {
                logSmartPlus("Warning: Could not get/create CTA portfolio: " . $e->getMessage());
            }

            // Loop for creating duplicate campaigns
            for ($copyNum = 1; $copyNum <= $duplicateCount; $copyNum++) {
                // Generate campaign name with copy number (if duplicates > 1)
                $campaignName = $duplicateCount > 1
                    ? $campaignConfig['campaign_name'] . ' (' . $copyNum . ')'
                    : $campaignConfig['campaign_name'];

                logSmartPlus("Creating campaign copy $copyNum/$duplicateCount: $campaignName");

            try {
                // 1. CREATE CAMPAIGN
                $campaignParams = [
                    'advertiser_id' => $targetAdvertiserId,
                    'campaign_name' => $campaignName,
                    'objective_type' => 'LEAD_GENERATION',
                    'request_id' => generateRequestId(),
                    'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',
                    'operation_status' => 'DISABLE'  // Create as DISABLED - safer default
                ];

                if (!empty($campaignConfig['budget'])) {
                    $campaignParams['budget'] = floatval($campaignConfig['budget']);
                }

                $campaignResult = makeApiCall('/smart_plus/campaign/create/', $campaignParams, $accessToken);

                if ($campaignResult['code'] != 0 || !isset($campaignResult['data']['campaign_id'])) {
                    throw new Exception('Campaign creation failed: ' . ($campaignResult['message'] ?? 'Unknown error'));
                }

                $campaignId = $campaignResult['data']['campaign_id'];
                logSmartPlus("Campaign created (DISABLED): $campaignId");

                // 2. CREATE AD GROUP
                $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));

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
                    'schedule_type' => 'SCHEDULE_FROM_NOW',
                    'schedule_start_time' => $scheduleStart,
                    'operation_status' => 'ENABLE',
                    'targeting_spec' => [
                        'location_ids' => $campaignConfig['location_ids'] ?? ['6252001'],
                        'age_groups' => $campaignConfig['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100']
                    ]
                ];

                // Add pixel if provided for this account
                if (!empty($pixelId)) {
                    $adgroupParams['pixel_id'] = $pixelId;
                    $adgroupParams['optimization_event'] = $campaignConfig['optimization_event'] ?? 'FORM';
                }

                // Add budget at adgroup level for LEAD_GENERATION
                if (!empty($campaignConfig['budget'])) {
                    $adgroupParams['budget'] = floatval($campaignConfig['budget']);
                }

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

                $creativeList = [];
                foreach ($campaignConfig['creatives'] ?? [] as $creative) {
                    $sourceVideoId = $creative['video_id'];
                    logSmartPlus("Processing source video: $sourceVideoId");

                    // Get target video ID from mapping
                    $targetVideoId = $videoMapping[$sourceVideoId] ?? $sourceVideoId;
                    logSmartPlus("Target video ID (after mapping): $targetVideoId");

                    // Get cover image for this video in target account
                    $coverImageId = getVideoCoverImage($targetVideoId, $targetAdvertiserId, $accessToken);

                    $creativeInfo = [
                        'video_info' => ['video_id' => $targetVideoId],
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

                    if ($coverImageId) {
                        $creativeInfo['image_info'] = [['web_uri' => $coverImageId]];
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

                // Build landing page URL list
                $landingPageList = [];
                if (!empty($campaignConfig['landing_page_url'])) {
                    $landingPageList[] = ['landing_page_url' => $campaignConfig['landing_page_url']];
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
                'ad_name' => $ad['ad_name'] ?? 'Unnamed Ad',
                'operation_status' => $ad['operation_status'] ?? 'UNKNOWN',
                'ad_format' => $ad['ad_format'] ?? '',
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

                // Extract identity from ad_configuration
                $identityId = $ad['ad_configuration']['identity_id'] ?? '';
                $identityType = $ad['ad_configuration']['identity_type'] ?? 'CUSTOMIZED_USER';

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
                        ['asset_content' => 'LEARN_MORE', 'asset_ids' => ["0"]],
                        ['asset_content' => 'GET_QUOTE', 'asset_ids' => ["0"]],
                        ['asset_content' => 'SIGN_UP', 'asset_ids' => ["0"]]
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
    // DEFAULT
    // ==========================================
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Unknown action: ' . $action
        ]);
        break;
}
