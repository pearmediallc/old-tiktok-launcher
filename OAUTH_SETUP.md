# OAuth Authentication Setup Guide

## Production-Ready TikTok OAuth Integration

This guide explains how to configure and use the OAuth authentication system to make your TikTok Campaign Launcher production-ready.

## 🎯 What This Enables

- **Any user** can connect their own TikTok Ads account
- No more hardcoded advertiser IDs
- Automatic access to all advertiser accounts the user manages
- Secure OAuth 2.0 authentication flow
- Production-ready multi-tenant architecture

## 📋 Prerequisites

1. TikTok For Business Developer Account
2. TikTok Marketing API App created
3. App ID and App Secret (already configured in your `.env`)

## 🔧 Configuration Steps

### ⚠️ IMPORTANT: Step 1 - Register Redirect URI in TikTok Developer Portal

**You MUST complete this step before OAuth will work!**

The 404 error you're seeing means the redirect URI hasn't been registered yet with TikTok.

1. Go to [TikTok For Business Developer Portal](https://business-api.tiktok.com/portal/developer)
2. Log in with your developer account
3. Navigate to **My Apps** → Select your app (App ID: 7535662119501430785)
4. Look for **OAuth Redirect URLs** or **Redirect URIs** section
5. Click **Add** or **Edit**
6. Add EXACTLY this URL (copy and paste to avoid typos):

   **For Local Development (ADD THIS NOW):**
   ```
   http://localhost:8080/oauth-callback.php
   ```

   **For Production (Add when deploying):**
   ```
   https://yourdomain.com/oauth-callback.php
   ```

7. Click **Save** or **Submit**
8. Wait for approval (usually instant)
9. Once approved, the OAuth flow will work!

**Common Issues:**
- Make sure the URL is EXACTLY `http://localhost:8080/oauth-callback.php` (no trailing slash, no extra characters)
- Don't use `https://` for localhost - use `http://`
- Port must be 8080 (or whatever port your PHP server is running on)

### Step 2: Update .env for Production

When deploying to production, update the `.env` file:

```env
# Change this from localhost to your production domain
OAUTH_REDIRECT_URI=https://yourdomain.com/oauth-callback.php
```

### Step 3: Request Proper Scopes

Make sure your TikTok app has the following scopes approved:
- `ad_management` - Required for creating and managing ads
- `user_info.basic` - Optional for user information

## 🚀 How It Works

### User Flow

1. User visits your Campaign Launcher login page
2. User clicks **"Connect Traffic Channel"** button (pink TikTok button)
3. Browser redirects to TikTok OAuth authorization page
4. If user is already logged into TikTok Ads Manager → auto-authorizes
5. If not logged in → user logs in and authorizes
6. TikTok redirects back to your app with authorization code
7. Your app exchanges code for access token
8. Your app fetches all advertiser accounts the user has access to
9. User selects which advertiser account to use
10. Dashboard loads with user's selected account

### Technical Flow

```
[Login Page]
    ↓ Click "Connect Traffic Channel"
[oauth-init.php]
    ↓ Generate CSRF state, redirect to TikTok
[TikTok OAuth Page]
    ↓ User authorizes
[oauth-callback.php]
    ↓ Validate state, exchange code for token
[select-advertiser-oauth.php]
    ↓ Display advertiser accounts, user selects
[api.php?action=set_oauth_advertiser]
    ↓ Validate & set session
[Dashboard]
    ↓ User starts creating campaigns
```

## 🔒 Security Features

- **CSRF Protection**: State parameter prevents cross-site request forgery
- **Session Validation**: OAuth tokens stored securely in PHP sessions
- **Advertiser Validation**: Only advertiser IDs returned by TikTok are accepted
- **Token Prioritization**: OAuth token takes precedence over static .env token

## 🧪 Testing the OAuth Flow

### Local Testing

1. Make sure PHP server is running:
   ```bash
   php -S localhost:8080
   ```

2. Open browser and navigate to:
   ```
   http://localhost:8080/index.php
   ```

3. Click the **"Connect Traffic Channel"** button

4. You should be redirected to TikTok OAuth page

5. Authorize the app (or it will auto-authorize if already logged in)

6. You should see the advertiser selection page with all your accounts

7. Select an account and click "Continue to Dashboard"

8. Dashboard should load with your selected advertiser account

### Common Issues

**Issue**: "Invalid redirect URI"
- **Solution**: Make sure you registered the exact redirect URI in TikTok Developer Portal
- **Check**: URI must match exactly including http/https, domain, and path

**Issue**: "Invalid state parameter"
- **Solution**: This is CSRF protection. Clear your browser cookies/session and try again
- **Cause**: State mismatch usually happens if you reload the callback page

**Issue**: "No advertiser accounts found"
- **Solution**: Make sure your TikTok account has access to at least one TikTok Ads account
- **Check**: Log into TikTok Ads Manager and verify you can see advertiser accounts

**Issue**: Token exchange fails
- **Solution**: Check that TIKTOK_APP_SECRET in .env matches your app's secret in TikTok portal
- **Check**: View error logs for detailed error messages

## 📁 Files Involved

- **oauth-init.php**: Initiates OAuth flow, generates CSRF state
- **oauth-callback.php**: Handles callback, exchanges code for token
- **select-advertiser-oauth.php**: Displays advertiser selection UI
- **config.php**: Loads environment variables for OAuth files
- **api.php**: Updated to prioritize OAuth token, includes `set_oauth_advertiser` endpoint
- **index.php**: Updated with "Connect Traffic Channel" button
- **.env**: Contains OAuth configuration (APP_ID, APP_SECRET, REDIRECT_URI)

## 🔄 Migration from .env to OAuth

The system maintains **backwards compatibility**:

- OAuth token takes priority if available
- Falls back to .env TIKTOK_ACCESS_TOKEN if no OAuth
- Can use both methods simultaneously for testing

### Development Mode
Use `.env` credentials for quick testing

### Production Mode
Use OAuth for real users connecting their own accounts

## 📊 Monitoring & Logs

All OAuth operations are logged using `error_log()`:

```bash
# View OAuth logs
tail -f /var/log/php_errors.log

# Or check PHP-FPM logs depending on your setup
```

Look for log entries like:
- `OAuth Init: Redirecting to TikTok OAuth`
- `Token Exchange Response Code: 200`
- `OAuth Success: Token obtained`
- `Advertiser IDs: [...]`
- `OAuth: Set advertiser ID to ...`

## 🌐 Production Deployment Checklist

- [ ] Update OAUTH_REDIRECT_URI in .env to production domain
- [ ] Register production redirect URI in TikTok Developer Portal
- [ ] Test OAuth flow on production environment
- [ ] Verify SSL certificate is valid (HTTPS required for production)
- [ ] Set up proper error logging and monitoring
- [ ] Review and adjust session cookie settings for security
- [ ] Consider implementing refresh token logic for long-lived sessions
- [ ] Set up rate limiting if handling multiple users

## 🎓 Additional Resources

- [TikTok Marketing API Documentation](https://business-api.tiktok.com/portal/docs)
- [TikTok OAuth 2.0 Guide](https://business-api.tiktok.com/portal/docs?id=1738373164380162)
- [TikTok Developer Portal](https://business-api.tiktok.com/portal/developer)

## ✅ Verification

Your OAuth system is working correctly if:

1. ✅ Clicking "Connect Traffic Channel" redirects to TikTok
2. ✅ After authorization, you see advertiser selection page
3. ✅ All your advertiser accounts are displayed
4. ✅ Selecting an account redirects to dashboard
5. ✅ Campaign creation works with OAuth token
6. ✅ User can create campaigns for their own advertiser accounts

---

**Need Help?**

Check the error logs first, then review the TikTok API documentation. Most OAuth issues are related to redirect URI configuration or app permissions in the TikTok Developer Portal.
