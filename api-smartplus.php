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

        // Smart+ Lead Generation Campaign - exact parameters from TikTok docs
        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION',
            'request_id' => generateRequestId(),
            'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',
            'operation_status' => 'ENABLE'
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
            'promotion_type' => 'LEAD_GENERATION',
            'promotion_target_type' => 'EXTERNAL_WEBSITE',  // Required for Website destination (not Instant Form)
            'optimization_goal' => 'CONVERT',  // Use CONVERT for Lead Gen with External Website
            'billing_event' => 'OCPM',
            'schedule_type' => 'SCHEDULE_FROM_NOW',
            'schedule_start_time' => $scheduleStart,
            'operation_status' => 'ENABLE',
            'targeting_spec' => [
                'location_ids' => $data['location_ids'] ?? ['6252001'],
                'age_groups' => $data['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54']
            ]
        ];

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
        $creativeList = [];

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

                // Get cover image - use provided, or from batch result
                $imageId = $creative['image_id'] ?? $coverMap[$videoId] ?? null;

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

        // Build landing_page_urls as array of OBJECTS with landing_page_url key
        // Per TikTok docs: landing_page_urls for Website destination (not page_list)
        $landingPageList = [];
        if (!empty($data['landing_page_url'])) {
            $landingPageList[] = ['landing_page_url' => $data['landing_page_url']];
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
            'landing_page_url_list' => $landingPageList,
            'ad_text_list' => $adTextList
        ];

        // Build ad_configuration - per SDK docs, call_to_action_id belongs INSIDE ad_configuration
        $adConfig = [
            'call_to_action_id' => $callToActionId  // Dynamic CTA Portfolio ID goes here
        ];

        // Add identity for non-spark ads
        if (!empty($data['identity_id'])) {
            $adConfig['identity_id'] = $data['identity_id'];
            $adConfig['identity_type'] = $data['identity_type'] ?? 'CUSTOMIZED_USER';
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
        logSmartPlus("Step 1: Creating Campaign...");

        $campaignParams = [
            'advertiser_id' => $advertiserId,
            'campaign_name' => $data['campaign_name'],
            'objective_type' => 'LEAD_GENERATION',
            'request_id' => generateRequestId(),
            'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',
            'operation_status' => 'ENABLE'
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
            'schedule_type' => 'SCHEDULE_FROM_NOW',
            'schedule_start_time' => $scheduleStart,
            'operation_status' => 'ENABLE',
            'targeting_spec' => [
                'location_ids' => $data['location_ids'] ?? ['6252001'],
                'age_groups' => $data['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54']
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
        $creativeListFormatted = [];

        foreach ($creativeList as $creative) {
            if (!empty($creative['video_id'])) {
                $videoId = $creative['video_id'];
                $creativeInfo = [
                    'video_info' => [
                        'video_id' => $videoId
                    ],
                    'ad_format' => 'SINGLE_VIDEO'
                ];

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

        // Add identity for non-spark ads
        if (!empty($data['identity_id'])) {
            $adConfig['identity_id'] = $data['identity_id'];
            $adConfig['identity_type'] = $data['identity_type'] ?? 'CUSTOMIZED_USER';
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

            // Step 4: DISABLE the campaign after ad creation
            // This ensures the campaign starts in paused state for user review
            logSmartPlus("Step 4: Disabling campaign after ad creation...");
            $disableResult = makeApiCall('/campaign/update/status/', [
                'advertiser_id' => $advertiserId,
                'campaign_ids' => [$campaignId],
                'operation_status' => 'DISABLE'
            ], $accessToken);

            if ($disableResult['code'] == 0) {
                logSmartPlus("Campaign disabled successfully: $campaignId");
                $results['campaign_disabled'] = true;
            } else {
                logSmartPlus("Warning: Failed to disable campaign: " . ($disableResult['message'] ?? 'Unknown error'));
                $results['campaign_disabled'] = false;
            }
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
            'message' => !empty($results['ads'][0]['success'])
                ? "Smart+ Campaign created: Campaign $campaignId, AdGroup $adgroupId, Ad with $creativesCount creatives"
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
            $existingPortfolio = $db->fetch(
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
            echo json_encode(['success' => false, 'message' => 'target_advertiser_id is required']);
            exit;
        }

        // Validate advertiser is authorized
        $allAdvertiserIds = $_SESSION['oauth_advertiser_ids'] ?? [];
        if (!in_array($targetAdvertiserId, $allAdvertiserIds)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized advertiser ID']);
            exit;
        }

        logSmartPlus("=== Getting Assets for Account: $targetAdvertiserId ===");

        $assets = [
            'advertiser_id' => $targetAdvertiserId,
            'pixels' => [],
            'identities' => [],
            'videos' => [],
            'images' => []
        ];

        // Get Pixels
        $pixelResult = makeApiCall('/pixel/list/', [
            'advertiser_id' => $targetAdvertiserId
        ], $accessToken, 'GET');

        if ($pixelResult['code'] == 0 && isset($pixelResult['data']['pixels'])) {
            $assets['pixels'] = $pixelResult['data']['pixels'];
            logSmartPlus("Found " . count($assets['pixels']) . " pixels");
        }

        // Get Identities
        $identityResult = makeApiCall('/identity/get/', [
            'advertiser_id' => $targetAdvertiserId
        ], $accessToken, 'GET');

        if ($identityResult['code'] == 0 && isset($identityResult['data']['identity_list'])) {
            $assets['identities'] = $identityResult['data']['identity_list'];
            logSmartPlus("Found " . count($assets['identities']) . " identities");
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
        }

        // Get Images
        $imageResult = makeApiCall('/file/image/ad/get/', [
            'advertiser_id' => $targetAdvertiserId,
            'page' => 1,
            'page_size' => 100
        ], $accessToken, 'GET');

        if ($imageResult['code'] == 0 && isset($imageResult['data']['list'])) {
            $assets['images'] = $imageResult['data']['list'];
            logSmartPlus("Found " . count($assets['images']) . " images");
        }

        echo json_encode([
            'success' => true,
            'data' => $assets
        ]);
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

        // Generate job ID
        $jobId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        logSmartPlus("Bulk Job ID: $jobId");
        logSmartPlus("Processing " . count($accounts) . " accounts");

        $results = [
            'job_id' => $jobId,
            'total' => count($accounts),
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
            $videoMapping = $account['video_mapping'] ?? [];

            logSmartPlus("--- Processing Account: $accountName ($targetAdvertiserId) ---");

            // Rate limiting: 200ms delay between accounts
            if ($index > 0) {
                usleep(200000);
            }

            try {
                // Clear caches for this advertiser
                $GLOBALS['imageLibraryCache'] = null;
                $GLOBALS['videoInfoCache'] = [];

                // 1. CREATE CAMPAIGN
                $campaignParams = [
                    'advertiser_id' => $targetAdvertiserId,
                    'campaign_name' => $campaignConfig['campaign_name'],
                    'objective_type' => 'LEAD_GENERATION',
                    'request_id' => generateRequestId(),
                    'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET',
                    'operation_status' => 'ENABLE'
                ];

                if (!empty($campaignConfig['budget'])) {
                    $campaignParams['budget'] = floatval($campaignConfig['budget']);
                }

                $campaignResult = makeApiCall('/smart_plus/campaign/create/', $campaignParams, $accessToken);

                if ($campaignResult['code'] != 0 || !isset($campaignResult['data']['campaign_id'])) {
                    throw new Exception('Campaign creation failed: ' . ($campaignResult['message'] ?? 'Unknown error'));
                }

                $campaignId = $campaignResult['data']['campaign_id'];
                logSmartPlus("Campaign created: $campaignId");

                // 2. CREATE AD GROUP
                $scheduleStart = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $adgroupParams = [
                    'advertiser_id' => $targetAdvertiserId,
                    'request_id' => generateRequestId(),
                    'campaign_id' => $campaignId,
                    'adgroup_name' => $campaignConfig['campaign_name'] . ' - Ad Group',
                    'promotion_type' => 'LEAD_GENERATION',
                    'promotion_target_type' => 'EXTERNAL_WEBSITE',
                    'optimization_goal' => 'CONVERT',
                    'billing_event' => 'OCPM',
                    'schedule_type' => 'SCHEDULE_FROM_NOW',
                    'schedule_start_time' => $scheduleStart,
                    'operation_status' => 'ENABLE',
                    'targeting_spec' => [
                        'location_ids' => $campaignConfig['location_ids'] ?? ['6252001'],
                        'age_groups' => $campaignConfig['age_groups'] ?? ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54']
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
                $creativeList = [];
                foreach ($campaignConfig['creatives'] ?? [] as $creative) {
                    $sourceVideoId = $creative['video_id'];

                    // Get target video ID from mapping
                    $targetVideoId = $videoMapping[$sourceVideoId] ?? $sourceVideoId;

                    // Get cover image for this video in target account
                    $coverImageId = getVideoCoverImage($targetVideoId, $targetAdvertiserId, $accessToken);

                    $creativeInfo = [
                        'video_info' => ['video_id' => $targetVideoId],
                        'ad_format' => 'SINGLE_VIDEO'
                    ];

                    if ($coverImageId) {
                        $creativeInfo['image_info'] = [['web_uri' => $coverImageId]];
                    }

                    $creativeList[] = ['creative_info' => $creativeInfo];
                }

                // Get or create CTA portfolio for this account
                $ctaPortfolioId = null;
                try {
                    // Check for existing "Frequently Used CTAs" portfolio
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

                            // Save to database
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

                if (!$ctaPortfolioId) {
                    throw new Exception('Failed to get or create CTA portfolio for this account');
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

                $adParams = [
                    'advertiser_id' => $targetAdvertiserId,
                    'adgroup_id' => $adgroupId,
                    'ad_name' => $campaignConfig['campaign_name'] . ' - Ad',
                    'creative_list' => $creativeList,
                    'landing_page_url_list' => $landingPageList,
                    'ad_text_list' => $adTextList,
                    'ad_configuration' => [
                        'call_to_action_id' => $ctaPortfolioId,
                        'identity_id' => $identityId,
                        'identity_type' => $identityType
                    ]
                ];

                $adResult = makeApiCall('/smart_plus/ad/create/', $adParams, $accessToken);

                if ($adResult['code'] != 0 || !isset($adResult['data']['smart_plus_ad_id'])) {
                    throw new Exception('Ad creation failed: ' . ($adResult['message'] ?? 'Unknown error'));
                }

                $adId = $adResult['data']['smart_plus_ad_id'];
                logSmartPlus("Ad created: $adId");

                // 4. DISABLE the campaign after ad creation
                // This ensures the campaign starts in paused state for user review
                logSmartPlus("Disabling campaign after ad creation...");
                $disableResult = makeApiCall('/campaign/update/status/', [
                    'advertiser_id' => $targetAdvertiserId,
                    'campaign_ids' => [$campaignId],
                    'operation_status' => 'DISABLE'
                ], $accessToken);

                if ($disableResult['code'] == 0) {
                    logSmartPlus("Campaign disabled successfully: $campaignId");
                } else {
                    logSmartPlus("Warning: Failed to disable campaign: " . ($disableResult['message'] ?? 'Unknown error'));
                }

                // Success!
                $results['success'][] = [
                    'advertiser_id' => $targetAdvertiserId,
                    'advertiser_name' => $accountName,
                    'campaign_id' => $campaignId,
                    'adgroup_id' => $adgroupId,
                    'ad_id' => $adId
                ];

            } catch (Exception $e) {
                logSmartPlus("ERROR for $targetAdvertiserId: " . $e->getMessage());
                $results['failed'][] = [
                    'advertiser_id' => $targetAdvertiserId,
                    'advertiser_name' => $accountName,
                    'error' => $e->getMessage()
                ];
            }
        }

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
    // DEFAULT
    // ==========================================
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Unknown action: ' . $action
        ]);
        break;
}
