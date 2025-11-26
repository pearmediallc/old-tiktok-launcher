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
    <style>
        .smart-container {
            max-width: 900px;
            margin: 30px auto;
            padding: 20px;
        }

        .smart-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .smart-header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .smart-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .smart-form-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .smart-form-section h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
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

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group small {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* Media Selection */
        .media-selection-area {
            border: 2px dashed #ddd;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .media-selection-area:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }

        .media-selection-area .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .selected-media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .selected-media-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 9/16;
            background: #f0f0f0;
        }

        .selected-media-item video,
        .selected-media-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .selected-media-item .remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(255,0,0,0.8);
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 14px;
        }

        /* Ad Text Section */
        .ad-text-item {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .ad-text-item input {
            flex: 1;
        }

        .ad-text-item .remove-text-btn {
            background: #ff4444;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0 15px;
            cursor: pointer;
        }

        .add-text-btn {
            background: #f0f0f0;
            border: 1px dashed #999;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            color: #666;
            margin-top: 10px;
        }

        .add-text-btn:hover {
            background: #e0e0e0;
        }

        /* CTA Selection */
        .cta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }

        .cta-item {
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .cta-item:hover {
            border-color: #667eea;
        }

        .cta-item.selected {
            border-color: #667eea;
            background: #f0f3ff;
        }

        /* Publish Button */
        .publish-section {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            color: white;
        }

        .publish-section h2 {
            margin-bottom: 15px;
            font-size: 22px;
        }

        .publish-section p {
            margin-bottom: 20px;
            opacity: 0.9;
        }

        .btn-publish {
            background: white;
            color: #667eea;
            border: none;
            padding: 16px 50px;
            border-radius: 30px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-publish:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }

        .btn-publish:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TikTok Campaign Launcher</h1>
        <div class="user-info">
            <span id="advertiser-name">Loading...</span>
            <a href="campaign-select.php" class="btn-secondary">Back</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="smart-container">
        <div class="smart-header">
            <span class="smart-badge">Smart+ Campaign</span>
            <h1>Create Smart+ Lead Generation Campaign</h1>
            <p>AI-powered campaign optimization for maximum results</p>
        </div>

        <!-- Campaign Info -->
        <div class="smart-form-section">
            <h2>1. Campaign Information</h2>
            <div class="form-group">
                <label>Campaign Name *</label>
                <input type="text" id="campaign-name" placeholder="Enter campaign name" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Daily Budget (USD) *</label>
                    <input type="number" id="budget" value="50" min="20" placeholder="50" required>
                    <small>Minimum $20 daily budget</small>
                </div>
                <div class="form-group">
                    <label>Age Targeting</label>
                    <select id="age-targeting">
                        <option value="18+">18+ (Recommended)</option>
                        <option value="25+">25+</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Pixel Selection -->
        <div class="smart-form-section">
            <h2>2. Conversion Tracking (Pixel)</h2>
            <div class="form-group">
                <label>Select Pixel</label>
                <select id="pixel-select">
                    <option value="">Loading pixels...</option>
                </select>
                <small>Select a pixel for conversion tracking</small>
            </div>
            <div class="form-group" id="pixel-event-group" style="display: none;">
                <label>Optimization Event</label>
                <select id="pixel-event">
                    <option value="FORM">Form Submission</option>
                    <option value="COMPLETE_PAYMENT">Complete Payment</option>
                    <option value="REGISTRATION">Registration</option>
                </select>
            </div>
        </div>

        <!-- Identity Selection -->
        <div class="smart-form-section">
            <h2>3. Identity</h2>
            <div class="form-group">
                <label>Select Identity *</label>
                <select id="identity-select" required>
                    <option value="">Loading identities...</option>
                </select>
                <small>The identity represents your brand on TikTok ads</small>
            </div>
            <button type="button" class="add-text-btn" onclick="openCreateIdentityModal()">+ Create New Identity</button>
        </div>

        <!-- Media Selection -->
        <div class="smart-form-section">
            <h2>4. Video Creative *</h2>
            <div class="media-selection-area" onclick="openMediaModal()">
                <div class="icon">🎬</div>
                <p><strong>Click to Select Videos</strong></p>
                <p>Select up to 30 videos for Smart+ optimization</p>
            </div>
            <div class="selected-media-grid" id="selected-media-grid">
                <!-- Selected media will appear here -->
            </div>
        </div>

        <!-- Ad Text -->
        <div class="smart-form-section">
            <h2>5. Ad Text *</h2>
            <div id="ad-texts-container">
                <div class="ad-text-item">
                    <input type="text" class="ad-text-input" placeholder="Enter ad text (12-100 characters)" maxlength="100" minlength="12">
                </div>
            </div>
            <button type="button" class="add-text-btn" onclick="addAdText()">+ Add Another Text Variation</button>
            <small style="display: block; margin-top: 10px;">Add multiple text variations for AI optimization. Min 12, Max 100 characters each.</small>
        </div>

        <!-- Landing Page -->
        <div class="smart-form-section">
            <h2>6. Landing Page URL *</h2>
            <div class="form-group">
                <label>Destination URL</label>
                <input type="url" id="landing-page-url" placeholder="https://example.com/landing-page" required>
                <small>Where users will go after clicking your ad</small>
            </div>
        </div>

        <!-- CTA Selection -->
        <div class="smart-form-section">
            <h2>7. Call to Action</h2>
            <p style="margin-bottom: 15px; color: #666;">Select CTA buttons for your ads (Smart+ will optimize automatically)</p>
            <div class="cta-grid" id="cta-grid">
                <div class="cta-item selected" data-cta="LEARN_MORE" onclick="selectCTA(this)">Learn More</div>
                <div class="cta-item" data-cta="SIGN_UP" onclick="selectCTA(this)">Sign Up</div>
                <div class="cta-item" data-cta="CONTACT_US" onclick="selectCTA(this)">Contact Us</div>
                <div class="cta-item" data-cta="APPLY_NOW" onclick="selectCTA(this)">Apply Now</div>
                <div class="cta-item" data-cta="GET_QUOTE" onclick="selectCTA(this)">Get Quote</div>
                <div class="cta-item" data-cta="DOWNLOAD" onclick="selectCTA(this)">Download</div>
            </div>
            <input type="hidden" id="selected-ctas" value="LEARN_MORE">
        </div>

        <!-- Publish Section -->
        <div class="publish-section">
            <h2>Ready to Launch Your Smart+ Campaign</h2>
            <p>Your campaign will be created with AI-powered optimization</p>
            <button class="btn-publish" id="publish-btn" onclick="publishSmartCampaign()">
                Publish Smart+ Campaign
            </button>
        </div>
    </div>

    <!-- Media Library Modal -->
    <div id="media-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Select Videos <span id="selection-counter" style="font-size: 14px; color: #667eea; margin-left: 10px;">0 selected</span></h3>
                <span class="modal-close" onclick="closeMediaModal()">&times;</span>
            </div>
            <div class="modal-tabs">
                <button class="tab-btn active" onclick="switchMediaTab('videos', event)">Videos</button>
                <button class="btn-secondary btn-sm" onclick="syncTikTokLibrary()" style="margin-left: auto;">Sync from TikTok</button>
            </div>
            <div class="modal-body">
                <div id="media-library-tab" class="media-tab active">
                    <div class="media-grid" id="media-grid">
                        <!-- Media items will be loaded here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeMediaModal()">Cancel</button>
                <button class="btn-primary" onclick="confirmMediaSelection()">Confirm Selection</button>
            </div>
        </div>
    </div>

    <!-- Create Identity Modal -->
    <div id="create-identity-modal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Create New Identity</h3>
                <span class="modal-close" onclick="closeCreateIdentityModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Display Name *</label>
                    <input type="text" id="new-identity-name" placeholder="Enter display name" maxlength="40">
                    <small>Max 40 characters</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeCreateIdentityModal()">Cancel</button>
                <button class="btn-primary" onclick="createNewIdentity()">Create Identity</button>
            </div>
        </div>
    </div>

    <!-- Loading overlay -->
    <div id="loading" class="loading-overlay">
        <div class="spinner"></div>
    </div>

    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

    <script src="assets/smart-campaign.js?v=<?php echo time(); ?>"></script>
</body>
</html>
