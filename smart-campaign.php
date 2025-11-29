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
    <title>Smart+ Campaign - TikTok Campaign Launcher</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
    <style>
        .smart-badge {
            background: linear-gradient(135deg, #ff0050 0%, #00f2ea 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        .form-info.smart-info {
            background: linear-gradient(135deg, #fff0f3 0%, #f0ffff 100%);
            border-left: 4px solid #ff0050;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1>🚀 TikTok Campaign Launcher <span class="smart-badge">Smart+</span></h1>
            <div class="header-info">
                <div id="advertiser-timezone-info" style="font-size: 0.9rem; color: #666; margin-right: 15px;">
                    <span id="timezone-status">Loading...</span>
                </div>
                <button class="btn-secondary" onclick="window.location.href='select-advertiser-oauth.php'" style="margin-right: 10px;">Back</button>
                <button class="btn-logout" onclick="logout()">Logout</button>
            </div>
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
                <h2>Create Smart+ Campaign</h2>
                <div class="form-group">
                    <label>Campaign Name</label>
                    <input type="text" id="campaign-name" placeholder="Enter campaign name" required>
                </div>

                <div class="form-info smart-info">
                    <p><strong>Objective:</strong> Lead Generation</p>
                    <p><strong>Type:</strong> Smart+ Campaign (AI-Optimized)</p>
                    <p><strong>Note:</strong> Budget is set at Ad Group level for Smart+ campaigns</p>
                </div>
                <button class="btn-primary" onclick="createSmartCampaign()">Continue to Ad Group →</button>
            </div>

            <!-- Step 2: Ad Group Creation -->
            <div class="step-content" id="step-2">
                <h2>Create Smart+ Ad Group</h2>
                <div class="form-info" style="margin-bottom: 20px; background: #e8f5e9; padding: 12px; border-radius: 6px;">
                    <p><strong>Campaign ID:</strong> <span id="display-campaign-id">-</span></p>
                </div>

                <div class="form-group">
                    <label>Ad Group Name</label>
                    <input type="text" id="adgroup-name" placeholder="Enter ad group name" required>
                </div>

                <div class="form-section">
                    <h3>Pixel Configuration</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Pixel for Form Tracking</label>
                            <select id="pixel-select">
                                <option value="">Loading pixels...</option>
                            </select>
                            <small>Required for External Website optimization</small>
                        </div>
                        <div class="form-group">
                            <label>Optimization Event</label>
                            <select id="optimization-event">
                                <option value="FORM">Form Submission</option>
                                <option value="COMPLETE_PAYMENT">Complete Payment</option>
                                <option value="REGISTRATION">Registration</option>
                                <option value="CONTACT">Contact</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Budget & Schedule</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Budget Mode</label>
                            <select id="budget-mode">
                                <option value="BUDGET_MODE_DAY">Daily Budget</option>
                                <option value="BUDGET_MODE_DYNAMIC_DAILY_BUDGET" selected>Dynamic Daily Budget (Recommended)</option>
                                <option value="BUDGET_MODE_TOTAL">Total Budget (Lifetime)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Ad Group Budget Amount ($)</label>
                            <input type="number" id="budget" value="50" min="20" placeholder="50" required>
                            <small>Minimum $20 budget</small>
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
                        <div class="age-controls" style="margin-top: 10px;">
                            <button type="button" class="btn-secondary" onclick="selectAllAges()">Select All</button>
                            <button type="button" class="btn-secondary" onclick="clearAllAges()">Clear All</button>
                            <button type="button" class="btn-secondary" onclick="selectDefaultAges()">Default (18+)</button>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Location Targeting</h3>
                    <div class="form-group">
                        <div class="location-method-container">
                            <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                <input type="radio" name="location_method" value="country" checked onchange="toggleLocationMethod()">
                                <span>Target Entire United States</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="radio" name="location_method" value="states" onchange="toggleLocationMethod()">
                                <span>Target Specific States</span>
                            </label>
                        </div>

                        <div id="country-targeting" style="margin-top: 10px;">
                            <div class="form-info">
                                <p><strong>Target:</strong> United States (Location ID: 6252001)</p>
                            </div>
                        </div>

                        <div id="states-targeting" style="display: none; margin-top: 10px;">
                            <div style="margin-bottom: 10px;">
                                <button type="button" class="btn-secondary" onclick="selectAllStates()">Select All States</button>
                                <button type="button" class="btn-secondary" onclick="clearAllStates()">Clear All</button>
                            </div>
                            <div class="states-grid" id="states-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; max-height: 300px; overflow-y: auto; padding: 10px; background: #f9f9f9; border-radius: 6px;">
                                <!-- States will be populated by JavaScript -->
                            </div>
                            <p style="margin-top: 10px;"><span id="selected-states-count">0</span> states selected</p>
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
                            <button type="button" class="btn-secondary" onclick="selectAllHours()">Select All</button>
                            <button type="button" class="btn-secondary" onclick="clearAllHours()">Clear All</button>
                            <button type="button" class="btn-secondary" onclick="selectBusinessHours()">Business Hours</button>
                            <button type="button" class="btn-secondary" onclick="selectPrimeTime()">Prime Time</button>
                        </div>
                        <div class="dayparting-grid">
                            <table class="dayparting-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th colspan="25">Hours (0-24)</th>
                                    </tr>
                                </thead>
                                <tbody id="dayparting-body">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Optimization & Placement</h3>
                    <div class="form-info smart-info">
                        <p><strong>Promotion Type:</strong> Lead Generation (External Website)</p>
                        <p><strong>Optimization Goal:</strong> Conversions</p>
                        <p><strong>Billing Event:</strong> OCPM</p>
                        <p><strong>Placement:</strong> TikTok</p>
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
                <div class="form-info" style="margin-bottom: 20px; background: #e8f5e9; padding: 12px; border-radius: 6px;">
                    <p><strong>Ad Group ID:</strong> <span id="display-adgroup-id">-</span></p>
                </div>

                <!-- Global Settings for All Ads -->
                <div class="form-section" style="background: #f8f9ff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 2px solid #667eea;">
                    <h3 style="margin-top: 0; color: #667eea;">Global Settings (Applied to All Ads)</h3>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Identity</label>
                            <select id="global-identity" required>
                                <option value="">Select identity...</option>
                            </select>
                            <button type="button" class="btn-secondary" onclick="openCreateIdentityModal()" style="margin-top: 8px; width: 100%;">+ Create New Identity</button>
                        </div>
                        <div class="form-group">
                            <label>Call to Action</label>
                            <select id="global-cta">
                                <option value="LEARN_MORE">Learn More</option>
                                <option value="SIGN_UP">Sign Up</option>
                                <option value="GET_QUOTE">Get Quote</option>
                                <option value="CONTACT_US">Contact Us</option>
                                <option value="APPLY_NOW">Apply Now</option>
                                <option value="DOWNLOAD">Download</option>
                                <option value="SHOP_NOW">Shop Now</option>
                                <option value="ORDER_NOW">Order Now</option>
                                <option value="BOOK_NOW">Book Now</option>
                                <option value="GET_STARTED">Get Started</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Landing Page URL</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="url" id="global-landing-url" placeholder="https://example.com/landing-page" required style="flex: 1;">
                            <button type="button" class="btn-secondary" onclick="testLandingUrl()">Test URL</button>
                        </div>
                    </div>
                </div>

                <h3>Ads (<span id="ads-count">0</span>)</h3>
                <div class="ads-container" id="ads-container">
                    <!-- Ad forms will be dynamically added here -->
                </div>

                <div class="button-row" style="align-items: center; gap: 15px;">
                    <button class="btn-secondary" onclick="addNewAd()">+ Add New Ad</button>
                    <span style="color: #666;">or</span>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="number" id="bulk-duplicate-count" min="1" max="50" value="5"
                               style="width: 70px; padding: 10px; border: 2px solid #ddd; border-radius: 5px; text-align: center; font-size: 14px;">
                        <button class="btn-primary" onclick="duplicateAdBulk()">Duplicate Multiple Ads</button>
                    </div>
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
                    <button class="btn-success" onclick="publishAll()">✓ Publish All Ads</button>
                </div>
            </div>
        </div>

        <!-- Media Library Modal -->
        <div id="media-modal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Select Media <span id="selection-counter" style="font-size: 14px; color: #667eea; margin-left: 10px;"></span></h3>
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
                </div>
                <div class="modal-body">
                    <div id="media-library-tab" class="media-tab active">
                        <div style="margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 5px;">
                            <label style="font-weight: 600; margin-right: 10px;">Filter by type:</label>
                            <button class="btn-secondary btn-sm media-filter active" data-filter="all" onclick="filterMedia('all')">All</button>
                            <button class="btn-secondary btn-sm media-filter" data-filter="image" onclick="filterMedia('image')">Images</button>
                            <button class="btn-secondary btn-sm media-filter" data-filter="video" onclick="filterMedia('video')">Videos</button>
                            <span id="media-count" style="margin-left: 15px; font-size: 12px; color: #666;"></span>
                        </div>
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
            <p id="loading-text">Processing...</p>
        </div>

        <!-- Toast Notification -->
        <div id="toast" class="toast"></div>

        <!-- Create Identity Modal -->
        <div id="create-identity-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>Create New Custom Identity</h3>
                    <span class="modal-close" onclick="closeCreateIdentityModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Display Name</label>
                        <input type="text" id="identity-display-name" placeholder="Enter display name" maxlength="40" required>
                        <div style="text-align: right; font-size: 12px; color: #666; margin-top: 5px;">
                            <span id="identity-char-count">0</span>/40
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeCreateIdentityModal()">Cancel</button>
                    <button class="btn-primary" onclick="createCustomIdentity()">Create Identity</button>
                </div>
            </div>
        </div>

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
                    <span class="log-message">Smart+ API Logger initialized</span>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/smart-campaign.js?v=<?php echo time(); ?>"></script>
</body>
</html>
