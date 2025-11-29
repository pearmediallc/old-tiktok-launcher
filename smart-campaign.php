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
                    <span id="timezone-status">Loading timezone...</span>
                </div>
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
                    <h3>Campaign Budget</h3>
                    <div class="form-group">
                        <label>Daily Budget ($)</label>
                        <input type="number" id="campaign-budget" value="50" min="20" placeholder="50">
                        <small>Minimum $20 daily budget. Smart+ uses dynamic daily budget allocation.</small>
                    </div>
                </div>

                <div class="form-info smart-info">
                    <p><strong>Objective:</strong> Lead Generation</p>
                    <p><strong>Type:</strong> Smart+ Campaign (AI-Optimized)</p>
                    <p><strong>Budget Mode:</strong> Dynamic Daily Budget</p>
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

                <div class="form-section">
                    <h3>Optimization & Placement</h3>
                    <div class="form-info smart-info">
                        <p><strong>Promotion Type:</strong> Lead Generation (External Website)</p>
                        <p><strong>Optimization Goal:</strong> Conversions</p>
                        <p><strong>Billing Event:</strong> OCPM</p>
                        <p><strong>Location:</strong> United States</p>
                        <p><strong>Targeting:</strong> AI-Optimized by Smart+</p>
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

                <div class="ads-container" id="ads-container">
                    <!-- Ad forms will be dynamically added here -->
                </div>

                <div class="button-row" style="align-items: center; gap: 15px;">
                    <button class="btn-secondary" onclick="duplicateAd(1)">+ Duplicate Last Ad</button>
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

        <!-- Create Identity Modal -->
        <div id="create-identity-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>Create new custom identity</h3>
                    <span class="modal-close" onclick="closeCreateIdentityModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div style="display: flex; gap: 20px; align-items: flex-start; margin-bottom: 20px;">
                        <div style="flex-shrink: 0;">
                            <div id="identity-avatar-preview" style="width: 80px; height: 80px; border-radius: 50%; background: #f0f0f0; overflow: hidden; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 2px solid #ddd;" onclick="selectIdentityAvatar()">
                                <img id="identity-avatar-img" src="https://sf16-sg.tiktokcdn.com/obj/eden-sg/lm_zkh_rvarpa/ljhwZthlaukjlkulzlp/ads_manager_creation/default-avatar.png"
                                     alt="Default Avatar"
                                     style="width: 100%; height: 100%; object-fit: cover;">
                            </div>
                            <button type="button" onclick="selectIdentityAvatar()" style="margin-top: 8px; padding: 4px 8px; font-size: 11px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer; width: 100%;">Change Avatar</button>
                        </div>
                        <div style="flex: 1;">
                            <div class="form-group">
                                <label>@ Enter a display name</label>
                                <input type="text" id="identity-display-name" placeholder="Enter a display name" maxlength="40" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                <div style="text-align: right; font-size: 12px; color: #666; margin-top: 5px;">
                                    <span id="identity-char-count">0</span>/40
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeCreateIdentityModal()">Cancel</button>
                    <button class="btn-primary" onclick="createCustomIdentity()" id="create-identity-btn">Create</button>
                </div>
            </div>
        </div>

        <!-- Avatar Selection Modal -->
        <div id="avatar-selection-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h3>Select Avatar Image</h3>
                    <span class="modal-close" onclick="closeAvatarSelectionModal()">&times;</span>
                </div>
                <div class="modal-tabs">
                    <button class="tab-btn active" onclick="switchAvatarTab('library', event)">TikTok Library</button>
                    <button class="tab-btn" onclick="switchAvatarTab('upload', event)">Upload New</button>
                </div>
                <div class="modal-body">
                    <div id="avatar-library-tab" class="media-tab active">
                        <div class="media-grid" id="avatar-library-grid">
                            <!-- Avatar images will be loaded here -->
                        </div>
                    </div>
                    <div id="avatar-upload-tab" class="media-tab" style="display: none;">
                        <div class="upload-area" style="text-align: center; padding: 40px; border: 2px dashed #ddd; border-radius: 8px;">
                            <input type="file" id="avatar-file-input" accept="image/*" style="display: none;" onchange="handleAvatarUpload(event)">
                            <div onclick="document.getElementById('avatar-file-input').click()" style="cursor: pointer;">
                                <div style="font-size: 40px; margin-bottom: 10px;">📷</div>
                                <p>Click to upload avatar image</p>
                                <p style="font-size: 12px; color: #666;">JPG/PNG, 1:1 aspect ratio recommended</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeAvatarSelectionModal()">Cancel</button>
                    <button class="btn-primary" onclick="confirmAvatarSelection()" id="confirm-avatar-btn" disabled>Select Avatar</button>
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
