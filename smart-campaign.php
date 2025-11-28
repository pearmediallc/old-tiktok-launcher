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
            background: linear-gradient(135deg, #ff0050 0%, #00f2ea 100%);
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

        .smart-badge {
            background: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 20px;
            display: inline-block;
            margin-top: 10px;
            font-size: 14px;
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
            border-bottom: 2px solid #ff0050;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h2 .step-num {
            background: #ff0050;
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
            border-color: #ff0050;
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

        /* Videos Grid */
        .videos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .video-item {
            position: relative;
            aspect-ratio: 9/16;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.3s;
            background: #000;
        }

        .video-item:hover {
            border-color: #ff0050;
        }

        .video-item.selected {
            border-color: #ff0050;
        }

        .video-item.selected::after {
            content: "✓";
            position: absolute;
            top: 8px;
            right: 8px;
            background: #ff0050;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .video-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .video-item .video-label {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 8px;
            font-size: 11px;
        }

        .selected-videos-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }

        .selected-video-thumb {
            width: 60px;
            height: 100px;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }

        .selected-video-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .selected-video-thumb .remove-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #ff0050;
            color: white;
            border: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 10px;
            line-height: 18px;
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
            border-color: #ff0050;
        }

        .cta-option.selected {
            border-color: #ff0050;
            background: #fff0f3;
            color: #ff0050;
            font-weight: 600;
        }

        /* Ad Text Section */
        .ad-text-item {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .ad-text-item input {
            flex: 1;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 6px;
        }

        .ad-text-item button {
            background: #ff4444;
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 18px;
        }

        .btn-add-text {
            background: #f0f0f0;
            border: 2px dashed #ccc;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            color: #666;
            margin-top: 10px;
        }

        .btn-add-text:hover {
            border-color: #ff0050;
            color: #ff0050;
        }

        /* Publish Section */
        .publish-section {
            background: linear-gradient(135deg, #ff0050 0%, #00f2ea 100%);
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
            color: #ff0050;
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

        /* Loading & Toast */
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
            border-top-color: #ff0050;
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

        /* Log Panel */
        .log-panel {
            background: #1e1e1e;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            max-height: 300px;
            overflow-y: auto;
        }

        .log-panel h3 {
            color: #4CAF50;
            margin: 0 0 15px 0;
            font-size: 14px;
        }

        .log-entry {
            font-family: monospace;
            font-size: 12px;
            padding: 6px 10px;
            margin-bottom: 4px;
            border-radius: 4px;
            border-left: 3px solid;
        }

        .log-entry.info { background: #1a3a4a; color: #4fc3f7; border-color: #4fc3f7; }
        .log-entry.success { background: #1a3a2a; color: #81c784; border-color: #81c784; }
        .log-entry.error { background: #3a1a1a; color: #e57373; border-color: #e57373; }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .cta-options { grid-template-columns: repeat(2, 1fr); }
            .videos-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TikTok Campaign Launcher</h1>
        <div class="user-info">
            <span id="advertiser-name">Loading...</span>
            <a href="campaign-select.php" class="btn-secondary" style="margin-left: 15px; padding: 8px 16px; text-decoration: none; border-radius: 6px;">Back</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="smart-page">
        <!-- Header -->
        <div class="smart-header">
            <h1>Smart+ Lead Generation</h1>
            <p>Create AI-powered campaigns with Creative Library videos</p>
            <div class="smart-badge">External Website + CUSTOMIZED_USER</div>
        </div>

        <!-- Step 1: Campaign Info -->
        <div class="form-section">
            <h2><span class="step-num">1</span> Campaign Information</h2>
            <div class="form-group">
                <label>Campaign Name <span class="required">*</span></label>
                <input type="text" id="campaign_name" placeholder="Enter your campaign name">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Daily Budget (USD) <span class="required">*</span></label>
                    <input type="number" id="budget" value="50" min="20" step="1">
                    <div class="hint">Minimum $20 per day</div>
                </div>
                <div class="form-group">
                    <label>Cost Per Conversion (USD)</label>
                    <input type="number" id="conversion_bid" value="10" min="1" step="0.5">
                    <div class="hint">Target cost per lead</div>
                </div>
            </div>
        </div>

        <!-- Step 2: Pixel -->
        <div class="form-section">
            <h2><span class="step-num">2</span> Pixel (Required for Website)</h2>
            <div class="form-group">
                <label>Select Pixel <span class="required">*</span></label>
                <select id="pixel_id">
                    <option value="">-- Loading Pixels --</option>
                </select>
                <div class="hint">Required for External Website optimization</div>
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

        <!-- Step 3: Identity (CUSTOMIZED_USER) -->
        <div class="form-section">
            <h2><span class="step-num">3</span> Identity (Custom Profile)</h2>
            <div class="form-group">
                <label>Select Identity <span class="required">*</span></label>
                <select id="identity_id">
                    <option value="">-- Loading Identities --</option>
                </select>
                <div class="hint">Your custom brand identity for the ads</div>
            </div>
        </div>

        <!-- Step 4: Creative Library Videos -->
        <div class="form-section">
            <h2><span class="step-num">4</span> Creative Library Videos <span class="required">*</span></h2>
            <p style="color:#666;margin-bottom:10px;">Select videos from your Creative Library (max 30)</p>
            <p id="videos_counter" style="color:#ff0050;font-weight:600;">0 video(s) selected</p>

            <div class="videos-grid" id="videos_grid">
                <p style="text-align:center;color:#666;grid-column:1/-1;">Loading videos...</p>
            </div>

            <div class="selected-videos-preview" id="selected_videos_preview"></div>
        </div>

        <!-- Step 5: Ad Text -->
        <div class="form-section">
            <h2><span class="step-num">5</span> Ad Text</h2>
            <p style="color:#666;margin-bottom:15px;">Add text variations for your ads (at least 1 required)</p>
            <div id="ad_texts_container">
                <div class="ad-text-item">
                    <input type="text" class="ad-text-input" placeholder="Enter ad text (12-100 characters)" maxlength="100" value="Get Your Free Quote Today!">
                    <button onclick="removeAdText(this)">x</button>
                </div>
            </div>
            <button class="btn-add-text" onclick="addAdText()">+ Add Another Text</button>
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
            <p style="color: #666; margin-bottom: 15px;">Select CTA (or multiple for Dynamic CTA)</p>
            <div class="cta-options" id="cta_options">
                <div class="cta-option selected" data-cta="LEARN_MORE" onclick="toggleCTA(this)">Learn More</div>
                <div class="cta-option" data-cta="SIGN_UP" onclick="toggleCTA(this)">Sign Up</div>
                <div class="cta-option" data-cta="CONTACT_US" onclick="toggleCTA(this)">Contact Us</div>
                <div class="cta-option" data-cta="APPLY_NOW" onclick="toggleCTA(this)">Apply Now</div>
                <div class="cta-option" data-cta="GET_QUOTE" onclick="toggleCTA(this)">Get Quote</div>
                <div class="cta-option" data-cta="DOWNLOAD" onclick="toggleCTA(this)">Download</div>
            </div>
        </div>

        <!-- Publish -->
        <div class="publish-section">
            <h2>Ready to Launch!</h2>
            <p>Your Smart+ Campaign will use Creative Library videos with AI optimization</p>
            <button class="btn-publish" id="btn_publish" onclick="publishCampaign()">
                Publish Smart+ Campaign
            </button>
        </div>

        <!-- Log Panel -->
        <div class="log-panel">
            <h3>API Logs</h3>
            <div id="log_entries"></div>
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
    // Smart+ Campaign - Creative Library Version
    // Uses multi-step flow with CUSTOMIZED_USER
    // ============================================

    const API_URL = 'api-smartplus.php';
    let selectedVideos = [];
    let selectedCTAs = ['LEARN_MORE'];
    let accountData = null;

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        log('info', 'Initializing Smart+ Campaign (Creative Library)...');
        loadAccountData();

        // Pixel change handler
        document.getElementById('pixel_id').addEventListener('change', function() {
            document.getElementById('event_group').style.display = this.value ? 'block' : 'none';
        });
    });

    // Logging
    function log(type, message) {
        const entries = document.getElementById('log_entries');
        const entry = document.createElement('div');
        entry.className = 'log-entry ' + type;
        entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
        entries.insertBefore(entry, entries.firstChild);
        if (entries.children.length > 30) entries.removeChild(entries.lastChild);
        console.log(`[${type}] ${message}`);
    }

    // API Call
    async function apiCall(action, data = {}) {
        log('info', `API: ${action}`);
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...data })
            });
            const result = await response.json();
            if (result.success) {
                log('success', `${action} OK`);
            } else {
                log('error', `${action}: ${result.message || 'Failed'}`);
            }
            return result;
        } catch (error) {
            log('error', `${action}: ${error.message}`);
            return { success: false, message: error.message };
        }
    }

    // Load all account data (pixels, identities, videos)
    async function loadAccountData() {
        showLoading('Loading account data...');

        const result = await apiCall('get_account_data');

        if (result.success && result.data) {
            accountData = result.data;

            // Set advertiser name
            document.getElementById('advertiser-name').textContent =
                localStorage.getItem('advertiser_name') || `Advertiser: ${result.data.advertiser_id}`;

            // Populate pixels
            const pixelSelect = document.getElementById('pixel_id');
            pixelSelect.innerHTML = '<option value="">-- Select Pixel --</option>';
            if (result.data.pixels && result.data.pixels.length > 0) {
                result.data.pixels.forEach(pixel => {
                    const option = document.createElement('option');
                    option.value = pixel.pixel_id;
                    option.textContent = pixel.pixel_name || pixel.pixel_id;
                    pixelSelect.appendChild(option);
                });
                // Pre-select Home_Insurance pixel if available
                const homeInsurance = result.data.pixels.find(p => p.pixel_name && p.pixel_name.includes('Home_Insurance'));
                if (homeInsurance) {
                    pixelSelect.value = homeInsurance.pixel_id;
                    document.getElementById('event_group').style.display = 'block';
                }
            }

            // Populate identities
            const identitySelect = document.getElementById('identity_id');
            identitySelect.innerHTML = '<option value="">-- Select Identity --</option>';
            if (result.data.identities && result.data.identities.length > 0) {
                result.data.identities.forEach(identity => {
                    const option = document.createElement('option');
                    option.value = identity.identity_id;
                    option.textContent = identity.display_name || identity.identity_name || identity.identity_id;
                    identitySelect.appendChild(option);
                });
                // Pre-select first identity
                if (result.data.identities.length > 0) {
                    identitySelect.value = result.data.identities[0].identity_id;
                }
            }

            // Populate videos
            const videosGrid = document.getElementById('videos_grid');
            videosGrid.innerHTML = '';
            if (result.data.videos && result.data.videos.length > 0) {
                result.data.videos.forEach(video => {
                    const item = document.createElement('div');
                    item.className = 'video-item';
                    item.dataset.videoId = video.video_id;
                    item.onclick = () => toggleVideo(video);

                    const coverUrl = video.video_cover_url || video.poster_url || '';
                    item.innerHTML = `
                        ${coverUrl ? `<img src="${coverUrl}" alt="Video">` :
                          '<div style="background:#333;width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:white;">VIDEO</div>'}
                        <div class="video-label">${(video.file_name || video.video_id || 'Video').substring(0, 20)}...</div>
                    `;
                    videosGrid.appendChild(item);
                });

                log('success', `Loaded ${result.data.videos.length} videos from Creative Library`);
            } else {
                videosGrid.innerHTML = '<p style="text-align:center;color:#666;grid-column:1/-1;">No videos found in Creative Library</p>';
            }
        }

        hideLoading();
    }

    // Toggle Video Selection
    function toggleVideo(video) {
        const index = selectedVideos.findIndex(v => v.video_id === video.video_id);

        if (index > -1) {
            selectedVideos.splice(index, 1);
        } else if (selectedVideos.length < 30) {
            selectedVideos.push({
                video_id: video.video_id,
                video_cover_url: video.video_cover_url || video.poster_url,
                file_name: video.file_name
            });
        } else {
            showToast('Maximum 30 videos allowed', 'error');
            return;
        }

        updateVideosUI();
    }

    // Update Videos UI
    function updateVideosUI() {
        // Update counter
        document.getElementById('videos_counter').textContent = `${selectedVideos.length} video(s) selected`;

        // Update grid selection
        document.querySelectorAll('.video-item').forEach(item => {
            const isSelected = selectedVideos.find(v => v.video_id === item.dataset.videoId);
            item.classList.toggle('selected', !!isSelected);
        });

        // Update preview
        const preview = document.getElementById('selected_videos_preview');
        preview.innerHTML = '';
        selectedVideos.forEach(video => {
            const thumb = document.createElement('div');
            thumb.className = 'selected-video-thumb';
            thumb.innerHTML = `
                ${video.video_cover_url ? `<img src="${video.video_cover_url}" alt="Video">` :
                  '<div style="background:#333;width:100%;height:100%"></div>'}
                <button class="remove-btn" onclick="removeVideo('${video.video_id}')">&times;</button>
            `;
            preview.appendChild(thumb);
        });
    }

    // Remove Video
    function removeVideo(videoId) {
        selectedVideos = selectedVideos.filter(v => v.video_id !== videoId);
        updateVideosUI();
    }

    // Add Ad Text
    function addAdText() {
        const container = document.getElementById('ad_texts_container');
        if (container.children.length >= 10) {
            showToast('Maximum 10 text variations', 'error');
            return;
        }

        const item = document.createElement('div');
        item.className = 'ad-text-item';
        item.innerHTML = `
            <input type="text" class="ad-text-input" placeholder="Enter ad text (12-100 characters)" maxlength="100">
            <button onclick="removeAdText(this)">x</button>
        `;
        container.appendChild(item);
    }

    // Remove Ad Text
    function removeAdText(btn) {
        const container = document.getElementById('ad_texts_container');
        if (container.children.length > 1) {
            btn.parentElement.remove();
        } else {
            showToast('At least one ad text is required', 'error');
        }
    }

    // Toggle CTA
    function toggleCTA(el) {
        el.classList.toggle('selected');
        selectedCTAs = [];
        document.querySelectorAll('.cta-option.selected').forEach(item => {
            selectedCTAs.push(item.dataset.cta);
        });
        if (selectedCTAs.length === 0) {
            el.classList.add('selected');
            selectedCTAs.push(el.dataset.cta);
        }
    }

    // Publish Campaign
    async function publishCampaign() {
        log('info', '========== PUBLISHING SMART+ CAMPAIGN ==========');

        const campaignName = document.getElementById('campaign_name').value.trim();
        const budget = parseFloat(document.getElementById('budget').value);
        const conversionBid = parseFloat(document.getElementById('conversion_bid').value);
        const pixelId = document.getElementById('pixel_id').value;
        const optimizationEvent = document.getElementById('optimization_event').value;
        const identityId = document.getElementById('identity_id').value;
        const landingPageUrl = document.getElementById('landing_page_url').value.trim();

        // Collect ad texts
        const adTexts = [];
        document.querySelectorAll('.ad-text-input').forEach(input => {
            const text = input.value.trim();
            if (text && text.length >= 1) {
                adTexts.push(text);
            }
        });

        // Validation
        if (!campaignName) { showToast('Enter campaign name', 'error'); return; }
        if (budget < 20) { showToast('Minimum budget $20', 'error'); return; }
        if (!pixelId) { showToast('Select a pixel', 'error'); return; }
        if (!identityId) { showToast('Select an identity', 'error'); return; }
        if (selectedVideos.length === 0) { showToast('Select at least one video', 'error'); return; }
        if (adTexts.length === 0) { showToast('Add at least one ad text', 'error'); return; }
        if (!landingPageUrl) { showToast('Enter landing page URL', 'error'); return; }

        const btn = document.getElementById('btn_publish');
        btn.disabled = true;
        btn.textContent = 'Publishing...';
        showLoading('Creating Smart+ Campaign...');

        try {
            // Launch the full Smart+ Campaign
            // CTA Portfolio is created automatically by the API
            log('info', 'Launching Smart+ Campaign...');

            const payload = {
                campaign_name: campaignName,
                budget: budget,
                conversion_bid: conversionBid || 10,
                pixel_id: pixelId,
                optimization_event: optimizationEvent || 'FORM',
                identity_id: identityId,
                video_ids: selectedVideos.map(v => v.video_id),
                ad_texts: adTexts,
                landing_page_url: landingPageUrl,
                call_to_action: selectedCTAs[0], // Primary CTA
                cta_values: selectedCTAs // All selected CTAs for Dynamic CTA Portfolio
            };

            log('info', 'Payload: ' + JSON.stringify(payload, null, 2));

            const result = await apiCall('launch_smartplus_campaign', payload);

            if (result.success) {
                log('success', 'CAMPAIGN PUBLISHED SUCCESSFULLY!');
                showToast('Smart+ Campaign published!', 'success');

                // Show results
                let alertMsg = 'Campaign Created Successfully!\n\n';
                if (result.data.campaign) {
                    alertMsg += `Campaign ID: ${result.data.campaign.campaign_id}\n`;
                }
                if (result.data.adgroup) {
                    alertMsg += `Ad Group ID: ${result.data.adgroup.adgroup_id}\n`;
                }
                if (result.data.ads) {
                    alertMsg += `Ads Created: ${result.data.ads.ad_ids ? result.data.ads.ad_ids.length : 'Yes'}\n`;
                }
                alertMsg += '\nYour Smart+ Lead Generation Campaign is now live!';

                setTimeout(() => {
                    alert(alertMsg);
                }, 500);
            } else {
                log('error', result.message || 'Failed');
                showToast(result.message || 'Failed to publish', 'error');

                // Show partial results if any
                if (result.partial_results) {
                    console.log('Partial results:', result.partial_results);
                }
            }

        } catch (error) {
            log('error', error.message);
            showToast('Error: ' + error.message, 'error');
        } finally {
            hideLoading();
            btn.disabled = false;
            btn.textContent = 'Publish Smart+ Campaign';
        }
    }

    // UI Helpers
    function showLoading(text) {
        document.getElementById('loading_text').textContent = text || 'Processing...';
        document.getElementById('loading_overlay').classList.add('active');
    }

    function hideLoading() {
        document.getElementById('loading_overlay').classList.remove('active');
    }

    function showToast(message, type) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = 'toast show ' + type;
        setTimeout(() => { toast.className = 'toast'; }, 3000);
    }
    </script>
</body>
</html>
