# Render Environment Variables Setup

## Important: Setting Environment Variables on Render

On Render, you **must** set environment variables through the Render dashboard. The `.env` file is only for local development and is not deployed to production.

## Step-by-Step Guide

### 1. Go to Your Web Service on Render

1. Login to [Render Dashboard](https://dashboard.render.com)
2. Click on your **tiktok-launcher** web service
3. Click on **"Environment"** in the left sidebar

### 2. Add Environment Variables

Click **"Add Environment Variable"** and add these:

#### Database Configuration

**Option 1: Use DB_URL (Recommended)**

| Key | Value |
|-----|-------|
| `DB_DRIVER` | `pgsql` |
| `DB_URL` | `postgresql://tiktok_database_nt4s_user:gm2ULwSbBH8gDOrVOm65r4gd0XhKulC9@dpg-d4g9140gjchc73dsijp0-a.oregon-postgres.render.com/tiktok_database_nt4s` |

**Option 2: Use Individual Variables**

| Key | Value |
|-----|-------|
| `DB_DRIVER` | `pgsql` |
| `DB_HOST` | `dpg-d4g9140gjchc73dsijp0-a.oregon-postgres.render.com` |
| `DB_PORT` | `5432` |
| `DB_NAME` | `tiktok_database_nt4s` |
| `DB_USER` | `tiktok_database_nt4s_user` |
| `DB_PASSWORD` | `gm2ULwSbBH8gDOrVOm65r4gd0XhKulC9` |

#### TikTok API Configuration

| Key | Value |
|-----|-------|
| `TIKTOK_ACCESS_TOKEN` | `e285e1288dbb1d9eff2e1924431917edbebfad31` |
| `TIKTOK_ADVERTISER_ID` | `7477902831723413521` |
| `TIKTOK_APP_ID` | `7535662119501430785` |
| `TIKTOK_APP_SECRET` | `b3d6d9df8f71e534d25d52a4fa08e995a53a7a8a` |
| `OAUTH_REDIRECT_URI` | `https://tiktok-launcher.onrender.com/oauth-callback.php` |

#### Authentication

| Key | Value |
|-----|-------|
| `AUTH_USERNAME` | `Sunny` |
| `AUTH_PASSWORD` | `Developer` |

### 3. Save and Deploy

1. Click **"Save Changes"** at the bottom
2. Render will automatically redeploy your application
3. Wait for deployment to complete (watch the logs)

### 4. Verify Connection

Once deployed, test the connection:

1. Go to your app URL: `https://your-app.onrender.com`
2. Login with `Sunny` / `Developer`
3. Try creating a portfolio with "Use Frequently Used CTAs"
4. If it works, the database is connected! ✅

## Troubleshooting

### Error: "Database connection failed"

**Check 1**: Verify environment variables are set
- Go to Render Dashboard → Your Service → Environment
- Make sure `DB_URL` or all individual `DB_*` variables are present

**Check 2**: Check the External Database URL
- Go to Render Dashboard → Your PostgreSQL Database
- Copy the **External Database URL** from the "Connect" section
- Make sure it matches what you put in `DB_URL`

**Check 3**: Check deployment logs
- Go to Render Dashboard → Your Service → Logs
- Look for database connection errors

### Error: "SSL/TLS required"

This means `DB_DRIVER` is not set to `pgsql`. Make sure:
- `DB_DRIVER` = `pgsql` (not `mysql`)

### Database Tables Not Found

Run the setup script on Render:

1. Go to Render Dashboard → Your Service → Shell
2. Run: `php database/setup_pgsql.php`

## Quick Reference: Get Database URL from Render

1. Go to [Render Dashboard](https://dashboard.render.com)
2. Click on your **PostgreSQL database** (tiktok-database)
3. Click **"Connect"** button
4. Copy the **External Database URL**
5. Paste it as the value for `DB_URL` environment variable

Example External URL:
```
postgresql://tiktok_database_nt4s_user:PASSWORD@dpg-xxxxx.oregon-postgres.render.com/tiktok_database_nt4s
```

## How It Works

- **Local Development**: Reads from `.env` file
- **Production (Render)**: Reads from system environment variables
- **Code**: Automatically detects which to use via `getenv()` fallback to `$_ENV`

## Next Steps After Setup

1. ✅ Environment variables configured
2. ✅ Application redeployed
3. ✅ Test login
4. ✅ Test portfolio creation
5. ✅ Verify portfolios persist in database

All done! Your TikTok Launcher should now be fully operational on Render with PostgreSQL! 🚀
