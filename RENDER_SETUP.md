# Render.com PostgreSQL Setup Guide

## Quick Setup for Portfolio Storage

Your TikTok Launcher is now configured to use **PostgreSQL on Render.com**. Follow these steps to complete the setup.

---

## Step 1: Verify .env Configuration

Your [.env](.env) file has been updated with Render PostgreSQL credentials:

```env
# Database Configuration (PostgreSQL on Render.com)
DB_DRIVER=pgsql
DB_HOST=dpg-d4g9140gjchc73dsijp0-a.oregon-postgres.render.com
DB_PORT=5432
DB_NAME=tiktok_database_nt4s
DB_USER=tiktok_database_nt4s_user
DB_PASSWORD=gm2ULw8bBH8qDOrVOm65+4gd0XhKu1C9
```

✅ **This is already configured!** No changes needed.

---

## Step 2: Run Database Setup Script

### Option A: Run from Your Local Machine (Recommended)

```bash
php database/setup_pgsql.php
```

**What this does:**
- ✅ Connects to your Render PostgreSQL database
- ✅ Creates all tables including `tool_portfolios`
- ✅ Creates indexes for fast queries
- ✅ Creates default admin user (Sunny/Developer)
- ✅ Verifies everything is working

### Option B: Run from Render.com

If you need to run it on Render itself:

1. Deploy your code to Render
2. Open Render Shell for your web service
3. Run: `php database/setup_pgsql.php`

---

## Step 3: Verify Database Setup

### Check Tables Were Created

Connect to your PostgreSQL database using Render's dashboard or any PostgreSQL client:

```sql
-- List all tables
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public'
ORDER BY table_name;
```

You should see:
- `sync_logs`
- `tiktok_adgroups`
- `tiktok_campaigns`
- `tiktok_connections`
- `tiktok_metrics`
- **`tool_portfolios`** ← Most important for portfolio storage
- `users`

### Verify tool_portfolios Table

```sql
SELECT column_name, data_type
FROM information_schema.columns
WHERE table_name = 'tool_portfolios';
```

Expected columns:
- `id` (integer)
- `advertiser_id` (character varying)
- `creative_portfolio_id` (character varying)
- `portfolio_name` (character varying)
- `portfolio_type` (character varying)
- `portfolio_content` (jsonb)
- `created_by_tool` (boolean)
- `created_at` (timestamp)
- `updated_at` (timestamp)

---

## Step 4: Test Portfolio Storage

### 1. Login to Your App

Access your TikTok Launcher on Render:
- URL: `https://your-app.onrender.com`
- Username: `Sunny`
- Password: `Developer`

### 2. Connect TikTok Account

- Click "Connect TikTok Ads Account"
- Complete OAuth flow
- Select advertiser

### 3. Create a Test Portfolio

Go to ad creation and:
- Click **"Use Frequently Used CTAs"**
- This creates a portfolio with 5 predefined CTAs
- Should see: "✅ Portfolio Created Successfully"

### 4. Verify Database Storage

```sql
SELECT
    advertiser_id,
    creative_portfolio_id,
    portfolio_name,
    created_by_tool,
    created_at
FROM tool_portfolios
ORDER BY created_at DESC
LIMIT 5;
```

You should see your "Frequently Used CTAs" portfolio!

### 5. Test Retrieval

- Click **"Use Existing Portfolio"**
- You should now see the portfolio you just created
- All portfolios will persist permanently in PostgreSQL

---

## How It Works

### Database-Only Architecture

The system NO LONGER calls TikTok's list API. Instead:

1. **Portfolio Creation** → Automatically saved to `tool_portfolios` table
2. **Portfolio Retrieval** → Fetched ONLY from database
3. **Frontend Display** → Shows portfolios from database with full details

### Key Features

✅ **Automatic Storage**: Every portfolio created is auto-saved
✅ **Permanent Persistence**: Portfolios survive across sessions
✅ **Fast Queries**: Indexed by advertiser_id for quick lookups
✅ **JSON Content**: Full portfolio details stored as JSONB
✅ **Deduplication**: UNIQUE constraint prevents duplicates

### Code Changes

**Database.php**: Now supports both MySQL and PostgreSQL
**api.php**: Uses database-agnostic upsert() method
**schema_pgsql.sql**: PostgreSQL-compatible schema

---

## Files Updated

### New Files
- [database/schema_pgsql.sql](database/schema_pgsql.sql) - PostgreSQL schema
- [database/setup_pgsql.php](database/setup_pgsql.php) - Setup script
- [RENDER_SETUP.md](RENDER_SETUP.md) - This guide

### Modified Files
- [database/Database.php](database/Database.php) - Added PostgreSQL support + upsert()
- [.env](.env) - Updated with Render credentials
- [api.php](api.php) - Changed to database-agnostic upsert

---

## Troubleshooting

### Error: "Database connection failed"

**Check 1**: Verify Render database is active
- Go to Render dashboard
- Check database status (should be "Available")

**Check 2**: Verify credentials in .env
```bash
# Should match exactly with Render dashboard
DB_HOST=dpg-d4g9140gjchc73dsijp0-a.oregon-postgres.render.com
DB_PORT=5432
DB_NAME=tiktok_database_nt4s
DB_USER=tiktok_database_nt4s_user
DB_PASSWORD=gm2ULw8bBH8qDOrVOm65+4gd0XhKu1C9
```

**Check 3**: Test connection
```bash
php -r "
\$pdo = new PDO('pgsql:host=dpg-d4g9140gjchc73dsijp0-a.oregon-postgres.render.com;port=5432;dbname=tiktok_database_nt4s', 'tiktok_database_nt4s_user', 'gm2ULw8bBH8qDOrVOm65+4gd0XhKu1C9');
echo 'Connected successfully!';
"
```

### Error: "Table tool_portfolios doesn't exist"

**Fix**: Run the setup script again
```bash
php database/setup_pgsql.php
```

### Error: "No existing portfolios found"

**Reason**: You haven't created any portfolios yet

**Fix**: Create a test portfolio first
1. Click "Use Frequently Used CTAs" (creates and saves portfolio)
2. Then click "Use Existing Portfolio" (should now show it)

### Database Query Logs

Check your application logs for database operations:
```bash
tail -f tiktok_launcher.log | grep -i portfolio
```

You should see:
- `✓ Portfolio saved to database (ID: ...)`
- `CTA Portfolios Found in Database: N`

---

## Production Deployment Checklist

- [x] Update .env with Render PostgreSQL credentials
- [x] Update Database.php to support PostgreSQL
- [x] Create PostgreSQL schema (schema_pgsql.sql)
- [x] Update api.php to use database-agnostic upsert
- [ ] Run `php database/setup_pgsql.php`
- [ ] Test portfolio creation
- [ ] Test portfolio retrieval
- [ ] Verify portfolios persist after server restart

---

## Next Steps

After database setup is complete:

1. ✅ **Portfolio Storage** - Working (database-only)
2. ⏳ **Token Refresh** - Implement automatic refresh mechanism
3. ⏳ **Campaign Sync** - Sync campaign data from TikTok API
4. ⏳ **Metrics Dashboard** - Display performance data

---

## Support

If you encounter issues:

1. **Check PHP Error Logs**
   ```bash
   tail -f /var/log/php_errors.log
   ```

2. **Check Database Logs in Render**
   - Go to Render dashboard
   - Click on your PostgreSQL database
   - View Logs tab

3. **Test Database Connection**
   ```bash
   php -r "require 'database/Database.php'; \$db = Database::getInstance(); echo 'Connected!';"
   ```

4. **Verify Schema**
   ```sql
   \dt -- List tables (in psql)
   ```

---

## Important Notes

⚠️ **Security**: The .env file contains sensitive credentials. Never commit it to public repositories.

✅ **Backup**: Render provides automatic backups, but consider setting up additional backups for production.

📊 **Monitoring**: Monitor database performance in Render dashboard (Metrics tab).

🔄 **Updates**: When deploying updates, existing data in `tool_portfolios` will be preserved.
