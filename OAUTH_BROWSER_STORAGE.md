# OAuth Browser Storage Implementation

## Overview

The TikTok Campaign Launcher now uses **browser localStorage** to store OAuth tokens instead of a database. This is a simpler, more efficient approach for this use case.

## Why Browser Storage Instead of Database?

### Advantages

1. **No Database Required** - Works immediately without MySQL installation
2. **User-Specific** - Token stays with the user's browser, more secure
3. **Automatic Expiration** - Tokens naturally expire when needed
4. **Simpler Architecture** - Less backend complexity
5. **Privacy** - Tokens never stored on server permanently
6. **Faster** - No database queries for token retrieval

### When This Approach Works

- ✅ Single user or small team usage
- ✅ Users access from same browser/computer
- ✅ Tokens expire in 24 hours (TikTok standard)
- ✅ Quick deployment without infrastructure

### When You Might Need Database

- Multi-tenant SaaS with hundreds of users
- Token refresh automation across all users
- Historical campaign data storage
- Users switching between devices frequently

## How It Works

### 1. OAuth Flow

```
User Login
   ↓
Click "Connect TikTok Ad Account"
   ↓
Redirect to TikTok OAuth (oauth-init.php)
   ↓
User authorizes in TikTok
   ↓
TikTok redirects back (oauth-callback.php)
   ↓
Exchange auth_code for access_token
   ↓
Store in PHP session
   ↓
Redirect to advertiser selection
   ↓
JavaScript stores in localStorage
   ↓
Token available for all API calls
```

### 2. Token Storage Format

**localStorage Key**: `tiktok_oauth_token`

**Value** (JSON):
```json
{
  "access_token": "e285e1288dbb1d9eff2e1924431917edbebfad31",
  "refresh_token": "abc123...",
  "expires_in": 86400,
  "token_type": "Bearer",
  "advertiser_ids": ["7477902831723413521", "7477902831723413522"],
  "selected_advertiser_id": "7477902831723413521",
  "expires_at": 1704123456789
}
```

### 3. Token Retrieval in Frontend

```javascript
// Get token from localStorage
const tokenData = JSON.parse(localStorage.getItem('tiktok_oauth_token') || '{}');

// Check if token exists and is not expired
if (tokenData.access_token && Date.now() < tokenData.expires_at) {
    console.log('Token is valid');
} else {
    console.log('Token expired, need to re-authorize');
}

// Use token in API calls (future enhancement)
fetch('api.php?action=get_campaigns', {
    headers: {
        'Authorization': `Bearer ${tokenData.access_token}`
    }
});
```

### 4. Token Retrieval in Backend (api.php)

**Current**: Session-based
```php
// Priority: Session OAuth token > .env token
$access_token = isset($_SESSION['oauth_access_token']) && !empty($_SESSION['oauth_access_token'])
    ? $_SESSION['oauth_access_token']
    : ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? '');
```

**Future Enhancement**: Accept from Authorization header
```php
// Check for Bearer token in Authorization header
$headers = getallheaders();
if (isset($headers['Authorization'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
        $access_token = $matches[1];
    }
}

// Fallback to session
if (empty($access_token)) {
    $access_token = $_SESSION['oauth_access_token'] ?? $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
}
```

## Implementation Files

### oauth-callback.php
Receives OAuth callback, exchanges code for token, stores in session.

```php
// Exchange auth_code for access_token
$tokenData = exchangeCodeForToken($auth_code);

// Store in session
$_SESSION['oauth_access_token'] = $tokenData['data']['access_token'];
$_SESSION['oauth_refresh_token'] = $tokenData['data']['refresh_token'] ?? '';
$_SESSION['oauth_advertiser_ids'] = $tokenData['data']['advertiser_ids'] ?? [];
$_SESSION['oauth_expires_in'] = $tokenData['data']['expires_in'] ?? 86400;

// Redirect to advertiser selection
header('Location: select-advertiser-oauth.php');
```

### select-advertiser-oauth.php
Displays advertiser accounts and stores token in browser localStorage.

```javascript
// On page load, transfer token from PHP session to localStorage
window.addEventListener('DOMContentLoaded', function() {
    const tokenData = {
        access_token: "<?php echo $_SESSION['oauth_access_token']; ?>",
        refresh_token: "<?php echo $_SESSION['oauth_refresh_token'] ?? ''; ?>",
        expires_in: <?php echo $_SESSION['oauth_expires_in'] ?? 86400; ?>,
        advertiser_ids: <?php echo json_encode($_SESSION['oauth_advertiser_ids'] ?? []); ?>,
        expires_at: Date.now() + (<?php echo $_SESSION['oauth_expires_in'] ?? 86400; ?> * 1000)
    };

    localStorage.setItem('tiktok_oauth_token', JSON.stringify(tokenData));
    console.log('OAuth token stored in browser localStorage');
});

// When user selects advertiser, update localStorage
function continueToDashboard() {
    const tokenData = JSON.parse(localStorage.getItem('tiktok_oauth_token') || '{}');
    tokenData.selected_advertiser_id = selectedAdvertiserId;
    localStorage.setItem('tiktok_oauth_token', JSON.stringify(tokenData));

    // Continue to dashboard
    window.location.href = 'dashboard.php';
}
```

### api.php
Uses session token for API calls.

```php
// Load token from session
$access_token = isset($_SESSION['oauth_access_token']) && !empty($_SESSION['oauth_access_token'])
    ? $_SESSION['oauth_access_token']
    : ($_ENV['TIKTOK_ACCESS_TOKEN'] ?? '');

// Use token with TikTok SDK
$config = [
    'access_token' => $access_token,
    'app_id' => $_ENV['TIKTOK_APP_ID'] ?? '',
    'app_secret' => $_ENV['TIKTOK_APP_SECRET'] ?? ''
];
```

## Security Considerations

### Is localStorage Safe for Tokens?

**Yes, for this use case**:
1. **Same-origin policy** - Only your domain can access the token
2. **HTTPS** - Use SSL in production to prevent interception
3. **XSS Protection** - Don't inject user input into JavaScript
4. **Short-lived** - Tokens expire in 24 hours
5. **User-specific** - Each user has their own token in their browser

### Best Practices

1. **Always use HTTPS** in production
2. **Validate all user input** to prevent XSS
3. **Check token expiration** before API calls
4. **Clear localStorage on logout**:
   ```javascript
   localStorage.removeItem('tiktok_oauth_token');
   ```
5. **Use httpOnly cookies** for sensitive session data

### XSS Prevention

```javascript
// BAD - Vulnerable to XSS
document.getElementById('output').innerHTML = userInput;

// GOOD - Safe
document.getElementById('output').textContent = userInput;
```

## Token Expiration Handling

### Check Expiration in Frontend

```javascript
function isTokenValid() {
    const tokenData = JSON.parse(localStorage.getItem('tiktok_oauth_token') || '{}');

    if (!tokenData.access_token) {
        return false;
    }

    if (tokenData.expires_at && Date.now() >= tokenData.expires_at) {
        console.log('Token expired');
        return false;
    }

    return true;
}

// Before making API call
if (!isTokenValid()) {
    alert('Your TikTok connection has expired. Please reconnect.');
    window.location.href = 'oauth-init.php';
}
```

### Automatic Refresh (Future Enhancement)

```javascript
// Check if token expires within 1 hour
function shouldRefreshToken() {
    const tokenData = JSON.parse(localStorage.getItem('tiktok_oauth_token') || '{}');
    const oneHour = 60 * 60 * 1000;

    return tokenData.expires_at && (tokenData.expires_at - Date.now()) < oneHour;
}

if (shouldRefreshToken()) {
    // Call refresh endpoint
    fetch('api.php?action=refresh_token', {
        method: 'POST',
        body: JSON.stringify({
            refresh_token: tokenData.refresh_token
        })
    });
}
```

## Logout Flow

Clear all OAuth data when user logs out:

```javascript
// logout.php or logout button
function logout() {
    // Clear localStorage
    localStorage.removeItem('tiktok_oauth_token');

    // Clear session (backend)
    window.location.href = 'logout.php';
}
```

**logout.php**:
```php
<?php
session_start();
session_destroy();
header('Location: index.php');
exit;
```

## Testing the Implementation

### 1. Check if Token is Stored

After OAuth flow, open browser console:
```javascript
console.log(localStorage.getItem('tiktok_oauth_token'));
```

Should show:
```json
{
  "access_token": "e285e1288dbb...",
  "advertiser_ids": ["7477902831723413521"],
  "expires_at": 1704123456789
}
```

### 2. Test API Calls

```javascript
// In browser console
const tokenData = JSON.parse(localStorage.getItem('tiktok_oauth_token'));
console.log('Access Token:', tokenData.access_token);
console.log('Selected Advertiser:', tokenData.selected_advertiser_id);
console.log('Expires at:', new Date(tokenData.expires_at));
```

### 3. Test Expiration

```javascript
// Manually expire token
const tokenData = JSON.parse(localStorage.getItem('tiktok_oauth_token'));
tokenData.expires_at = Date.now() - 1000; // Set to past
localStorage.setItem('tiktok_oauth_token', JSON.stringify(tokenData));

// Token should now be invalid
```

## Migration Path to Database (If Needed Later)

If you later need database storage for multi-user support:

1. Keep localStorage for frontend UX
2. Add backend sync to database
3. Backend checks database first, then session
4. Token refresh updates both database and localStorage

This hybrid approach gives you best of both worlds:
- Fast frontend access (localStorage)
- Persistent backend storage (database)
- Multi-user support (database)
- Cross-device sync (database)

## Comparison: Browser vs Database Storage

| Feature | Browser localStorage | Database Storage |
|---------|---------------------|------------------|
| Setup Complexity | ⭐ Simple | ⭐⭐⭐ Complex |
| Infrastructure | None | MySQL/PostgreSQL |
| Multi-user | Single browser | Unlimited users |
| Cross-device | ❌ No | ✅ Yes |
| Token Refresh | Manual | Automatic |
| Data Persistence | Browser only | Server-side |
| Speed | ⚡ Instant | 🐌 Query latency |
| Scalability | Limited | High |
| Cost | Free | Server costs |
| Best for | Solo/Small team | SaaS/Enterprise |

## Conclusion

For the TikTok Campaign Launcher, **browser localStorage is the optimal choice** because:

1. ✅ No database setup required
2. ✅ Works immediately
3. ✅ Secure for single-user/small team
4. ✅ Fast and simple
5. ✅ Easy to understand and maintain

If you later need multi-user support or cross-device sync, you can migrate to database storage while keeping localStorage for performance.
