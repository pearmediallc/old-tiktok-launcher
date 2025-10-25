<?php
session_start();

// Check authentication
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
    <title>TikTok Campaign Launcher - Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1>🚀 TikTok Campaign Launcher</h1>
            <button class="btn-logout" onclick="logout()">Logout</button>
        </header>

        <!-- Progress Steps -->
        <div class="progress-steps">
            <div class="step active" data-step="1">
                <div class="step-number">1</div>
                <div class="step-label">Campaign</div>
            </div>
            <div class="step" data-step="2">
                <div class="step-number">2</div>
                <div class="step-label">Ad Group</div>
            </div>
            <div class="step" data-step="3">
                <div class="step-number">3</div>
                <div class="step-label">Ads</div>
            </div>
            <div class="step" data-step="4">
                <div class="step-number">4</div>
                <div class="step-label">Review & Publish</div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <!-- Step 1: Campaign Creation -->
            <div class="step-content active" id="step-1">
                <h2>Create Campaign</h2>
                <div class="form-group">
                    <label>Campaign Name</label>
                    <input type="text" id="campaign-name" placeholder="Enter campaign name" required>
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
                            <input type="number" id="campaign-budget" value="20" min="20" placeholder="20">
                            <small>Minimum $20 daily budget for campaigns</small>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Schedule</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date & Time (UTC)</label>
                            <input type="datetime-local" id="campaign-start-date" required>
                        </div>
                        <div class="form-group">
                            <label>End Date & Time (UTC)</label>
                            <input type="datetime-local" id="campaign-end-date">
                        </div>
                    </div>
                </div>

                <div class="form-info">
                    <p><strong>Objective:</strong> Lead Generation</p>
                    <p><strong>Type:</strong> Manual Campaign</p>
                </div>
                <button class="btn-primary" onclick="createCampaign()">Continue to Ad Group →</button>
            </div>

            <!-- Step 2: Ad Group Creation -->
            <div class="step-content" id="step-2">
                <h2>Create Ad Group</h2>
                <div class="form-info" style="margin-bottom: 20px; background: #e8f5e9; padding: 12px; border-radius: 6px;">
                    <p><strong>Campaign ID:</strong> <span id="display-campaign-id">-</span></p>
                </div>
                <div class="form-group">
                    <label>Ad Group Name</label>
                    <input type="text" id="adgroup-name" placeholder="Enter ad group name" required>
                </div>

                <div class="form-section">
                    <h3>Pixel Configuration (for Form Tracking)</h3>
                    <div class="form-group">
                        <label>Pixel Selection Method</label>
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="radio" name="pixel-method" value="dropdown" checked onchange="togglePixelInput()">
                                Select from list
                            </label>
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="radio" name="pixel-method" value="manual" onchange="togglePixelInput()">
                                Enter manually
                            </label>
                        </div>
                    </div>
                    <div class="form-group" id="pixel-dropdown-container">
                        <label>Select Pixel for Form Tracking</label>
                        <select id="lead-gen-form-id">
                            <option value="">Loading pixels...</option>
                        </select>
                        <small>Select the pixel that will track form submissions on your website.</small>
                    </div>
                    <div class="form-group" id="pixel-manual-container" style="display: none;">
                        <label>Pixel ID (Required)</label>
                        <input type="text" id="pixel-manual-input" placeholder="Enter numeric Pixel ID (e.g., 1234567890)" style="width: 100%; padding: 8px;">
                        <small>Go to TikTok Ads Manager > Assets > Events > Web Events. Find your pixel and copy the numeric pixel ID.</small>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Budget & Schedule</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Budget Mode</label>
                            <select id="budget-mode">
                                <option value="BUDGET_MODE_DAY">Daily Budget</option>
                                <option value="BUDGET_MODE_DYNAMIC_DAILY_BUDGET">Dynamic Daily Budget (Recommended)</option>
                                <option value="BUDGET_MODE_TOTAL">Total Budget (Lifetime)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Ad Group Budget Amount ($)</label>
                            <input type="number" id="budget" placeholder="50" min="20" required>
                            <small>Budget is set at ad group level when CBO is disabled</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date & Time (UTC)</label>
                            <input type="datetime-local" id="start-date" required>
                        </div>
                        <div class="form-group">
                            <label>Bid Amount ($) <span class="optional">(Optional - TikTok will auto-optimize if empty)</span></label>
                            <input type="number" id="bid-price" placeholder="1.00" step="0.01" min="0.01">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Optimization & Placement</h3>
                    <div class="form-info">
                        <p><strong>Promotion Type:</strong> Lead Generation (External Website)</p>
                        <p><strong>Optimization Goal:</strong> Form Conversions</p>
                        <p><strong>Billing Event:</strong> OCPM</p>
                        <p><strong>Location:</strong> United States (6252001)</p>
                        <p><strong>Placement:</strong> TikTok</p>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Age Targeting</h3>
                    <div class="form-group">
                        <label>Select Age Groups</label>
                        <div class="age-groups-container">
                            <div class="age-group-item">
                                <label>
                                    <input type="checkbox" name="age_groups" value="AGE_13_17" class="age-checkbox">
                                    <span>13-17 years</span>
                                    <span class="age-note">*Restricted in some regions</span>
                                </label>
                            </div>
                            <div class="age-group-item">
                                <label>
                                    <input type="checkbox" name="age_groups" value="AGE_18_24" class="age-checkbox" checked>
                                    <span>18-24 years</span>
                                </label>
                            </div>
                            <div class="age-group-item">
                                <label>
                                    <input type="checkbox" name="age_groups" value="AGE_25_34" class="age-checkbox" checked>
                                    <span>25-34 years</span>
                                </label>
                            </div>
                            <div class="age-group-item">
                                <label>
                                    <input type="checkbox" name="age_groups" value="AGE_35_44" class="age-checkbox" checked>
                                    <span>35-44 years</span>
                                </label>
                            </div>
                            <div class="age-group-item">
                                <label>
                                    <input type="checkbox" name="age_groups" value="AGE_45_54" class="age-checkbox" checked>
                                    <span>45-54 years</span>
                                </label>
                            </div>
                            <div class="age-group-item">
                                <label>
                                    <input type="checkbox" name="age_groups" value="AGE_55_100" class="age-checkbox" checked>
                                    <span>55+ years</span>
                                </label>
                            </div>
                        </div>
                        <div class="age-controls">
                            <button type="button" class="btn-secondary age-btn" onclick="selectAllAges()">Select All</button>
                            <button type="button" class="btn-secondary age-btn" onclick="clearAllAges()">Clear All</button>
                            <button type="button" class="btn-secondary age-btn" onclick="selectDefaultAges()">Default (18+)</button>
                        </div>
                        <small class="form-help">Select at least one age group for targeting. Default selection excludes 13-17 due to regional restrictions.</small>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Location Targeting</h3>
                    <div class="form-group">
                        <label>Select Targeting Method</label>
                        <div class="location-method-container">
                            <div class="location-method-item">
                                <label>
                                    <input type="radio" name="location_method" value="country" class="location-method-radio" checked onchange="toggleLocationMethod()">
                                    <span>Target Entire United States</span>
                                </label>
                                <small>Target all users in the United States (default)</small>
                            </div>
                            <div class="location-method-item">
                                <label>
                                    <input type="radio" name="location_method" value="bulk" class="location-method-radio" onchange="toggleLocationMethod()">
                                    <span>Target Specific States/Regions</span>
                                </label>
                                <small>Upload Excel/CSV file with specific state names or location IDs</small>
                            </div>
                        </div>
                        
                        <div id="country-targeting" class="location-option active">
                            <div class="location-info">
                                <p><strong>Target:</strong> United States (Location ID: 6252001)</p>
                                <p><small>This will target all users across all 50 states and territories in the United States.</small></p>
                            </div>
                        </div>
                        
                        <div id="bulk-targeting" class="location-option" style="display: none;">
                            <div class="bulk-upload-container">
                                <div class="upload-section">
                                    <label for="location-file">Upload Location File</label>
                                    <input type="file" id="location-file" accept=".xlsx,.xls,.csv" onchange="handleLocationFileUpload(event)">
                                    <small>Supported formats: Excel (.xlsx, .xls) and CSV (.csv) - Excel files are processed automatically</small>
                                </div>
                                
                                <div class="file-format-help">
                                    <h4>File Format Requirements:</h4>
                                    <div class="format-example">
                                        <p><strong>Option 1: State Names</strong></p>
                                        <div class="code-block">
                                            State<br>
                                            California<br>
                                            Texas<br>
                                            New York<br>
                                            Florida
                                        </div>
                                    </div>
                                    <div class="format-example">
                                        <p><strong>Option 2: Location IDs</strong></p>
                                        <div class="code-block">
                                            location_id<br>
                                            5332921<br>
                                            4736286<br>
                                            5128638<br>
                                            4155751
                                        </div>
                                    </div>
                                    <p><small>Note: Max 3,000 locations per ad group. Overlapping locations (e.g., US + California) are not allowed.</small></p>
                                </div>
                                
                                <div id="location-preview" class="location-preview" style="display: none;">
                                    <h4>Uploaded Locations Preview:</h4>
                                    <div id="location-list"></div>
                                    <button type="button" class="btn-secondary" onclick="clearLocationFile()">Clear File</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Dayparting (Optional)</h3>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="enable-dayparting" onchange="toggleDayparting()">
                            Enable Dayparting (Select specific hours)
                        </label>
                    </div>

                    <div id="dayparting-section" style="display: none;">
                        <div style="margin-bottom: 15px;">
                            <button type="button" class="btn-secondary" onclick="selectAllHours()" style="padding: 8px 15px; font-size: 13px;">Select All</button>
                            <button type="button" class="btn-secondary" onclick="clearAllHours()" style="padding: 8px 15px; font-size: 13px;">Clear All</button>
                            <button type="button" class="btn-secondary" onclick="selectBusinessHours()" style="padding: 8px 15px; font-size: 13px;">Business Hours (8-17)</button>
                            <button type="button" class="btn-secondary" onclick="selectPrimeTime()" style="padding: 8px 15px; font-size: 13px;">Prime Time (18-22)</button>
                        </div>
                        <div class="dayparting-grid">
                            <table class="dayparting-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th colspan="24">Hours (0-23)</th>
                                    </tr>
                                </thead>
                                <tbody id="dayparting-body">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top: 10px; font-size: 12px; color: #666;">
                            <p><strong>Note:</strong> Each hour checkbox controls both 30-minute slots within that hour (e.g., selecting 9am enables both 9:00-9:30 and 9:30-10:00).</p>
                            <p>Selected hours will show ads in your advertiser's timezone. TikTok uses UTC for scheduling.</p>
                        </div>
                    </div>
                </div>

                <div class="button-row">
                    <button class="btn-secondary" onclick="prevStep()">← Back</button>
                    <button class="btn-primary" onclick="createAdGroup()">Continue to Ads →</button>
                </div>
            </div>

            <!-- Step 3: Ads Creation -->
            <div class="step-content" id="step-3">
                <h2>Create Ads</h2>

                <div class="ads-container" id="ads-container">
                    <!-- Ad forms will be dynamically added here -->
                </div>

                <div class="button-row">
                    <button class="btn-secondary" onclick="duplicateAd()">+ Duplicate Last Ad</button>
                </div>

                <div class="button-row" style="margin-top: 20px;">
                    <button class="btn-secondary" onclick="prevStep()">← Back</button>
                    <button class="btn-primary" onclick="reviewAds()">Review & Publish →</button>
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

                <div class="button-row">
                    <button class="btn-secondary" onclick="prevStep()">← Back</button>
                    <button class="btn-success" onclick="publishAll()">✓ Publish All</button>
                </div>
            </div>
        </div>

        <!-- Media Library Modal -->
        <div id="media-modal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Select Media <span id="selection-counter" style="font-size: 14px; color: #667eea; margin-left: 10px; display: none;"></span></h3>
                    <span class="modal-close" onclick="closeMediaModal()">&times;</span>
                </div>
                <div style="padding: 10px 20px; background: #e8f4f8; border-bottom: 1px solid #eee;">
                    <p style="margin: 0; font-size: 13px; color: #333;">
                        <strong>For Video Ads:</strong> Select 1 video + 1 image (as cover). 
                        <strong>For Image Ads:</strong> Select 1 image only.
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
                            <input type="file" id="media-file-input" accept="image/*,video/*" onchange="handleMediaUpload(event)">
                            <label for="media-file-input">
                                <div class="upload-icon">📁</div>
                                <p>Click to upload or drag and drop</p>
                                <p class="upload-hint">Images or Videos</p>
                            </label>
                        </div>
                        <div id="upload-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill" id="upload-progress-fill"></div>
                            </div>
                            <p id="upload-status">Uploading...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeMediaModal()">Cancel</button>
                    <button class="btn-primary" onclick="confirmMediaSelection()">Select</button>
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div id="loading-overlay" class="loading-overlay">
            <div class="spinner"></div>
            <p>Processing...</p>
        </div>

        <!-- Toast Notification -->
        <div id="toast" class="toast"></div>

        <!-- API Logs Panel -->
        <div id="logs-panel" class="logs-panel collapsed">
            <div class="logs-header" onclick="toggleLogsPanel()" style="cursor: pointer;">
                <h3>📋 API Request Logs <span id="logs-toggle-icon">▲ Show Logs</span></h3>
                <div class="logs-controls" onclick="event.stopPropagation();">
                    <button class="btn-clear-logs" onclick="clearLogs()">Clear</button>
                    <button class="btn-toggle-logs" onclick="toggleLogsPanel()">▲</button>
                </div>
            </div>
            <div class="logs-content" id="logs-content">
                <div class="log-entry log-info">
                    <span class="log-time"><?php echo date('H:i:s'); ?></span>
                    <span class="log-message">API Logger initialized - All requests will be logged here</span>
                </div>
            </div>
        </div>
    </div>

    <!-- SheetJS library for Excel file parsing -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="assets/app.js"></script>
</body>
</html>
