-- TikTok Campaign Launcher - Production Database Schema
-- This schema supports multi-user OAuth connections like RedTrack

-- ============================================
-- Table 1: Users
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE,
    full_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table 2: TikTok OAuth Connections
-- ============================================
CREATE TABLE IF NOT EXISTS tiktok_connections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_in INT DEFAULT 86400,
    token_expires_at TIMESTAMP NOT NULL,
    advertiser_id VARCHAR(255),
    advertiser_name VARCHAR(255),
    advertiser_ids JSON COMMENT 'All advertiser IDs user has access to',
    scope TEXT COMMENT 'OAuth scopes granted',
    connection_status ENUM('active', 'expired', 'revoked', 'error') DEFAULT 'active',
    last_sync_at TIMESTAMP NULL COMMENT 'Last time we synced campaign data',
    last_refresh_at TIMESTAMP NULL COMMENT 'Last time we refreshed the token',
    error_message TEXT COMMENT 'Last error encountered',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_advertiser_id (advertiser_id),
    INDEX idx_status (connection_status),
    INDEX idx_token_expires (token_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table 3: TikTok Campaigns (Synced Data)
-- ============================================
CREATE TABLE IF NOT EXISTS tiktok_campaigns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connection_id INT NOT NULL,
    campaign_id VARCHAR(255) NOT NULL,
    campaign_name VARCHAR(500),
    objective_type VARCHAR(100),
    operation_status VARCHAR(50),
    budget DECIMAL(15,2),
    budget_mode VARCHAR(50),
    created_time TIMESTAMP NULL,
    modify_time TIMESTAMP NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE,
    UNIQUE KEY unique_campaign (connection_id, campaign_id),
    INDEX idx_connection_id (connection_id),
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_synced_at (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table 4: TikTok Ad Groups (Synced Data)
-- ============================================
CREATE TABLE IF NOT EXISTS tiktok_adgroups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connection_id INT NOT NULL,
    campaign_id VARCHAR(255) NOT NULL,
    adgroup_id VARCHAR(255) NOT NULL,
    adgroup_name VARCHAR(500),
    optimization_goal VARCHAR(100),
    budget DECIMAL(15,2),
    schedule_start_time TIMESTAMP NULL,
    schedule_end_time TIMESTAMP NULL,
    operation_status VARCHAR(50),
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE,
    UNIQUE KEY unique_adgroup (connection_id, adgroup_id),
    INDEX idx_connection_id (connection_id),
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_adgroup_id (adgroup_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table 5: TikTok Performance Metrics (Like RedTrack)
-- ============================================
CREATE TABLE IF NOT EXISTS tiktok_metrics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connection_id INT NOT NULL,
    stat_time_day DATE NOT NULL COMMENT 'Date of the metrics',
    campaign_id VARCHAR(255),
    adgroup_id VARCHAR(255),
    ad_id VARCHAR(255),

    -- Cost metrics
    spend DECIMAL(15,2) DEFAULT 0.00,
    cost_per_conversion DECIMAL(15,2),

    -- Performance metrics
    impressions BIGINT DEFAULT 0,
    clicks BIGINT DEFAULT 0,
    conversions INT DEFAULT 0,

    -- Calculated metrics
    ctr DECIMAL(10,4) COMMENT 'Click-through rate',
    cpc DECIMAL(15,2) COMMENT 'Cost per click',
    cpm DECIMAL(15,2) COMMENT 'Cost per thousand impressions',
    conversion_rate DECIMAL(10,4),

    -- Value metrics
    total_complete_payment BIGINT DEFAULT 0,
    total_complete_payment_rate DECIMAL(10,4),
    value_per_complete_payment DECIMAL(15,2),

    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE,
    UNIQUE KEY unique_metric (connection_id, stat_time_day, campaign_id, adgroup_id, ad_id),
    INDEX idx_connection_date (connection_id, stat_time_day),
    INDEX idx_campaign_date (campaign_id, stat_time_day),
    INDEX idx_synced_at (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table 6: Sync Logs (Track all sync operations)
-- ============================================
CREATE TABLE IF NOT EXISTS sync_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connection_id INT NOT NULL,
    sync_type ENUM('campaigns', 'adgroups', 'metrics', 'token_refresh') NOT NULL,
    status ENUM('started', 'success', 'failed') NOT NULL,
    records_synced INT DEFAULT 0,
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    duration_seconds INT,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE,
    INDEX idx_connection_type (connection_id, sync_type),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Create default admin user
-- ============================================
-- Password is 'Developer' hashed with bcrypt
INSERT INTO users (username, password_hash, email, full_name, status)
VALUES ('Sunny', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'sunny@example.com', 'Sunny Developer', 'active')
ON DUPLICATE KEY UPDATE username = username;

-- ============================================
-- Views for easy querying
-- ============================================

-- View: Active connections with last sync info
CREATE OR REPLACE VIEW v_active_connections AS
SELECT
    c.id,
    c.user_id,
    u.username,
    c.advertiser_id,
    c.advertiser_name,
    c.connection_status,
    c.token_expires_at,
    c.last_sync_at,
    c.last_refresh_at,
    TIMESTAMPDIFF(HOUR, NOW(), c.token_expires_at) AS hours_until_expiry,
    (SELECT COUNT(*) FROM tiktok_campaigns WHERE connection_id = c.id) AS campaign_count,
    (SELECT MAX(synced_at) FROM tiktok_metrics WHERE connection_id = c.id) AS last_metric_sync
FROM tiktok_connections c
JOIN users u ON c.user_id = u.id
WHERE c.connection_status = 'active';

-- View: Campaign performance summary
CREATE OR REPLACE VIEW v_campaign_performance AS
SELECT
    c.connection_id,
    c.campaign_id,
    c.campaign_name,
    c.objective_type,
    c.operation_status,
    c.budget,
    COALESCE(SUM(m.spend), 0) AS total_spend,
    COALESCE(SUM(m.impressions), 0) AS total_impressions,
    COALESCE(SUM(m.clicks), 0) AS total_clicks,
    COALESCE(SUM(m.conversions), 0) AS total_conversions,
    COALESCE(AVG(m.ctr), 0) AS avg_ctr,
    COALESCE(AVG(m.cpc), 0) AS avg_cpc,
    MAX(m.synced_at) AS last_metric_update
FROM tiktok_campaigns c
LEFT JOIN tiktok_metrics m ON c.campaign_id = m.campaign_id AND c.connection_id = m.connection_id
GROUP BY c.connection_id, c.campaign_id, c.campaign_name, c.objective_type, c.operation_status, c.budget;
