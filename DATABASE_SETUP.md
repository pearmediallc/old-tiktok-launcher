# Database Setup Guide

## Overview

This guide will help you set up the MySQL database for the TikTok Campaign Launcher production OAuth system.

## Why Database Storage?

The production OAuth implementation requires database storage because:

1. **Persistent Tokens**: OAuth access tokens need to survive beyond PHP sessions
2. **Token Refresh**: Refresh tokens allow automatic renewal without user intervention
3. **Multi-User Support**: Each user can have their own TikTok connections
4. **Campaign Data Sync**: Store campaign metrics, costs, and performance data
5. **Audit Trail**: Track token refresh history and sync operations

## Prerequisites

- MySQL 5.7+ or MariaDB 10.2+
- PHP 7.4+ with PDO MySQL extension
- Database credentials (configured in `.env`)

## Quick Setup

### Step 1: Ensure Database Configuration in `.env`

Your `.env` file should have these settings:

```env
# Database Configuration
DB_HOST=localhost
DB_NAME=tiktok_launcher
DB_USER=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
```

Update these values according to your local MySQL setup.

### Step 2: Run the Setup Script

```bash
php database/setup.php
```

This will:
- ✅ Create the `tiktok_launcher` database
- ✅ Create all required tables
- ✅ Create views for easy querying
- ✅ Create default admin user (from .env credentials)

### Step 3: Verify Database

Check that everything was created:

```bash
mysql -u root -p
```

```sql
USE tiktok_launcher;
SHOW TABLES;
```

You should see:
- `users` - User accounts
- `tiktok_connections` - OAuth tokens
- `tiktok_campaigns` - Synced campaigns
- `tiktok_adgroups` - Synced ad groups
- `tiktok_metrics` - Performance data
- `sync_logs` - Sync history
- `tool_portfolios` - **NEW!** Portfolios created by the launcher
- `v_active_connections` - View
- `v_campaign_performance` - View

## Database Schema

### users
Stores user accounts for the application.

```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,  -- bcrypt hashed
    email VARCHAR(255) UNIQUE,
    full_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active'
);
```

**Default User**: Created from `.env` AUTH_USERNAME and AUTH_PASSWORD

### tiktok_connections
Stores OAuth tokens and connection status.

```sql
CREATE TABLE tiktok_connections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    access_token TEXT NOT NULL,           -- OAuth access token
    refresh_token TEXT NOT NULL,          -- OAuth refresh token
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_in INT DEFAULT 86400,         -- Token lifetime in seconds
    token_expires_at TIMESTAMP NOT NULL,  -- Calculated expiration
    advertiser_id VARCHAR(255),           -- Selected advertiser
    advertiser_name VARCHAR(500),
    advertiser_ids JSON,                  -- All available advertisers
    scope TEXT,
    connection_status ENUM('active', 'expired', 'revoked', 'error'),
    error_message TEXT,
    last_sync_at TIMESTAMP NULL,
    last_refresh_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Key Features**:
- One user can have multiple connections (though typically just one active)
- Tracks token expiration for automatic refresh
- Stores all advertiser IDs user has access to
- Connection status for monitoring

### tiktok_campaigns
Synced campaign data from TikTok API.

```sql
CREATE TABLE tiktok_campaigns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connection_id INT NOT NULL,
    campaign_id VARCHAR(255) NOT NULL,
    campaign_name VARCHAR(500),
    objective_type VARCHAR(100),
    status VARCHAR(50),
    budget DECIMAL(15,2),
    budget_mode VARCHAR(50),
    created_time TIMESTAMP NULL,
    modified_time TIMESTAMP NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE,
    UNIQUE KEY unique_campaign (connection_id, campaign_id)
);
```

### tiktok_adgroups
Synced ad group data from TikTok API.

```sql
CREATE TABLE tiktok_adgroups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connection_id INT NOT NULL,
    campaign_id VARCHAR(255) NOT NULL,
    adgroup_id VARCHAR(255) NOT NULL,
    adgroup_name VARCHAR(500),
    status VARCHAR(50),
    budget DECIMAL(15,2),
    bid_price DECIMAL(15,2),
    created_time TIMESTAMP NULL,
    modified_time TIMESTAMP NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE,
    UNIQUE KEY unique_adgroup (connection_id, adgroup_id)
);
```

### tiktok_metrics
Performance metrics and cost tracking (like RedTrack).

```sql
CREATE TABLE tiktok_metrics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connection_id INT NOT NULL,
    stat_time_day DATE NOT NULL,
    campaign_id VARCHAR(255),
    adgroup_id VARCHAR(255),
    ad_id VARCHAR(255),
    spend DECIMAL(15,2) DEFAULT 0.00,
    impressions BIGINT DEFAULT 0,
    clicks BIGINT DEFAULT 0,
    conversions INT DEFAULT 0,
    ctr DECIMAL(10,4),
    cpc DECIMAL(15,2),
    cpm DECIMAL(15,2),
    cvr DECIMAL(10,4),
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE,
    UNIQUE KEY unique_metric (connection_id, stat_time_day, campaign_id, adgroup_id, ad_id)
);
```

**Key Features**:
- Daily granularity
- Tracks cost (spend), impressions, clicks, conversions
- Calculated metrics: CTR, CPC, CPM, CVR
- Can be grouped by campaign, ad group, or ad

### sync_logs
Audit trail for synchronization operations.

```sql
CREATE TABLE sync_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connection_id INT NOT NULL,
    sync_type ENUM('campaigns', 'adgroups', 'metrics', 'token_refresh'),
    sync_status ENUM('success', 'partial', 'failed'),
    records_synced INT DEFAULT 0,
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (connection_id) REFERENCES tiktok_connections(id) ON DELETE CASCADE
);
```

### tool_portfolios (NEW!)
**Stores ALL portfolios created through the TikTok Launcher tool.**

This table ensures portfolios appear in the "existing portfolios" list, even if TikTok API pagination doesn't show them.

```sql
CREATE TABLE tool_portfolios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    advertiser_id VARCHAR(255) NOT NULL,           -- TikTok Advertiser ID
    creative_portfolio_id VARCHAR(255) NOT NULL,   -- Portfolio ID from TikTok API
    portfolio_name VARCHAR(500),                    -- Name of the portfolio
    portfolio_type VARCHAR(50) DEFAULT 'CTA',      -- Portfolio type (CTA, etc)
    portfolio_content JSON,                         -- Full portfolio content data
    created_by_tool BOOLEAN DEFAULT TRUE,           -- Whether created by launcher
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_portfolio (advertiser_id, creative_portfolio_id),
    INDEX idx_advertiser_id (advertiser_id)
);
```

**Key Features**:
- **Automatic Storage**: Every portfolio created via the tool is automatically saved
- **Deduplication**: UNIQUE constraint prevents duplicate entries
- **Fast Lookups**: Indexed by advertiser_id for quick queries
- **JSON Storage**: Full portfolio content stored for future reference
- **Smart Merging**: Database portfolios are merged with TikTok API results when fetching

**How It Works**:
1. **Portfolio Creation**: When creating a portfolio via `create_cta_portfolio` or "Frequently Used CTAs", it's automatically saved to this table
2. **Portfolio Retrieval**: When fetching portfolios, backend merges database records with TikTok API results
3. **Always Visible**: Portfolios created by the tool will always appear in the "existing portfolios" list

## How OAuth Flow Works with Database

### 1. User Login
```
User enters credentials → index.php validates → Sets $_SESSION['user_id']
```

### 2. OAuth Connection
```
User clicks "Connect TikTok" → oauth-init.php → TikTok OAuth → oauth-callback.php
```

### 3. Token Storage (oauth-callback.php)
```php
// Exchange auth_code for tokens
$tokenData = exchangeCodeForToken($auth_code);

// Store in database
$tiktokConnection = new TikTokConnection();
$connectionId = $tiktokConnection->create(
    $_SESSION['user_id'],
    $tokenData,
    $advertiserIds
);
```

### 4. Token Retrieval (api.php)
```php
// Load from database
$tiktokConnection = new TikTokConnection();
$connection = $tiktokConnection->getByUserId($_SESSION['user_id']);

if ($connection && !$tiktokConnection->isTokenExpired($connection['id'])) {
    $access_token = $connection['access_token'];
}
```

### 5. Advertiser Selection (select-advertiser-oauth.php)
```php
// User selects advertiser
$tiktokConnection->setAdvertiser($connectionId, $advertiserId);
```

### 6. Token Refresh (Future Implementation)
```php
// Cron job runs every 12 hours
$connections = $tiktokConnection->getConnectionsNeedingRefresh();
foreach ($connections as $conn) {
    $newTokens = refreshToken($conn['refresh_token']);
    $tiktokConnection->updateTokens($conn['id'], $newTokens);
}
```

## Testing Database Integration

### 1. Check User Created
```sql
SELECT * FROM users WHERE username = 'Sunny';
```

### 2. Test OAuth Connection (After OAuth Flow)
```sql
SELECT
    c.id,
    c.advertiser_id,
    c.connection_status,
    c.token_expires_at,
    u.username
FROM tiktok_connections c
JOIN users u ON c.user_id = u.id
WHERE u.username = 'Sunny';
```

### 3. Check Token Expiration
```sql
SELECT
    id,
    advertiser_id,
    token_expires_at,
    TIMESTAMPDIFF(HOUR, NOW(), token_expires_at) AS hours_until_expiry
FROM tiktok_connections
WHERE connection_status = 'active';
```

### 4. Test Portfolio Storage (NEW!)

**Step 1**: Create a portfolio through the tool
- Login to TikTok Launcher
- Complete OAuth flow and select an advertiser
- Navigate to Ad creation
- Click "Create New Portfolio" under Dynamic CTA
- Add some CTAs and create the portfolio

**Step 2**: Verify portfolio is stored in database
```sql
SELECT
    advertiser_id,
    creative_portfolio_id,
    portfolio_name,
    portfolio_type,
    created_by_tool,
    created_at
FROM tool_portfolios
ORDER BY created_at DESC
LIMIT 5;
```

**Step 3**: Test "Frequently Used CTAs" feature
- Click "Use Frequently Used CTAs" button
- Should create portfolio with 5 predefined CTAs

**Step 4**: Check frequently used CTA portfolio
```sql
SELECT
    advertiser_id,
    creative_portfolio_id,
    portfolio_name,
    JSON_LENGTH(portfolio_content) as cta_count,
    created_at
FROM tool_portfolios
WHERE portfolio_name = 'Frequently Used CTAs';
```

**Step 5**: Verify portfolios appear in existing list
- Click "Use Existing Portfolio"
- You should see ALL portfolios you created, including:
  - Custom portfolios from "Create New Portfolio"
  - "Frequently Used CTAs" portfolio
  - Portfolios are merged from both database and TikTok API

**Step 6**: Check database logs
```bash
tail -f tiktok_launcher.log | grep -i portfolio
```

You should see:
- `✓ Portfolio saved to database (ID: ...)`
- `CTA Portfolios Found in Database: N`
- `Total CTA Portfolios (merged): N`

## Manual Database Setup (Alternative)

If you prefer manual setup:

```bash
# 1. Create database
mysql -u root -p -e "CREATE DATABASE tiktok_launcher CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import schema
mysql -u root -p tiktok_launcher < database/schema.sql

# 3. Create admin user manually
mysql -u root -p tiktok_launcher
```

```sql
INSERT INTO users (username, password_hash, email, full_name, status)
VALUES (
    'Sunny',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- "Developer"
    'admin@tiktok-launcher.local',
    'Administrator',
    'active'
);
```

## Troubleshooting

### Error: "Access denied for user"
**Solution**: Check DB_USER and DB_PASSWORD in `.env`

### Error: "Unknown database"
**Solution**: Run `database/setup.php` to create database

### Error: "Can't connect to MySQL server"
**Solution**:
1. Check if MySQL is running: `sudo systemctl status mysql`
2. Start MySQL: `sudo systemctl start mysql`
3. Check port 3306 is accessible

### Error: "SQLSTATE[HY000]: General error: 1364"
**Solution**: Some fields may be missing default values. Run the provided schema.sql which includes all defaults.

### Error: "Table already exists"
**Solution**: This is fine if running setup twice. The script uses `CREATE TABLE IF NOT EXISTS`.

## Production Deployment

### For Production Servers

1. **Create production database**:
```bash
mysql -u your_user -p
CREATE DATABASE tiktok_launcher CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. **Update .env with production credentials**:
```env
DB_HOST=your-db-host.com
DB_NAME=tiktok_launcher
DB_USER=production_user
DB_PASSWORD=secure_password_here
```

3. **Import schema**:
```bash
mysql -u production_user -p tiktok_launcher < database/schema.sql
```

4. **Set up database backups**:
```bash
# Daily backup script
mysqldump -u production_user -p tiktok_launcher > backup_$(date +%Y%m%d).sql
```

### Security Recommendations

1. **Use strong database passwords**
2. **Limit database user permissions** (only what's needed)
3. **Enable SSL for database connections**
4. **Regular backups** (especially tiktok_connections table)
5. **Monitor token_expires_at** for automatic refresh

## Next Steps

After database setup:

1. ✅ **Register OAuth Redirect URI** in TikTok Developer Portal
2. ✅ **Test OAuth Flow** with database storage
3. ⏳ **Implement Token Refresh** mechanism (cron job)
4. ⏳ **Implement Campaign Data Sync** from TikTok API
5. ⏳ **Build Dashboard** to display synced data

## Support

If you encounter issues:
1. Check PHP error logs: `tail -f /var/log/php_errors.log`
2. Check MySQL error logs: `tail -f /var/log/mysql/error.log`
3. Enable query logging in Database.php (line 77)
4. Check [database/schema.sql](database/schema.sql) for reference
