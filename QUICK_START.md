# Quick Start: Portfolio Storage Setup

## Problem
When clicking "Use Existing Portfolio", you see:
> "📋 No existing CTA portfolios found."

This happens because the database table for storing portfolios hasn't been created yet.

## Solution
Run the database setup script to create the `tool_portfolios` table.

## Steps

### Option 1: Automatic Setup (Recommended)

Run this command from your project root:

```bash
php database/setup.php
```

**What this does:**
- Creates the `tiktok_launcher` database if it doesn't exist
- Creates all tables including the new `tool_portfolios` table
- Sets up indexes for fast queries
- Creates default admin user

### Option 2: Manual Table Creation

If you prefer to create just the portfolio table manually:

```bash
mysql -u root -p
```

Then run:

```sql
USE tiktok_launcher;

CREATE TABLE IF NOT EXISTS tool_portfolios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    advertiser_id VARCHAR(255) NOT NULL COMMENT 'TikTok Advertiser ID',
    creative_portfolio_id VARCHAR(255) NOT NULL COMMENT 'Portfolio ID from TikTok API',
    portfolio_name VARCHAR(500) COMMENT 'Name of the portfolio',
    portfolio_type VARCHAR(50) DEFAULT 'CTA' COMMENT 'Portfolio type (CTA, etc)',
    portfolio_content JSON COMMENT 'Full portfolio content data',
    created_by_tool BOOLEAN DEFAULT TRUE COMMENT 'Whether this was created by the launcher',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_portfolio (advertiser_id, creative_portfolio_id),
    INDEX idx_advertiser_id (advertiser_id),
    INDEX idx_portfolio_id (creative_portfolio_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores all portfolios created through TikTok Launcher for easy retrieval';
```

## Verify Setup

Check if the table was created:

```sql
SHOW TABLES LIKE 'tool_portfolios';
```

You should see:
```
+--------------------------------------+
| Tables_in_tiktok_launcher (tool_portfolios) |
+--------------------------------------+
| tool_portfolios                      |
+--------------------------------------+
```

## Test the Feature

1. **Create a portfolio**:
   - Go to ad creation
   - Select "Dynamic CTA"
   - Click "Create New Portfolio" or "Use Frequently Used CTAs"
   - Create the portfolio

2. **Verify storage**:
   ```sql
   SELECT * FROM tool_portfolios ORDER BY created_at DESC LIMIT 5;
   ```

3. **Check in UI**:
   - Click "Use Existing Portfolio"
   - You should now see your created portfolios!

## Troubleshooting

### "No existing CTA portfolios found" still shows

**Check 1**: Verify the table exists
```sql
SHOW TABLES LIKE 'tool_portfolios';
```

**Check 2**: Check if any portfolios are stored
```sql
SELECT COUNT(*) FROM tool_portfolios;
```

**Check 3**: Create a test portfolio first
- Click "Use Frequently Used CTAs" button
- This will create and save a portfolio
- Then try "Use Existing Portfolio" again

### Database connection error

**Check**: Verify `.env` database settings:
```env
DB_HOST=localhost
DB_NAME=tiktok_launcher
DB_USER=root
DB_PASSWORD=
```

**Fix**: Update credentials and ensure MySQL is running:
```bash
# Check if MySQL is running
systemctl status mysql
# or on Mac
brew services list
```

### Table creation failed

**Error**: `ERROR 1007 (HY000): Can't create database 'tiktok_launcher'; database exists`

**Fix**: Database already exists, just create the table:
```sql
USE tiktok_launcher;
-- Then run the CREATE TABLE statement above
```

## How It Works

Once set up, the system will:

1. **Automatically save** every portfolio you create
2. **Store** in database with advertiser_id + portfolio_id
3. **Merge** database portfolios with TikTok API results
4. **Display** all portfolios in "Use Existing Portfolio" list

You'll see portfolios with these indicators:
- 📋 Regular portfolios from TikTok API
- ⚡ "Frequently Used CTAs" (created by tool)
- ✨ Custom portfolios (created via "Create New Portfolio")

All portfolios created through the tool will now persist permanently!
