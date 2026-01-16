<?php
session_start();

// Check if OAuth was successful
if (!isset($_SESSION['oauth_access_token']) || !isset($_SESSION['oauth_advertiser_ids'])) {
    header('Location: index.php');
    exit;
}

$advertiser_ids = $_SESSION['oauth_advertiser_ids'];
$advertiser_details = $_SESSION['oauth_advertiser_details'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok Campaign Launcher</title>
    <link rel="stylesheet" href="assets/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, rgb(30, 157, 241) 0%, rgb(26, 138, 216) 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
            padding-top: 20px;
        }

        .header h1 {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .header p {
            font-size: 18px;
            opacity: 0.95;
            font-weight: 400;
        }

        .wizard-container {
            background: white;
            border-radius: 1.3rem;
            box-shadow: 0 20px 60px rgba(30, 157, 241, 0.3);
            overflow: hidden;
        }

        .wizard-header {
            background: linear-gradient(135deg, rgb(30, 157, 241) 0%, rgb(26, 138, 216) 100%);
            padding: 30px;
            color: white;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            transition: all 0.3s;
        }

        .step.active .step-number {
            background: white;
            color: rgb(30, 157, 241);
            box-shadow: 0 4px 15px rgba(255,255,255,0.3);
        }

        .step.completed .step-number {
            background: rgb(0, 184, 122);
        }

        .step-label {
            font-weight: 600;
            font-size: 14px;
            opacity: 0.8;
        }

        .step.active .step-label {
            opacity: 1;
        }

        .step-divider {
            width: 50px;
            height: 2px;
            background: rgba(255,255,255,0.8);
        }

        .wizard-body {
            padding: 40px;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
            animation: fadeIn 0.4s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-title {
            font-size: 28px;
            font-weight: 700;
            color: rgb(15, 20, 25);
            margin-bottom: 10px;
        }

        .section-subtitle {
            font-size: 16px;
            color: rgb(15, 20, 25);
            opacity: 0.7;
            margin-bottom: 30px;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .selection-card {
            border: 3px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 4px);
            padding: 25px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
            position: relative;
            overflow: hidden;
        }

        .selection-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, rgb(30, 157, 241), rgb(26, 138, 216));
            transform: scaleX(0);
            transition: transform 0.3s;
        }

        .selection-card:hover {
            border-color: rgb(30, 157, 241);
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(30, 157, 241, 0.2);
        }

        .selection-card:hover::before {
            transform: scaleX(1);
        }

        .selection-card.selected {
            border-color: rgb(0, 184, 122);
            background: linear-gradient(135deg, rgb(227, 236, 246) 0%, rgb(240, 248, 244) 100%);
            box-shadow: 0 8px 24px rgba(0, 184, 122, 0.3);
        }

        .selection-card.selected::before {
            background: rgb(0, 184, 122);
            transform: scaleX(1);
        }

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 15px;
            background: linear-gradient(135deg, rgba(30, 157, 241, 0.1), rgba(26, 138, 216, 0.1));
        }

        .selection-card.selected .card-icon {
            background: linear-gradient(135deg, rgba(0, 184, 122, 0.1), rgba(0, 160, 106, 0.1));
        }

        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: rgb(15, 20, 25);
            margin-bottom: 8px;
        }

        .card-subtitle {
            font-size: 14px;
            color: rgb(15, 20, 25);
            opacity: 0.7;
            margin-bottom: 12px;
            font-weight: 500;
        }

        .card-description {
            font-size: 13px;
            color: rgb(15, 20, 25);
            opacity: 0.6;
            line-height: 1.6;
        }

        .check-icon {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgb(0, 184, 122);
            color: white;
            display: none;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .selection-card.selected .check-icon {
            display: flex;
            animation: checkPop 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        @keyframes checkPop {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        .card-next-button {
            display: none;
            margin-top: 20px;
            padding: 12px 24px;
            background: linear-gradient(135deg, rgb(30, 157, 241), rgb(26, 138, 216));
            color: white;
            border: none;
            border-radius: calc(1.3rem - 4px);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            box-shadow: 0 4px 12px rgba(30, 157, 241, 0.3);
        }

        .card-next-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(30, 157, 241, 0.5);
        }

        .selection-card.selected .card-next-button {
            display: block;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .campaign-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .campaign-card {
            border: 3px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 4px);
            padding: 30px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
            position: relative;
        }

        .campaign-card:hover {
            border-color: rgb(30, 157, 241);
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(30, 157, 241, 0.25);
        }

        .campaign-card.selected {
            border-color: rgb(0, 184, 122);
            background: linear-gradient(135deg, #ffffff 0%, rgb(240, 248, 244) 100%);
            box-shadow: 0 10px 30px rgba(0, 184, 122, 0.3);
        }

        .campaign-icon {
            width: 70px;
            height: 70px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, rgb(30, 157, 241), rgb(26, 138, 216));
        }

        .campaign-card.selected .campaign-icon {
            background: linear-gradient(135deg, rgb(0, 184, 122), rgb(0, 160, 106));
        }

        .campaign-title {
            font-size: 24px;
            font-weight: 700;
            color: rgb(15, 20, 25);
            margin-bottom: 10px;
        }

        .campaign-description {
            font-size: 14px;
            color: rgb(15, 20, 25);
            opacity: 0.7;
            line-height: 1.7;
            margin-bottom: 15px;
        }

        .campaign-features {
            list-style: none;
            padding: 0;
        }

        .campaign-features li {
            padding: 8px 0;
            font-size: 13px;
            color: rgb(15, 20, 25);
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .campaign-features li::before {
            content: '✓';
            color: rgb(0, 184, 122);
            font-weight: 700;
            font-size: 16px;
        }

        .button-group {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 2px solid rgb(225, 234, 239);
            margin-top: 30px;
        }

        .btn {
            padding: 14px 32px;
            border: none;
            border-radius: calc(1.3rem - 4px);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary {
            background: rgb(247, 249, 250);
            color: rgb(15, 20, 25);
        }

        .btn-secondary:hover {
            background: rgb(227, 236, 246);
            transform: translateY(-2px);
        }

        .btn-primary {
            background: linear-gradient(135deg, rgb(30, 157, 241), rgb(26, 138, 216));
            color: white;
            box-shadow: 0 4px 15px rgba(30, 157, 241, 0.4);
        }

        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(30, 157, 241, 0.6);
            transform: translateY(-2px);
        }

        .btn-primary:disabled {
            background: rgb(229, 229, 230);
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .success-banner {
            background: linear-gradient(135deg, rgb(227, 236, 246), rgb(240, 248, 244));
            border-left: 5px solid rgb(0, 184, 122);
            padding: 20px;
            border-radius: calc(1.3rem - 4px);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .success-banner-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgb(0, 184, 122);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .success-banner-text {
            flex: 1;
        }

        .success-banner-title {
            font-weight: 700;
            color: rgb(0, 140, 94);
            font-size: 16px;
            margin-bottom: 4px;
        }

        .success-banner-subtitle {
            color: rgb(0, 160, 106);
            font-size: 14px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: rgb(227, 236, 246);
            border-radius: calc(1.3rem - 4px);
            margin-top: 30px;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .empty-state-title {
            font-size: 24px;
            font-weight: 700;
            color: rgb(15, 20, 25);
            margin-bottom: 10px;
        }

        .empty-state-text {
            color: rgb(15, 20, 25);
            opacity: 0.7;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 32px;
            }

            .wizard-body {
                padding: 25px;
            }

            .card-grid {
                grid-template-columns: 1fr;
            }

            .campaign-type-grid {
                grid-template-columns: 1fr;
            }

            .step-label {
                display: none;
            }

            .button-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1>🚀 TikTok Campaign Launcher</h1>
            <p>Create powerful ad campaigns in minutes</p>
        </div>

        <div class="wizard-container">
            <div class="wizard-header">
                <div class="step-indicator">
                    <div class="step active" id="step-indicator-1">
                        <div class="step-number">1</div>
                        <div class="step-label">Ad Account</div>
                    </div>
                    <div class="step-divider"></div>
                    <div class="step" id="step-indicator-2">
                        <div class="step-number">2</div>
                        <div class="step-label">Campaign Type</div>
                    </div>
                    <div class="step-divider"></div>
                    <div class="step" id="step-indicator-3">
                        <div class="step-number">3</div>
                        <div class="step-label">Launch</div>
                    </div>
                </div>
            </div>

            <div class="wizard-body">
                <!-- Step 1: Select Ad Account -->
                <div class="section active" id="section-1">
                    <div class="success-banner">
                        <div class="success-banner-icon">✓</div>
                        <div class="success-banner-text">
                            <div class="success-banner-title">Successfully Connected!</div>
                            <div class="success-banner-subtitle">
                                Found <?php echo count($advertiser_ids); ?> advertiser account<?php echo count($advertiser_ids) > 1 ? 's' : ''; ?> linked to your TikTok Ads Manager
                            </div>
                        </div>
                    </div>

                    <div class="section-title">Select Your Ad Account</div>
                    <div class="section-subtitle">Choose the TikTok Ads account you want to use for your campaigns</div>

                    <?php if (empty($advertiser_ids)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">⚠️</div>
                            <div class="empty-state-title">No Accounts Found</div>
                            <div class="empty-state-text">
                                No advertiser accounts found. Please make sure you have access to at least one TikTok Ads account.
                            </div>
                            <a href="index.php" class="btn btn-primary">Back to Login</a>
                        </div>
                    <?php else: ?>
                        <div class="card-grid" id="advertiser-list">
                            <?php foreach ($advertiser_ids as $index => $advertiser_id):
                                $details = $advertiser_details[$advertiser_id] ?? null;
                                $name = $details ? $details['name'] : 'Advertiser Account ' . ($index + 1);
                            ?>
                                <div class="selection-card" data-advertiser-id="<?php echo htmlspecialchars($advertiser_id); ?>">
                                    <div onclick="selectAdvertiser('<?php echo htmlspecialchars($advertiser_id); ?>', this.parentElement)">
                                        <div class="check-icon">✓</div>
                                        <div class="card-icon">📊</div>
                                        <div class="card-title"><?php echo htmlspecialchars($name); ?></div>
                                        <div class="card-subtitle">ID: <?php echo htmlspecialchars($advertiser_id); ?></div>
                                        <div class="card-description">Click to select this account for campaign creation</div>
                                    </div>
                                    <button class="card-next-button" onclick="goToStep(2); event.stopPropagation();">
                                        Next: Choose Campaign Type →
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Step 2: Select Campaign Type -->
                <div class="section" id="section-2">
                    <div class="section-title">What would you like to do?</div>
                    <div class="section-subtitle">Create a new campaign or view your existing campaigns</div>

                    <div class="campaign-type-grid">
                        <div class="campaign-card" onclick="selectCampaignType('view', this)">
                            <div class="check-icon">✓</div>
                            <div class="campaign-icon">📊</div>
                            <div class="campaign-title">View Campaigns</div>
                            <div class="campaign-description">
                                View and manage your existing campaigns with detailed metrics and performance data.
                            </div>
                            <ul class="campaign-features">
                                <li>See all your campaigns</li>
                                <li>View metrics & performance</li>
                                <li>Turn campaigns ON/OFF</li>
                                <li>Duplicate existing campaigns</li>
                                <li>Expand to see ad groups & ads</li>
                            </ul>
                        </div>

                        <div class="campaign-card" onclick="selectCampaignType('smart', this)">
                            <div class="check-icon">✓</div>
                            <div class="campaign-icon">⚡</div>
                            <div class="campaign-title">Create Smart+ Campaign</div>
                            <div class="campaign-description">
                                AI-powered automation that optimizes your campaigns for maximum performance.
                            </div>
                            <ul class="campaign-features">
                                <li>Automated audience discovery</li>
                                <li>Smart bid optimization</li>
                                <li>AI-driven budget allocation</li>
                                <li>Real-time performance optimization</li>
                                <li>Simplified campaign setup</li>
                            </ul>
                        </div>

                        <div class="campaign-card" onclick="selectCampaignType('manual', this)">
                            <div class="check-icon">✓</div>
                            <div class="campaign-icon">🎯</div>
                            <div class="campaign-title">Create Manual Campaign</div>
                            <div class="campaign-description">
                                Full control over your campaign settings, targeting, and optimization strategies.
                            </div>
                            <ul class="campaign-features">
                                <li>Custom audience targeting</li>
                                <li>Manual bid management</li>
                                <li>Advanced optimization controls</li>
                                <li>Detailed performance tracking</li>
                                <li>Flexible budget allocation</li>
                            </ul>
                        </div>
                    </div>

                    <div class="button-group">
                        <button class="btn btn-secondary" onclick="goToStep(1)">← Back</button>
                        <button class="btn btn-primary" id="btn-next-2" onclick="launchDashboard()" disabled>
                            Continue →
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let selectedAdvertiserId = null;
        let selectedCampaignType = null;

        // Store OAuth tokens in browser localStorage on page load
        window.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_SESSION['oauth_access_token'])): ?>
                const tokenData = {
                    access_token: <?php echo json_encode($_SESSION['oauth_access_token']); ?>,
                    refresh_token: <?php echo json_encode($_SESSION['oauth_refresh_token'] ?? ''); ?>,
                    expires_in: <?php echo json_encode($_SESSION['oauth_expires_in'] ?? 86400); ?>,
                    token_type: <?php echo json_encode($_SESSION['oauth_token_type'] ?? 'Bearer'); ?>,
                    advertiser_ids: <?php echo json_encode($_SESSION['oauth_advertiser_ids'] ?? []); ?>,
                    expires_at: Date.now() + (<?php echo $_SESSION['oauth_expires_in'] ?? 86400; ?> * 1000)
                };

                // Store in localStorage for persistence across sessions
                localStorage.setItem('tiktok_oauth_token', JSON.stringify(tokenData));
                console.log('OAuth token stored in browser localStorage');
            <?php endif; ?>
        });

        function selectAdvertiser(advertiserId, element) {
            // Remove selection from all cards
            document.querySelectorAll('.selection-card').forEach(card => {
                card.classList.remove('selected');
            });

            // Add selection to clicked card
            element.classList.add('selected');
            selectedAdvertiserId = advertiserId;

            console.log('Selected Advertiser ID:', advertiserId);
        }

        function selectCampaignType(type, element) {
            // Remove selection from all campaign cards
            document.querySelectorAll('.campaign-card').forEach(card => {
                card.classList.remove('selected');
            });

            // Add selection to clicked card
            element.classList.add('selected');
            selectedCampaignType = type;

            // Enable launch button
            document.getElementById('btn-next-2').disabled = false;

            console.log('Selected Campaign Type:', type);
        }

        function goToStep(stepNumber) {
            // Hide all sections
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });

            // Show target section
            document.getElementById('section-' + stepNumber).classList.add('active');

            // Update step indicators
            document.querySelectorAll('.step').forEach((step, index) => {
                step.classList.remove('active', 'completed');
                if (index + 1 < stepNumber) {
                    step.classList.add('completed');
                } else if (index + 1 === stepNumber) {
                    step.classList.add('active');
                }
            });

            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function launchDashboard() {
            if (!selectedAdvertiserId || !selectedCampaignType) {
                alert('Please select both an advertiser and campaign type');
                return;
            }

            // Update localStorage with selected advertiser and campaign type
            const tokenData = JSON.parse(localStorage.getItem('tiktok_oauth_token') || '{}');
            tokenData.selected_advertiser_id = selectedAdvertiserId;
            tokenData.campaign_type = selectedCampaignType;
            localStorage.setItem('tiktok_oauth_token', JSON.stringify(tokenData));

            // Send selected advertiser to backend (for session)
            fetch('api.php?action=set_oauth_advertiser', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    advertiser_id: selectedAdvertiserId,
                    campaign_type: selectedCampaignType
                })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Redirect based on campaign type
                    if (selectedCampaignType === 'view') {
                        window.location.href = 'smart-campaign.php?view=campaigns';
                    } else if (selectedCampaignType === 'smart') {
                        window.location.href = 'smart-campaign.php';
                    } else {
                        window.location.href = 'dashboard.php';
                    }
                } else {
                    alert('Failed to initialize: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }

        // Auto-select if only one advertiser
        <?php if (count($advertiser_ids) === 1): ?>
            const firstCard = document.querySelector('.selection-card');
            if (firstCard) {
                selectAdvertiser('<?php echo htmlspecialchars($advertiser_ids[0]); ?>', firstCard);
            }
        <?php endif; ?>
    </script>
</body>
</html>
