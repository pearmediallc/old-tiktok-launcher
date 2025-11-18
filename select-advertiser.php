<?php
session_start();

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Advertiser Account - TikTok Campaign Launcher</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .advertiser-selection {
            max-width: 800px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .advertiser-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .advertiser-header h2 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .advertiser-header p {
            color: #666;
            font-size: 14px;
        }
        
        .search-container {
            margin-bottom: 25px;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(26, 26, 26, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 16px;
        }
        
        .search-results-info {
            margin-bottom: 15px;
            color: #666;
            font-size: 13px;
            text-align: center;
        }
        
        .advertiser-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .advertiser-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
            background: #f9f9f9;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .advertiser-item:hover {
            border-color: #1a1a1a;
            background: #f8f9fa;
        }
        
        .advertiser-item.selected {
            border-color: #1a1a1a;
            background: #f0f0f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .advertiser-info {
            flex: 1;
        }
        
        .advertiser-name {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .advertiser-id {
            font-size: 12px;
            color: #666;
            font-family: monospace;
        }
        
        .advertiser-status {
            display: inline-block;
            padding: 4px 12px;
            background: #10b981;
            color: white;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .advertiser-status.inactive {
            background: #ef4444;
        }
        
        .advertiser-radio {
            width: 20px;
            height: 20px;
        }
        
        .continue-button {
            display: flex;
            justify-content: center;
            margin-top: 30px;
        }
        
        .no-advertisers {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .loading-advertisers {
            text-align: center;
            padding: 40px;
        }
        
        .loading-advertisers .spinner {
            margin: 0 auto 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1>🚀 TikTok Campaign Launcher</h1>
            <button class="btn-logout" onclick="logout()">Logout</button>
        </header>

        <!-- Advertiser Selection -->
        <div class="advertiser-selection">
            <div class="advertiser-header">
                <h2>Select Advertiser Account</h2>
                <p>Choose which advertiser account you want to use for creating campaigns</p>
            </div>

            <!-- OAuth Connect Button -->
            <div style="margin-bottom: 30px; text-align: center;">
                <a href="oauth-init.php" style="display: inline-block; padding: 15px 30px; background: #fe2c55; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.3s;">
                    🔗 Connect Your TikTok Ad Account via OAuth
                </a>
                <p style="margin-top: 10px; font-size: 13px; color: #666;">
                    Or select from existing accounts below
                </p>
            </div>

            <!-- Search Bar -->
            <div class="search-container" id="search-container" style="display: none;">
                <span class="search-icon">🔍</span>
                <input type="text" class="search-input" id="search-input" placeholder="Search advertiser accounts by name or ID..." onkeyup="filterAdvertisers()">
            </div>
            
            <div class="search-results-info" id="search-results-info" style="display: none;">
                Showing <span id="visible-count">0</span> of <span id="total-count">0</span> accounts
            </div>
            
            <div id="advertiser-container" class="loading-advertisers">
                <div class="spinner"></div>
                <p>Loading advertiser accounts...</p>
            </div>
            
            <div class="continue-button" id="continue-container" style="display: none;">
                <button class="btn-primary" onclick="proceedWithSelectedAdvertiser()" disabled id="continue-btn">
                    Continue to Campaign Creation →
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <script>
        let selectedAdvertiserId = null;
        let allAdvertisers = [];

        // Load advertiser accounts on page load
        window.addEventListener('DOMContentLoaded', () => {
            loadAdvertiserAccounts();
        });

        async function loadAdvertiserAccounts() {
            try {
                const response = await fetch('api.php?action=get_advertisers', {
                    method: 'GET',
                    headers: { 
                        'Content-Type': 'application/json'
                    }
                });

                const responseText = await response.text();
                console.log('Raw response:', responseText);
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Failed to parse response:', parseError);
                    showError('Invalid response from server. Please check the logs.');
                    return;
                }
                
                if (result.success) {
                    console.log('Advertisers loaded:', result.data);
                    allAdvertisers = result.data || [];
                    displayAdvertiserAccounts(allAdvertisers);
                } else {
                    showError('Failed to load advertiser accounts: ' + (result.message || result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error loading advertisers:', error);
                showError('Failed to load advertiser accounts. Please try again.');
            }
        }

        function displayAdvertiserAccounts(advertisers, isFiltered = false) {
            const container = document.getElementById('advertiser-container');
            
            if (!advertisers || advertisers.length === 0) {
                if (isFiltered) {
                    container.innerHTML = `
                        <div class="no-advertisers">
                            <p>No advertiser accounts match your search.</p>
                            <p>Try adjusting your search terms.</p>
                        </div>
                    `;
                } else {
                    container.innerHTML = `
                        <div class="no-advertisers">
                            <p>No advertiser accounts found.</p>
                            <p>Please ensure your TikTok access token has the necessary permissions.</p>
                        </div>
                    `;
                }
                return;
            }

            // Show search bar if more than 5 accounts
            if (allAdvertisers.length > 5) {
                document.getElementById('search-container').style.display = 'block';
                document.getElementById('search-results-info').style.display = 'block';
            }
            
            // Update results info
            document.getElementById('visible-count').textContent = advertisers.length;
            document.getElementById('total-count').textContent = allAdvertisers.length;

            let html = '<div class="advertiser-list">';
            advertisers.forEach((advertiser, index) => {
                // Status field may not be included in response, assume active if not present
                const isActive = !advertiser.status || advertiser.status === 'STATUS_ENABLE' || advertiser.status === 'ENABLE';
                html += `
                    <div class="advertiser-item" onclick="selectAdvertiser('${advertiser.advertiser_id}', this)">
                        <div class="advertiser-info">
                            <div class="advertiser-name">
                                ${advertiser.advertiser_name || `Advertiser ${index + 1}`}
                                <span class="advertiser-status">
                                    Active
                                </span>
                            </div>
                            <div class="advertiser-id">ID: ${advertiser.advertiser_id}</div>
                        </div>
                        <input type="radio" name="advertiser" class="advertiser-radio" value="${advertiser.advertiser_id}">
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
            document.getElementById('continue-container').style.display = 'flex';
        }

        function selectAdvertiser(advertiserId, element) {
            // Remove previous selection
            document.querySelectorAll('.advertiser-item').forEach(item => {
                item.classList.remove('selected');
                item.querySelector('.advertiser-radio').checked = false;
            });

            // Add selection to clicked item
            element.classList.add('selected');
            element.querySelector('.advertiser-radio').checked = true;
            
            selectedAdvertiserId = advertiserId;
            document.getElementById('continue-btn').disabled = false;
        }

        function filterAdvertisers() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase().trim();
            
            if (!searchTerm) {
                displayAdvertiserAccounts(allAdvertisers, false);
                return;
            }
            
            const filteredAdvertisers = allAdvertisers.filter(advertiser => {
                const name = (advertiser.advertiser_name || '').toLowerCase();
                const id = (advertiser.advertiser_id || '').toLowerCase();
                return name.includes(searchTerm) || id.includes(searchTerm);
            });
            
            displayAdvertiserAccounts(filteredAdvertisers, true);
        }

        async function proceedWithSelectedAdvertiser() {
            if (!selectedAdvertiserId) {
                showError('Please select an advertiser account');
                return;
            }

            document.getElementById('continue-btn').disabled = true;
            document.getElementById('continue-btn').textContent = 'Setting up...';

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'set_advertiser',
                        advertiser_id: selectedAdvertiserId
                    })
                });

                const responseText = await response.text();
                console.log('Set advertiser raw response:', responseText);
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Failed to parse set_advertiser response:', parseError);
                    showError('Invalid response from server when setting advertiser');
                    document.getElementById('continue-btn').disabled = false;
                    document.getElementById('continue-btn').textContent = 'Continue to Campaign Creation →';
                    return;
                }
                
                if (result.success) {
                    showSuccess('Advertiser account selected successfully');
                    setTimeout(() => {
                        window.location.href = result.redirect || 'campaign-select.php';
                    }, 1000);
                } else {
                    showError('Failed to set advertiser: ' + (result.message || result.error || 'Unknown error'));
                    document.getElementById('continue-btn').disabled = false;
                    document.getElementById('continue-btn').textContent = 'Continue to Campaign Creation →';
                }
            } catch (error) {
                console.error('Error setting advertiser:', error);
                showError('Failed to set advertiser account. Please try again.');
                document.getElementById('continue-btn').disabled = false;
                document.getElementById('continue-btn').textContent = 'Continue to Campaign Creation →';
            }
        }

        function logout() {
            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'logout' })
            }).then(() => {
                window.location.href = 'index.php';
            });
        }

        function showToast(message, type = '') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast show ' + type;
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        function showSuccess(message) {
            showToast(message, 'success');
        }

        function showError(message) {
            showToast(message, 'error');
        }
    </script>
</body>
</html>