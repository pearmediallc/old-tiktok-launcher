-- TikTok Optimizer - Database Schema
-- 3 tables: rules config, monitored campaigns, action logs

-- ============================================
-- Table 1: Optimizer Rules (configurable thresholds)
-- ============================================
CREATE TABLE IF NOT EXISTS optimizer_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_key VARCHAR(50) NOT NULL UNIQUE,
    rule_name VARCHAR(255) NOT NULL,
    metric_source ENUM('tiktok','redtrack') NOT NULL,
    metric_field VARCHAR(50) NOT NULL,
    operator ENUM('gt','lt','gte','lte','eq') NOT NULL,
    threshold DECIMAL(12,4) NOT NULL,
    secondary_metric VARCHAR(50) DEFAULT NULL,
    secondary_operator ENUM('gt','lt','gte','lte','eq') DEFAULT NULL,
    secondary_threshold DECIMAL(12,4) DEFAULT NULL,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default Home Insurance rules
INSERT INTO optimizer_rules (rule_key, rule_name, metric_source, metric_field, operator, threshold, secondary_metric, secondary_operator, secondary_threshold, enabled)
VALUES
    ('spend_no_conversion', 'Spend without Conversion', 'tiktok', 'spend', 'gte', 30.0000, 'conversions', 'eq', 0.0000, 1),
    ('high_cpc', 'High CPC', 'tiktok', 'cpc', 'gt', 3.0000, NULL, NULL, NULL, 1),
    ('low_lpctr', 'Low LP CTR', 'redtrack', 'lp_ctr', 'lt', 20.0000, NULL, NULL, NULL, 1),
    ('low_ctr', 'Low CTR', 'redtrack', 'ctr', 'lt', 0.7000, NULL, NULL, NULL, 1)
ON DUPLICATE KEY UPDATE rule_name = VALUES(rule_name);

-- ============================================
-- Table 2: Monitored Campaigns
-- ============================================
CREATE TABLE IF NOT EXISTS optimizer_monitored_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(64) NOT NULL,
    advertiser_id VARCHAR(64) NOT NULL,
    campaign_name VARCHAR(500),
    monitoring_enabled TINYINT(1) DEFAULT 1,
    paused_by_optimizer TINYINT(1) DEFAULT 0,
    paused_at TIMESTAMP NULL,
    resume_at TIMESTAMP NULL,
    last_checked_at TIMESTAMP NULL,
    last_violation_rule VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_campaign (campaign_id, advertiser_id),
    INDEX idx_monitoring (monitoring_enabled),
    INDEX idx_paused (paused_by_optimizer, resume_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table 3: Optimizer Action Logs
-- ============================================
CREATE TABLE IF NOT EXISTS optimizer_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(64) NOT NULL,
    advertiser_id VARCHAR(64) NOT NULL,
    action ENUM('pause','resume','rule_check') NOT NULL,
    rule_key VARCHAR(50) DEFAULT NULL,
    rule_details TEXT,
    metrics_snapshot JSON,
    api_response JSON,
    success TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign_id),
    INDEX idx_created (created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
