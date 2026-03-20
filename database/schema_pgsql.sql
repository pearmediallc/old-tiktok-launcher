-- TikTok Campaign Launcher - PostgreSQL Schema for Render.com
-- This schema is compatible with PostgreSQL

-- ============================================
-- Table 1: Users
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE,
    full_name VARCHAR(255),
    role VARCHAR(20) DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'suspended'))
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);

-- ============================================
-- Table 2: TikTok OAuth Connections
-- ============================================
CREATE TABLE IF NOT EXISTS tiktok_connections (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_in INT DEFAULT 86400,
    token_expires_at TIMESTAMP NOT NULL,
    advertiser_id VARCHAR(255),
    advertiser_name VARCHAR(255),
    advertiser_ids JSONB,
    scope TEXT,
    connection_status VARCHAR(20) DEFAULT 'active' CHECK (connection_status IN ('active', 'expired', 'revoked', 'error')),
    last_sync_at TIMESTAMP NULL,
    last_refresh_at TIMESTAMP NULL,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tiktok_connections_user_id ON tiktok_connections(user_id);
CREATE INDEX IF NOT EXISTS idx_tiktok_connections_advertiser_id ON tiktok_connections(advertiser_id);
CREATE INDEX IF NOT EXISTS idx_tiktok_connections_status ON tiktok_connections(connection_status);
CREATE INDEX IF NOT EXISTS idx_tiktok_connections_token_expires ON tiktok_connections(token_expires_at);

-- ============================================
-- Table 3: TikTok Campaigns (Synced Data)
-- ============================================
CREATE TABLE IF NOT EXISTS tiktok_campaigns (
    id SERIAL PRIMARY KEY,
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
    UNIQUE (connection_id, campaign_id)
);

CREATE INDEX IF NOT EXISTS idx_tiktok_campaigns_connection_id ON tiktok_campaigns(connection_id);
CREATE INDEX IF NOT EXISTS idx_tiktok_campaigns_campaign_id ON tiktok_campaigns(campaign_id);
CREATE INDEX IF NOT EXISTS idx_tiktok_campaigns_synced_at ON tiktok_campaigns(synced_at);

-- ============================================
-- Table 4: TikTok Ad Groups (Synced Data)
-- ============================================
CREATE TABLE IF NOT EXISTS tiktok_adgroups (
    id SERIAL PRIMARY KEY,
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
    UNIQUE (connection_id, adgroup_id)
);

CREATE INDEX IF NOT EXISTS idx_tiktok_adgroups_connection_id ON tiktok_adgroups(connection_id);
CREATE INDEX IF NOT EXISTS idx_tiktok_adgroups_campaign_id ON tiktok_adgroups(campaign_id);
CREATE INDEX IF NOT EXISTS idx_tiktok_adgroups_adgroup_id ON tiktok_adgroups(adgroup_id);

-- ============================================
-- Table 5: TikTok Performance Metrics (Like RedTrack)
-- ============================================
CREATE TABLE IF NOT EXISTS tiktok_metrics (
    id SERIAL PRIMARY KEY,
    connection_id INT NOT NULL,
    stat_time_day DATE NOT NULL,
    campaign_id VARCHAR(255),
    adgroup_id VARCHAR(255),
    ad_id VARCHAR(255),
    spend DECIMAL(15,2) DEFAULT 0.00,
    cost_per_conversion DECIMAL(15,2),
    impressions BIGINT DEFAULT 0,
    clicks BIGINT DEFAULT 0,
    conversions INT DEFAULT 0,
    ctr DECIMAL(10,4),
    cpc DECIMAL(15,2),
    cpm DECIMAL(15,2),
    conversion_rate DECIMAL(10,4),
    total_complete_payment BIGINT DEFAULT 0,
    total_complete_payment_rate DECIMAL(10,4),
    value_per_complete_payment DECIMAL(15,2),
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE,
    UNIQUE (connection_id, stat_time_day, campaign_id, adgroup_id, ad_id)
);

CREATE INDEX IF NOT EXISTS idx_tiktok_metrics_connection_date ON tiktok_metrics(connection_id, stat_time_day);
CREATE INDEX IF NOT EXISTS idx_tiktok_metrics_campaign_date ON tiktok_metrics(campaign_id, stat_time_day);
CREATE INDEX IF NOT EXISTS idx_tiktok_metrics_synced_at ON tiktok_metrics(synced_at);

-- ============================================
-- Table 6: Sync Logs (Track all sync operations)
-- ============================================
CREATE TABLE IF NOT EXISTS sync_logs (
    id SERIAL PRIMARY KEY,
    connection_id INT NOT NULL,
    sync_type VARCHAR(20) NOT NULL CHECK (sync_type IN ('campaigns', 'adgroups', 'metrics', 'token_refresh')),
    status VARCHAR(20) NOT NULL CHECK (status IN ('started', 'success', 'failed')),
    records_synced INT DEFAULT 0,
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    duration_seconds INT,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sync_logs_connection_type ON sync_logs(connection_id, sync_type);
CREATE INDEX IF NOT EXISTS idx_sync_logs_status ON sync_logs(status);
CREATE INDEX IF NOT EXISTS idx_sync_logs_started_at ON sync_logs(started_at);

-- ============================================
-- Table 7: Tool-Created Portfolios (CRITICAL FOR PORTFOLIO STORAGE)
-- ============================================
CREATE TABLE IF NOT EXISTS tool_portfolios (
    id SERIAL PRIMARY KEY,
    advertiser_id VARCHAR(255) NOT NULL,
    creative_portfolio_id VARCHAR(255) NOT NULL,
    portfolio_name VARCHAR(500),
    portfolio_type VARCHAR(50) DEFAULT 'CTA',
    portfolio_content JSONB,
    created_by_tool BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (advertiser_id, creative_portfolio_id)
);

CREATE INDEX IF NOT EXISTS idx_tool_portfolios_advertiser_id ON tool_portfolios(advertiser_id);
CREATE INDEX IF NOT EXISTS idx_tool_portfolios_portfolio_id ON tool_portfolios(creative_portfolio_id);
CREATE INDEX IF NOT EXISTS idx_tool_portfolios_created_at ON tool_portfolios(created_at);

-- ============================================
-- Create default admin user
-- ============================================
-- Password is 'Developer' hashed with bcrypt
INSERT INTO users (username, password_hash, email, full_name, role, status)
VALUES ('Sunny', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'sunny@example.com', 'Sunny Developer', 'admin', 'active')
ON CONFLICT (username) DO NOTHING;

-- Migrate existing users without role to 'user' (admin must be set manually)
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) DEFAULT 'user' CHECK (role IN ('admin', 'user'));

-- remember_me_tokens for persistent 7-day sessions
CREATE TABLE IF NOT EXISTS remember_me_tokens (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_rmt_token ON remember_me_tokens(token_hash);
CREATE INDEX IF NOT EXISTS idx_rmt_user ON remember_me_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_rmt_expires ON remember_me_tokens(expires_at);

-- Per-user Slack connections
CREATE TABLE IF NOT EXISTS user_slack_connections (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    team_id VARCHAR(100),
    team_name VARCHAR(255),
    webhook_url TEXT DEFAULT '',
    channel VARCHAR(255),
    channel_id VARCHAR(100),
    bot_user_id VARCHAR(100),
    scope TEXT,
    authed_user_id VARCHAR(100),
    access_token TEXT,
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_usc_user_id ON user_slack_connections(user_id);

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
    EXTRACT(EPOCH FROM (c.token_expires_at - NOW())) / 3600 AS hours_until_expiry,
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

-- ============================================
-- Function to auto-update updated_at timestamp
-- ============================================
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Triggers for updated_at
CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_tiktok_connections_updated_at BEFORE UPDATE ON tiktok_connections
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_tool_portfolios_updated_at BEFORE UPDATE ON tool_portfolios
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
