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
    <title>Select Campaign Type - TikTok Campaign Launcher</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .campaign-select-container {
            max-width: 900px;
            margin: 50px auto;
            padding: 30px;
        }
        
        .campaign-type-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }
        
        .campaign-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 30px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .campaign-card:hover {
            border-color: #667eea;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }
        
        .campaign-card.selected {
            border-color: #667eea;
            background: #f8f9ff;
        }
        
        .campaign-card h3 {
            margin-top: 0;
            color: #333;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .campaign-card .badge {
            background: #667eea;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .campaign-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .campaign-icon.manual {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        }

        .campaign-icon.smart {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
        }

        .smart-stars {
            position: absolute;
            top: 15px;
            left: 15px;
            font-size: 20px;
            animation: sparkle 2s ease-in-out infinite;
        }

        @keyframes sparkle {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.1); }
        }
        
        .campaign-card .description {
            color: #666;
            margin: 15px 0;
            line-height: 1.6;
        }
        
        .campaign-card .features {
            margin-top: 20px;
        }
        
        .campaign-card .features li {
            color: #555;
            margin: 8px 0;
            padding-left: 20px;
            position: relative;
        }
        
        .campaign-card .features li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #4CAF50;
            font-weight: bold;
        }
        
        .radio-indicator {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 24px;
            height: 24px;
            border: 2px solid #ddd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .campaign-card.selected .radio-indicator {
            border-color: #667eea;
        }
        
        .campaign-card.selected .radio-indicator:after {
            content: "";
            width: 12px;
            height: 12px;
            background: #667eea;
            border-radius: 50%;
        }
        
        .continue-section {
            margin-top: 40px;
            text-align: center;
        }
        
        .btn-continue {
            background: #667eea;
            color: white;
            padding: 14px 40px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-continue:hover:not(:disabled) {
            background: #5567d8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .btn-continue:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .header-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header-section h1 {
            color: #333;
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .header-section p {
            color: #666;
            font-size: 16px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .campaign-select-container {
                margin: 20px auto;
                padding: 15px;
            }

            .campaign-type-cards {
                grid-template-columns: 1fr;
                gap: 20px;
                margin-top: 25px;
            }

            .campaign-card {
                padding: 20px;
            }

            .campaign-card h3 {
                font-size: 20px;
            }

            .campaign-icon {
                width: 40px;
                height: 40px;
            }

            .header-section h1 {
                font-size: 24px;
            }

            .header-section p {
                font-size: 14px;
            }

            .btn-continue {
                width: 100%;
                padding: 14px 20px;
            }
        }

        /* Button click fixes */
        .campaign-card,
        .btn-continue,
        .logout-btn {
            cursor: pointer !important;
            user-select: none !important;
            -webkit-user-select: none !important;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        .campaign-card:active,
        .btn-continue:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TikTok Campaign Launcher</h1>
        <div class="user-info">
            <span id="advertiser-name">Select Campaign Type</span>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="campaign-select-container">
        <div class="header-section">
            <h1>Choose Your Campaign Type</h1>
            <p>Select the type of campaign that best fits your advertising goals</p>
        </div>

        <div class="campaign-type-cards">
            <div class="campaign-card" onclick="selectCampaignType('manual')" id="manual-card">
                <div class="radio-indicator"></div>
                <div class="campaign-icon manual">🎯</div>
                <h3>Manual Campaign</h3>
                <p class="description">
                    Full control over your campaign settings, targeting, and optimization strategies.
                </p>
                <ul class="features">
                    <li>Custom audience targeting</li>
                    <li>Manual bid management</li>
                    <li>Advanced optimization controls</li>
                    <li>Detailed performance tracking</li>
                    <li>Flexible budget allocation</li>
                </ul>
            </div>

            <div class="campaign-card" onclick="selectCampaignType('smart')" id="smart-card">
                <div class="radio-indicator"></div>
                <div class="smart-stars">✨</div>
                <div class="campaign-icon smart">⚡</div>
                <h3>Smart+ Campaign <span class="badge">NEW</span></h3>
                <p class="description">
                    AI-powered automation that optimizes your campaigns for maximum performance.
                </p>
                <ul class="features">
                    <li>Automated audience discovery</li>
                    <li>Smart bid optimization</li>
                    <li>AI-driven budget allocation</li>
                    <li>Real-time performance optimization</li>
                    <li>Simplified campaign setup</li>
                </ul>
            </div>
        </div>

        <div class="continue-section">
            <button class="btn-continue" id="continue-btn" onclick="continueToCampaign()" disabled>
                Continue →
            </button>
        </div>
    </div>

    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

    <script>
        let selectedType = null;

        function selectCampaignType(type) {
            selectedType = type;
            
            // Update visual selection
            document.querySelectorAll('.campaign-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            document.getElementById(type + '-card').classList.add('selected');
            
            // Enable continue button
            document.getElementById('continue-btn').disabled = false;
        }

        function continueToCampaign() {
            if (!selectedType) {
                showToast('Please select a campaign type', 'error');
                return;
            }

            // Redirect based on selection
            if (selectedType === 'manual') {
                window.location.href = 'dashboard.php';
            } else if (selectedType === 'smart') {
                window.location.href = 'smart-campaign.php';
            }
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast show ' + type;
            
            setTimeout(() => {
                toast.className = 'toast';
            }, 3000);
        }

        // Load advertiser info if available
        window.addEventListener('DOMContentLoaded', async () => {
            const advertiserName = localStorage.getItem('advertiser_name');
            if (advertiserName) {
                document.getElementById('advertiser-name').textContent = advertiserName;
            }
        });
    </script>
</body>
</html>