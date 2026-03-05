<?php
/**
 * Activity Logger for Production
 * Logs all user actions to:
 * 1. stderr (captured by Render.com's built-in log viewer)
 * 2. Database table (user_activity_logs) for dashboard viewing
 *
 * Every API call, login, campaign action, optimizer action is logged.
 */

class ActivityLogger {
    private static $db = null;
    private static $tableChecked = false;

    /**
     * Log a user activity event
     *
     * @param string $action    Action name (e.g., 'login', 'campaign_pause', 'api_call')
     * @param string $endpoint  API endpoint or page (e.g., 'api-optimizer.php', 'index.php')
     * @param array  $details   Additional details (merged into log)
     * @param string $status    'success', 'error', 'denied'
     */
    public static function log($action, $endpoint = '', $details = [], $status = 'success') {
        $user = $_SESSION['username'] ?? 'anonymous';
        $ip = self::getClientIP();
        $advertiserId = $_SESSION['selected_advertiser_id'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // Build log entry
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user' => $user,
            'ip' => $ip,
            'method' => $method,
            'action' => $action,
            'endpoint' => $endpoint,
            'advertiser_id' => $advertiserId,
            'status' => $status,
            'details' => $details,
        ];

        // 1. Always log to stderr (Render captures this)
        self::logToStderr($entry);

        // 2. Try to log to database (non-blocking)
        self::logToDatabase($entry, $userAgent);
    }

    /**
     * Log to stderr - visible in Render.com dashboard logs
     */
    private static function logToStderr($entry) {
        $detailStr = !empty($entry['details']) ? json_encode($entry['details']) : '';
        $line = sprintf(
            "[%s] [%s] [%s] [%s] %s %s adv=%s status=%s %s",
            $entry['timestamp'],
            $entry['user'],
            $entry['ip'],
            $entry['method'],
            $entry['action'],
            $entry['endpoint'],
            $entry['advertiser_id'] ?: '-',
            $entry['status'],
            $detailStr
        );

        // Write to stderr so Render.com log viewer captures it
        file_put_contents('php://stderr', "[ACTIVITY] $line\n");
    }

    /**
     * Log to database table
     */
    private static function logToDatabase($entry, $userAgent) {
        try {
            if (self::$db === null) {
                require_once __DIR__ . '/../database/Database.php';
                self::$db = Database::getInstance();
            }

            self::ensureTable();

            self::$db->insert('user_activity_logs', [
                'username' => substr($entry['user'], 0, 100),
                'ip_address' => $entry['ip'],
                'http_method' => $entry['method'],
                'action' => substr($entry['action'], 0, 100),
                'endpoint' => substr($entry['endpoint'], 0, 255),
                'advertiser_id' => $entry['advertiser_id'] ?: null,
                'status' => $entry['status'],
                'details' => !empty($entry['details']) ? json_encode($entry['details']) : null,
                'user_agent' => $userAgent ?: null,
            ]);
        } catch (Exception $e) {
            // Don't let logging errors break the app
            error_log("ActivityLogger DB error: " . $e->getMessage());
        }
    }

    /**
     * Auto-create the activity logs table if it doesn't exist
     */
    private static function ensureTable() {
        if (self::$tableChecked) return;
        self::$tableChecked = true;

        try {
            self::$db->fetchOne("SELECT 1 FROM user_activity_logs LIMIT 1");
        } catch (Exception $e) {
            $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');

            if ($driver === 'pgsql') {
                self::$db->query("CREATE TABLE IF NOT EXISTS user_activity_logs (
                    id SERIAL PRIMARY KEY,
                    username VARCHAR(100),
                    ip_address VARCHAR(45),
                    http_method VARCHAR(10),
                    action VARCHAR(100) NOT NULL,
                    endpoint VARCHAR(255),
                    advertiser_id VARCHAR(64),
                    status VARCHAR(20) DEFAULT 'success',
                    details JSONB,
                    user_agent VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                self::$db->query("CREATE INDEX IF NOT EXISTS idx_ual_created ON user_activity_logs(created_at)");
                self::$db->query("CREATE INDEX IF NOT EXISTS idx_ual_user ON user_activity_logs(username)");
                self::$db->query("CREATE INDEX IF NOT EXISTS idx_ual_action ON user_activity_logs(action)");
            } else {
                self::$db->query("CREATE TABLE IF NOT EXISTS user_activity_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(100),
                    ip_address VARCHAR(45),
                    http_method VARCHAR(10),
                    action VARCHAR(100) NOT NULL,
                    endpoint VARCHAR(255),
                    advertiser_id VARCHAR(64),
                    status VARCHAR(20) DEFAULT 'success',
                    details JSON,
                    user_agent VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ual_created (created_at),
                    INDEX idx_ual_user (username),
                    INDEX idx_ual_action (action)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            error_log("ActivityLogger: Created user_activity_logs table");
        }
    }

    /**
     * Get activity logs (for dashboard/admin viewing)
     */
    public static function getLogs($limit = 100, $filters = []) {
        try {
            if (self::$db === null) {
                require_once __DIR__ . '/../database/Database.php';
                self::$db = Database::getInstance();
            }

            $where = [];
            $params = [];

            if (!empty($filters['username'])) {
                $where[] = "username = ?";
                $params[] = $filters['username'];
            }
            if (!empty($filters['action'])) {
                $where[] = "action = ?";
                $params[] = $filters['action'];
            }
            if (!empty($filters['status'])) {
                $where[] = "status = ?";
                $params[] = $filters['status'];
            }
            if (!empty($filters['advertiser_id'])) {
                $where[] = "advertiser_id = ?";
                $params[] = $filters['advertiser_id'];
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $limit = intval($limit);

            return self::$db->fetchAll(
                "SELECT * FROM user_activity_logs $whereClause ORDER BY created_at DESC LIMIT $limit",
                $params
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get client IP (handles Render.com proxy)
     */
    private static function getClientIP() {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }

    /**
     * Cleanup old logs (call from cron, keeps last 30 days)
     */
    public static function cleanup($daysToKeep = 30) {
        try {
            if (self::$db === null) {
                require_once __DIR__ . '/../database/Database.php';
                self::$db = Database::getInstance();
            }

            $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');
            $interval = ($driver === 'pgsql')
                ? "INTERVAL '$daysToKeep days'"
                : "INTERVAL $daysToKeep DAY";

            self::$db->query("DELETE FROM user_activity_logs WHERE created_at < NOW() - $interval");
        } catch (Exception $e) {
            error_log("ActivityLogger cleanup error: " . $e->getMessage());
        }
    }
}
