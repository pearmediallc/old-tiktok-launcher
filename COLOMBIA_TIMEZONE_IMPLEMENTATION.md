# Colombia Timezone Implementation Guide

## Overview
This document explains how the TikTok Campaign Launcher handles Colombia timezone (UTC-05:00) for ad scheduling.

## How It Works

### 1. User Input
- Users enter dates and times in **Colombia Time (UTC-05:00)** directly
- Form labels clearly indicate: "Start Date & Time - Colombia Time (UTC-05:00)"
- No timezone conversion required by the user

### 2. Frontend Conversion
The JavaScript function `convertColombiaToUTC()` in [assets/app.js:197-232](assets/app.js#L197-L232) handles the conversion:

```javascript
// Example:
// User enters: 2025-11-15 09:00 (Colombia Time)
// Converts to:  2025-11-15 14:00:00 (UTC)
// TikTok API receives the UTC time
```

**Conversion Logic:**
- Parse the Colombia time components (year, month, day, hour, minute)
- Add 5 hours to convert from UTC-5 to UTC
- Format as `YYYY-MM-DD HH:MM:SS` for TikTok API

### 3. API Format
The TikTok API expects:
- Field: `schedule_start_time` and `schedule_end_time`
- Format: `YYYY-MM-DD HH:MM:SS` (UTC+0)
- Example: `"2025-11-15 14:00:00"`

**Note:** The text.txt file mentions using Unix timestamps with `start_time`/`end_time` fields, but the official TikTok API documentation uses datetime strings with `schedule_start_time`/`schedule_end_time` fields.

### 4. Advertiser Timezone Verification
A new endpoint verifies your TikTok advertiser account timezone:

**Endpoint:** `GET /api.php?action=get_advertiser_info`

**Purpose:**
- Confirms your TikTok advertiser account is set to Colombia timezone
- Displays timezone status in dashboard header
- Warns if timezone is not Colombia (UTC-5)

**Why This Matters:**
According to TikTok's API behavior, the platform interprets UTC times according to the advertiser's account timezone. If your advertiser account is set to Colombia timezone, your ads will run exactly when intended.

## Visual Indicators

### Dashboard Header
The header now displays your advertiser timezone status:

✅ **Green indicator:** Advertiser timezone is set to Colombia (UTC-5) - All good!
⚠️ **Yellow indicator:** Advertiser timezone is different - Times may not align correctly

## Files Modified

1. **[api.php:2644-2708](api.php#L2644-L2708)**
   - Added `get_advertiser_info` endpoint
   - Fetches advertiser timezone from TikTok API
   - Validates Colombia timezone

2. **[assets/app.js:71-100](assets/app.js#L71-L100)**
   - Added `loadAdvertiserInfo()` function
   - Displays timezone status in header
   - Auto-loads on page load

3. **[assets/app.js:197-232](assets/app.js#L197-L232)**
   - Enhanced `convertColombiaToUTC()` with better logging
   - Clear console output showing conversion process

4. **[dashboard.php:27-35](dashboard.php#L27-L35)**
   - Added timezone status display in header
   - Shows advertiser timezone info

5. **[assets/style.css:38-49](assets/style.css#L38-L49)**
   - Styled header info section
   - Timezone info badge styling

## Testing Your Implementation

### 1. Check Advertiser Timezone
Open the dashboard and look at the header. You should see one of:
- ✅ Colombia Time (UTC-5) - Perfect!
- ⚠️ Different timezone - Contact TikTok support to change your advertiser timezone

### 2. Test Time Conversion
Open browser console and create a test ad group:
1. Enter a Colombia time (e.g., 2025-11-15 09:00)
2. Check console logs for conversion details:
   ```
   🇨🇴 Converting Colombia time to UTC: 2025-11-15T09:00
   ✅ Conversion complete:
       📍 Colombia Time (UTC-5): 2025-11-15 09:00
       🌐 UTC Time (for API): 2025-11-15 14:00:00
   ```

### 3. Verify in TikTok Ads Manager
After creating a campaign:
1. Go to TikTok Ads Manager
2. Check the ad group schedule
3. It should show your selected Colombia time exactly

## API Endpoints Used

### Get Advertiser Info
```
GET https://business-api.tiktok.com/open_api/v1.3/advertiser/info/
Parameters: advertiser_id
Response includes: timezone, timezone_offset
```

### Create Ad Group
```
POST https://business-api.tiktok.com/open_api/v1.3/adgroup/create/
Body includes:
  - schedule_start_time: "YYYY-MM-DD HH:MM:SS" (UTC)
  - schedule_end_time: "YYYY-MM-DD HH:MM:SS" (UTC)
```

## Important Notes

1. **Advertiser Timezone is Key**
   - Your TikTok advertiser account has a timezone setting
   - This cannot be changed via API (must be changed in TikTok Ads Manager settings)
   - TikTok interprets all UTC times according to this timezone

2. **Dayparting Also Uses Colombia Time**
   - The dayparting (schedule by hour) also follows Colombia timezone
   - When advertiser timezone is Colombia, dayparting works correctly

3. **Consistency Across Campaigns**
   - All campaigns under the same advertiser account share the same timezone
   - This ensures consistent scheduling across all your campaigns

## Example Scenario

**Goal:** Run ads from 9 AM to 5 PM Colombia Time

**User Input:**
- Start: 2025-11-15 09:00 (Colombia Time)
- End: 2025-11-15 17:00 (Colombia Time)

**Conversion (Automatic):**
- Start: 2025-11-15 14:00:00 (UTC) → sent to API
- End: 2025-11-15 22:00:00 (UTC) → sent to API

**TikTok Interpretation:**
- If advertiser timezone = Colombia (UTC-5)
- TikTok shows and runs: 09:00 - 17:00 Colombia Time ✅

## Troubleshooting

### Problem: Times don't match in TikTok Ads Manager
**Solution:** Check your advertiser timezone:
1. Look at dashboard header - is it Colombia?
2. If not, change it in TikTok Ads Manager account settings
3. Contact TikTok support if you need help changing it

### Problem: Console shows different times
**Solution:** This is normal! The console shows:
- Your input (Colombia Time)
- The converted UTC time (for API)
- TikTok will display it back in Colombia time

### Problem: Timezone verification fails
**Solution:** Check:
1. TikTok API credentials are correct
2. Internet connection is stable
3. Try refreshing the page

## References

- [TikTok API Documentation - Ad Group Creation](https://business-api.tiktok.com/portal/docs?id=1739953377508354)
- [text.txt](text.txt) - Additional timezone notes
- [adgroup.txt:1253-1256](adgroup.txt#L1253-L1256) - Official field documentation
