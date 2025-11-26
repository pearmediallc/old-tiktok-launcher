<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}

// Check if advertiser is selected
if (!isset($_SESSION['selected_advertiser_id'])) {
    header('Location: select-advertiser.php');
    exit;
}

$advertiser_id = $_SESSION['selected_advertiser_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart+ Campaign - TikTok Campaign Launcher</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
    <style>
        * { box-sizing: border-box; }

        .smart-page {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        .smart-header {
            text-align: center;
            padding: 30px 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            margin-bottom: 30px;
        }

        .smart-header h1 {
            font-size: 32px;
            margin: 0 0 10px 0;
        }

        .smart-header p {
            opacity: 0.9;
            margin: 0;
        }

        .form-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }

        .form-section h2 {
            font-size: 18px;
            color: #333;
            margin: 0 0 20px 0;
            padding-bottom: 12px;
            border-bottom: 2px solid #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h2 .step-num {
            background: #667eea;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }

        .form-group label .required {
            color: #e74c3c;
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="url"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group .hint {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* Media Selection */
        .media-select-box {
            border: 2px dashed #ccc;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafafa;
        }

        .media-select-box:hover {
            border-color: #667eea;
            background: #f0f3ff;
        }

        .media-select-box .icon {
            font-size: 50px;
            margin-bottom: 15px;
        }

        .media-select-box h3 {
            margin: 0 0 8px 0;
            color: #333;
        }

        .media-select-box p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .selected-media-preview {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 12px;
            margin-top: 20px;
        }

        .media-thumb {
            position: relative;
            aspect-ratio: 9/16;
            border-radius: 8px;
            overflow: hidden;
            background: #000;
        }

        .media-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .media-thumb .remove-media {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(255,0,0,0.85);
            color: white;
            border: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 12px;
            line-height: 22px;
        }

        /* Ad Text Inputs */
        .ad-text-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .ad-text-row {
            display: flex;
            gap: 10px;
        }

        .ad-text-row input {
            flex: 1;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
        }

        .ad-text-row input:focus {
            outline: none;
            border-color: #667eea;
        }

        .ad-text-row .remove-text {
            background: #ff4757;
            color: white;
            border: none;
            padding: 0 15px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
        }

        .add-text-btn {
            background: #f0f0f0;
            border: 2px dashed #ccc;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            color: #666;
            font-size: 14px;
            margin-top: 10px;
            transition: all 0.3s;
        }

        .add-text-btn:hover {
            border-color: #667eea;
            background: #f0f3ff;
            color: #667eea;
        }

        /* CTA Grid */
        .cta-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
        }

        .cta-option {
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }

        .cta-option:hover {
            border-color: #667eea;
        }

        .cta-option.selected {
            border-color: #667eea;
            background: #f0f3ff;
            color: #667eea;
            font-weight: 600;
        }

        /* Publish Section */
        .publish-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            color: white;
            margin-top: 30px;
        }

        .publish-section h2 {
            font-size: 24px;
            margin: 0 0 10px 0;
        }

        .publish-section p {
            opacity: 0.9;
            margin: 0 0 25px 0;
        }

        .btn-publish {
            background: white;
            color: #667eea;
            border: none;
            padding: 18px 60px;
            font-size: 18px;
            font-weight: 700;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-publish:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .btn-publish:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Log Panel */
        .log-panel {
            background: #1e1e1e;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            max-height: 400px;
            overflow-y: auto;
        }

        .log-panel h3 {
            color: #4CAF50;
            margin: 0 0 15px 0;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .log-panel h3::before {
            content: "●";
            animation: blink 1s infinite;
        }

        @keyframes blink {
            50% { opacity: 0.5; }
        }

        .log-entry {
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 12px;
            padding: 8px 12px;
            margin-bottom: 5px;
            border-radius: 6px;
            border-left: 3px solid;
        }

        .log-entry.info {
            background: #1a3a4a;
            color: #4fc3f7;
            border-color: #4fc3f7;
        }

        .log-entry.success {
            background: #1a3a2a;
            color: #81c784;
            border-color: #81c784;
        }

        .log-entry.error {
            background: #3a1a1a;
            color: #e57373;
            border-color: #e57373;
        }

        .log-entry.request {
            background: #3a3a1a;
            color: #fff176;
            border-color: #fff176;
        }

        .log-entry .timestamp {
            opacity: 0.6;
            margin-right: 10px;
        }

        .log-entry .endpoint {
            color: #ce93d8;
            font-weight: bold;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #666;
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Media Grid in Modal */
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
        }

        .media-item {
            position: relative;
            aspect-ratio: 9/16;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.3s;
        }

        .media-item:hover {
            border-color: #667eea;
        }

        .media-item.selected {
            border-color: #667eea;
        }

        .media-item.selected::after {
            content: "✓";
            position: absolute;
            top: 8px;
            right: 8px;
            background: #667eea;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .media-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .media-item .placeholder {
            width: 100%;
            height: 100%;
            background: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
        }

        /* Buttons */
        .btn-primary {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
        }

        /* Loading */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .loading-overlay.active {
            display: flex;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f0f0f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            margin-top: 15px;
            color: #666;
            font-size: 16px;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 15px 25px;
            background: #333;
            color: white;
            border-radius: 8px;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s;
            z-index: 10000;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success { background: #4CAF50; }
        .toast.error { background: #f44336; }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .cta-options { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TikTok Campaign Launcher</h1>
        <div class="user-info">
            <span id="advertiser-name">Loading...</span>
            <a href="campaign-select.php" class="btn-secondary" style="margin-left: 15px; padding: 8px 16px; text-decoration: none; border-radius: 6px;">← Back</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="smart-page">
        <!-- Header -->
        <div class="smart-header">
            <h1>🚀 Smart+ Campaign</h1>
            <p>Create your AI-powered Lead Generation campaign in one step</p>
        </div>

        <!-- Step 1: Campaign Info -->
        <div class="form-section">
            <h2><span class="step-num">1</span> Campaign Information</h2>
            <div class="form-group">
                <label>Campaign Name <span class="required">*</span></label>
                <input type="text" id="campaign_name" placeholder="Enter your campaign name">
                <div class="hint">Give your campaign a descriptive name</div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Daily Budget (USD) <span class="required">*</span></label>
                    <input type="number" id="budget" value="50" min="20" step="1">
                    <div class="hint">Minimum $20 per day</div>
                </div>
                <div class="form-group">
                    <label>Age Targeting</label>
                    <select id="age_targeting">
                        <option value="18+">18+ (All adults)</option>
                        <option value="25+">25+ (Adults 25 and older)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Step 2: Pixel -->
        <div class="form-section">
            <h2><span class="step-num">2</span> Pixel (Conversion Tracking)</h2>
            <div class="form-group">
                <label>Select Pixel</label>
                <select id="pixel_id">
                    <option value="">-- Loading Pixels --</option>
                </select>
                <div class="hint">Optional: Track conversions with a TikTok Pixel</div>
            </div>
            <div class="form-group" id="event_group" style="display: none;">
                <label>Optimization Event</label>
                <select id="optimization_event">
                    <option value="FORM">Form Submission</option>
                    <option value="COMPLETE_PAYMENT">Complete Payment</option>
                    <option value="REGISTRATION">Registration</option>
                    <option value="CONTACT">Contact</option>
                </select>
            </div>
        </div>

        <!-- Step 3: Identity -->
        <div class="form-section">
            <h2><span class="step-num">3</span> Identity <span class="required">*</span></h2>
            <div class="form-group">
                <label>Select Identity</label>
                <select id="identity_id">
                    <option value="">-- Loading Identities --</option>
                </select>
                <div class="hint">The TikTok identity that will be shown on your ads</div>
            </div>
        </div>

        <!-- Step 4: Video Selection -->
        <div class="form-section">
            <h2><span class="step-num">4</span> Video Creative <span class="required">*</span></h2>
            <div class="media-select-box" onclick="openMediaModal()">
                <div class="icon">🎬</div>
                <h3>Click to Select Videos</h3>
                <p>Choose up to 30 videos for Smart+ optimization</p>
            </div>
            <div class="selected-media-preview" id="selected_media_preview"></div>
        </div>

        <!-- Step 5: Ad Text -->
        <div class="form-section">
            <h2><span class="step-num">5</span> Ad Text <span class="required">*</span></h2>
            <div class="ad-text-list" id="ad_text_list">
                <div class="ad-text-row">
                    <input type="text" class="ad-text-input" placeholder="Enter ad text (12-100 characters)" maxlength="100">
                </div>
            </div>
            <button type="button" class="add-text-btn" onclick="addAdTextRow()">+ Add Another Text Variation</button>
            <div class="hint" style="margin-top: 10px;">Add multiple text variations. Smart+ will optimize which performs best.</div>
        </div>

        <!-- Step 6: Landing Page -->
        <div class="form-section">
            <h2><span class="step-num">6</span> Landing Page URL <span class="required">*</span></h2>
            <div class="form-group">
                <label>Destination URL</label>
                <input type="url" id="landing_page_url" placeholder="https://example.com/your-landing-page">
                <div class="hint">Where users go after clicking your ad</div>
            </div>
        </div>

        <!-- Step 7: CTA -->
        <div class="form-section">
            <h2><span class="step-num">7</span> Call to Action</h2>
            <p style="color: #666; margin-bottom: 15px;">Select one or more CTAs. Smart+ will optimize automatically.</p>
            <div class="cta-options" id="cta_options">
                <div class="cta-option selected" data-cta="LEARN_MORE" onclick="toggleCTA(this)">Learn More</div>
                <div class="cta-option" data-cta="SIGN_UP" onclick="toggleCTA(this)">Sign Up</div>
                <div class="cta-option" data-cta="CONTACT_US" onclick="toggleCTA(this)">Contact Us</div>
                <div class="cta-option" data-cta="APPLY_NOW" onclick="toggleCTA(this)">Apply Now</div>
                <div class="cta-option" data-cta="GET_QUOTE" onclick="toggleCTA(this)">Get Quote</div>
                <div class="cta-option" data-cta="DOWNLOAD" onclick="toggleCTA(this)">Download</div>
                <div class="cta-option" data-cta="SUBSCRIBE" onclick="toggleCTA(this)">Subscribe</div>
                <div class="cta-option" data-cta="SHOP_NOW" onclick="toggleCTA(this)">Shop Now</div>
            </div>
        </div>

        <!-- Publish -->
        <div class="publish-section">
            <h2>Ready to Launch!</h2>
            <p>Your Smart+ Campaign will be created with AI-powered optimization</p>
            <button class="btn-publish" id="btn_publish" onclick="publishCampaign()">
                🚀 Publish Smart+ Campaign
            </button>
        </div>

        <!-- Log Panel -->
        <div class="log-panel" id="log_panel">
            <h3>API Logs</h3>
            <div id="log_entries"></div>
        </div>
    </div>

    <!-- Media Selection Modal -->
    <div class="modal" id="media_modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Select Videos <span id="media_count" style="color: #667eea; font-size: 14px; margin-left: 10px;">(0 selected)</span></h3>
                <button class="modal-close" onclick="closeMediaModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="media-grid" id="media_grid">
                    <p style="text-align: center; color: #666; grid-column: 1/-1;">Loading videos...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeMediaModal()">Cancel</button>
                <button class="btn-primary" onclick="confirmMediaSelection()">Confirm Selection</button>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loading_overlay">
        <div class="spinner"></div>
        <div class="loading-text" id="loading_text">Processing...</div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script>
    // ============================================
    // Smart+ Campaign - Single Page Form
    // ============================================

    const API_URL = 'api.php';
    let selectedMedia = [];
    let selectedCTAs = ['LEARN_MORE'];
    let allVideos = [];

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        log('info', 'Initializing Smart+ Campaign form...');
        loadAdvertiserInfo();
        loadPixels();
        loadIdentities();
        loadVideos();

        // Pixel change handler
        document.getElementById('pixel_id').addEventListener('change', function() {
            document.getElementById('event_group').style.display = this.value ? 'block' : 'none';
        });
    });

    // ============================================
    // LOGGING
    // ============================================
    function log(type, message, endpoint = null) {
        const logEntries = document.getElementById('log_entries');
        const entry = document.createElement('div');
        entry.className = 'log-entry ' + type;

        const time = new Date().toLocaleTimeString();
        let html = `<span class="timestamp">[${time}]</span>`;
        if (endpoint) {
            html += `<span class="endpoint">${endpoint}</span> - `;
        }
        html += message;

        entry.innerHTML = html;
        logEntries.insertBefore(entry, logEntries.firstChild);

        // Keep only last 50 entries
        while (logEntries.children.length > 50) {
            logEntries.removeChild(logEntries.lastChild);
        }
    }

    // ============================================
    // API CALLS
    // ============================================
    async function apiCall(action, data = {}) {
        log('request', `Calling action: ${action}`, action);

        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...data })
            });

            const result = await response.json();

            if (result.success) {
                log('success', `${action} completed successfully`, action);
            } else {
                log('error', `${action} failed: ${result.message || 'Unknown error'}`, action);
            }

            return result;
        } catch (error) {
            log('error', `${action} error: ${error.message}`, action);
            return { success: false, message: error.message };
        }
    }

    // ============================================
    // LOAD DATA
    // ============================================
    async function loadAdvertiserInfo() {
        const result = await apiCall('get_selected_advertiser');
        if (result.success && result.advertiser) {
            document.getElementById('advertiser-name').textContent = result.advertiser.advertiser_name || 'Advertiser';
            log('success', `Advertiser: ${result.advertiser.advertiser_name}`);
        }
    }

    async function loadPixels() {
        log('info', 'Loading pixels...', 'get_pixels');
        const result = await apiCall('get_pixels');
        const select = document.getElementById('pixel_id');
        select.innerHTML = '<option value="">-- No Pixel (Optional) --</option>';

        if (result.success && result.data && result.data.pixels) {
            result.data.pixels.forEach(pixel => {
                const option = document.createElement('option');
                option.value = pixel.pixel_id;
                option.textContent = pixel.pixel_name || pixel.pixel_id;
                select.appendChild(option);
            });
            log('success', `Loaded ${result.data.pixels.length} pixels`);
        } else {
            log('info', 'No pixels found');
        }
    }

    async function loadIdentities() {
        log('info', 'Loading identities...', 'get_identities');
        const result = await apiCall('get_identities');
        const select = document.getElementById('identity_id');
        select.innerHTML = '<option value="">-- Select Identity --</option>';

        if (result.success && result.data && result.data.list) {
            result.data.list.forEach(identity => {
                const option = document.createElement('option');
                option.value = identity.identity_id;
                option.textContent = identity.display_name || identity.identity_name || 'Identity';
                select.appendChild(option);
            });
            log('success', `Loaded ${result.data.list.length} identities`);
        } else {
            log('info', 'No identities found');
        }
    }

    async function loadVideos() {
        log('info', 'Loading videos...', 'get_videos');
        const result = await apiCall('get_videos');
        const grid = document.getElementById('media_grid');
        grid.innerHTML = '';
        allVideos = [];

        if (result.success && result.data && result.data.list) {
            allVideos = result.data.list;
            allVideos.forEach(video => {
                const item = document.createElement('div');
                item.className = 'media-item';
                item.dataset.videoId = video.video_id;
                item.onclick = () => toggleMediaItem(item, video);

                if (video.video_cover_url || video.preview_url) {
                    item.innerHTML = `<img src="${video.video_cover_url || video.preview_url}" alt="Video">`;
                } else {
                    item.innerHTML = `<div class="placeholder">VIDEO<br>${video.file_name || ''}</div>`;
                }

                grid.appendChild(item);
            });
            log('success', `Loaded ${allVideos.length} videos`);
        } else {
            grid.innerHTML = '<p style="text-align: center; color: #666; grid-column: 1/-1;">No videos found. Upload videos in TikTok Ads Manager.</p>';
            log('info', 'No videos found');
        }
    }

    // ============================================
    // MEDIA SELECTION
    // ============================================
    function openMediaModal() {
        document.getElementById('media_modal').classList.add('active');
        updateMediaCount();

        // Update selection state in modal
        document.querySelectorAll('.media-item').forEach(item => {
            const isSelected = selectedMedia.find(m => m.video_id === item.dataset.videoId);
            item.classList.toggle('selected', !!isSelected);
        });
    }

    function closeMediaModal() {
        document.getElementById('media_modal').classList.remove('active');
    }

    function toggleMediaItem(item, video) {
        const index = selectedMedia.findIndex(m => m.video_id === video.video_id);

        if (index > -1) {
            selectedMedia.splice(index, 1);
            item.classList.remove('selected');
        } else if (selectedMedia.length < 30) {
            selectedMedia.push(video);
            item.classList.add('selected');
        } else {
            showToast('Maximum 30 videos allowed', 'error');
            return;
        }

        updateMediaCount();
    }

    function updateMediaCount() {
        document.getElementById('media_count').textContent = `(${selectedMedia.length} selected)`;
    }

    function confirmMediaSelection() {
        if (selectedMedia.length === 0) {
            showToast('Please select at least one video', 'error');
            return;
        }

        // Update preview
        const preview = document.getElementById('selected_media_preview');
        preview.innerHTML = '';

        selectedMedia.forEach(video => {
            const thumb = document.createElement('div');
            thumb.className = 'media-thumb';
            thumb.innerHTML = `
                ${video.video_cover_url ?
                    `<img src="${video.video_cover_url}" alt="Video">` :
                    `<div class="placeholder">VIDEO</div>`}
                <button class="remove-media" onclick="removeMedia('${video.video_id}')">&times;</button>
            `;
            preview.appendChild(thumb);
        });

        closeMediaModal();
        log('success', `Selected ${selectedMedia.length} video(s)`);
        showToast(`${selectedMedia.length} video(s) selected`, 'success');
    }

    function removeMedia(videoId) {
        selectedMedia = selectedMedia.filter(m => m.video_id !== videoId);
        confirmMediaSelection();
    }

    // ============================================
    // AD TEXT
    // ============================================
    function addAdTextRow() {
        const list = document.getElementById('ad_text_list');
        if (list.children.length >= 10) {
            showToast('Maximum 10 text variations', 'error');
            return;
        }

        const row = document.createElement('div');
        row.className = 'ad-text-row';
        row.innerHTML = `
            <input type="text" class="ad-text-input" placeholder="Enter ad text (12-100 characters)" maxlength="100">
            <button class="remove-text" onclick="removeAdTextRow(this)">&times;</button>
        `;
        list.appendChild(row);
    }

    function removeAdTextRow(btn) {
        const list = document.getElementById('ad_text_list');
        if (list.children.length > 1) {
            btn.parentElement.remove();
        }
    }

    // ============================================
    // CTA
    // ============================================
    function toggleCTA(element) {
        element.classList.toggle('selected');

        selectedCTAs = [];
        document.querySelectorAll('.cta-option.selected').forEach(el => {
            selectedCTAs.push(el.dataset.cta);
        });

        // Ensure at least one is selected
        if (selectedCTAs.length === 0) {
            element.classList.add('selected');
            selectedCTAs.push(element.dataset.cta);
        }
    }

    // ============================================
    // PUBLISH CAMPAIGN
    // ============================================
    async function publishCampaign() {
        log('info', '========== PUBLISHING SMART+ CAMPAIGN ==========');

        // Gather form data
        const campaignName = document.getElementById('campaign_name').value.trim();
        const budget = parseFloat(document.getElementById('budget').value);
        const ageTargeting = document.getElementById('age_targeting').value;
        const pixelId = document.getElementById('pixel_id').value;
        const optimizationEvent = document.getElementById('optimization_event').value;
        const identityId = document.getElementById('identity_id').value;
        const landingPageUrl = document.getElementById('landing_page_url').value.trim();

        // Collect ad texts
        const adTexts = [];
        document.querySelectorAll('.ad-text-input').forEach(input => {
            const text = input.value.trim();
            if (text && text.length >= 12) {
                adTexts.push(text);
            }
        });

        // Validation
        if (!campaignName) {
            showToast('Please enter a campaign name', 'error');
            log('error', 'Validation failed: Missing campaign name');
            return;
        }
        if (budget < 20) {
            showToast('Minimum budget is $20', 'error');
            log('error', 'Validation failed: Budget too low');
            return;
        }
        if (!identityId) {
            showToast('Please select an identity', 'error');
            log('error', 'Validation failed: Missing identity');
            return;
        }
        if (selectedMedia.length === 0) {
            showToast('Please select at least one video', 'error');
            log('error', 'Validation failed: No videos selected');
            return;
        }
        if (adTexts.length === 0) {
            showToast('Please enter at least one ad text (min 12 chars)', 'error');
            log('error', 'Validation failed: No valid ad text');
            return;
        }
        if (!landingPageUrl) {
            showToast('Please enter a landing page URL', 'error');
            log('error', 'Validation failed: Missing landing page URL');
            return;
        }

        // Disable button and show loading
        const btn = document.getElementById('btn_publish');
        btn.disabled = true;
        btn.textContent = 'Publishing...';
        showLoading('Creating CTA Portfolio...');

        try {
            // Step 1: Create CTA Portfolio
            log('info', 'Step 1: Creating CTA Portfolio...', 'create_cta_portfolio');

            const portfolioContent = selectedCTAs.map(cta => ({
                asset_content: cta,
                asset_ids: selectedMedia.map(m => m.video_id)
            }));

            const ctaResult = await apiCall('create_cta_portfolio', {
                portfolio_content: portfolioContent
            });

            let ctaPortfolioId = null;
            if (ctaResult.success && ctaResult.data) {
                ctaPortfolioId = ctaResult.data.portfolio_id || ctaResult.data.creative_portfolio_id;
                log('success', `CTA Portfolio created: ${ctaPortfolioId}`);
            } else {
                log('info', 'CTA Portfolio not created, proceeding without it');
            }

            // Step 2: Build and send Smart+ Campaign
            showLoading('Publishing Smart+ Campaign...');
            log('info', 'Step 2: Publishing Smart+ Campaign...', 'publish_smart_plus_campaign');

            // Build media_info_list
            const mediaInfoList = selectedMedia.map(video => ({
                video_id: video.video_id
            }));

            // Calculate schedule times
            const now = new Date();
            const startTime = new Date(now.getTime() + 3600000); // 1 hour from now
            const endTime = new Date(now.getTime() + 365 * 24 * 3600000); // 1 year

            const formatUTC = (date) => date.toISOString().replace('T', ' ').substring(0, 19);

            const payload = {
                campaign_name: campaignName,
                budget: budget,
                spc_audience_age: ageTargeting,
                identity_id: identityId,
                media_info_list: mediaInfoList,
                title_list: adTexts,
                landing_page_url: landingPageUrl,
                location_ids: ['6252001'],
                schedule_start_time: formatUTC(startTime),
                schedule_end_time: formatUTC(endTime)
            };

            if (pixelId) {
                payload.pixel_id = pixelId;
                payload.optimization_event = optimizationEvent;
            }

            if (ctaPortfolioId) {
                payload.call_to_action_id = ctaPortfolioId;
            }

            log('info', 'Payload: ' + JSON.stringify(payload, null, 2));

            const result = await apiCall('publish_smart_plus_campaign', payload);

            if (result.success) {
                log('success', '========== CAMPAIGN PUBLISHED SUCCESSFULLY ==========');
                if (result.data && result.data.campaign_id) {
                    log('success', `Campaign ID: ${result.data.campaign_id}`);
                }
                showToast('Smart+ Campaign published successfully!', 'success');

                setTimeout(() => {
                    alert('Campaign Created Successfully!\n\nYour Smart+ Campaign is now live with AI-powered optimization.');
                }, 500);
            } else {
                log('error', 'Campaign creation failed: ' + (result.message || 'Unknown error'));
                showToast(result.message || 'Failed to publish campaign', 'error');
            }

        } catch (error) {
            log('error', 'Error: ' + error.message);
            showToast('Error: ' + error.message, 'error');
        } finally {
            hideLoading();
            btn.disabled = false;
            btn.textContent = '🚀 Publish Smart+ Campaign';
        }
    }

    // ============================================
    // UI HELPERS
    // ============================================
    function showLoading(text = 'Processing...') {
        document.getElementById('loading_text').textContent = text;
        document.getElementById('loading_overlay').classList.add('active');
    }

    function hideLoading() {
        document.getElementById('loading_overlay').classList.remove('active');
    }

    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = 'toast show ' + type;

        setTimeout(() => {
            toast.className = 'toast';
        }, 3000);
    }
    </script>
</body>
</html>
