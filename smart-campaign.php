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

        <!-- Main View Tabs -->
        <div class="main-view-tabs">
            <button class="main-view-tab active" id="tab-create" onclick="switchMainView('create')">
                <span class="tab-icon">✏️</span> Create Campaign
            </button>
            <button class="main-view-tab" id="tab-campaigns" onclick="switchMainView('campaigns')">
                <span class="tab-icon">📋</span> My Campaigns
            </button>
        </div>

        <!-- CREATE VIEW (existing functionality) -->
        <div id="create-view">

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
                            <label>Age Targeting</label>
                            <div class="age-radio-container" id="age-selection-container">
                                <label class="age-radio-option">
                                    <input type="radio" name="age_targeting" value="18+" checked onchange="updateAgeSelection('18+')">
                                    <span class="age-radio-label">
                                        <strong>18+</strong>
                                        <small>All Adults (18-24, 25-34, 35-44, 45-54, 55+)</small>
                                    </span>
                                </label>
                                <label class="age-radio-option">
                                    <input type="radio" name="age_targeting" value="25+" onchange="updateAgeSelection('25+')">
                                    <span class="age-radio-label">
                                        <strong>25+</strong>
                                        <small>Older Adults (25-34, 35-44, 45-54, 55+)</small>
                                    </span>
                                </label>
                            </div>
                            <small>Select minimum age for targeting (matches TikTok Ads Manager options)</small>
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

                <!-- Duplicate Campaign Section (Single Launch) -->
                <div class="review-section" style="margin-top: 30px;">
                    <h3>Campaign Copies</h3>
                    <div class="duplicate-campaign-section" style="padding: 20px; background: #f8f9ff; border-radius: 8px; border: 2px solid #e0e0e0;">
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="enable-duplicates" onchange="toggleDuplicates()" style="width: 20px; height: 20px; accent-color: #667eea;">
                                <span style="font-weight: 600;">Create multiple copies of this campaign</span>
                            </label>
                            <small style="display: block; margin-top: 8px; margin-left: 30px; color: #666;">
                                Launch several identical campaigns at once with auto-numbered names.
                            </small>
                        </div>
                        <div id="duplicate-settings" style="display: none;">
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label style="font-weight: 500;">Total number of campaigns to create:</label>
                                <div style="display: flex; align-items: center; gap: 15px; margin-top: 8px;">
                                    <input type="number" id="duplicate-count" min="1" max="20" value="2"
                                           style="width: 80px; padding: 10px; border: 2px solid #667eea; border-radius: 6px; font-size: 16px; text-align: center;"
                                           onchange="updateDuplicatePreview()" oninput="updateDuplicatePreview()">
                                    <span style="color: #666;">campaigns (1-20)</span>
                                </div>
                                <small style="display: block; margin-top: 8px; color: #888;">
                                    Enter 1 to create just the original campaign, or more to create multiple copies.
                                </small>
                            </div>
                            <div class="duplicate-preview" style="margin-top: 15px; padding: 12px; background: white; border-radius: 6px; border: 1px solid #ddd;">
                                <p style="margin: 0 0 8px 0; font-weight: 500; color: #333;">Preview:</p>
                                <div id="duplicate-preview-names" style="font-size: 13px; color: #666;">
                                    <!-- Will be populated by JS -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Launch Options Section -->
                <div class="review-section" style="margin-top: 30px;">
                    <h3>Launch Options</h3>

                    <div class="launch-options-container">
                        <!-- Option 1: Single Account -->
                        <div class="launch-option-card" id="single-launch-option">
                            <div class="launch-option-header">
                                <input type="radio" name="launch_mode" value="single" id="launch-mode-single" checked onchange="toggleLaunchMode()">
                                <label for="launch-mode-single">
                                    <span class="launch-option-icon">🚀</span>
                                    <span class="launch-option-title">Launch to Current Account Only</span>
                                </label>
                            </div>
                            <div class="launch-option-body">
                                <p class="launch-option-desc">Launch campaign to the currently selected advertiser account.</p>
                                <div class="current-account-info">
                                    <span class="account-label">Account:</span>
                                    <span class="account-name" id="current-account-name">Loading...</span>
                                </div>
                            </div>
                        </div>

                        <!-- Option 2: Bulk Launch -->
                        <div class="launch-option-card" id="bulk-launch-option">
                            <div class="launch-option-header">
                                <input type="radio" name="launch_mode" value="bulk" id="launch-mode-bulk" onchange="toggleLaunchMode()">
                                <label for="launch-mode-bulk">
                                    <span class="launch-option-icon">⚡</span>
                                    <span class="launch-option-title">Bulk Launch to Multiple Accounts</span>
                                </label>
                            </div>
                            <div class="launch-option-body">
                                <p class="launch-option-desc">Launch the same campaign to multiple advertiser accounts at once.</p>
                                <div id="bulk-accounts-preview" style="display: none;">
                                    <span class="accounts-count"><span id="available-accounts-count">0</span> accounts available</span>
                                    <button type="button" class="btn-configure-bulk" onclick="openBulkLaunchModal()">Configure Accounts →</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Launch Summary (shows after configuration) -->
                    <div id="bulk-launch-summary" style="display: none; margin-top: 20px;">
                        <div class="bulk-summary-card">
                            <h4>Bulk Launch Configuration</h4>
                            <div class="bulk-summary-stats">
                                <div class="stat-item">
                                    <span class="stat-value" id="bulk-selected-count">0</span>
                                    <span class="stat-label">Accounts Selected</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-value" id="bulk-total-budget">$0</span>
                                    <span class="stat-label">Total Daily Budget</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-value" id="bulk-ready-count">0</span>
                                    <span class="stat-label">Ready to Launch</span>
                                </div>
                            </div>
                            <div id="bulk-accounts-list" class="bulk-accounts-list">
                                <!-- Populated by JavaScript -->
                            </div>
                            <button type="button" class="btn-secondary" onclick="openBulkLaunchModal()">Edit Configuration</button>
                        </div>
                    </div>
                </div>

                <div class="button-row">
                    <button class="btn-secondary" onclick="prevStep()">← Back</button>
                    <button class="btn-success" id="launch-button" onclick="handleLaunch()">🚀 Launch Campaign</button>
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

        <!-- Bulk Launch Modal -->
        <div id="bulk-launch-modal" class="modal" style="display: none;">
            <div class="modal-content bulk-launch-modal-content">
                <div class="modal-header">
                    <h3>⚡ Bulk Launch Configuration</h3>
                    <span class="modal-close" onclick="closeBulkLaunchModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <!-- Campaign Info Banner -->
                    <div class="bulk-campaign-info">
                        <strong>Campaign:</strong> <span id="bulk-campaign-name">-</span> |
                        <strong>Budget:</strong> $<span id="bulk-campaign-budget">0</span>/day per account
                    </div>

                    <!-- Duplicate Campaign Section -->
                    <div class="bulk-section">
                        <h4>📋 Campaign Copies</h4>
                        <p class="bulk-section-desc">Create multiple copies of this campaign per account.</p>
                        <div class="duplicate-options">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" id="bulk-enable-duplicates" onchange="toggleBulkDuplicates()" style="width: 18px; height: 18px;">
                                    <span>Create multiple campaign copies per account</span>
                                </label>
                            </div>
                            <div id="bulk-duplicate-settings" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9ff; border-radius: 8px; border: 1px solid #667eea;">
                                <div class="form-group" style="margin-bottom: 10px;">
                                    <label>Total campaigns per account:</label>
                                    <input type="number" id="bulk-duplicate-count" min="1" max="10" value="2" style="width: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                <p style="margin: 0; font-size: 12px; color: #666;">
                                    Campaign names will be auto-numbered: "Campaign Name (1)", "Campaign Name (2)", etc.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Video Distribution Section -->
                    <div class="bulk-section">
                        <h4>📹 Video Distribution</h4>
                        <p class="bulk-section-desc">Your selected videos need to exist in each target account.</p>
                        <div class="video-options">
                            <label class="video-option">
                                <input type="radio" name="video_distribution" value="match" checked onchange="toggleVideoDistribution()">
                                <span>Videos already exist in all accounts (match by file name)</span>
                            </label>
                            <label class="video-option">
                                <input type="radio" name="video_distribution" value="upload" onchange="toggleVideoDistribution()">
                                <span>Upload videos to selected accounts now</span>
                            </label>
                        </div>

                        <!-- Video Upload UI (shown when upload option selected) -->
                        <div id="video-upload-section" style="display: none; margin-top: 15px;">
                            <div class="video-upload-info" style="padding: 15px; background: #fff8e6; border-radius: 8px; border: 1px solid #ffc107; margin-bottom: 15px;">
                                <p style="margin: 0 0 10px 0; font-weight: 600; color: #856404;">
                                    <span style="font-size: 18px;">📤</span> Video Upload Required
                                </p>
                                <p style="margin: 0; font-size: 13px; color: #856404;">
                                    Your <span id="upload-video-count">0</span> selected videos will be uploaded to each selected account before launching.
                                </p>
                            </div>

                            <!-- Upload Progress Container -->
                            <div id="video-upload-progress-container" style="display: none;">
                                <div class="upload-progress-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <span style="font-weight: 600;">Uploading Videos...</span>
                                    <span id="upload-progress-text">0 / 0</span>
                                </div>
                                <div class="progress-bar-container" style="background: #e0e0e0; border-radius: 10px; height: 12px; overflow: hidden; margin-bottom: 10px;">
                                    <div id="video-upload-bar" class="progress-bar-fill" style="background: linear-gradient(135deg, #667eea, #764ba2); height: 100%; width: 0%; transition: width 0.3s;"></div>
                                </div>
                                <div id="video-upload-details" style="max-height: 150px; overflow-y: auto; font-size: 12px; background: #f9f9f9; border-radius: 6px; padding: 10px;">
                                    <!-- Upload status per account will be shown here -->
                                </div>
                            </div>

                            <!-- Upload Button -->
                            <button type="button" id="start-upload-btn" class="btn-primary" onclick="startBulkVideoUpload()" style="width: 100%; margin-top: 10px; background: linear-gradient(135deg, #667eea, #764ba2);">
                                📤 Upload Videos to Selected Accounts
                            </button>

                            <!-- Upload Complete Status -->
                            <div id="upload-complete-status" style="display: none; padding: 15px; background: #e8f5e9; border-radius: 8px; border: 1px solid #4caf50; margin-top: 15px;">
                                <p style="margin: 0; color: #2e7d32; font-weight: 600;">
                                    <span style="font-size: 18px;">✅</span> Videos uploaded successfully!
                                </p>
                                <p id="upload-complete-details" style="margin: 5px 0 0 0; font-size: 13px; color: #2e7d32;"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Accounts Selection Section -->
                    <div class="bulk-section">
                        <h4>Select Accounts & Configure</h4>
                        <div class="bulk-accounts-header">
                            <button type="button" class="btn-sm" onclick="selectAllBulkAccounts()">Select All</button>
                            <button type="button" class="btn-sm" onclick="deselectAllBulkAccounts()">Deselect All</button>
                            <span class="accounts-selected-text"><span id="modal-selected-count">0</span> selected</span>
                        </div>

                        <div id="bulk-accounts-container" class="bulk-accounts-container">
                            <!-- Account cards will be populated by JavaScript -->
                            <div class="loading-accounts">
                                <div class="spinner-small"></div>
                                <span>Loading accounts...</span>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Section -->
                    <div class="bulk-modal-summary">
                        <div class="summary-item">
                            <span class="summary-label">Total Accounts:</span>
                            <span class="summary-value" id="modal-total-accounts">0</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Ready to Launch:</span>
                            <span class="summary-value" id="modal-ready-accounts">0</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Total Daily Budget:</span>
                            <span class="summary-value" id="modal-total-budget">$0</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeBulkLaunchModal()">Cancel</button>
                    <button class="btn-primary" id="confirm-bulk-config-btn" onclick="confirmBulkConfiguration()">Confirm Configuration</button>
                </div>
            </div>
        </div>

        <!-- Bulk Launch Progress Modal -->
        <div id="bulk-progress-modal" class="modal" style="display: none;">
            <div class="modal-content bulk-progress-modal-content">
                <div class="modal-header">
                    <h3>🚀 Launching Campaigns...</h3>
                </div>
                <div class="modal-body">
                    <div class="bulk-progress-stats">
                        <div class="progress-stat">
                            <span class="progress-stat-value" id="progress-completed">0</span>
                            <span class="progress-stat-label">Completed</span>
                        </div>
                        <div class="progress-stat">
                            <span class="progress-stat-value" id="progress-total">0</span>
                            <span class="progress-stat-label">Total</span>
                        </div>
                        <div class="progress-stat success">
                            <span class="progress-stat-value" id="progress-success">0</span>
                            <span class="progress-stat-label">Success</span>
                        </div>
                        <div class="progress-stat failed">
                            <span class="progress-stat-value" id="progress-failed">0</span>
                            <span class="progress-stat-label">Failed</span>
                        </div>
                    </div>

                    <div class="bulk-progress-bar-container">
                        <div id="bulk-progress-bar" class="bulk-progress-bar" style="width: 0%;"></div>
                    </div>

                    <div id="bulk-progress-list" class="bulk-progress-list">
                        <!-- Progress items will be added here -->
                    </div>
                </div>
                <div class="modal-footer" id="bulk-progress-footer" style="display: none;">
                    <button class="btn-primary" onclick="closeBulkProgressModal()">Done</button>
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

        </div><!-- End of create-view -->

        <!-- CAMPAIGNS VIEW (My Campaigns) -->
        <div id="campaigns-view" style="display: none;">
            <!-- Campaigns Header -->
            <div class="campaigns-header">
                <h2>📋 My Campaigns</h2>
                <div class="campaigns-actions">
                    <button class="btn-secondary" onclick="refreshCampaignList()">🔄 Refresh</button>
                </div>
            </div>

            <!-- Campaign Filters -->
            <div class="campaign-filters">
                <button class="campaign-filter-btn active" data-filter="all" onclick="filterCampaignsByStatus('all')">
                    All <span class="filter-count" id="count-all">0</span>
                </button>
                <button class="campaign-filter-btn" data-filter="active" onclick="filterCampaignsByStatus('active')">
                    Active <span class="filter-count" id="count-active">0</span>
                </button>
                <button class="campaign-filter-btn" data-filter="inactive" onclick="filterCampaignsByStatus('inactive')">
                    Inactive <span class="filter-count" id="count-inactive">0</span>
                </button>
            </div>

            <!-- Bulk Actions Bar -->
            <div class="bulk-actions-bar" id="bulk-actions-bar">
                <div class="bulk-select-controls">
                    <label class="select-all-checkbox">
                        <input type="checkbox" id="select-all-campaigns" onchange="toggleSelectAllCampaigns()">
                        <span>Select All</span>
                    </label>
                    <span class="selected-count" id="selected-campaigns-count">0 selected</span>
                </div>
                <div class="bulk-action-buttons" id="bulk-action-buttons" style="display: none;">
                    <button class="btn-bulk-action btn-enable" onclick="bulkToggleCampaigns('ENABLE')">
                        <span class="btn-icon">▶</span> Turn ON Selected
                    </button>
                    <button class="btn-bulk-action btn-disable" onclick="bulkToggleCampaigns('DISABLE')">
                        <span class="btn-icon">⏸</span> Turn OFF Selected
                    </button>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="campaign-search-container">
                <input type="text"
                       id="campaign-search-input"
                       class="campaign-search-input"
                       placeholder="🔍 Search campaigns by name..."
                       oninput="searchCampaigns()">
            </div>

            <!-- Campaign List -->
            <div class="campaign-list" id="campaign-list">
                <!-- Loading State -->
                <div class="campaign-loading" id="campaign-loading">
                    <div class="spinner"></div>
                    <p>Loading campaigns...</p>
                </div>

                <!-- Empty State (hidden by default) -->
                <div class="campaign-empty-state" id="campaign-empty-state" style="display: none;">
                    <div class="empty-icon">📭</div>
                    <h3>No campaigns found</h3>
                    <p>You haven't created any campaigns yet, or no campaigns match your filter.</p>
                    <button class="btn-primary" onclick="switchMainView('create')">Create Your First Campaign</button>
                </div>

                <!-- Campaign Cards Container -->
                <div id="campaign-cards-container">
                    <!-- Campaign cards will be rendered here by JavaScript -->
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

    <!-- Video Picker Modal -->
    <div id="video-picker-modal" class="modal video-picker-modal" style="display: none;">
        <div class="modal-content video-picker-modal-content">
            <div class="modal-header">
                <h3>📹 Select Video from Library</h3>
                <span class="modal-close" onclick="closeVideoPickerModal()">&times;</span>
            </div>
            <div class="modal-body">
                <!-- Source Video Info -->
                <div class="picker-source-info" id="picker-source-info">
                    <span class="picker-label">Mapping for:</span>
                    <span class="picker-source-name" id="picker-source-name">-</span>
                </div>

                <!-- Search Input -->
                <div class="picker-search-container">
                    <input type="text"
                           id="video-picker-search"
                           class="picker-search-input"
                           placeholder="🔍 Search videos by name..."
                           oninput="filterVideoPickerResults()">
                    <span class="picker-video-count" id="picker-video-count">0 videos</span>
                </div>

                <!-- Video Grid -->
                <div class="picker-video-grid" id="picker-video-grid">
                    <!-- Videos will be rendered here -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeVideoPickerModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Duplicate Campaign Modal -->
    <div id="duplicate-campaign-modal" class="modal" style="display: none;">
        <div class="modal-content duplicate-modal-content">
            <div class="modal-header">
                <h3>📋 Duplicate Campaign</h3>
                <span class="modal-close" onclick="closeDuplicateCampaignModal()">&times;</span>
            </div>
            <div class="modal-body">
                <!-- Campaign Info -->
                <div class="duplicate-campaign-info">
                    <div class="info-row">
                        <span class="info-label">Campaign:</span>
                        <span class="info-value" id="duplicate-campaign-name">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Campaign ID:</span>
                        <span class="info-value" id="duplicate-campaign-id">-</span>
                    </div>
                </div>

                <!-- Loading State -->
                <div id="duplicate-loading-state" style="display: none; text-align: center; padding: 30px;">
                    <div class="spinner"></div>
                    <p style="margin-top: 15px; color: #666;">Fetching campaign details...</p>
                </div>

                <!-- Campaign Details (shown after loading) -->
                <div id="duplicate-details-section" style="display: none;">
                    <div class="duplicate-details-summary">
                        <h4>Campaign Structure</h4>
                        <div class="structure-item">
                            <span class="structure-icon">📢</span>
                            <span class="structure-label">Campaign:</span>
                            <span class="structure-value" id="dup-detail-campaign">-</span>
                        </div>
                        <div class="structure-item">
                            <span class="structure-icon">📦</span>
                            <span class="structure-label">Ad Group:</span>
                            <span class="structure-value" id="dup-detail-adgroup">-</span>
                        </div>
                        <div class="structure-item">
                            <span class="structure-icon">🎬</span>
                            <span class="structure-label">Ad:</span>
                            <span class="structure-value" id="dup-detail-ad">-</span>
                        </div>
                    </div>

                    <!-- Landing Page URL Input (required for Smart+ duplication) -->
                    <div class="duplicate-landing-url-section" id="duplicate-landing-url-section" style="margin-bottom: 20px;">
                        <label for="duplicate-landing-url" style="font-weight: 600; margin-bottom: 8px; display: block;">
                            Landing Page URL <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="url" id="duplicate-landing-url" placeholder="https://example.com/landing-page"
                               style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                        <small style="color: #666; margin-top: 4px; display: block;">
                            Enter the landing page URL for the duplicated campaigns (TikTok API doesn't return this for Smart+ ads)
                        </small>
                    </div>

                    <!-- Number of Copies Input -->
                    <div class="duplicate-count-section">
                        <label for="duplicate-copy-count">Number of copies to create:</label>
                        <div class="count-input-wrapper">
                            <button type="button" class="count-btn minus" onclick="adjustDuplicateCount(-1)">−</button>
                            <input type="number" id="duplicate-copy-count" min="1" max="20" value="1"
                                   onchange="updateDuplicatePreviewList()" oninput="updateDuplicatePreviewList()">
                            <button type="button" class="count-btn plus" onclick="adjustDuplicateCount(1)">+</button>
                        </div>
                        <small>Maximum 20 copies at a time</small>
                    </div>

                    <!-- Preview of Names -->
                    <div class="duplicate-preview-section">
                        <h4>Preview</h4>
                        <p class="preview-description">The following campaigns will be created:</p>
                        <div class="duplicate-preview-list" id="duplicate-preview-list">
                            <!-- Preview items will be rendered here -->
                        </div>
                    </div>

                    <!-- What will be duplicated -->
                    <div class="duplicate-includes-section">
                        <h4>Each copy will include:</h4>
                        <ul class="includes-list">
                            <li><span class="check-icon">✓</span> Campaign settings (budget, objective)</li>
                            <li><span class="check-icon">✓</span> Ad Group (targeting, pixel, schedule)</li>
                            <li><span class="check-icon">✓</span> Ad (videos, identity, CTA, landing URL)</li>
                        </ul>
                    </div>
                </div>

                <!-- Progress Section (shown during duplication) -->
                <div id="duplicate-progress-section" style="display: none;">
                    <div class="duplicate-progress-header">
                        <span>Creating duplicates...</span>
                        <span id="duplicate-progress-text">0 / 0</span>
                    </div>
                    <div class="duplicate-progress-bar-container">
                        <div class="duplicate-progress-bar" id="duplicate-progress-bar" style="width: 0%;"></div>
                    </div>
                    <div class="duplicate-progress-log" id="duplicate-progress-log">
                        <!-- Progress log items will be added here -->
                    </div>
                </div>

                <!-- Success Section (shown after completion) -->
                <div id="duplicate-success-section" style="display: none;">
                    <div class="duplicate-success-icon">✅</div>
                    <h4>Duplication Complete!</h4>
                    <p id="duplicate-success-message">Successfully created 0 campaigns.</p>
                    <div class="duplicate-results-summary" id="duplicate-results-summary">
                        <!-- Results will be shown here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="duplicate-modal-footer">
                <button class="btn-secondary" onclick="closeDuplicateCampaignModal()">Cancel</button>
                <button class="btn-primary" id="duplicate-create-btn" onclick="executeDuplicateCampaign()" disabled>
                    📋 Create Copies
                </button>
            </div>
        </div>
    </div>

    <script src="assets/smart-campaign.js?v=<?php echo time(); ?>"></script>
</body>
</html>
