# TikTok Launcher — Route Documentation

> Auto-generated route map for the TikTok Campaign Launcher application.

---

## Table of Contents

1. [Frontend / Page Routes](#i-frontend--page-routes)
2. [API Routes — `api.php`](#ii-api-routes--apiphp)
3. [Smart+ API Routes — `api-smartplus.php`](#iii-smart-api-routes--api-smartplusphp)
4. [Image Serving](#iv-image-serving)
5. [Legacy / Test Routes](#v-legacy--test-routes)
6. [Authentication & Security](#vi-authentication--security)
7. [Summary](#vii-summary)

---

## I. Frontend / Page Routes

| # | Path | Method | Auth Required | Description |
|---|------|--------|---------------|-------------|
| 1 | `/index.php` | GET / POST | None (public) | Login page — username/password auth with CSRF protection and rate limiting (5 attempts / 15 min) |
| 2 | `/oauth-init.php` | GET | Session | Initiates TikTok OAuth 2.0 flow — generates state param and redirects to TikTok authorization |
| 3 | `/oauth-callback.php` | GET | OAuth state | Handles OAuth callback — exchanges auth code for access token, fetches advertiser accounts (paginated, up to 5 000) |
| 4 | `/select-advertiser.php` | GET | Session | Account selection page for non-OAuth (hardcoded credential) users |
| 5 | `/select-advertiser-oauth.php` | GET | OAuth token | Account selection page for OAuth users — filtering & search |
| 6 | `/campaign-select.php` | GET | Session + advertiser | Campaign type selection (Manual, Smart+, etc.) |
| 7 | `/smart-campaign.php` | GET | Session + advertiser | Smart+ campaign creation & management — multi-step form (Campaign → Ad Group → Ads) |
| 8 | `/dashboard.php` | GET | Session + advertiser | Legacy dashboard for campaign management |
| 9 | `/check_session.php` | GET | None (debug) | Debug endpoint — displays current session state and env config |

---

## II. API Routes — `api.php`

**Base path:** `/api.php`
**Protocol:** POST / GET — `Content-Type: application/json`
**Auth:** Session-based (`$_SESSION['authenticated']`)
**Response:** JSON

### Authentication & Session

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `test_auth` | GET/POST | — | Tests auth status; returns `authenticated`, `has_advertiser`, timestamp |
| `set_advertiser` | POST | `advertiser_id` | Sets the active advertiser in session |
| `set_oauth_advertiser` | POST | `advertiser_id`, `campaign_type` | Sets advertiser + campaign type after OAuth (validates against authorized list) |
| `get_selected_advertiser` | GET | — | Returns currently selected advertiser info |
| `get_advertisers` | GET | — | Retrieves advertiser accounts with pagination (up to 5 000) |
| `logout` | POST | — | Destroys session and logs out |

### Campaign Management

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `create_campaign` | POST | `campaign_name`, `budget`, `optimization_goal`, `promotion_type`, … | Creates a regular (non-Smart+) campaign |
| `get_campaigns` | GET | — | Retrieves campaigns for the advertiser |
| `bulk_duplicate_campaign` | POST | `campaign_id`, `count` | Duplicates a campaign N times |

### Ad Group Management

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `create_adgroup` | POST | `campaign_id`, `adgroup_name`, `budget`, `optimization_goal`, … | Creates an ad group in a regular campaign |
| `get_adgroups` | GET | `campaign_id` | Retrieves ad groups for a campaign |
| `duplicate_adgroup` | POST | `adgroup_id` | Duplicates an ad group with all its ads |

### Ad Management

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `create_ad` | POST | `ad_name`, `ad_text`, `ad_format`, `identity_id`, `identity_type`, `landing_page_url`, `adgroup_id`, … | Creates an ad with creative assets |
| `get_ads` | GET | `adgroup_id` | Retrieves ads for an ad group |
| `publish_ads` | POST | `ad_ids` (array) | Publishes multiple ads |
| `duplicate_ad` | POST | `ad_id` | Duplicates an existing ad |

### Smart+ Campaign (Legacy via `api.php`)

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `publish_smart_plus_campaign` | POST | `campaign_name`, `budget`, `identity_id`, `identity_type`, `tiktok_posts`, `landing_page_url`, `schedule_*`, `promotion_type`, `optimization_goal` | Creates Smart+ Lead Gen campaign via `/campaign/spc/create/` (Spark Ads) |
| `create_smart_campaign` | POST | `campaign_name`, `budget`, `schedule_*`, `cbo_enabled`, `campaign_budget` | Legacy multi-step Smart+ campaign creation |
| `create_smart_adgroup` | POST | `campaign_id`, `adgroup_name`, `optimization_goal`, `location_ids`, `age_groups`, … | Creates ad group in Smart+ campaign |
| `create_smart_ad` | POST | `adgroup_id`, `ad_name`, `ad_text`, `ad_format`, `creative_ids`, `video_id`, `landing_page_url` | Creates ad in Smart+ ad group |

### Identity Management

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_identities` | GET | — | Retrieves available identities for the advertiser |
| `create_identity` | POST | `display_name`, `image_uri` | Creates a new `CUSTOMIZED_USER` identity |
| `get_tiktok_posts` | GET | — | Retrieves TikTok posts from linked account |
| `get_video_by_auth_code` | GET | `auth_code` | Gets video info using OAuth auth code |

### Media — Upload & Library

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `upload_video` | POST (multipart) | `file` | Uploads video to TikTok Business API |
| `upload_video_to_advertiser` | POST (multipart) | `file`, `advertiser_id` | Uploads video to a specific advertiser account |
| `upload_video_direct` | POST (multipart) | `file` | Direct video upload bypass |
| `upload_image` | POST (multipart) | `file` | Uploads image to TikTok API |
| `upload_thumbnail_as_cover` | POST (multipart) | `file` | Uploads a thumbnail image as ad cover |
| `get_videos` | GET | — | Retrieves videos from TikTok media library (cached) |
| `get_images` | GET | — | Retrieves images from TikTok media library (cached) |
| `sync_images_from_tiktok` | GET/POST | — | Syncs images from TikTok library |
| `sync_tiktok_library` | GET/POST | — | Syncs full TikTok media library (videos + images) |
| `add_existing_media` | POST | `media_ids`, `media_type` | Adds existing media to campaign |
| `generate_video_thumbnail` | POST | `video_id`, `video_file` | Generates thumbnail from video file |
| `auto_crop_and_upload` | POST | `video_id`, `crop_data` | Auto-crops video and uploads cropped version |

### CTA Portfolios

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_dynamic_ctas` | GET | — | Retrieves list of dynamic CTA portfolios |
| `get_cta_portfolios` | GET | — | Retrieves all CTA portfolios for the advertiser |
| `get_portfolio_details` | GET | `portfolio_id` | Gets details of a specific CTA portfolio |
| `create_cta_portfolio` | POST | `name`, `call_to_actions` | Creates a CTA portfolio |
| `create_portfolio` | POST | `name`, `call_to_actions` | Alternate portfolio creation endpoint |
| `get_or_create_frequently_used_cta_portfolio` | GET | — | Gets or auto-creates frequently-used CTA portfolio |

### Pixels & Tracking

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_pixels` | GET | — | Retrieves conversion tracking pixels |

### Account Info

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_advertiser_info` | GET | — | Retrieves detailed advertiser info (name, timezone, status) |
| `get_timezones` | GET | — | Retrieves available timezones |

### Debug & Testing

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_debug_logs` | GET | — | Retrieves debug logs (dev only) |
| `test_image_search` | GET/POST | — | Tests image search functionality |
| `test_image_api` | GET | — | Tests image API endpoint |
| `debug_storage` | GET | — | Debug endpoint for media storage |

---

## III. Smart+ API Routes — `api-smartplus.php`

**Base path:** `/api-smartplus.php`
**Protocol:** POST / GET — `Content-Type: application/json`
**Auth:** Session-based OAuth token or `.env` token
**Response:** JSON

### Account & Balance

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_advertiser_timezone` | GET | — | Retrieves advertiser's timezone for schedule conversion |
| `get_account_balance` | GET | — | Retrieves account balance, grant balance, currency |
| `get_bulk_accounts` | GET | — | Retrieves all authorized accounts for bulk operations |
| `get_all_advertisers` | GET | — | Retrieves all authorized advertisers with details |
| `get_account_assets` | POST | `advertiser_id` | Gets all assets (identities, pixels, videos, images) for an account |

### Smart+ Campaign CRUD

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `create_smartplus_campaign` | POST | `campaign_name`, `budget`, … | Creates Smart+ campaign (BUDGET_MODE_DYNAMIC_DAILY_BUDGET, CBO enabled) |
| `create_smartplus_adgroup` | POST | `campaign_id`, `adgroup_name`, `schedule_type`, `schedule_*`, … | Creates Smart+ ad group (SCHEDULE_FROM_NOW or SCHEDULE_START_END) |
| `create_smartplus_ad` | POST | `adgroup_id`, `ad_name`, `ad_text`, `identity_id`, `landing_page_url`, … | Creates Smart+ ad with creative assets |
| `create_full_smartplus` | POST | `campaign_name`, `budget`, `identity_id`, `landing_page_url`, … | Creates full campaign structure (Campaign → AdGroup → Ad) in one call with rollback |
| `update_smartplus_campaign` | POST | `campaign_id`, `campaign_name`, … | Updates Smart+ campaign properties |
| `update_smartplus_adgroup` | POST | `adgroup_id`, `adgroup_name`, … | Updates Smart+ ad group |
| `update_smartplus_ad` | POST | `ad_id`, `ad_name`, `ad_text`, … | Updates Smart+ ad |
| `delete_smartplus_adgroup` | POST | `adgroup_id` | Deletes Smart+ ad group (sets DELETE status) |
| `enable_smartplus_campaign` | POST | `campaign_id` | Enables/activates a Smart+ campaign |

### Campaign Retrieval & Metrics

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_campaigns` | GET | — | Retrieves campaigns with basic info |
| `get_campaigns_with_metrics` | GET | `date_range_start`, `date_range_end`, `date_range_preset` | Retrieves campaigns with performance metrics (impressions, clicks, CTR, CPC, spend, etc.) |
| `get_adgroups_for_campaign` | GET | `campaign_id` | Retrieves ad groups for a campaign |
| `get_ads_for_adgroup` | GET | `adgroup_id` | Retrieves ads for an ad group |
| `get_campaign_details` | GET | `campaign_id` | Retrieves detailed campaign info including expanded ad groups and ads |
| `update_campaign_status` | POST | `campaign_id`, `operation_status` | Updates campaign status (ENABLE / DISABLE) |
| `update_campaign_budget` | POST | `campaign_id`, `budget` | Updates campaign daily budget |

### Bulk Operations

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `execute_bulk_launch` | POST | `campaign_config`, `accounts` (array), `primary_advertiser_id`, `duplicate_count` | Bulk-launches campaigns across multiple accounts with duplication; assigns unique job UUID |

### Identity Management

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_identities` | GET | — | Retrieves identities (CUSTOMIZED_USER, TT_USER, etc.) |
| `create_identity` | POST | `display_name`, `image_uri` | Creates CUSTOMIZED_USER identity |
| `create_identity_for_account` | POST | `advertiser_id`, `display_name`, `image_uri` | Creates identity for a specific account |

### Media

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_videos` | GET | — | Retrieves videos from media library (cached) |
| `get_images` | GET | — | Retrieves images from media library |
| `upload_video_to_account` | POST (multipart) | `file`, `advertiser_id` | Uploads video to a specific account |
| `match_videos_by_filename` | POST | `video_files` (array), `videos_available` (array) | Matches uploaded files to available videos by filename |

### Pixels & CTA Portfolios

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `get_pixels` | GET | — | Retrieves conversion pixels for Smart+ campaigns |
| `get_cta_portfolios` | GET | — | Retrieves CTA portfolios |
| `create_cta_portfolio` | POST | `name`, `questions` (array) | Creates a new CTA portfolio |
| `create_portfolio_for_account` | POST | `advertiser_id`, `name`, `questions` | Creates CTA portfolio for a specific account |
| `get_or_create_frequently_used_cta_portfolio` | GET | — | Gets or auto-creates frequently-used CTA portfolio |

### Cache Management

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `clear_cache` | POST | `cache_type` (`pixels`, `identities`, `videos`, `images`, `campaigns`, `advertiser`, `all`) | Clears specified cache(s) |
| `cache_stats` | GET | — | Returns cache statistics (size, hit rates) |

---

## IV. Image Serving

| Path | Method | Parameters | Auth | Description |
|------|--------|------------|------|-------------|
| `/serve-image.php` | GET | `path` (filename) | Session | Secure image server — directory traversal prevention, file type whitelisting (.jpg, .png, .gif, .webp), MIME validation via magic bytes, 1-day cache headers, `X-Content-Type-Options: nosniff` |

---

## V. Legacy / Test Routes

| File | Actions | Description |
|------|---------|-------------|
| `api-smart.php` | `create_smart_campaign`, `create_smart_adgroup`, `create_smart_ad`, `get_smart_campaign_insights` | Alternate Smart+ API handler (legacy) |

---

## VI. Authentication & Security

### Auth Methods

| Method | Flow |
|--------|------|
| **Username / Password** | `index.php` POST → session creation → advertiser selection |
| **OAuth 2.0** | `index.php` → `oauth-init.php` → TikTok auth → `oauth-callback.php` → token exchange → `select-advertiser-oauth.php` |

### Session Guards

| Page / Endpoint | Required Session Keys |
|-----------------|----------------------|
| All dashboard pages | `$_SESSION['authenticated']` |
| Smart campaign page | `$_SESSION['selected_advertiser_id']` |
| OAuth pages | `$_SESSION['oauth_access_token']` |
| `api.php` actions | `$_SESSION['authenticated']` |
| `api-smartplus.php` actions | OAuth token or `.env` token |

### Rate Limiting

- **Login attempts:** 5 per 15 minutes per IP
- Enforced on `index.php` POST via `Security::checkRateLimit()`

### CSRF Protection

- Token generated via `Security::generateCSRFToken()`
- Validated on all form POST submissions

### Error Response Format

```json
{
  "success": false,
  "message": "Human-readable error message",
  "code": "error_code",
  "details": {}
}
```

---

## VII. Summary

| Category | Count |
|----------|-------|
| Frontend pages | **9** |
| API actions (`api.php`) | **41** |
| Smart+ API actions (`api-smartplus.php`) | **35** |
| Image serving endpoints | **1** |
| Legacy routes | **4** |
| **Total routes** | **90** |
| Authentication methods | 2 (Password, OAuth 2.0) |
