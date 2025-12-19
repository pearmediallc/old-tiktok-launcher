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
            background: linear-gradient(135deg, rgb(30, 157, 241), rgb(26, 138, 216));
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        .form-info.smart-info {
            background: rgb(227, 236, 246);
            border-left: 4px solid rgb(30, 157, 241);
        }
        /* Video Selection Grid */
        .video-select-item {
            border: 2px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 4px);
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }
        .video-select-item:hover {
            border-color: rgb(30, 157, 241);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 157, 241, 0.1);
        }
        .video-select-item.selected {
            border-color: rgb(0, 184, 122);
            background: rgb(227, 236, 246);
        }
        .video-select-item .video-preview {
            position: relative;
            height: 100px;
            background: rgb(247, 249, 250);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .video-select-item .video-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .video-select-item .video-badge {
            position: absolute;
            top: 5px;
            left: 5px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }
        .video-select-item .selected-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgb(0, 184, 122);
            color: white;
            padding: 4px 8px;
            border-radius: 50%;
            font-size: 14px;
            font-weight: bold;
        }
        .video-select-item .video-name {
            padding: 8px;
            font-size: 12px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Creative Items */
        .creative-item {
            background: white;
            border: 1px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 4px);
            overflow: hidden;
        }
        .creative-item .creative-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: rgb(247, 248, 248);
            border-bottom: 1px solid rgb(225, 234, 239);
        }
        .creative-item .creative-video-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .creative-item .creative-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
        }
        .creative-item .creative-number {
            font-weight: 600;
            color: rgb(30, 157, 241);
        }
        .creative-item .creative-video-name {
            color: rgb(15, 20, 25);
            font-size: 13px;
        }
        .creative-item .btn-remove {
            background: rgb(244, 33, 46);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 5px 10px;
            cursor: pointer;
        }
        .creative-item .creative-body {
            padding: 15px;
        }
        .creative-item .creative-body textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 6px);
            resize: vertical;
        }
        /* Age Selection Toggle Buttons */
        .age-selection-container {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .age-toggle-btn {
            padding: 8px 16px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
            color: #333;
            transition: all 0.2s ease;
        }
        .age-toggle-btn:hover {
            border-color: #00b8a9;
        }
        .age-toggle-btn.selected {
            border-color: #00b8a9;
            background: #e6f7f5;
            color: #00b8a9;
        }
        .age-toggle-btn.selected::after {
            content: ' ✓';
            font-size: 12px;
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

                <div class="form-section">
                    <h3>Budget Settings</h3>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="cbo-enabled" checked onchange="toggleCBOBudget()" style="width: 20px; height: 20px; accent-color: rgb(30, 157, 241);">
                            <span style="font-weight: 600;">Campaign Budget Optimization (CBO)</span>
                        </label>
                        <small style="display: block; margin-top: 8px; margin-left: 30px; color: #666;">
                            When enabled, budget is set at campaign level and TikTok optimizes across ad groups.<br>
                            When disabled, you set budget individually at ad group level.
                        </small>
                    </div>
                    <div id="campaign-budget-section">
                        <div class="form-group">
                            <label>Daily Budget ($)</label>
                            <input type="number" id="campaign-budget" value="50" min="20" placeholder="50">
                            <small>Minimum $20 daily budget. Default: $50</small>
                        </div>
                    </div>
                    <div id="campaign-cbo-disabled-note" style="display: none; padding: 15px; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                        <p style="margin: 0; color: #856404;"><strong>CBO Disabled:</strong> You will set the budget at Ad Group level in Step 2.</p>
                    </div>
                </div>

                <div class="form-info smart-info">
                    <p><strong>Objective:</strong> Lead Generation</p>
                    <p><strong>Type:</strong> Smart+ Campaign (AI-Optimized)</p>
                    <p><strong>Budget Mode:</strong> Dynamic Daily Budget</p>
                </div>
                <button class="btn-primary" onclick="createCampaign()">Create Campaign →</button>
            </div>

            <!-- Step 2: Ad Group Creation -->
            <div class="step-content" id="step-2">
                <h2>Smart+ Ad Group Settings</h2>
                <div class="form-info" style="margin-bottom: 20px; background: #e8f5e9; padding: 12px; border-radius: 6px;">
                    <p><strong>Campaign:</strong> <span id="display-campaign-name">-</span></p>
                    <p><strong>Campaign ID:</strong> <span id="display-campaign-id" style="color: #22c55e; font-weight: bold;">-</span></p>
                    <p id="display-budget-info"><strong>Budget:</strong> $<span id="display-budget">-</span>/day (Campaign Level)</p>
                </div>

                <div class="form-section">
                    <h3>Budget & Schedule</h3>
                    <div id="adgroup-budget-section" style="display: none;">
                        <div class="form-group">
                            <label>Ad Group Daily Budget ($)</label>
                            <input type="number" id="adgroup-budget" placeholder="50" min="20" value="50">
                            <small>Minimum $20 daily budget. This budget applies to this ad group only.</small>
                        </div>
                    </div>
                    <div id="cbo-budget-note" style="padding: 15px; background: #e8f5e9; border-radius: 6px; margin-bottom: 15px;">
                        <p style="margin: 0; color: #2e7d32;"><strong>✓ Campaign Budget Optimization is enabled</strong></p>
                        <small>Budget is managed at campaign level ($<span id="cbo-budget-display">50</span>/day). TikTok will optimize spend across ad groups.</small>
                    </div>
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
                    <h3>Audience Targeting</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Age</label>
                            <div class="age-selection-container" id="age-selection-container">
                                <button type="button" class="age-toggle-btn selected" data-age="AGE_18_24">18-24</button>
                                <button type="button" class="age-toggle-btn selected" data-age="AGE_25_34">25-34</button>
                                <button type="button" class="age-toggle-btn selected" data-age="AGE_35_44">35-44</button>
                                <button type="button" class="age-toggle-btn selected" data-age="AGE_45_54">45-54</button>
                                <button type="button" class="age-toggle-btn" data-age="AGE_55_100">55+</button>
                            </div>
                            <small>Select one or more age ranges for targeting</small>
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
                        <div style="margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 10px;">
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('all')" title="All hours, all days">24/7 (All Hours)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('business')" title="8AM-5PM, Monday-Friday">Business (8AM-5PM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('prime')" title="6PM-11PM, all days">Prime Time (6PM-11PM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('evening')" title="5PM-12AM, all days">Evening (5PM-12AM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('daytime')" title="6AM-6PM, all days">Daytime (6AM-6PM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('none')" title="Clear all selections">Clear All</button>
                        </div>
                        <div class="dayparting-grid">
                            <table class="dayparting-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th title="12:00 AM">12A</th>
                                        <th title="1:00 AM">1A</th>
                                        <th title="2:00 AM">2A</th>
                                        <th title="3:00 AM">3A</th>
                                        <th title="4:00 AM">4A</th>
                                        <th title="5:00 AM">5A</th>
                                        <th title="6:00 AM">6A</th>
                                        <th title="7:00 AM">7A</th>
                                        <th title="8:00 AM">8A</th>
                                        <th title="9:00 AM">9A</th>
                                        <th title="10:00 AM">10A</th>
                                        <th title="11:00 AM">11A</th>
                                        <th title="12:00 PM">12P</th>
                                        <th title="1:00 PM">1P</th>
                                        <th title="2:00 PM">2P</th>
                                        <th title="3:00 PM">3P</th>
                                        <th title="4:00 PM">4P</th>
                                        <th title="5:00 PM">5P</th>
                                        <th title="6:00 PM">6P</th>
                                        <th title="7:00 PM">7P</th>
                                        <th title="8:00 PM">8P</th>
                                        <th title="9:00 PM">9P</th>
                                        <th title="10:00 PM">10P</th>
                                        <th title="11:00 PM">11P</th>
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
                    <button class="btn-primary" onclick="createAdGroup()">Create Ad Group →</button>
                </div>
            </div>

            <!-- Step 3: Ads Creation -->
            <div class="step-content" id="step-3">
                <h2>Create Smart+ Ad</h2>
                <div class="form-info" style="margin-bottom: 20px; background: #e8f5e9; padding: 12px; border-radius: 6px;">
                    <p><strong>Campaign ID:</strong> <span id="display-campaign-id-step3" style="color: #22c55e; font-weight: bold;">-</span></p>
                    <p><strong>Ad Group ID:</strong> <span id="display-adgroup-id" style="color: #22c55e; font-weight: bold;">-</span></p>
                </div>
                <div class="form-info smart-info" style="margin-bottom: 20px;">
                    <p><strong>Smart+ Ad:</strong> Select multiple videos below. All videos will be combined into ONE ad with multiple creatives.</p>
                </div>

                <!-- Global Settings -->
                <div class="form-section" style="background: #f8f9ff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 2px solid #667eea;">
                    <h3 style="margin-top: 0; color: #667eea;">Ad Settings</h3>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Identity</label>
                            <select id="global-identity" required>
                                <option value="">Select identity...</option>
                            </select>
                            <button type="button" class="btn-secondary" onclick="openCreateIdentityModal()" style="margin-top: 8px; width: 100%;">+ Create New Identity</button>
                        </div>
                        <div class="form-group">
                            <label>Dynamic CTA Portfolio <span style="color: #ff0050;">*</span></label>
                            <select id="cta-portfolio-select" required>
                                <option value="">Loading portfolios...</option>
                            </select>
                            <div style="display: flex; gap: 10px; margin-top: 8px;">
                                <button type="button" class="btn-secondary" onclick="useFrequentlyUsedCTAs()" style="flex: 1;">Use Frequently Used CTAs</button>
                                <button type="button" class="btn-secondary" onclick="openCreatePortfolioModal()" style="flex: 1;">+ Create Portfolio</button>
                            </div>
                            <div id="selected-portfolio-info" style="display: none; margin-top: 10px; padding: 10px; background: #e8f5e9; border-radius: 6px;">
                                <strong>Selected:</strong> <span id="portfolio-name-display"></span><br>
                                <small>CTAs: <span id="portfolio-ctas-display"></span></small>
                            </div>
                            <small style="color: #666;">Lead Gen campaigns require a Dynamic CTA Portfolio. TikTok will optimize which CTA to show.</small>
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

                <!-- Media Library Section -->
                <div class="form-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0;">Media Library</h3>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn-secondary" onclick="refreshMediaLibrary()" title="Refresh from TikTok">🔄 Refresh</button>
                            <button class="btn-primary" onclick="openUploadModal('video')" style="background: linear-gradient(135deg, #667eea, #764ba2);">📹 Upload Video</button>
                            <button class="btn-primary" onclick="openUploadModal('image')" style="background: linear-gradient(135deg, #4fc3f7, #29b6f6);">🖼️ Upload Image</button>
                        </div>
                    </div>

                    <!-- Videos Section -->
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: #667eea;">🎬 Videos (<span id="selected-videos-count">0</span> selected)</h4>
                            <div>
                                <button class="btn-secondary btn-sm" onclick="selectAllVideos()">Select All</button>
                                <button class="btn-secondary btn-sm" onclick="clearVideoSelection()">Clear</button>
                            </div>
                        </div>
                        <div id="video-selection-grid" class="video-selection-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; max-height: 300px; overflow-y: auto; padding: 10px; background: #f9f9f9; border-radius: 8px; border: 2px solid #667eea;">
                            <p style="text-align: center; padding: 20px; color: #666;">Loading videos...</p>
                        </div>
                    </div>

                    <!-- Images Section -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: #4fc3f7;">🖼️ Images (<span id="images-count">0</span> available)</h4>
                        </div>
                        <div id="image-selection-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; max-height: 200px; overflow-y: auto; padding: 10px; background: #f0f9ff; border-radius: 8px; border: 2px solid #4fc3f7;">
                            <p style="text-align: center; padding: 20px; color: #666;">Loading images...</p>
                        </div>
                        <small style="color: #666; display: block; margin-top: 8px;">Images are used as cover images for videos. TikTok will auto-match or you can upload matching covers.</small>
                    </div>
                </div>

                <!-- Ad Text Section (Single text field like TikTok Ads Manager) -->
                <div class="form-section">
                    <h3>Identity and Text for your Ad</h3>
                    <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Your TikTok posts in this campaign will use the creator's original identity and text.</p>

                    <!-- Selected Videos Summary -->
                    <div id="selected-videos-summary" style="margin-bottom: 20px; padding: 15px; background: #f8f9ff; border-radius: 8px; border: 2px solid #667eea;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-weight: 600; color: #333;">Creative assets (<span id="creative-assets-count">0</span>/<span>50</span>)</span>
                            <button type="button" class="btn-secondary btn-sm" onclick="scrollToMediaSection()">✏️ Edit selections</button>
                        </div>
                        <div id="selected-videos-preview" style="display: flex; gap: 10px; flex-wrap: wrap; max-height: 120px; overflow-y: auto;">
                            <p style="color: #666; font-size: 13px;">No videos selected yet</p>
                        </div>
                    </div>

                    <!-- Ad Text Fields -->
                    <div class="form-group">
                        <label style="font-weight: 600;">Text <span style="color: #999; font-weight: normal;">(0/100)</span></label>
                        <div id="ad-text-fields" style="display: flex; flex-direction: column; gap: 10px;">
                            <div class="ad-text-field" style="display: flex; align-items: center; gap: 10px;">
                                <input type="text" id="ad-text-1" class="ad-text-input" placeholder="Enter text for your ad" maxlength="100" style="flex: 1;" oninput="updateTextCount(this)">
                                <span class="text-count" style="color: #999; font-size: 12px;">0/100</span>
                            </div>
                        </div>
                        <button type="button" id="add-text-btn" onclick="addAdTextField()" style="margin-top: 10px; background: none; border: none; color: #1e9df1; cursor: pointer; font-size: 14px; padding: 5px 0;">
                            + Add text
                        </button>
                        <small style="display: block; margin-top: 8px; color: #666;">Add multiple text variations. TikTok will automatically optimize which text performs best.</small>
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
                    <button class="btn-success" onclick="createAd()">✓ Create Ad & Publish</button>
                </div>
            </div>
        </div>

        <!-- Upload Modal -->
        <div id="upload-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3 id="upload-modal-title">Upload Media</h3>
                    <span class="modal-close" onclick="closeUploadModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="upload-area" id="upload-area" style="text-align: center; padding: 40px; border: 2px dashed #ddd; border-radius: 8px; cursor: pointer;">
                        <input type="file" id="media-file-input" accept="image/*,video/*" style="display: none;" onchange="handleSmartMediaUpload(event)">
                        <div onclick="document.getElementById('media-file-input').click()">
                            <div id="upload-icon" style="font-size: 50px; margin-bottom: 10px;">📁</div>
                            <p id="upload-text" style="font-size: 16px; color: #333;">Click to select file or drag and drop</p>
                            <p id="upload-hint" style="font-size: 12px; color: #666;">Supported: MP4, MOV, JPG, PNG</p>
                        </div>
                    </div>
                    <div id="upload-progress" style="display: none; margin-top: 20px;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div class="spinner" style="width: 30px; height: 30px;"></div>
                            <div style="flex: 1;">
                                <p id="upload-status" style="margin: 0; font-weight: 600;">Uploading...</p>
                                <div style="background: #e0e0e0; border-radius: 10px; height: 8px; margin-top: 8px; overflow: hidden;">
                                    <div id="upload-progress-bar" style="background: linear-gradient(135deg, #667eea, #764ba2); height: 100%; width: 0%; transition: width 0.3s;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="upload-success" style="display: none; margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 8px; border: 2px solid #4caf50;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="font-size: 30px;">✅</div>
                            <div>
                                <p style="margin: 0; font-weight: 600; color: #2e7d32;">Upload Successful!</p>
                                <p id="upload-success-name" style="margin: 5px 0 0 0; font-size: 13px; color: #666;"></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeUploadModal()">Close</button>
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

        <!-- Create Portfolio Modal -->
        <div id="create-portfolio-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>Create CTA Portfolio</h3>
                    <span class="modal-close" onclick="closeCreatePortfolioModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Portfolio Name</label>
                        <input type="text" id="portfolio-name-input" placeholder="My CTA Portfolio" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>Select CTAs (1-5)</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; max-height: 200px; overflow-y: auto; padding: 10px; background: #f9f9f9; border-radius: 6px;">
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="LEARN_MORE" checked> Learn More
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="GET_QUOTE" checked> Get Quote
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="SIGN_UP"> Sign Up
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="CONTACT_US"> Contact Us
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="APPLY_NOW"> Apply Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="DOWNLOAD"> Download
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="SHOP_NOW"> Shop Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="ORDER_NOW"> Order Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="BOOK_NOW"> Book Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="GET_STARTED"> Get Started
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeCreatePortfolioModal()">Cancel</button>
                    <button class="btn-primary" onclick="createCtaPortfolio()">Create Portfolio</button>
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
