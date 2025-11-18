<?php
require_once __DIR__ . '/../Database.php';

/**
 * TikTok Connection Model
 * Handles OAuth token storage and management
 */
class TikTokConnection {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new TikTok connection
     */
    public function create($userId, $tokenData, $advertiserIds = []) {
        $expiresAt = date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 86400));

        $data = [
            'user_id' => $userId,
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? '',
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'expires_in' => $tokenData['expires_in'] ?? 86400,
            'token_expires_at' => $expiresAt,
            'advertiser_ids' => json_encode($advertiserIds),
            'scope' => $tokenData['scope'] ?? '',
            'connection_status' => 'active',
            'last_refresh_at' => date('Y-m-d H:i:s')
        ];

        try {
            $connectionId = $this->db->insert('tiktok_connections', $data);
            error_log("Created TikTok connection ID: $connectionId for user ID: $userId");
            return $connectionId;
        } catch (PDOException $e) {
            error_log("Failed to create TikTok connection: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update connection with selected advertiser
     */
    public function setAdvertiser($connectionId, $advertiserId, $advertiserName = null) {
        return $this->db->update(
            'tiktok_connections',
            [
                'advertiser_id' => $advertiserId,
                'advertiser_name' => $advertiserName
            ],
            'id = :id',
            ['id' => $connectionId]
        );
    }

    /**
     * Get connection by ID
     */
    public function getById($id) {
        return $this->db->fetchOne(
            "SELECT * FROM tiktok_connections WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Get active connection for user
     */
    public function getByUserId($userId) {
        return $this->db->fetchOne(
            "SELECT * FROM tiktok_connections WHERE user_id = :user_id AND connection_status = 'active' ORDER BY created_at DESC LIMIT 1",
            ['user_id' => $userId]
        );
    }

    /**
     * Get all connections for user
     */
    public function getAllByUserId($userId) {
        return $this->db->fetchAll(
            "SELECT * FROM tiktok_connections WHERE user_id = :user_id ORDER BY created_at DESC",
            ['user_id' => $userId]
        );
    }

    /**
     * Update access token (after refresh)
     */
    public function updateTokens($connectionId, $accessToken, $refreshToken, $expiresIn) {
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

        return $this->db->update(
            'tiktok_connections',
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $expiresIn,
                'token_expires_at' => $expiresAt,
                'last_refresh_at' => date('Y-m-d H:i:s'),
                'connection_status' => 'active'
            ],
            'id = :id',
            ['id' => $connectionId]
        );
    }

    /**
     * Mark connection as expired
     */
    public function markAsExpired($connectionId, $errorMessage = null) {
        $data = ['connection_status' => 'expired'];
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        return $this->db->update(
            'tiktok_connections',
            $data,
            'id = :id',
            ['id' => $connectionId]
        );
    }

    /**
     * Mark connection as revoked
     */
    public function markAsRevoked($connectionId) {
        return $this->db->update(
            'tiktok_connections',
            ['connection_status' => 'revoked'],
            'id = :id',
            ['id' => $connectionId]
        );
    }

    /**
     * Update last sync time
     */
    public function updateLastSync($connectionId) {
        return $this->db->update(
            'tiktok_connections',
            ['last_sync_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $connectionId]
        );
    }

    /**
     * Get connections that need token refresh
     * (expires within next 24 hours)
     */
    public function getConnectionsNeedingRefresh() {
        return $this->db->fetchAll(
            "SELECT * FROM tiktok_connections
             WHERE connection_status = 'active'
             AND token_expires_at < DATE_ADD(NOW(), INTERVAL 24 HOUR)
             AND refresh_token IS NOT NULL
             AND refresh_token != ''
             ORDER BY token_expires_at ASC"
        );
    }

    /**
     * Get all active connections
     */
    public function getAllActive() {
        return $this->db->fetchAll(
            "SELECT * FROM tiktok_connections WHERE connection_status = 'active'"
        );
    }

    /**
     * Delete connection
     */
    public function delete($connectionId) {
        return $this->db->delete('tiktok_connections', 'id = :id', ['id' => $connectionId]);
    }

    /**
     * Check if token is expired
     */
    public function isTokenExpired($connectionId) {
        $connection = $this->getById($connectionId);
        if (!$connection) {
            return true;
        }

        $expiresAt = strtotime($connection['token_expires_at']);
        return time() >= $expiresAt;
    }

    /**
     * Check if token expires soon (within 1 hour)
     */
    public function isTokenExpiringSoon($connectionId) {
        $connection = $this->getById($connectionId);
        if (!$connection) {
            return true;
        }

        $expiresAt = strtotime($connection['token_expires_at']);
        $oneHourFromNow = time() + 3600;

        return $expiresAt <= $oneHourFromNow;
    }
}
