<?php
// Include security helper
require_once __DIR__ . '/includes/Security.php';

// Initialize security settings
Security::init();

// Start session with secure settings
session_start();

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

$error = null;
$rateLimitError = null;
$clientIP = Security::getClientIP();

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Check rate limit
        $rateLimit = Security::checkRateLimit($clientIP, 5, 900); // 5 attempts per 15 minutes

        if (!$rateLimit['allowed']) {
            $rateLimitError = 'Too many login attempts. Please try again in ' . Security::formatTimeRemaining($rateLimit['reset_in']) . '.';
        } else {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            // Verify credentials
            $validUsername = $username === ($_ENV['AUTH_USERNAME'] ?? '');
            $validPassword = Security::verifyPassword($password, $_ENV['AUTH_PASSWORD'] ?? '');

            if ($validUsername && $validPassword) {
                // Clear rate limit on successful login
                Security::clearRateLimit($clientIP);

                // Regenerate session ID to prevent session fixation
                Security::regenerateSession();

                $_SESSION['authenticated'] = true;
                $_SESSION['username'] = $username;
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();

                header('Location: select-advertiser.php');
                exit;
            } else {
                // Record failed attempt
                $attempts = Security::recordFailedAttempt($clientIP);
                $remaining = 5 - $attempts;

                if ($remaining > 0) {
                    $error = 'Invalid credentials. ' . $remaining . ' attempts remaining.';
                } else {
                    $rateLimitError = 'Too many login attempts. Please try again in 15 minutes.';
                }
            }
        }
    }
}

// Check session timeout (1 hour of inactivity)
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated']) {
    $lastActivity = $_SESSION['last_activity'] ?? 0;
    if (time() - $lastActivity > 3600) {
        // Session expired
        session_destroy();
        session_start();
        $error = 'Session expired. Please login again.';
    } else {
        // Update last activity and redirect
        $_SESSION['last_activity'] = time();
        header('Location: select-advertiser.php');
        exit;
    }
}

// Generate CSRF token for form
$csrfToken = Security::generateCSRFToken();
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
            padding: 12px 15px;
            border-radius: calc(1.3rem - 6px);
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
            border: 1px solid rgba(244, 33, 46, 0.2);
        }

        .rate-limit-error {
            background: rgba(255, 152, 0, 0.1);
            color: #e65100;
            padding: 12px 15px;
            border-radius: calc(1.3rem - 6px);
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
            border: 1px solid rgba(255, 152, 0, 0.3);
        }

        .rate-limit-error strong {
            display: block;
            margin-bottom: 5px;
        }

        .btn-login:disabled {
            background: #ccc;
            border-color: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 20px;
            padding: 10px;
            background: rgba(34, 197, 94, 0.1);
            border-radius: 8px;
            font-size: 12px;
            color: #16a34a;
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

        <?php if ($rateLimitError): ?>
            <div class="rate-limit-error">
                <strong>Account Temporarily Locked</strong>
                <?php echo htmlspecialchars($rateLimitError); ?>
            </div>
        <?php elseif ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="login-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus
                       autocomplete="username" <?php echo $rateLimitError ? 'disabled' : ''; ?>>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required
                       autocomplete="current-password" <?php echo $rateLimitError ? 'disabled' : ''; ?>>
            </div>

            <button type="submit" class="btn-login" <?php echo $rateLimitError ? 'disabled' : ''; ?>>
                <?php echo $rateLimitError ? 'Please Wait...' : 'Login'; ?>
            </button>
        </form>

        <div class="security-badge">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            Secure Login
        </div>
    </div>

    <script>
        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>
