<?php
/**
 * Simple File-Based Cache System
 *
 * Provides caching for TikTok API responses to improve performance
 * and reduce redundant API calls for multiple concurrent users.
 *
 * Usage:
 *   $cache = Cache::getInstance();
 *   $data = $cache->get('key');
 *   $cache->set('key', $data, 300); // 5 min TTL
 */

class Cache {
    private static $instance = null;
    private $cacheDir;
    private $enabled = true;

    // Default TTLs (in seconds)
    const TTL_SHORT = 60;        // 1 minute - for frequently changing data
    const TTL_MEDIUM = 300;      // 5 minutes - for advertiser info
    const TTL_LONG = 600;        // 10 minutes - for pixels, identities, CTAs
    const TTL_EXTENDED = 1800;   // 30 minutes - for rarely changing data

    private function __construct() {
        $this->cacheDir = __DIR__ . '/../cache';
        $this->ensureCacheDirectory();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ensure cache directory exists
     */
    private function ensureCacheDirectory() {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Create .htaccess to prevent direct access
        $htaccess = $this->cacheDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        // Create index.php for extra security
        $index = $this->cacheDir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php // Silence is golden\n");
        }
    }

    /**
     * Generate cache key from components
     *
     * @param string $type Cache type (e.g., 'pixels', 'identities')
     * @param string $advertiserId Advertiser ID
     * @param array $extra Additional key components
     * @return string
     */
    public function generateKey($type, $advertiserId, $extra = []) {
        $components = array_merge([$type, $advertiserId], $extra);
        return md5(implode('_', $components));
    }

    /**
     * Get cached data
     *
     * @param string $key Cache key
     * @return mixed|null Cached data or null if not found/expired
     */
    public function get($key) {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if ($data === null) {
            $this->delete($key);
            return null;
        }

        // Check if expired
        if (isset($data['expires']) && time() > $data['expires']) {
            $this->delete($key);
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Set cached data
     *
     * @param string $key Cache key
     * @param mixed $value Data to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success
     */
    public function set($key, $value, $ttl = self::TTL_MEDIUM) {
        if (!$this->enabled) {
            return false;
        }

        $file = $this->getCacheFile($key);

        $data = [
            'value' => $value,
            'created' => time(),
            'expires' => time() + $ttl
        ];

        $result = file_put_contents($file, json_encode($data), LOCK_EX);
        return $result !== false;
    }

    /**
     * Delete cached data
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public function delete($key) {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    /**
     * Clear all cache for an advertiser
     *
     * @param string $advertiserId Advertiser ID
     * @return int Number of files deleted
     */
    public function clearAdvertiserCache($advertiserId) {
        $count = 0;
        $pattern = $this->cacheDir . '/*.cache';

        foreach (glob($pattern) as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            // Check if this cache belongs to the advertiser
            // (We store advertiser ID in the key generation)
            if (unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Clear all expired cache entries
     *
     * @return int Number of files deleted
     */
    public function clearExpired() {
        $count = 0;
        $pattern = $this->cacheDir . '/*.cache';

        foreach (glob($pattern) as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data && isset($data['expires']) && time() > $data['expires']) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Clear all cache
     *
     * @return int Number of files deleted
     */
    public function clearAll() {
        $count = 0;
        $pattern = $this->cacheDir . '/*.cache';

        foreach (glob($pattern) as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get cache statistics
     *
     * @return array Cache stats
     */
    public function getStats() {
        $pattern = $this->cacheDir . '/*.cache';
        $files = glob($pattern);

        $totalSize = 0;
        $expired = 0;
        $valid = 0;

        foreach ($files as $file) {
            $totalSize += filesize($file);
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data && isset($data['expires'])) {
                if (time() > $data['expires']) {
                    $expired++;
                } else {
                    $valid++;
                }
            }
        }

        return [
            'total_files' => count($files),
            'valid_entries' => $valid,
            'expired_entries' => $expired,
            'total_size_bytes' => $totalSize,
            'total_size_readable' => $this->formatBytes($totalSize),
            'cache_dir' => $this->cacheDir
        ];
    }

    /**
     * Enable/disable caching
     *
     * @param bool $enabled
     */
    public function setEnabled($enabled) {
        $this->enabled = $enabled;
    }

    /**
     * Check if caching is enabled
     *
     * @return bool
     */
    public function isEnabled() {
        return $this->enabled;
    }

    /**
     * Get cache file path
     *
     * @param string $key Cache key
     * @return string File path
     */
    private function getCacheFile($key) {
        return $this->cacheDir . '/' . $key . '.cache';
    }

    /**
     * Format bytes to human readable
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Remember pattern - get from cache or execute callback
     *
     * @param string $key Cache key
     * @param int $ttl Time to live
     * @param callable $callback Function to get fresh data
     * @return mixed
     */
    public function remember($key, $ttl, callable $callback) {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        if ($value !== null && $value !== false) {
            $this->set($key, $value, $ttl);
        }

        return $value;
    }
}
