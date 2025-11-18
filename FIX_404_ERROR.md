# Fix the 404 Error - Register Redirect URI

## The Problem

You're seeing "404 page not found" from TikTok because the **redirect URI** hasn't been registered in your TikTok Developer Portal yet.

This is the **ONLY** step needed to make OAuth work!

## The Solution (5 Minutes)

### Step 1: Open TikTok Developer Portal

Go to: https://business-api.tiktok.com/portal/developer

### Step 2: Login

Use your TikTok For Business developer account credentials

### Step 3: Find Your App

1. Click on **"My Apps"** in the left sidebar or top navigation
2. You should see your app with App ID: **7535662119501430785**
3. Click on the app name to open it

### Step 4: Find OAuth Settings

Look for one of these sections (the exact name varies):
- **"OAuth Redirect URLs"**
- **"Redirect URIs"**
- **"Authorization Callback URL"**
- **"OAuth Settings"**
- **"App Settings"** → then look for redirect URI field

### Step 5: Add the Redirect URI

Click **"Add"** or **"Edit"** and add this EXACT URL:

```
http://localhost:8080/oauth-callback.php
```

**⚠️ IMPORTANT - Copy and Paste Exactly:**
- Use `http://` NOT `https://` for localhost
- Port must be `8080`
- Path must be `/oauth-callback.php`
- NO trailing slash
- NO extra characters

### Step 6: Save

1. Click **"Save"** or **"Submit"**
2. Wait a few seconds for approval (usually instant)
3. You might see a "Success" or "Saved" message

### Step 7: Test

1. Go back to your Campaign Launcher: http://localhost:8080
2. Login with username: `Sunny` and password: `Developer`
3. Click the **"Connect Your TikTok Ad Account via OAuth"** button
4. You should now be redirected to TikTok's authorization page (NO 404!)
5. Authorize with your TikTok Ads account
6. You'll be redirected back and see all your advertiser accounts!

## Still Getting 404?

### Check These Common Issues:

**1. Wrong URL format**
   - ❌ Bad: `https://localhost:8080/oauth-callback.php` (https instead of http)
   - ❌ Bad: `http://localhost:8080/oauth-callback.php/` (trailing slash)
   - ❌ Bad: `http://localhost/oauth-callback.php` (missing port)
   - ✅ Good: `http://localhost:8080/oauth-callback.php`

**2. Not saved properly**
   - Make sure you clicked "Save" or "Submit"
   - Look for a success message
   - Try refreshing the page to verify the URI is listed

**3. PHP server not running**
   - Check if server is running on port 8080
   - In your terminal: `php -S localhost:8080`

**4. Wrong port**
   - The redirect URI port (8080) must match your PHP server port
   - If your server is running on a different port, use that port in the redirect URI

## After It Works

Once you register the redirect URI and it works, you'll see this flow:

1. **Click OAuth button** → Redirected to TikTok
2. **TikTok authorization page** → Login if needed
3. **Authorize the app** → Allow access
4. **Redirected back** → To select-advertiser-oauth.php
5. **See all accounts** → All your TikTok Ads advertiser accounts
6. **Select account** → Click on one
7. **Dashboard** → Start creating campaigns!

## Visual Confirmation

You'll know it's working when:
- ✅ NO 404 error
- ✅ You see TikTok's authorization page with your app name
- ✅ After authorizing, you're redirected back to your app
- ✅ You see "Select Your Ad Account" page with your accounts listed

## Need Help Finding the Setting?

The redirect URI setting location varies by TikTok's UI updates. Try these paths:

**Path 1:**
My Apps → [Your App] → OAuth Settings → Redirect URIs → Add

**Path 2:**
My Apps → [Your App] → Settings → OAuth → Redirect URLs → Add

**Path 3:**
My Apps → [Your App] → App Settings → Authorization Callback URL

**Path 4:**
My Apps → [Your App] → API → OAuth Configuration → Redirect URI

## Contact TikTok Support

If you can't find where to add the redirect URI:

1. Click "Help" or "Support" in the developer portal
2. Submit a ticket asking: "Where do I add OAuth redirect URIs for my app?"
3. Include your App ID: **7535662119501430785**

## For Production Deployment

When you deploy to a real domain:

1. Add production redirect URI:
   ```
   https://yourdomain.com/oauth-callback.php
   ```

2. Update `.env` file:
   ```env
   OAUTH_REDIRECT_URI=https://yourdomain.com/oauth-callback.php
   ```

3. Make sure to use `https://` (with SSL) for production

---

**That's it!** Once the redirect URI is registered, OAuth will work perfectly! 🎉
