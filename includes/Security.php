<?php
/**
 * Security Helper Class
 * Handles rate limiting, CSRF protection, and session security
 */

class Security {
    private static $rateLimitFile;

    /**
     * Initialize security settings
     */
    public static function init() {
        self::$rateLimitFile = __DIR__ . '/../cache/rate_limits.json';
        self::configureSession();
    }

    /**
     * Configure secure session settings
     */
    public static function configureSession() {
        // Only set these before session_start()
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.gc_maxlifetime', 3600); // 1 hour
            ini_set('session.use_strict_mode', '1');

            // Enable secure cookies if using HTTPS
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                ini_set('session.cookie_secure', '1');
            }
        }
    }

    /**
     * Regenerate session ID after login
     */
    public static function regenerateSession() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token
     */
    public static function validateCSRFToken($token) {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Get CSRF token input field HTML
     */
    public static function csrfField() {
        $token = self::generateCSRFToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Check rate limit for login attempts
     * @param string $ip IP address
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_in' => int]
     */
    public static function checkRateLimit($ip, $maxAttempts = 5, $windowSeconds = 900) {
        $data = self::getRateLimitData();
        $now = time();
        $key = 'login_' . md5($ip);

        // Clean up old entries
        foreach ($data as $k => $v) {
            if ($v['expires'] < $now) {
                unset($data[$k]);
            }
        }

        if (!isset($data[$key])) {
            $data[$key] = [
                'attempts' => 0,
                'expires' => $now + $windowSeconds,
                'first_attempt' => $now
            ];
        }

        // Reset if window expired
        if ($data[$key]['expires'] < $now) {
            $data[$key] = [
                'attempts' => 0,
                'expires' => $now + $windowSeconds,
                'first_attempt' => $now
            ];
        }

        $remaining = $maxAttempts - $data[$key]['attempts'];
        $resetIn = $data[$key]['expires'] - $now;

        self::saveRateLimitData($data);

        return [
            'allowed' => $remaining > 0,
            'remaining' => max(0, $remaining),
            'reset_in' => $resetIn
        ];
    }

    /**
     * Record a failed login attempt
     */
    public static function recordFailedAttempt($ip) {
        $data = self::getRateLimitData();
        $key = 'login_' . md5($ip);
        $now = time();

        if (!isset($data[$key]) || $data[$key]['expires'] < $now) {
            $data[$key] = [
                'attempts' => 1,
                'expires' => $now + 900, // 15 minutes
                'first_attempt' => $now
            ];
        } else {
            $data[$key]['attempts']++;
        }

        self::saveRateLimitData($data);

        return $data[$key]['attempts'];
    }

    /**
     * Clear rate limit for IP (after successful login)
     */
    public static function clearRateLimit($ip) {
        $data = self::getRateLimitData();
        $key = 'login_' . md5($ip);
        unset($data[$key]);
        self::saveRateLimitData($data);
    }

    /**
     * Get rate limit data from file
     */
    private static function getRateLimitData() {
        if (!file_exists(self::$rateLimitFile)) {
            return [];
        }
        $content = file_get_contents(self::$rateLimitFile);
        return json_decode($content, true) ?: [];
    }

    /**
     * Save rate limit data to file
     */
    private static function saveRateLimitData($data) {
        $dir = dirname(self::$rateLimitFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(self::$rateLimitFile, json_encode($data));
    }

    /**
     * Verify password against hash
     * For migration: if no hash exists, compare plain text and suggest update
     */
    public static function verifyPassword($password, $hash) {
        // Check if it's a proper hash (starts with $2y$ for bcrypt)
        if (strpos($hash, '$2y$') === 0) {
            return password_verify($password, $hash);
        }
        // Fall back to plain text comparison for backwards compatibility
        return $password === $hash;
    }

    /**
     * Hash a password
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Get client IP address
     */
    public static function getClientIP() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Check for proxy headers (be careful with these in production)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }

    /**
     * Sanitize and validate input
     */
    public static function sanitizeInput($input, $type = 'string') {
        if ($input === null) {
            return null;
        }

        switch ($type) {
            case 'int':
                return filter_var($input, FILTER_VALIDATE_INT) !== false ? (int)$input : null;
            case 'float':
                return filter_var($input, FILTER_VALIDATE_FLOAT) !== false ? (float)$input : null;
            case 'email':
                return filter_var($input, FILTER_VALIDATE_EMAIL) ?: null;
            case 'url':
                return filter_var($input, FILTER_VALIDATE_URL) ?: null;
            case 'alphanumeric':
                return preg_match('/^[a-zA-Z0-9_\-\s]+$/', $input) ? $input : null;
            case 'string':
            default:
                return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * Redact sensitive data from arrays (for logging)
     */
    public static function redactSensitiveData($data, $keysToRedact = null) {
        if ($keysToRedact === null) {
            $keysToRedact = [
                'password', 'token', 'access_token', 'refresh_token',
                'secret', 'api_key', 'authorization', 'auth', 'credential',
                'Authorization', 'Cookie', 'Set-Cookie'
            ];
        }

        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);
            foreach ($keysToRedact as $redactKey) {
                if (stripos($keyLower, strtolower($redactKey)) !== false) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }
            if (is_array($value)) {
                $data[$key] = self::redactSensitiveData($value, $keysToRedact);
            }
        }

        return $data;
    }

    /**
     * Format remaining time for display
     */
    public static function formatTimeRemaining($seconds) {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        } elseif ($seconds < 3600) {
            return ceil($seconds / 60) . ' minutes';
        } else {
            return ceil($seconds / 3600) . ' hours';
        }
    }
}
