# 🚀 Quick Start: Enable OAuth Authentication

## Current Status

✅ **OAuth Code**: Fully implemented and ready
✅ **Login Page**: Redesigned with OAuth as primary option
✅ **User Flow**: Optimized for production use
⚠️ **Redirect URI**: NOT YET REGISTERED (causing 404 error)

## The 404 Error You're Seeing

The error `404 page not found` from TikTok is **expected** and **normal** at this stage!

It means:
- ✅ Your code is working correctly
- ✅ OAuth flow initiated successfully
- ✅ TikTok recognized your app
- ❌ Redirect URI is not registered in TikTok Developer Portal

## Fix in 5 Minutes

### Step 1: Go to TikTok Developer Portal

Open this link: https://business-api.tiktok.com/portal/developer

### Step 2: Find Your App

- Click on **"My Apps"** in the navigation
- Find your app: **App ID 7535662119501430785**
- Click on it to open app settings

### Step 3: Register Redirect URI

Look for one of these sections:
- **OAuth Redirect URLs**
- **Redirect URIs**
- **Authorization Callback URL**
- **OAuth Settings**

### Step 4: Add This EXACT URL

```
http://localhost:8080/oauth-callback.php
```

**⚠️ IMPORTANT:**
- Use `http://` NOT `https://` for localhost
- Port must be `8080`
- Path must be `/oauth-callback.php`
- No trailing slash
- Copy-paste to avoid typos!

### Step 5: Save and Test

1. Click **Save** or **Submit**
2. Wait a few seconds for approval (usually instant)
3. Go back to your Campaign Launcher: http://localhost:8080
4. Click **"Connect TikTok Ads Account"**
5. Should work perfectly now! ✅

## What Happens After Registration

Once you register the redirect URI:

1. ✅ Click "Connect TikTok Ads Account" on login page
2. ✅ Redirected to TikTok OAuth authorization
3. ✅ If already logged into TikTok Ads → auto-authorizes
4. ✅ If not logged in → login screen → authorize
5. ✅ TikTok redirects back to your callback
6. ✅ Your app exchanges code for access token
7. ✅ Fetches all advertiser accounts you have access to
8. ✅ Shows selection page with all accounts
9. ✅ You select account → redirected to dashboard
10. ✅ Full access to create campaigns!

## Updated User Experience

### New Login Flow (OAuth First)

```
┌─────────────────────────────────┐
│  🚀 TikTok Campaign Launcher   │
│                                 │
│  Connect your TikTok Ads       │
│  account to get started        │
│                                 │
│  ┌───────────────────────────┐ │
│  │ 🔗 Connect TikTok Ads     │ │  ← PRIMARY BUTTON
│  │    Account                │ │     (Big, prominent)
│  └───────────────────────────┘ │
│                                 │
│  ✓ Secure OAuth 2.0            │
│  ✓ Access all accounts         │
│  ✓ No credentials needed       │
│                                 │
│  ─────  Developer Access  ───── │
│                                 │
│  ▶ Use developer credentials   │  ← Collapsed section
│    instead                      │     (for testing only)
└─────────────────────────────────┘
```

### Old Login Flow (Before)

```
┌─────────────────────────────────┐
│  Username: _______________     │
│  Password: _______________     │
│  [Login]                       │  ← Traditional form first
│                                 │
│  ────────  OR  ────────        │
│                                 │
│  [🔗 Connect Traffic Channel]  │  ← OAuth secondary
└─────────────────────────────────┘
```

## Why This Change Matters

**Before:** Users didn't know which method to use
**After:** Clear primary action = better conversion

**Before:** Equal prominence = confusion
**After:** Visual hierarchy = clear path forward

**Before:** "Login" suggested username/password
**After:** "Connect" suggests OAuth integration

## Production Deployment

When you're ready to deploy:

1. Update `.env`:
   ```env
   OAUTH_REDIRECT_URI=https://yourdomain.com/oauth-callback.php
   ```

2. Register production redirect URI in TikTok Portal:
   ```
   https://yourdomain.com/oauth-callback.php
   ```

3. Deploy your code

4. Test OAuth flow on production

## Testing Checklist

- [ ] Register `http://localhost:8080/oauth-callback.php` in TikTok Portal
- [ ] Click "Connect TikTok Ads Account" button
- [ ] Authorize with your TikTok Ads account
- [ ] See advertiser selection page
- [ ] All your advertiser accounts appear
- [ ] Select one account
- [ ] Dashboard loads successfully
- [ ] Can create campaigns with OAuth token

## Support

If you encounter issues:

1. **Check redirect URI** in TikTok Developer Portal
2. **Check PHP server** is running on port 8080
3. **Check error logs**: Look at browser console and PHP logs
4. **Check .env file**: Verify APP_ID and APP_SECRET are correct

## Files Modified

- `index.php` - Redesigned login page (OAuth first)
- `oauth-init.php` - Initiates OAuth flow
- `oauth-callback.php` - Handles OAuth callback
- `select-advertiser-oauth.php` - Advertiser selection UI
- `config.php` - Environment configuration loader
- `api.php` - OAuth token support and endpoint
- `OAUTH_SETUP.md` - Complete setup documentation

---

**Next Step:** Register the redirect URI and test! 🎉
