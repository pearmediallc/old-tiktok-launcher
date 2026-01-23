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

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .header {
                flex-direction: column;
                gap: 12px;
                padding: 15px;
                text-align: center;
            }

            .header h1 {
                font-size: 20px;
            }

            .btn-logout {
                width: 100%;
            }

            .advertiser-selection {
                padding: 15px;
            }

            .advertiser-header h2 {
                font-size: 20px;
            }

            .advertiser-header p {
                font-size: 14px;
            }

            .advertiser-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                padding: 15px;
            }

            .advertiser-info {
                width: 100%;
            }

            .advertiser-name {
                font-size: 15px;
            }

            .advertiser-status {
                margin-left: 0;
                margin-top: 5px;
            }

            .advertiser-radio {
                align-self: flex-end;
            }

            .continue-button button {
                width: 100%;
                padding: 14px 20px;
            }

            /* OAuth connect button mobile */
            [href="oauth-init.php"] {
                display: block !important;
                padding: 16px 20px !important;
                font-size: 16px !important;
            }
        }

        /* Button click fixes */
        button,
        a[href="oauth-init.php"],
        .btn-logout {
            cursor: pointer !important;
            user-select: none !important;
            -webkit-user-select: none !important;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
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
                <h2>Connect TikTok Ad Account</h2>
                <p>Connect your TikTok Ads Manager account to start creating campaigns</p>
            </div>

            <!-- OAuth Connect Button -->
            <div style="margin-bottom: 30px; text-align: center; padding: 40px 20px;">
                <a href="oauth-init.php" style="display: inline-block; padding: 20px 50px; background: #fe2c55; color: white; text-decoration: none; border-radius: 12px; font-weight: 700; font-size: 18px; transition: all 0.3s; box-shadow: 0 4px 12px rgba(254, 44, 85, 0.3);">
                    🔗 Connect Your TikTok Ad Account
                </a>
                <p style="margin-top: 20px; font-size: 14px; color: #888; line-height: 1.6;">
                    You'll be redirected to TikTok to authorize access.<br>
                    After authorization, you can select which advertiser account to use.
                </p>
            </div>
        </div>
    </div>

    <script>
        function logout() {
            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'logout' })
            }).then(() => {
                window.location.href = 'index.php';
            });
        }
    </script>
</body>
</html>