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
        $_SESSION['username'] = $username;
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
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, rgb(30, 157, 241) 0%, rgb(26, 138, 216) 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 1.3rem;
            box-shadow: 0 10px 40px rgba(30, 157, 241, 0.3);
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            color: rgb(15, 20, 25);
            font-size: 24px;
            margin-bottom: 10px;
        }

        .login-header p {
            color: rgb(15, 20, 25);
            font-size: 14px;
            opacity: 0.7;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: rgb(15, 20, 25);
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 4px);
            font-size: 14px;
            transition: border-color 0.3s;
            background: rgb(247, 249, 250);
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: rgb(30, 157, 241);
            box-shadow: 0 0 0 3px rgba(30, 157, 241, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: rgb(30, 157, 241);
            color: white;
            border: 2px solid rgb(30, 157, 241);
            border-radius: calc(1.3rem - 4px);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            background: rgb(26, 138, 216);
            border-color: rgb(26, 138, 216);
            box-shadow: 0 6px 20px rgba(30, 157, 241, 0.3);
        }

        .error {
            background: rgba(244, 33, 46, 0.1);
            color: rgb(244, 33, 46);
            padding: 10px;
            border-radius: calc(1.3rem - 6px);
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
            border: 1px solid rgba(244, 33, 46, 0.2);
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 30px 0;
            color: rgb(15, 20, 25);
            opacity: 0.5;
            font-size: 14px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgb(225, 234, 239);
        }

        .divider span {
            padding: 0 15px;
        }

        .btn-oauth {
            width: 100%;
            padding: 16px;
            background: rgb(30, 157, 241);
            color: white;
            border: 2px solid rgb(30, 157, 241);
            border-radius: calc(1.3rem - 4px);
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
            background: rgb(26, 138, 216);
            border-color: rgb(26, 138, 216);
            box-shadow: 0 8px 25px rgba(30, 157, 241, 0.4);
        }

        .btn-oauth-primary {
            padding: 20px;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(30, 157, 241, 0.3);
        }

        .info-text {
            text-align: center;
            font-size: 14px;
            color: rgb(15, 20, 25);
            opacity: 0.7;
            margin-top: 20px;
            line-height: 1.8;
        }

        .developer-login {
            margin-top: 10px;
            padding: 15px;
            background: rgb(227, 236, 246);
            border-radius: calc(1.3rem - 4px);
            cursor: pointer;
        }

        .developer-login summary {
            font-size: 14px;
            color: rgb(15, 20, 25);
            opacity: 0.7;
            text-align: center;
            list-style: none;
            padding: 5px;
        }

        .developer-login summary::-webkit-details-marker {
            display: none;
        }

        .developer-login summary:hover {
            opacity: 1;
        }

        .developer-login[open] summary {
            color: rgb(15, 20, 25);
            opacity: 1;
            font-weight: 600;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>🚀 TikTok Campaign Launcher</h1>
            <p>Login to access the dashboard</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn-login">Login</button>
        </form>
    </div>
</body>
</html>
