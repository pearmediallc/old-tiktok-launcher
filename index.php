<?php
session_start();

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === $_ENV['AUTH_USERNAME'] && $password === $_ENV['AUTH_PASSWORD']) {
        $_SESSION['authenticated'] = true;
        header('Location: select-advertiser.php');
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}

// If already logged in, redirect to advertiser selection
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated']) {
    header('Location: select-advertiser.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok Campaign Launcher - Connect Your Account</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 10px;
        }

        .login-header p {
            color: #666;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(26, 26, 26, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: #1a1a1a;
            color: white;
            border: 2px solid #1a1a1a;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            background: #2d2d2d;
            box-shadow: 0 6px 20px rgba(26, 26, 26, 0.3);
        }

        .error {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 30px 0;
            color: #999;
            font-size: 14px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #ddd;
        }

        .divider span {
            padding: 0 15px;
        }

        .btn-oauth {
            width: 100%;
            padding: 16px;
            background: #fe2c55;
            color: white;
            border: 2px solid #fe2c55;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            margin-top: 10px;
        }

        .btn-oauth:hover {
            transform: translateY(-3px);
            background: #d91d45;
            box-shadow: 0 8px 25px rgba(254, 44, 85, 0.4);
        }

        .btn-oauth-primary {
            padding: 20px;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(254, 44, 85, 0.3);
        }

        .info-text {
            text-align: center;
            font-size: 14px;
            color: #666;
            margin-top: 20px;
            line-height: 1.8;
        }

        .developer-login {
            margin-top: 10px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            cursor: pointer;
        }

        .developer-login summary {
            font-size: 14px;
            color: #666;
            text-align: center;
            list-style: none;
            padding: 5px;
        }

        .developer-login summary::-webkit-details-marker {
            display: none;
        }

        .developer-login summary:hover {
            color: #333;
        }

        .developer-login[open] summary {
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>🚀 TikTok Campaign Launcher</h1>
            <p>Connect your TikTok Ads account to get started</p>
        </div>

        <a href="oauth-init.php" class="btn-oauth btn-oauth-primary">
            🔗 Connect TikTok Ads Account
        </a>

        <p class="info-text">
            <strong>✓ Secure OAuth 2.0 authentication</strong><br>
            Access all your advertiser accounts instantly<br>
            No credentials needed - authorize with TikTok directly
        </p>

        <div class="divider">
            <span>Developer Access</span>
        </div>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <details class="developer-login">
            <summary>Use developer credentials instead</summary>
            <form method="POST" action="" style="margin-top: 20px;">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <button type="submit" class="btn-login">Login with Credentials</button>
            </form>
        </details>
    </div>
</body>
</html>
