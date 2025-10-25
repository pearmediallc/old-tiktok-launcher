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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart+ Campaign - TikTok Campaign Launcher</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/smart-campaign.css">
</head>
<body>
    <div class="header">
        <h1>🚀 Smart+ Campaign Launcher</h1>
        <div class="user-info">
            <span id="advertiser-name">Loading...</span>
            <select id="advertiser-selector" style="display: none;">
                <!-- Will be populated dynamically -->
            </select>
            <a href="campaign-select.php" class="btn-secondary">← Back to Selection</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Progress Steps -->
        <div class="progress-steps">
            <div class="step active" data-step="1">
                <span class="step-number">1</span>
                <span class="step-label">Campaign Setup</span>
            </div>
            <div class="step" data-step="2">
                <span class="step-number">2</span>
                <span class="step-label">Ad Group</span>
            </div>
            <div class="step" data-step="3">
                <span class="step-number">3</span>
                <span class="step-label">Ads & Creative</span>
            </div>
            <div class="step" data-step="4">
                <span class="step-number">4</span>
                <span class="step-label">Review & Publish</span>
            </div>
        </div>

        <!-- Step 1: Campaign Creation -->
        <div class="step-content active" id="step-1">
            <h2>Create Smart+ Campaign</h2>
            
            <div class="smart-badge-info">
                <span class="badge-smart">Smart+</span>
                <p>AI-powered campaign with automated optimization for best results</p>
            </div>

            <div class="form-section">
                <h3>Basic Information</h3>
                <div class="form-group">
                    <label>Campaign Name</label>
                    <input type="text" id="campaign-name" placeholder="Enter campaign name" required>
                </div>
            </div>

            <div class="form-section">
                <h3>Smart Optimization Settings</h3>
                <div class="smart-features">
                    <div class="feature-toggle">
                        <label>
                            <input type="checkbox" id="auto-targeting" checked>
                            <span>Automated Audience Targeting</span>
                        </label>
                        <small>Let AI find the best audience for your ads</small>
                    </div>
                    <div class="feature-toggle">
                        <label>
                            <input type="checkbox" id="auto-placement" checked>
                            <span>Smart Placement Optimization</span>
                        </label>
                        <small>Automatically optimize ad placements across TikTok</small>
                    </div>
                    <div class="feature-toggle">
                        <label>
                            <input type="checkbox" id="creative-optimization" checked>
                            <span>Creative Optimization</span>
                        </label>
                        <small>AI-powered creative performance optimization</small>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Budget Optimization</h3>
                <div class="feature-toggle">
                    <label>
                        <input type="checkbox" id="cbo-enabled" onchange="toggleCBOBudget()">
                        <span>Campaign Budget Optimization (CBO)</span>
                    </label>
                    <small>Enable to set budget at campaign level. When disabled, budget is set only at ad group level.</small>
                </div>
                
                <div id="campaign-budget-section" style="display: none; margin-top: 15px;">
                    <div class="form-group">
                        <label>Campaign Daily Budget ($)</label>
                        <input type="number" id="campaign-budget" value="50" min="20" placeholder="50">
                        <small>Minimum $20 daily budget for Smart+ campaigns</small>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Age Targeting</h3>
                <div class="form-group">
                    <label>Select Age Groups</label>
                    <div class="age-groups-container">
                        <div class="age-group-item">
                            <label>
                                <input type="checkbox" name="smart_age_groups" value="AGE_13_17" class="smart-age-checkbox">
                                <span>13-17 years</span>
                                <span class="age-note">*Restricted in some regions</span>
                            </label>
                        </div>
                        <div class="age-group-item">
                            <label>
                                <input type="checkbox" name="smart_age_groups" value="AGE_18_24" class="smart-age-checkbox" checked>
                                <span>18-24 years</span>
                            </label>
                        </div>
                        <div class="age-group-item">
                            <label>
                                <input type="checkbox" name="smart_age_groups" value="AGE_25_34" class="smart-age-checkbox" checked>
                                <span>25-34 years</span>
                            </label>
                        </div>
                        <div class="age-group-item">
                            <label>
                                <input type="checkbox" name="smart_age_groups" value="AGE_35_44" class="smart-age-checkbox" checked>
                                <span>35-44 years</span>
                            </label>
                        </div>
                        <div class="age-group-item">
                            <label>
                                <input type="checkbox" name="smart_age_groups" value="AGE_45_54" class="smart-age-checkbox" checked>
                                <span>45-54 years</span>
                            </label>
                        </div>
                        <div class="age-group-item">
                            <label>
                                <input type="checkbox" name="smart_age_groups" value="AGE_55_100" class="smart-age-checkbox" checked>
                                <span>55+ years</span>
                            </label>
                        </div>
                    </div>
                    <div class="age-controls">
                        <button type="button" class="btn-secondary age-btn" onclick="selectAllSmartAges()">Select All</button>
                        <button type="button" class="btn-secondary age-btn" onclick="clearAllSmartAges()">Clear All</button>
                        <button type="button" class="btn-secondary age-btn" onclick="selectDefaultSmartAges()">Default (18+)</button>
                    </div>
                    <small class="form-help">Select at least one age group for Smart+ targeting. Default selection excludes 13-17 due to regional restrictions.</small>
                </div>
            </div>

            <div class="form-section">
                <h3>Location Targeting</h3>
                <div class="form-group">
                    <label>Select Targeting Method</label>
                    <div class="location-method-container">
                        <div class="location-method-item">
                            <label>
                                <input type="radio" name="smart_location_method" value="country" class="smart-location-method-radio" checked onchange="toggleSmartLocationMethod()">
                                <span>Target Entire United States</span>
                            </label>
                            <small>Target all users in the United States (default)</small>
                        </div>
                        <div class="location-method-item">
                            <label>
                                <input type="radio" name="smart_location_method" value="states" class="smart-location-method-radio" onchange="toggleSmartLocationMethod()">
                                <span>Target Specific States</span>
                            </label>
                            <small>Select specific US states to target (all states selected by default)</small>
                        </div>
                    </div>
                    
                    <div id="smart-country-targeting" class="location-option active">
                        <div class="location-info">
                            <p><strong>Target:</strong> United States (Location ID: 6252001)</p>
                            <p><small>Smart+ will optimize targeting across all 50 states and territories in the United States.</small></p>
                        </div>
                    </div>
                    
                    <div id="smart-states-targeting" class="location-option" style="display: none;">
                        <div class="states-selection-container">
                            <div class="states-controls">
                                <button type="button" class="btn-secondary state-btn" onclick="selectAllSmartStates()">Select All States</button>
                                <button type="button" class="btn-secondary state-btn" onclick="clearAllSmartStates()">Clear All</button>
                                <button type="button" class="btn-secondary state-btn" onclick="selectPopularSmartStates()">Popular States Only</button>
                            </div>
                            
                            <div class="states-grid" id="smart-states-grid">
                                <!-- States will be populated by JavaScript -->
                            </div>
                            
                            <div class="states-summary">
                                <p><span id="smart-selected-states-count">50</span> states selected</p>
                                <small>Select the states you want Smart+ to target. All states are selected by default for maximum optimization.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Schedule</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Start Date & Time (Optional)</label>
                        <input type="datetime-local" id="campaign-start-date">
                        <small>Leave empty to start immediately</small>
                    </div>
                    <div class="form-group">
                        <label>End Date & Time (Optional)</label>
                        <input type="datetime-local" id="campaign-end-date">
                        <small>Leave empty for ongoing campaign</small>
                    </div>
                </div>
            </div>

            <div class="button-row">
                <button class="btn-primary" onclick="createSmartCampaign()">Continue to Ad Group →</button>
            </div>
        </div>

        <!-- Step 2: Ad Group -->
        <div class="step-content" id="step-2">
            <h2>Smart+ Ad Group Configuration</h2>
            
            <div class="campaign-info">
                <p>Campaign ID: <span id="display-campaign-id"></span></p>
            </div>

            <div class="form-section">
                <h3>Ad Group Details</h3>
                <div class="form-group">
                    <label>Ad Group Name</label>
                    <input type="text" id="adgroup-name" placeholder="Enter ad group name" required>
                </div>
            </div>

            <div class="form-section">
                <h3>Conversion Tracking</h3>
                <div class="form-group">
                    <label>Lead Generation Form ID</label>
                    <select id="lead-gen-form-id" onchange="togglePixelMethod()">
                        <option value="">Loading forms...</option>
                    </select>
                    <div style="margin-top: 10px;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="radio" name="pixel-method" value="manual" onchange="togglePixelMethod()" style="margin-right: 8px;">
                            Enter Pixel ID manually
                        </label>
                        <input type="text" id="pixel-manual-input" placeholder="Enter Pixel ID" style="display: none; margin-top: 10px;">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Smart Targeting (AI-Optimized)</h3>
                <div class="smart-info">
                    <p>🤖 Smart+ campaigns automatically optimize targeting based on performance data</p>
                    <ul>
                        <li>Dynamic audience expansion</li>
                        <li>Lookalike audience creation</li>
                        <li>Behavioral targeting optimization</li>
                    </ul>
                </div>
            </div>

            <div class="form-section">
                <h3>Budget & Bidding</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Ad Group Daily Budget ($)</label>
                        <input type="number" id="budget" value="50" min="50" placeholder="50" required>
                        <small>Budget is set at ad group level when CBO is disabled</small>
                    </div>
                    <div class="form-group">
                        <label>Smart Bid <span class="optional">(Optional - TikTok will auto-optimize if empty)</span></label>
                        <input type="number" id="bid-price" value="10" min="0.1" step="0.01" placeholder="10">
                        <small>AI will optimize bidding automatically</small>
                    </div>
                </div>
            </div>

            <div class="button-row">
                <button class="btn-secondary" onclick="prevStep()">← Back</button>
                <button class="btn-primary" onclick="createSmartAdGroup()">Continue to Ads →</button>
            </div>
        </div>

        <!-- Step 3: Ads Creation -->
        <div class="step-content" id="step-3">
            <h2>Create Smart+ Ads</h2>
            
            <div class="smart-ads-info">
                <p>Smart+ campaigns support multiple creatives for automatic optimization</p>
            </div>

            <div class="ads-container" id="ads-container">
                <!-- Ad forms will be dynamically added here -->
            </div>

            <div class="button-row">
                <button class="btn-secondary" onclick="addSmartAd()">+ Add Another Creative Set</button>
            </div>

            <div class="button-row" style="margin-top: 20px;">
                <button class="btn-secondary" onclick="prevStep()">← Back</button>
                <button class="btn-primary" onclick="reviewSmartAds()">Review & Publish →</button>
            </div>
        </div>

        <!-- Step 4: Review & Publish -->
        <div class="step-content" id="step-4">
            <h2>Review & Publish</h2>

            <div class="review-section">
                <h3>Campaign Summary</h3>
                <div id="campaign-summary" class="summary-card">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>

            <div class="review-section">
                <h3>Ad Group Summary</h3>
                <div id="adgroup-summary" class="summary-card">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>

            <div class="review-section">
                <h3>Ads Summary</h3>
                <div id="ads-summary" class="summary-list">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>

            <div class="smart-optimization-note">
                <h4>🚀 Smart+ Optimization Features</h4>
                <p>Your campaign will automatically benefit from:</p>
                <ul>
                    <li>AI-powered creative rotation</li>
                    <li>Dynamic budget allocation</li>
                    <li>Automated A/B testing</li>
                    <li>Performance-based optimization</li>
                </ul>
            </div>

            <div class="button-row">
                <button class="btn-secondary" onclick="prevStep()">← Back</button>
                <button class="btn-success" onclick="publishSmartCampaign()">✓ Publish Smart+ Campaign</button>
            </div>
        </div>
    </div>

    <!-- Media Library Modal (Same as manual campaign) -->
    <div id="media-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Select Media <span id="selection-counter" style="font-size: 14px; color: #667eea; margin-left: 10px; display: none;"></span></h3>
                <span class="modal-close" onclick="closeMediaModal()">&times;</span>
            </div>
            <div style="padding: 10px 20px; background: #e8f4f8; border-bottom: 1px solid #eee;">
                <p style="margin: 0; font-size: 13px; color: #333;">
                    <strong>Smart+ Tip:</strong> Add multiple creatives for AI optimization. Select up to 10 videos/images per ad.
                </p>
            </div>
            <div class="modal-tabs">
                <button class="tab-btn active" onclick="switchMediaTab('library', event)">Library</button>
                <button class="tab-btn" onclick="switchMediaTab('upload', event)">Upload New</button>
                <button class="btn-secondary btn-sm" onclick="refreshMediaLibrary()" style="margin-left: auto;">🔄 Refresh</button>
                <button class="btn-secondary btn-sm" onclick="syncTikTokLibrary()">📥 Sync from TikTok</button>
            </div>
            <div class="modal-body">
                <div id="media-library-tab" class="media-tab active">
                    <div class="media-grid" id="media-grid">
                        <!-- Media items will be loaded here -->
                    </div>
                </div>
                <div id="media-upload-tab" class="media-tab">
                    <div class="upload-area" id="upload-area">
                        <p>📁 Click to select files or drag and drop</p>
                        <p style="font-size: 12px; color: #666;">Supports MP4 (video) and JPG/PNG (images)</p>
                        <input type="file" id="file-input" multiple accept="video/mp4,image/jpeg,image/png" style="display: none;">
                    </div>
                    <div id="upload-preview" class="upload-preview"></div>
                    <button class="btn-primary" onclick="uploadFiles()" style="margin-top: 20px; width: 100%;">Upload Selected Files</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeMediaModal()">Cancel</button>
                <button class="btn-primary" onclick="confirmMediaSelection()">Confirm Selection</button>
            </div>
        </div>
    </div>

    <!-- Loading overlay -->
    <div id="loading" class="loading-overlay">
        <div class="spinner"></div>
    </div>

    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

    <!-- Debug Console -->
    <div id="debug-console" style="position: fixed; bottom: 10px; right: 10px; width: 400px; max-height: 300px; background: #1a1a1a; color: #0f0; padding: 10px; border-radius: 8px; font-family: monospace; font-size: 11px; overflow-y: auto; display: none; z-index: 10000;">
        <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 10px;">
            <strong style="color: #fff;">Debug Console</strong>
            <button onclick="loadServerLogs()" style="margin-left: auto; padding: 2px 8px; background: #0a84ff; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Server Logs</button>
            <button onclick="clearDebugConsole()" style="margin-left: 5px; padding: 2px 8px; background: #333; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Clear</button>
            <button onclick="toggleDebugConsole()" style="margin-left: 5px; padding: 2px 8px; background: #333; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Hide</button>
        </div>
        <div id="debug-output" style="white-space: pre-wrap; word-wrap: break-word;"></div>
    </div>
    
    <!-- Debug Toggle Button -->
    <button onclick="toggleDebugConsole()" style="position: fixed; bottom: 10px; right: 10px; padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 20px; cursor: pointer; z-index: 9999;" id="debug-toggle-btn">
        🐛 Debug
    </button>

    <script src="assets/smart-campaign.js"></script>
    <script>
        // Debug console functions
        function toggleDebugConsole() {
            const console = document.getElementById('debug-console');
            const toggleBtn = document.getElementById('debug-toggle-btn');
            if (console.style.display === 'none') {
                console.style.display = 'block';
                toggleBtn.style.display = 'none';
            } else {
                console.style.display = 'none';
                toggleBtn.style.display = 'block';
            }
        }
        
        function clearDebugConsole() {
            document.getElementById('debug-output').innerHTML = '';
        }
        
        async function loadServerLogs() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_debug_logs' })
                });
                const result = await response.json();
                const debugOutput = document.getElementById('debug-output');
                if (result.success) {
                    debugOutput.innerHTML += `<span style="color: #888;">[${new Date().toLocaleTimeString()}]</span> <span style="color: #ff0;">SERVER LOGS:</span>\n${result.logs}\n\n`;
                    debugOutput.scrollTop = debugOutput.scrollHeight;
                } else {
                    debugOutput.innerHTML += `<span style="color: #f00;">Failed to load server logs: ${result.message}</span>\n`;
                }
            } catch (error) {
                const debugOutput = document.getElementById('debug-output');
                debugOutput.innerHTML += `<span style="color: #f00;">Error loading server logs: ${error.message}</span>\n`;
            }
        }
        
        // Override console.log to also display in debug console
        const originalLog = console.log;
        const originalError = console.error;
        
        console.log = function(...args) {
            originalLog.apply(console, args);
            const debugOutput = document.getElementById('debug-output');
            if (debugOutput) {
                const timestamp = new Date().toLocaleTimeString();
                debugOutput.innerHTML += `<span style="color: #888;">[${timestamp}]</span> <span style="color: #0f0;">LOG:</span> ${args.map(arg => 
                    typeof arg === 'object' ? JSON.stringify(arg, null, 2) : arg
                ).join(' ')}\n`;
                debugOutput.scrollTop = debugOutput.scrollHeight;
            }
        };
        
        console.error = function(...args) {
            originalError.apply(console, args);
            const debugOutput = document.getElementById('debug-output');
            if (debugOutput) {
                const timestamp = new Date().toLocaleTimeString();
                debugOutput.innerHTML += `<span style="color: #888;">[${timestamp}]</span> <span style="color: #f00;">ERROR:</span> ${args.map(arg => 
                    typeof arg === 'object' ? JSON.stringify(arg, null, 2) : arg
                ).join(' ')}\n`;
                debugOutput.scrollTop = debugOutput.scrollHeight;
            }
        };
        
        // Log initial load
        console.log('Smart+ Campaign Page Loaded');
        console.log('Advertiser ID:', localStorage.getItem('advertiser_id'));
    </script>
</body>
</html>