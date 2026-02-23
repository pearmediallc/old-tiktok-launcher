-- TikTok Optimizer - PostgreSQL Database Schema
-- 3 tables: rules config, monitored campaigns, action logs

-- ============================================
-- Table 1: Optimizer Rules (configurable thresholds)
-- ============================================
CREATE TABLE IF NOT EXISTS optimizer_rules (
    id SERIAL PRIMARY KEY,
    rule_key VARCHAR(50) NOT NULL UNIQUE,
    rule_name VARCHAR(255) NOT NULL,
    rule_group VARCHAR(50) NOT NULL DEFAULT 'home_insurance',
    metric_source VARCHAR(20) NOT NULL CHECK (metric_source IN ('tiktok','redtrack')),
    metric_field VARCHAR(50) NOT NULL,
    operator VARCHAR(10) NOT NULL CHECK (operator IN ('gt','lt','gte','lte','eq')),
    threshold DECIMAL(12,4) NOT NULL,
    secondary_metric VARCHAR(50) DEFAULT NULL,
    secondary_operator VARCHAR(10) DEFAULT NULL CHECK (secondary_operator IN ('gt','lt','gte','lte','eq',NULL)),
    secondary_threshold DECIMAL(12,4) DEFAULT NULL,
    enabled SMALLINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default Home Insurance rules
INSERT INTO optimizer_rules (rule_key, rule_name, rule_group, metric_source, metric_field, operator, threshold, secondary_metric, secondary_operator, secondary_threshold, enabled)
VALUES
    ('hi_spend_no_conversion', 'Spend without Conversion', 'home_insurance', 'tiktok', 'spend', 'gte', 30.0000, 'conversions', 'eq', 0.0000, 1),
    ('hi_high_cpc', 'High CPC', 'home_insurance', 'tiktok', 'cpc', 'gt', 3.0000, NULL, NULL, NULL, 1),
    ('hi_low_lpctr', 'Low LP CTR', 'home_insurance', 'redtrack', 'lp_ctr', 'lt', 20.0000, NULL, NULL, NULL, 1),
    ('hi_low_ctr', 'Low CTR', 'home_insurance', 'tiktok', 'ctr', 'lt', 0.7000, NULL, NULL, NULL, 1)
ON CONFLICT (rule_key) DO UPDATE SET rule_name = EXCLUDED.rule_name;

-- Insert default Medicare rules
INSERT INTO optimizer_rules (rule_key, rule_name, rule_group, metric_source, metric_field, operator, threshold, secondary_metric, secondary_operator, secondary_threshold, enabled)
VALUES
    ('med_spend_no_conversion', 'Spend without Conversion', 'medicare', 'tiktok', 'spend', 'gte', 30.0000, 'conversions', 'eq', 0.0000, 1),
    ('med_high_cpc', 'High CPC', 'medicare', 'tiktok', 'cpc', 'gt', 0.7000, NULL, NULL, NULL, 1),
    ('med_low_lpctr', 'Low LP CTR', 'medicare', 'redtrack', 'lp_ctr', 'lt', 20.0000, NULL, NULL, NULL, 1),
    ('med_high_ctr', 'High CTR', 'medicare', 'tiktok', 'ctr', 'gt', 1.0000, NULL, NULL, NULL, 1)
ON CONFLICT (rule_key) DO UPDATE SET rule_name = EXCLUDED.rule_name;

-- ============================================
-- Table 2: Monitored Campaigns
-- ============================================
CREATE TABLE IF NOT EXISTS optimizer_monitored_campaigns (
    id SERIAL PRIMARY KEY,
    campaign_id VARCHAR(64) NOT NULL,
    advertiser_id VARCHAR(64) NOT NULL,
    campaign_name VARCHAR(500),
    rule_group VARCHAR(50) NOT NULL DEFAULT 'home_insurance',
    redtrack_campaign_name VARCHAR(500) DEFAULT NULL,
    monitoring_enabled SMALLINT DEFAULT 1,
    paused_by_optimizer SMALLINT DEFAULT 0,
    paused_at TIMESTAMP NULL,
    resume_at TIMESTAMP NULL,
    last_checked_at TIMESTAMP NULL,
    last_violation_rule VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (campaign_id, advertiser_id)
);

CREATE INDEX IF NOT EXISTS idx_opt_mc_monitoring ON optimizer_monitored_campaigns(monitoring_enabled);
CREATE INDEX IF NOT EXISTS idx_opt_mc_paused ON optimizer_monitored_campaigns(paused_by_optimizer, resume_at);

-- ============================================
-- Table 3: Optimizer Action Logs
-- ============================================
CREATE TABLE IF NOT EXISTS optimizer_logs (
    id SERIAL PRIMARY KEY,
    campaign_id VARCHAR(64) NOT NULL,
    advertiser_id VARCHAR(64) NOT NULL,
    action VARCHAR(20) NOT NULL CHECK (action IN ('pause','resume','rule_check')),
    rule_key VARCHAR(50) DEFAULT NULL,
    rule_details TEXT,
    metrics_snapshot JSONB,
    api_response JSONB,
    success SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_opt_logs_campaign ON optimizer_logs(campaign_id);
CREATE INDEX IF NOT EXISTS idx_opt_logs_created ON optimizer_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_opt_logs_action ON optimizer_logs(action);
