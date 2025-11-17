<?php
session_start();

// Check if OAuth was successful
if (!isset($_SESSION['oauth_access_token']) || !isset($_SESSION['oauth_advertiser_ids'])) {
    header('Location: index.php');
    exit;
}

$advertiser_ids = $_SESSION['oauth_advertiser_ids'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Advertiser Account</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .advertiser-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 30px;
        }
        .advertiser-list {
            display: grid;
            gap: 15px;
            margin-top: 30px;
        }
        .advertiser-card {
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }
        .advertiser-card:hover {
            border-color: #667eea;
            background: #f8f9ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        .advertiser-card.selected {
            border-color: #4caf50;
            background: #f1f8f4;
            border-width: 3px;
        }
        .advertiser-id {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        .advertiser-status {
            font-size: 14px;
            color: #666;
        }
        .success-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #4caf50;
            color: white;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 10px;
        }
        .btn-continue {
            margin-top: 30px;
            padding: 15px 40px;
            font-size: 16px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-continue:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .btn-continue:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        .header-info {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #4caf50;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="advertiser-container">
            <h1>🎯 Select Your Ad Account</h1>

            <div class="header-info">
                <p style="margin: 0; color: #2e7d32;">
                    <strong>✓ Successfully connected!</strong><br>
                    Found <?php echo count($advertiser_ids); ?> advertiser account<?php echo count($advertiser_ids) > 1 ? 's' : ''; ?> linked to your TikTok Ads Manager.
                </p>
            </div>

            <?php if (empty($advertiser_ids)): ?>
                <div style="padding: 30px; text-align: center; background: #fff3cd; border-radius: 8px; margin-top: 20px;">
                    <p style="color: #856404; margin: 0;">
                        No advertiser accounts found. Please make sure you have access to at least one TikTok Ads account.
                    </p>
                    <a href="index.php" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 6px;">
                        Back to Login
                    </a>
                </div>
            <?php else: ?>
                <div class="advertiser-list" id="advertiser-list">
                    <?php foreach ($advertiser_ids as $index => $advertiser_id): ?>
                        <div class="advertiser-card" onclick="selectAdvertiser('<?php echo htmlspecialchars($advertiser_id); ?>', this)">
                            <div class="advertiser-id">
                                📊 Advertiser ID: <?php echo htmlspecialchars($advertiser_id); ?>
                                <span class="success-badge">Connected</span>
                            </div>
                            <div class="advertiser-status">
                                Click to select this account
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button id="continue-btn" class="btn-continue" onclick="continueToDashboard()" disabled>
                    Continue to Dashboard →
                </button>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let selectedAdvertiserId = null;

        function selectAdvertiser(advertiserId, element) {
            // Remove selection from all cards
            document.querySelectorAll('.advertiser-card').forEach(card => {
                card.classList.remove('selected');
            });

            // Add selection to clicked card
            element.classList.add('selected');
            selectedAdvertiserId = advertiserId;

            // Enable continue button
            document.getElementById('continue-btn').disabled = false;

            console.log('Selected Advertiser ID:', advertiserId);
        }

        function continueToDashboard() {
            if (!selectedAdvertiserId) {
                alert('Please select an advertiser account');
                return;
            }

            // Send selected advertiser to backend
            fetch('api.php?action=set_oauth_advertiser', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    advertiser_id: selectedAdvertiserId
                })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    window.location.href = 'dashboard.php';
                } else {
                    alert('Failed to set advertiser: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }

        // Auto-select if only one advertiser
        <?php if (count($advertiser_ids) === 1): ?>
            const firstCard = document.querySelector('.advertiser-card');
            if (firstCard) {
                selectAdvertiser('<?php echo htmlspecialchars($advertiser_ids[0]); ?>', firstCard);
            }
        <?php endif; ?>
    </script>
</body>
</html>
