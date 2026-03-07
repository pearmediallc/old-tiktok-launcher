// Smart+ Campaign JavaScript
// Flow: Step 1 CREATES Campaign -> Step 2 CREATES AdGroup -> Step 4 CREATES Ad
// Supports UPDATE when going back to modify existing resources

// Global state
let state = {
    currentStep: 1,
    campaignId: null,
    campaignName: null,
    budget: null,  // Budget is at campaign level for Smart+ (with CBO)
    adGroupId: null,
    adId: null,  // Smart+ Ad ID
    pixelId: null,
    optimizationEvent: null,
    locationIds: [],
    ageGroups: ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'],  // Default: 18+ (all adults)
    ageSelection: '18+',  // '18+' or '25+' - matches TikTok Ads Manager options
    dayparting: null,
    creatives: [],
    identities: [],
    mediaLibrary: [],
    selectedVideos: [],
    ctaPortfolios: [],
    selectedPortfolioId: null,
    selectedPortfolioName: null,
    globalCtaPortfolioId: null,  // Portfolio ID for Lead Gen ads
    adTexts: [],  // Array of ad text variations

    // Current advertiser ID (tab-specific - prevents cross-tab contamination)
    currentAdvertiserId: null,

    // Advertiser timezone info (for converting schedule times)
    advertiserTimezone: null,        // e.g., "America/New_York" or "UTC"
    advertiserTimezoneOffset: 0,     // e.g., -5 for EST, 0 for UTC

    // Creation tracking - for UPDATE vs CREATE logic
    campaignCreated: false,
    adGroupCreated: false,
    adCreated: false,

    // Previous values - to detect changes that require delete+recreate
    previousPixelId: null,
    previousOptEvent: null,

    // Campaign listing state
    campaignsList: [],           // All campaigns from API
    filteredCampaigns: [],       // Currently displayed (after filter)
    campaignFilter: 'all',       // Current filter: 'all', 'active', 'inactive'
    campaignSearchQuery: '',     // Search query
    campaignsLoaded: false,      // Whether campaigns have been fetched
    currentView: 'create',       // Current main view: 'create' or 'campaigns'
    selectedCampaigns: [],       // Array of selected campaign IDs for bulk operations

    // Rejected ads state
    rejectedAds: [],             // Array of rejected ads from API
    rejectedAdsCount: 0,         // Count of rejected ads
    rejectedAdsLoaded: false,    // Whether rejected ads have been fetched
    showingRejectedAds: false,   // Whether the rejected ads panel is currently displayed

    // Date range for metrics
    dateRangePreset: 'today',    // Current preset: 'today', '7days', '30days', 'custom'
    dateRangeStart: null,        // Start date (YYYY-MM-DD)
    dateRangeEnd: null,          // End date (YYYY-MM-DD)

    // Expanded rows state
    expandedCampaigns: {},       // { campaignId: { adgroups: [...], loaded: true } }
    expandedAdgroups: {},        // { adgroupId: { ads: [...], loaded: true } }

    // RedTrack LP CTR mappings
    redtrackMappings: {},        // { campaignId: 'redtrack_campaign_name' }
    redtrackLpCtrs: {}           // { campaignId: { lp_ctr, lp_clicks, lp_views } }
};

// ============================================
// INPUT VALIDATION & ERROR MESSAGES
// ============================================

const Validation = {
    // Validation rules
    rules: {
        campaignName: {
            minLength: 1,
            maxLength: 100,
            pattern: /^[a-zA-Z0-9_\-\s\.\(\)]+$/,
            patternMessage: 'Campaign name can only contain letters, numbers, spaces, and basic punctuation (._-())'
        },
        budget: {
            min: 20,
            max: 50000
        },
        adText: {
            maxLength: 100
        },
        url: {
            pattern: /^https?:\/\/.+/,
            patternMessage: 'Please enter a valid URL starting with http:// or https://'
        }
    },

    // User-friendly error messages
    messages: {
        // API Errors
        'INVALID_BUDGET': 'Budget must be between $20 and $50,000 per day.',
        'PIXEL_NOT_FOUND': 'The selected pixel is no longer available. Please select a different pixel.',
        'CAMPAIGN_LIMIT': 'Your account has reached the maximum number of campaigns.',
        'IDENTITY_NOT_FOUND': 'The selected identity is no longer available. Please refresh and try again.',
        'ADGROUP_LIMIT': 'Maximum ad groups reached for this campaign.',
        'VIDEO_NOT_FOUND': 'One or more selected videos could not be found.',
        'DUPLICATE_CAMPAIGN_NAME': 'A campaign with this name already exists. Please choose a different name.',
        'INSUFFICIENT_BALANCE': 'Insufficient account balance to create this campaign.',
        'INVALID_LOCATION': 'One or more selected locations are invalid.',
        'CTA_REQUIRED': 'A Call-to-Action is required for Lead Generation campaigns.',

        // Network errors
        'NETWORK_ERROR': 'Network error. Please check your internet connection and try again.',
        'TIMEOUT': 'Request timed out. Please try again.',
        'SERVER_ERROR': 'Server error. Please try again later.',

        // Default
        'UNKNOWN': 'An unexpected error occurred. Please try again.'
    },

    // Validate campaign name
    validateCampaignName(name) {
        const rules = this.rules.campaignName;

        if (!name || name.trim().length === 0) {
            return { valid: false, message: 'Campaign name is required.' };
        }

        if (name.length < rules.minLength) {
            return { valid: false, message: 'Campaign name is too short.' };
        }

        if (name.length > rules.maxLength) {
            return { valid: false, message: `Campaign name must be ${rules.maxLength} characters or less.` };
        }

        if (!rules.pattern.test(name)) {
            return { valid: false, message: rules.patternMessage };
        }

        return { valid: true };
    },

    // Validate budget
    validateBudget(budget) {
        const rules = this.rules.budget;
        const value = parseFloat(budget);

        if (isNaN(value)) {
            return { valid: false, message: 'Please enter a valid budget amount.' };
        }

        if (value < rules.min) {
            return { valid: false, message: `Minimum budget is $${rules.min} per day.` };
        }

        if (value > rules.max) {
            return { valid: false, message: `Maximum budget is $${rules.max.toLocaleString()} per day.` };
        }

        return { valid: true };
    },

    // Validate URL
    validateUrl(url) {
        if (!url || url.trim().length === 0) {
            return { valid: false, message: 'URL is required.' };
        }

        if (!this.rules.url.pattern.test(url)) {
            return { valid: false, message: this.rules.url.patternMessage };
        }

        return { valid: true };
    },

    // Get friendly error message from API response
    getFriendlyError(response) {
        if (!response) {
            return this.messages['UNKNOWN'];
        }

        // Check for specific error codes
        const errorCode = response.error_code || response.code;
        if (errorCode && this.messages[errorCode]) {
            return this.messages[errorCode];
        }

        // Check message content for known patterns
        const message = (response.message || response.error || '').toLowerCase();

        if (message.includes('budget')) {
            return this.messages['INVALID_BUDGET'];
        }
        if (message.includes('pixel')) {
            return this.messages['PIXEL_NOT_FOUND'];
        }
        if (message.includes('identity')) {
            return this.messages['IDENTITY_NOT_FOUND'];
        }
        if (message.includes('duplicate') || message.includes('already exist')) {
            return this.messages['DUPLICATE_CAMPAIGN_NAME'];
        }
        if (message.includes('balance') || message.includes('fund')) {
            return this.messages['INSUFFICIENT_BALANCE'];
        }
        if (message.includes('cta') || message.includes('call to action') || message.includes('portfolio')) {
            return this.messages['CTA_REQUIRED'];
        }
        if (message.includes('network') || message.includes('connection')) {
            return this.messages['NETWORK_ERROR'];
        }
        if (message.includes('timeout')) {
            return this.messages['TIMEOUT'];
        }

        // Return original message if no friendly version found
        return response.message || response.error || this.messages['UNKNOWN'];
    },

    // Show inline validation error
    showFieldError(fieldId, message) {
        const field = document.getElementById(fieldId);
        if (!field) return;

        // Remove existing error
        this.clearFieldError(fieldId);

        // Add error styling
        field.style.borderColor = '#ef4444';
        field.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';

        // Add error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.id = `${fieldId}-error`;
        errorDiv.style.cssText = 'color: #ef4444; font-size: 12px; margin-top: 5px;';
        errorDiv.textContent = message;

        field.parentNode.appendChild(errorDiv);
    },

    // Clear inline validation error
    clearFieldError(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;

        // Remove error styling
        field.style.borderColor = '';
        field.style.boxShadow = '';

        // Remove error message
        const errorDiv = document.getElementById(`${fieldId}-error`);
        if (errorDiv) {
            errorDiv.remove();
        }
    },

    // Validate on input (real-time validation)
    setupRealTimeValidation() {
        // Campaign name validation
        const campaignNameField = document.getElementById('campaign-name');
        if (campaignNameField) {
            campaignNameField.addEventListener('blur', () => {
                const result = this.validateCampaignName(campaignNameField.value);
                if (!result.valid) {
                    this.showFieldError('campaign-name', result.message);
                } else {
                    this.clearFieldError('campaign-name');
                }
            });

            campaignNameField.addEventListener('input', () => {
                // Clear error on input
                this.clearFieldError('campaign-name');
            });
        }

        // Budget validation (at campaign level for Smart+)
        const budgetField = document.getElementById('campaign-budget');
        if (budgetField) {
            budgetField.addEventListener('blur', () => {
                const result = this.validateBudget(budgetField.value);
                if (!result.valid) {
                    this.showFieldError('campaign-budget', result.message);
                } else {
                    this.clearFieldError('campaign-budget');
                }
            });

            budgetField.addEventListener('input', () => {
                this.clearFieldError('campaign-budget');
            });
        }
    }
};

// Initialize validation when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    Validation.setupRealTimeValidation();
});

// US States with TikTok location IDs (verified from TikTok API)
const US_STATES = [
    { id: '4829764', name: 'Alabama', abbr: 'AL' },
    { id: '5879092', name: 'Alaska', abbr: 'AK' },
    { id: '5551752', name: 'Arizona', abbr: 'AZ' },
    { id: '4099753', name: 'Arkansas', abbr: 'AR' },
    { id: '5332921', name: 'California', abbr: 'CA' },
    { id: '5417618', name: 'Colorado', abbr: 'CO' },
    { id: '4831725', name: 'Connecticut', abbr: 'CT' },
    { id: '4142224', name: 'Delaware', abbr: 'DE' },
    { id: '4155751', name: 'Florida', abbr: 'FL' },
    { id: '4197000', name: 'Georgia', abbr: 'GA' },
    { id: '5855797', name: 'Hawaii', abbr: 'HI' },
    { id: '5596512', name: 'Idaho', abbr: 'ID' },
    { id: '4896861', name: 'Illinois', abbr: 'IL' },
    { id: '4921868', name: 'Indiana', abbr: 'IN' },
    { id: '4862182', name: 'Iowa', abbr: 'IA' },
    { id: '4273857', name: 'Kansas', abbr: 'KS' },
    { id: '6254925', name: 'Kentucky', abbr: 'KY' },
    { id: '4331987', name: 'Louisiana', abbr: 'LA' },
    { id: '4971068', name: 'Maine', abbr: 'ME' },
    { id: '4361885', name: 'Maryland', abbr: 'MD' },
    { id: '6254926', name: 'Massachusetts', abbr: 'MA' },
    { id: '5001836', name: 'Michigan', abbr: 'MI' },
    { id: '5037779', name: 'Minnesota', abbr: 'MN' },
    { id: '4436296', name: 'Mississippi', abbr: 'MS' },
    { id: '4398678', name: 'Missouri', abbr: 'MO' },
    { id: '5667009', name: 'Montana', abbr: 'MT' },
    { id: '5073708', name: 'Nebraska', abbr: 'NE' },
    { id: '5509151', name: 'Nevada', abbr: 'NV' },
    { id: '5090174', name: 'New Hampshire', abbr: 'NH' },
    { id: '5101760', name: 'New Jersey', abbr: 'NJ' },
    { id: '5481136', name: 'New Mexico', abbr: 'NM' },
    { id: '5128638', name: 'New York', abbr: 'NY' },
    { id: '4482348', name: 'North Carolina', abbr: 'NC' },
    { id: '5690763', name: 'North Dakota', abbr: 'ND' },
    { id: '5165418', name: 'Ohio', abbr: 'OH' },
    { id: '4544379', name: 'Oklahoma', abbr: 'OK' },
    { id: '5744337', name: 'Oregon', abbr: 'OR' },
    { id: '6254927', name: 'Pennsylvania', abbr: 'PA' },
    { id: '5224323', name: 'Rhode Island', abbr: 'RI' },
    { id: '4597040', name: 'South Carolina', abbr: 'SC' },
    { id: '5769223', name: 'South Dakota', abbr: 'SD' },
    { id: '4662168', name: 'Tennessee', abbr: 'TN' },
    { id: '4736286', name: 'Texas', abbr: 'TX' },
    { id: '5549030', name: 'Utah', abbr: 'UT' },
    { id: '5242283', name: 'Vermont', abbr: 'VT' },
    { id: '6254928', name: 'Virginia', abbr: 'VA' },
    { id: '5815135', name: 'Washington', abbr: 'WA' },
    { id: '4138106', name: 'Washington, D.C.', abbr: 'DC' },
    { id: '4826850', name: 'West Virginia', abbr: 'WV' },
    { id: '5279468', name: 'Wisconsin', abbr: 'WI' },
    { id: '5843591', name: 'Wyoming', abbr: 'WY' }
];

const SMARTPLUS_API = 'api-smartplus.php';
const MAIN_API = 'api.php';

// API Request function with detailed logging
async function apiRequest(action, data = {}, useMainApi = false) {
    const apiUrl = useMainApi ? MAIN_API : SMARTPLUS_API;

    // IMPORTANT: Include current advertiser ID with every request to prevent cross-tab contamination
    // This ensures each browser tab uses its own advertiser context, not the shared PHP session
    const requestBody = {
        action,
        _advertiser_id: state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || null,
        ...data
    };

    // Log full request details
    addLog('request', `>>> ${action}`, {
        endpoint: apiUrl,
        action: action,
        parameters: data
    });

    try {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify(requestBody)
        });

        // Check HTTP status before parsing JSON
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP ${response.status}: ${response.statusText}. ${errorText.substring(0, 200)}`);
        }

        // Parse JSON with error handling
        let result;
        try {
            result = await response.json();
        } catch (parseError) {
            throw new Error('Invalid JSON response from server');
        }

        if (result.success) {
            addLog('response', `<<< ${action} SUCCESS`, {
                status: 'success',
                response: result
            });
        } else {
            addLog('error', `<<< ${action} FAILED: ${result.message}`, {
                status: 'failed',
                error_code: result.error_code || null,
                message: result.message,
                details: result.details || result
            });
        }

        return result;
    } catch (error) {
        addLog('error', `<<< ${action} ERROR: ${error.message}`, {
            status: 'error',
            error: error.message,
            stack: error.stack
        });
        throw error;
    }
}

// API Logger Functions - Shows full details
function addLog(type, message, details = null) {
    const logsContent = document.getElementById('logs-content');
    if (!logsContent) return;

    const logEntry = document.createElement('div');
    logEntry.className = `log-entry log-${type}`;
    logEntry.style.borderLeft = type === 'error' ? '3px solid #ff4444' :
                                 type === 'request' ? '3px solid #667eea' :
                                 type === 'response' ? '3px solid #22c55e' : '3px solid #999';

    const now = new Date();
    const time = now.toTimeString().split(' ')[0];

    let typeLabel = '';
    let typeBg = '';
    if (type === 'request') {
        typeLabel = '<span class="log-type" style="background:#667eea;color:white;padding:2px 6px;border-radius:3px;font-size:10px;">REQUEST</span>';
    } else if (type === 'response') {
        typeLabel = '<span class="log-type" style="background:#22c55e;color:white;padding:2px 6px;border-radius:3px;font-size:10px;">RESPONSE</span>';
    } else if (type === 'error') {
        typeLabel = '<span class="log-type" style="background:#ff4444;color:white;padding:2px 6px;border-radius:3px;font-size:10px;">ERROR</span>';
    } else {
        typeLabel = '<span class="log-type" style="background:#999;color:white;padding:2px 6px;border-radius:3px;font-size:10px;">INFO</span>';
    }

    logEntry.innerHTML = `
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:5px;">
            <span class="log-time" style="color:#666;font-size:11px;">${time}</span>
            ${typeLabel}
            <span class="log-message" style="font-weight:500;">${message}</span>
        </div>
    `;

    if (details) {
        const detailsDiv = document.createElement('div');
        detailsDiv.className = 'log-details';
        detailsDiv.style.cssText = 'background:#f5f5f5;padding:10px;border-radius:4px;margin-top:5px;overflow-x:auto;';
        detailsDiv.innerHTML = `<pre style="margin:0;font-size:11px;white-space:pre-wrap;word-break:break-all;">${JSON.stringify(details, null, 2)}</pre>`;
        logEntry.appendChild(detailsDiv);
    }

    logsContent.appendChild(logEntry);
    logsContent.scrollTop = logsContent.scrollHeight;

    // Also log to console for debugging
    console.log(`[${type.toUpperCase()}] ${message}`, details);
}

function clearLogs() {
    const logsContent = document.getElementById('logs-content');
    logsContent.innerHTML = `
        <div class="log-entry log-info">
            <span class="log-time">${new Date().toTimeString().split(' ')[0]}</span>
            <span class="log-message">Logs cleared</span>
        </div>
    `;
}

function toggleLogsPanel() {
    const logsPanel = document.getElementById('logs-panel');
    const toggleIcon = document.getElementById('logs-toggle-icon');

    logsPanel.classList.toggle('collapsed');

    if (logsPanel.classList.contains('collapsed')) {
        toggleIcon.textContent = '▲ Show Logs';
    } else {
        toggleIcon.textContent = '▼ Hide Logs';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Smart+ Campaign JS loaded');
    addLog('info', 'Smart+ Campaign initialized');

    // Initialize current advertiser ID from page variable (tab-specific)
    // This prevents cross-tab contamination when multiple tabs have different ad accounts
    state.currentAdvertiserId = window.TIKTOK_ADVERTISER_ID || null;
    if (state.currentAdvertiserId) {
        addLog('info', `Advertiser ID initialized: ${state.currentAdvertiserId}`);
    }

    // APP SHELL MODE: Determine which view to initialize based on URL
    const isShellMode = window.APP_SHELL_MODE === true;
    const urlParams = new URLSearchParams(window.location.search);
    const shellView = urlParams.get('view') || '';

    if (isShellMode && shellView === 'campaigns') {
        // Shell campaigns view: only load campaigns-related data
        initializeDateRange();
        loadAdvertiserTimezone();

        // Make campaigns-view visible (shell partial sets it as default)
        const campaignsView = document.getElementById('campaigns-view');
        if (campaignsView) campaignsView.style.display = 'block';

        // Load campaigns immediately
        state.currentView = 'campaigns';
        loadCampaigns();

    } else if (isShellMode && shellView === 'create-smart') {
        // Shell create-smart view: load all creation resources
        loadPixels();
        loadIdentities();
        loadCtaPortfolios();
        loadMediaLibrary();
        loadAdvertiserTimezone();
        checkAccountBalance();
        initializeDayparting();
        initializeLocationTargeting();
        initializeAgeTargeting();
        loadBulkAccounts();
        initializeDateRange();
        initializeStepNavigation();

        // Show create view
        const createView = document.getElementById('create-view');
        if (createView) createView.style.display = 'block';
        state.currentView = 'create';

        // Initialize launch mode
        const singleOption = document.getElementById('single-launch-option');
        if (singleOption) singleOption.classList.add('selected');

        const identityInput = document.getElementById('identity-display-name');
        const charCounter = document.getElementById('identity-char-count');
        if (identityInput && charCounter) {
            identityInput.addEventListener('input', function() {
                charCounter.textContent = this.value.length;
            });
        }

    } else {
        // Original standalone mode (smart-campaign.php loaded directly)
        loadPixels();
        loadIdentities();
        loadCtaPortfolios();
        loadMediaLibrary();
        loadAdvertiserTimezone();
        checkAccountBalance();
        initializeDayparting();
        initializeLocationTargeting();
        initializeAgeTargeting();
        loadBulkAccounts();
        initializeDateRange();
        initializeStepNavigation();

        // Check for URL parameter to auto-switch to campaigns view
        if (urlParams.get('view') === 'campaigns') {
            setTimeout(() => {
                switchMainView('campaigns');
            }, 100);
        }

        // Initialize launch mode (single is default and selected)
        const singleOption = document.getElementById('single-launch-option');
        if (singleOption) singleOption.classList.add('selected');

        const statusElement = document.getElementById('timezone-status');
        if (statusElement) {
            statusElement.innerHTML = '<span style="color: #22c55e;">✓</span> Smart+ Campaign Mode';
            statusElement.style.color = '#22c55e';
        }

        const identityInput = document.getElementById('identity-display-name');
        const charCounter = document.getElementById('identity-char-count');
        if (identityInput && charCounter) {
            identityInput.addEventListener('input', function() {
                charCounter.textContent = this.value.length;
            });
        }
    }
});

// Initialize age targeting radio buttons
function initializeAgeTargeting() {
    // Set default state - 18+ is checked by default in HTML
    state.ageSelection = '18+';
    state.ageGroups = ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'];
    console.log('Age targeting initialized:', state.ageSelection, state.ageGroups);
}

// Update age selection based on radio button choice (called from HTML onchange)
function updateAgeSelection(selection) {
    state.ageSelection = selection;

    if (selection === '18+') {
        // 18+ = All adults
        state.ageGroups = ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'];
    } else if (selection === '25+') {
        // 25+ = Older adults only
        state.ageGroups = ['AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'];
    }

    console.log('Age selection updated:', state.ageSelection, state.ageGroups);
}

// Legacy function - kept for compatibility
function updateAgeGroupsState() {
    // This function is no longer needed with radio buttons
    // Age state is now managed by updateAgeSelection()
    console.log('Current age groups:', state.ageGroups);
}

// Toggle CBO budget section (deprecated - budget is always at ad group level now)
function toggleCBOBudget() {
    // No-op: Budget is always set at Ad Group level
}

// Load Advertiser Timezone (for schedule time conversion)
// TikTok interprets schedule_start_time in the advertiser's account timezone
// User enters time in EST, so we need to convert to the advertiser's timezone
async function loadAdvertiserTimezone() {
    try {
        const result = await apiRequest('get_advertiser_timezone');

        if (result.success && result.data) {
            state.advertiserTimezone = result.data.timezone || 'UTC';
            state.advertiserTimezoneOffset = result.data.timezone_offset || 0;
            const offsetStr = state.advertiserTimezoneOffset >= 0 ? `+${state.advertiserTimezoneOffset}` : `${state.advertiserTimezoneOffset}`;
            addLog('info', `Account timezone: ${state.advertiserTimezone} (UTC${offsetStr})`);

            // Update timezone display in Step 2 if element exists
            const tzDisplay = document.getElementById('account-timezone-display');
            if (tzDisplay) {
                tzDisplay.innerHTML = `<span style="font-size: 12px; color: #64748b;">Account Timezone: <strong>${state.advertiserTimezone}</strong> (UTC${offsetStr})</span>`;
            }

            // Dayparting times are interpreted in this timezone by TikTok
            addLog('info', `Dayparting schedule will be interpreted in ${state.advertiserTimezone} timezone by TikTok`);
        } else {
            // Default to UTC if we can't get timezone
            state.advertiserTimezone = 'UTC';
            state.advertiserTimezoneOffset = 0;
            addLog('warn', 'Could not get advertiser timezone, defaulting to UTC');
        }
    } catch (error) {
        console.error('Error loading advertiser timezone:', error);
        // Default to UTC on error
        state.advertiserTimezone = 'UTC';
        state.advertiserTimezoneOffset = 0;
    }
}

// ============================================
// ACCOUNT BALANCE & PAYMENT CHECK
// ============================================

// Check account balance and show warnings if needed
async function checkAccountBalance() {
    try {
        addLog('info', 'Checking account balance...');
        const result = await apiRequest('get_account_balance');

        if (result.success && result.data) {
            const balance = parseFloat(result.data.total_balance);
            const currency = result.data.currency || 'USD';
            state.accountBalance = balance;
            state.accountCurrency = currency;

            addLog('info', `Account balance: ${currency} ${balance.toFixed(2)}`);

            // Show warning if balance is low (less than $50)
            if (balance < 50) {
                showBalanceWarning(balance, currency);
            }
        } else if (result.payment_issue) {
            // Payment method issue detected
            showPaymentError('Unable to access account funds. Please check your payment method in TikTok Ads Manager.');
            addLog('warning', 'Payment method issue detected');
        }
    } catch (e) {
        console.warn('Could not check account balance:', e);
        addLog('warning', 'Could not check account balance: ' + e.message);
    }
}

// Show low balance warning banner
function showBalanceWarning(balance, currency) {
    // Remove any existing warnings first
    removeAccountAlerts();

    const warningHtml = `
        <div id="balance-warning" class="balance-warning">
            <span class="warning-icon">⚠️</span>
            <span>Low account balance: ${currency} ${balance.toFixed(2)} - Campaigns may not deliver properly.</span>
            <a href="https://ads.tiktok.com/i18n/account/payment" target="_blank">Add Funds →</a>
        </div>
    `;

    // Insert inside the main content area at the top
    const insertPoint = document.querySelector('.view-panel') ||
                       document.getElementById('main-content') ||
                       document.querySelector('.main-content') ||
                       document.querySelector('main');
    if (insertPoint) {
        insertPoint.insertAdjacentHTML('afterbegin', warningHtml);
    }
}

// Show payment error banner
function showPaymentError(message) {
    // Remove any existing warnings first
    removeAccountAlerts();

    const errorHtml = `
        <div id="payment-error" class="payment-error">
            <span class="error-icon">❌</span>
            <span>${message}</span>
            <a href="https://ads.tiktok.com/i18n/account/payment" target="_blank">Fix Payment →</a>
        </div>
    `;

    // Insert inside the main content area at the top
    const insertPoint = document.querySelector('.view-panel') ||
                       document.getElementById('main-content') ||
                       document.querySelector('.main-content') ||
                       document.querySelector('main');
    if (insertPoint) {
        insertPoint.insertAdjacentHTML('afterbegin', errorHtml);
    }
}

// Remove account alert banners
function removeAccountAlerts() {
    document.getElementById('balance-warning')?.remove();
    document.getElementById('payment-error')?.remove();
}

// Load Pixels
async function loadPixels() {
    const select = document.getElementById('pixel-select');

    try {
        // Log the advertiser ID being used
        const advertiserId = state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID;
        addLog('info', `Loading pixels for advertiser: ${advertiserId}`);

        if (!advertiserId) {
            addLog('error', 'No advertiser ID available for pixel loading');
            select.innerHTML = '<option value="">No ad account selected</option>';
            return;
        }

        const result = await apiRequest('get_pixels');

        if (result.success && result.data && result.data.pixels) {
            const pixels = result.data.pixels;
            if (pixels.length === 0) {
                select.innerHTML = '<option value="">No pixels found in this account</option>';
                addLog('info', 'No pixels found for this ad account');
            } else {
                select.innerHTML = '<option value="">Select a pixel...</option>';
                pixels.forEach(pixel => {
                    const option = document.createElement('option');
                    option.value = pixel.pixel_id;
                    option.textContent = pixel.pixel_name || pixel.pixel_id;
                    select.appendChild(option);
                });
                addLog('success', `Loaded ${pixels.length} pixels`);
            }
        } else {
            // API returned an error
            const errorMsg = result.message || 'Unknown error';
            select.innerHTML = '<option value="">Error loading pixels</option>';
            addLog('error', `Pixel load error: ${errorMsg}`);
            console.error('Pixel API error:', result);
        }
    } catch (error) {
        console.error('Error loading pixels:', error);
        select.innerHTML = '<option value="">Error loading pixels</option>';
        addLog('error', `Pixel load exception: ${error.message}`);
    }
}

// Load Identities (Custom Identities + TikTok Pages)
async function loadIdentities() {
    try {
        const result = await apiRequest('get_identities', {}, true);
        const select = document.getElementById('global-identity');

        if (result.success && result.data) {
            // Get both custom identities and TikTok Pages (BC_AUTH_TT)
            const customIdentities = result.data.identities || result.data.list || [];
            const pages = result.data.pages || [];

            // Combine for state (backward compatibility)
            state.identities = [...customIdentities, ...pages];
            state.customIdentities = customIdentities;
            state.tiktokPages = pages;

            select.innerHTML = '<option value="">Select identity...</option>';

            // Add Custom Identities section
            if (customIdentities.length > 0) {
                const customGroup = document.createElement('optgroup');
                customGroup.label = 'Custom Identities';

                customIdentities.forEach(identity => {
                    const option = document.createElement('option');
                    option.value = identity.identity_id;
                    option.dataset.identityType = 'CUSTOMIZED_USER';
                    option.dataset.sourceType = 'custom_identity';
                    option.textContent = `${identity.display_name || identity.identity_name}`;
                    customGroup.appendChild(option);
                });

                select.appendChild(customGroup);
            }

            // Add TikTok Pages section (BC_AUTH_TT)
            if (pages.length > 0) {
                const pagesGroup = document.createElement('optgroup');
                pagesGroup.label = 'TikTok Pages (Authorized)';

                pages.forEach(page => {
                    // Log full page data for debugging
                    console.log('BC_AUTH_TT page data:', page);

                    const option = document.createElement('option');
                    option.value = page.identity_id;
                    option.dataset.identityType = 'BC_AUTH_TT';
                    option.dataset.sourceType = 'bc_auth_tt';
                    // Store identity_authorized_bc_id if available
                    // TikTok may return this as 'identity_authorized_bc_id' or 'bc_id'
                    const bcId = page.identity_authorized_bc_id || page.bc_id || page.authorized_bc_id;
                    if (bcId) {
                        option.dataset.identityAuthorizedBcId = bcId;
                        console.log(`Page ${page.identity_id} has bc_id: ${bcId}`);
                    } else {
                        console.warn(`Page ${page.identity_id} missing identity_authorized_bc_id!`);
                    }
                    option.textContent = `${page.display_name || page.identity_name || 'TikTok Page'}`;
                    pagesGroup.appendChild(option);
                });

                select.appendChild(pagesGroup);
            }

            const totalCount = customIdentities.length + pages.length;
            addLog('info', `Loaded ${totalCount} identities (${customIdentities.length} custom, ${pages.length} pages)`);

            if (totalCount === 0) {
                select.innerHTML = '<option value="">No identities found - Create one in TikTok Ads Manager</option>';
            }
        } else {
            select.innerHTML = '<option value="">No identities found</option>';
            state.identities = [];
            state.customIdentities = [];
            state.tiktokPages = [];
        }
    } catch (error) {
        console.error('Error loading identities:', error);
        state.identities = [];
        state.customIdentities = [];
        state.tiktokPages = [];
    }
}

// ========================================
// REFRESH FUNCTIONS FOR PIXELS & IDENTITIES
// ========================================

// Refresh pixels - supports both main page and per-account
async function refreshPixels(advertiserId = null) {
    const iconId = advertiserId ? `pixel-refresh-icon-${advertiserId}` : 'pixel-refresh-icon';
    const btnId = advertiserId ? `pixel-refresh-btn-${advertiserId}` : 'pixel-refresh-btn';
    const selectId = advertiserId ? `pixel-${advertiserId}` : 'pixel-select';

    const icon = document.getElementById(iconId);
    const btn = document.getElementById(btnId);
    const select = document.getElementById(selectId);

    // Show loading state
    if (icon) icon.innerHTML = '<span class="spinner">🔄</span>';
    if (btn) btn.disabled = true;
    if (select) select.disabled = true;

    try {
        const endpoint = advertiserId ? 'api-smartplus.php' : 'api-smartplus.php';
        const params = new URLSearchParams({ action: 'get_pixels', force_refresh: 'true' });
        if (advertiserId) params.append('advertiser_id', advertiserId);

        const response = await fetch(`${endpoint}?${params}`);
        const result = await response.json();

        if (result.success && result.data?.pixels) {
            const pixels = result.data.pixels;
            const currentValue = select?.value;

            // Rebuild dropdown
            select.innerHTML = '<option value="">Select pixel...</option>';
            pixels.forEach(p => {
                const name = p.pixel_name || p.name || `Pixel ${p.pixel_id}`;
                const option = document.createElement('option');
                option.value = p.pixel_id;
                option.textContent = name;
                select.appendChild(option);
            });

            // Restore previous selection if still valid
            if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
                select.value = currentValue;
            }

            showToast(`Refreshed ${pixels.length} pixel(s)`, 'success');
            addLog('success', `Refreshed ${pixels.length} pixels${advertiserId ? ` for account ${advertiserId}` : ''}`);
        } else {
            showToast(result.message || 'Failed to refresh pixels', 'error');
        }
    } catch (e) {
        console.error('Refresh pixels error:', e);
        showToast('Error refreshing pixels: ' + e.message, 'error');
    } finally {
        if (icon) icon.innerHTML = '🔄';
        if (btn) btn.disabled = false;
        if (select) select.disabled = false;
    }
}

// Refresh identities - supports both main page and per-account
async function refreshIdentities(advertiserId = null) {
    const iconId = advertiserId ? `identity-refresh-icon-${advertiserId}` : 'identity-refresh-icon';
    const btnId = advertiserId ? `identity-refresh-btn-${advertiserId}` : 'identity-refresh-btn';
    const selectId = advertiserId ? `identity-${advertiserId}` : 'global-identity';

    const icon = document.getElementById(iconId);
    const btn = document.getElementById(btnId);
    const select = document.getElementById(selectId);

    // Show loading state
    if (icon) icon.innerHTML = '<span class="spinner">🔄</span>';
    if (btn) btn.disabled = true;
    if (select) select.disabled = true;

    try {
        const endpoint = advertiserId ? 'api-smartplus.php' : 'api-smartplus.php';
        const params = new URLSearchParams({ action: 'get_identities', force_refresh: 'true' });
        if (advertiserId) params.append('advertiser_id', advertiserId);

        const response = await fetch(`${endpoint}?${params}`);
        const result = await response.json();

        if (result.success) {
            const identities = result.data?.list || result.data?.identities || [];
            const pages = result.data?.pages || [];
            const currentValue = select?.value;

            // Rebuild dropdown with optgroups
            select.innerHTML = '<option value="">Select identity...</option>';

            if (identities.length > 0) {
                const customGroup = document.createElement('optgroup');
                customGroup.label = 'Custom Identities';
                identities.forEach(id => {
                    const option = document.createElement('option');
                    option.value = id.identity_id;
                    option.dataset.type = id.identity_type || 'CUSTOMIZED_USER';
                    option.textContent = id.display_name || id.identity_name || id.identity_id;
                    customGroup.appendChild(option);
                });
                select.appendChild(customGroup);
            }

            if (pages.length > 0) {
                const bcGroup = document.createElement('optgroup');
                bcGroup.label = 'TikTok Pages (Business Center)';
                pages.forEach(id => {
                    const option = document.createElement('option');
                    option.value = id.identity_id;
                    option.dataset.type = 'BC_AUTH_TT';
                    option.dataset.bcId = id.identity_authorized_bc_id || '';
                    option.textContent = id.display_name || id.identity_name || id.identity_id;
                    bcGroup.appendChild(option);
                });
                select.appendChild(bcGroup);
            }

            // Restore previous selection if still valid
            if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
                select.value = currentValue;
            }

            // Update state if main page refresh
            if (!advertiserId) {
                state.identities = [...identities, ...pages];
                state.customIdentities = identities;
                state.tiktokPages = pages;
            }

            const totalCount = identities.length + pages.length;
            showToast(`Refreshed ${totalCount} identity(ies)`, 'success');
            addLog('success', `Refreshed ${totalCount} identities${advertiserId ? ` for account ${advertiserId}` : ''}`);
        } else {
            showToast(result.message || 'Failed to refresh identities', 'error');
        }
    } catch (e) {
        console.error('Refresh identities error:', e);
        showToast('Error refreshing identities: ' + e.message, 'error');
    } finally {
        if (icon) icon.innerHTML = '🔄';
        if (btn) btn.disabled = false;
        if (select) select.disabled = false;
    }
}

// Refresh pixels for duplicate bulk modal (alias)
async function refreshDupBulkPixels(advertiserId) {
    return refreshPixels(advertiserId);
}

// Refresh identities for duplicate bulk modal (alias)
async function refreshDupBulkIdentities(advertiserId) {
    return refreshIdentities(advertiserId);
}

// Load CTA Portfolios
async function loadCtaPortfolios() {
    try {
        const result = await apiRequest('get_cta_portfolios');
        const select = document.getElementById('cta-portfolio-select');

        if (result.success && result.data && result.data.portfolios) {
            state.ctaPortfolios = result.data.portfolios;
            select.innerHTML = '<option value="">Select a CTA portfolio...</option>';

            state.ctaPortfolios.forEach(portfolio => {
                const option = document.createElement('option');
                option.value = portfolio.creative_portfolio_id;
                // Extract CTA names from portfolio_content
                const ctaNames = (portfolio.portfolio_content || [])
                    .map(c => c.asset_content.replace(/_/g, ' '))
                    .join(', ');
                option.textContent = `${portfolio.portfolio_name} (${ctaNames || 'CTAs'})`;
                option.dataset.ctas = ctaNames;
                option.dataset.name = portfolio.portfolio_name;
                select.appendChild(option);
            });

            // Add event listener for portfolio selection
            select.addEventListener('change', onPortfolioSelect);

            addLog('info', `Loaded ${state.ctaPortfolios.length} CTA portfolios`);
        } else {
            select.innerHTML = '<option value="">No portfolios found - Create one below</option>';
            state.ctaPortfolios = [];
        }
    } catch (error) {
        console.error('Error loading portfolios:', error);
        const select = document.getElementById('cta-portfolio-select');
        select.innerHTML = '<option value="">Error loading portfolios</option>';
        state.ctaPortfolios = [];
    }
}

// On portfolio selection
function onPortfolioSelect() {
    const select = document.getElementById('cta-portfolio-select');
    const selectedOption = select.options[select.selectedIndex];
    const infoDiv = document.getElementById('selected-portfolio-info');
    const nameDisplay = document.getElementById('portfolio-name-display');
    const ctasDisplay = document.getElementById('portfolio-ctas-display');

    if (select.value) {
        state.selectedPortfolioId = select.value;
        state.selectedPortfolioName = selectedOption.dataset.name || 'CTA Portfolio';

        nameDisplay.textContent = state.selectedPortfolioName;
        ctasDisplay.textContent = selectedOption.dataset.ctas || 'Dynamic CTAs';
        infoDiv.style.display = 'block';

        addLog('info', `Selected portfolio: ${state.selectedPortfolioName} (ID: ${state.selectedPortfolioId})`);
    } else {
        state.selectedPortfolioId = null;
        state.selectedPortfolioName = null;
        infoDiv.style.display = 'none';
    }
}

// Use Frequently Used CTAs (auto-creates or fetches existing)
async function useFrequentlyUsedCTAs() {
    showLoading('Setting up frequently used CTAs...');
    addLog('info', 'Getting or creating frequently used CTA portfolio');

    try {
        const result = await apiRequest('get_or_create_frequently_used_cta_portfolio');

        if (result.success && result.data && result.data.portfolio_id) {
            const portfolioId = result.data.portfolio_id;
            const portfolioName = result.data.portfolio_name || 'Frequently Used CTAs';

            state.selectedPortfolioId = portfolioId;
            state.selectedPortfolioName = portfolioName;

            // Reload portfolios and select the new one
            await loadCtaPortfolios();

            const select = document.getElementById('cta-portfolio-select');
            select.value = portfolioId;
            onPortfolioSelect();

            hideLoading();
            showToast('Frequently Used CTAs portfolio ready!', 'success');
            addLog('info', `Portfolio ready: ${portfolioName} (ID: ${portfolioId})`);
        } else {
            hideLoading();
            showToast('Failed to create portfolio: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        hideLoading();
        showToast('Error: ' + error.message, 'error');
    }
}

// Quick-create a Learn_More CTA portfolio
async function createLearnMorePortfolio() {
    showLoading('Creating Learn_More portfolio...');
    addLog('info', 'Creating Learn_More CTA portfolio');

    try {
        const result = await apiRequest('create_cta_portfolio', {
            portfolio_name: 'Learn_More',
            portfolio_content: [
                { asset_content: 'LEARN_MORE', asset_ids: ["0"] }
            ]
        });

        if (result.success && result.portfolio_id) {
            const portfolioId = result.portfolio_id;

            state.selectedPortfolioId = portfolioId;
            state.selectedPortfolioName = 'Learn_More';

            // Reload portfolios and select the new one
            await loadCtaPortfolios();

            const select = document.getElementById('cta-portfolio-select');
            if (select) {
                select.value = portfolioId;
                onPortfolioSelect();
            }

            hideLoading();
            showToast('Learn_More portfolio created!', 'success');
            addLog('info', `Portfolio ready: Learn_More (ID: ${portfolioId})`);
        } else {
            hideLoading();
            showToast('Failed to create portfolio: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        hideLoading();
        showToast('Error: ' + error.message, 'error');
    }
}

// Open Create Portfolio Modal
function openCreatePortfolioModal() {
    document.getElementById('portfolio-name-input').value = '';
    // Uncheck all except default
    document.querySelectorAll('.portfolio-cta-checkbox').forEach(cb => {
        cb.checked = cb.value === 'LEARN_MORE';
    });
    document.getElementById('create-portfolio-modal').style.display = 'flex';
}

// Close Create Portfolio Modal
function closeCreatePortfolioModal() {
    document.getElementById('create-portfolio-modal').style.display = 'none';
}

// Create CTA Portfolio
async function createCtaPortfolio() {
    const portfolioName = document.getElementById('portfolio-name-input').value.trim();

    if (!portfolioName) {
        showToast('Please enter a portfolio name', 'error');
        return;
    }

    // Get selected CTAs
    const selectedCTAs = [];
    document.querySelectorAll('.portfolio-cta-checkbox:checked').forEach(cb => {
        selectedCTAs.push({
            asset_content: cb.value,
            asset_ids: ["0"]  // Must be string per TikTok API requirement
        });
    });

    if (selectedCTAs.length === 0) {
        showToast('Please select at least one CTA', 'error');
        return;
    }

    if (selectedCTAs.length > 5) {
        showToast('Maximum 5 CTAs allowed per portfolio', 'error');
        return;
    }

    showLoading('Creating CTA portfolio...');
    addLog('info', `Creating portfolio: ${portfolioName} with ${selectedCTAs.length} CTAs`);

    try {
        const result = await apiRequest('create_cta_portfolio', {
            portfolio_name: portfolioName,
            portfolio_content: selectedCTAs
        });

        if (result.success && result.portfolio_id) {
            state.selectedPortfolioId = result.portfolio_id;
            state.selectedPortfolioName = portfolioName;

            closeCreatePortfolioModal();

            // Reload portfolios and select the new one
            await loadCtaPortfolios();

            const select = document.getElementById('cta-portfolio-select');
            select.value = result.portfolio_id;
            onPortfolioSelect();

            hideLoading();
            showToast('Portfolio created successfully!', 'success');
            addLog('info', `Portfolio created: ${portfolioName} (ID: ${result.portfolio_id})`);
        } else {
            hideLoading();
            showToast('Failed to create portfolio: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        hideLoading();
        showToast('Error: ' + error.message, 'error');
    }
}

// Load Media Library
async function loadMediaLibrary(forceRefresh = false) {
    try {
        const [videosResult, imagesResult] = await Promise.all([
            apiRequest('get_videos', { force_refresh: forceRefresh }),
            apiRequest('get_images', { force_refresh: forceRefresh })
        ]);

        // Preserve newly uploaded items that may not be in API yet (marked with is_new flag)
        const newItems = state.mediaLibrary.filter(m => m.is_new);

        state.mediaLibrary = [];

        if (videosResult.success && videosResult.data) {
            videosResult.data.forEach(video => {
                // Skip if we already have this video as a new upload
                if (newItems.some(n => n.id === video.video_id)) return;

                state.mediaLibrary.push({
                    type: 'video',
                    id: video.video_id,
                    url: video.video_cover_url || video.preview_url,
                    name: video.file_name || video.video_id,
                    material_id: video.material_id || null
                });
            });
        }

        if (imagesResult.success && imagesResult.data) {
            imagesResult.data.forEach(image => {
                // Skip if we already have this image as a new upload
                if (newItems.some(n => n.id === image.image_id)) return;

                state.mediaLibrary.push({
                    type: 'image',
                    id: image.image_id,
                    url: image.image_url,
                    name: image.file_name || image.image_id
                });
            });
        }

        // Merge new uploads: replace processing_* IDs with real IDs from API (matched by filename)
        const mergedNewItems = newItems.map(item => {
            if (item.id && String(item.id).startsWith('processing_') && item.name) {
                // Look for matching video in API results by filename
                const apiMatch = state.mediaLibrary.find(m =>
                    m.type === 'video' && m.name && m.name === item.name
                );
                if (apiMatch) {
                    console.log(`[Merge] Replaced processing ID ${item.id} with real ID ${apiMatch.id} for ${item.name}`);
                    // Update selectedVideos too
                    const selectedIdx = state.selectedVideos.findIndex(v => v.id === item.id);
                    if (selectedIdx >= 0) {
                        state.selectedVideos[selectedIdx].id = apiMatch.id;
                        state.selectedVideos[selectedIdx].url = apiMatch.url || item.url;
                        delete state.selectedVideos[selectedIdx].is_processing;
                    }
                    // Update creatives too
                    const creativeIdx = state.creatives.findIndex(c => c.video_id === item.id);
                    if (creativeIdx >= 0) {
                        state.creatives[creativeIdx].video_id = apiMatch.id;
                    }
                    // Remove the API duplicate (we're keeping the merged newItem)
                    state.mediaLibrary = state.mediaLibrary.filter(m => m.id !== apiMatch.id);
                    return { ...item, id: apiMatch.id, url: apiMatch.url || item.url, is_processing: false };
                }
            }
            return item;
        });

        // Merge in the new uploads at the beginning (they appear first)
        state.mediaLibrary = [...mergedNewItems, ...state.mediaLibrary];

        // Clear is_new flag after 30 seconds (by then API should have them)
        if (mergedNewItems.length > 0) {
            setTimeout(() => {
                state.mediaLibrary.forEach(m => {
                    if (m.is_new) delete m.is_new;
                });
            }, 30000);
        }

        addLog('info', `Loaded ${state.mediaLibrary.length} media items${forceRefresh ? ' (force refresh)' : ''}`);
        renderVideoSelectionGrid();
        renderImageGrid();
    } catch (error) {
        console.error('Error loading media:', error);
        addLog('error', 'Failed to load media library');
    }
}

// Render Image Grid
function renderImageGrid() {
    const container = document.getElementById('image-selection-grid');
    if (!container) return;

    const images = state.mediaLibrary.filter(m => m.type === 'image');
    const countEl = document.getElementById('images-count');
    if (countEl) countEl.textContent = images.length;

    if (images.length === 0) {
        container.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;">No images found. Upload images to use as video covers.</p>';
        return;
    }

    container.innerHTML = '';

    images.forEach(image => {
        const item = document.createElement('div');
        item.style.cssText = 'border: 2px solid #4fc3f7; border-radius: 10px; overflow: hidden; background: white; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.08); cursor: default;';

        item.innerHTML = `
            <div style="position: relative; aspect-ratio: 1; background: #f5f5f5;">
                ${image.url ? `<img src="${image.url}" alt="${image.name}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div style="display:none; background: linear-gradient(135deg, #4fc3f7, #29b6f6); width: 100%; height: 100%; align-items: center; justify-content: center; color: white; font-size: 28px;">🖼️</div>` : '<div style="background: linear-gradient(135deg, #4fc3f7, #29b6f6); width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 28px;">🖼️</div>'}
                <span style="position: absolute; top: 4px; right: 4px; background: #4fc3f7; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold;">IMG</span>
            </div>
            <div style="padding: 8px 10px; font-size: 11px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #334155;" title="${image.name || 'Image'}">
                ${(image.name || 'Image').substring(0, 20)}${image.name && image.name.length > 20 ? '...' : ''}
            </div>
        `;

        container.appendChild(item);
    });
}

// Refresh Media Library
async function refreshMediaLibrary() {
    showToast('Refreshing media library...', 'info');
    await loadMediaLibrary(true);  // Force refresh to bypass cache
    showToast('Media library refreshed!', 'success');
}

// =====================
// Upload Functionality
// =====================
let currentUploadType = 'video';

function openUploadModal(type) {
    // For video uploads, route through upload options (single vs multi-account)
    if (type === 'video') {
        showUploadOptions();
        return;
    }

    currentUploadType = type;
    const modal = document.getElementById('upload-modal');
    const title = document.getElementById('upload-modal-title');
    const icon = document.getElementById('upload-icon');
    const hint = document.getElementById('upload-hint');
    const fileInput = document.getElementById('media-file-input');

    // Reset modal state
    document.getElementById('upload-area').style.display = 'block';
    document.getElementById('upload-progress').style.display = 'none';
    document.getElementById('upload-success').style.display = 'none';

    title.textContent = '🖼️ Upload Image';
    icon.textContent = '📷';
    hint.textContent = 'Supported: JPG, PNG, GIF (Max 10MB)';
    fileInput.accept = 'image/*';

    modal.style.display = 'flex';
}

function closeUploadModal() {
    document.getElementById('upload-modal').style.display = 'none';
    document.getElementById('media-file-input').value = '';
}

async function handleSmartMediaUpload(event) {
    const files = Array.from(event.target.files);
    if (files.length === 0) return;

    // Validate all files
    const validFiles = [];
    for (const file of files) {
        const isVideo = file.type.startsWith('video/');
        const isImage = file.type.startsWith('image/');

        if (!isImage && !isVideo) {
            showToast(`Skipped ${file.name}: Not a supported file type`, 'warning');
            continue;
        }

        const maxSize = isVideo ? 500 * 1024 * 1024 : 10 * 1024 * 1024;
        if (file.size > maxSize) {
            showToast(`Skipped ${file.name}: Exceeds ${isVideo ? '500MB' : '10MB'} limit`, 'warning');
            continue;
        }

        validFiles.push(file);
    }

    if (validFiles.length === 0) {
        showToast('No valid files selected', 'error');
        event.target.value = '';
        return;
    }

    // Setup progress UI
    document.getElementById('upload-area').style.display = 'none';
    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-success').style.display = 'none';

    const statusEl = document.getElementById('upload-status');
    const countEl = document.getElementById('upload-count');
    const progressBar = document.getElementById('upload-progress-bar');
    const fileList = document.getElementById('upload-file-list');

    if (statusEl) statusEl.textContent = `Uploading ${validFiles.length} file(s)...`;
    if (countEl) countEl.textContent = `0/${validFiles.length}`;
    if (progressBar) progressBar.style.width = '0%';

    // Create file list items with individual progress bars
    if (fileList) {
        fileList.innerHTML = validFiles.map((file, i) => `
            <div class="upload-item" id="smart-upload-item-${i}" data-file-index="${i}" style="display: flex; flex-direction: column; gap: 4px; padding: 8px 10px; background: #f8fafc; border-radius: 6px; margin-bottom: 6px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="upload-item-name" title="${file.name}" style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px;">${file.name}</span>
                    <span class="upload-item-size" style="color: #64748b; font-size: 11px;">${(file.size / 1024 / 1024).toFixed(1)}MB</span>
                    <span class="upload-item-status pending" style="font-weight: 600; font-size: 11px; min-width: 50px; text-align: right;">Pending</span>
                </div>
                <div class="upload-item-progress-container" style="height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                    <div class="upload-item-progress-bar" id="smart-progress-bar-${i}" style="height: 100%; width: 0%; background: linear-gradient(90deg, #3b82f6, #60a5fa); transition: width 0.15s ease;"></div>
                </div>
            </div>
        `).join('');
    }

    let completed = 0;
    let failed = 0;

    // Upload files in PARALLEL BATCHES of 2 for reliability
    const BATCH_SIZE = 2;
    const totalBatches = Math.ceil(validFiles.length / BATCH_SIZE);

    for (let batchIndex = 0; batchIndex < totalBatches; batchIndex++) {
        const startIdx = batchIndex * BATCH_SIZE;
        const batch = validFiles.slice(startIdx, startIdx + BATCH_SIZE);

        addLog('info', `Uploading batch ${batchIndex + 1}/${totalBatches} (${batch.length} files)`);

        // Upload batch in parallel
        const batchResults = await Promise.all(
            batch.map((file, idx) => uploadSingleMediaFile(file, startIdx + idx, validFiles.length))
        );

        // Count results
        batchResults.forEach(result => {
            if (result?.success) {
                completed++;
            } else {
                failed++;
            }
        });

        // Small delay between batches to avoid rate limiting
        if (batchIndex < totalBatches - 1) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }

    // Show completion
    if (failed === 0) {
        if (statusEl) statusEl.textContent = `Successfully uploaded ${completed} file(s)!`;
        showToast(`Uploaded ${completed} file(s) successfully!`, 'success');
    } else if (completed > 0) {
        if (statusEl) statusEl.textContent = `Uploaded ${completed}/${validFiles.length} (${failed} failed)`;
        showToast(`Uploaded ${completed}/${validFiles.length} files (${failed} failed)`, 'warning');
    } else {
        if (statusEl) statusEl.textContent = `Failed to upload all files`;
        showToast('Failed to upload files', 'error');
    }

    // Refresh media library
    setTimeout(async () => {
        await loadMediaLibrary();
    }, 2000);

    // Auto-close after 3 seconds
    setTimeout(() => {
        closeUploadModal();
    }, 3000);

    event.target.value = '';
}

// Helper function to upload a single media file - NO AUTO-RETRY to prevent duplicates
async function uploadSingleMediaFile(file, index, total) {
    const itemId = `smart-upload-item-${index}`;
    const progressBarId = `smart-progress-bar-${index}`;
    const isVideo = file.type.startsWith('video/');
    const uploadTimeout = isVideo ? 300000 : 120000; // 5 min for video, 2 min for image

    // Pre-generate thumbnail for instant preview (if video)
    let previewUrl = '';
    if (isVideo) {
        console.log('[SmartUpload] Pre-generating thumbnail for', file.name);
        previewUrl = await generateVideoThumbnailSafe(file);
        console.log('[SmartUpload] Thumbnail generated:', previewUrl ? 'success' : 'failed');
    }

    updateSmartUploadItemStatus(itemId, 'uploading', '0%', 0);

    // Add timestamp to filename
    const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
    const originalName = file.name;
    const lastDotIndex = originalName.lastIndexOf('.');
    const nameWithoutExt = lastDotIndex > 0 ? originalName.slice(0, lastDotIndex) : originalName;
    const extension = lastDotIndex > 0 ? originalName.slice(lastDotIndex) : '';
    const newFileName = `${nameWithoutExt}_${timestamp}${extension}`;

    // Use FormData with original file, just set the filename
    const formData = new FormData();
    formData.append(isVideo ? 'video' : 'image', file, newFileName);

    addLog('request', `Uploading ${isVideo ? 'video' : 'image'}: ${newFileName}`);

    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        let timeoutId;
        let uploadComplete = false;

        // Real-time progress tracking
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                updateSmartUploadItemStatus(itemId, 'uploading', `${percent}%`, percent);
                if (percent === 100) {
                    uploadComplete = true;
                    updateSmartUploadItemStatus(itemId, 'uploading', 'Processing...', 100);
                }
            }
        });

        // Response handler
        xhr.addEventListener('load', () => {
            clearTimeout(timeoutId);

            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    let result;
                    try {
                        result = JSON.parse(xhr.responseText);
                    } catch (e) {
                        const jsonMatch = xhr.responseText.match(/\{[\s\S]*"success"[\s\S]*\}/);
                        if (jsonMatch) {
                            result = JSON.parse(jsonMatch[0]);
                        } else {
                            throw new Error('Invalid server response');
                        }
                    }

                    // previewUrl is already pre-generated at the start of the function for videos

                    if (result.success && result.data?.video_id) {
                        // Immediate success with video_id
                        updateSmartUploadItemStatus(itemId, 'success', '✓ Uploaded', 100);
                        addLog('success', `Uploaded: ${newFileName} (${result.data.video_id})`);

                        if (isVideo) {
                            const newVideo = {
                                type: 'video',
                                id: result.data.video_id,
                                url: previewUrl,
                                name: newFileName,
                                is_new: true
                            };
                            state.mediaLibrary.unshift(newVideo);
                            // Auto-select the uploaded video
                            if (!state.selectedVideos.some(v => v.id === newVideo.id)) {
                                state.selectedVideos.push(newVideo);
                            }
                            renderVideoSelectionGrid();
                            updateSelectedVideosSummary();
                        }
                        updateOverallProgress(index, total);
                        resolve({ success: true, video_id: result.data.video_id });

                    } else if (result.success && result.processing) {
                        // Video accepted but processing - still count as success!
                        updateSmartUploadItemStatus(itemId, 'processing', '⏳ Processing', 100);
                        addLog('info', `Video accepted, processing: ${newFileName}`);

                        // Add to state with temporary ID for immediate display
                        if (isVideo) {
                            state.mediaLibrary.unshift({
                                type: 'video',
                                id: 'processing_' + Date.now() + '_' + index,
                                url: previewUrl,
                                name: newFileName,
                                is_new: true,
                                is_processing: true
                            });
                            renderVideoSelectionGrid();
                        }
                        updateOverallProgress(index, total);
                        resolve({ success: true, processing: true });

                    } else if (result.success && !isVideo && result.data?.image_id) {
                        // Image upload success
                        updateSmartUploadItemStatus(itemId, 'success', '✓ Uploaded', 100);
                        addLog('success', `Uploaded: ${newFileName}`);

                        state.mediaLibrary.unshift({
                            type: 'image',
                            id: result.data.image_id,
                            url: previewUrl,
                            name: newFileName,
                            is_new: true
                        });
                        renderImageGrid();
                        updateOverallProgress(index, total);
                        resolve({ success: true, image_id: result.data.image_id });

                    } else {
                        // Actual failure
                        const errorMsg = result.message || 'Upload failed';
                        handleUploadError(errorMsg);
                    }
                } catch (e) {
                    handleUploadError('Invalid server response');
                }
            } else {
                handleUploadError(`Server error (${xhr.status})`);
            }
        });

        // Error handler
        xhr.addEventListener('error', () => {
            clearTimeout(timeoutId);
            handleUploadError(uploadComplete ? 'Connection lost - check library' : 'Network error');
        });

        // Abort handler (timeout)
        xhr.addEventListener('abort', () => {
            clearTimeout(timeoutId);
            handleUploadError(uploadComplete ? 'Timeout - check library' : 'Upload timeout');
        });

        function handleUploadError(errorMsg) {
            addLog('error', `Failed: ${file.name} - ${errorMsg}`);
            const shortError = errorMsg.length > 20 ? errorMsg.substring(0, 20) + '...' : errorMsg;
            updateSmartUploadItemStatus(itemId, 'failed', `✗ ${shortError}`, 0);
            resolve({ success: false, error: errorMsg });
        }

        function updateOverallProgress(currentIndex, totalFiles) {
            const countEl = document.getElementById('upload-count');
            const progressBar = document.getElementById('upload-progress-bar');
            const completed = currentIndex + 1;
            if (countEl) countEl.textContent = `${completed}/${totalFiles}`;
            if (progressBar) progressBar.style.width = `${(completed / totalFiles) * 100}%`;
        }

        // Set timeout
        timeoutId = setTimeout(() => xhr.abort(), uploadTimeout);

        // Send request
        xhr.open('POST', `api.php?action=${isVideo ? 'upload_video' : 'upload_image'}`);
        xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || '');
        xhr.send(formData);
    });
}

// Helper to update individual upload item status in Step 3 modal with progress bar
function updateSmartUploadItemStatus(itemId, status, text, progress = null) {
    const item = document.getElementById(itemId);
    if (!item) return;

    const statusEl = item.querySelector('.upload-item-status');
    if (statusEl) {
        statusEl.className = `upload-item-status ${status}`;
        statusEl.textContent = text;

        // Update status colors
        if (status === 'uploading') {
            statusEl.style.color = '#3b82f6';
        } else if (status === 'success') {
            statusEl.style.color = '#22c55e';
        } else if (status === 'failed') {
            statusEl.style.color = '#ef4444';
        } else if (status === 'processing') {
            statusEl.style.color = '#f59e0b';
        } else {
            statusEl.style.color = '#64748b';
        }
    }

    // Update progress bar
    const index = item.dataset.fileIndex;
    const progressBar = document.getElementById(`smart-progress-bar-${index}`);
    if (progressBar && progress !== null) {
        progressBar.style.width = `${progress}%`;

        // Update progress bar color based on status
        if (status === 'success') {
            progressBar.style.background = 'linear-gradient(90deg, #22c55e, #4ade80)';
        } else if (status === 'failed') {
            progressBar.style.background = 'linear-gradient(90deg, #ef4444, #f87171)';
        } else if (status === 'processing') {
            progressBar.style.background = 'linear-gradient(90deg, #f59e0b, #fbbf24)';
        } else {
            progressBar.style.background = 'linear-gradient(90deg, #3b82f6, #60a5fa)';
        }
    }
}

// =====================
// Location Targeting
// =====================
function initializeLocationTargeting() {
    const grid = document.getElementById('states-grid');
    if (!grid) return;

    grid.innerHTML = '';

    US_STATES.forEach(stateItem => {
        const item = document.createElement('div');
        item.className = 'state-item';
        item.innerHTML = `
            <label>
                <input type="checkbox" class="state-checkbox" value="${stateItem.id}" data-name="${stateItem.name}" checked>
                <span>${stateItem.abbr} - ${stateItem.name}</span>
            </label>
        `;
        grid.appendChild(item);
    });

    // Add change event listener to all state checkboxes to update count
    document.querySelectorAll('.state-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateStatesCount);
    });

    updateStatesCount();
}

function toggleLocationMethod() {
    const method = document.querySelector('input[name="location_method"]:checked').value;
    document.getElementById('country-targeting').style.display = method === 'country' ? 'block' : 'none';
    document.getElementById('states-targeting').style.display = method === 'states' ? 'block' : 'none';

    // When switching to specific states, clear all selections by default
    if (method === 'states') {
        clearAllStates();
    }
}

function selectAllStates() {
    document.querySelectorAll('.state-checkbox').forEach(cb => cb.checked = true);
    updateStatesCount();
}

function clearAllStates() {
    document.querySelectorAll('.state-checkbox').forEach(cb => cb.checked = false);
    updateStatesCount();
}

// ============================================
// SCHEDULE FUNCTIONS
// ============================================

// Toggle schedule type UI (continuous vs scheduled_start_only vs scheduled)
function toggleScheduleType() {
    const scheduleType = document.querySelector('input[name="schedule_type"]:checked')?.value || 'continuous';
    const startOnlyContainer = document.getElementById('schedule-start-only-container');
    const dateTimeContainer = document.getElementById('schedule-datetime-container');
    const scheduleOptions = document.querySelectorAll('.schedule-option');

    // Hide both containers first
    if (startOnlyContainer) {
        startOnlyContainer.style.display = 'none';
    }
    if (dateTimeContainer) {
        dateTimeContainer.style.display = 'none';
    }

    // Show appropriate container based on selection
    if (scheduleType === 'scheduled_start_only' && startOnlyContainer) {
        startOnlyContainer.style.display = 'block';
    } else if (scheduleType === 'scheduled' && dateTimeContainer) {
        dateTimeContainer.style.display = 'block';
    }

    // Update border styling to show selected option
    scheduleOptions.forEach(option => {
        const radio = option.querySelector('input[type="radio"]');
        if (radio && radio.checked) {
            option.style.borderColor = '#1a1a1a';
        } else {
            option.style.borderColor = '#e2e8f0';
        }
    });

    // Set default start time if not set (EST now + 1 hour)
    if (scheduleType === 'scheduled_start_only') {
        const startInput = document.getElementById('schedule-start-only-datetime');

        if (startInput) {
            // Set min to EST now + 7 minutes
            const minTime = getESTNow();
            minTime.setMinutes(minTime.getMinutes() + 7);
            startInput.min = formatDateTimeLocal(minTime);

            if (!startInput.value) {
                const estNow = getESTNow();
                estNow.setHours(estNow.getHours() + 1);
                estNow.setMinutes(0, 0, 0);
                startInput.value = formatDateTimeLocal(estNow);
            }
        }
    } else if (scheduleType === 'scheduled') {
        const startInput = document.getElementById('schedule-start-datetime');
        const endInput = document.getElementById('schedule-end-datetime');

        if (startInput) {
            // Set min to EST now + 7 minutes
            const minTime = getESTNow();
            minTime.setMinutes(minTime.getMinutes() + 7);
            startInput.min = formatDateTimeLocal(minTime);

            if (!startInput.value) {
                const estNow = getESTNow();
                estNow.setHours(estNow.getHours() + 1);
                estNow.setMinutes(0, 0, 0);
                startInput.value = formatDateTimeLocal(estNow);
            }
        }

        if (endInput && !endInput.value) {
            const endDate = getESTNow();
            endDate.setDate(endDate.getDate() + 7); // Default: 1 week from now
            endDate.setHours(23, 59, 0, 0);
            endInput.value = formatDateTimeLocal(endDate);
        }
    }
}

// Format date for datetime-local input
function formatDateTimeLocal(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Get current time in EST (America/New_York) as a Date object
// Used for defaults and validation so calendar shows EST date, not browser local date
function getESTNow() {
    const now = new Date();
    const estString = now.toLocaleString('en-US', { timeZone: 'America/New_York' });
    return new Date(estString);
}

// Get schedule data for API
function getScheduleData() {
    const scheduleType = document.querySelector('input[name="schedule_type"]:checked')?.value || 'continuous';

    // Format datetime for TikTok API
    // Format datetime for TikTok API — user enters EST time
    const formatScheduleTime = (dateTimeLocalValue) => {
        if (!dateTimeLocalValue) return null;
        const [datePart, timePart] = dateTimeLocalValue.split('T');
        const result = `${datePart} ${timePart}:00`;
        console.log(`[Schedule] Formatted for API (EST): ${dateTimeLocalValue} -> ${result}`);
        return result;
    };

    if (scheduleType === 'continuous') {
        return {
            schedule_type: 'SCHEDULE_FROM_NOW'
        };
    }

    // Option 2: Schedule start time only (no end) - runs continuously from scheduled time
    if (scheduleType === 'scheduled_start_only') {
        const startDateTime = document.getElementById('schedule-start-only-datetime')?.value;

        if (!startDateTime) {
            return {
                schedule_type: 'SCHEDULE_FROM_NOW'
            };
        }

        return {
            schedule_type: 'SCHEDULE_FROM_NOW',
            schedule_start_time: formatScheduleTime(startDateTime),
        };
    }

    // Option 3: Schedule start AND end time
    const startDateTime = document.getElementById('schedule-start-datetime')?.value;
    const endDateTime = document.getElementById('schedule-end-datetime')?.value;

    if (!startDateTime || !endDateTime) {
        return {
            schedule_type: 'SCHEDULE_FROM_NOW'
        };
    }

    return {
        schedule_type: 'SCHEDULE_START_END',
        schedule_start_time: formatScheduleTime(startDateTime),
        schedule_end_time: formatScheduleTime(endDateTime),
    };
}

// Validate schedule dates
function validateScheduleDates() {
    const scheduleType = document.querySelector('input[name="schedule_type"]:checked')?.value || 'continuous';

    if (scheduleType === 'continuous') {
        return { valid: true };
    }

    const now = getESTNow();

    // Validate scheduled_start_only option
    if (scheduleType === 'scheduled_start_only') {
        const startDateTime = document.getElementById('schedule-start-only-datetime')?.value;

        if (!startDateTime) {
            return { valid: false, message: 'Please select a start date and time' };
        }

        const startDate = new Date(startDateTime);

        if (startDate < now) {
            return { valid: false, message: 'Start time must be in the future' };
        }

        return { valid: true };
    }

    // Validate scheduled (start and end) option
    const startDateTime = document.getElementById('schedule-start-datetime')?.value;
    const endDateTime = document.getElementById('schedule-end-datetime')?.value;

    if (!startDateTime) {
        return { valid: false, message: 'Please select a start date and time' };
    }

    if (!endDateTime) {
        return { valid: false, message: 'Please select an end date and time' };
    }

    const startDate = new Date(startDateTime);
    const endDate = new Date(endDateTime);

    if (startDate < now) {
        return { valid: false, message: 'Start time must be in the future' };
    }

    if (endDate <= startDate) {
        return { valid: false, message: 'End time must be after start time' };
    }

    // Minimum 1 hour duration
    const durationHours = (endDate - startDate) / (1000 * 60 * 60);
    if (durationHours < 1) {
        return { valid: false, message: 'Ad group must run for at least 1 hour' };
    }

    return { valid: true };
}

// Legacy function - no longer used but kept for compatibility
function applySchedule(type) {
    // Schedule is now applied automatically when user selects date/time
    // No separate apply button needed
}

// Legacy function - no longer used
function showSchedulePending(type) {
    // No longer needed with simple datetime picker
}

// Legacy function - no longer used
function updateScheduleSummary() {
    // No longer needed with simple datetime picker
}

// Format datetime for readable display
function formatReadableDateTime(date) {
    const options = {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    };
    return date.toLocaleString('en-US', options);
}

function updateStatesCount() {
    const count = document.querySelectorAll('.state-checkbox:checked').length;
    const countEl = document.getElementById('selected-states-count');
    if (countEl) countEl.textContent = count;
}

// Apply bulk states from input box - ADDS to existing selection (doesn't clear)
function applyBulkStates() {
    const input = document.getElementById('bulk-state-input');
    const feedback = document.getElementById('bulk-state-feedback');

    if (!input || !input.value.trim()) {
        showBulkStateFeedback('Please enter state names or abbreviations', 'error');
        return;
    }

    const inputText = input.value.trim();

    // Split by comma, semicolon, or newline — strip trailing punctuation (e.g. "Kentucky.")
    const stateInputs = inputText.split(/[,;\n]+/)
        .map(s => s.trim().replace(/[.\-!?;:]+$/, '').trim().toLowerCase())
        .filter(s => s.length > 0);

    if (stateInputs.length === 0) {
        showBulkStateFeedback('No valid state names found', 'error');
        return;
    }

    let newlySelectedCount = 0;
    let alreadySelectedCount = 0;
    let notFoundStates = [];

    // Get current selection count before adding
    const previousCount = document.querySelectorAll('.state-checkbox:checked').length;

    // Match and ADD states to existing selection (don't clear!)
    stateInputs.forEach(stateInput => {
        let matchedState = null;

        // Pass 1: Exact name or abbreviation match (highest priority)
        for (const state of US_STATES) {
            if (state.name.toLowerCase() === stateInput || state.abbr.toLowerCase() === stateInput) {
                matchedState = state;
                break;
            }
        }

        // Pass 2: startsWith match (e.g. "south" → "South Carolina")
        if (!matchedState) {
            for (const state of US_STATES) {
                if (state.name.toLowerCase().startsWith(stateInput)) {
                    matchedState = state;
                    break;
                }
            }
        }

        // Pass 3: includes match (e.g. "dakota" → "North Dakota") — last resort
        if (!matchedState) {
            for (const state of US_STATES) {
                if (state.name.toLowerCase().includes(stateInput)) {
                    matchedState = state;
                    break;
                }
            }
        }

        if (matchedState) {
            const checkbox = document.querySelector(`.state-checkbox[value="${matchedState.id}"]`);
            if (checkbox) {
                if (checkbox.checked) {
                    alreadySelectedCount++;
                } else {
                    checkbox.checked = true;
                    newlySelectedCount++;
                }
            }
        } else {
            notFoundStates.push(stateInput);
        }
    });

    // Update count display
    updateStatesCount();

    // Get new total
    const newTotal = document.querySelectorAll('.state-checkbox:checked').length;

    // Show feedback
    if (newlySelectedCount > 0 || alreadySelectedCount > 0) {
        let message = '';
        if (newlySelectedCount > 0) {
            message = `Added ${newlySelectedCount} new state${newlySelectedCount > 1 ? 's' : ''}`;
        }
        if (alreadySelectedCount > 0) {
            if (message) message += ', ';
            message += `${alreadySelectedCount} already selected`;
        }
        message += `. Total: ${newTotal} states`;

        if (notFoundStates.length > 0) {
            message += `. Not found: ${notFoundStates.join(', ')}`;
            showBulkStateFeedback(message, 'warning');
        } else {
            showBulkStateFeedback(message, 'success');
        }
        // Clear input on success
        input.value = '';
    } else {
        showBulkStateFeedback(`No matching states found for: ${stateInputs.join(', ')}`, 'error');
    }
}

// Show feedback for bulk state input
function showBulkStateFeedback(message, type) {
    const feedback = document.getElementById('bulk-state-feedback');
    if (!feedback) return;

    feedback.style.display = 'block';
    feedback.textContent = message;

    // Set styles based on type
    if (type === 'success') {
        feedback.style.background = '#e8f5e9';
        feedback.style.color = '#2e7d32';
        feedback.style.border = '1px solid #a5d6a7';
    } else if (type === 'warning') {
        feedback.style.background = '#fff3e0';
        feedback.style.color = '#ef6c00';
        feedback.style.border = '1px solid #ffcc80';
    } else {
        feedback.style.background = '#ffebee';
        feedback.style.color = '#c62828';
        feedback.style.border = '1px solid #ef9a9a';
    }

    // Hide after 5 seconds
    setTimeout(() => {
        feedback.style.display = 'none';
    }, 5000);
}

function getSelectedLocationIds() {
    const method = document.querySelector('input[name="location_method"]:checked').value;
    console.log('Location method:', method);

    if (method === 'country') {
        console.log('Targeting entire US: [6252001]');
        return ['6252001'];
    }

    const selected = [];
    document.querySelectorAll('.state-checkbox:checked').forEach(cb => {
        selected.push(cb.value);
    });

    const result = selected.length > 0 ? selected : ['6252001'];
    console.log('Selected state location IDs:', result.length, 'states', result);
    return result;
}

// =====================
// Dayparting
// =====================
function initializeDayparting() {
    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const tbody = document.getElementById('dayparting-body');
    if (!tbody) return;

    tbody.innerHTML = '';

    // Hour labels for tooltips (12-hour format)
    const hourLabels = [
        '12:00 AM', '1:00 AM', '2:00 AM', '3:00 AM', '4:00 AM', '5:00 AM',
        '6:00 AM', '7:00 AM', '8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM',
        '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM',
        '6:00 PM', '7:00 PM', '8:00 PM', '9:00 PM', '10:00 PM', '11:00 PM'
    ];

    days.forEach((day, dayIndex) => {
        const tr = document.createElement('tr');
        const dayCell = document.createElement('td');
        dayCell.innerHTML = `<strong>${day}</strong>`;
        tr.appendChild(dayCell);

        // Create exactly 24 checkboxes (hours 0-23)
        for (let hour = 0; hour < 24; hour++) {
            const td = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'hour-checkbox';
            checkbox.dataset.day = dayIndex;
            checkbox.dataset.hour = hour;
            // Show range: e.g., "Monday 9:00 AM - 10:00 AM"
            const nextHour = (hour + 1) % 24;
            checkbox.title = `${day} ${hourLabels[hour]} - ${hourLabels[nextHour]}`;
            checkbox.checked = false;
            td.appendChild(checkbox);
            tr.appendChild(td);
        }

        tbody.appendChild(tr);
    });
}

function toggleDayparting() {
    const enabled = document.getElementById('enable-dayparting').checked;
    document.getElementById('dayparting-section').style.display = enabled ? 'block' : 'none';
}

// Dayparting preset function with clear time descriptions
// Note: Each hour checkbox represents a 1-hour slot (e.g., hour 9 = 9:00 AM - 10:00 AM)
// To end at 5:00 PM, select hours up to 16 (the 4:00-5:00 PM slot is the last one)
function setDaypartingPreset(preset) {
    const checkboxes = document.querySelectorAll('.hour-checkbox');

    // Clear all first
    checkboxes.forEach(cb => cb.checked = false);

    checkboxes.forEach(cb => {
        const hour = parseInt(cb.dataset.hour);
        const day = parseInt(cb.dataset.day); // 0=Sunday, 1=Monday, ..., 6=Saturday

        switch(preset) {
            case 'all':
                // All hours, all days (24/7)
                cb.checked = true;
                break;

            case 'business':
                // Business Hours: 8AM-5PM (hours 8-16), Monday-Friday (days 1-5)
                // Hour 8 = 8:00-9:00 AM, Hour 16 = 4:00-5:00 PM (ends at 5 PM)
                cb.checked = (day >= 1 && day <= 5 && hour >= 8 && hour <= 16);
                break;

            case 'office':
                // Office Hours: 9AM-5PM (hours 9-16), Monday-Friday (days 1-5)
                // Hour 9 = 9:00-10:00 AM, Hour 16 = 4:00-5:00 PM (ends at 5 PM)
                cb.checked = (day >= 1 && day <= 5 && hour >= 9 && hour <= 16);
                break;

            case 'prime':
                // Prime Time: 6PM-11PM (hours 18-22), all days
                // Hour 18 = 6:00-7:00 PM, Hour 22 = 10:00-11:00 PM (ends at 11 PM)
                cb.checked = (hour >= 18 && hour <= 22);
                break;

            case 'evening':
                // Evening: 5PM-12AM (hours 17-23), all days
                // Hour 17 = 5:00-6:00 PM, Hour 23 = 11:00 PM-12:00 AM (ends at midnight)
                cb.checked = (hour >= 17 && hour <= 23);
                break;

            case 'daytime':
                // Daytime: 6AM-6PM (hours 6-17), all days
                // Hour 6 = 6:00-7:00 AM, Hour 17 = 5:00-6:00 PM (ends at 6 PM)
                cb.checked = (hour >= 6 && hour <= 17);
                break;

            case 'none':
                // Clear All - already handled above
                break;
        }
    });

    // Log the preset selection
    const presetNames = {
        'all': '24/7 (All Hours)',
        'business': 'Business Hours (8AM-5PM, Mon-Fri)',
        'office': 'Office Hours (9AM-5PM, Mon-Fri)',
        'prime': 'Prime Time (6PM-11PM)',
        'evening': 'Evening (5PM-12AM)',
        'daytime': 'Daytime (6AM-6PM)',
        'none': 'Cleared All'
    };
    addLog('info', `Dayparting preset: ${presetNames[preset] || preset}`);
}

// Legacy functions for backward compatibility
function selectAllHours() { setDaypartingPreset('all'); }
function clearAllHours() { setDaypartingPreset('none'); }
function selectBusinessHours() { setDaypartingPreset('business'); }
function selectPrimeTime() { setDaypartingPreset('prime'); }

function getDaypartingData() {
    if (!document.getElementById('enable-dayparting').checked) {
        return null;
    }

    let dayparting = '';
    const dayOrder = [1, 2, 3, 4, 5, 6, 0];

    dayOrder.forEach(dayIndex => {
        for (let hour = 0; hour < 24; hour++) {
            const checkbox = document.querySelector(`.hour-checkbox[data-day="${dayIndex}"][data-hour="${hour}"]`);
            const isChecked = checkbox ? checkbox.checked : false;
            dayparting += isChecked ? '11' : '00';
        }
    });

    return dayparting;
}

// =====================
// Step Navigation
// =====================

// Initialize clickable step indicators
function initializeStepNavigation() {
    document.querySelectorAll('.step').forEach((step) => {
        step.style.cursor = 'pointer';
        step.addEventListener('click', () => {
            const stepNum = parseInt(step.dataset.step);
            // Allow clicking on completed steps or the current step
            // Don't allow jumping ahead to steps that haven't been reached
            if (canNavigateToStep(stepNum)) {
                goToStep(stepNum);
            } else {
                showToast('Please complete the current step first', 'info');
            }
        });
    });
}

// Check if user can navigate to a specific step
function canNavigateToStep(stepNumber) {
    // Can always go back to previous steps
    if (stepNumber <= state.currentStep) {
        return true;
    }

    // Can go to step 2 if campaign is created
    if (stepNumber === 2 && state.campaignCreated) {
        return true;
    }

    // Can go to step 3 if ad group is created
    if (stepNumber === 3 && state.adGroupCreated) {
        return true;
    }

    // Can go to step 4 if we have selected videos
    if (stepNumber === 4 && state.selectedVideos.length > 0) {
        return true;
    }

    return false;
}

function goToStep(stepNumber) {
    document.querySelectorAll('.step').forEach((step, index) => {
        step.classList.remove('active', 'completed');
        if (index + 1 < stepNumber) {
            step.classList.add('completed');
        } else if (index + 1 === stepNumber) {
            step.classList.add('active');
        }
    });

    document.querySelectorAll('.step-content').forEach((content, index) => {
        content.classList.remove('active');
        if (index + 1 === stepNumber) {
            content.classList.add('active');
        }
    });

    state.currentStep = stepNumber;
    updateStepButtonLabels();  // Update button labels when navigating
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function nextStep() {
    if (state.currentStep < 4) {
        goToStep(state.currentStep + 1);
    }
}

function prevStep() {
    if (state.currentStep > 1) {
        goToStep(state.currentStep - 1);
    }
}

// =====================
// STEP 1: CREATE OR UPDATE CAMPAIGN
// =====================
async function createCampaign() {
    const campaignName = document.getElementById('campaign-name').value.trim();
    const campaignBudget = parseFloat(document.getElementById('campaign-budget').value) || 50;

    // Validate campaign name
    const nameValidation = Validation.validateCampaignName(campaignName);
    if (!nameValidation.valid) {
        Validation.showFieldError('campaign-name', nameValidation.message);
        showToast(nameValidation.message, 'error');
        return;
    }

    // Validate budget
    const budgetValidation = Validation.validateBudget(campaignBudget);
    if (!budgetValidation.valid) {
        Validation.showFieldError('campaign-budget', budgetValidation.message);
        showToast(budgetValidation.message, 'error');
        return;
    }

    // Check if we should UPDATE instead of CREATE
    if (state.campaignCreated && state.campaignId) {
        return await updateCampaign();
    }

    showLoading('Creating Campaign...');
    addLog('info', '=== Creating Smart+ Campaign ===');

    try {
        // Smart+ campaigns require budget at campaign level with CBO
        const result = await apiRequest('create_smartplus_campaign', {
            campaign_name: campaignName,
            budget: campaignBudget
        });

        hideLoading();

        if (result.success && result.campaign_id) {
            state.campaignId = result.campaign_id;
            state.campaignName = campaignName;
            state.budget = campaignBudget;
            state.campaignCreated = true;  // Mark as created

            // Update display
            const displayNameEl = document.getElementById('display-campaign-name');
            const displayCampaignIdEl = document.getElementById('display-campaign-id');
            const displayBudgetEl = document.getElementById('display-budget');

            if (displayNameEl) displayNameEl.textContent = campaignName;
            if (displayCampaignIdEl) displayCampaignIdEl.textContent = result.campaign_id;
            if (displayBudgetEl) displayBudgetEl.textContent = campaignBudget;

            // Update button text
            updateStepButtonLabels();

            showToast(`Campaign created (PAUSED)! ID: ${result.campaign_id}`, 'success');
            addLog('info', `Campaign created (disabled): ${result.campaign_id}`);
            nextStep();
        } else {
            const friendlyError = Validation.getFriendlyError(result);
            showToast(friendlyError, 'error');
        }
    } catch (error) {
        hideLoading();
        const friendlyError = Validation.getFriendlyError({ message: error.message });
        showToast(friendlyError, 'error');
    }
}

// UPDATE existing campaign
async function updateCampaign() {
    const campaignName = document.getElementById('campaign-name').value.trim();
    const campaignBudget = parseFloat(document.getElementById('campaign-budget').value) || 50;

    showLoading('Updating Campaign...');
    addLog('info', '=== Updating Smart+ Campaign ===');

    try {
        // Smart+ campaigns require budget at campaign level with CBO
        const result = await apiRequest('update_smartplus_campaign', {
            campaign_id: state.campaignId,
            campaign_name: campaignName,
            budget: campaignBudget
        });

        hideLoading();

        if (result.success) {
            state.campaignName = campaignName;
            state.budget = campaignBudget;

            // Update display
            const displayNameEl = document.getElementById('display-campaign-name');
            const displayBudgetEl = document.getElementById('display-budget');
            if (displayNameEl) displayNameEl.textContent = campaignName;
            if (displayBudgetEl) displayBudgetEl.textContent = campaignBudget;

            showToast('Campaign updated successfully!', 'success');
            addLog('info', `Campaign updated: ${state.campaignId}`);
            nextStep();
        } else {
            showToast('Failed to update campaign: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        hideLoading();
        showToast('Error updating campaign: ' + error.message, 'error');
    }
}

// =====================
// STEP 2: CREATE OR UPDATE AD GROUP
// NOTE: pixel_id and optimization_event CANNOT be updated!
// If they change, we must DELETE and recreate the ad group.
// =====================
async function createAdGroup() {
    if (!state.campaignId) {
        showToast('Campaign not created yet. Please go back and create campaign first.', 'error');
        return;
    }

    const pixelId = document.getElementById('pixel-select').value;
    const optimizationEvent = document.getElementById('optimization-event').value;
    const locationIds = getSelectedLocationIds();
    const dayparting = getDaypartingData();

    // Log location targeting for debugging
    addLog('info', `Location targeting: ${locationIds.length} location(s) selected`);
    console.log('Location IDs being sent to API:', locationIds);

    // Budget is at Campaign level for Smart+ campaigns (CBO enabled)
    // AdGroup uses BUDGET_MODE_INFINITE

    if (!pixelId) {
        showToast('Please select a pixel', 'error');
        return;
    }

    // Validate schedule dates if using scheduled option
    const scheduleValidation = validateScheduleDates();
    if (!scheduleValidation.valid) {
        showToast(scheduleValidation.message, 'error');
        return;
    }

    // Get schedule data
    const scheduleData = getScheduleData();
    addLog('info', `Schedule type: ${scheduleData.schedule_type}`);
    if (scheduleData.schedule_start_time) {
        addLog('info', `Schedule: ${scheduleData.schedule_start_time} to ${scheduleData.schedule_end_time}`);
    }

    // Check if ad group already exists - determine if UPDATE or DELETE+CREATE needed
    if (state.adGroupCreated && state.adGroupId) {
        // Check if pixel or optimization event changed - these CANNOT be updated!
        const pixelChanged = state.previousPixelId && state.previousPixelId !== pixelId;
        const optEventChanged = state.previousOptEvent && state.previousOptEvent !== optimizationEvent;

        if (pixelChanged || optEventChanged) {
            // Must delete and recreate
            addLog('info', 'Pixel or optimization event changed - deleting existing ad group');
            const deleteSuccess = await deleteAdGroup();
            if (!deleteSuccess) {
                showToast('Failed to delete existing ad group. Please try again.', 'error');
                return;
            }
            // Reset state and continue with create
            state.adGroupCreated = false;
            state.adGroupId = null;
            state.adCreated = false;  // Ad also needs to be recreated
            state.adId = null;
        } else {
            // Only other fields changed - use update
            return await updateAdGroup(locationIds, dayparting);
        }
    }

    showLoading('Creating Ad Group...');
    addLog('info', '=== Creating Smart+ Ad Group ===');

    try {
        // For Smart+ campaigns: budget is at Campaign level (CBO), AdGroup uses BUDGET_MODE_INFINITE
        const result = await apiRequest('create_smartplus_adgroup', {
            campaign_id: state.campaignId,
            adgroup_name: state.campaignName + ' - Ad Group',
            pixel_id: pixelId,
            optimization_event: optimizationEvent,
            location_ids: locationIds,
            age_groups: state.ageGroups,  // Age targeting
            dayparting: dayparting,
            // Note: No budget - it's at campaign level for Smart+ campaigns
            // Schedule parameters
            schedule_type: scheduleData.schedule_type,
            schedule_start_time: scheduleData.schedule_start_time || null,
            schedule_end_time: scheduleData.schedule_end_time || null
        });

        hideLoading();

        if (result.success && result.adgroup_id) {
            state.adGroupId = result.adgroup_id;
            state.pixelId = pixelId;
            state.optimizationEvent = optimizationEvent;
            state.locationIds = locationIds;
            state.dayparting = dayparting;
            state.adGroupCreated = true;  // Mark as created

            // Store previous values for change detection
            state.previousPixelId = pixelId;
            state.previousOptEvent = optimizationEvent;

            // Update display
            const displayAdGroupIdEl = document.getElementById('display-adgroup-id');
            if (displayAdGroupIdEl) displayAdGroupIdEl.textContent = result.adgroup_id;

            // Also update campaign ID in step 3
            const displayCampaignIdStep3 = document.getElementById('display-campaign-id-step3');
            if (displayCampaignIdStep3) displayCampaignIdStep3.textContent = state.campaignId;

            // Update button labels
            updateStepButtonLabels();

            showToast(`Ad Group created! ID: ${result.adgroup_id}`, 'success');
            addLog('info', `Ad Group created: ${result.adgroup_id}`);
            nextStep();
        } else {
            showToast('Failed to create ad group: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        hideLoading();
        showToast('Error creating ad group: ' + error.message, 'error');
    }
}

// UPDATE existing ad group (for fields that can be updated)
// Note: Budget is at campaign level for Smart+ campaigns, not updatable here
async function updateAdGroup(locationIds, dayparting) {
    showLoading('Updating Ad Group...');
    addLog('info', '=== Updating Smart+ Ad Group ===');

    try {
        const result = await apiRequest('update_smartplus_adgroup', {
            adgroup_id: state.adGroupId,
            targeting_spec: {
                location_ids: locationIds,
                age_groups: state.ageGroups
            },
            dayparting: dayparting
        });

        hideLoading();

        if (result.success) {
            state.locationIds = locationIds;
            state.dayparting = dayparting;

            showToast('Ad Group updated successfully!', 'success');
            addLog('info', `Ad Group updated: ${state.adGroupId}`);
            nextStep();
        } else {
            showToast('Failed to update ad group: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        hideLoading();
        showToast('Error updating ad group: ' + error.message, 'error');
    }
}

// DELETE ad group (needed when pixel/optimization_event changes)
async function deleteAdGroup() {
    addLog('info', '=== Deleting Smart+ Ad Group ===');

    try {
        const result = await apiRequest('delete_smartplus_adgroup', {
            adgroup_id: state.adGroupId
        });

        if (result.success) {
            addLog('info', `Ad Group deleted: ${state.adGroupId}`);
            return true;
        } else {
            addLog('error', 'Failed to delete ad group: ' + (result.message || 'Unknown error'));
            return false;
        }
    } catch (error) {
        addLog('error', 'Error deleting ad group: ' + error.message);
        return false;
    }
}

// =====================
// STEP 3: Select Videos and Create Creatives
// =====================
function renderVideoSelectionGrid() {
    const container = document.getElementById('video-selection-grid');
    if (!container) return;

    const videos = state.mediaLibrary.filter(m => m.type === 'video');

    if (videos.length === 0) {
        container.innerHTML = '<p style="text-align: center; padding: 20px;">No videos found. Please upload videos via TikTok Ads Manager.</p>';
        return;
    }

    container.innerHTML = '';

    videos.forEach(video => {
        const isSelected = state.selectedVideos.some(v => v.id === video.id);
        const item = document.createElement('div');
        item.className = `video-select-item ${isSelected ? 'selected' : ''}`;
        item.onclick = () => toggleVideoSelection(video);

        item.innerHTML = `
            <div class="video-preview">
                ${video.url ? `<img src="${video.url}" alt="${video.name}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="no-preview" style="display:none;">No Preview</div>` : '<div class="no-preview">No Preview</div>'}
                ${isSelected ? '<span class="selected-badge">✓</span>' : ''}
            </div>
            <div class="video-name" title="${video.name || ''}">${(video.name || '').substring(0, 25)}${video.name && video.name.length > 25 ? '...' : ''}</div>
        `;

        container.appendChild(item);
    });

    updateSelectedVideosCount();
}

function toggleVideoSelection(video) {
    const index = state.selectedVideos.findIndex(v => v.id === video.id);
    if (index >= 0) {
        state.selectedVideos.splice(index, 1);
    } else {
        state.selectedVideos.push(video);
    }
    renderVideoSelectionGrid();
    updateSelectedVideosSummary();
}

function selectAllVideos() {
    const videos = state.mediaLibrary.filter(m => m.type === 'video');
    state.selectedVideos = [...videos];
    renderVideoSelectionGrid();
    updateSelectedVideosSummary();
}

function clearVideoSelection() {
    state.selectedVideos = [];
    renderVideoSelectionGrid();
    updateSelectedVideosSummary();
}

function updateSelectedVideosCount() {
    const countEl = document.getElementById('selected-videos-count');
    if (countEl) countEl.textContent = state.selectedVideos.length;

    // Also update the creative assets count
    const assetsCountEl = document.getElementById('creative-assets-count');
    if (assetsCountEl) assetsCountEl.textContent = state.selectedVideos.length;
}

// Update the selected videos summary (preview thumbnails)
function updateSelectedVideosSummary() {
    const previewContainer = document.getElementById('selected-videos-preview');
    const assetsCountEl = document.getElementById('creative-assets-count');

    if (assetsCountEl) assetsCountEl.textContent = state.selectedVideos.length;

    if (!previewContainer) return;

    if (state.selectedVideos.length === 0) {
        previewContainer.innerHTML = '<p style="color: #666; font-size: 13px;">No videos selected yet</p>';
        return;
    }

    previewContainer.innerHTML = '';

    state.selectedVideos.forEach((video, index) => {
        const item = document.createElement('div');
        item.style.cssText = 'position: relative; width: 80px; height: 80px; border-radius: 8px; overflow: hidden; border: 2px solid #667eea; flex-shrink: 0;';
        item.innerHTML = `
            ${video.url ? `<img src="${video.url}" style="width: 100%; height: 100%; object-fit: cover;">` : '<div style="background: linear-gradient(135deg, #667eea, #764ba2); width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px;">🎬</div>'}
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.5); border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">
                <span style="color: white; font-size: 10px;">▶</span>
            </div>
            <div style="position: absolute; bottom: 2px; left: 2px; right: 2px; background: rgba(0,0,0,0.7); padding: 2px 4px; border-radius: 3px;">
                <span style="color: white; font-size: 9px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${(video.name || 'Video').substring(0, 10)}</span>
            </div>
        `;
        previewContainer.appendChild(item);
    });

    // Update creatives state
    state.creatives = state.selectedVideos.map(video => {
        const existing = state.creatives.find(c => c.video_id === video.id);
        const matchingImage = state.mediaLibrary.find(m =>
            m.type === 'image' &&
            m.name && video.name &&
            m.name.includes(video.id.replace('v10033g50000', ''))
        );
        return {
            video_id: video.id,
            video_name: video.name,
            video_url: video.url,
            material_id: video.material_id,
            image_id: matchingImage?.id || null,
            ad_text: existing?.ad_text || ''
        };
    });
}

// Scroll to media section when clicking Edit selections
function scrollToMediaSection() {
    const mediaSection = document.getElementById('video-selection-grid');
    if (mediaSection) {
        mediaSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Ad Text Field Management
let adTextFieldCount = 1;

function addAdTextField() {
    adTextFieldCount++;
    const container = document.getElementById('ad-text-fields');

    const fieldDiv = document.createElement('div');
    fieldDiv.className = 'ad-text-field';
    fieldDiv.style.cssText = 'display: flex; align-items: center; gap: 10px;';
    fieldDiv.id = `ad-text-field-${adTextFieldCount}`;
    fieldDiv.innerHTML = `
        <input type="text" id="ad-text-${adTextFieldCount}" class="ad-text-input" placeholder="Enter text for your ad" maxlength="100" style="flex: 1;" oninput="updateTextCount(this)">
        <span class="text-count" style="color: #999; font-size: 12px;">0/100</span>
        <button type="button" onclick="removeAdTextField(${adTextFieldCount})" style="background: none; border: none; color: #f44336; cursor: pointer; font-size: 18px; padding: 5px;">✕</button>
    `;
    container.appendChild(fieldDiv);
}

function removeAdTextField(fieldNum) {
    const field = document.getElementById(`ad-text-field-${fieldNum}`);
    if (field) {
        field.remove();
    }
}

function updateTextCount(input) {
    const countSpan = input.nextElementSibling;
    if (countSpan && countSpan.classList.contains('text-count')) {
        countSpan.textContent = `${input.value.length}/100`;
    }
}

// Get all ad texts
function getAdTexts() {
    const inputs = document.querySelectorAll('.ad-text-input');
    const texts = [];
    inputs.forEach(input => {
        if (input.value.trim()) {
            texts.push(input.value.trim());
        }
    });
    return texts;
}

// Legacy function - kept for compatibility
function renderCreativesList() {
    updateSelectedVideosSummary();
}

function updateCreativeText(videoId, text) {
    const creative = state.creatives.find(c => c.video_id === videoId);
    if (creative) {
        creative.ad_text = text;
    }
}

function removeCreative(videoId) {
    state.selectedVideos = state.selectedVideos.filter(v => v.id !== videoId);
    state.creatives = state.creatives.filter(c => c.video_id !== videoId);
    renderVideoSelectionGrid();
    renderCreativesList();
}

function applyAdTextToAll() {
    const globalText = document.getElementById('global-ad-text')?.value || '';
    if (!globalText) {
        showToast('Please enter ad text first', 'error');
        return;
    }

    state.creatives.forEach(creative => {
        creative.ad_text = globalText;
        const textarea = document.getElementById(`ad-text-${creative.video_id}`);
        if (textarea) textarea.value = globalText;
    });

    showToast('Ad text applied to all creatives', 'success');
}

function testLandingUrl() {
    const url = document.getElementById('global-landing-url').value;
    if (url) {
        window.open(url, '_blank');
    } else {
        showToast('Please enter a URL first', 'error');
    }
}

// Get selected portfolio ID
function getSelectedPortfolioId() {
    const select = document.getElementById('cta-portfolio-select');
    return select ? select.value : null;
}

// =====================
// STEP 4: Review & Publish (Creates Ad)
// =====================
function reviewAds() {
    if (!state.adGroupId) {
        showToast('Ad Group not created yet. Please go back and create ad group first.', 'error');
        return;
    }

    const identityId = document.getElementById('global-identity').value;
    const landingUrl = document.getElementById('global-landing-url').value.trim();
    const portfolioId = getSelectedPortfolioId();

    if (!identityId) {
        showToast('Please select an identity', 'error');
        return;
    }

    if (!landingUrl) {
        showToast('Please enter a landing page URL', 'error');
        return;
    }

    if (!portfolioId) {
        showToast('Please select a CTA Portfolio (required for Lead Gen campaigns)', 'error');
        return;
    }

    // Get ad texts from the new text fields
    const adTexts = getAdTexts();

    if (adTexts.length === 0) {
        showToast('Please enter at least one ad text', 'error');
        return;
    }

    if (state.selectedVideos.length === 0) {
        showToast('Please select at least one video', 'error');
        return;
    }

    // Store ad texts in state
    state.adTexts = adTexts;

    // Update creatives with the first ad text (all videos share the same text variations)
    state.creatives.forEach(creative => {
        creative.ad_text = adTexts[0]; // Primary text
    });

    state.globalIdentityId = identityId;
    state.globalLandingUrl = landingUrl;
    state.globalCtaPortfolioId = portfolioId;

    // Get identity details from state or dropdown
    const identitySelect = document.getElementById('global-identity');
    const selectedOption = identitySelect?.options[identitySelect.selectedIndex];

    // Find identity in state (check tiktokPages first since BC_AUTH_TT is there)
    const identityFromPages = state.tiktokPages?.find(i => i.identity_id === identityId);
    const identityFromCustom = state.customIdentities?.find(i => i.identity_id === identityId);
    const identityFromAll = state.identities.find(i => i.identity_id === identityId);

    // Determine identity type - prioritize tiktokPages (BC_AUTH_TT), then dropdown, then state
    let identityType = 'CUSTOMIZED_USER';
    let identityAuthorizedBcId = null;

    if (identityFromPages) {
        // This is a BC_AUTH_TT identity from TikTok Pages
        identityType = 'BC_AUTH_TT';
        identityAuthorizedBcId = identityFromPages.identity_authorized_bc_id || selectedOption?.dataset?.identityAuthorizedBcId;
        console.log('Found identity in tiktokPages:', identityFromPages);
    } else if (selectedOption?.dataset?.identityType === 'BC_AUTH_TT') {
        // Dropdown says it's BC_AUTH_TT
        identityType = 'BC_AUTH_TT';
        identityAuthorizedBcId = selectedOption?.dataset?.identityAuthorizedBcId;
        console.log('Identity type from dropdown dataset: BC_AUTH_TT');
    } else if (identityFromAll?.identity_type === 'BC_AUTH_TT') {
        // State says it's BC_AUTH_TT
        identityType = 'BC_AUTH_TT';
        identityAuthorizedBcId = identityFromAll.identity_authorized_bc_id;
        console.log('Identity type from state.identities: BC_AUTH_TT');
    }

    // Store identity type and BC ID in state for use in createAd
    state.globalIdentityType = identityType;
    state.globalIdentityAuthorizedBcId = identityAuthorizedBcId;

    console.log('Identity captured in reviewAds:', {
        identityId,
        identityType,
        identityAuthorizedBcId,
        fromPages: !!identityFromPages,
        fromCustom: !!identityFromCustom,
        dropdownDataset: selectedOption?.dataset
    });

    addLog('info', `Identity selected: ${identityId} (type: ${identityType})`);
    if (identityType === 'BC_AUTH_TT') {
        addLog('info', `BC_AUTH_TT identity with bc_id: ${identityAuthorizedBcId || 'NOT FOUND'}`);
    }

    const identity = identityFromPages || identityFromCustom || identityFromAll;
    const identityName = identity ? (identity.display_name || identity.identity_name) : identityId;

    // Populate review summaries - Budget is at Campaign level for Smart+ (CBO)
    const budgetVal = state.budget || document.getElementById('campaign-budget')?.value || '50';
    const budgetDisplay = `$${budgetVal}/day`;

    document.getElementById('campaign-summary').innerHTML = `
        <p><strong>Campaign Name:</strong> ${state.campaignName}</p>
        <p><strong>Campaign ID:</strong> ${state.campaignId}</p>
        <p><strong>Budget:</strong> ${budgetDisplay}</p>
    `;

    document.getElementById('adgroup-summary').innerHTML = `
        <p><strong>Ad Group ID:</strong> ${state.adGroupId}</p>
        <p><strong>Pixel ID:</strong> ${state.pixelId}</p>
        <p><strong>Optimization Event:</strong> ${state.optimizationEvent}</p>
    `;

    let adsSummaryHtml = `
        <div class="summary-item" style="background: #f0f4ff; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
            <p><strong>Identity:</strong> ${identityName}</p>
            <p><strong>Landing Page:</strong> ${landingUrl}</p>
            <p><strong>CTA Portfolio:</strong> ${state.selectedPortfolioName || portfolioId}</p>
            <p><strong>Total Videos:</strong> ${state.creatives.length}</p>
            <p><strong>Ad Text Variations:</strong> ${adTexts.length}</p>
        </div>
    `;

    // Show ad texts
    adsSummaryHtml += `
        <div class="summary-item" style="background: #e8f5e9; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
            <h4 style="margin-top: 0;">Ad Text${adTexts.length > 1 ? ' Variations' : ''}</h4>
            ${adTexts.map((text, i) => `<p><strong>Text ${i + 1}:</strong> ${text}</p>`).join('')}
        </div>
    `;

    // Show videos
    adsSummaryHtml += `<h4>Selected Videos (${state.creatives.length})</h4>`;
    state.creatives.forEach((creative, index) => {
        adsSummaryHtml += `
            <div class="summary-item" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f9f9f9; border-radius: 6px; margin-bottom: 8px;">
                ${creative.video_url ? `<img src="${creative.video_url}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">` : '<div style="width: 50px; height: 50px; background: #667eea; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white;">🎬</div>'}
                <div>
                    <p style="margin: 0; font-weight: 600;">${creative.video_name || creative.video_id}</p>
                </div>
            </div>
        `;
    });

    document.getElementById('ads-summary').innerHTML = adsSummaryHtml;

    nextStep();
}

// Create or Update Ad (Final step)
async function createAd() {
    if (!state.adGroupId) {
        showToast('Ad Group not created. Please complete previous steps.', 'error');
        return;
    }

    if (!state.globalCtaPortfolioId) {
        showToast('CTA Portfolio is required. Please go back and select a portfolio.', 'error');
        return;
    }

    // Check if we should UPDATE instead of CREATE
    if (state.adCreated && state.adId) {
        return await updateAd();
    }

    // Block submission if any video is still processing
    const processingVideos = state.creatives.filter(c => c.video_id && String(c.video_id).startsWith('processing_'));
    if (processingVideos.length > 0) {
        showToast('Some videos are still processing. Click "Refresh Library" and try again.', 'error');
        addLog('error', `Blocked: ${processingVideos.length} video(s) still have processing IDs`);
        return;
    }

    showLoading('Creating Smart+ Ad...');
    addLog('info', '=== Creating Smart+ Ad ===');

    try {
        // Use identity type and BC ID that were captured in reviewAds()
        // This ensures we use the correct values that were set when the user selected the identity
        const identityType = state.globalIdentityType || 'CUSTOMIZED_USER';
        const identityAuthorizedBcId = state.globalIdentityAuthorizedBcId || null;

        // Debug logging
        console.log('createAd using stored identity info:', {
            globalIdentityId: state.globalIdentityId,
            identityType: identityType,
            identityAuthorizedBcId: identityAuthorizedBcId
        });

        addLog('info', `Using identity type: ${identityType} for identity ${state.globalIdentityId}`);
        if (identityType === 'BC_AUTH_TT') {
            addLog('info', `BC_AUTH_TT identity with bc_id: ${identityAuthorizedBcId || 'NOT SET - THIS MAY CAUSE ERROR'}`);
        }

        const creativeList = state.creatives.map(creative => ({
            video_id: creative.video_id,
            ad_text: creative.ad_text,
            image_id: creative.image_id || null
        }));

        // Log detailed creative list to verify each video is unique
        addLog('info', `Creating ad with ${creativeList.length} creatives and portfolio ${state.globalCtaPortfolioId}`);
        addLog('info', 'Creative list details:', creativeList.map((c, i) => `Creative ${i+1}: video_id=${c.video_id}`).join(', '));
        addLog('info', `Ad text variations: ${state.adTexts.length}`, state.adTexts);

        // Build request data
        const adRequestData = {
            adgroup_id: state.adGroupId,
            ad_name: state.campaignName + ' - Ad',
            identity_id: state.globalIdentityId,
            identity_type: identityType,
            landing_page_url: state.globalLandingUrl,
            call_to_action_id: state.globalCtaPortfolioId,
            creatives: creativeList,
            ad_texts: state.adTexts
        };

        // Add identity_authorized_bc_id for BC_AUTH_TT identities
        if (identityType === 'BC_AUTH_TT' && identityAuthorizedBcId) {
            adRequestData.identity_authorized_bc_id = identityAuthorizedBcId;
        }

        const result = await apiRequest('create_smartplus_ad', adRequestData);

        hideLoading();

        if (result.success && result.smart_plus_ad_id) {
            state.adId = result.smart_plus_ad_id;  // Store ad ID
            state.adCreated = true;  // Mark as created

            // Update button labels
            updateStepButtonLabels();

            showToast('Smart+ Ad created successfully! Campaign is PAUSED.', 'success');
            addLog('info', `Smart+ Ad created: ${result.smart_plus_ad_id}`);
            addLog('info', `Videos submitted: ${result.videos_count || creativeList.length}, Text variations: ${result.texts_count || state.adTexts.length}`);

            // Show success modal after a short delay
            setTimeout(() => {
                showSuccessModal(result.smart_plus_ad_id, result.videos_count || creativeList.length, result.texts_count || state.adTexts.length);
            }, 500);
        } else {
            showToast('Failed to create ad: ' + (result.message || 'Unknown error'), 'error');
            addLog('error', 'Failed to create ad', result);
        }
    } catch (error) {
        hideLoading();
        showToast('Error creating ad: ' + error.message, 'error');
        addLog('error', 'Error: ' + error.message);
    }
}

// UPDATE existing ad
async function updateAd() {
    showLoading('Updating Smart+ Ad...');
    addLog('info', '=== Updating Smart+ Ad ===');

    try {
        // Use stored identity type from reviewAds()
        const identityType = state.globalIdentityType || 'CUSTOMIZED_USER';
        const identityAuthorizedBcId = state.globalIdentityAuthorizedBcId || null;

        const creativeList = state.creatives.map(creative => ({
            video_id: creative.video_id,
            ad_text: creative.ad_text,
            image_id: creative.image_id || null
        }));

        const adConfig = {
            call_to_action_id: state.globalCtaPortfolioId,
            identity_id: state.globalIdentityId,
            identity_type: identityType
        };

        // Add identity_authorized_bc_id for BC_AUTH_TT
        if (identityType === 'BC_AUTH_TT' && identityAuthorizedBcId) {
            adConfig.identity_authorized_bc_id = identityAuthorizedBcId;
        }

        const result = await apiRequest('update_smartplus_ad', {
            smart_plus_ad_id: state.adId,
            ad_name: state.campaignName + ' - Ad',
            ad_text_list: state.adTexts.map(text => ({ ad_text: text })),
            landing_page_url_list: state.globalLandingUrl ? [{ landing_page_url: state.globalLandingUrl }] : [],
            ad_configuration: adConfig
        });

        hideLoading();

        if (result.success) {
            showToast('Smart+ Ad updated successfully!', 'success');
            addLog('info', `Smart+ Ad updated: ${state.adId}`);

            // Show success modal
            setTimeout(() => {
                showSuccessModal(state.adId, creativeList.length, state.adTexts.length);
            }, 500);
        } else {
            showToast('Failed to update ad: ' + (result.message || 'Unknown error'), 'error');
            addLog('error', 'Failed to update ad', result);
        }
    } catch (error) {
        hideLoading();
        showToast('Error updating ad: ' + error.message, 'error');
        addLog('error', 'Error: ' + error.message);
    }
}

// Update button labels based on creation state
function updateStepButtonLabels() {
    // Step 1 button
    const step1Btn = document.querySelector('#step-1-content .btn-next');
    if (step1Btn) {
        step1Btn.textContent = state.campaignCreated ? 'Update Campaign & Continue' : 'Create Campaign';
    }

    // Step 2 button
    const step2Btn = document.querySelector('#step-2-content .btn-next');
    if (step2Btn) {
        step2Btn.textContent = state.adGroupCreated ? 'Update Ad Group & Continue' : 'Create Ad Group';
    }

    // Step 4 button
    const step4Btn = document.querySelector('#step-4-content .btn-next');
    if (step4Btn) {
        step4Btn.textContent = state.adCreated ? 'Update Ad' : 'Create Ad';
    }
}

// =====================
// Identity Modal
// =====================
// State for identity logo upload
let identityLogoFile = null;

function openCreateIdentityModal() {
    document.getElementById('identity-display-name').value = '';
    document.getElementById('identity-char-count').textContent = '0';
    // Reset logo upload
    identityLogoFile = null;
    document.getElementById('identity-logo-input').value = '';
    document.getElementById('identity-logo-preview').style.display = 'none';
    document.getElementById('identity-logo-placeholder').style.display = 'block';
    document.getElementById('identity-logo-remove-btn').style.display = 'none';
    document.getElementById('create-identity-modal').style.display = 'flex';
}

function closeCreateIdentityModal() {
    document.getElementById('create-identity-modal').style.display = 'none';
    identityLogoFile = null;
}

function previewIdentityLogo(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];

        // Validate file type
        if (!file.type.startsWith('image/')) {
            showToast('Please select an image file', 'error');
            return;
        }

        // Validate file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            showToast('Image too large. Maximum size is 5MB', 'error');
            return;
        }

        identityLogoFile = file;

        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('identity-logo-img').src = e.target.result;
            document.getElementById('identity-logo-preview').style.display = 'block';
            document.getElementById('identity-logo-placeholder').style.display = 'none';
            document.getElementById('identity-logo-remove-btn').style.display = 'inline-block';
        };
        reader.readAsDataURL(file);
    }
}

function removeIdentityLogo() {
    identityLogoFile = null;
    document.getElementById('identity-logo-input').value = '';
    document.getElementById('identity-logo-preview').style.display = 'none';
    document.getElementById('identity-logo-placeholder').style.display = 'block';
    document.getElementById('identity-logo-remove-btn').style.display = 'none';
}

async function createCustomIdentity() {
    const displayName = document.getElementById('identity-display-name').value.trim();

    if (!displayName) {
        showToast('Please enter a display name', 'error');
        return;
    }

    showLoading('Creating identity...');

    try {
        let profileImageId = null;

        // Upload logo if provided
        if (identityLogoFile) {
            showLoading('Uploading logo...');
            addLog('info', 'Uploading identity logo...');

            const formData = new FormData();
            formData.append('image', identityLogoFile);

            const uploadResponse = await fetch('api.php?action=upload_image', {
                method: 'POST',
                headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                body: formData
            });

            const uploadResult = await uploadResponse.json();

            if (uploadResult.success && uploadResult.data?.image_id) {
                profileImageId = uploadResult.data.image_id;
                addLog('success', `Logo uploaded: ${profileImageId}`);
            } else {
                addLog('warning', 'Logo upload failed, creating identity without logo');
            }
        }

        showLoading('Creating identity...');

        // Create identity with optional profile_image_id
        const params = { display_name: displayName };
        if (profileImageId) {
            params.profile_image_id = profileImageId;
        }

        const result = await apiRequest('create_identity', params);

        if (result.success && result.identity_id) {
            closeCreateIdentityModal();
            showToast('Identity created successfully!', 'success');
            addLog('success', `Identity created: ${displayName} (ID: ${result.identity_id})`);

            // Reload identities from server to ensure fresh data (cache was invalidated server-side)
            await loadIdentities();

            // Select the newly created identity
            const select = document.getElementById('global-identity');
            if (select) {
                select.value = result.identity_id;
            }
        } else {
            showToast(result.message || 'Failed to create identity', 'error');
            addLog('error', `Identity creation failed: ${result.message}`);
            if (result.details) {
                console.error('Identity creation details:', result.details);
            }
        }
    } catch (error) {
        showToast('Error creating identity: ' + error.message, 'error');
        addLog('error', `Identity creation error: ${error.message}`);
    } finally {
        hideLoading();
    }
}

// =====================
// Utility Functions
// =====================
function showLoading(text = 'Processing...') {
    document.getElementById('loading-text').textContent = text;
    document.getElementById('loading-overlay').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loading-overlay').style.display = 'none';
}

// =====================
// Video Thumbnail Generation (Client-Side)
// =====================
// Generate thumbnail from video file using Canvas - instant preview before upload
async function generateVideoThumbnail(file, seekPercent = 0.25) {
    return new Promise((resolve, reject) => {
        const video = document.createElement('video');
        video.preload = 'metadata';
        video.muted = true;
        video.playsInline = true;

        // Timeout after 10 seconds
        const timeout = setTimeout(() => {
            URL.revokeObjectURL(video.src);
            reject(new Error('Thumbnail generation timed out'));
        }, 10000);

        video.onloadedmetadata = function() {
            // Seek to specified percent of video (default 25%)
            video.currentTime = Math.min(video.duration * seekPercent, video.duration - 0.1);
        };

        video.onseeked = function() {
            clearTimeout(timeout);
            try {
                const canvas = document.createElement('canvas');
                // Use reasonable dimensions for thumbnail (max 480px width)
                const scale = Math.min(1, 480 / video.videoWidth);
                canvas.width = video.videoWidth * scale;
                canvas.height = video.videoHeight * scale;

                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                // Convert to blob URL for immediate display
                canvas.toBlob(blob => {
                    if (blob) {
                        const thumbnailUrl = URL.createObjectURL(blob);
                        URL.revokeObjectURL(video.src);
                        resolve(thumbnailUrl);
                    } else {
                        URL.revokeObjectURL(video.src);
                        reject(new Error('Failed to create thumbnail blob'));
                    }
                }, 'image/jpeg', 0.8);
            } catch (e) {
                URL.revokeObjectURL(video.src);
                reject(e);
            }
        };

        video.onerror = function() {
            clearTimeout(timeout);
            URL.revokeObjectURL(video.src);
            reject(new Error('Failed to load video for thumbnail'));
        };

        video.src = URL.createObjectURL(file);
    });
}

// Generate thumbnail with fallback - returns blob URL or empty string
async function generateVideoThumbnailSafe(file) {
    try {
        const thumbnail = await generateVideoThumbnail(file);
        console.log('[Thumbnail] Generated client-side thumbnail for:', file.name);
        return thumbnail;
    } catch (e) {
        console.warn('[Thumbnail] Failed to generate thumbnail:', e.message);
        return ''; // Return empty string as fallback
    }
}

function showToast(message, type = 'info') {
    let toast = document.getElementById('toast');
    if (!toast) {
        // Create toast element if it doesn't exist (e.g. in app-shell.php)
        toast = document.createElement('div');
        toast.id = 'toast';
        toast.className = 'toast';
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.className = `toast show ${type}`;

    setTimeout(() => {
        toast.className = 'toast';
    }, 3000);
}

function logout() {
    window.location.href = 'logout.php';
}

// Show success modal with thank you message
function showSuccessModal(adId, creativesCount, textsCount = 1) {
    // Create modal overlay
    const modalHtml = `
        <div id="success-modal" style="
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: fadeIn 0.3s ease;
        ">
            <div style="
                background: white;
                border-radius: 20px;
                padding: 40px;
                max-width: 500px;
                text-align: center;
                animation: slideIn 0.3s ease;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            ">
                <div style="font-size: 72px; margin-bottom: 20px;">🎉</div>
                <h2 style="color: #1e9df1; margin-bottom: 10px; font-size: 32px;">Thank You!</h2>
                <p style="color: #333; margin-bottom: 20px; font-size: 18px; font-weight: 500;">
                    Smart+ Campaign Launched Successfully!
                </p>
                <p style="color: #666; margin-bottom: 15px; font-size: 14px;">
                    Your Smart+ TikTok ad campaign has been created and is now live.
                </p>
                <div style="background: #f0f8ff; padding: 15px; border-radius: 10px; margin-bottom: 25px;">
                    <p style="margin: 5px 0; font-size: 13px;"><strong>Campaign ID:</strong> ${state.campaignId}</p>
                    <p style="margin: 5px 0; font-size: 13px;"><strong>Ad Group ID:</strong> ${state.adGroupId}</p>
                    <p style="margin: 5px 0; font-size: 13px;"><strong>Smart+ Ad ID:</strong> ${adId}</p>
                    <p style="margin: 5px 0; font-size: 13px;"><strong>Videos:</strong> ${creativesCount} (TikTok will auto-optimize)</p>
                    <p style="margin: 5px 0; font-size: 13px;"><strong>Text Variations:</strong> ${textsCount}</p>
                </div>
                <p style="color: #888; margin-bottom: 15px; font-size: 12px; font-style: italic;">
                    TikTok AI will automatically rotate and optimize your ${creativesCount} video${creativesCount > 1 ? 's' : ''} to show the best performing creative.
                </p>
                <p style="color: #666; margin-bottom: 30px; font-size: 14px; font-weight: 600;">
                    Would you like to create another campaign?
                </p>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button onclick="createNewCampaign()" style="
                        background: #1e9df1;
                        color: white;
                        border: 2px solid #1e9df1;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                        box-shadow: 0 4px 15px rgba(30, 157, 241, 0.3);
                        transition: all 0.3s;
                    " onmouseover="this.style.background='#1a8ad8'; this.style.transform='translateY(-2px)'" onmouseout="this.style.background='#1e9df1'; this.style.transform='translateY(0)'">
                        Yes, Create Another
                    </button>
                    <button onclick="finishAndReset()" style="
                        background: #f3f4f6;
                        color: #374151;
                        border: 2px solid #e5e7eb;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                        transition: all 0.2s;
                    " onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                        No, Go Back
                    </button>
                </div>
            </div>
        </div>
    `;

    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // Add CSS animation if not already added
    if (!document.getElementById('success-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'success-modal-styles';
        style.textContent = `
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes slideIn {
                from {
                    transform: translateY(-30px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);
    }
}

// Create new campaign - reload page
function createNewCampaign() {
    location.reload();
}

// Finish and redirect to campaigns view
function finishAndReset() {
    // Remove success modal
    const modal = document.getElementById('success-modal');
    if (modal) {
        modal.remove();
    }

    // Redirect to app shell campaigns view
    window.location.href = window.APP_SHELL_MODE ? 'app-shell.php?view=campaigns' : 'app-shell.php?view=campaigns';
}

// =====================
// BULK LAUNCH FUNCTIONALITY
// =====================

// Bulk launch state
let bulkLaunchState = {
    accounts: [],
    selectedAccounts: [],
    accountAssets: {},
    videoDistributionMode: 'match',
    scheduleType: 'continuous',  // Default schedule type
    isConfigured: false
};

// Toggle between single and bulk launch modes
function toggleLaunchMode() {
    const mode = document.querySelector('input[name="launch_mode"]:checked').value;
    const singleOption = document.getElementById('single-launch-option');
    const bulkOption = document.getElementById('bulk-launch-option');
    const bulkPreview = document.getElementById('bulk-accounts-preview');
    const bulkSummary = document.getElementById('bulk-launch-summary');
    const launchButton = document.getElementById('launch-button');

    if (mode === 'bulk') {
        singleOption.classList.remove('selected');
        bulkOption.classList.add('selected');
        bulkPreview.style.display = 'block';

        // Load available accounts if not already loaded
        if (bulkLaunchState.accounts.length === 0) {
            loadBulkAccounts();
        }

        // Show summary if already configured
        if (bulkLaunchState.isConfigured && bulkLaunchState.selectedAccounts.length > 0) {
            bulkSummary.style.display = 'block';
            launchButton.textContent = `⚡ Bulk Launch to ${bulkLaunchState.selectedAccounts.length} Accounts`;
        } else {
            bulkSummary.style.display = 'none';
            launchButton.textContent = '⚡ Configure & Launch';
        }
    } else {
        singleOption.classList.add('selected');
        bulkOption.classList.remove('selected');
        bulkPreview.style.display = 'none';
        bulkSummary.style.display = 'none';
        launchButton.textContent = '🚀 Launch Campaign';
    }

    addLog('info', `Launch mode changed to: ${mode}`);
}

// Load available accounts for bulk launch
async function loadBulkAccounts() {
    if (!window.TIKTOK_ADVERTISER_ID) return;
    try {
        addLog('info', 'Loading available accounts for bulk launch...');

        const result = await apiRequest('get_bulk_accounts');

        if (result.success && result.data && result.data.accounts) {
            bulkLaunchState.accounts = result.data.accounts;

            // Update the available count
            const countEl = document.getElementById('available-accounts-count');
            if (countEl) countEl.textContent = result.data.accounts.length;

            // Set current account name
            const currentAccount = result.data.accounts.find(a => a.is_current);
            if (currentAccount) {
                const currentNameEl = document.getElementById('current-account-name');
                if (currentNameEl) currentNameEl.textContent = currentAccount.advertiser_name;
            }

            addLog('info', `Loaded ${result.data.accounts.length} accounts`);
        } else {
            showToast('Failed to load accounts: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        addLog('error', 'Error loading accounts: ' + error.message);
        showToast('Error loading accounts', 'error');
    }
}

// Open bulk launch modal
function openBulkLaunchModal() {
    const modal = document.getElementById('bulk-launch-modal');
    modal.style.display = 'flex';

    // Populate campaign info
    document.getElementById('bulk-campaign-name').textContent = state.campaignName || '-';
    const budgetVal = state.budget || document.getElementById('campaign-budget')?.value || '50';
    document.getElementById('bulk-campaign-budget').textContent = budgetVal;

    // Render accounts
    renderBulkAccountsInModal();
}

// Close bulk launch modal
function closeBulkLaunchModal() {
    document.getElementById('bulk-launch-modal').style.display = 'none';
}

// Render accounts in the bulk launch modal
async function renderBulkAccountsInModal() {
    const container = document.getElementById('bulk-accounts-container');

    if (bulkLaunchState.accounts.length === 0) {
        await loadBulkAccounts();
    }

    if (bulkLaunchState.accounts.length === 0) {
        container.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;">No accounts available</p>';
        return;
    }

    container.innerHTML = '';

    // Find the current account (original account where campaign was created)
    const currentAccount = bulkLaunchState.accounts.find(a => a.is_current);

    // Render current account first (at the top) - always selected
    if (currentAccount) {
        // Auto-add current account to selectedAccounts if not already there
        // Use String() to ensure consistent type comparison
        const currentAdvId = String(currentAccount.advertiser_id);
        const isCurrentSelected = bulkLaunchState.selectedAccounts.some(a => String(a.advertiser_id) === currentAdvId);
        if (!isCurrentSelected) {
            // Pre-populate with assets from main campaign
            bulkLaunchState.selectedAccounts.push({
                advertiser_id: currentAccount.advertiser_id,
                advertiser_name: currentAccount.advertiser_name,
                pixel_id: state.pixelId || '',
                identity_id: state.globalIdentityId || '',
                identity_type: state.globalIdentityType || 'CUSTOMIZED_USER',
                identity_authorized_bc_id: state.globalIdentityAuthorizedBcId || '',
                portfolio_id: state.selectedPortfolioId || '',
                video_mapping: {},
                is_original: true
            });
            console.log('[Bulk Launch] Added original account to selectedAccounts:', currentAdvId);
        } else {
            console.log('[Bulk Launch] Original account already in selectedAccounts:', currentAdvId);
        }

        // Create assets object for current account from main campaign state
        const currentAssets = {
            pixels: state.pixels || [],
            identities: state.identities || [],
            portfolios: state.ctaPortfolios || [],
            videoMatch: { match_rate: 100 }, // Original account always has 100% match
            errors: {}
        };
        bulkLaunchState.accountAssets[currentAccount.advertiser_id] = currentAssets;

        const card = document.createElement('div');
        card.className = 'bulk-account-card selected original-account';
        card.id = `bulk-account-${currentAccount.advertiser_id}`;

        card.innerHTML = `
            <div class="bulk-account-header">
                <label class="bulk-account-checkbox">
                    <input type="checkbox"
                           id="bulk-check-${currentAccount.advertiser_id}"
                           checked
                           disabled
                           title="Original account is always included">
                    <span class="checkmark"></span>
                </label>
                <div class="bulk-account-info">
                    <span class="bulk-account-name">${currentAccount.advertiser_name}</span>
                    <span class="bulk-account-id">${currentAccount.advertiser_id}</span>
                    <span class="original-badge">📍 Original Account</span>
                </div>
            </div>
            <div class="bulk-account-assets" id="assets-${currentAccount.advertiser_id}">
                ${renderOriginalAccountAssets()}
            </div>
            <div class="bulk-account-status" id="status-${currentAccount.advertiser_id}">
                <span class="status-ready">✓ Ready (campaign configured here)</span>
            </div>
        `;

        container.appendChild(card);
    }

    // Render other accounts
    for (const account of bulkLaunchState.accounts) {
        // Skip current account (already rendered above)
        if (account.is_current) continue;

        const isSelected = bulkLaunchState.selectedAccounts.some(a => a.advertiser_id === account.advertiser_id);
        const assets = bulkLaunchState.accountAssets[account.advertiser_id] || null;

        const card = document.createElement('div');
        card.className = `bulk-account-card ${isSelected ? 'selected' : ''}`;
        card.id = `bulk-account-${account.advertiser_id}`;

        card.innerHTML = `
            <div class="bulk-account-header">
                <label class="bulk-account-checkbox">
                    <input type="checkbox"
                           id="bulk-check-${account.advertiser_id}"
                           ${isSelected ? 'checked' : ''}
                           onchange="toggleBulkAccountSelection('${account.advertiser_id}')">
                    <span class="checkmark"></span>
                </label>
                <div class="bulk-account-info">
                    <span class="bulk-account-name">${account.advertiser_name}</span>
                    <span class="bulk-account-id">${account.advertiser_id}</span>
                </div>
                <div class="bulk-account-header-actions">
                    <button type="button" class="btn-load-assets" onclick="loadAccountAssets('${account.advertiser_id}')"
                            ${assets ? 'style="display:none;"' : ''}>
                        Load Assets
                    </button>
                    <button type="button" class="btn-toggle-assets" id="toggle-btn-${account.advertiser_id}"
                            onclick="toggleAccountAssets('${account.advertiser_id}')"
                            title="Expand/Collapse" ${assets ? '' : 'style="display:none;"'}>
                        <span id="toggle-icon-${account.advertiser_id}">▼</span>
                    </button>
                </div>
            </div>
            <div class="bulk-account-assets" id="assets-${account.advertiser_id}" style="${assets ? '' : 'display:none;'}">
                ${assets ? renderAccountAssetsDropdowns(account.advertiser_id, assets) : '<div class="loading-assets"><div class="spinner-small"></div> Loading...</div>'}
            </div>
            <div class="bulk-account-status" id="status-${account.advertiser_id}">
                ${isSelected && assets ? getAccountStatus(account.advertiser_id) : ''}
            </div>
        `;

        container.appendChild(card);
    }

    updateBulkModalCounts();
}

// Render original account's assets (read-only display)
function renderOriginalAccountAssets() {
    const pixelName = state.pixels?.find(p => p.pixel_id === state.pixelId)?.pixel_name || state.pixelId || 'Not selected';
    const identity = state.identities?.find(i => i.identity_id === state.globalIdentityId);
    const identityName = identity?.display_name || identity?.identity_name || state.globalIdentityId || 'Not selected';
    const portfolio = state.ctaPortfolios?.find(p => p.creative_portfolio_id === state.selectedPortfolioId);
    const portfolioName = portfolio?.portfolio_name || state.selectedPortfolioId || 'Not selected';
    const currentAccount = bulkLaunchState.accounts.find(a => a.is_current);
    const advertiserId = currentAccount?.advertiser_id || 'original';
    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.is_original);
    const currentLandingUrl = selectedAccount?.landing_page_url || '';
    const globalLandingUrl = document.getElementById('global-landing-url')?.value || '';

    return `
        <div class="bulk-asset-grid original-assets">
            <div class="asset-item">
                <label>Pixel</label>
                <div class="asset-value">${pixelName}</div>
            </div>
            <div class="asset-item">
                <label>Identity</label>
                <div class="asset-value">${identityName}</div>
            </div>
            <div class="asset-item">
                <label>CTA Portfolio</label>
                <div class="asset-value">${portfolioName}</div>
            </div>
        </div>
        <div class="bulk-landing-url-section" style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #64748b; margin-bottom: 6px;">
                <span class="asset-icon">🔗</span> Landing Page URL <span style="color: #94a3b8;">(optional override)</span>
            </label>
            <input type="url"
                   id="landing-url-${advertiserId}"
                   placeholder="${globalLandingUrl || 'Use campaign default URL...'}"
                   value="${currentLandingUrl}"
                   onchange="updateOriginalAccountLandingUrl()"
                   style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; transition: border-color 0.2s;">
            <small style="color: #94a3b8; font-size: 11px; margin-top: 4px; display: block;">
                Leave empty to use campaign default: <span style="color: #64748b;">${globalLandingUrl || 'Not set'}</span>
            </small>
        </div>
    `;
}

// Render asset dropdowns for an account
function renderAccountAssetsDropdowns(advertiserId, assets) {
    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    const selectedPixelId = selectedAccount?.pixel_id || '';
    const selectedIdentityId = selectedAccount?.identity_id || '';
    const selectedPortfolioId = selectedAccount?.portfolio_id || '';
    const errors = assets.errors || {};

    // Check if we have pixels or if there was an error
    const pixelsArray = assets.pixels || [];
    const identitiesArray = assets.identities || [];
    const portfoliosArray = assets.portfolios || [];
    const hasPixelError = errors.pixels;
    const hasIdentityError = errors.identities;
    const hasPortfolioError = errors.portfolios;

    let html = '';

    // Show any API errors at the top
    if (Object.keys(errors).length > 0) {
        html += `<div class="asset-errors" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 6px; padding: 10px; margin-bottom: 10px; font-size: 12px;">
            <strong style="color: #ef4444;">API Issues:</strong>
            <ul style="margin: 5px 0 0 0; padding-left: 20px; color: #ef4444;">
                ${Object.entries(errors).map(([key, msg]) => `<li>${key}: ${msg}</li>`).join('')}
            </ul>
        </div>`;
    }

    html += `
        <div class="bulk-asset-grid">
            <div class="bulk-asset-item">
                <label><span class="asset-icon">📊</span> Pixel</label>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <select id="pixel-${advertiserId}" onchange="updateAccountAssetSelection('${advertiserId}')" style="flex: 1;">
                        ${pixelsArray.length === 0
                            ? `<option value="">${hasPixelError ? 'Error loading' : 'No pixels'}</option>`
                            : `<option value="">Select Pixel...</option>
                               ${pixelsArray.map(p =>
                                   `<option value="${p.pixel_id}" ${p.pixel_id === selectedPixelId ? 'selected' : ''}>${p.pixel_name || p.pixel_id}</option>`
                               ).join('')}`
                        }
                    </select>
                    <button type="button" class="refresh-btn" id="pixel-refresh-btn-${advertiserId}" onclick="refreshPixels('${advertiserId}')" title="Refresh pixels">
                        <span id="pixel-refresh-icon-${advertiserId}">🔄</span>
                    </button>
                </div>
                ${pixelsArray.length > 0 ? `<span class="asset-count">${pixelsArray.length} available</span>` : ''}
            </div>
            <div class="bulk-asset-item">
                <label><span class="asset-icon">👤</span> Identity</label>
                <div class="identity-select-wrapper" style="display: flex; gap: 6px; align-items: center;">
                    <select id="identity-${advertiserId}" onchange="updateAccountAssetSelection('${advertiserId}')" style="flex: 1;">
                        ${identitiesArray.length === 0
                            ? `<option value="">${hasIdentityError ? 'Error loading' : 'No identities'}</option>`
                            : `<option value="">Select Identity...</option>
                               ${identitiesArray.map(i =>
                                   `<option value="${i.identity_id}" data-type="${i.identity_type || 'CUSTOMIZED_USER'}" data-identity-type="${i.identity_type || 'CUSTOMIZED_USER'}" ${i.identity_authorized_bc_id ? `data-identity-authorized-bc-id="${i.identity_authorized_bc_id}"` : ''} ${i.identity_id === selectedIdentityId ? 'selected' : ''}>${i.display_name || i.identity_name || i.identity_id}${i.identity_type === 'BC_AUTH_TT' ? ' (Page)' : ''}</option>`
                               ).join('')}`
                        }
                    </select>
                    <button type="button" class="refresh-btn" id="identity-refresh-btn-${advertiserId}" onclick="refreshIdentities('${advertiserId}')" title="Refresh identities">
                        <span id="identity-refresh-icon-${advertiserId}">🔄</span>
                    </button>
                    <button type="button" class="btn-create-identity" onclick="openBulkIdentityCreate('${advertiserId}')" title="Create new identity">+</button>
                </div>
                ${identitiesArray.length > 0 ? `<span class="asset-count">${identitiesArray.length} available</span>` : '<span class="asset-count">Click + to create</span>'}
            </div>
            <div class="bulk-asset-item">
                <label><span class="asset-icon">🔘</span> CTA Portfolio</label>
                <div class="portfolio-select-wrapper">
                    <select id="portfolio-${advertiserId}" onchange="updateAccountAssetSelection('${advertiserId}')">
                        ${portfoliosArray.length === 0
                            ? `<option value="">${hasPortfolioError ? 'Error loading' : 'No portfolios'}</option>`
                            : `<option value="">Select Portfolio...</option>
                               <option value="auto_create" ${selectedPortfolioId === 'auto_create' ? 'selected' : ''}>✨ Auto-create new</option>
                               ${portfoliosArray.map(p =>
                                   `<option value="${p.portfolio_id}" ${p.portfolio_id === selectedPortfolioId ? 'selected' : ''}>${p.portfolio_name}</option>`
                               ).join('')}`
                        }
                    </select>
                    <button type="button" class="btn-create-portfolio" onclick="openBulkPortfolioCreate('${advertiserId}')" title="Create new portfolio">+</button>
                </div>
                ${portfoliosArray.length > 0 ? `<span class="asset-count">${portfoliosArray.length} available</span>` : '<span class="asset-count">Will auto-create</span>'}
            </div>
        </div>
    `;

    // Landing Page URL field (optional, per-account override)
    const currentLandingUrl = selectedAccount?.landing_page_url || '';
    const globalLandingUrl = document.getElementById('global-landing-url')?.value || '';
    html += `
        <div class="bulk-landing-url-section" style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #64748b; margin-bottom: 6px;">
                <span class="asset-icon">🔗</span> Landing Page URL <span style="color: #94a3b8;">(optional override)</span>
            </label>
            <input type="url"
                   id="landing-url-${advertiserId}"
                   placeholder="${globalLandingUrl || 'Use campaign default URL...'}"
                   value="${currentLandingUrl}"
                   onchange="updateAccountLandingUrl('${advertiserId}')"
                   style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; transition: border-color 0.2s;">
            <small style="color: #94a3b8; font-size: 11px; margin-top: 4px; display: block;">
                Leave empty to use campaign default: <span style="color: #64748b;">${globalLandingUrl || 'Not set'}</span>
            </small>
        </div>
    `;

    // Campaign name override section
    const currentCampaignName = selectedAccount?.campaign_name || '';
    const defaultCampaignName = state.campaignName || '';
    html += `
        <div class="bulk-campaign-name-section" style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #64748b; margin-bottom: 6px;">
                <span class="asset-icon">📝</span> Campaign Name <span style="color: #94a3b8;">(optional override)</span>
            </label>
            <input type="text"
                   id="campaign-name-${advertiserId}"
                   placeholder="${defaultCampaignName || 'Use default campaign name...'}"
                   value="${currentCampaignName}"
                   onchange="updateAccountCampaignName('${advertiserId}')"
                   style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; transition: border-color 0.2s;">
            <small style="color: #94a3b8; font-size: 11px; margin-top: 4px; display: block;">
                Leave empty to use: <span style="color: #64748b;">${defaultCampaignName || 'Not set'}</span>
            </small>
        </div>
    `;

    // Video matching status with upload button
    const videoMatch = bulkLaunchState.accountAssets[advertiserId]?.videoMatch;
    const videoCount = assets.videos?.length || 0;
    if (videoMatch || state.selectedVideos.length > 0) {
        const matchRate = videoMatch?.match_rate || 0;
        const statusClass = matchRate === 100 ? 'success' : matchRate > 0 ? 'warning' : 'error';
        html += `
            <div class="asset-row video-match-status ${statusClass}">
                <span class="match-icon">${matchRate === 100 ? '✓' : matchRate > 0 ? '⚠' : '✗'}</span>
                <span>Videos: ${videoMatch?.matched?.length || 0}/${state.selectedVideos.length} matched (${matchRate}%)</span>
                <div class="video-actions" style="display: flex; gap: 8px; margin-left: auto;">
                    <input type="file"
                           id="account-video-upload-${advertiserId}"
                           accept="video/*"
                           multiple
                           style="display: none;"
                           onchange="handleBulkAccountVideoUpload(event, '${advertiserId}')">
                    <button type="button" class="btn-use-original-video"
                            onclick="useOriginalVideoForAccount('${advertiserId}')"
                            style="padding: 4px 10px; background: linear-gradient(135deg, #8b5cf6, #6366f1); color: white; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;"
                            title="Upload the original campaign videos to this account">
                        🔄 Use Original
                    </button>
                    <button type="button" class="btn-upload-account-video"
                            onclick="document.getElementById('account-video-upload-${advertiserId}').click()"
                            style="padding: 4px 10px; background: linear-gradient(135deg, #fe2c55, #25f4ee); color: white; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                        📤 Upload
                    </button>
                    <button type="button" class="btn-toggle-library" onclick="toggleMediaLibrary('${advertiserId}')">
                        📁 Library (${videoCount})
                    </button>
                </div>
            </div>
            <!-- Upload Progress Bar for this account -->
            <div id="bulk-upload-progress-${advertiserId}" style="display: none; margin: 8px 0; padding: 10px; background: #f0f9ff; border-radius: 6px; border: 1px solid #bae6fd;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span id="bulk-upload-status-${advertiserId}" style="font-size: 12px; color: #0369a1; font-weight: 500;">Uploading...</span>
                    <span id="bulk-upload-count-${advertiserId}" style="font-size: 12px; color: #0369a1;">0/0</span>
                </div>
                <div style="background: #e0f2fe; border-radius: 4px; height: 6px; overflow: hidden;">
                    <div id="bulk-upload-bar-${advertiserId}" style="background: linear-gradient(90deg, #0284c7, #22d3ee); height: 100%; width: 0%; transition: width 0.3s;"></div>
                </div>
            </div>
        `;
    }

    // Add collapsible media library section
    const videos = assets.videos || [];
    const images = assets.images || [];
    const isExpanded = bulkLaunchState.expandedLibraries?.[advertiserId] || false;

    html += `
        <div class="account-media-library" id="media-library-${advertiserId}" style="display: ${isExpanded ? 'block' : 'none'};">
            <div class="media-library-header">
                <span class="media-library-title">📁 Media Library</span>
                <span class="media-counts">
                    <span class="video-count">🎬 ${videos.length} videos</span>
                    <span class="image-count">🖼️ ${images.length} images</span>
                </span>
            </div>
            ${renderAccountMediaContent(advertiserId, assets, videoMatch)}
        </div>
    `;

    return html;
}

// Render media library content (videos and images)
function renderAccountMediaContent(advertiserId, assets, videoMatch) {
    const videos = assets.videos || [];
    const images = assets.images || [];
    const matched = videoMatch?.matched || [];
    const unmatched = videoMatch?.unmatched || [];

    // Create a set of matched target video IDs for quick lookup
    const matchedTargetIds = new Set(matched.map(m => m.target_video_id));
    const matchedSourceToTarget = {};
    matched.forEach(m => {
        matchedSourceToTarget[m.source_video_id] = m.target_video_id;
    });

    let html = '';

    // Campaign Videos Section - Show which videos are needed and their match status
    if (state.selectedVideos.length > 0) {
        html += `
            <div class="media-section">
                <h5 class="media-section-title">Campaign Videos (${state.selectedVideos.length} required)</h5>
                <div class="campaign-videos-list">
        `;

        state.selectedVideos.forEach(sourceVideo => {
            const matchInfo = matched.find(m => m.source_video_id === sourceVideo.id);
            const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
            const currentMapping = selectedAccount?.video_mapping?.[sourceVideo.id];
            const targetVideoId = currentMapping || matchInfo?.target_video_id;
            const isMatched = !!targetVideoId;

            // Find the selected video info for display
            const selectedVideoInfo = targetVideoId ? videos.find(v => v.video_id === targetVideoId) : null;
            const selectedVideoName = selectedVideoInfo?.file_name || targetVideoId || '';

            html += `
                <div class="campaign-video-row ${isMatched ? 'matched' : 'unmatched'}" id="video-row-${advertiserId}-${sourceVideo.id}">
                    <div class="campaign-video-info">
                        <div class="campaign-video-thumb">
                            ${sourceVideo.url ? `<img src="${sourceVideo.url}" alt="">` : '<span class="no-thumb">🎬</span>'}
                        </div>
                        <div class="campaign-video-details">
                            <span class="campaign-video-name">${(sourceVideo.name || 'Video').substring(0, 25)}${sourceVideo.name?.length > 25 ? '...' : ''}</span>
                            <span class="campaign-video-status ${isMatched ? 'matched' : 'unmatched'}" id="video-status-${advertiserId}-${sourceVideo.id}">
                                ${isMatched
                                    ? `<span class="status-icon">✓</span> Mapped → ${selectedVideoName.substring(0, 20)}${selectedVideoName.length > 20 ? '...' : ''}`
                                    : '<span class="status-icon">!</span> Needs mapping'}
                            </span>
                        </div>
                    </div>
                    <div class="campaign-video-mapping">
                        <button type="button"
                                id="video-map-btn-${advertiserId}-${sourceVideo.id}"
                                class="btn-select-library ${isMatched ? 'has-selection' : ''}"
                                onclick="openVideoPicker('${advertiserId}', '${sourceVideo.id}', '${(sourceVideo.name || 'Video').replace(/'/g, "\\'")}')">
                            ${isMatched ? '✓ Change Video' : '📹 Select from Library'}
                        </button>
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    }

    // All Videos in Account Section
    html += `
        <div class="media-section">
            <h5 class="media-section-title">All Videos in Account (${videos.length})</h5>
            <div class="media-grid-container">
                <div class="media-grid">
    `;

    if (videos.length === 0) {
        html += '<p class="no-media-text">No videos in this account</p>';
    } else {
        videos.forEach(video => {
            const isUsedInCampaign = matchedTargetIds.has(video.video_id);
            html += `
                <div class="media-item video-item ${isUsedInCampaign ? 'used-in-campaign' : ''}">
                    <div class="media-thumb">
                        ${video.video_cover_url ? `<img src="${video.video_cover_url}" alt="">` : '<div class="media-placeholder">🎬</div>'}
                        ${isUsedInCampaign ? '<span class="used-badge">✓</span>' : ''}
                    </div>
                    <div class="media-name">${(video.file_name || 'Video').substring(0, 15)}${video.file_name?.length > 15 ? '...' : ''}</div>
                </div>
            `;
        });
    }

    html += `
                </div>
            </div>
        </div>
    `;

    // Images Section
    html += `
        <div class="media-section">
            <h5 class="media-section-title">Images (${images.length})</h5>
            <div class="media-grid-container">
                <div class="media-grid">
    `;

    if (images.length === 0) {
        html += '<p class="no-media-text">No images in this account</p>';
    } else {
        images.forEach(image => {
            html += `
                <div class="media-item image-item">
                    <div class="media-thumb">
                        ${image.image_url ? `<img src="${image.image_url}" alt="">` : '<div class="media-placeholder">🖼️</div>'}
                    </div>
                    <div class="media-name">${(image.file_name || 'Image').substring(0, 15)}${image.file_name?.length > 15 ? '...' : ''}</div>
                </div>
            `;
        });
    }

    html += `
                </div>
            </div>
        </div>
    `;

    return html;
}

// =============================================
// VIDEO PICKER MODAL FUNCTIONS
// =============================================

// Video picker state
let videoPickerState = {
    advertiserId: null,
    sourceVideoId: null,
    sourceVideoName: null,
    videos: [],
    searchTerm: ''
};

// Open video picker modal
function openVideoPicker(advertiserId, sourceVideoId, sourceVideoName) {
    console.log('Opening video picker:', { advertiserId, sourceVideoId, sourceVideoName });

    // Get videos for this account
    const accountAssets = bulkLaunchState.accountAssets[advertiserId];
    if (!accountAssets || !accountAssets.videos) {
        showToast('No videos available for this account', 'error');
        return;
    }

    // Sort videos: newly uploaded first, then preserve order (newest first from API/unshift)
    const videos = [...accountAssets.videos].sort((a, b) => {
        if (a.is_new && !b.is_new) return -1;
        if (!a.is_new && b.is_new) return 1;
        return 0;
    });

    // Store state
    videoPickerState = {
        advertiserId,
        sourceVideoId,
        sourceVideoName,
        videos,
        searchTerm: ''
    };

    // Get current selection
    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    videoPickerState.currentSelection = selectedAccount?.video_mapping?.[sourceVideoId] || null;

    // Update modal content
    document.getElementById('picker-source-name').textContent = sourceVideoName;
    document.getElementById('video-picker-search').value = '';

    // Render video grid
    renderVideoPickerGrid();

    // Show modal
    document.getElementById('video-picker-modal').style.display = 'flex';
}

// Close video picker modal
function closeVideoPickerModal() {
    document.getElementById('video-picker-modal').style.display = 'none';
    videoPickerState = {
        advertiserId: null,
        sourceVideoId: null,
        sourceVideoName: null,
        videos: [],
        searchTerm: ''
    };
}

// Filter video picker results based on search
function filterVideoPickerResults() {
    const searchInput = document.getElementById('video-picker-search');
    videoPickerState.searchTerm = searchInput.value.toLowerCase().trim();
    renderVideoPickerGrid();
}

// Render video picker grid
function renderVideoPickerGrid() {
    const grid = document.getElementById('picker-video-grid');
    const countEl = document.getElementById('picker-video-count');

    // Filter videos based on search term
    let filteredVideos = videoPickerState.videos;
    if (videoPickerState.searchTerm) {
        filteredVideos = videoPickerState.videos.filter(v => {
            const fileName = (v.file_name || '').toLowerCase();
            return fileName.includes(videoPickerState.searchTerm);
        });
    }

    // Update count
    countEl.textContent = `${filteredVideos.length} video${filteredVideos.length !== 1 ? 's' : ''}`;

    // Render grid
    if (filteredVideos.length === 0) {
        const advertiserId = videoPickerState.advertiserId;
        grid.innerHTML = `
            <div class="picker-empty-state">
                <div class="empty-icon">🎬</div>
                <p>${videoPickerState.searchTerm ? 'No videos match your search' : 'No videos in this account'}</p>
                ${!videoPickerState.searchTerm ? `
                    <div class="picker-upload-section" style="margin-top: 20px;">
                        <input type="file"
                               id="bulk-video-upload-${advertiserId}"
                               accept="video/*"
                               multiple
                               style="display: none;"
                               onchange="handleBulkAccountVideoUpload(event, '${advertiserId}')">
                        <button type="button"
                                class="btn-upload-video"
                                onclick="document.getElementById('bulk-video-upload-${advertiserId}').click()"
                                style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: linear-gradient(135deg, #fe2c55, #25f4ee); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                            <span>📤</span> Upload Videos to This Account
                        </button>
                        <p style="margin-top: 10px; font-size: 12px; color: #64748b;">Select one or more videos to upload</p>
                    </div>
                    <div id="bulk-upload-progress-${advertiserId}" style="display: none; margin-top: 15px; width: 100%; max-width: 300px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span id="bulk-upload-status-${advertiserId}" style="font-size: 12px; color: #64748b;">Uploading...</span>
                            <span id="bulk-upload-count-${advertiserId}" style="font-size: 12px; color: #64748b;">0/0</span>
                        </div>
                        <div style="background: #e2e8f0; border-radius: 4px; height: 6px; overflow: hidden;">
                            <div id="bulk-upload-bar-${advertiserId}" style="background: linear-gradient(90deg, #fe2c55, #25f4ee); height: 100%; width: 0%; transition: width 0.3s;"></div>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;
        return;
    }

    let html = '';
    filteredVideos.forEach(video => {
        const isSelected = videoPickerState.currentSelection === video.video_id;
        const fileName = video.file_name || video.video_id || 'Untitled';
        const coverUrl = video.video_cover_url || video.preview_url || '';
        const isLocalBlob = coverUrl.startsWith('blob:');
        const isNew = video.is_new;
        const isProcessing = video.is_processing;

        // For any URL (local blob thumbnail or remote TikTok URL), use img element
        // Client-side thumbnails are now JPEG images, not video blobs
        let mediaPreview;
        if (coverUrl) {
            // Use img for both local thumbnails and remote URLs
            mediaPreview = `<img src="${coverUrl}" alt="${fileName}" loading="lazy" style="width:100%; height:100%; object-fit:cover;" onerror="this.parentElement.innerHTML='<div class=\\'no-preview\\'>🎬</div>'">`;
        } else {
            // No preview available
            mediaPreview = '<div class="no-preview">🎬</div>';
        }

        // Check if video is still processing (has fake processing_ ID)
        const isStillProcessing = isProcessing || (video.video_id && video.video_id.startsWith('processing_'));

        // Don't allow selection of processing videos - they don't have real TikTok IDs yet
        const clickHandler = isStillProcessing
            ? `showToast('This video is still processing. Please wait or click Refresh to check status.', 'warning')`
            : `selectVideoFromPicker('${video.video_id}', '${fileName.replace(/'/g, "\\'")}')`;

        html += `
            <div class="picker-video-item ${isSelected ? 'selected' : ''} ${isNew ? 'newly-uploaded' : ''} ${isStillProcessing ? 'processing not-selectable' : ''}"
                 onclick="${clickHandler}">
                <div class="picker-video-thumb">
                    ${mediaPreview}
                    ${isNew && !isStillProcessing ? '<span class="new-badge">NEW</span>' : ''}
                    ${isStillProcessing ? '<span class="processing-badge">⏳ Processing</span>' : ''}
                </div>
                <div class="picker-video-info">
                    <span class="picker-video-name">${fileName}</span>
                </div>
                <div class="selected-badge">✓</div>
                <div class="select-hint">${isStillProcessing ? 'Processing...' : (isSelected ? 'Selected' : 'Click to select')}</div>
            </div>
        `;
    });

    grid.innerHTML = html;
}

// Select video from picker
function selectVideoFromPicker(targetVideoId, targetVideoName) {
    const { advertiserId, sourceVideoId } = videoPickerState;

    console.log('Video selected from picker:', { advertiserId, sourceVideoId, targetVideoId, targetVideoName });

    // Update video mapping
    updateVideoMapping(advertiserId, sourceVideoId, targetVideoId);

    // Update the button and status in the campaign video row
    const rowEl = document.getElementById(`video-row-${advertiserId}-${sourceVideoId}`);
    if (rowEl) {
        rowEl.classList.remove('unmatched');
        rowEl.classList.add('matched');

        const statusEl = document.getElementById(`video-status-${advertiserId}-${sourceVideoId}`);
        if (statusEl) {
            statusEl.className = 'campaign-video-status matched';
            statusEl.innerHTML = `<span class="status-icon">✓</span> Mapped → ${targetVideoName.substring(0, 20)}${targetVideoName.length > 20 ? '...' : ''}`;
        }

        const btnEl = document.getElementById(`video-map-btn-${advertiserId}-${sourceVideoId}`);
        if (btnEl) {
            btnEl.className = 'btn-select-library has-selection';
            btnEl.textContent = '✓ Change Video';
        }
    }

    // Close modal
    closeVideoPickerModal();

    showToast(`Video mapped: ${targetVideoName}`, 'success');
}

// Handle video upload for a specific account in bulk launch
async function handleBulkAccountVideoUpload(event, advertiserId) {
    const files = Array.from(event.target.files);
    if (files.length === 0) return;

    // Validate files
    const maxSize = 500 * 1024 * 1024; // 500MB
    const validFiles = files.filter(file => {
        if (!file.type.startsWith('video/')) {
            showToast(`Skipped ${file.name}: Not a video file`, 'warning');
            return false;
        }
        if (file.size > maxSize) {
            showToast(`Skipped ${file.name}: Exceeds 500MB limit`, 'warning');
            return false;
        }
        return true;
    });

    if (validFiles.length === 0) {
        showToast('No valid video files selected', 'error');
        event.target.value = '';
        return;
    }

    // Show per-file progress UI
    const progressContainer = document.getElementById(`bulk-upload-progress-${advertiserId}`);
    const statusEl = document.getElementById(`bulk-upload-status-${advertiserId}`);
    const countEl = document.getElementById(`bulk-upload-count-${advertiserId}`);
    const barEl = document.getElementById(`bulk-upload-bar-${advertiserId}`);

    if (progressContainer) {
        progressContainer.style.display = 'block';
        // Build per-file progress items
        let perFileHtml = '';
        validFiles.forEach((file, i) => {
            const sizeMB = (file.size / (1024 * 1024)).toFixed(1);
            perFileHtml += `
                <div id="bulk-item-${advertiserId}-${i}" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;">
                    <span style="flex:1;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;">${file.name}</span>
                    <span style="font-size:11px;color:#94a3b8;min-width:45px;text-align:right;">${sizeMB}MB</span>
                    <div style="width:120px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                        <div id="bulk-bar-${advertiserId}-${i}" style="width:0%;height:100%;background:linear-gradient(90deg,#3b82f6,#60a5fa);border-radius:3px;transition:width 0.2s;"></div>
                    </div>
                    <span id="bulk-status-${advertiserId}-${i}" style="font-size:11px;min-width:70px;text-align:right;color:#94a3b8;">Pending</span>
                </div>
            `;
        });
        let perFileContainer = document.getElementById(`bulk-per-file-${advertiserId}`);
        if (!perFileContainer) {
            perFileContainer = document.createElement('div');
            perFileContainer.id = `bulk-per-file-${advertiserId}`;
            perFileContainer.style.cssText = 'max-height:200px;overflow-y:auto;margin-top:8px;';
            progressContainer.appendChild(perFileContainer);
        }
        perFileContainer.innerHTML = perFileHtml;
    }
    if (statusEl) statusEl.textContent = 'Uploading...';
    if (countEl) countEl.textContent = `0/${validFiles.length}`;
    if (barEl) barEl.style.width = '0%';

    addLog('info', `Starting upload of ${validFiles.length} videos to account ${advertiserId}`);

    // Pre-generate thumbnails for all videos (instant preview)
    if (statusEl) statusEl.textContent = 'Generating previews...';
    const thumbnailMap = new Map();
    for (const file of validFiles) {
        try {
            const thumbnail = await generateVideoThumbnailSafe(file);
            thumbnailMap.set(file, thumbnail);
        } catch (e) {
            console.warn(`[Thumbnail] Failed for ${file.name}:`, e);
            thumbnailMap.set(file, '');
        }
    }
    addLog('info', `Generated ${thumbnailMap.size} thumbnails`);

    let completed = 0;
    let failed = 0;
    const uploadedVideos = [];

    // Upload files sequentially using XMLHttpRequest for per-file progress
    for (let fileIdx = 0; fileIdx < validFiles.length; fileIdx++) {
        const file = validFiles[fileIdx];
        const itemBarEl = document.getElementById(`bulk-bar-${advertiserId}-${fileIdx}`);
        const itemStatusEl = document.getElementById(`bulk-status-${advertiserId}-${fileIdx}`);

        try {
            const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
            const ext = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.')) : '';
            const baseName = file.name.includes('.') ? file.name.slice(0, file.name.lastIndexOf('.')) : file.name;
            const newFileName = `${baseName}_${timestamp}${ext}`;
            const thumbnailUrl = thumbnailMap.get(file) || '';

            const formData = new FormData();
            formData.append('video', file, newFileName);
            formData.append('target_advertiser_id', advertiserId);

            if (statusEl) statusEl.textContent = `Uploading ${file.name}...`;
            if (itemStatusEl) { itemStatusEl.textContent = 'Uploading...'; itemStatusEl.style.color = '#3b82f6'; }

            // Use XMLHttpRequest for real-time progress tracking
            const result = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                const timeoutId = setTimeout(() => xhr.abort(), 300000);

                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        if (itemBarEl) itemBarEl.style.width = `${percent}%`;
                        if (itemStatusEl) itemStatusEl.textContent = percent < 100 ? `${percent}%` : 'Processing...';
                    }
                });

                xhr.addEventListener('load', () => {
                    clearTimeout(timeoutId);
                    try {
                        let parsed;
                        try { parsed = JSON.parse(xhr.responseText); } catch (e) {
                            const m = xhr.responseText.match(/\{[\s\S]*"success"[\s\S]*\}/);
                            parsed = m ? JSON.parse(m[0]) : null;
                        }
                        resolve(parsed);
                    } catch (e) { reject(new Error('Invalid server response')); }
                });

                xhr.addEventListener('error', () => { clearTimeout(timeoutId); reject(new Error('Network error')); });
                xhr.addEventListener('abort', () => { clearTimeout(timeoutId); reject(new Error('Upload timed out')); });

                xhr.open('POST', 'api.php?action=upload_video_to_advertiser');
                xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || '');
                xhr.send(formData);
            });

            console.log(`[Bulk Upload] Response for ${file.name}:`, result);

            if (result && result.success && result.data?.video_id) {
                completed++;
                uploadedVideos.push({
                    video_id: result.data.video_id, file_name: newFileName,
                    video_cover_url: thumbnailUrl, preview_url: thumbnailUrl,
                    type: 'video', is_new: true
                });
                if (itemStatusEl) { itemStatusEl.textContent = '✓ Done'; itemStatusEl.style.color = '#16a34a'; }
                if (itemBarEl) { itemBarEl.style.background = '#22c55e'; itemBarEl.style.width = '100%'; }
                addLog('success', `Uploaded ${newFileName} to ${advertiserId}`);
            } else if (result && result.success && result.processing) {
                completed++;
                uploadedVideos.push({
                    video_id: 'processing_' + Date.now(), file_name: newFileName,
                    video_cover_url: thumbnailUrl, preview_url: thumbnailUrl,
                    type: 'video', is_new: true, is_processing: true
                });
                if (itemStatusEl) { itemStatusEl.textContent = '⏳ Processing'; itemStatusEl.style.color = '#d97706'; }
                if (itemBarEl) { itemBarEl.style.background = '#f59e0b'; itemBarEl.style.width = '100%'; }
                addLog('info', `Video accepted, processing: ${newFileName} on ${advertiserId}`);
            } else if (result && result.success) {
                completed++;
                if (itemStatusEl) { itemStatusEl.textContent = '✓ Accepted'; itemStatusEl.style.color = '#16a34a'; }
                if (itemBarEl) { itemBarEl.style.width = '100%'; }
                addLog('info', `Video accepted: ${newFileName} on ${advertiserId}`);
            } else {
                failed++;
                if (itemStatusEl) { itemStatusEl.textContent = '✗ Failed'; itemStatusEl.style.color = '#dc2626'; }
                if (itemBarEl) { itemBarEl.style.background = '#ef4444'; itemBarEl.style.width = '100%'; }
                addLog('error', `Failed to upload ${file.name}: ${result?.message || 'Upload failed'}`);
            }
        } catch (error) {
            failed++;
            if (itemStatusEl) { itemStatusEl.textContent = '✗ Error'; itemStatusEl.style.color = '#dc2626'; }
            if (itemBarEl) { itemBarEl.style.background = '#ef4444'; itemBarEl.style.width = '100%'; }
            addLog('error', `Error uploading ${file.name}: ${error.message}`);
        }

        // Update overall progress
        const totalProcessed = completed + failed;
        if (countEl) countEl.textContent = `${totalProcessed}/${validFiles.length}`;
        if (barEl) barEl.style.width = `${(totalProcessed / validFiles.length) * 100}%`;

        if (totalProcessed < validFiles.length) {
            await new Promise(resolve => setTimeout(resolve, 300));
        }
    }

    // Final status
    if (statusEl) {
        statusEl.textContent = failed === 0
            ? `✓ Uploaded ${completed} videos`
            : `Uploaded ${completed}, failed ${failed}`;
    }

    // Add uploaded videos to account assets
    if (uploadedVideos.length > 0) {
        // Initialize accountAssets for this advertiser if it doesn't exist
        if (!bulkLaunchState.accountAssets[advertiserId]) {
            bulkLaunchState.accountAssets[advertiserId] = {
                videos: [],
                images: [],
                pixels: [],
                identities: [],
                portfolios: [],
                videoMatch: { match_rate: 0 }
            };
        }
        if (!bulkLaunchState.accountAssets[advertiserId].videos) {
            bulkLaunchState.accountAssets[advertiserId].videos = [];
        }

        // Add uploaded videos to the beginning
        bulkLaunchState.accountAssets[advertiserId].videos.unshift(...uploadedVideos);

        console.log(`[Bulk Upload] Added ${uploadedVideos.length} videos to account ${advertiserId}. Total videos:`, bulkLaunchState.accountAssets[advertiserId].videos.length);

        // Update the video picker state if it's open for this account
        if (videoPickerState.advertiserId === advertiserId) {
            videoPickerState.videos = [...bulkLaunchState.accountAssets[advertiserId].videos];
            renderVideoPickerGrid();
        }

        // Update the media library UI to show newly uploaded videos immediately
        updateAccountMediaLibraryUI(advertiserId);

        showToast(`Uploaded ${uploadedVideos.length} videos successfully! Select one to use.`, 'success');
    } else if (failed > 0) {
        showToast(`Failed to upload ${failed} video(s). Please try again.`, 'error');
    }

    // Clear file input
    event.target.value = '';
}

// Use original campaign videos for a bulk launch account
// Downloads each source video from the original account and uploads it to the target account
async function useOriginalVideoForAccount(advertiserId) {
    if (!state.selectedVideos || state.selectedVideos.length === 0) {
        showToast('No videos in the original campaign to copy', 'error');
        return;
    }

    const btn = document.querySelector(`[onclick="useOriginalVideoForAccount('${advertiserId}')"]`);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Copying...';
    }

    // Show progress area
    const progressContainer = document.getElementById(`bulk-upload-progress-${advertiserId}`);
    const statusEl = document.getElementById(`bulk-upload-status-${advertiserId}`);
    const countEl = document.getElementById(`bulk-upload-count-${advertiserId}`);
    const barEl = document.getElementById(`bulk-upload-bar-${advertiserId}`);

    if (progressContainer) progressContainer.style.display = 'block';
    if (statusEl) statusEl.textContent = 'Copying original videos...';
    if (countEl) countEl.textContent = `0/${state.selectedVideos.length}`;
    if (barEl) barEl.style.width = '0%';

    // Build per-file progress items
    let perFileHtml = '';
    state.selectedVideos.forEach((video, i) => {
        perFileHtml += `
            <div id="bulk-item-${advertiserId}-orig-${i}" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;">
                <span style="flex:1;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;">${video.name || 'Video ' + (i + 1)}</span>
                <div style="width:120px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                    <div id="bulk-bar-${advertiserId}-orig-${i}" style="width:0%;height:100%;background:linear-gradient(90deg,#8b5cf6,#a78bfa);border-radius:3px;transition:width 0.2s;"></div>
                </div>
                <span id="bulk-status-${advertiserId}-orig-${i}" style="font-size:11px;min-width:70px;text-align:right;color:#94a3b8;">Pending</span>
            </div>
        `;
    });
    let perFileContainer = document.getElementById(`bulk-per-file-${advertiserId}`);
    if (!perFileContainer) {
        perFileContainer = document.createElement('div');
        perFileContainer.id = `bulk-per-file-${advertiserId}`;
        perFileContainer.style.cssText = 'max-height:200px;overflow-y:auto;margin-top:8px;';
        progressContainer.appendChild(perFileContainer);
    }
    perFileContainer.innerHTML = perFileHtml;

    addLog('info', `Copying ${state.selectedVideos.length} original videos to account ${advertiserId}`);

    let completed = 0;
    let failed = 0;
    const uploadedVideos = [];

    // For each original video, fetch it from original account and upload to target
    for (let i = 0; i < state.selectedVideos.length; i++) {
        const sourceVideo = state.selectedVideos[i];
        const itemBarEl = document.getElementById(`bulk-bar-${advertiserId}-orig-${i}`);
        const itemStatusEl = document.getElementById(`bulk-status-${advertiserId}-orig-${i}`);

        try {
            if (itemStatusEl) { itemStatusEl.textContent = 'Fetching...'; itemStatusEl.style.color = '#8b5cf6'; }
            if (itemBarEl) itemBarEl.style.width = '20%';

            // Step 1: Get the video download URL from the original account
            const infoResult = await apiRequest('get_video_download_url', {
                video_id: sourceVideo.id,
                _advertiser_id: window.TIKTOK_ADVERTISER_ID
            });

            if (!infoResult.success || !infoResult.data?.video_url) {
                throw new Error(infoResult.message || 'Could not get video URL');
            }

            if (itemStatusEl) { itemStatusEl.textContent = 'Downloading...'; }
            if (itemBarEl) itemBarEl.style.width = '40%';

            // Step 2: Download the video as a blob
            const videoResponse = await fetch(infoResult.data.video_url);
            if (!videoResponse.ok) throw new Error('Failed to download video');
            const videoBlob = await videoResponse.blob();

            if (itemStatusEl) { itemStatusEl.textContent = 'Uploading...'; }
            if (itemBarEl) itemBarEl.style.width = '60%';

            // Step 3: Upload to target account
            const fileName = sourceVideo.name || `video_${Date.now()}.mp4`;
            const formData = new FormData();
            formData.append('video', videoBlob, fileName);
            formData.append('target_advertiser_id', advertiserId);

            const uploadResult = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                const timeoutId = setTimeout(() => xhr.abort(), 300000);

                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percent = 60 + Math.round((e.loaded / e.total) * 35);
                        if (itemBarEl) itemBarEl.style.width = `${percent}%`;
                    }
                });

                xhr.addEventListener('load', () => {
                    clearTimeout(timeoutId);
                    try {
                        let parsed;
                        try { parsed = JSON.parse(xhr.responseText); } catch (e) {
                            const m = xhr.responseText.match(/\{[\s\S]*"success"[\s\S]*\}/);
                            parsed = m ? JSON.parse(m[0]) : null;
                        }
                        resolve(parsed);
                    } catch (e) { reject(new Error('Invalid server response')); }
                });

                xhr.addEventListener('error', () => { clearTimeout(timeoutId); reject(new Error('Network error')); });
                xhr.addEventListener('abort', () => { clearTimeout(timeoutId); reject(new Error('Upload timed out')); });

                xhr.open('POST', 'api.php?action=upload_video_to_advertiser');
                xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || '');
                xhr.send(formData);
            });

            if (uploadResult && uploadResult.success && uploadResult.data?.video_id) {
                completed++;
                const newVideoId = uploadResult.data.video_id;
                uploadedVideos.push({
                    video_id: newVideoId, file_name: fileName,
                    video_cover_url: sourceVideo.url || '', preview_url: sourceVideo.url || '',
                    type: 'video', is_new: true
                });

                // Auto-map this video: sourceVideoId → newTargetVideoId
                let selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
                if (selectedAccount) {
                    if (!selectedAccount.video_mapping) selectedAccount.video_mapping = {};
                    selectedAccount.video_mapping[sourceVideo.id] = newVideoId;
                }

                if (itemStatusEl) { itemStatusEl.textContent = '✓ Done'; itemStatusEl.style.color = '#16a34a'; }
                if (itemBarEl) { itemBarEl.style.background = '#22c55e'; itemBarEl.style.width = '100%'; }
                addLog('success', `Copied ${fileName} to ${advertiserId} → ${newVideoId}`);
            } else if (uploadResult && uploadResult.success && uploadResult.processing) {
                completed++;
                const tempId = 'processing_' + Date.now() + '_' + i;
                uploadedVideos.push({
                    video_id: tempId, file_name: fileName,
                    video_cover_url: sourceVideo.url || '', preview_url: sourceVideo.url || '',
                    type: 'video', is_new: true, is_processing: true
                });
                if (itemStatusEl) { itemStatusEl.textContent = '⏳ Processing'; itemStatusEl.style.color = '#d97706'; }
                if (itemBarEl) { itemBarEl.style.background = '#f59e0b'; itemBarEl.style.width = '100%'; }
                addLog('info', `Video accepted, processing: ${fileName} on ${advertiserId}`);
            } else {
                throw new Error(uploadResult?.message || 'Upload failed');
            }
        } catch (error) {
            failed++;
            if (itemStatusEl) { itemStatusEl.textContent = '✗ ' + error.message; itemStatusEl.style.color = '#dc2626'; }
            if (itemBarEl) { itemBarEl.style.background = '#ef4444'; itemBarEl.style.width = '100%'; }
            addLog('error', `Failed to copy video to ${advertiserId}: ${error.message}`);
        }

        // Update overall progress
        const totalProcessed = completed + failed;
        if (countEl) countEl.textContent = `${totalProcessed}/${state.selectedVideos.length}`;
        if (barEl) barEl.style.width = `${(totalProcessed / state.selectedVideos.length) * 100}%`;
    }

    // Final status
    if (statusEl) {
        statusEl.textContent = failed === 0
            ? `✓ Copied ${completed} videos`
            : `Copied ${completed}, failed ${failed}`;
    }

    // Add to account assets and update video match status
    if (uploadedVideos.length > 0) {
        if (!bulkLaunchState.accountAssets[advertiserId]) {
            bulkLaunchState.accountAssets[advertiserId] = {
                videos: [], images: [], pixels: [], identities: [], portfolios: [],
                videoMatch: { match_rate: 0 }
            };
        }
        if (!bulkLaunchState.accountAssets[advertiserId].videos) {
            bulkLaunchState.accountAssets[advertiserId].videos = [];
        }
        bulkLaunchState.accountAssets[advertiserId].videos.unshift(...uploadedVideos);

        // Update video match status
        updateVideoMatchStatus(advertiserId);

        // Update media library UI
        updateAccountMediaLibraryUI(advertiserId);

        // Update bulk modal counts
        updateBulkModalCounts();

        showToast(`Copied ${uploadedVideos.length} original videos to account!`, 'success');
    }

    // Restore button
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '🔄 Use Original';
    }
}

// Refresh video list in the video picker by fetching from TikTok API
async function refreshVideoPickerList() {
    const advertiserId = videoPickerState.advertiserId;
    if (!advertiserId) {
        showToast('Error: No account selected', 'error');
        return;
    }

    const grid = document.getElementById('picker-video-grid');
    const countEl = document.getElementById('picker-video-count');

    // Show loading state
    grid.innerHTML = `
        <div class="picker-empty-state">
            <div class="empty-icon" style="animation: spin 1s linear infinite;">🔄</div>
            <p>Fetching videos from TikTok...</p>
        </div>
    `;
    countEl.textContent = 'Loading...';

    try {
        addLog('info', `Refreshing videos for account ${advertiserId}`);

        // Use get_videos action with force_refresh to get fresh data from TikTok
        const result = await apiRequest('get_videos', {
            advertiser_id: advertiserId,
            force_refresh: true
        });

        if (result.success && result.data) {
            // Handle both response formats: data as array directly OR data.videos
            const videos = Array.isArray(result.data) ? result.data : (result.data.videos || []);

            // Update account assets
            if (!bulkLaunchState.accountAssets[advertiserId]) {
                bulkLaunchState.accountAssets[advertiserId] = {
                    videos: [],
                    images: [],
                    pixels: [],
                    identities: [],
                    portfolios: [],
                    videoMatch: { match_rate: 0 }
                };
            }

            // Merge with any locally uploaded videos (preserve thumbnails and is_new flag)
            const existingNewVideos = (bulkLaunchState.accountAssets[advertiserId].videos || [])
                .filter(v => v.is_new);

            // Create maps for both video IDs and filenames to catch all duplicates
            const newVideoIds = new Set(existingNewVideos.map(v => v.video_id));
            const newVideoFileNames = new Map(existingNewVideos.map(v => [v.file_name?.toLowerCase(), v]));

            // Process API videos - update local processing videos with real IDs when matched by filename
            const mergedVideos = [...existingNewVideos];
            videos.forEach(apiVideo => {
                const apiFileName = apiVideo.file_name?.toLowerCase();
                const localMatch = newVideoFileNames.get(apiFileName);

                if (localMatch && localMatch.video_id?.startsWith('processing_')) {
                    // Found matching local processing video - update it with real ID
                    // but KEEP the local thumbnail if API doesn't have one yet
                    localMatch.video_id = apiVideo.video_id;
                    if (apiVideo.video_cover_url && !apiVideo.video_cover_url.startsWith('blob:')) {
                        // API has a real TikTok thumbnail - use it
                        localMatch.video_cover_url = apiVideo.video_cover_url;
                        localMatch.preview_url = apiVideo.video_cover_url;
                    }
                    // Keep is_new and is_processing flags, clear processing flag now that we have real ID
                    delete localMatch.is_processing;
                    console.log(`[Merge] Updated local video with real ID: ${apiVideo.video_id}, thumbnail: ${localMatch.video_cover_url ? 'kept' : 'none'}`);
                } else if (!newVideoIds.has(apiVideo.video_id)) {
                    // New video from API - add it
                    mergedVideos.push(apiVideo);
                }
            });

            bulkLaunchState.accountAssets[advertiserId].videos = mergedVideos;

            // Update picker state - newly uploaded first, then preserve order
            videoPickerState.videos = [...mergedVideos].sort((a, b) => {
                if (a.is_new && !b.is_new) return -1;
                if (!a.is_new && b.is_new) return 1;
                return 0;
            });

            renderVideoPickerGrid();

            addLog('success', `Loaded ${videos.length} videos for account ${advertiserId}`);
            showToast(`Loaded ${videos.length} videos`, 'success');
        } else {
            throw new Error(result.message || 'Failed to load videos');
        }
    } catch (error) {
        console.error('Error refreshing videos:', error);
        addLog('error', `Failed to refresh videos: ${error.message}`);
        showToast('Failed to load videos', 'error');

        grid.innerHTML = `
            <div class="picker-empty-state">
                <div class="empty-icon">❌</div>
                <p>Failed to load videos. Please try again.</p>
                <button type="button" onclick="refreshVideoPickerList()"
                        style="margin-top: 10px; padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                    Retry
                </button>
            </div>
        `;
    }
}

// Handle video upload from within the video picker modal
async function handlePickerVideoUpload(event) {
    const advertiserId = videoPickerState.advertiserId;
    if (!advertiserId) {
        showToast('Error: No account selected', 'error');
        event.target.value = '';
        return;
    }

    const files = Array.from(event.target.files);
    if (files.length === 0) return;

    // Validate files
    const maxSize = 500 * 1024 * 1024; // 500MB
    const validFiles = files.filter(file => {
        if (!file.type.startsWith('video/')) {
            showToast(`Skipped ${file.name}: Not a video file`, 'warning');
            return false;
        }
        if (file.size > maxSize) {
            showToast(`Skipped ${file.name}: Exceeds 500MB limit`, 'warning');
            return false;
        }
        return true;
    });

    if (validFiles.length === 0) {
        showToast('No valid video files selected', 'error');
        event.target.value = '';
        return;
    }

    // Pre-generate thumbnails for all files BEFORE uploading (for instant preview)
    const thumbnailMap = new Map();
    console.log('[Picker Upload] Pre-generating thumbnails for', validFiles.length, 'files');
    await Promise.all(validFiles.map(async (file) => {
        const thumbnail = await generateVideoThumbnailSafe(file);
        if (thumbnail) {
            thumbnailMap.set(file, thumbnail);
        }
    }));
    console.log('[Picker Upload] Generated', thumbnailMap.size, 'thumbnails');

    // Show per-file progress UI
    const progressContainer = document.getElementById('picker-upload-progress');
    const statusEl = document.getElementById('picker-upload-status');
    const countEl = document.getElementById('picker-upload-count');
    const barEl = document.getElementById('picker-upload-bar');
    const uploadBtn = document.getElementById('picker-upload-btn');

    if (progressContainer) {
        progressContainer.style.display = 'block';
        // Build per-file progress items
        let perFileHtml = '';
        validFiles.forEach((file, i) => {
            const sizeMB = (file.size / (1024 * 1024)).toFixed(1);
            perFileHtml += `
                <div id="picker-upload-item-${i}" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;">
                    <span style="flex:1;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;">${file.name}</span>
                    <span style="font-size:11px;color:#94a3b8;min-width:45px;text-align:right;">${sizeMB}MB</span>
                    <div style="width:120px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                        <div id="picker-bar-${i}" style="width:0%;height:100%;background:linear-gradient(90deg,#3b82f6,#60a5fa);border-radius:3px;transition:width 0.2s;"></div>
                    </div>
                    <span id="picker-status-${i}" style="font-size:11px;min-width:70px;text-align:right;color:#94a3b8;">Pending</span>
                </div>
            `;
        });
        // Insert per-file list after the overall progress bar
        let perFileContainer = document.getElementById('picker-per-file-list');
        if (!perFileContainer) {
            perFileContainer = document.createElement('div');
            perFileContainer.id = 'picker-per-file-list';
            perFileContainer.style.cssText = 'max-height:200px;overflow-y:auto;margin-top:8px;';
            progressContainer.appendChild(perFileContainer);
        }
        perFileContainer.innerHTML = perFileHtml;
    }
    if (uploadBtn) uploadBtn.disabled = true;
    if (statusEl) statusEl.textContent = 'Uploading...';
    if (countEl) countEl.textContent = `0/${validFiles.length}`;
    if (barEl) barEl.style.width = '0%';

    addLog('info', `Starting upload of ${validFiles.length} videos to account ${advertiserId} from picker`);

    let completed = 0;
    let failed = 0;
    const uploadedVideos = [];

    // Upload files sequentially using XMLHttpRequest for per-file progress
    for (let fileIdx = 0; fileIdx < validFiles.length; fileIdx++) {
        const file = validFiles[fileIdx];
        const itemBarEl = document.getElementById(`picker-bar-${fileIdx}`);
        const itemStatusEl = document.getElementById(`picker-status-${fileIdx}`);

        try {
            const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
            const ext = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.')) : '';
            const baseName = file.name.includes('.') ? file.name.slice(0, file.name.lastIndexOf('.')) : file.name;
            const newFileName = `${baseName}_${timestamp}${ext}`;

            const formData = new FormData();
            formData.append('video', file, newFileName);
            formData.append('target_advertiser_id', advertiserId);

            if (statusEl) statusEl.textContent = `Uploading ${file.name}...`;
            if (itemStatusEl) { itemStatusEl.textContent = 'Uploading...'; itemStatusEl.style.color = '#3b82f6'; }

            // Use XMLHttpRequest for real-time progress tracking
            const result = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                const timeoutId = setTimeout(() => xhr.abort(), 300000); // 5 min timeout

                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        if (itemBarEl) itemBarEl.style.width = `${percent}%`;
                        if (itemStatusEl) itemStatusEl.textContent = percent < 100 ? `${percent}%` : 'Processing...';
                    }
                });

                xhr.addEventListener('load', () => {
                    clearTimeout(timeoutId);
                    try {
                        let parsed;
                        try { parsed = JSON.parse(xhr.responseText); } catch (e) {
                            const m = xhr.responseText.match(/\{[\s\S]*"success"[\s\S]*\}/);
                            parsed = m ? JSON.parse(m[0]) : null;
                        }
                        resolve(parsed);
                    } catch (e) { reject(new Error('Invalid server response')); }
                });

                xhr.addEventListener('error', () => { clearTimeout(timeoutId); reject(new Error('Network error')); });
                xhr.addEventListener('abort', () => { clearTimeout(timeoutId); reject(new Error('Upload timed out')); });

                xhr.open('POST', 'api.php?action=upload_video_to_advertiser');
                xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || '');
                xhr.send(formData);
            });

            console.log(`[Picker Upload] Response for ${file.name}:`, result);

            if (result && result.success && result.data?.video_id) {
                completed++;
                const previewUrl = thumbnailMap.get(file) || '';
                uploadedVideos.push({
                    video_id: result.data.video_id, file_name: newFileName,
                    video_cover_url: previewUrl, preview_url: previewUrl,
                    type: 'video', is_new: true
                });
                if (itemStatusEl) { itemStatusEl.textContent = '✓ Done'; itemStatusEl.style.color = '#16a34a'; }
                if (itemBarEl) { itemBarEl.style.background = '#22c55e'; itemBarEl.style.width = '100%'; }
                addLog('success', `Uploaded ${newFileName} to ${advertiserId}`);
            } else if (result && result.success && result.processing) {
                completed++;
                const previewUrl = thumbnailMap.get(file) || '';
                uploadedVideos.push({
                    video_id: 'processing_' + Date.now(), file_name: newFileName,
                    video_cover_url: previewUrl, preview_url: previewUrl,
                    type: 'video', is_new: true, is_processing: true
                });
                if (itemStatusEl) { itemStatusEl.textContent = '⏳ Processing'; itemStatusEl.style.color = '#d97706'; }
                if (itemBarEl) { itemBarEl.style.background = '#f59e0b'; itemBarEl.style.width = '100%'; }
                addLog('info', `Video accepted, processing: ${newFileName} on ${advertiserId}`);
            } else if (result && result.success) {
                completed++;
                if (itemStatusEl) { itemStatusEl.textContent = '✓ Accepted'; itemStatusEl.style.color = '#16a34a'; }
                if (itemBarEl) { itemBarEl.style.width = '100%'; }
                addLog('info', `Video accepted: ${newFileName} on ${advertiserId}`);
            } else {
                failed++;
                const errorMsg = result?.message || 'Upload failed';
                if (itemStatusEl) { itemStatusEl.textContent = '✗ Failed'; itemStatusEl.style.color = '#dc2626'; }
                if (itemBarEl) { itemBarEl.style.background = '#ef4444'; itemBarEl.style.width = '100%'; }
                addLog('error', `Failed to upload ${file.name}: ${errorMsg}`);
            }
        } catch (error) {
            failed++;
            if (itemStatusEl) { itemStatusEl.textContent = '✗ Error'; itemStatusEl.style.color = '#dc2626'; }
            if (itemBarEl) { itemBarEl.style.background = '#ef4444'; itemBarEl.style.width = '100%'; }
            addLog('error', `Error uploading ${file.name}: ${error.message}`);
        }

        // Update overall progress
        const totalProcessed = completed + failed;
        if (countEl) countEl.textContent = `${totalProcessed}/${validFiles.length}`;
        if (barEl) barEl.style.width = `${(totalProcessed / validFiles.length) * 100}%`;

        if (totalProcessed < validFiles.length) {
            await new Promise(resolve => setTimeout(resolve, 300));
        }
    }

    // Final status
    if (statusEl) {
        statusEl.textContent = failed === 0
            ? `✓ Uploaded ${completed} videos`
            : `Uploaded ${completed}, failed ${failed}`;
    }
    if (uploadBtn) uploadBtn.disabled = false;

    // Add uploaded videos to account assets and re-render
    if (uploadedVideos.length > 0) {
        // Initialize accountAssets if needed
        if (!bulkLaunchState.accountAssets[advertiserId]) {
            bulkLaunchState.accountAssets[advertiserId] = {
                videos: [],
                images: [],
                pixels: [],
                identities: [],
                portfolios: [],
                videoMatch: { match_rate: 0 }
            };
        }
        if (!bulkLaunchState.accountAssets[advertiserId].videos) {
            bulkLaunchState.accountAssets[advertiserId].videos = [];
        }

        // Add uploaded videos to the beginning
        bulkLaunchState.accountAssets[advertiserId].videos.unshift(...uploadedVideos);

        // Update picker state and re-render immediately
        videoPickerState.videos = [...bulkLaunchState.accountAssets[advertiserId].videos];
        renderVideoPickerGrid();

        // Update the media library UI to show newly uploaded videos immediately
        updateAccountMediaLibraryUI(advertiserId);

        // Auto-select the first uploaded video if we have one with a real video ID
        const hasRealVideoId = uploadedVideos.some(v => v.video_id && !v.video_id.startsWith('processing_'));

        if (hasRealVideoId) {
            const firstRealVideo = uploadedVideos.find(v => v.video_id && !v.video_id.startsWith('processing_'));
            videoPickerState.currentSelection = firstRealVideo.video_id;
            renderVideoPickerGrid();  // Re-render to show selection

            // Actually select it in the mapping
            selectVideoFromPicker(firstRealVideo.video_id, firstRealVideo.file_name);
            showToast(`✓ Video uploaded and selected!`, 'success');
        } else {
            // Video is still processing - show message and schedule auto-refresh
            showToast(`Video uploaded! Processing on TikTok... Click Refresh in ~30 seconds to select it.`, 'info');

            // Auto-refresh after 30 seconds to check if video is ready
            setTimeout(() => {
                if (videoPickerState.advertiserId === advertiserId) {
                    addLog('info', 'Auto-refreshing video list to check for processed videos...');
                    refreshVideoPickerList();
                }
            }, 30000);
        }

        // Hide progress after a moment
        setTimeout(() => {
            if (progressContainer) progressContainer.style.display = 'none';
        }, 2000);
    } else if (failed > 0) {
        showToast(`Failed to upload ${failed} video(s). Please try again.`, 'error');
    }

    // Clear file input
    event.target.value = '';
}

// Toggle media library visibility
function toggleMediaLibrary(advertiserId) {
    if (!bulkLaunchState.expandedLibraries) {
        bulkLaunchState.expandedLibraries = {};
    }

    const library = document.getElementById(`media-library-${advertiserId}`);
    if (!library) return;

    const isExpanded = library.style.display !== 'none';
    library.style.display = isExpanded ? 'none' : 'block';
    bulkLaunchState.expandedLibraries[advertiserId] = !isExpanded;

    // Update button text
    const btn = document.querySelector(`#bulk-account-${advertiserId} .btn-toggle-library`);
    if (btn) {
        btn.textContent = isExpanded ? '📁 View Library' : '📁 Hide Library';
    }
}

// Update the media library UI after video upload (immediate rendering)
function updateAccountMediaLibraryUI(advertiserId) {
    const assets = bulkLaunchState.accountAssets[advertiserId];
    if (!assets) return;

    const videos = assets.videos || [];
    const images = assets.images || [];

    // Update video count in header
    const videoCountEl = document.querySelector(`#media-library-${advertiserId} .video-count`);
    if (videoCountEl) {
        videoCountEl.textContent = `🎬 ${videos.length} videos`;
    }

    // Update "All Videos in Account" section title
    const videoSectionTitle = document.querySelector(`#media-library-${advertiserId} .media-section-title`);
    if (videoSectionTitle && videoSectionTitle.textContent.includes('All Videos')) {
        videoSectionTitle.textContent = `All Videos in Account (${videos.length})`;
    }

    // Find and update the video grid
    const libraryEl = document.getElementById(`media-library-${advertiserId}`);
    if (libraryEl) {
        // Get the video match info for proper rendering
        const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
        const videoMapping = selectedAccount?.video_mapping || {};
        const matchedTargetIds = new Set(Object.values(videoMapping));

        // Find the video grid container
        const videoGrids = libraryEl.querySelectorAll('.media-grid');
        if (videoGrids.length > 0) {
            const videoGrid = videoGrids[0]; // First grid is for videos

            if (videos.length === 0) {
                videoGrid.innerHTML = '<p class="no-media-text">No videos in this account</p>';
            } else {
                let html = '';
                videos.forEach(video => {
                    const isUsedInCampaign = matchedTargetIds.has(video.video_id);
                    const isNew = video.is_new;
                    html += `
                        <div class="media-item video-item ${isUsedInCampaign ? 'used-in-campaign' : ''} ${isNew ? 'newly-uploaded' : ''}">
                            <div class="media-thumb">
                                ${video.video_cover_url ? `<img src="${video.video_cover_url}" alt="">` : '<div class="media-placeholder">🎬</div>'}
                                ${isUsedInCampaign ? '<span class="used-badge">✓</span>' : ''}
                                ${isNew ? '<span class="new-badge">NEW</span>' : ''}
                            </div>
                            <div class="media-name">${(video.file_name || 'Video').substring(0, 15)}${video.file_name?.length > 15 ? '...' : ''}</div>
                        </div>
                    `;
                });
                videoGrid.innerHTML = html;
            }
        }
    }

    console.log(`[Media Library] Updated UI for account ${advertiserId}: ${videos.length} videos`);
}

// Update video mapping when user manually selects a video
function updateVideoMapping(advertiserId, sourceVideoId, targetVideoId) {
    console.log('updateVideoMapping called:', { advertiserId, sourceVideoId, targetVideoId });

    let selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!selectedAccount) {
        // Add to selected accounts if not already there
        const account = bulkLaunchState.accounts.find(a => a.advertiser_id === advertiserId);
        if (account) {
            selectedAccount = {
                advertiser_id: advertiserId,
                advertiser_name: account.advertiser_name,
                pixel_id: null,
                identity_id: null,
                identity_type: 'CUSTOMIZED_USER',
                video_mapping: {}
            };
            bulkLaunchState.selectedAccounts.push(selectedAccount);
        }
    }

    if (selectedAccount) {
        if (!selectedAccount.video_mapping) {
            selectedAccount.video_mapping = {};
        }

        if (targetVideoId) {
            selectedAccount.video_mapping[sourceVideoId] = targetVideoId;
            console.log('Video mapping set:', selectedAccount.video_mapping);
        } else {
            delete selectedAccount.video_mapping[sourceVideoId];
            console.log('Video mapping removed for:', sourceVideoId);
        }

        // Update the video match status in account assets
        updateVideoMatchStatus(advertiserId);

        // Update UI
        const statusEl = document.getElementById(`status-${advertiserId}`);
        if (statusEl) {
            statusEl.innerHTML = getAccountStatus(advertiserId);
        }

        updateBulkModalCounts();

        addLog('info', `Manual video mapping for ${advertiserId}: ${sourceVideoId} -> ${targetVideoId}`);
    }
}

// Update video match status based on manual mappings
function updateVideoMatchStatus(advertiserId) {
    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!selectedAccount || !bulkLaunchState.accountAssets[advertiserId]) return;

    const videoMapping = selectedAccount.video_mapping || {};
    const totalRequired = state.selectedVideos.length;
    const mappedCount = Object.keys(videoMapping).filter(k => videoMapping[k]).length;

    // Update the videoMatch object
    if (!bulkLaunchState.accountAssets[advertiserId].videoMatch) {
        bulkLaunchState.accountAssets[advertiserId].videoMatch = {
            matched: [],
            unmatched: [],
            total_source: totalRequired,
            match_rate: 0
        };
    }

    bulkLaunchState.accountAssets[advertiserId].videoMatch.match_rate =
        totalRequired > 0 ? Math.round(mappedCount / totalRequired * 100) : 0;

    // Update the video match status display
    const matchStatusEl = document.querySelector(`#assets-${advertiserId} .video-match-status`);
    if (matchStatusEl) {
        const matchRate = bulkLaunchState.accountAssets[advertiserId].videoMatch.match_rate;
        const statusClass = matchRate === 100 ? 'success' : matchRate > 0 ? 'warning' : 'error';
        matchStatusEl.className = `asset-row video-match-status ${statusClass}`;

        const matchIcon = matchStatusEl.querySelector('.match-icon');
        if (matchIcon) {
            matchIcon.textContent = matchRate === 100 ? '✓' : matchRate > 0 ? '⚠' : '✗';
        }

        // Find the span with video count text (second span element)
        const spans = matchStatusEl.querySelectorAll('span');
        if (spans.length >= 2) {
            spans[1].textContent = `Videos: ${mappedCount}/${totalRequired} matched (${matchRate}%)`;
        }
    }

    // Also update the campaign video rows to show matched/unmatched status
    // videoMapping already declared above, reuse it
    state.selectedVideos.forEach(sourceVideo => {
        const rowEl = document.querySelector(`#video-map-${advertiserId}-${sourceVideo.id}`)?.closest('.campaign-video-row');
        if (rowEl) {
            const isMapped = !!videoMapping[sourceVideo.id];
            rowEl.classList.toggle('matched', isMapped);
            rowEl.classList.toggle('unmatched', !isMapped);

            const statusEl = rowEl.querySelector('.campaign-video-status');
            if (statusEl) {
                statusEl.className = `campaign-video-status ${isMapped ? 'matched' : 'unmatched'}`;
                statusEl.innerHTML = isMapped
                    ? '<span class="status-icon">✓</span> Mapped'
                    : '<span class="status-icon">!</span> Needs mapping';
            }
        }
    });
}

// Get account status text
function getAccountStatus(advertiserId) {
    const account = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!account) return '';

    // Original account is always ready (campaign was configured there)
    if (account.is_original) {
        return '<span class="status-ready">✓ Ready (campaign configured here)</span>';
    }

    const hasPixel = !!account.pixel_id;
    const hasIdentity = !!account.identity_id;
    const hasPortfolio = !!account.portfolio_id; // Can be a portfolio ID or "auto_create"
    const videoMatch = bulkLaunchState.accountAssets[advertiserId]?.videoMatch;
    const hasVideos = videoMatch && videoMatch.match_rate === 100;

    if (hasPixel && hasIdentity && hasPortfolio && hasVideos) {
        return '<span class="status-ready">✓ Ready to launch</span>';
    } else {
        const missing = [];
        if (!hasPixel) missing.push('pixel');
        if (!hasIdentity) missing.push('identity');
        if (!hasPortfolio) missing.push('CTA portfolio');
        if (!hasVideos) missing.push('videos');
        return `<span class="status-incomplete">Missing: ${missing.join(', ')}</span>`;
    }
}

// Load assets for a specific account
async function loadAccountAssets(advertiserId) {
    const assetsContainer = document.getElementById(`assets-${advertiserId}`);
    const loadButton = document.querySelector(`#bulk-account-${advertiserId} .btn-load-assets`);

    if (loadButton) loadButton.style.display = 'none';
    assetsContainer.style.display = 'block';
    assetsContainer.innerHTML = '<div class="loading-assets"><div class="spinner-small"></div> Loading assets...</div>';

    try {
        // Load assets
        console.log(`[Bulk Launch] Loading assets for account: ${advertiserId}`);
        const result = await apiRequest('get_account_assets', { target_advertiser_id: advertiserId });
        console.log(`[Bulk Launch] API response for ${advertiserId}:`, result);

        if (result.success && result.data) {
            // Videos come from API sorted by create_time descending (newest first) - keep that order

            bulkLaunchState.accountAssets[advertiserId] = result.data;

            // Log detailed asset counts
            const data = result.data;
            console.log(`[Bulk Launch] Assets for ${advertiserId}:`, {
                pixels: data.pixels?.length || 0,
                identities: data.identities?.length || 0,
                videos: data.videos?.length || 0,
                images: data.images?.length || 0,
                errors: data.errors || 'none'
            });

            // Also match videos
            await matchVideosForAccount(advertiserId);

            // Auto-match assets (pixel, identity, portfolio) by name
            autoMatchAssetsByName(advertiserId, result.data);

            // Render dropdowns
            assetsContainer.innerHTML = renderAccountAssetsDropdowns(advertiserId, result.data);

            // Show toggle button now that assets are loaded
            const toggleBtn = document.getElementById(`toggle-btn-${advertiserId}`);
            if (toggleBtn) toggleBtn.style.display = 'inline-block';

            // Log with any errors
            const hasErrors = data.errors && Object.keys(data.errors).length > 0;
            if (hasErrors) {
                addLog('warning', `Loaded assets for ${advertiserId} with errors: ${JSON.stringify(data.errors)}`);
            } else {
                addLog('info', `Loaded assets for ${advertiserId}: ${data.pixels?.length || 0} pixels, ${data.identities?.length || 0} identities`);
            }
        } else {
            const errorMsg = result.message || 'Unknown error';
            console.error(`[Bulk Launch] Failed to load assets for ${advertiserId}:`, errorMsg);
            assetsContainer.innerHTML = renderAssetLoadError(advertiserId, errorMsg);
            addLog('error', `Failed to load assets for ${advertiserId}: ${errorMsg}`);
        }
    } catch (error) {
        // Handle any type of error - ensure we have a message
        const errorMsg = error?.message || error?.toString() || 'Network or server error';
        console.error(`[Bulk Launch] Exception loading assets for ${advertiserId}:`, error);
        assetsContainer.innerHTML = renderAssetLoadError(advertiserId, errorMsg);
        addLog('error', `Error loading assets for ${advertiserId}: ${errorMsg}`);
    }
}

// Render error state with retry button
function renderAssetLoadError(advertiserId, errorMsg) {
    return `
        <div class="asset-load-error" style="text-align: center; padding: 15px;">
            <p class="error-text" style="margin-bottom: 10px;">Failed to load assets: ${errorMsg}</p>
            <button type="button" class="btn-retry-assets" onclick="retryLoadAssets('${advertiserId}')"
                    style="background: var(--primary); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 12px;">
                🔄 Retry
            </button>
        </div>
    `;
}

// Retry loading assets for an account
async function retryLoadAssets(advertiserId) {
    // Clear any cached error state
    delete bulkLaunchState.accountAssets[advertiserId];
    // Reload assets
    await loadAccountAssets(advertiserId);
}

// Match videos for an account
async function matchVideosForAccount(advertiserId) {
    if (state.selectedVideos.length === 0) return;

    try {
        const sourceVideos = state.selectedVideos.map(v => ({
            video_id: v.id,
            file_name: v.name
        }));

        const result = await apiRequest('match_videos_by_filename', {
            target_advertiser_id: advertiserId,
            source_videos: sourceVideos
        });

        if (result.success && result.data) {
            bulkLaunchState.accountAssets[advertiserId].videoMatch = result.data;

            // Build video mapping
            const videoMapping = {};
            (result.data.matched || []).forEach(m => {
                videoMapping[m.source_video_id] = m.target_video_id;
            });

            // Update selected account if exists
            const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
            if (selectedAccount) {
                selectedAccount.video_mapping = videoMapping;
            }

            addLog('info', `Video matching for ${advertiserId}: ${result.data.match_rate}% match rate`);
        }
    } catch (error) {
        addLog('error', `Error matching videos for ${advertiserId}: ${error.message}`);
    }
}

// Auto-match assets (pixel, identity, portfolio) by name from campaign config
function autoMatchAssetsByName(advertiserId, assets) {
    // Get campaign's pixel and identity info
    const campaignPixel = state.pixels?.find(p => p.pixel_id === state.pixelId);
    let campaignPixelName = campaignPixel?.pixel_name?.toLowerCase().trim();

    // Fallback: read pixel name from the pixel dropdown if state.pixels is empty
    if (!campaignPixelName && state.pixelId) {
        const pixelSelect = document.getElementById('pixel-select');
        if (pixelSelect && pixelSelect.selectedOptions[0]) {
            campaignPixelName = pixelSelect.selectedOptions[0].textContent?.toLowerCase().trim();
        }
    }

    const campaignIdentity = state.identities?.find(i => i.identity_id === state.globalIdentityId);
    let campaignIdentityName = (campaignIdentity?.display_name || campaignIdentity?.identity_name)?.toLowerCase().trim();

    // Fallback: read identity name from dropdown
    if (!campaignIdentityName && state.globalIdentityId) {
        const identitySelect = document.getElementById('global-identity-select');
        if (identitySelect && identitySelect.selectedOptions[0]) {
            campaignIdentityName = identitySelect.selectedOptions[0].textContent?.toLowerCase().trim();
        }
    }

    // Note: campaign portfolios use creative_portfolio_id, target account portfolios use portfolio_id
    const campaignPortfolio = state.ctaPortfolios?.find(p => p.creative_portfolio_id === state.selectedPortfolioId || p.portfolio_id === state.selectedPortfolioId);
    const campaignPortfolioName = campaignPortfolio?.portfolio_name?.toLowerCase().trim();

    // Get or create selected account entry
    let selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!selectedAccount) {
        const account = bulkLaunchState.accounts.find(a => a.advertiser_id === advertiserId);
        if (!account) return;

        selectedAccount = {
            advertiser_id: advertiserId,
            advertiser_name: account.advertiser_name,
            pixel_id: null,
            identity_id: null,
            identity_type: 'CUSTOMIZED_USER',
            portfolio_id: null,
            video_mapping: {}
        };
        bulkLaunchState.selectedAccounts.push(selectedAccount);
    }

    let matchedAssets = [];

    // Auto-match pixel: exact name → partial name → single pixel fallback
    if (assets.pixels?.length > 0 && !selectedAccount.pixel_id) {
        let matchingPixel = null;

        if (campaignPixelName) {
            // Try exact match first
            matchingPixel = assets.pixels.find(p =>
                p.pixel_name?.toLowerCase().trim() === campaignPixelName
            );
            // Try partial/contains match
            if (!matchingPixel) {
                matchingPixel = assets.pixels.find(p => {
                    const name = p.pixel_name?.toLowerCase().trim() || '';
                    return name.includes(campaignPixelName) || campaignPixelName.includes(name);
                });
            }
        }

        // Fallback: if only 1 pixel exists, auto-select it
        if (!matchingPixel && assets.pixels.length === 1) {
            matchingPixel = assets.pixels[0];
        }

        if (matchingPixel) {
            selectedAccount.pixel_id = matchingPixel.pixel_id;
            matchedAssets.push(`Pixel: ${matchingPixel.pixel_name}`);
        }
    }

    // Auto-match identity: exact name → partial name → single identity fallback
    if (assets.identities?.length > 0 && !selectedAccount.identity_id) {
        let matchingIdentity = null;

        if (campaignIdentityName) {
            // Try exact match
            matchingIdentity = assets.identities.find(i => {
                const name = (i.display_name || i.identity_name)?.toLowerCase().trim();
                return name === campaignIdentityName;
            });
            // Try partial match
            if (!matchingIdentity) {
                matchingIdentity = assets.identities.find(i => {
                    const name = (i.display_name || i.identity_name)?.toLowerCase().trim() || '';
                    return name.includes(campaignIdentityName) || campaignIdentityName.includes(name);
                });
            }
        }

        // Fallback: single identity
        if (!matchingIdentity && assets.identities.length === 1) {
            matchingIdentity = assets.identities[0];
        }

        if (matchingIdentity) {
            selectedAccount.identity_id = matchingIdentity.identity_id;
            selectedAccount.identity_type = matchingIdentity.identity_type || 'CUSTOMIZED_USER';
            if (matchingIdentity.identity_authorized_bc_id) {
                selectedAccount.identity_authorized_bc_id = matchingIdentity.identity_authorized_bc_id;
            }
            matchedAssets.push(`Identity: ${matchingIdentity.display_name || matchingIdentity.identity_name}`);
        }
    }

    // Auto-match portfolio: exact name → partial name → single portfolio fallback
    if (assets.portfolios?.length > 0 && !selectedAccount.portfolio_id) {
        let matchingPortfolio = null;

        if (campaignPortfolioName) {
            matchingPortfolio = assets.portfolios.find(p =>
                p.portfolio_name?.toLowerCase().trim() === campaignPortfolioName
            );
            if (!matchingPortfolio) {
                matchingPortfolio = assets.portfolios.find(p => {
                    const name = p.portfolio_name?.toLowerCase().trim() || '';
                    return name.includes(campaignPortfolioName) || campaignPortfolioName.includes(name);
                });
            }
        }

        if (!matchingPortfolio && assets.portfolios.length === 1) {
            matchingPortfolio = assets.portfolios[0];
        }

        if (matchingPortfolio) {
            selectedAccount.portfolio_id = matchingPortfolio.portfolio_id;
            matchedAssets.push(`Portfolio: ${matchingPortfolio.portfolio_name}`);
        }
    }

    // Log auto-matched assets
    if (matchedAssets.length > 0) {
        addLog('success', `Auto-matched for ${advertiserId}: ${matchedAssets.join(', ')}`);
        console.log(`[Bulk Launch] Auto-matched assets for ${advertiserId}:`, matchedAssets);
    } else {
        console.log(`[Bulk Launch] No auto-match for ${advertiserId}. Campaign pixel: "${campaignPixelName}", identity: "${campaignIdentityName}", Available pixels:`, assets.pixels?.map(p => p.pixel_name));
    }
}

// Toggle expand/collapse for account assets section
function toggleAccountAssets(advertiserId) {
    const assetsDiv = document.getElementById(`assets-${advertiserId}`);
    const statusDiv = document.getElementById(`status-${advertiserId}`);
    const icon = document.getElementById(`toggle-icon-${advertiserId}`);

    if (!assetsDiv) return;

    const isCollapsed = assetsDiv.style.display === 'none';

    if (isCollapsed) {
        // Expand
        assetsDiv.style.display = 'block';
        if (statusDiv) statusDiv.style.display = 'block';
        if (icon) icon.textContent = '▼';
    } else {
        // Collapse
        assetsDiv.style.display = 'none';
        if (statusDiv) statusDiv.style.display = 'none';
        if (icon) icon.textContent = '▶';
    }
}

// Toggle account selection
function toggleBulkAccountSelection(advertiserId) {
    const checkbox = document.getElementById(`bulk-check-${advertiserId}`);
    const card = document.getElementById(`bulk-account-${advertiserId}`);
    const account = bulkLaunchState.accounts.find(a => a.advertiser_id === advertiserId);

    if (!account) return;

    if (checkbox.checked) {
        // Add to selected
        card.classList.add('selected');

        // Load assets if not loaded
        if (!bulkLaunchState.accountAssets[advertiserId]) {
            loadAccountAssets(advertiserId);
        }

        // Add to selected accounts
        if (!bulkLaunchState.selectedAccounts.some(a => a.advertiser_id === advertiserId)) {
            bulkLaunchState.selectedAccounts.push({
                advertiser_id: advertiserId,
                advertiser_name: account.advertiser_name,
                pixel_id: null,
                identity_id: null,
                identity_type: 'CUSTOMIZED_USER',
                portfolio_id: null,
                video_mapping: {}
            });
        }
    } else {
        // Remove from selected
        card.classList.remove('selected');
        bulkLaunchState.selectedAccounts = bulkLaunchState.selectedAccounts.filter(a => a.advertiser_id !== advertiserId);
    }

    // Update status
    const statusEl = document.getElementById(`status-${advertiserId}`);
    if (statusEl) {
        statusEl.innerHTML = checkbox.checked ? getAccountStatus(advertiserId) : '';
    }

    updateBulkModalCounts();
}

// Update account asset selection (pixel/identity)
function updateAccountAssetSelection(advertiserId) {
    const pixelSelect = document.getElementById(`pixel-${advertiserId}`);
    const identitySelect = document.getElementById(`identity-${advertiserId}`);
    const portfolioSelect = document.getElementById(`portfolio-${advertiserId}`);

    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!selectedAccount) return;

    if (pixelSelect) {
        selectedAccount.pixel_id = pixelSelect.value;
    }

    if (identitySelect) {
        selectedAccount.identity_id = identitySelect.value;
        const selectedOption = identitySelect.options[identitySelect.selectedIndex];
        selectedAccount.identity_type = selectedOption?.dataset?.type || selectedOption?.dataset?.identityType || 'CUSTOMIZED_USER';
        // For BC_AUTH_TT, capture identity_authorized_bc_id
        if (selectedOption?.dataset?.identityAuthorizedBcId) {
            selectedAccount.identity_authorized_bc_id = selectedOption.dataset.identityAuthorizedBcId;
        } else {
            selectedAccount.identity_authorized_bc_id = null;
        }
    }

    if (portfolioSelect) {
        selectedAccount.portfolio_id = portfolioSelect.value;
    }

    // Update status
    const statusEl = document.getElementById(`status-${advertiserId}`);
    if (statusEl) {
        statusEl.innerHTML = getAccountStatus(advertiserId);
    }

    updateBulkModalCounts();
}

// Update account landing page URL (optional override)
function updateAccountLandingUrl(advertiserId) {
    const urlInput = document.getElementById(`landing-url-${advertiserId}`);
    if (!urlInput) return;

    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!selectedAccount) {
        // If account not selected yet, just store the value for when it gets selected
        return;
    }

    const url = urlInput.value.trim();
    selectedAccount.landing_page_url = url || null; // null means use campaign default

    addLog('info', `Updated landing page URL for ${advertiserId}: ${url || '(using default)'}`);
}

// Update account campaign name (optional override)
function updateAccountCampaignName(advertiserId) {
    const nameInput = document.getElementById(`campaign-name-${advertiserId}`);
    if (!nameInput) return;

    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!selectedAccount) {
        // If account not selected yet, just store the value for when it gets selected
        return;
    }

    const name = nameInput.value.trim();
    selectedAccount.campaign_name = name || null; // null means use campaign default

    addLog('info', `Updated campaign name for ${advertiserId}: ${name || '(using default)'}`);
}

// Update original account landing page URL
function updateOriginalAccountLandingUrl() {
    const currentAccount = bulkLaunchState.accounts.find(a => a.is_current);
    if (!currentAccount) return;

    const urlInput = document.getElementById(`landing-url-${currentAccount.advertiser_id}`) ||
                     document.getElementById('landing-url-original');
    if (!urlInput) return;

    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.is_original);
    if (!selectedAccount) return;

    const url = urlInput.value.trim();
    selectedAccount.landing_page_url = url || null;

    addLog('info', `Updated landing page URL for original account: ${url || '(using default)'}`);
}

// Select all accounts
function selectAllBulkAccounts() {
    bulkLaunchState.accounts.forEach(account => {
        if (account.is_current) return;

        const checkbox = document.getElementById(`bulk-check-${account.advertiser_id}`);
        if (checkbox && !checkbox.checked) {
            checkbox.checked = true;
            toggleBulkAccountSelection(account.advertiser_id);
        }
    });
}

// Deselect all accounts
function deselectAllBulkAccounts() {
    bulkLaunchState.selectedAccounts.forEach(account => {
        const checkbox = document.getElementById(`bulk-check-${account.advertiser_id}`);
        if (checkbox && checkbox.checked) {
            checkbox.checked = false;
            toggleBulkAccountSelection(account.advertiser_id);
        }
    });
}

// Filter bulk accounts by search query
function filterBulkAccounts(query) {
    const searchQuery = query.toLowerCase().trim();
    const container = document.getElementById('bulk-accounts-container');
    const accountCards = container.querySelectorAll('.bulk-account-card');
    let visibleCount = 0;
    let totalCount = 0;

    accountCards.forEach(card => {
        const accountName = card.querySelector('.bulk-account-name')?.textContent?.toLowerCase() || '';
        const accountId = card.querySelector('.bulk-account-id')?.textContent?.toLowerCase() || '';

        totalCount++;

        if (searchQuery === '' || accountName.includes(searchQuery) || accountId.includes(searchQuery)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    // Update search results count
    const resultsCountEl = document.getElementById('bulk-search-results-count');
    if (resultsCountEl) {
        if (searchQuery === '') {
            resultsCountEl.textContent = '';
        } else {
            resultsCountEl.textContent = `Showing ${visibleCount} of ${totalCount} accounts`;
        }
    }
}

// Update counts in the modal
function updateBulkModalCounts() {
    const selectedCount = bulkLaunchState.selectedAccounts.length;
    const readyCount = bulkLaunchState.selectedAccounts.filter(a => {
        // Original account is always ready
        if (a.is_original) return true;

        const assets = bulkLaunchState.accountAssets[a.advertiser_id];
        const videoMatch = assets?.videoMatch;
        return a.pixel_id && a.identity_id && a.portfolio_id && videoMatch && videoMatch.match_rate === 100;
    }).length;

    const budget = parseFloat(state.budget || document.getElementById('campaign-budget')?.value || 50);
    const totalBudget = budget * selectedCount;

    // Modal counts (include all accounts including original)
    document.getElementById('modal-selected-count').textContent = selectedCount;
    document.getElementById('modal-total-accounts').textContent = bulkLaunchState.accounts.length;
    document.getElementById('modal-ready-accounts').textContent = readyCount;
    document.getElementById('modal-total-budget').textContent = `$${totalBudget.toFixed(2)}`;

    // Enable/disable confirm button (at least 1 account should be selected - original is always selected)
    const confirmBtn = document.getElementById('confirm-bulk-config-btn');
    if (confirmBtn) {
        confirmBtn.disabled = selectedCount === 0;
    }
}

// Open portfolio creation modal for bulk launch
function openBulkPortfolioCreate(advertiserId) {
    const accountName = bulkLaunchState.accounts.find(a => a.advertiser_id === advertiserId)?.advertiser_name || advertiserId;

    // Create modal HTML
    const modalHtml = `
        <div id="bulk-portfolio-modal" class="modal" style="display: flex; z-index: 10001;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>Create CTA Portfolio</h3>
                    <span class="modal-close" onclick="closeBulkPortfolioModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 15px; color: #666;">
                        Creating portfolio for: <strong>${accountName}</strong>
                    </p>
                    <div class="form-group">
                        <label>Portfolio Name</label>
                        <input type="text" id="bulk-portfolio-name" value="Frequently Used CTAs" placeholder="Enter portfolio name">
                    </div>
                    <div class="form-group">
                        <label>Select CTAs to Include</label>
                        <div class="cta-checkbox-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px;">
                            <label class="cta-checkbox"><input type="checkbox" value="LEARN_MORE" checked> Learn More</label>
                            <label class="cta-checkbox"><input type="checkbox" value="GET_QUOTE"> Get Quote</label>
                            <label class="cta-checkbox"><input type="checkbox" value="SIGN_UP"> Sign Up</label>
                            <label class="cta-checkbox"><input type="checkbox" value="CONTACT_US"> Contact Us</label>
                            <label class="cta-checkbox"><input type="checkbox" value="APPLY_NOW"> Apply Now</label>
                            <label class="cta-checkbox"><input type="checkbox" value="SHOP_NOW"> Shop Now</label>
                            <label class="cta-checkbox"><input type="checkbox" value="DOWNLOAD"> Download</label>
                            <label class="cta-checkbox"><input type="checkbox" value="BOOK_NOW"> Book Now</label>
                            <label class="cta-checkbox"><input type="checkbox" value="SUBSCRIBE"> Subscribe</label>
                            <label class="cta-checkbox"><input type="checkbox" value="ORDER_NOW"> Order Now</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeBulkPortfolioModal()">Cancel</button>
                    <button class="btn-primary" onclick="createBulkPortfolio('${advertiserId}')">Create Portfolio</button>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if any
    const existing = document.getElementById('bulk-portfolio-modal');
    if (existing) existing.remove();

    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// Close bulk portfolio modal
function closeBulkPortfolioModal() {
    const modal = document.getElementById('bulk-portfolio-modal');
    if (modal) modal.remove();
}

// Create portfolio for bulk launch account
async function createBulkPortfolio(advertiserId) {
    const portfolioName = document.getElementById('bulk-portfolio-name').value.trim();
    if (!portfolioName) {
        showToast('Please enter a portfolio name', 'error');
        return;
    }

    // Get selected CTAs
    const selectedCTAs = [];
    document.querySelectorAll('#bulk-portfolio-modal .cta-checkbox input:checked').forEach(checkbox => {
        selectedCTAs.push({
            asset_content: checkbox.value,
            asset_ids: ["0"]
        });
    });

    if (selectedCTAs.length === 0) {
        showToast('Please select at least one CTA', 'error');
        return;
    }

    showLoading('Creating portfolio...');

    try {
        const result = await apiRequest('create_portfolio_for_account', {
            target_advertiser_id: advertiserId,
            portfolio_name: portfolioName,
            portfolio_content: selectedCTAs
        });

        if (result.success && result.portfolio_id) {
            closeBulkPortfolioModal();
            showToast('Portfolio created successfully!', 'success');

            // Add new portfolio to assets
            const assets = bulkLaunchState.accountAssets[advertiserId];
            if (assets) {
                if (!assets.portfolios) assets.portfolios = [];
                assets.portfolios.unshift({
                    portfolio_id: result.portfolio_id,
                    portfolio_name: portfolioName,
                    source: 'just_created'
                });
            }

            // Select the new portfolio
            const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
            if (selectedAccount) {
                selectedAccount.portfolio_id = result.portfolio_id;
            }

            // Re-render the account assets
            const assetsContainer = document.getElementById(`assets-${advertiserId}`);
            if (assetsContainer && assets) {
                assetsContainer.innerHTML = renderAccountAssetsDropdowns(advertiserId, assets);
            }

            // Update status
            const statusEl = document.getElementById(`status-${advertiserId}`);
            if (statusEl) {
                statusEl.innerHTML = getAccountStatus(advertiserId);
            }

            updateBulkModalCounts();
        } else {
            showToast(result.message || 'Failed to create portfolio', 'error');
        }
    } catch (error) {
        showToast('Error creating portfolio: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Open bulk identity creation modal for specific account
function openBulkIdentityCreate(advertiserId) {
    const accountName = bulkLaunchState.accounts.find(a => a.advertiser_id === advertiserId)?.advertiser_name || advertiserId;

    // Create modal HTML
    const modalHtml = `
        <div id="bulk-identity-modal" class="modal" style="display: flex; z-index: 10001;">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Create Custom Identity</h3>
                    <span class="modal-close" onclick="closeBulkIdentityModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 15px; color: #666;">
                        Creating identity for: <strong>${accountName}</strong>
                    </p>
                    <div class="form-group">
                        <label>Display Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="bulk-identity-name" placeholder="Enter display name (e.g., Your Brand)" maxlength="50">
                        <small style="color: #64748b; font-size: 11px;">This name will appear on your ads</small>
                    </div>
                    <div class="form-group" style="margin-top: 15px;">
                        <label>Profile Logo <span style="color: #94a3b8;">(optional)</span></label>
                        <div class="logo-upload-area" style="border: 2px dashed #e2e8f0; border-radius: 8px; padding: 20px; text-align: center; cursor: pointer;" onclick="document.getElementById('bulk-identity-logo-input').click()">
                            <input type="file" id="bulk-identity-logo-input" accept="image/*" style="display: none;" onchange="previewBulkIdentityLogo(this)">
                            <div id="bulk-identity-logo-placeholder">
                                <span style="font-size: 32px;">📷</span>
                                <p style="margin: 10px 0 0; color: #64748b; font-size: 13px;">Click to upload logo</p>
                                <p style="margin: 5px 0 0; color: #94a3b8; font-size: 11px;">Recommended: 100x100px, max 5MB</p>
                            </div>
                            <div id="bulk-identity-logo-preview" style="display: none;">
                                <img id="bulk-identity-logo-img" style="max-width: 100px; max-height: 100px; border-radius: 50%;">
                            </div>
                        </div>
                        <button type="button" id="bulk-identity-logo-remove" style="display: none; margin-top: 10px; padding: 5px 10px; font-size: 12px; background: #ef4444; color: white; border: none; border-radius: 4px; cursor: pointer;" onclick="removeBulkIdentityLogo()">Remove Logo</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeBulkIdentityModal()">Cancel</button>
                    <button class="btn-primary" onclick="createBulkIdentity('${advertiserId}')">Create Identity</button>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if any
    const existing = document.getElementById('bulk-identity-modal');
    if (existing) existing.remove();

    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // Reset state
    bulkIdentityLogoFile = null;
}

// Close bulk identity modal
function closeBulkIdentityModal() {
    const modal = document.getElementById('bulk-identity-modal');
    if (modal) modal.remove();
    bulkIdentityLogoFile = null;
}

// Preview logo in bulk identity modal
let bulkIdentityLogoFile = null;

function previewBulkIdentityLogo(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];

        if (!file.type.startsWith('image/')) {
            showToast('Please select an image file', 'error');
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            showToast('Image too large. Maximum size is 5MB', 'error');
            return;
        }

        bulkIdentityLogoFile = file;

        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('bulk-identity-logo-img').src = e.target.result;
            document.getElementById('bulk-identity-logo-preview').style.display = 'block';
            document.getElementById('bulk-identity-logo-placeholder').style.display = 'none';
            document.getElementById('bulk-identity-logo-remove').style.display = 'inline-block';
        };
        reader.readAsDataURL(file);
    }
}

function removeBulkIdentityLogo() {
    bulkIdentityLogoFile = null;
    document.getElementById('bulk-identity-logo-input').value = '';
    document.getElementById('bulk-identity-logo-preview').style.display = 'none';
    document.getElementById('bulk-identity-logo-placeholder').style.display = 'block';
    document.getElementById('bulk-identity-logo-remove').style.display = 'none';
}

// Create identity for bulk launch account
async function createBulkIdentity(advertiserId) {
    const displayName = document.getElementById('bulk-identity-name').value.trim();
    if (!displayName) {
        showToast('Please enter a display name', 'error');
        return;
    }

    showLoading('Creating identity...');

    try {
        let profileImageId = null;

        // Upload logo if provided
        if (bulkIdentityLogoFile) {
            showLoading('Uploading logo...');
            addLog('info', 'Uploading identity logo...');

            const formData = new FormData();
            formData.append('image', bulkIdentityLogoFile);
            formData.append('advertiser_id', advertiserId);

            const uploadResponse = await fetch('api.php?action=upload_image', {
                method: 'POST',
                headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                body: formData
            });

            const uploadResult = await uploadResponse.json();

            if (uploadResult.success && uploadResult.data?.image_id) {
                profileImageId = uploadResult.data.image_id;
                addLog('success', `Logo uploaded: ${profileImageId}`);
            } else {
                addLog('warning', 'Logo upload failed, creating identity without logo');
            }
        }

        showLoading('Creating identity...');

        const params = {
            target_advertiser_id: advertiserId,
            display_name: displayName
        };
        if (profileImageId) {
            params.profile_image_id = profileImageId;
        }

        const result = await apiRequest('create_identity_for_account', params);

        if (result.success && result.identity_id) {
            closeBulkIdentityModal();
            showToast('Identity created successfully!', 'success');
            addLog('success', `Identity created for ${advertiserId}: ${displayName} (ID: ${result.identity_id})`);

            // Add new identity to assets
            const assets = bulkLaunchState.accountAssets[advertiserId];
            if (assets) {
                if (!assets.identities) assets.identities = [];
                assets.identities.unshift({
                    identity_id: result.identity_id,
                    display_name: displayName,
                    identity_name: displayName,
                    identity_type: 'CUSTOMIZED_USER',
                    source: 'just_created'
                });
            }

            // Select the new identity
            const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
            if (selectedAccount) {
                selectedAccount.identity_id = result.identity_id;
                selectedAccount.identity_type = 'CUSTOMIZED_USER';
            }

            // Re-render the account assets
            const assetsContainer = document.getElementById(`assets-${advertiserId}`);
            if (assetsContainer && assets) {
                assetsContainer.innerHTML = renderAccountAssetsDropdowns(advertiserId, assets);
            }

            // Update status
            const statusEl = document.getElementById(`status-${advertiserId}`);
            if (statusEl) {
                statusEl.innerHTML = getAccountStatus(advertiserId);
            }

            updateBulkModalCounts();
        } else {
            showToast(result.message || 'Failed to create identity', 'error');
            addLog('error', `Identity creation failed: ${result.message}`);
        }
    } catch (error) {
        showToast('Error creating identity: ' + error.message, 'error');
        addLog('error', `Identity creation error: ${error.message}`);
    } finally {
        hideLoading();
    }
}

// Toggle video distribution mode
function toggleVideoDistribution() {
    const mode = document.querySelector('input[name="video_distribution"]:checked').value;
    bulkLaunchState.videoDistributionMode = mode;

    const uploadProgress = document.getElementById('video-upload-progress');
    uploadProgress.style.display = mode === 'upload' ? 'block' : 'none';

    addLog('info', `Video distribution mode: ${mode}`);
}

// Confirm bulk configuration
function confirmBulkConfiguration() {
    if (bulkLaunchState.selectedAccounts.length === 0) {
        showToast('Please select at least one account', 'error');
        return;
    }

    // Check if all selected accounts are ready (skip original account, it's always ready)
    const notReady = bulkLaunchState.selectedAccounts.filter(a => {
        // Original account is always ready (campaign was configured there)
        if (a.is_original) return false;

        const assets = bulkLaunchState.accountAssets[a.advertiser_id];
        const videoMatch = assets?.videoMatch;
        return !a.pixel_id || !a.identity_id || !a.portfolio_id || !videoMatch || videoMatch.match_rate < 100;
    });

    if (notReady.length > 0) {
        const names = notReady.map(a => {
            const missing = [];
            if (!a.pixel_id) missing.push('pixel');
            if (!a.identity_id) missing.push('identity');
            if (!a.portfolio_id) missing.push('portfolio');
            const assets = bulkLaunchState.accountAssets[a.advertiser_id];
            const videoMatch = assets?.videoMatch;
            if (!videoMatch || videoMatch.match_rate < 100) missing.push('videos');
            return `${a.advertiser_name} (missing: ${missing.join(', ')})`;
        }).join('\n');
        showToast(`Some accounts are not ready:\n${names}`, 'warning');
        return;
    }

    // Mark as configured
    bulkLaunchState.isConfigured = true;

    // Close modal
    closeBulkLaunchModal();

    // Update summary
    updateBulkLaunchSummary();

    showToast(`Configured ${bulkLaunchState.selectedAccounts.length} accounts for bulk launch`, 'success');
}

// Update bulk launch summary on main page
function updateBulkLaunchSummary() {
    const summaryDiv = document.getElementById('bulk-launch-summary');
    const accountsList = document.getElementById('bulk-accounts-list');
    const launchButton = document.getElementById('launch-button');

    if (!bulkLaunchState.isConfigured || bulkLaunchState.selectedAccounts.length === 0) {
        summaryDiv.style.display = 'none';
        return;
    }

    summaryDiv.style.display = 'block';

    // Update stats
    const budget = parseFloat(state.budget || document.getElementById('campaign-budget')?.value || 50);
    document.getElementById('bulk-selected-count').textContent = bulkLaunchState.selectedAccounts.length;
    document.getElementById('bulk-total-budget').textContent = `$${(budget * bulkLaunchState.selectedAccounts.length).toFixed(2)}`;
    document.getElementById('bulk-ready-count').textContent = bulkLaunchState.selectedAccounts.length;

    // Render accounts list (show original account first with badge)
    accountsList.innerHTML = bulkLaunchState.selectedAccounts.map(a => `
        <div class="bulk-account-item ${a.is_original ? 'original' : ''}">
            <span class="account-name">${a.advertiser_name}${a.is_original ? ' <span class="original-badge-small">📍 Original</span>' : ''}</span>
            <span class="account-status ready">✓ Ready</span>
        </div>
    `).join('');

    // Update launch button
    launchButton.textContent = `⚡ Bulk Launch to ${bulkLaunchState.selectedAccounts.length} Accounts`;
}

// Handle launch button click (routes to single or bulk)
function handleLaunch() {
    const mode = document.querySelector('input[name="launch_mode"]:checked').value;

    if (mode === 'bulk') {
        if (!bulkLaunchState.isConfigured || bulkLaunchState.selectedAccounts.length === 0) {
            // Open configuration modal if not configured
            openBulkLaunchModal();
        } else {
            // Execute bulk launch
            executeBulkLaunch();
        }
    } else {
        // Single account launch - check for duplicates
        const duplicatesEnabled = document.getElementById('enable-duplicates')?.checked;
        const duplicateCount = duplicatesEnabled ? parseInt(document.getElementById('duplicate-count')?.value) || 1 : 1;

        if (duplicatesEnabled && duplicateCount > 1) {
            // Launch multiple campaign copies
            launchDuplicateCampaigns(duplicateCount);
        } else {
            // Standard single launch
            createAd();
        }
    }
}

// Launch multiple duplicate campaigns
async function launchDuplicateCampaigns(count) {
    if (count < 1 || count > 20) {
        showToast('Invalid duplicate count (1-20 allowed)', 'error');
        return;
    }

    // Validate CTA portfolio is selected
    if (!state.globalCtaPortfolioId) {
        showToast('CTA Portfolio is required. Please go back and select a portfolio.', 'error');
        return;
    }

    const baseName = state.campaignName;
    addLog('info', `Starting duplicate campaign launch: 1 original + ${count - 1} copies of "${baseName}"`);
    showLoading(`Creating ${count} campaigns (1 original + ${count - 1} copies)...`);

    // Use stored identity type from reviewAds()
    const identityType = state.globalIdentityType || 'CUSTOMIZED_USER';
    const identityAuthorizedBcId = state.globalIdentityAuthorizedBcId || null;

    addLog('info', `Using identity type: ${identityType} for duplicates`);
    if (identityType === 'BC_AUTH_TT') {
        addLog('info', `BC_AUTH_TT with bc_id: ${identityAuthorizedBcId || 'NOT SET'}`);
    }

    // Prepare creative list
    const creativeList = state.creatives.map(creative => ({
        video_id: creative.video_id,
        ad_text: creative.ad_text,
        image_id: creative.image_id || null
    }));

    let successCount = 0;
    let failedCount = 0;
    const results = [];

    // Loop from 0 to count-1: i=0 is original, i>0 are copies
    for (let i = 0; i < count; i++) {
        // Generate campaign name - i=0 is original (no number), i>0 gets copy number
        const campaignName = i === 0 ? baseName : `${baseName} (${i})`;
        const isOriginal = i === 0;

        try {
            let newCampaignId, newAdGroupId;

            if (isOriginal && state.campaignId && state.adGroupId) {
                // Use existing campaign and ad group from Steps 1-2
                newCampaignId = state.campaignId;
                newAdGroupId = state.adGroupId;
                addLog('info', `Using existing campaign ${i + 1}/${count}: "${campaignName}" (ID: ${newCampaignId})`);
            } else {
                // Create new campaign
                addLog('info', `Creating campaign ${i + 1}/${count}: "${campaignName}"`);

                // Budget is set at ad group level, not campaign level
                const campaignResult = await apiRequest('create_smartplus_campaign', {
                    campaign_name: campaignName
                });

                if (!campaignResult.success) {
                    throw new Error(campaignResult.message || 'Failed to create campaign');
                }

                newCampaignId = campaignResult.campaign_id || campaignResult.data?.campaign_id;

                // Generate ad group name based on campaign name
                const adGroupName = campaignName + ' Ad Group';

                addLog('info', `Creating ad group: "${adGroupName}"`);

                // Create ad group for this campaign - budget always at adgroup level
                const adGroupResult = await apiRequest('create_smartplus_adgroup', {
                    campaign_id: newCampaignId,
                    adgroup_name: adGroupName,
                    pixel_id: state.pixelId,
                    optimization_event: state.optimizationEvent,
                    location_ids: state.locationIds,
                    age_groups: state.ageGroups,
                    dayparting: state.dayparting
                    // Note: No budget - it's at campaign level for Smart+ campaigns
                });

                if (!adGroupResult.success) {
                    throw new Error(adGroupResult.message || 'Failed to create ad group');
                }

                newAdGroupId = adGroupResult.adgroup_id || adGroupResult.data?.adgroup_id;
            }

            // Generate ad name based on campaign name
            const adName = campaignName + ' Ad';

            addLog('info', `Creating ad: "${adName}"`);

            // Create ad for this campaign (using same params as createAd function)
            const adRequestData = {
                adgroup_id: newAdGroupId,
                ad_name: adName,
                identity_id: state.globalIdentityId,
                identity_type: identityType,
                landing_page_url: state.globalLandingUrl,
                call_to_action_id: state.globalCtaPortfolioId,
                creatives: creativeList,
                ad_texts: state.adTexts
            };

            // Add identity_authorized_bc_id for BC_AUTH_TT identities
            if (identityType === 'BC_AUTH_TT' && identityAuthorizedBcId) {
                adRequestData.identity_authorized_bc_id = identityAuthorizedBcId;
            }

            const adResult = await apiRequest('create_smartplus_ad', adRequestData);

            if (adResult.success && (adResult.smart_plus_ad_id || adResult.ad_id)) {
                const adId = adResult.smart_plus_ad_id || adResult.ad_id;
                successCount++;
                results.push({
                    name: campaignName,
                    campaign_id: newCampaignId,
                    adgroup_id: newAdGroupId,
                    ad_id: adId,
                    status: 'success',
                    isOriginal: isOriginal
                });
                addLog('success', `Campaign ${i + 1}/${count} created successfully: Campaign=${newCampaignId}, AdGroup=${newAdGroupId}, Ad=${adId}`);
            } else {
                throw new Error(adResult.message || adResult.error || 'Failed to create ad');
            }
        } catch (error) {
            failedCount++;
            results.push({
                name: campaignName,
                status: 'failed',
                error: error.message,
                isOriginal: isOriginal
            });
            addLog('error', `Campaign ${i + 1}/${count} failed: ${error.message}`);
        }

        // Small delay between campaign creations to avoid rate limiting
        if (i < count - 1) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }

    // Restore original campaign name
    state.campaignName = baseName;

    hideLoading();

    // Show results modal
    showDuplicateLaunchResults(results, successCount, failedCount);
}

// Show duplicate launch results
function showDuplicateLaunchResults(results, successCount, failedCount) {
    const modalHtml = `
        <div id="duplicate-results-modal" style="
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: fadeIn 0.3s ease;
        ">
            <div style="
                background: white;
                border-radius: 20px;
                padding: 40px;
                max-width: 600px;
                width: 90%;
                max-height: 80vh;
                overflow-y: auto;
                text-align: center;
                animation: slideIn 0.3s ease;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            ">
                <div style="font-size: 72px; margin-bottom: 20px;">
                    ${failedCount === 0 ? '🎉' : (successCount > 0 ? '⚠️' : '❌')}
                </div>
                <h2 style="color: ${failedCount === 0 ? '#22c55e' : '#1e9df1'}; margin-bottom: 10px; font-size: 28px;">
                    ${failedCount === 0 ? 'All Campaigns Created!' : 'Campaign Creation Complete'}
                </h2>
                <p style="color: #333; margin-bottom: 20px; font-size: 18px;">
                    ${successCount} of ${results.length} campaigns created successfully
                    ${failedCount > 0 ? `, ${failedCount} failed` : ''}
                </p>

                <div style="text-align: left; background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; max-height: 200px; overflow-y: auto;">
                    ${results.map(r => `
                        <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 14px;">${r.name}${r.isOriginal ? ' <span style="color:#667eea;font-size:11px;">(original)</span>' : ''}</span>
                            <span style="color: ${r.status === 'success' ? '#22c55e' : '#ef4444'}; font-size: 13px;">
                                ${r.status === 'success' ? `✓ ${r.campaign_id}` : `✗ ${r.error}`}
                            </span>
                        </div>
                    `).join('')}
                </div>

                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button onclick="createNewCampaign()" style="
                        background: #1e9df1;
                        color: white;
                        border: 2px solid #1e9df1;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                    ">Create More</button>
                    <button onclick="document.getElementById('duplicate-results-modal').remove(); switchMainView('campaigns');" style="
                        background: #f3f4f6;
                        color: #374151;
                        border: 2px solid #e5e7eb;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                    ">View Campaigns</button>
                </div>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// Execute bulk launch
async function executeBulkLaunch() {
    if (bulkLaunchState.selectedAccounts.length === 0) {
        showToast('No accounts configured for bulk launch', 'error');
        return;
    }

    // Check that we have creatives (videos) to use
    if (!state.creatives || state.creatives.length === 0) {
        showToast('Please add at least one video before launching', 'error');
        addLog('error', 'Bulk launch cancelled: No videos/creatives configured');
        return;
    }

    // IMPORTANT: Deduplicate accounts by advertiser_id to prevent duplicate campaigns
    const uniqueAccountsMap = new Map();
    bulkLaunchState.selectedAccounts.forEach(account => {
        // Use Map to ensure only one entry per advertiser_id (last one wins)
        uniqueAccountsMap.set(account.advertiser_id, account);
    });
    const uniqueAccounts = Array.from(uniqueAccountsMap.values());

    if (uniqueAccounts.length !== bulkLaunchState.selectedAccounts.length) {
        console.warn(`[Bulk Launch] Removed ${bulkLaunchState.selectedAccounts.length - uniqueAccounts.length} duplicate account(s)`);
        addLog('warning', `Removed duplicate account entries`);
        bulkLaunchState.selectedAccounts = uniqueAccounts;
    }

    // Reset failed accounts tracking for retry feature
    bulkLaunchState.failedAccounts = [];

    const originalAccountId = bulkLaunchState.accounts.find(a => a.is_current)?.advertiser_id;
    const originalAccount = bulkLaunchState.accounts.find(a => a.is_current);
    let originalAccountSkipped = false;
    let originalNeedsAdCompletion = false;

    // STEP 1: Check if original account needs its ad completed
    // If campaign exists but ad doesn't, mark it for completion
    if (state.campaignId && state.adGroupId && !state.adId && originalAccountId) {
        const originalInList = bulkLaunchState.selectedAccounts.find(a =>
            String(a.advertiser_id) === String(originalAccountId) && a.is_original
        );
        if (originalInList) {
            originalNeedsAdCompletion = true;
            addLog('info', `Original account has incomplete campaign (missing ad) - will complete it first`);
        }
    }

    // STEP 2: Handle original account - skip if it already has a COMPLETE campaign
    if (state.campaignId && state.adId && originalAccountId) {
        // Original account has a complete campaign - remove it from bulk launch
        const originalInList = bulkLaunchState.selectedAccounts.find(a =>
            String(a.advertiser_id) === String(originalAccountId) && a.is_original
        );

        if (originalInList) {
            bulkLaunchState.selectedAccounts = bulkLaunchState.selectedAccounts.filter(a =>
                String(a.advertiser_id) !== String(originalAccountId)
            );
            originalAccountSkipped = true;
            console.log(`[Bulk Launch] Original account ${originalAccountId} already has complete campaign ${state.campaignId} - skipping to prevent duplicate`);
            addLog('info', `Original account campaign complete - launching to other accounts only`);
        }
    }

    // STEP 3: Validate video mappings for non-original accounts
    const accountsMissingVideos = [];
    const sourceVideoIds = state.creatives.map(c => c.video_id);

    for (const account of bulkLaunchState.selectedAccounts) {
        if (account.is_original) continue; // Original account uses its own videos

        const videoMapping = account.video_mapping || {};
        const missingVideos = sourceVideoIds.filter(vid => !videoMapping[vid]);

        if (missingVideos.length > 0) {
            accountsMissingVideos.push({
                name: account.advertiser_name,
                id: account.advertiser_id,
                missing: missingVideos.length
            });
        }
    }

    if (accountsMissingVideos.length > 0) {
        const accountNames = accountsMissingVideos.map(a => a.name).join(', ');
        showToast(`Please upload/select videos for: ${accountNames}`, 'error');
        addLog('error', `Video mapping missing for accounts: ${accountNames}`);
        addLog('info', `Each account needs videos uploaded or selected from their library before bulk launch`);
        return;
    }

    // Check if we have any accounts left after filtering (not counting original if it needs ad completion)
    const accountsForBulkLaunch = bulkLaunchState.selectedAccounts.filter(a => !a.is_original);
    if (accountsForBulkLaunch.length === 0 && !originalNeedsAdCompletion) {
        if (originalAccountSkipped) {
            showToast('Original account campaign already complete! Select other accounts for bulk launch.', 'info');
        } else {
            showToast('No accounts configured for bulk launch', 'error');
        }
        return;
    }

    // Show progress modal
    const progressModal = document.getElementById('bulk-progress-modal');
    const progressList = document.getElementById('bulk-progress-list');
    const progressBar = document.getElementById('bulk-progress-bar');
    const progressFooter = document.getElementById('bulk-progress-footer');

    progressModal.style.display = 'flex';
    progressFooter.style.display = 'none';
    progressList.innerHTML = '';
    progressBar.style.width = '0%';

    // Calculate total: original (if needs completion) + other accounts for bulk launch
    const totalAccounts = (originalNeedsAdCompletion ? 1 : 0) + accountsForBulkLaunch.length;

    // Update progress stats
    document.getElementById('progress-total').textContent = totalAccounts;
    document.getElementById('progress-completed').textContent = '0';
    document.getElementById('progress-success').textContent = '0';
    document.getElementById('progress-failed').textContent = '0';

    let completedCount = 0;
    let successCount = 0;
    let failedCount = 0;

    // Add progress item for original account if it needs ad completion
    if (originalNeedsAdCompletion && originalAccount) {
        progressList.innerHTML += `
            <div class="progress-item" id="progress-item-${originalAccountId}">
                <span class="progress-account-name">${originalAccount.advertiser_name} (completing ad)</span>
                <span class="progress-status pending">Creating ad...</span>
            </div>
        `;
    }

    // Add progress items for other accounts
    accountsForBulkLaunch.forEach(account => {
        progressList.innerHTML += `
            <div class="progress-item" id="progress-item-${account.advertiser_id}">
                <span class="progress-account-name">${account.advertiser_name}</span>
                <span class="progress-status pending">Pending...</span>
            </div>
        `;
    });

    addLog('info', `Starting bulk launch: ${originalNeedsAdCompletion ? '1 original (completing) + ' : ''}${accountsForBulkLaunch.length} accounts`);

    // STEP 4a: Auto-resolve any processing_* video IDs before creating ads
    const processingCreatives = state.creatives.filter(c => c.video_id && String(c.video_id).startsWith('processing_'));
    if (processingCreatives.length > 0) {
        addLog('info', `Found ${processingCreatives.length} processing video(s) - refreshing library to resolve...`);
        try {
            await loadMediaLibrary();
            // Check again after refresh
            const stillProcessing = state.creatives.filter(c => c.video_id && String(c.video_id).startsWith('processing_'));
            if (stillProcessing.length > 0) {
                addLog('warning', `${stillProcessing.length} video(s) still processing after library refresh`);
                // For original account ad: abort if videos aren't ready
                if (originalNeedsAdCompletion) {
                    showToast(`${stillProcessing.length} video(s) still processing. Please wait and try again, or click "Refresh Library" first.`, 'error');
                    progressModal.style.display = 'none';
                    return;
                }
            } else {
                addLog('success', 'All processing videos resolved successfully');
            }
        } catch (e) {
            addLog('warning', 'Could not refresh library: ' + e.message);
        }
    }

    // STEP 4b: Complete original account's ad first if needed
    if (originalNeedsAdCompletion) {
        try {
            updateProgressItem(originalAccountId, 'pending', 'Creating ad...');

            const identityType = state.globalIdentityType || 'CUSTOMIZED_USER';
            const identityAuthorizedBcId = state.globalIdentityAuthorizedBcId || null;

            const creativeList = state.creatives.map(creative => ({
                video_id: creative.video_id,
                ad_text: creative.ad_text,
                image_id: creative.image_id || null
            }));

            const adRequestData = {
                adgroup_id: state.adGroupId,
                ad_name: state.campaignName + ' - Ad',
                identity_id: state.globalIdentityId,
                identity_type: identityType,
                landing_page_url: state.globalLandingUrl,
                call_to_action_id: state.globalCtaPortfolioId,
                creatives: creativeList,
                ad_texts: state.adTexts
            };

            if (identityType === 'BC_AUTH_TT' && identityAuthorizedBcId) {
                adRequestData.identity_authorized_bc_id = identityAuthorizedBcId;
            }

            const adResult = await apiRequest('create_smartplus_ad', adRequestData);

            completedCount++;
            document.getElementById('progress-completed').textContent = completedCount;
            progressBar.style.width = `${(completedCount / totalAccounts) * 100}%`;

            if (adResult.success && adResult.smart_plus_ad_id) {
                state.adId = adResult.smart_plus_ad_id;
                state.adCreated = true;
                successCount++;
                document.getElementById('progress-success').textContent = successCount;
                updateProgressItem(originalAccountId, 'success', `✓ Ad: ${adResult.smart_plus_ad_id}`);
                addLog('success', `Original account ad created: ${adResult.smart_plus_ad_id}`);
            } else {
                failedCount++;
                document.getElementById('progress-failed').textContent = failedCount;
                const origError = adResult.message || 'Failed to create ad';
                updateProgressItem(originalAccountId, 'failed', `✗ ${origError}`);
                bulkLaunchState.failedAccounts.push({
                    advertiser_id: originalAccountId,
                    advertiser_name: originalAccount.advertiser_name || originalAccountId,
                    error: origError,
                    is_original: true
                });
                addLog('error', `Failed to create ad for original account: ${adResult.message}`);
            }
        } catch (error) {
            completedCount++;
            failedCount++;
            document.getElementById('progress-completed').textContent = completedCount;
            document.getElementById('progress-failed').textContent = failedCount;
            updateProgressItem(originalAccountId, 'failed', `✗ ${error.message}`);
            bulkLaunchState.failedAccounts.push({
                advertiser_id: originalAccountId,
                advertiser_name: originalAccount.advertiser_name || originalAccountId,
                error: error.message,
                is_original: true
            });
            addLog('error', `Error creating ad for original account: ${error.message}`);
        }

        // Remove original from selectedAccounts so it's not sent to bulk launch backend
        bulkLaunchState.selectedAccounts = bulkLaunchState.selectedAccounts.filter(a =>
            String(a.advertiser_id) !== String(originalAccountId)
        );
    }

    // If no more accounts to launch after completing original, show results
    if (bulkLaunchState.selectedAccounts.length === 0) {
        progressFooter.style.display = 'flex';
        const retryBtnOrig = document.getElementById('btn-retry-failed');
        if (retryBtnOrig) retryBtnOrig.style.display = failedCount > 0 ? 'inline-block' : 'none';
        if (successCount > 0) {
            showToast('Original account campaign completed successfully!', 'success');
        } else {
            showToast('Failed to complete original campaign', 'error');
        }
        return;
    }

    // Get duplicate settings
    const duplicatesEnabled = document.getElementById('bulk-enable-duplicates')?.checked || false;
    const duplicateCount = duplicatesEnabled ? parseInt(document.getElementById('bulk-duplicate-count')?.value) || 1 : 1;

    // Get schedule data for bulk launch
    const bulkScheduleData = getBulkScheduleData();

    // Build campaign config - budget always at adgroup level
    const campaignConfig = {
        campaign_name: state.campaignName,
        budget: parseFloat(state.budget || document.getElementById('campaign-budget')?.value || 50),
        location_ids: state.locationIds,
        age_groups: state.ageGroups,
        dayparting: state.dayparting,
        optimization_event: state.optimizationEvent,
        landing_page_url: state.globalLandingUrl,
        ad_texts: state.adTexts,
        creatives: state.creatives.map(c => ({
            video_id: c.video_id,
            ad_text: c.ad_text
        })),
        duplicate_count: duplicateCount,  // Add duplicate count to config
        // Schedule data from bulk launch modal
        schedule_type: bulkScheduleData.schedule_type,
        schedule_start_time: bulkScheduleData.schedule_start_time,
        schedule_end_time: bulkScheduleData.schedule_end_time
    };

    // Prepare accounts with video mappings — exclude original account
    // Original account's campaign is handled separately (step 4b above), never send to bulk backend
    const accountsToLaunch = bulkLaunchState.selectedAccounts
        .filter(account => !account.is_original)
        .map(account => {
        // For other accounts
        const accountData = {
            advertiser_id: account.advertiser_id,
            advertiser_name: account.advertiser_name,
            pixel_id: account.pixel_id,
            identity_id: account.identity_id,
            identity_type: account.identity_type,
            portfolio_id: account.portfolio_id,
            video_mapping: account.video_mapping || {},
            // Landing page URL override (null = use campaign default)
            landing_page_url: account.landing_page_url || null,
            // Campaign name override (null = use campaign default)
            campaign_name: account.campaign_name || null
        };
        // Include identity_authorized_bc_id for BC_AUTH_TT identities
        if (account.identity_type === 'BC_AUTH_TT' && account.identity_authorized_bc_id) {
            accountData.identity_authorized_bc_id = account.identity_authorized_bc_id;
        }
        return accountData;
    });

    addLog('info', `Bulk launch config: ${accountsToLaunch.length} accounts, ${duplicateCount} copies each`);

    try {
        const result = await apiRequest('execute_bulk_launch', {
            campaign_config: campaignConfig,
            accounts: accountsToLaunch,
            primary_advertiser_id: bulkLaunchState.accounts.find(a => a.is_current)?.advertiser_id,
            duplicate_count: duplicateCount  // Also pass at top level
        });

        if (result.success && result.data) {
            const data = result.data;

            // Update progress UI - accumulate with any previous counts (from original account)
            // Update success items
            (data.success || []).forEach(item => {
                completedCount++;
                successCount++;
                updateProgressItem(item.advertiser_id, 'success', `✓ Campaign: ${item.campaign_id}`);
            });

            // Update failed items with full error messages
            (data.failed || []).forEach(item => {
                completedCount++;
                failedCount++;
                const displayError = item.error || 'Unknown error';
                updateProgressItem(item.advertiser_id, 'failed', `✗ ${displayError}`);
                // Store failed account info for retry
                if (!bulkLaunchState.failedAccounts) bulkLaunchState.failedAccounts = [];
                bulkLaunchState.failedAccounts.push({
                    advertiser_id: item.advertiser_id,
                    advertiser_name: item.advertiser_name || item.advertiser_id,
                    error: item.error || 'Unknown error',
                    step: item.step || null
                });
                addLog('error', `Failed: ${item.advertiser_name} (${item.advertiser_id}) - ${item.error}`);
                console.error(`[Bulk Launch] Failed for ${item.advertiser_id}:`, item);
            });

            // Update stats with total counts (including original if it was processed)
            document.getElementById('progress-completed').textContent = completedCount;
            document.getElementById('progress-success').textContent = successCount;
            document.getElementById('progress-failed').textContent = failedCount;
            progressBar.style.width = '100%';

            // Show footer
            progressFooter.style.display = 'flex';

            addLog('info', `Bulk launch completed: ${successCount} success, ${failedCount} failed`);

            // Show retry button if there are failures
            const retryBtn = document.getElementById('btn-retry-failed');
            if (retryBtn) retryBtn.style.display = failedCount > 0 ? 'inline-block' : 'none';

            if (failedCount === 0) {
                showToast(`Successfully launched to ${successCount} account(s)!`, 'success');
            } else if (successCount > 0) {
                showToast(`Launched to ${successCount} account(s), ${failedCount} failed`, 'warning');
            } else {
                showToast(`Bulk launch failed for all accounts`, 'error');
            }
        } else {
            // Mark all remaining accounts as failed
            if (!bulkLaunchState.failedAccounts) bulkLaunchState.failedAccounts = [];
            bulkLaunchState.selectedAccounts.forEach(account => {
                updateProgressItem(account.advertiser_id, 'failed', `✗ ${result.message || 'API error'}`);
                bulkLaunchState.failedAccounts.push({
                    advertiser_id: account.advertiser_id,
                    advertiser_name: account.advertiser_name,
                    error: result.message || 'API error'
                });
                failedCount++;
                completedCount++;
            });
            document.getElementById('progress-completed').textContent = completedCount;
            document.getElementById('progress-failed').textContent = failedCount;
            progressBar.style.width = '100%';
            showToast('Bulk launch failed: ' + (result.message || 'Unknown error'), 'error');
            progressFooter.style.display = 'flex';
            const retryBtn2 = document.getElementById('btn-retry-failed');
            if (retryBtn2) retryBtn2.style.display = 'inline-block';
        }
    } catch (error) {
        // Mark all remaining accounts as failed
        if (!bulkLaunchState.failedAccounts) bulkLaunchState.failedAccounts = [];
        bulkLaunchState.selectedAccounts.forEach(account => {
            updateProgressItem(account.advertiser_id, 'failed', `✗ ${error.message}`);
            bulkLaunchState.failedAccounts.push({
                advertiser_id: account.advertiser_id,
                advertiser_name: account.advertiser_name,
                error: error.message
            });
            failedCount++;
            completedCount++;
        });
        document.getElementById('progress-completed').textContent = completedCount;
        document.getElementById('progress-failed').textContent = failedCount;
        progressBar.style.width = '100%';
        addLog('error', 'Bulk launch error: ' + error.message);
        showToast('Error during bulk launch: ' + error.message, 'error');
        progressFooter.style.display = 'flex';
        const retryBtn3 = document.getElementById('btn-retry-failed');
        if (retryBtn3) retryBtn3.style.display = 'inline-block';
    }
}

// Update progress item status
function updateProgressItem(advertiserId, status, message) {
    const item = document.getElementById(`progress-item-${advertiserId}`);
    if (!item) return;

    const statusEl = item.querySelector('.progress-status');
    if (statusEl) {
        statusEl.className = `progress-status ${status}`;
        statusEl.textContent = message;
        // Show full error with word-wrap for failed items
        if (status === 'failed') {
            statusEl.style.cssText = 'word-break: break-word; white-space: normal; max-width: 60%; text-align: right; line-height: 1.4;';
            statusEl.title = message;
        }
    }

    // Add error styling to the item container for failed items
    if (status === 'failed') {
        item.classList.add('has-error');
    } else {
        item.classList.remove('has-error');
    }
}

// Close bulk progress modal
function closeBulkProgressModal() {
    document.getElementById('bulk-progress-modal').style.display = 'none';

    // Optionally refresh or redirect
    showSuccessModalBulk();
}

// Retry failed accounts — reopens bulk config with only failed accounts and shows errors
function retryFailedAccounts() {
    const failedAccounts = bulkLaunchState.failedAccounts || [];
    if (failedAccounts.length === 0) {
        showToast('No failed accounts to retry', 'info');
        return;
    }

    // Close progress modal
    document.getElementById('bulk-progress-modal').style.display = 'none';

    // Filter selectedAccounts to only include failed ones
    const failedIds = new Set(failedAccounts.map(a => String(a.advertiser_id)));

    // Rebuild selectedAccounts from existing data for failed accounts only
    bulkLaunchState.selectedAccounts = bulkLaunchState.selectedAccounts.filter(a =>
        failedIds.has(String(a.advertiser_id))
    );

    // If selectedAccounts got cleared during launch, re-add failed accounts from stored data
    if (bulkLaunchState.selectedAccounts.length === 0) {
        failedAccounts.forEach(fa => {
            const existingAssets = bulkLaunchState.accountAssets[fa.advertiser_id];
            bulkLaunchState.selectedAccounts.push({
                advertiser_id: fa.advertiser_id,
                advertiser_name: fa.advertiser_name,
                pixel_id: existingAssets?.selectedPixelId || '',
                identity_id: existingAssets?.selectedIdentityId || '',
                identity_type: existingAssets?.selectedIdentityType || 'CUSTOMIZED_USER',
                portfolio_id: existingAssets?.selectedPortfolioId || '',
                video_mapping: existingAssets?.videoMapping || {},
                is_original: fa.is_original || false
            });
        });
    }

    // Store errors on each failed account for display
    bulkLaunchState._retryErrors = {};
    failedAccounts.forEach(fa => {
        bulkLaunchState._retryErrors[String(fa.advertiser_id)] = fa.error;
    });

    // Reopen the bulk config modal
    const modal = document.getElementById('bulk-launch-modal');
    modal.style.display = 'flex';

    // Re-render accounts in modal
    renderBulkAccountsInModal().then(() => {
        // After rendering, inject error banners into each failed account card
        setTimeout(() => {
            failedAccounts.forEach(fa => {
                const card = document.getElementById(`bulk-account-${fa.advertiser_id}`);
                if (card) {
                    // Remove any existing error banners
                    const existing = card.querySelector('.bulk-retry-error-banner');
                    if (existing) existing.remove();

                    const errorBanner = document.createElement('div');
                    errorBanner.className = 'bulk-retry-error-banner';
                    errorBanner.style.cssText = 'background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; margin: 8px 16px; display: flex; align-items: flex-start; gap: 8px;';
                    errorBanner.innerHTML = `
                        <span style="color: #dc2626; font-size: 16px; flex-shrink: 0;">⚠️</span>
                        <div style="flex: 1;">
                            <div style="font-size: 11px; font-weight: 600; color: #dc2626; margin-bottom: 2px;">Previous Error:</div>
                            <div style="font-size: 12px; color: #7f1d1d; word-break: break-word; line-height: 1.4;">${fa.error}</div>
                        </div>
                    `;

                    // Insert after the header
                    const header = card.querySelector('.bulk-account-header');
                    if (header && header.nextSibling) {
                        card.insertBefore(errorBanner, header.nextSibling);
                    } else {
                        card.appendChild(errorBanner);
                    }

                    // Auto-expand the account card so user sees the error
                    const configSection = card.querySelector('.bulk-account-config');
                    if (configSection) {
                        configSection.style.display = 'block';
                    }

                    // Ensure checkbox is checked
                    const checkbox = document.getElementById(`bulk-check-${fa.advertiser_id}`);
                    if (checkbox && !checkbox.checked) {
                        checkbox.checked = true;
                        checkbox.dispatchEvent(new Event('change'));
                    }
                }
            });
        }, 500);
    });

    addLog('info', `Retry mode: ${failedAccounts.length} failed account(s) loaded for reconfiguration`);
    showToast(`${failedAccounts.length} failed account(s) loaded — fix the errors and re-launch`, 'info');
}

// Show success modal for bulk launch
function showSuccessModalBulk() {
    const successCount = parseInt(document.getElementById('progress-success').textContent) || 0;
    const failedCount = parseInt(document.getElementById('progress-failed').textContent) || 0;

    const modalHtml = `
        <div id="success-modal-bulk" style="
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: fadeIn 0.3s ease;
        ">
            <div style="
                background: white;
                border-radius: 20px;
                padding: 40px;
                max-width: 500px;
                text-align: center;
                animation: slideIn 0.3s ease;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            ">
                <div style="font-size: 72px; margin-bottom: 20px;">${failedCount === 0 ? '🎉' : '⚡'}</div>
                <h2 style="color: #1e9df1; margin-bottom: 10px; font-size: 28px;">Bulk Launch ${failedCount === 0 ? 'Complete!' : 'Finished'}</h2>
                <p style="color: #333; margin-bottom: 20px; font-size: 18px; font-weight: 500;">
                    ${successCount} campaigns launched successfully
                    ${failedCount > 0 ? `, ${failedCount} failed` : ''}
                </p>
                <div style="background: #f0f8ff; padding: 15px; border-radius: 10px; margin-bottom: 25px;">
                    <p style="margin: 5px 0; font-size: 13px;"><strong>Primary Campaign:</strong> ${state.campaignId}</p>
                    <p style="margin: 5px 0; font-size: 13px;"><strong>Accounts Launched:</strong> ${successCount}</p>
                </div>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button onclick="createNewCampaign()" style="
                        background: #1e9df1;
                        color: white;
                        border: 2px solid #1e9df1;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                    ">Create Another</button>
                    <button onclick="document.getElementById('success-modal-bulk').remove(); finishAndReset();" style="
                        background: #f3f4f6;
                        color: #374151;
                        border: 2px solid #e5e7eb;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                    ">Done</button>
                </div>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// ==========================================
// DUPLICATE CAMPAIGN FUNCTIONALITY
// ==========================================

// Toggle duplicate settings visibility (Single launch)
function toggleDuplicates() {
    const enabled = document.getElementById('enable-duplicates').checked;
    const settingsDiv = document.getElementById('duplicate-settings');

    if (enabled) {
        settingsDiv.style.display = 'block';
        updateDuplicatePreview();
    } else {
        settingsDiv.style.display = 'none';
    }

    addLog('info', `Duplicate campaigns ${enabled ? 'enabled' : 'disabled'}`);
}

// Update the preview of duplicate campaign names
function updateDuplicatePreview() {
    const count = parseInt(document.getElementById('duplicate-count')?.value) || 1;
    const baseName = state.campaignName || 'Campaign';
    const previewDiv = document.getElementById('duplicate-preview-names');

    if (!previewDiv) return;

    let previewHtml = '';

    if (count === 1) {
        // Single campaign - no numbering
        previewHtml = `<div style="margin: 3px 0;">• ${baseName} <span style="color: #888;">(original only)</span></div>`;
    } else {
        // Multiple campaigns: original (no number) + copies with numbering
        // First show the original
        previewHtml = `<div style="margin: 3px 0;">• ${baseName} <span style="color: #22c55e;">(original)</span></div>`;
        // Then show copies (1), (2), (3), etc.
        const copiesToShow = Math.min(count - 1, 4); // Show up to 4 copies in preview
        for (let i = 1; i <= copiesToShow; i++) {
            previewHtml += `<div style="margin: 3px 0;">• ${baseName} (${i})</div>`;
        }
        if (count - 1 > 4) {
            previewHtml += `<div style="margin: 3px 0; font-style: italic;">... and ${count - 1 - 4} more copies</div>`;
        }
    }

    previewDiv.innerHTML = previewHtml;
}

// Add event listener for duplicate count change
document.addEventListener('DOMContentLoaded', function() {
    const duplicateCountInput = document.getElementById('duplicate-count');
    if (duplicateCountInput) {
        duplicateCountInput.addEventListener('change', updateDuplicatePreview);
        duplicateCountInput.addEventListener('input', updateDuplicatePreview);
    }
});

// Toggle bulk duplicates (in Bulk Launch modal)
function toggleBulkDuplicates() {
    const enabled = document.getElementById('bulk-enable-duplicates').checked;
    const settingsDiv = document.getElementById('bulk-duplicate-settings');

    if (enabled) {
        settingsDiv.style.display = 'block';
    } else {
        settingsDiv.style.display = 'none';
    }

    // Update bulk launch state
    bulkLaunchState.duplicatesEnabled = enabled;
    bulkLaunchState.duplicateCount = enabled ? (parseInt(document.getElementById('bulk-duplicate-count')?.value) || 1) : 1;

    updateBulkModalCounts();
    addLog('info', `Bulk duplicates ${enabled ? 'enabled' : 'disabled'}, count: ${bulkLaunchState.duplicateCount}`);
}

// Toggle bulk schedule type (in Bulk Launch modal)
function toggleBulkScheduleType() {
    const scheduleType = document.querySelector('input[name="bulk_schedule_type"]:checked')?.value || 'same_as_original';
    const startOnlyContainer = document.getElementById('bulk-schedule-start-only-container');
    const dateTimeContainer = document.getElementById('bulk-schedule-datetime-container');
    const scheduleOptions = document.querySelectorAll('.bulk-schedule-option');
    const originalScheduleInfo = document.getElementById('bulk-original-schedule-info');

    // Hide both containers first
    if (startOnlyContainer) {
        startOnlyContainer.style.display = 'none';
    }
    if (dateTimeContainer) {
        dateTimeContainer.style.display = 'none';
    }

    // Update original schedule info display
    if (originalScheduleInfo && scheduleType === 'same_as_original') {
        const originalSchedule = getScheduleData();
        if (originalSchedule.schedule_start_time) {
            originalScheduleInfo.textContent = `Will use: ${originalSchedule.schedule_type === 'SCHEDULE_START_END' ? 'Start & End' : 'Scheduled Start'} - ${originalSchedule.schedule_start_time}`;
        } else {
            originalScheduleInfo.textContent = 'Will use: Start Immediately (continuous)';
        }
    }

    // Show appropriate container based on selection
    if (scheduleType === 'scheduled_start_only' && startOnlyContainer) {
        startOnlyContainer.style.display = 'block';
    } else if (scheduleType === 'scheduled' && dateTimeContainer) {
        dateTimeContainer.style.display = 'block';
    }

    // Update border styling to show selected option
    scheduleOptions.forEach(option => {
        const radio = option.querySelector('input[type="radio"]');
        if (radio && radio.checked) {
            option.style.borderColor = '#1a1a1a';
        } else {
            option.style.borderColor = '#e2e8f0';
        }
    });

    // Set default start time if not set (EST now + 1 hour)
    if (scheduleType === 'scheduled_start_only') {
        const startInput = document.getElementById('bulk-schedule-start-only-datetime');

        if (startInput) {
            const minTime = getESTNow();
            minTime.setMinutes(minTime.getMinutes() + 7);
            startInput.min = formatDateTimeLocal(minTime);

            if (!startInput.value) {
                const estNow = getESTNow();
                estNow.setHours(estNow.getHours() + 1);
                estNow.setMinutes(0, 0, 0);
                startInput.value = formatDateTimeLocal(estNow);
            }
        }
    } else if (scheduleType === 'scheduled') {
        const startInput = document.getElementById('bulk-schedule-start-datetime');
        const endInput = document.getElementById('bulk-schedule-end-datetime');

        if (startInput) {
            const minTime = getESTNow();
            minTime.setMinutes(minTime.getMinutes() + 7);
            startInput.min = formatDateTimeLocal(minTime);

            if (!startInput.value) {
                const estNow = getESTNow();
                estNow.setHours(estNow.getHours() + 1);
                estNow.setMinutes(0, 0, 0);
                startInput.value = formatDateTimeLocal(estNow);
            }
        }

        if (endInput && !endInput.value) {
            const endDate = getESTNow();
            endDate.setDate(endDate.getDate() + 7); // Default: 1 week from now
            endDate.setHours(23, 59, 0, 0);
            endInput.value = formatDateTimeLocal(endDate);
        }
    }

    // Update bulk launch state
    bulkLaunchState.scheduleType = scheduleType;
    addLog('info', `Bulk schedule type set to: ${scheduleType}`);
}

// Format datetime-local value to 'YYYY-MM-DD HH:MM:SS' string for API
// Same format as single account flow - times are interpreted as EST
function formatBulkScheduleTime(dateTimeLocalValue) {
    if (!dateTimeLocalValue) return null;
    const [datePart, timePart] = dateTimeLocalValue.split('T');
    return `${datePart} ${timePart}:00`;
}

// Get bulk schedule data for API
function getBulkScheduleData() {
    const scheduleType = document.querySelector('input[name="bulk_schedule_type"]:checked')?.value || 'same_as_original';

    // Same as original - use the schedule from the original campaign
    if (scheduleType === 'same_as_original') {
        const originalSchedule = getScheduleData();
        addLog('info', `Using same schedule as original: ${JSON.stringify(originalSchedule)}`);
        return originalSchedule;
    }

    // Start immediately - no schedule times
    if (scheduleType === 'continuous') {
        return {
            schedule_type: 'SCHEDULE_FROM_NOW',
            schedule_start_time: null,
            schedule_end_time: null
        };
    }

    // Scheduled start only - run continuously from a specific time
    if (scheduleType === 'scheduled_start_only') {
        const startInput = document.getElementById('bulk-schedule-start-only-datetime');
        if (startInput && startInput.value) {
            const formattedTime = formatBulkScheduleTime(startInput.value);
            return {
                schedule_type: 'SCHEDULE_FROM_NOW',
                schedule_start_time: formattedTime,
                schedule_end_time: null
            };
        }
    }

    // Scheduled start and end
    if (scheduleType === 'scheduled') {
        const startInput = document.getElementById('bulk-schedule-start-datetime');
        const endInput = document.getElementById('bulk-schedule-end-datetime');

        const startTime = startInput?.value ? formatBulkScheduleTime(startInput.value) : null;
        const endTime = endInput?.value ? formatBulkScheduleTime(endInput.value) : null;

        return {
            schedule_type: 'SCHEDULE_START_END',
            schedule_start_time: startTime,
            schedule_end_time: endTime,
        };
    }

    // Default fallback
    return {
        schedule_type: 'SCHEDULE_FROM_NOW',
        schedule_start_time: null,
        schedule_end_time: null
    };
}

// ==========================================
// VIDEO UPLOAD FUNCTIONALITY (Bulk Launch)
// ==========================================

// Video upload state
let videoUploadState = {
    isUploading: false,
    uploadedVideos: {}, // { advertiserId: { sourceVideoId: newVideoId } }
    uploadProgress: 0,
    totalUploads: 0,
    completedUploads: 0,
    failedUploads: 0
};

// Toggle video distribution mode (updated to show upload UI)
function toggleVideoDistribution() {
    const mode = document.querySelector('input[name="video_distribution"]:checked').value;
    bulkLaunchState.videoDistributionMode = mode;

    const uploadSection = document.getElementById('video-upload-section');
    const uploadVideoCount = document.getElementById('upload-video-count');

    if (mode === 'upload') {
        uploadSection.style.display = 'block';
        // Update video count
        if (uploadVideoCount) {
            uploadVideoCount.textContent = state.selectedVideos.length;
        }
        // Reset upload state
        resetVideoUploadState();
    } else {
        uploadSection.style.display = 'none';
    }

    addLog('info', `Video distribution mode: ${mode}`);
}

// Reset video upload state
function resetVideoUploadState() {
    videoUploadState = {
        isUploading: false,
        uploadedVideos: {},
        uploadProgress: 0,
        totalUploads: 0,
        completedUploads: 0,
        failedUploads: 0
    };

    // Reset UI
    const progressContainer = document.getElementById('video-upload-progress-container');
    const startBtn = document.getElementById('start-upload-btn');
    const completeStatus = document.getElementById('upload-complete-status');
    const progressBar = document.getElementById('video-upload-bar');
    const progressText = document.getElementById('upload-progress-text');
    const detailsDiv = document.getElementById('video-upload-details');

    if (progressContainer) progressContainer.style.display = 'none';
    if (startBtn) {
        startBtn.style.display = 'block';
        startBtn.disabled = false;
        startBtn.textContent = '📤 Upload Videos to Selected Accounts';
    }
    if (completeStatus) completeStatus.style.display = 'none';
    if (progressBar) progressBar.style.width = '0%';
    if (progressText) progressText.textContent = '0 / 0';
    if (detailsDiv) detailsDiv.innerHTML = '';
}

// Start bulk video upload
async function startBulkVideoUpload() {
    if (videoUploadState.isUploading) {
        showToast('Upload already in progress', 'warning');
        return;
    }

    if (bulkLaunchState.selectedAccounts.length === 0) {
        showToast('Please select at least one account first', 'error');
        return;
    }

    if (state.selectedVideos.length === 0) {
        showToast('No videos selected for upload', 'error');
        return;
    }

    videoUploadState.isUploading = true;
    videoUploadState.uploadedVideos = {};

    const accounts = bulkLaunchState.selectedAccounts;
    const videos = state.selectedVideos;
    const totalUploads = accounts.length * videos.length;

    videoUploadState.totalUploads = totalUploads;
    videoUploadState.completedUploads = 0;
    videoUploadState.failedUploads = 0;

    // Show progress UI
    const progressContainer = document.getElementById('video-upload-progress-container');
    const startBtn = document.getElementById('start-upload-btn');
    const progressBar = document.getElementById('video-upload-bar');
    const progressText = document.getElementById('upload-progress-text');
    const detailsDiv = document.getElementById('video-upload-details');

    progressContainer.style.display = 'block';
    startBtn.disabled = true;
    startBtn.textContent = 'Uploading...';
    progressText.textContent = `0 / ${totalUploads}`;
    detailsDiv.innerHTML = '';

    addLog('info', `Starting video upload: ${videos.length} videos to ${accounts.length} accounts (${totalUploads} total)`);

    // Upload videos to each account
    for (const account of accounts) {
        const advertiserId = account.advertiser_id;
        const accountName = account.advertiser_name;

        // Initialize upload map for this account
        videoUploadState.uploadedVideos[advertiserId] = {};

        // Add account header to details
        detailsDiv.innerHTML += `<div class="upload-account-header" style="font-weight: 600; margin-top: 10px; color: #333;">📁 ${accountName}</div>`;

        for (const video of videos) {
            try {
                // Get the video URL for upload
                const videoUrl = video.video_url || video.url;

                if (!videoUrl) {
                    throw new Error('No video URL available');
                }

                // Add uploading status
                const statusId = `upload-status-${advertiserId}-${video.id}`;
                detailsDiv.innerHTML += `<div id="${statusId}" style="margin-left: 15px; color: #666;">⏳ Uploading: ${video.name || 'Video'}...</div>`;
                detailsDiv.scrollTop = detailsDiv.scrollHeight;

                // Call API to upload video
                const result = await apiRequest('upload_video_to_account', {
                    target_advertiser_id: advertiserId,
                    video_url: videoUrl,
                    file_name: video.name || `video_${video.id}`
                });

                if (result.success && result.data?.video_id) {
                    // Store the mapping
                    videoUploadState.uploadedVideos[advertiserId][video.id] = result.data.video_id;
                    videoUploadState.completedUploads++;

                    // Update status
                    const statusEl = document.getElementById(statusId);
                    if (statusEl) {
                        statusEl.innerHTML = `<span style="color: #22c55e;">✓</span> ${video.name || 'Video'} → ${result.data.video_id}`;
                    }

                    addLog('info', `Uploaded video "${video.name}" to ${accountName}: ${result.data.video_id}`);
                } else {
                    throw new Error(result.message || 'Upload failed');
                }
            } catch (error) {
                videoUploadState.failedUploads++;

                // Update status with error
                const statusId = `upload-status-${advertiserId}-${video.id}`;
                const statusEl = document.getElementById(statusId);
                if (statusEl) {
                    statusEl.innerHTML = `<span style="color: #ef4444;">✗</span> ${video.name || 'Video'}: ${error.message}`;
                }

                addLog('error', `Failed to upload video "${video.name}" to ${accountName}: ${error.message}`);
            }

            // Update progress
            const completed = videoUploadState.completedUploads + videoUploadState.failedUploads;
            const progress = Math.round((completed / totalUploads) * 100);
            progressBar.style.width = `${progress}%`;
            progressText.textContent = `${completed} / ${totalUploads}`;
        }

        // Update account's video mapping in bulkLaunchState
        if (Object.keys(videoUploadState.uploadedVideos[advertiserId]).length > 0) {
            const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
            if (selectedAccount) {
                selectedAccount.video_mapping = videoUploadState.uploadedVideos[advertiserId];
            }

            // Initialize accountAssets if needed and update videoMatch
            if (!bulkLaunchState.accountAssets[advertiserId]) {
                bulkLaunchState.accountAssets[advertiserId] = {};
            }

            // Mark videos as matched
            const uploadedCount = Object.keys(videoUploadState.uploadedVideos[advertiserId]).length;
            bulkLaunchState.accountAssets[advertiserId].videoMatch = {
                matched: Object.entries(videoUploadState.uploadedVideos[advertiserId]).map(([srcId, tgtId]) => ({
                    source_video_id: srcId,
                    target_video_id: tgtId
                })),
                unmatched: [],
                match_rate: Math.round((uploadedCount / videos.length) * 100)
            };
        }
    }

    // Upload complete
    videoUploadState.isUploading = false;

    // Show completion status
    const completeStatus = document.getElementById('upload-complete-status');
    const completeDetails = document.getElementById('upload-complete-details');

    if (videoUploadState.failedUploads === 0) {
        completeStatus.style.display = 'block';
        completeStatus.style.background = '#e8f5e9';
        completeStatus.style.borderColor = '#4caf50';
        completeStatus.querySelector('p').innerHTML = `<span style="font-size: 18px;">✅</span> Videos uploaded successfully!`;
        completeDetails.textContent = `${videoUploadState.completedUploads} videos uploaded to ${accounts.length} accounts.`;
        startBtn.style.display = 'none';
    } else if (videoUploadState.completedUploads > 0) {
        completeStatus.style.display = 'block';
        completeStatus.style.background = '#fff3cd';
        completeStatus.style.borderColor = '#ffc107';
        completeStatus.querySelector('p').innerHTML = `<span style="font-size: 18px;">⚠️</span> Upload completed with some failures`;
        completeDetails.textContent = `${videoUploadState.completedUploads} succeeded, ${videoUploadState.failedUploads} failed.`;
        startBtn.textContent = '🔄 Retry Failed Uploads';
        startBtn.disabled = false;
    } else {
        completeStatus.style.display = 'block';
        completeStatus.style.background = '#fee2e2';
        completeStatus.style.borderColor = '#ef4444';
        completeStatus.querySelector('p').innerHTML = `<span style="font-size: 18px;">❌</span> Upload failed`;
        completeDetails.textContent = `All ${videoUploadState.failedUploads} uploads failed. Please check your connection and try again.`;
        startBtn.textContent = '🔄 Retry Upload';
        startBtn.disabled = false;
    }

    // Update modal counts
    updateBulkModalCounts();

    addLog('info', `Video upload complete: ${videoUploadState.completedUploads} success, ${videoUploadState.failedUploads} failed`);
}

// Update bulkLaunchState with duplicate settings
bulkLaunchState.duplicatesEnabled = false;
bulkLaunchState.duplicateCount = 1;

// ==========================================
// CAMPAIGN LISTING FUNCTIONALITY
// ==========================================

// ==========================================
// DATE RANGE FILTER FUNCTIONS
// ==========================================

// Initialize date range on page load (default to today)
function initializeDateRange() {
    const today = new Date();
    const todayStr = formatDateForInput(today);

    state.dateRangeStart = todayStr;
    state.dateRangeEnd = todayStr;
    state.dateRangePreset = 'today';

    // Set date inputs
    const dateFrom = document.getElementById('date-from');
    const dateTo = document.getElementById('date-to');
    if (dateFrom) dateFrom.value = todayStr;
    if (dateTo) dateTo.value = todayStr;

    // Update display
    updateDateRangeDisplay();
}

// Format date for input element (YYYY-MM-DD)
function formatDateForInput(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Format date for display (e.g., "Jan 17, 2026")
function formatDateForDisplay(dateStr) {
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// Set date preset (today, 7days, 30days)
function setDatePreset(preset) {
    const today = new Date();
    let startDate, endDate;

    switch (preset) {
        case 'today':
            startDate = today;
            endDate = today;
            break;
        case 'yesterday':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 1);
            endDate = new Date(today);
            endDate.setDate(today.getDate() - 1);
            break;
        case '7days':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 6); // Last 7 days including today
            endDate = today;
            break;
        case '30days':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 29); // Last 30 days including today
            endDate = today;
            break;
        case 'custom':
            // Don't change dates, just show the picker
            toggleCustomDatePicker();
            return;
        default:
            startDate = today;
            endDate = today;
    }

    state.dateRangePreset = preset;
    state.dateRangeStart = formatDateForInput(startDate);
    state.dateRangeEnd = formatDateForInput(endDate);

    // Update UI
    updateDatePresetButtons(preset);
    updateDateRangeDisplay();

    // Hide custom picker if visible
    const picker = document.getElementById('date-range-picker');
    if (picker) picker.style.display = 'none';

    // Reload campaigns with new date range
    state.campaignsLoaded = false;
    loadCampaigns();

    // Notify shell.js for multi-account mode
    if (typeof window.onShellDateRangeChange === 'function') {
        window.onShellDateRangeChange();
    }
}

// Toggle custom date picker visibility
function toggleCustomDatePicker() {
    const picker = document.getElementById('date-range-picker');
    const isVisible = picker.style.display !== 'none';

    if (isVisible) {
        picker.style.display = 'none';
    } else {
        picker.style.display = 'flex';
        // Set current values
        const dateFrom = document.getElementById('date-from');
        const dateTo = document.getElementById('date-to');
        if (dateFrom) dateFrom.value = state.dateRangeStart;
        if (dateTo) dateTo.value = state.dateRangeEnd;
    }

    // Update button state
    updateDatePresetButtons('custom');
}

// Apply custom date range
function applyCustomDateRange() {
    const dateFrom = document.getElementById('date-from');
    const dateTo = document.getElementById('date-to');

    if (!dateFrom.value || !dateTo.value) {
        showToast('Please select both start and end dates', 'error');
        return;
    }

    if (dateFrom.value > dateTo.value) {
        showToast('Start date must be before end date', 'error');
        return;
    }

    state.dateRangePreset = 'custom';
    state.dateRangeStart = dateFrom.value;
    state.dateRangeEnd = dateTo.value;

    // Update UI
    updateDatePresetButtons('custom');
    updateDateRangeDisplay();

    // Hide picker
    const picker = document.getElementById('date-range-picker');
    if (picker) picker.style.display = 'none';

    // Reload campaigns with new date range
    state.campaignsLoaded = false;
    loadCampaigns();

    // Notify shell.js for multi-account mode
    if (typeof window.onShellDateRangeChange === 'function') {
        window.onShellDateRangeChange();
    }
}

// Update date preset button active states
function updateDatePresetButtons(activePreset) {
    document.querySelectorAll('.date-preset-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.preset === activePreset) {
            btn.classList.add('active');
        }
    });
}

// Update the date range display text
function updateDateRangeDisplay() {
    const displayEl = document.getElementById('date-range-display');
    if (!displayEl) return;

    let displayText;
    switch (state.dateRangePreset) {
        case 'today':
            displayText = 'Today';
            break;
        case 'yesterday':
            displayText = 'Yesterday';
            break;
        case '7days':
            displayText = 'Last 7 Days';
            break;
        case '30days':
            displayText = 'Last 30 Days';
            break;
        case 'custom':
            const start = formatDateForDisplay(state.dateRangeStart);
            const end = formatDateForDisplay(state.dateRangeEnd);
            displayText = start === end ? start : `${start} - ${end}`;
            break;
        default:
            displayText = 'Today';
    }

    displayEl.textContent = displayText;
}

// Get current date range for API calls
function getCurrentDateRange() {
    // If not initialized, default to today
    if (!state.dateRangeStart || !state.dateRangeEnd) {
        const today = formatDateForInput(new Date());
        return { start_date: today, end_date: today };
    }
    return {
        start_date: state.dateRangeStart,
        end_date: state.dateRangeEnd
    };
}

// ==========================================
// AD ACCOUNT SWITCHING & SEARCH
// ==========================================

// Show the ad account dropdown
function showAdAccountDropdown() {
    const dropdown = document.getElementById('ad-account-dropdown');
    if (dropdown) {
        dropdown.style.display = 'block';
    }
}

// Hide the ad account dropdown
function hideAdAccountDropdown() {
    const dropdown = document.getElementById('ad-account-dropdown');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
}

// Filter ad account options based on search input
function filterAdAccountOptions() {
    const searchInput = document.getElementById('ad-account-search');
    const dropdown = document.getElementById('ad-account-dropdown');
    if (!searchInput || !dropdown) return;

    const searchTerm = searchInput.value.toLowerCase().trim();
    const options = dropdown.querySelectorAll('.ad-account-option');

    let visibleCount = 0;
    options.forEach(option => {
        const name = option.getAttribute('data-name') || '';
        const advertiserId = option.getAttribute('data-advertiser-id') || '';

        // Match against name or full advertiser ID
        const matches = name.includes(searchTerm) || advertiserId.toLowerCase().includes(searchTerm);

        option.style.display = matches ? 'block' : 'none';
        if (matches) visibleCount++;
    });

    // Show dropdown if there's a search term
    if (searchTerm.length > 0) {
        showAdAccountDropdown();
    }
}

// Select an ad account from the dropdown
function selectAdAccount(advertiserId, displayName) {
    const searchInput = document.getElementById('ad-account-search');
    if (searchInput) {
        searchInput.value = displayName;
    }

    // Hide dropdown
    hideAdAccountDropdown();

    // Update selected state in dropdown
    const options = document.querySelectorAll('.ad-account-option');
    options.forEach(option => {
        option.classList.remove('selected');
        if (option.getAttribute('data-advertiser-id') === advertiserId) {
            option.classList.add('selected');
        }
    });

    // Switch to the selected account
    switchAdAccount(advertiserId);
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const wrapper = document.querySelector('.ad-account-search-wrapper');
    const dropdown = document.getElementById('ad-account-dropdown');

    if (wrapper && dropdown && !wrapper.contains(event.target)) {
        hideAdAccountDropdown();
    }
});

// Initialize ad account search input with currently selected account
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('ad-account-search');
    const selectedOption = document.querySelector('.ad-account-option.selected');

    if (searchInput && selectedOption) {
        // Get the display name from the selected option
        const nameDiv = selectedOption.querySelector('div:first-child');
        if (nameDiv) {
            searchInput.value = nameDiv.textContent.trim();
        }
    }
});

// Switch to a different ad account
async function switchAdAccount(advertiserId) {
    if (!advertiserId) return;

    const selectEl = document.getElementById('ad-account-select');
    const switchingEl = document.getElementById('ad-account-switching');

    // Show switching indicator
    if (selectEl) selectEl.disabled = true;
    if (switchingEl) switchingEl.style.display = 'flex';

    addLog('info', `Switching to ad account: ${advertiserId}`);

    try {
        // Call API to set the new advertiser
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({
                action: 'set_advertiser',
                advertiser_id: advertiserId
            })
        });

        const result = await response.json();

        if (result.success) {
            addLog('success', `Switched to ad account: ${advertiserId}`);
            showToast('Ad account switched successfully', 'success');

            // ========================================
            // UPDATE CURRENT ADVERTISER ID (tab-specific)
            // This ensures this tab uses the correct advertiser even if
            // another tab changes the PHP session's advertiser
            // ========================================
            state.currentAdvertiserId = advertiserId;
            window.TIKTOK_ADVERTISER_ID = advertiserId;

            // ========================================
            // CLEAR ALL STATE FOR NEW ACCOUNT
            // ========================================

            // Clear campaigns state
            state.campaignsLoaded = false;
            state.campaignsList = [];
            state.filteredCampaigns = [];
            state.expandedCampaigns = {};
            state.expandedAdgroups = {};
            state.selectedCampaigns = [];

            // Clear media library - crucial for showing correct videos
            state.mediaLibrary = [];
            state.selectedVideos = [];
            state.creatives = [];

            // Clear identities state
            state.identities = [];
            state.customIdentities = [];
            state.tiktokPages = [];

            // Clear CTA portfolios state
            state.ctaPortfolios = [];
            state.selectedPortfolioId = null;
            state.selectedPortfolioName = null;
            state.globalCtaPortfolioId = null;

            // Clear pixel state
            state.pixelId = null;
            state.optimizationEvent = null;

            // Clear duplicate state to prevent showing old account's videos
            duplicateState.campaignDetails = null;
            duplicateState.changedVideos = null;

            // Clear video modal state
            videoModalState.selectedVideos = [];
            videoModalState.allVideos = [];

            // Reset creation tracking
            state.campaignCreated = false;
            state.adGroupCreated = false;
            state.adCreated = false;
            state.campaignId = null;
            state.adGroupId = null;
            state.adId = null;

            // Clear RedTrack state for new account
            state.redtrackMappings = {};
            state.redtrackLpCtrs = {};
            state._redtrackMappingsLoaded = false;

            // ========================================
            // CLEAR UI DROPDOWNS (show loading state)
            // ========================================
            const pixelSelect = document.getElementById('pixel-select');
            if (pixelSelect) pixelSelect.innerHTML = '<option value="">Loading pixels...</option>';

            const identitySelect = document.getElementById('global-identity');
            if (identitySelect) identitySelect.innerHTML = '<option value="">Loading identities...</option>';

            const ctaSelect = document.getElementById('cta-portfolio-select');
            if (ctaSelect) ctaSelect.innerHTML = '<option value="">Loading portfolios...</option>';

            // Clear video grid displays
            const videoGrid = document.getElementById('video-grid');
            if (videoGrid) videoGrid.innerHTML = '';

            const selectedVideosGrid = document.getElementById('selected-videos-grid');
            if (selectedVideosGrid) selectedVideosGrid.innerHTML = '<div class="empty-selection">No videos selected</div>';

            // ========================================
            // RELOAD ALL DATA FOR NEW ACCOUNT
            // ========================================

            // Reload all account-specific data in parallel
            await Promise.all([
                loadMediaLibrary(),
                loadPixels(),
                loadIdentities(),
                loadCtaPortfolios()
            ]);

            // Reload campaigns with new ad account
            await loadCampaigns();

            addLog('info', `Account data reloaded: ${state.mediaLibrary.length} videos, ${state.identities.length} identities`);
        } else {
            throw new Error(result.message || 'Failed to switch ad account');
        }
    } catch (error) {
        console.error('Error switching ad account:', error);
        addLog('error', `Failed to switch ad account: ${error.message}`);
        showToast('Failed to switch ad account: ' + error.message, 'error');

        // Revert the dropdown selection (reload page to get correct state)
        window.location.reload();
    } finally {
        // Hide switching indicator
        if (selectEl) selectEl.disabled = false;
        if (switchingEl) switchingEl.style.display = 'none';
    }
}

// Switch between Create, My Campaigns, and Media Library views
function switchMainView(view) {
    state.currentView = view;

    const createView = document.getElementById('create-view');
    const campaignsView = document.getElementById('campaigns-view');
    const mediaView = document.getElementById('media-view');
    const tabCreate = document.getElementById('tab-create');
    const tabCampaigns = document.getElementById('tab-campaigns');
    const tabMedia = document.getElementById('tab-media');

    // Hide all views first
    if (createView) createView.style.display = 'none';
    if (campaignsView) campaignsView.style.display = 'none';
    if (mediaView) mediaView.style.display = 'none';

    // Remove active from all tabs
    if (tabCreate) tabCreate.classList.remove('active');
    if (tabCampaigns) tabCampaigns.classList.remove('active');
    if (tabMedia) tabMedia.classList.remove('active');

    if (view === 'create') {
        if (createView) createView.style.display = 'block';
        if (tabCreate) tabCreate.classList.add('active');
    } else if (view === 'campaigns') {
        if (campaignsView) campaignsView.style.display = 'block';
        if (tabCampaigns) tabCampaigns.classList.add('active');

        // Load campaigns if not already loaded
        if (!state.campaignsLoaded) {
            loadCampaigns();
        }
    } else if (view === 'media') {
        if (mediaView) mediaView.style.display = 'block';
        if (tabMedia) tabMedia.classList.add('active');

        // Load media library
        loadMediaLibraryView();
    }

    addLog('info', `Switched to ${view} view`);
}

// ============================================
// MEDIA LIBRARY VIEW FUNCTIONS
// ============================================

// Media library view state
let mediaLibraryState = {
    videos: [],
    images: [],
    isLoading: false,
    uploadQueue: [],
    uploadCompleted: 0,
    uploadFailed: 0,
    uploadProcessing: 0,  // Videos accepted but still processing
    uploadTotal: 0
};

// Load and display media library in the Media View tab
async function loadMediaLibraryView(forceRefresh = false) {
    if (mediaLibraryState.isLoading) return;

    mediaLibraryState.isLoading = true;

    const videoGrid = document.getElementById('media-video-grid');
    const imageGrid = document.getElementById('media-image-grid');
    const videoCount = document.getElementById('media-video-count');
    const imageCount = document.getElementById('media-image-count');

    // Show loading state
    if (videoGrid) videoGrid.innerHTML = '<div style="text-align: center; padding: 40px; color: #94a3b8; grid-column: 1/-1;">Loading videos...</div>';
    if (imageGrid) imageGrid.innerHTML = '<div style="text-align: center; padding: 40px; color: #94a3b8; grid-column: 1/-1;">Loading images...</div>';

    try {
        const [videosResult, imagesResult] = await Promise.all([
            apiRequest('get_videos', { force_refresh: forceRefresh }),
            apiRequest('get_images', { force_refresh: forceRefresh })
        ]);

        mediaLibraryState.videos = [];
        mediaLibraryState.images = [];

        if (videosResult.success && videosResult.data) {
            mediaLibraryState.videos = videosResult.data.map(video => ({
                video_id: video.video_id,
                name: video.file_name || video.displayable_name || video.video_id,
                thumbnail: video.video_cover_url || video.preview_url || '',
                duration: video.duration || 0,
                create_time: video.create_time || ''
            }));
        }

        if (imagesResult.success && imagesResult.data) {
            mediaLibraryState.images = imagesResult.data.map(image => ({
                image_id: image.image_id,
                name: image.file_name || image.image_id,
                url: image.image_url || '',
                create_time: image.create_time || ''
            }));
        }

        // Update counts
        if (videoCount) videoCount.textContent = mediaLibraryState.videos.length;
        if (imageCount) imageCount.textContent = mediaLibraryState.images.length;

        // Render grids
        renderMediaVideoGrid();
        renderMediaImageGrid();

        addLog('success', `Loaded ${mediaLibraryState.videos.length} videos, ${mediaLibraryState.images.length} images`);

    } catch (error) {
        console.error('Error loading media library:', error);
        addLog('error', `Failed to load media library: ${error.message}`);

        if (videoGrid) videoGrid.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444; grid-column: 1/-1;">Failed to load videos</div>';
        if (imageGrid) imageGrid.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444; grid-column: 1/-1;">Failed to load images</div>';
    }

    mediaLibraryState.isLoading = false;
}

// Render video grid in media library view
function renderMediaVideoGrid() {
    const grid = document.getElementById('media-video-grid');
    if (!grid) return;

    if (mediaLibraryState.videos.length === 0) {
        grid.innerHTML = '<div style="text-align: center; padding: 40px; color: #94a3b8; grid-column: 1/-1;">No videos found. Upload some videos to get started!</div>';
        return;
    }

    grid.innerHTML = mediaLibraryState.videos.map(video => `
        <div class="media-library-item" style="border: 2px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: white; transition: all 0.2s;">
            <div style="position: relative; height: 120px; background: #0f0f0f; display: flex; align-items: center; justify-content: center;">
                ${video.thumbnail ?
                    `<img src="${video.thumbnail}" alt="${video.name}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                     <div style="display: none; color: #64748b; font-size: 32px;">📹</div>` :
                    `<div style="color: #64748b; font-size: 32px;">📹</div>`
                }
                ${video.duration ? `<span style="position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.8); color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px;">${formatDuration(video.duration)}</span>` : ''}
            </div>
            <div style="padding: 10px;">
                <div style="font-size: 12px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${video.name}">${video.name}</div>
                <div style="font-size: 10px; color: #94a3b8; margin-top: 4px;">ID: ${video.video_id.slice(-8)}</div>
            </div>
        </div>
    `).join('');
}

// Render image grid in media library view
function renderMediaImageGrid() {
    const grid = document.getElementById('media-image-grid');
    if (!grid) return;

    if (mediaLibraryState.images.length === 0) {
        grid.innerHTML = '<div style="text-align: center; padding: 40px; color: #94a3b8; grid-column: 1/-1;">No images found</div>';
        return;
    }

    grid.innerHTML = mediaLibraryState.images.map(image => `
        <div class="media-library-item" style="border: 2px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: white; transition: all 0.2s;">
            <div style="position: relative; height: 100px; background: #f8fafc; display: flex; align-items: center; justify-content: center;">
                ${image.url ?
                    `<img src="${image.url}" alt="${image.name}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                     <div style="display: none; color: #64748b; font-size: 28px;">🖼️</div>` :
                    `<div style="color: #64748b; font-size: 28px;">🖼️</div>`
                }
            </div>
            <div style="padding: 8px;">
                <div style="font-size: 11px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${image.name}">${image.name}</div>
            </div>
        </div>
    `).join('');
}

// Format duration in seconds to MM:SS
function formatDuration(seconds) {
    if (!seconds) return '';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

// Handle bulk video upload from media library view
async function handleMediaLibraryUpload(event) {
    const files = Array.from(event.target.files);
    if (files.length === 0) return;

    // Validate files
    const validFiles = [];
    const maxSize = 500 * 1024 * 1024; // 500MB

    for (const file of files) {
        if (!file.type.startsWith('video/')) {
            showToast(`Skipped ${file.name}: Not a video file`, 'warning');
            continue;
        }
        if (file.size > maxSize) {
            showToast(`Skipped ${file.name}: Exceeds 500MB limit`, 'warning');
            continue;
        }
        validFiles.push(file);
    }

    if (validFiles.length === 0) {
        showToast('No valid video files selected', 'error');
        event.target.value = '';
        return;
    }

    // Initialize upload state
    mediaLibraryState.uploadQueue = validFiles;
    mediaLibraryState.uploadCompleted = 0;
    mediaLibraryState.uploadFailed = 0;
    mediaLibraryState.uploadProcessing = 0;
    mediaLibraryState.uploadTotal = validFiles.length;

    // Show progress UI
    showMediaUploadProgress();

    // Upload files in PARALLEL BATCHES of 2 for reliability
    const BATCH_SIZE = 2;
    const totalBatches = Math.ceil(validFiles.length / BATCH_SIZE);

    for (let batchIndex = 0; batchIndex < totalBatches; batchIndex++) {
        const startIdx = batchIndex * BATCH_SIZE;
        const batch = validFiles.slice(startIdx, startIdx + BATCH_SIZE);

        // Upload entire batch in parallel
        await Promise.all(
            batch.map((file, idx) => uploadMediaVideo(file, startIdx + idx))
        );

        // Small delay between batches to prevent server overload
        if (batchIndex < totalBatches - 1) {
            await new Promise(resolve => setTimeout(resolve, 300));
        }
    }

    // Complete
    finishMediaUpload();

    // Clear file input
    event.target.value = '';
}

// Show upload progress UI with individual progress bars
function showMediaUploadProgress() {
    const container = document.getElementById('media-upload-progress');
    const list = document.getElementById('media-upload-list');
    const countEl = document.getElementById('media-upload-count');

    if (container) container.style.display = 'block';
    if (countEl) countEl.textContent = `0/${mediaLibraryState.uploadTotal}`;

    // Create item for each file with individual progress bar
    if (list) {
        list.innerHTML = mediaLibraryState.uploadQueue.map((file, i) => `
            <div class="upload-item" id="media-upload-item-${i}" style="display: flex; flex-direction: column; gap: 6px; padding: 10px 12px; background: #f8fafc; border-radius: 6px; margin-bottom: 8px; font-size: 13px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500;">${file.name}</span>
                    <span style="color: #64748b; font-size: 12px;">${(file.size / 1024 / 1024).toFixed(1)}MB</span>
                    <span class="upload-status" style="font-weight: 600; padding: 2px 10px; border-radius: 4px; font-size: 11px; background: #f1f5f9; color: #64748b; min-width: 60px; text-align: center;">Pending</span>
                </div>
                <div style="height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                    <div class="upload-item-progress-bar" style="height: 100%; width: 0%; background: linear-gradient(90deg, #3b82f6, #60a5fa); transition: width 0.2s ease;"></div>
                </div>
            </div>
        `).join('');
    }
}

// Upload single video with real-time progress
async function uploadMediaVideo(file, index) {
    const itemId = `media-upload-item-${index}`;
    updateMediaUploadStatus(itemId, 'uploading', '0%', '#dbeafe', '#1d4ed8', 0);

    // Add timestamp to filename
    const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
    const ext = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.')) : '';
    const baseName = file.name.includes('.') ? file.name.slice(0, file.name.lastIndexOf('.')) : file.name;
    const newFileName = `${baseName}_${timestamp}${ext}`;

    // Pre-generate thumbnail for instant preview (before upload starts)
    console.log('[MediaLibrary Upload] Pre-generating thumbnail for', file.name);
    const thumbnailUrl = await generateVideoThumbnailSafe(file);
    console.log('[MediaLibrary Upload] Thumbnail generated:', thumbnailUrl ? 'success' : 'failed');

    const formData = new FormData();
    formData.append('video', file, newFileName);

    addLog('info', `Uploading video: ${newFileName}`);

    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        const uploadTimeout = 300000; // 5 minutes timeout
        let timeoutId;
        let uploadComplete = false;

        // Real-time progress tracking
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                updateMediaUploadStatus(itemId, 'uploading', `${percent}%`, '#dbeafe', '#1d4ed8', percent);
                if (percent === 100) {
                    uploadComplete = true;
                    updateMediaUploadStatus(itemId, 'uploading', 'Processing...', '#dbeafe', '#1d4ed8', 100);
                }
            }
        });

        // Success handler
        xhr.addEventListener('load', () => {
            clearTimeout(timeoutId);

            // Try to parse response regardless of HTTP status
            // (polling errors can return 500 even when upload succeeded)
            try {
                let result;
                try {
                    result = JSON.parse(xhr.responseText);
                } catch (e) {
                    const jsonMatch = xhr.responseText.match(/\{[\s\S]*"success"[\s\S]*\}/);
                    if (jsonMatch) {
                        result = JSON.parse(jsonMatch[0]);
                    } else {
                        // No parseable JSON — fall back to HTTP status message
                        if (xhr.status >= 200 && xhr.status < 300) {
                            throw new Error('Invalid server response');
                        } else if (uploadComplete) {
                            // Upload reached 100% — video likely accepted, server error during processing
                            mediaLibraryState.uploadProcessing++;
                            updateMediaUploadStatus(itemId, 'processing', '⏳ Processing (check library)', '#fef3c7', '#d97706', 100);
                            addLog('info', `Video likely accepted but server returned ${xhr.status} during processing: ${newFileName}`);
                            updateMediaUploadProgress();
                            resolve({ success: true, processing: true });
                            return;
                        } else {
                            handleUploadError(`Server error (${xhr.status})`);
                            return;
                        }
                    }
                }

                console.log('Upload response:', result);

                if (result.success && result.data?.video_id) {
                    // Immediate success with video_id
                    mediaLibraryState.uploadCompleted++;
                    updateMediaUploadStatus(itemId, 'success', '✓ Uploaded', '#dcfce7', '#16a34a', 100);
                    addLog('success', `Video uploaded: ${result.data.video_id}`);

                    mediaLibraryState.videos.unshift({
                        video_id: result.data.video_id,
                        name: newFileName,
                        // Use pre-generated thumbnail for instant preview
                        thumbnail: thumbnailUrl || '',
                        duration: 0,
                        create_time: new Date().toISOString()
                    });

                    updateMediaUploadProgress();
                    resolve({ success: true, video_id: result.data.video_id });
                } else if (result.success && result.processing) {
                    // Video accepted but processing - count as SUCCESS!
                    mediaLibraryState.uploadProcessing++;
                    updateMediaUploadStatus(itemId, 'processing', '⏳ Processing', '#fef3c7', '#d97706', 100);
                    addLog('info', `Video accepted, processing: ${newFileName}`);
                    updateMediaUploadProgress();
                    resolve({ success: true, processing: true });
                } else if (result.success) {
                    // Success but no video_id (legacy response)
                    mediaLibraryState.uploadProcessing++;
                    updateMediaUploadStatus(itemId, 'processing', '⏳ Accepted', '#fef3c7', '#d97706', 100);
                    addLog('info', `Video accepted: ${newFileName}`);
                    updateMediaUploadProgress();
                    resolve({ success: true, processing: true });
                } else if (uploadComplete) {
                    // Upload data was fully sent but server reported failure
                    // Video may still have been accepted — show as processing
                    mediaLibraryState.uploadProcessing++;
                    updateMediaUploadStatus(itemId, 'processing', '⏳ Check library', '#fef3c7', '#d97706', 100);
                    addLog('warning', `Upload completed but server error: ${result.message || 'Unknown'} — video may still appear in library`);
                    updateMediaUploadProgress();
                    resolve({ success: true, processing: true });
                } else {
                    // Actual failure
                    handleUploadError(result.message || 'Upload failed');
                }
            } catch (e) {
                if (uploadComplete) {
                    // Upload reached 100% — treat as processing
                    mediaLibraryState.uploadProcessing++;
                    updateMediaUploadStatus(itemId, 'processing', '⏳ Processing (check library)', '#fef3c7', '#d97706', 100);
                    addLog('warning', `Upload completed but response error — video may appear in library after refresh`);
                    updateMediaUploadProgress();
                    resolve({ success: true, processing: true });
                } else {
                    handleUploadError('Invalid server response');
                }
            }
        });

        // Error handler
        xhr.addEventListener('error', () => {
            clearTimeout(timeoutId);
            handleUploadError(uploadComplete ? 'Connection lost after upload - check library' : 'Network error');
        });

        // Abort handler (timeout)
        xhr.addEventListener('abort', () => {
            clearTimeout(timeoutId);
            handleUploadError(uploadComplete ? 'Timeout - video may have uploaded, check library' : 'Upload timeout');
        });

        function handleUploadError(errorMsg) {
            mediaLibraryState.uploadFailed++;
            // Show truncated error in status for better visibility
            const shortError = errorMsg.length > 20 ? errorMsg.substring(0, 20) + '...' : errorMsg;
            updateMediaUploadStatus(itemId, 'failed', `✗ ${shortError}`, '#fee2e2', '#dc2626', 0);
            addLog('error', `Upload failed for ${file.name}: ${errorMsg}`);
            updateMediaUploadProgress();
            resolve({ success: false, error: errorMsg });
        }

        // Set timeout
        timeoutId = setTimeout(() => xhr.abort(), uploadTimeout);

        // Send request
        xhr.open('POST', 'api.php?action=upload_video');
        xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || '');
        xhr.send(formData);
    });
}

// Update single upload item status with progress bar
function updateMediaUploadStatus(itemId, status, text, bgColor, textColor, progress = null) {
    const item = document.getElementById(itemId);
    if (!item) return;

    const statusEl = item.querySelector('.upload-status');
    if (statusEl) {
        statusEl.textContent = text;
        statusEl.style.background = bgColor;
        statusEl.style.color = textColor;
    }

    // Update individual progress bar if present
    const progressBar = item.querySelector('.upload-item-progress-bar');
    if (progressBar && progress !== null) {
        progressBar.style.width = `${progress}%`;
    }
}

// Update overall upload progress
function updateMediaUploadProgress() {
    const completed = mediaLibraryState.uploadCompleted + mediaLibraryState.uploadFailed + mediaLibraryState.uploadProcessing;
    const total = mediaLibraryState.uploadTotal;
    const percent = Math.round((completed / total) * 100);

    const countEl = document.getElementById('media-upload-count');
    const barEl = document.getElementById('media-upload-bar');

    if (countEl) countEl.textContent = `${completed}/${total}`;
    if (barEl) barEl.style.width = `${percent}%`;
}

// Finish bulk upload
function finishMediaUpload() {
    const { uploadCompleted, uploadFailed, uploadProcessing, uploadTotal } = mediaLibraryState;

    if (uploadFailed === 0 && uploadProcessing === 0) {
        // All succeeded immediately
        showToast(`Successfully uploaded ${uploadCompleted} video${uploadCompleted > 1 ? 's' : ''}!`, 'success');
    } else if (uploadFailed === 0 && uploadProcessing > 0) {
        // Some or all are processing - this is OK, not an error
        if (uploadCompleted > 0) {
            showToast(`${uploadCompleted} uploaded, ${uploadProcessing} processing. Videos will appear in 1-2 minutes.`, 'success');
        } else {
            showToast(`${uploadProcessing} video${uploadProcessing > 1 ? 's' : ''} accepted! Will appear in 1-2 minutes.`, 'success');
        }
    } else if (uploadCompleted > 0 || uploadProcessing > 0) {
        // Mixed results
        const successCount = uploadCompleted + uploadProcessing;
        showToast(`${successCount} accepted, ${uploadFailed} failed. Processing videos appear in 1-2 min.`, 'warning');
    } else {
        // All failed
        showToast(`Failed to upload all ${uploadTotal} videos`, 'error');
    }

    // Update video count
    const videoCount = document.getElementById('media-video-count');
    if (videoCount) videoCount.textContent = mediaLibraryState.videos.length;

    // Refresh video grid
    renderMediaVideoGrid();

    // Hide progress after delay
    setTimeout(() => {
        const container = document.getElementById('media-upload-progress');
        if (container) container.style.display = 'none';
    }, 3000);

    // Background refresh to get proper thumbnails from API
    setTimeout(() => {
        loadMediaLibraryView(true);
    }, 2000);
}

// Refresh media library (Media View tab)
function refreshMediaViewLibrary() {
    showToast('Refreshing media library...', 'info');
    loadMediaLibraryView(true);
}

// Load campaigns from API (with metrics)
async function loadCampaigns() {
    const loadingEl = document.getElementById('campaign-loading');
    const emptyEl = document.getElementById('campaign-empty-state');
    const tableWrapper = document.getElementById('metrics-table-wrapper');
    const tableBody = document.getElementById('campaign-table-body');

    // Show loading state
    loadingEl.style.display = 'flex';
    emptyEl.style.display = 'none';
    if (tableWrapper) tableWrapper.style.display = 'none';
    if (tableBody) tableBody.innerHTML = '';

    // Reset expansion state
    state.expandedCampaigns = {};
    state.expandedAdgroups = {};

    // Get current date range
    const dateRange = getCurrentDateRange();
    addLog('info', `Loading campaigns with metrics for ${dateRange.start_date} to ${dateRange.end_date}...`);

    try {
        // Fetch campaigns and rejected ads in parallel
        const [result, rejectedResult] = await Promise.all([
            apiRequest('get_campaigns_with_metrics', dateRange),
            apiRequest('get_rejected_ads').catch(err => ({ success: false }))
        ]);

        // Handle rejected ads result
        if (rejectedResult && rejectedResult.success) {
            state.rejectedAds = rejectedResult.ads || [];
            state.rejectedAdsCount = rejectedResult.count || state.rejectedAds.length;
            state.rejectedAdsLoaded = true;
        } else {
            state.rejectedAds = [];
            state.rejectedAdsCount = 0;
            state.rejectedAdsLoaded = true;
        }
        updateRejectedAdsCount();

        if (result.success) {
            state.campaignsList = result.campaigns || [];
            state.campaignsLoaded = true;

            addLog('success', `Loaded ${state.campaignsList.length} campaigns with metrics`);

            // Apply current filter and render
            applyFiltersAndRender();

            // Update shell balance card with campaign spend data
            if (typeof window.updateBalanceFromCampaigns === 'function') {
                const totalSpend = state.campaignsList.reduce((sum, c) => sum + (parseFloat(c.spend) || 0), 0);
                window.updateBalanceFromCampaigns(totalSpend, state.campaignsList.length);
            }
        } else {
            throw new Error(result.message || 'Failed to load campaigns');
        }
    } catch (error) {
        console.error('Error loading campaigns:', error);
        addLog('error', `Failed to load campaigns: ${error.message}`);

        // Show empty state with error
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        emptyEl.querySelector('h3').textContent = 'Error Loading Campaigns';
        emptyEl.querySelector('p').textContent = error.message || 'Please try again later.';
    }
}

// Refresh campaign list
function refreshCampaignList() {
    state.campaignsLoaded = false;
    state._redtrackMappingsLoaded = false;
    state.redtrackLpCtrs = {};
    state.accountRtMetrics = null;
    loadCampaigns();
    showToast('Refreshing campaigns...', 'info');
}

// Filter campaigns by status
function filterCampaignsByStatus(status) {
    // If viewing rejected ads, exit that view first
    if (state.showingRejectedAds) {
        hideRejectedAds();
    }

    state.campaignFilter = status;

    // Clear selection when changing filters
    state.selectedCampaigns = [];

    // Update filter button active states
    document.querySelectorAll('.campaign-filter-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.filter === status) {
            btn.classList.add('active');
        }
    });

    applyFiltersAndRender();
    updateBulkSelectionUI();
}

// Search campaigns
function searchCampaigns() {
    const searchInput = document.getElementById('campaign-search-input');
    state.campaignSearchQuery = searchInput.value.toLowerCase().trim();
    applyFiltersAndRender();
}

// Apply all filters and render
function applyFiltersAndRender() {
    let filtered = [...state.campaignsList];

    // Apply status filter
    if (state.campaignFilter === 'active') {
        filtered = filtered.filter(c => c.operation_status === 'ENABLE');
    } else if (state.campaignFilter === 'inactive') {
        filtered = filtered.filter(c => c.operation_status === 'DISABLE');
    }

    // Apply search filter
    if (state.campaignSearchQuery) {
        filtered = filtered.filter(c =>
            c.campaign_name.toLowerCase().includes(state.campaignSearchQuery) ||
            c.campaign_id.toLowerCase().includes(state.campaignSearchQuery)
        );
    }

    // Sort by spend descending (highest spending campaigns first)
    filtered.sort((a, b) => (parseFloat(b.spend) || 0) - (parseFloat(a.spend) || 0));

    state.filteredCampaigns = filtered;

    // Update counts
    updateCampaignCounts();

    // Render the list
    renderCampaignList();
}

// Update filter counts
function updateCampaignCounts() {
    const allCount = state.campaignsList.length;
    const activeCount = state.campaignsList.filter(c => c.operation_status === 'ENABLE').length;
    const inactiveCount = state.campaignsList.filter(c => c.operation_status === 'DISABLE').length;

    document.getElementById('count-all').textContent = allCount;
    document.getElementById('count-active').textContent = activeCount;
    document.getElementById('count-inactive').textContent = inactiveCount;
    updateRejectedAdsCount();
}

// Update rejected ads count badge
function updateRejectedAdsCount() {
    const el = document.getElementById('count-rejected');
    if (el) el.textContent = state.rejectedAdsCount || 0;
}

// Render campaign list (table-based with metrics)
function renderCampaignList() {
    const loadingEl = document.getElementById('campaign-loading');
    const emptyEl = document.getElementById('campaign-empty-state');
    const tableWrapper = document.getElementById('metrics-table-wrapper');
    const tableBody = document.getElementById('campaign-table-body');

    // Hide loading
    loadingEl.style.display = 'none';

    // Check if empty
    if (state.filteredCampaigns.length === 0) {
        emptyEl.style.display = 'block';
        if (tableWrapper) tableWrapper.style.display = 'none';
        emptyEl.querySelector('h3').textContent = 'No campaigns found';
        emptyEl.querySelector('p').textContent =
            state.campaignSearchQuery
                ? 'No campaigns match your search. Try a different keyword.'
                : state.campaignFilter !== 'all'
                    ? `No ${state.campaignFilter} campaigns found.`
                    : "You haven't created any campaigns yet.";
        // Clear totals when empty
        renderCampaignTotals();
        return;
    }

    emptyEl.style.display = 'none';
    if (tableWrapper) tableWrapper.style.display = 'block';

    // Render campaign table rows
    if (tableBody) {
        tableBody.innerHTML = state.filteredCampaigns.map(campaign => renderCampaignTableRow(campaign)).join('');
    }

    // Render totals footer
    renderCampaignTotals();

    // Load optimizer monitoring status for badges
    loadOptimizerMonitoringStatus();

    // Load account-level RT campaign banner
    loadCampaignsAccountRt();

    // Load RedTrack LP CTR mappings (only on first render)
    if (!state._redtrackMappingsLoaded) {
        state._redtrackMappingsLoaded = true;
        loadRedTrackMappings();
    }
}

// Calculate and render campaign totals in table footer
function renderCampaignTotals() {
    const tfoot = document.getElementById('campaign-table-totals');
    if (!tfoot) return;

    // If no campaigns, clear the footer
    if (!state.campaignsList || state.campaignsList.length === 0) {
        tfoot.innerHTML = '';
        return;
    }

    // Separate campaigns by status
    const allCampaigns = state.campaignsList;
    const activeCampaigns = allCampaigns.filter(c => c.operation_status === 'ENABLE');
    const inactiveCampaigns = allCampaigns.filter(c => c.operation_status === 'DISABLE');

    // Calculate totals for each group
    const calculateTotals = (campaigns) => {
        return {
            count: campaigns.length,
            budget: campaigns.reduce((sum, c) => sum + (parseFloat(c.budget) || 0), 0),
            spend: campaigns.reduce((sum, c) => sum + (parseFloat(c.spend) || 0), 0),
            impressions: campaigns.reduce((sum, c) => sum + (parseInt(c.impressions) || 0), 0),
            clicks: campaigns.reduce((sum, c) => sum + (parseInt(c.clicks) || 0), 0),
            conversions: campaigns.reduce((sum, c) => sum + (parseInt(c.conversions) || 0), 0),
            results: campaigns.reduce((sum, c) => sum + (parseInt(c.results) || 0), 0)
        };
    };

    const allTotals = calculateTotals(allCampaigns);
    const activeTotals = calculateTotals(activeCampaigns);
    const inactiveTotals = calculateTotals(inactiveCampaigns);

    // Calculate averages (CPC, CTR, Cost/Result)
    const calculateAverages = (totals) => {
        return {
            cpc: totals.clicks > 0 ? totals.spend / totals.clicks : 0,
            ctr: totals.impressions > 0 ? (totals.clicks / totals.impressions) * 100 : 0,
            costPerResult: totals.results > 0 ? totals.spend / totals.results : 0
        };
    };

    const allAvg = calculateAverages(allTotals);
    const activeAvg = calculateAverages(activeTotals);
    const inactiveAvg = calculateAverages(inactiveTotals);

    // Render totals row
    const renderTotalsRow = (label, totals, averages, rowClass, badgeClass) => {
        return `
            <tr class="${rowClass}">
                <td colspan="2"></td>
                <td class="totals-label">
                    ${label}
                    <span class="totals-type-badge ${badgeClass}">${totals.count}</span>
                </td>
                <td></td>
                <td style="text-align: right; font-weight: 600;">$${totals.budget.toFixed(2)}</td>
                <td style="text-align: right; font-weight: 600; color: #dc2626;">$${totals.spend.toFixed(2)}</td>
                <td style="text-align: right;">$${averages.cpc.toFixed(2)}</td>
                <td style="text-align: right;">${formatNumberWithCommas(totals.impressions)}</td>
                <td style="text-align: right;">${formatNumberWithCommas(totals.clicks)}</td>
                <td style="text-align: right;">${averages.ctr.toFixed(2)}%</td>
                <td></td>
                <td></td>
                <td></td>
                <td style="text-align: right;">${formatNumberWithCommas(totals.conversions)}</td>
                <td style="text-align: right;">$${averages.costPerResult.toFixed(2)}</td>
                <td style="text-align: right;">${formatNumberWithCommas(totals.results)}</td>
                <td></td>
            </tr>
        `;
    };

    // Build footer HTML with all three rows
    let footerHtml = '';

    // Show totals based on current filter or show all three
    if (state.campaignFilter === 'all' || !state.campaignFilter) {
        // Show all three totals when viewing "All" campaigns
        footerHtml += renderTotalsRow('Total (All)', allTotals, allAvg, 'totals-row-all', 'badge-all');
        if (activeCampaigns.length > 0) {
            footerHtml += renderTotalsRow('Total (Active)', activeTotals, activeAvg, 'totals-row-active', 'badge-active');
        }
        if (inactiveCampaigns.length > 0) {
            footerHtml += renderTotalsRow('Total (Inactive)', inactiveTotals, inactiveAvg, 'totals-row-inactive', 'badge-inactive');
        }
    } else if (state.campaignFilter === 'active') {
        // Only show active total when filtered to active
        footerHtml += renderTotalsRow('Total (Active)', activeTotals, activeAvg, 'totals-row-active', 'badge-active');
    } else if (state.campaignFilter === 'inactive') {
        // Only show inactive total when filtered to inactive
        footerHtml += renderTotalsRow('Total (Inactive)', inactiveTotals, inactiveAvg, 'totals-row-inactive', 'badge-inactive');
    }

    tfoot.innerHTML = footerHtml;
}

// Format number with commas for totals display
function formatNumberWithCommas(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// Render single campaign table row
function renderCampaignTableRow(campaign) {
    const isActive = campaign.operation_status === 'ENABLE';
    const statusClass = isActive ? 'active' : 'inactive';
    const statusLabel = isActive ? 'Active' : 'Paused';
    const toggleClass = isActive ? 'on' : '';
    const isSelected = state.selectedCampaigns.includes(campaign.campaign_id);
    const isExpanded = state.expandedCampaigns && state.expandedCampaigns[campaign.campaign_id];

    // Format budget
    const budget = campaign.budget ? `$${parseFloat(campaign.budget).toFixed(2)}` : '-';

    // Smart+ badge
    const smartPlusBadge = campaign.is_smart_performance_campaign
        ? '<span class="smart-badge-small">Smart+</span>'
        : '';

    return `
        <tr class="row-campaign" data-campaign-id="${campaign.campaign_id}">
            <td class="col-checkbox">
                <input type="checkbox"
                       class="campaign-checkbox"
                       data-campaign-id="${campaign.campaign_id}"
                       ${isSelected ? 'checked' : ''}
                       onchange="toggleCampaignSelection('${campaign.campaign_id}')">
            </td>
            <td class="col-toggle">
                <div class="toggle-table ${toggleClass}"
                     data-campaign-id="${campaign.campaign_id}"
                     data-status="${campaign.operation_status}"
                     onclick="toggleCampaignStatus('${campaign.campaign_id}', '${campaign.operation_status}')"
                     title="${isActive ? 'Click to disable' : 'Click to enable'}">
                    <div class="toggle-slider-table"></div>
                </div>
            </td>
            <td class="col-name">
                <div class="name-cell">
                    <button class="expand-btn ${isExpanded ? 'expanded' : ''}"
                            onclick="toggleCampaignExpand('${campaign.campaign_id}')"
                            title="Expand to see ad groups">▶</button>
                    <span class="entity-icon">📢</span>
                    <span class="entity-name">${escapeHtml(campaign.campaign_name)}</span>
                    ${smartPlusBadge}
                </div>
            </td>
            <td class="col-status">
                <span class="status-badge-table ${statusClass}">${statusLabel}</span>
            </td>
            <td class="col-budget" style="text-align: right;">
                <div class="budget-cell" data-campaign-id="${campaign.campaign_id}" data-budget="${campaign.budget || 50}">
                    <span class="budget-display">${budget}</span>
                    <button class="edit-budget-btn" onclick="openInlineBudgetEdit('${campaign.campaign_id}', ${campaign.budget || 50}); event.stopPropagation();" title="Edit Budget">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path>
                        </svg>
                    </button>
                </div>
            </td>
            <td class="col-spend" style="text-align: right;">${formatCurrency(campaign.spend)}</td>
            <td class="col-cpc" style="text-align: right;">${formatCurrency(campaign.cpc)}</td>
            <td class="col-impressions" style="text-align: right;">${formatNumber(campaign.impressions)}</td>
            <td class="col-clicks" style="text-align: right;">${formatNumber(campaign.clicks)}</td>
            <td class="col-ctr" style="text-align: right;">${formatPercent(campaign.ctr)}</td>
            <td class="col-lpclicks" style="text-align: right;" id="lpclicks-cell-${campaign.campaign_id}">${renderLpClicksCell(campaign.campaign_id)}</td>
            <td class="col-lpviews" style="text-align: right;" id="lpviews-cell-${campaign.campaign_id}">${renderLpViewsCell(campaign.campaign_id)}</td>
            <td class="col-lpctr" style="text-align: right;" id="lpctr-cell-${campaign.campaign_id}">${renderLpCtrCell(campaign.campaign_id)}</td>
            <td class="col-conversions" style="text-align: right;">${formatNumber(campaign.conversions)}</td>
            <td class="col-cpr" style="text-align: right;">${formatCurrency(campaign.cost_per_result)}</td>
            <td class="col-results" style="text-align: right;">${formatNumber(campaign.results)}</td>
            <td class="col-actions" style="display:flex;gap:4px;align-items:center;">
                <button class="action-btn-table duplicate-btn"
                        onclick="openDuplicateCampaignModal('${campaign.campaign_id}', '${escapeHtml(campaign.campaign_name).replace(/'/g, "\\'")}'); event.stopPropagation();"
                        title="Duplicate Campaign">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                </button>
                <button class="action-btn-table optimizer-monitor-btn" id="opt-btn-${campaign.campaign_id}"
                        onclick="toggleOptimizerMonitoring('${campaign.campaign_id}', '${escapeHtml(campaign.campaign_name).replace(/'/g, "\\'")}'); event.stopPropagation();"
                        title="Toggle Optimizer Monitoring"
                        style="position:relative;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                </button>
            </td>
        </tr>
    `;
}

// Format currency for display
function formatCurrency(value) {
    const num = parseFloat(value) || 0;
    return '$' + num.toFixed(2);
}

// Format number for display
function formatNumber(value) {
    const num = parseInt(value) || 0;
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
}

// Format percent for display
// TikTok API returns CTR already as a percentage value (0.29 means 0.29%, NOT 29%)
function formatPercent(value) {
    const num = parseFloat(value) || 0;
    return num.toFixed(2) + '%';
}

// ============================================
// REDTRACK LP CTR FUNCTIONS
// ============================================

function renderLpClicksCell(campaignId) {
    const data = state.redtrackLpCtrs[campaignId];
    if (!data || data.lp_clicks === undefined) return '-';
    return `<span style="font-weight:500;">${formatNumber(data.lp_clicks)}</span>`;
}

function renderLpViewsCell(campaignId) {
    const data = state.redtrackLpCtrs[campaignId];
    if (!data || data.lp_views === undefined) return '-';
    return `<span style="font-weight:500;">${formatNumber(data.lp_views)}</span>`;
}

function renderLpCtrCell(campaignId) {
    const rtName = state.redtrackMappings[campaignId];
    const data = state.redtrackLpCtrs[campaignId];

    if (!rtName) {
        return `<button onclick="showRedTrackInput('${campaignId}'); event.stopPropagation();"
                    style="font-size:10px;padding:2px 6px;border:1px dashed #94a3b8;border-radius:4px;background:none;color:#64748b;cursor:pointer;white-space:nowrap;"
                    title="Link RedTrack campaign to show LP CTR">Link RT</button>`;
    }

    if (!data || data.lp_ctr === undefined || data.lp_ctr === null) {
        return `<span style="color:#94a3b8;font-size:11px;" title="${escapeHtml(rtName)}">...</span>`;
    }

    const displayVal = parseFloat(data.lp_ctr) || 0;
    return `<span style="cursor:pointer;font-weight:500;" title="RT: ${escapeHtml(rtName)} (click to edit)"
                onclick="showRedTrackInput('${campaignId}'); event.stopPropagation();">${displayVal.toFixed(2)}%</span>`;
}

function showRedTrackInput(campaignId) {
    const cell = document.getElementById('lpctr-cell-' + campaignId);
    if (!cell) return;

    const currentName = state.redtrackMappings[campaignId] || '';
    cell.innerHTML = `
        <input type="text" value="${escapeHtml(currentName)}"
            placeholder="RT campaign name"
            style="width:100px;font-size:11px;padding:2px 4px;border:1px solid #0ea5e9;border-radius:4px;outline:none;box-sizing:border-box;"
            onkeydown="if(event.key==='Enter'){saveRedTrackMapping('${campaignId}',this.value);} if(event.key==='Escape'){cancelRedTrackInput('${campaignId}');}"
            onblur="setTimeout(()=>cancelRedTrackInput('${campaignId}'),200)"
            autofocus />
        <button onclick="saveRedTrackMapping('${campaignId}', this.previousElementSibling.value); event.stopPropagation();"
            style="font-size:10px;padding:1px 4px;border:none;background:#0ea5e9;color:white;border-radius:3px;cursor:pointer;margin-left:2px;">Go</button>
    `;
    cell.querySelector('input').focus();
}

function cancelRedTrackInput(campaignId) {
    const cell = document.getElementById('lpctr-cell-' + campaignId);
    if (!cell) return;
    cell.innerHTML = renderLpCtrCell(campaignId);
}

async function saveRedTrackMapping(campaignId, rtName) {
    rtName = (rtName || '').trim();
    if (!rtName) return;

    const cell = document.getElementById('lpctr-cell-' + campaignId);
    if (cell) cell.innerHTML = '<span style="color:#94a3b8;font-size:11px;">Saving...</span>';

    const result = await apiRequest('save_redtrack_mapping', {
        campaign_id: campaignId,
        redtrack_campaign_name: rtName,
    });

    if (result.success) {
        state.redtrackMappings[campaignId] = rtName;
        fetchLpCtrForCampaign(campaignId, rtName);
    } else {
        if (cell) cell.innerHTML = renderLpCtrCell(campaignId);
    }
}

async function fetchLpCtrForCampaign(campaignId, rtName) {
    const ctrCell = document.getElementById('lpctr-cell-' + campaignId);
    const clicksCell = document.getElementById('lpclicks-cell-' + campaignId);
    const viewsCell = document.getElementById('lpviews-cell-' + campaignId);
    if (ctrCell) ctrCell.innerHTML = '<span style="color:#94a3b8;font-size:11px;">Loading...</span>';
    if (clicksCell) clicksCell.innerHTML = '<span style="color:#94a3b8;font-size:11px;">...</span>';
    if (viewsCell) viewsCell.innerHTML = '<span style="color:#94a3b8;font-size:11px;">...</span>';

    const result = await apiRequest('fetch_redtrack_lpctr', {
        redtrack_campaign_name: rtName,
    });

    if (result.success) {
        state.redtrackLpCtrs[campaignId] = {
            lp_ctr: parseFloat(result.lp_ctr) || 0,
            lp_clicks: parseInt(result.lp_clicks) || 0,
            lp_views: parseInt(result.lp_views) || 0,
        };
    } else {
        state.redtrackLpCtrs[campaignId] = { lp_ctr: 0, lp_clicks: 0, lp_views: 0 };
    }

    if (ctrCell) ctrCell.innerHTML = renderLpCtrCell(campaignId);
    if (clicksCell) clicksCell.innerHTML = renderLpClicksCell(campaignId);
    if (viewsCell) viewsCell.innerHTML = renderLpViewsCell(campaignId);
}

async function loadRedTrackMappings() {
    const result = await apiRequest('get_redtrack_mappings');
    if (result.success && result.mappings) {
        state.redtrackMappings = result.mappings;

        // Only fetch LP CTR for campaigns with their own individual RT mapping
        // (campaigns using account-level RT are handled by fetchAccountRtMetrics)
        const accountRt = state.accountRtCampaignName || '';
        const uniqueRtNames = new Set();
        const toFetch = [];

        for (const [campaignId, rtName] of Object.entries(result.mappings)) {
            // Skip if this mapping is the same as the account-level RT (already fetched once)
            if (accountRt && rtName === accountRt) {
                // Use the account-level metrics already fetched
                if (state.accountRtMetrics) {
                    state.redtrackLpCtrs[campaignId] = {
                        lp_ctr: parseFloat(state.accountRtMetrics.lp_ctr) || 0,
                        lp_clicks: parseInt(state.accountRtMetrics.lp_clicks) || 0,
                        lp_views: parseInt(state.accountRtMetrics.lp_views) || 0,
                    };
                }
                continue;
            }
            // Deduplicate: only fetch once per unique RT campaign name
            if (!uniqueRtNames.has(rtName)) {
                uniqueRtNames.add(rtName);
                toFetch.push([campaignId, rtName]);
            }
        }

        const fetches = toFetch.map(([campaignId, rtName]) =>
            fetchLpCtrForCampaign(campaignId, rtName)
        );
        await Promise.all(fetches);
    }
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// ACCOUNT-LEVEL REDTRACK CAMPAIGN (Campaigns View Banner)
// ============================================

async function loadCampaignsAccountRt() {
    const banner = document.getElementById('account-rt-banner');
    if (!banner) return;

    const advId = state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || '';
    if (!advId) { banner.style.display = 'none'; return; }

    banner.style.display = 'flex';
    state.accountRtCampaignName = '';

    try {
        const response = await fetch(`api-optimizer.php?action=get_account_rt_campaign&advertiser_id=${encodeURIComponent(advId)}`);
        const result = await response.json();
        const input = document.getElementById('campaigns-account-rt-input');
        if (input && result.success && result.redtrack_campaign_name) {
            input.value = result.redtrack_campaign_name;
            state.accountRtCampaignName = result.redtrack_campaign_name;
            // Fetch RT metrics once for the account-level campaign
            fetchAccountRtMetrics(result.redtrack_campaign_name);
        } else if (input) {
            input.value = '';
            // Hide metrics row if no account RT campaign
            const metricsRow = document.getElementById('account-rt-metrics');
            if (metricsRow) metricsRow.style.display = 'none';
        }
    } catch (e) {
        console.error('Error loading account RT campaign:', e);
    }
}

async function fetchAccountRtMetrics(rtName) {
    const metricsRow = document.getElementById('account-rt-metrics');
    if (metricsRow) metricsRow.innerHTML = '<span style="color:#94a3b8;font-size:12px;">Loading RedTrack data...</span>';
    if (metricsRow) metricsRow.style.display = 'flex';

    try {
        const result = await apiRequest('fetch_redtrack_lpctr', { redtrack_campaign_name: rtName });
        if (result.success && metricsRow) {
            const lpCtr = parseFloat(result.lp_ctr) || 0;
            const lpCtrFmt = lpCtr.toFixed(2) + '%';
            const rev = parseFloat(result.revenue) || 0;
            const cost = parseFloat(result.cost) || 0;
            const profit = parseFloat(result.profit) || 0;
            const convs = parseInt(result.conversions) || 0;
            const lpClicks = parseInt(result.lp_clicks) || 0;
            const lpViews = parseInt(result.lp_views) || 0;
            const profitColor = profit >= 0 ? '#16a34a' : '#dc2626';

            // Store for LP CTR column display
            state.accountRtMetrics = result;

            metricsRow.innerHTML = `
                <span style="font-size:12px;color:#475569;"><b>LP CTR:</b> ${lpCtrFmt}</span>
                <span style="font-size:12px;color:#475569;"><b>LP Clicks:</b> ${lpClicks}</span>
                <span style="font-size:12px;color:#475569;"><b>LP Views:</b> ${lpViews}</span>
                <span style="font-size:12px;color:#475569;"><b>Conversions:</b> ${convs}</span>
                <span style="font-size:12px;color:#475569;"><b>Revenue:</b> $${rev.toFixed(2)}</span>
                <span style="font-size:12px;color:#475569;"><b>Cost:</b> $${cost.toFixed(2)}</span>
                <span style="font-size:12px;color:${profitColor};font-weight:700;"><b>Profit:</b> $${profit.toFixed(2)}</span>
            `;

            // Update all campaign LP CTR cells with account-level data
            updateAllLpCtrFromAccount({ lp_ctr: lpCtr, lp_clicks: lpClicks, lp_views: lpViews }, rtName);
        } else if (metricsRow) {
            metricsRow.innerHTML = '<span style="color:#94a3b8;font-size:12px;">No RedTrack data found for this campaign</span>';
        }
    } catch (e) {
        if (metricsRow) metricsRow.innerHTML = '<span style="color:#dc2626;font-size:12px;">Error fetching RedTrack data</span>';
    }
}

function updateAllLpCtrFromAccount(data, rtName) {
    // When account RT is set, apply the same LP CTR/Clicks/Views to all campaigns that don't have their own mapping
    document.querySelectorAll('[id^="lpctr-cell-"]').forEach(cell => {
        const campaignId = cell.id.replace('lpctr-cell-', '');
        // Only update if campaign doesn't have its own individual RT mapping
        if (!state.redtrackMappings[campaignId]) {
            state.redtrackLpCtrs[campaignId] = data;
            const displayVal = parseFloat(data.lp_ctr) || 0;
            cell.innerHTML = `<span style="font-weight:500;color:#b45309;" title="Account RT: ${escapeHtml(rtName)}">${displayVal.toFixed(2)}%</span>`;
            // Update LP Clicks and LP Views cells too
            const clicksCell = document.getElementById('lpclicks-cell-' + campaignId);
            const viewsCell = document.getElementById('lpviews-cell-' + campaignId);
            if (clicksCell) clicksCell.innerHTML = `<span style="font-weight:500;color:#b45309;">${formatNumber(data.lp_clicks)}</span>`;
            if (viewsCell) viewsCell.innerHTML = `<span style="font-weight:500;color:#b45309;">${formatNumber(data.lp_views)}</span>`;
        }
    });
}

async function saveCampaignsAccountRt() {
    const input = document.getElementById('campaigns-account-rt-input');
    const rtName = input ? input.value.trim() : '';
    const advId = state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || '';

    if (!rtName) {
        showToast('Enter a RedTrack campaign name', 'error');
        return;
    }

    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({ action: 'set_account_rt_campaign', advertiser_id: advId, redtrack_campaign_name: rtName })
        });
        const result = await response.json();
        if (result.success) {
            showToast('Account RedTrack campaign saved', 'success');
            state.accountRtCampaignName = rtName;
            // Fetch fresh metrics for the new RT campaign
            fetchAccountRtMetrics(rtName);
        } else {
            showToast(result.message || 'Failed to save', 'error');
        }
    } catch (e) {
        showToast('Error saving account RT campaign', 'error');
    }
}

async function clearCampaignsAccountRt() {
    const advId = state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || '';

    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({ action: 'set_account_rt_campaign', advertiser_id: advId, redtrack_campaign_name: '' })
        });
        const result = await response.json();
        if (result.success) {
            const input = document.getElementById('campaigns-account-rt-input');
            if (input) input.value = '';
            state.accountRtCampaignName = '';
            state.accountRtMetrics = null;
            const metricsRow = document.getElementById('account-rt-metrics');
            if (metricsRow) metricsRow.style.display = 'none';
            showToast('Account RedTrack campaign cleared', 'success');
            // Re-render LP CTR, LP Clicks, LP Views cells (will show "Link RT" buttons again)
            document.querySelectorAll('[id^="lpctr-cell-"]').forEach(cell => {
                const campaignId = cell.id.replace('lpctr-cell-', '');
                if (!state.redtrackMappings[campaignId]) {
                    delete state.redtrackLpCtrs[campaignId];
                    cell.innerHTML = renderLpCtrCell(campaignId);
                    const clicksCell = document.getElementById('lpclicks-cell-' + campaignId);
                    const viewsCell = document.getElementById('lpviews-cell-' + campaignId);
                    if (clicksCell) clicksCell.innerHTML = renderLpClicksCell(campaignId);
                    if (viewsCell) viewsCell.innerHTML = renderLpViewsCell(campaignId);
                }
            });
        }
    } catch (e) {
        showToast('Error clearing account RT campaign', 'error');
    }
}

// ============================================
// OPTIMIZER MONITORING (View Campaigns integration)
// ============================================

/**
 * Loads monitoring status for all visible campaigns and updates button styles.
 * Called after campaign rows are rendered.
 */
async function loadOptimizerMonitoringStatus() {
    try {
        const advId = state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || '';
        const response = await fetch(`api-optimizer.php?action=get_monitoring_status&advertiser_id=${encodeURIComponent(advId)}`);
        const result = await response.json();

        if (!result.success) return;

        const statusMap = result.data;

        // Update all optimizer monitor buttons
        document.querySelectorAll('.optimizer-monitor-btn').forEach(btn => {
            const campaignId = btn.id.replace('opt-btn-', '');
            const status = statusMap[campaignId];

            // Reset classes and data
            btn.classList.remove('monitoring', 'paused-by-opt');
            btn.removeAttribute('data-rule-group');

            if (status && status.monitoring) {
                btn.setAttribute('data-rule-group', status.rule_group || 'home_insurance');
                if (status.redtrack_campaign_name) {
                    btn.setAttribute('data-redtrack-campaign', status.redtrack_campaign_name);
                }
                const groupLabel = status.rule_group === 'medicare' ? 'Medicare' : 'Home Insurance';
                const rtLabel = status.redtrack_campaign_name ? ` | RT: ${status.redtrack_campaign_name}` : '';
                if (status.paused_by_optimizer) {
                    btn.classList.add('paused-by-opt');
                    btn.title = `Paused by Optimizer [${groupLabel}${rtLabel}] (click to remove)`;
                } else {
                    btn.classList.add('monitoring');
                    btn.title = `Monitoring [${groupLabel}${rtLabel}] (click to remove)`;
                }
            } else {
                btn.title = 'Click to enable Optimizer Monitoring';
            }
        });
    } catch (e) {
        console.error('Error loading optimizer monitoring status:', e);
    }
}

/**
 * Toggles optimizer monitoring for a campaign.
 * If not monitored → shows rule group picker.
 * If monitored → removes from monitoring.
 */
async function toggleOptimizerMonitoring(campaignId, campaignName) {
    const btn = document.getElementById(`opt-btn-${campaignId}`);
    if (!btn) return;

    const isMonitoring = btn.classList.contains('monitoring') || btn.classList.contains('paused-by-opt');

    if (isMonitoring) {
        // Remove from monitoring
        if (!confirm(`Remove "${campaignName}" from optimizer monitoring?`)) return;
        await sendToggleMonitoring(campaignId, campaignName, null, btn);
    } else {
        // Show rule group picker dropdown
        showRuleGroupPicker(btn, campaignId, campaignName);
    }
}

/**
 * Shows a small dropdown to pick rule group (Home Insurance / Medicare) + RedTrack campaign name
 */
async function showRuleGroupPicker(btn, campaignId, campaignName) {
    // Remove any existing picker
    const existing = document.getElementById('opt-rule-group-picker');
    if (existing) existing.remove();

    // Fetch account-level RT campaign default
    let accountRtCampaign = '';
    try {
        const advId = state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || '';
        const resp = await fetch(`api-optimizer.php?action=get_account_rt_campaign&advertiser_id=${advId}`);
        const data = await resp.json();
        if (data.success && data.redtrack_campaign_name) {
            accountRtCampaign = data.redtrack_campaign_name;
        }
    } catch (e) {}

    const rect = btn.getBoundingClientRect();

    const picker = document.createElement('div');
    picker.id = 'opt-rule-group-picker';
    picker.style.cssText = `
        position: fixed; top: ${rect.bottom + 4}px; left: ${rect.left - 60}px; z-index: 99999;
        background: white; border: 1px solid #e2e8f0; border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.15); min-width: 220px; overflow: hidden;
    `;

    const rtPlaceholder = accountRtCampaign
        ? `Account default: ${accountRtCampaign}`
        : 'Enter RedTrack campaign name';
    const rtHint = accountRtCampaign
        ? `<div style="font-size:10px;color:#16a34a;margin-top:3px;">Account default will be used if left empty</div>`
        : `<div style="font-size:10px;color:#94a3b8;margin-top:3px;">Set account default in Optimizer &gt; Monitored Campaigns</div>`;

    picker.innerHTML = `
        <div style="padding:8px 12px;font-size:11px;font-weight:700;color:#64748b;border-bottom:1px solid #f1f5f9;text-transform:uppercase;">Select Rule Group</div>
        <div class="opt-picker-option" data-group="home_insurance" style="padding:10px 12px;cursor:pointer;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;transition:background 0.15s;">
            <span style="width:8px;height:8px;border-radius:50%;background:#0369a1;display:inline-block;flex-shrink:0;"></span>
            Home Insurance
            <span style="font-size:11px;color:#94a3b8;font-weight:400;">CPC &gt; $3, CTR &lt; 0.7%</span>
        </div>
        <div class="opt-picker-option" data-group="medicare" style="padding:10px 12px;cursor:pointer;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;transition:background 0.15s;border-top:1px solid #f1f5f9;">
            <span style="width:8px;height:8px;border-radius:50%;background:#7c3aed;display:inline-block;flex-shrink:0;"></span>
            Medicare
            <span style="font-size:11px;color:#94a3b8;font-weight:400;">CPC &gt; $0.7, CTR &gt; 1%</span>
        </div>
        <div style="padding:8px 12px;border-top:1px solid #e2e8f0;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">RedTrack Campaign Name</label>
            <input type="text" id="opt-redtrack-campaign-input" placeholder="${rtPlaceholder}"
                style="width:100%;padding:6px 8px;font-size:12px;border:1px solid #e2e8f0;border-radius:6px;box-sizing:border-box;outline:none;transition:border-color 0.2s;"
                onfocus="this.style.borderColor='#0369a1'" onblur="this.style.borderColor='#e2e8f0'" />
            ${rtHint}
        </div>
    `;

    document.body.appendChild(picker);

    // Handle option clicks
    picker.querySelectorAll('.opt-picker-option').forEach(opt => {
        opt.addEventListener('mouseenter', () => opt.style.background = '#f8fafc');
        opt.addEventListener('mouseleave', () => opt.style.background = 'white');
        opt.addEventListener('click', async () => {
            const ruleGroup = opt.dataset.group;
            const redtrackInput = document.getElementById('opt-redtrack-campaign-input');
            const redtrackCampaignName = redtrackInput ? redtrackInput.value.trim() : '';
            picker.remove();
            await sendToggleMonitoring(campaignId, campaignName, ruleGroup, btn, redtrackCampaignName);
        });
    });

    // Close picker on outside click
    setTimeout(() => {
        const closeHandler = (e) => {
            if (!picker.contains(e.target) && e.target !== btn) {
                picker.remove();
                document.removeEventListener('click', closeHandler);
            }
        };
        document.addEventListener('click', closeHandler);
    }, 10);
}

/**
 * Sends the toggle_monitoring API call
 */
async function sendToggleMonitoring(campaignId, campaignName, ruleGroup, btn, redtrackCampaignName) {
    btn.disabled = true;
    btn.style.opacity = '0.5';

    const isMonitoring = btn.classList.contains('monitoring') || btn.classList.contains('paused-by-opt');

    try {
        const body = {
            action: 'toggle_monitoring',
            campaign_id: campaignId,
            campaign_name: campaignName,
            advertiser_id: state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || ''
        };
        if (ruleGroup) body.rule_group = ruleGroup;
        if (redtrackCampaignName) body.redtrack_campaign_name = redtrackCampaignName;

        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify(body)
        });
        const result = await response.json();

        if (result.success) {
            showToast(result.message || (isMonitoring ? 'Removed from monitoring' : 'Added to monitoring'), 'success');
            await loadOptimizerMonitoringStatus();
        } else {
            showToast(result.message || 'Failed to toggle monitoring', 'error');
        }
    } catch (e) {
        showToast('Error toggling optimizer monitoring', 'error');
        console.error('Error toggling optimizer monitoring:', e);
    } finally {
        btn.disabled = false;
        btn.style.opacity = '1';
    }
}

// Toggle campaign expansion to show ad groups
async function toggleCampaignExpand(campaignId) {
    // Initialize expansion state if needed
    if (!state.expandedCampaigns) state.expandedCampaigns = {};

    const isCurrentlyExpanded = state.expandedCampaigns[campaignId];
    const expandBtn = document.querySelector(`tr[data-campaign-id="${campaignId}"] .expand-btn`);
    const campaignRow = document.querySelector(`tr[data-campaign-id="${campaignId}"]`);

    if (isCurrentlyExpanded) {
        // Collapse: Remove all ad group and ad rows for this campaign
        state.expandedCampaigns[campaignId] = false;
        if (expandBtn) expandBtn.classList.remove('expanded');

        // Remove child rows
        document.querySelectorAll(`tr[data-parent-campaign="${campaignId}"]`).forEach(row => row.remove());
        document.querySelectorAll(`tr[data-grandparent-campaign="${campaignId}"]`).forEach(row => row.remove());
    } else {
        // Expand: Fetch and show ad groups
        state.expandedCampaigns[campaignId] = true;
        if (expandBtn) expandBtn.classList.add('expanded');

        // Show loading row
        const loadingRow = document.createElement('tr');
        loadingRow.className = 'loading-row';
        loadingRow.setAttribute('data-parent-campaign', campaignId);
        loadingRow.innerHTML = `<td colspan="14"><span class="mini-spinner"></span>Loading ad groups...</td>`;
        campaignRow.after(loadingRow);

        try {
            // Pass date range for metrics
            const dateRange = getCurrentDateRange();
            const result = await apiRequest('get_adgroups_for_campaign', {
                campaign_id: campaignId,
                ...dateRange
            });

            // Remove loading row
            loadingRow.remove();

            if (result.success && result.adgroups && result.adgroups.length > 0) {
                // Store ad groups in state for later use
                if (!state.adgroupsData) state.adgroupsData = {};
                state.adgroupsData[campaignId] = result.adgroups;

                // Insert ad group rows after the campaign row
                let insertAfter = campaignRow;
                result.adgroups.forEach(adgroup => {
                    const adgroupRow = createAdgroupTableRow(adgroup, campaignId);
                    insertAfter.after(adgroupRow);
                    insertAfter = adgroupRow;
                });
            } else {
                // Show "no ad groups" message
                const emptyRow = document.createElement('tr');
                emptyRow.className = 'row-adgroup';
                emptyRow.setAttribute('data-parent-campaign', campaignId);
                emptyRow.innerHTML = `<td colspan="14" class="indent-1" style="color: #94a3b8; font-style: italic;">No ad groups found</td>`;
                campaignRow.after(emptyRow);
            }
        } catch (error) {
            loadingRow.remove();
            console.error('Error loading ad groups:', error);
            showToast('Failed to load ad groups: ' + error.message, 'error');
        }
    }
}

// Create ad group table row element
function createAdgroupTableRow(adgroup, parentCampaignId) {
    const isActive = adgroup.operation_status === 'ENABLE';
    const statusClass = isActive ? 'active' : 'inactive';
    const statusLabel = isActive ? 'Active' : 'Paused';
    const toggleClass = isActive ? 'on' : '';

    if (!state.expandedAdgroups) state.expandedAdgroups = {};
    const isExpanded = state.expandedAdgroups[adgroup.adgroup_id];

    const budget = adgroup.budget ? `$${parseFloat(adgroup.budget).toFixed(2)}` : '-';

    const row = document.createElement('tr');
    row.className = 'row-adgroup';
    row.setAttribute('data-adgroup-id', adgroup.adgroup_id);
    row.setAttribute('data-parent-campaign', parentCampaignId);

    row.innerHTML = `
        <td class="col-checkbox"></td>
        <td class="col-toggle">
            <div class="toggle-table ${toggleClass}"
                 data-adgroup-id="${adgroup.adgroup_id}"
                 data-status="${adgroup.operation_status}"
                 onclick="toggleAdgroupStatus('${adgroup.adgroup_id}', '${adgroup.operation_status}')"
                 title="${isActive ? 'Click to disable' : 'Click to enable'}">
                <div class="toggle-slider-table"></div>
            </div>
        </td>
        <td class="col-name indent-1">
            <div class="name-cell">
                <button class="expand-btn ${isExpanded ? 'expanded' : ''}"
                        onclick="toggleAdgroupExpand('${adgroup.adgroup_id}', '${parentCampaignId}')"
                        title="Expand to see ads">▶</button>
                <span class="entity-icon">📦</span>
                <span class="entity-name">${escapeHtml(adgroup.adgroup_name)}</span>
            </div>
        </td>
        <td class="col-status">
            <span class="status-badge-table ${statusClass}">${statusLabel}</span>
        </td>
        <td class="col-budget" style="text-align: right;">${budget}</td>
        <td class="col-spend" style="text-align: right;">${formatCurrency(adgroup.spend)}</td>
        <td class="col-cpc" style="text-align: right;">${formatCurrency(adgroup.cpc)}</td>
        <td class="col-impressions" style="text-align: right;">${formatNumber(adgroup.impressions)}</td>
        <td class="col-clicks" style="text-align: right;">${formatNumber(adgroup.clicks)}</td>
        <td class="col-ctr" style="text-align: right;">${formatPercent(adgroup.ctr)}</td>
        <td class="col-lpclicks" style="text-align: right;">-</td>
        <td class="col-lpviews" style="text-align: right;">-</td>
        <td class="col-lpctr" style="text-align: right;">-</td>
        <td class="col-conversions" style="text-align: right;">${formatNumber(adgroup.conversions)}</td>
        <td class="col-cpr" style="text-align: right;">${formatCurrency(adgroup.cost_per_result)}</td>
        <td class="col-results" style="text-align: right;">${formatNumber(adgroup.results)}</td>
        <td class="col-actions"></td>
    `;

    return row;
}

// Toggle ad group expansion to show ads
async function toggleAdgroupExpand(adgroupId, parentCampaignId) {
    if (!state.expandedAdgroups) state.expandedAdgroups = {};

    const isCurrentlyExpanded = state.expandedAdgroups[adgroupId];
    const expandBtn = document.querySelector(`tr[data-adgroup-id="${adgroupId}"] .expand-btn`);
    const adgroupRow = document.querySelector(`tr[data-adgroup-id="${adgroupId}"]`);

    if (isCurrentlyExpanded) {
        // Collapse: Remove all ad rows for this ad group
        state.expandedAdgroups[adgroupId] = false;
        if (expandBtn) expandBtn.classList.remove('expanded');

        // Remove child rows
        document.querySelectorAll(`tr[data-parent-adgroup="${adgroupId}"]`).forEach(row => row.remove());
    } else {
        // Expand: Fetch and show ads
        state.expandedAdgroups[adgroupId] = true;
        if (expandBtn) expandBtn.classList.add('expanded');

        // Show loading row
        const loadingRow = document.createElement('tr');
        loadingRow.className = 'loading-row';
        loadingRow.setAttribute('data-parent-adgroup', adgroupId);
        loadingRow.setAttribute('data-grandparent-campaign', parentCampaignId);
        loadingRow.innerHTML = `<td colspan="14"><span class="mini-spinner"></span>Loading ads...</td>`;
        adgroupRow.after(loadingRow);

        try {
            // Pass date range for metrics
            const dateRange = getCurrentDateRange();
            const result = await apiRequest('get_ads_for_adgroup', {
                adgroup_id: adgroupId,
                ...dateRange
            });

            // Remove loading row
            loadingRow.remove();

            if (result.success && result.ads && result.ads.length > 0) {
                // Insert ad rows after the ad group row
                let insertAfter = adgroupRow;
                result.ads.forEach(ad => {
                    const adRow = createAdTableRow(ad, adgroupId, parentCampaignId);
                    insertAfter.after(adRow);
                    insertAfter = adRow;
                });
            } else {
                // Show "no ads" message
                const emptyRow = document.createElement('tr');
                emptyRow.className = 'row-ad';
                emptyRow.setAttribute('data-parent-adgroup', adgroupId);
                emptyRow.setAttribute('data-grandparent-campaign', parentCampaignId);
                emptyRow.innerHTML = `<td colspan="14" class="indent-2" style="color: #94a3b8; font-style: italic;">No ads found</td>`;
                adgroupRow.after(emptyRow);
            }
        } catch (error) {
            loadingRow.remove();
            console.error('Error loading ads:', error);
            showToast('Failed to load ads: ' + error.message, 'error');
        }
    }
}

// Create ad table row element
function createAdTableRow(ad, parentAdgroupId, parentCampaignId) {
    const isActive = ad.operation_status === 'ENABLE';
    const toggleClass = isActive ? 'on' : '';

    // Determine delivery status from primary_status
    let deliveryLabel, deliveryClass;
    const ps = ad.primary_status || '';

    if (ps === 'STATUS_DELIVERY_OK') {
        deliveryLabel = 'Delivering';
        deliveryClass = 'delivering';
    } else if (ps === 'STATUS_AUDIT_DENY') {
        deliveryLabel = 'Rejected';
        deliveryClass = 'rejected';
    } else if (ps === 'STATUS_PENDING_REVIEW') {
        deliveryLabel = 'Under Review';
        deliveryClass = 'under-review';
    } else if (ps === 'STATUS_DISABLE') {
        deliveryLabel = 'Disabled';
        deliveryClass = 'inactive';
    } else if (ps.includes('BUDGET') || ps.includes('BALANCE')) {
        deliveryLabel = 'No Budget';
        deliveryClass = 'no-budget';
    } else if (ps === 'STATUS_DELETE') {
        deliveryLabel = 'Deleted';
        deliveryClass = 'inactive';
    } else if (isActive) {
        deliveryLabel = 'Active';
        deliveryClass = 'active';
    } else {
        deliveryLabel = 'Paused';
        deliveryClass = 'inactive';
    }

    // Use smart_plus_ad_id if available, fallback to ad_id
    const effectiveAdId = ad.smart_plus_ad_id || ad.ad_id;
    const adAdvertiserId = ad.advertiser_id || state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || '';

    // Appeal button (only for rejected ads)
    const appealBtnHtml = (ps === 'STATUS_AUDIT_DENY')
        ? `<button class="btn-appeal" onclick="openAppealModal('${effectiveAdId}', '${escapeHtml(ad.ad_name).replace(/'/g, "\\'")}', '${adAdvertiserId}')" title="Appeal this ad">Appeal</button>`
        : '';

    const row = document.createElement('tr');
    row.className = 'row-ad';
    row.setAttribute('data-ad-id', effectiveAdId);
    row.setAttribute('data-advertiser-id', adAdvertiserId);
    row.setAttribute('data-parent-adgroup', parentAdgroupId);
    row.setAttribute('data-grandparent-campaign', parentCampaignId);

    row.innerHTML = `
        <td class="col-checkbox"></td>
        <td class="col-toggle">
            <div class="toggle-table ${toggleClass}"
                 data-ad-id="${ad.ad_id}"
                 data-status="${ad.operation_status}"
                 onclick="toggleAdStatus('${ad.ad_id}', '${ad.operation_status}')"
                 title="${isActive ? 'Click to disable' : 'Click to enable'}">
                <div class="toggle-slider-table"></div>
            </div>
        </td>
        <td class="col-name indent-2">
            <div class="name-cell">
                <span class="entity-icon">🎬</span>
                <span class="entity-name">${escapeHtml(ad.ad_name)}</span>
            </div>
        </td>
        <td class="col-status">
            <span class="ad-delivery-badge ${deliveryClass}">${deliveryLabel}</span>
        </td>
        <td class="col-budget" style="text-align: right;">-</td>
        <td class="col-spend" style="text-align: right;">${formatCurrency(ad.spend)}</td>
        <td class="col-cpc" style="text-align: right;">${formatCurrency(ad.cpc)}</td>
        <td class="col-impressions" style="text-align: right;">${formatNumber(ad.impressions)}</td>
        <td class="col-clicks" style="text-align: right;">${formatNumber(ad.clicks)}</td>
        <td class="col-ctr" style="text-align: right;">${formatPercent(ad.ctr)}</td>
        <td class="col-lpclicks" style="text-align: right;">-</td>
        <td class="col-lpviews" style="text-align: right;">-</td>
        <td class="col-lpctr" style="text-align: right;">-</td>
        <td class="col-conversions" style="text-align: right;">${formatNumber(ad.conversions)}</td>
        <td class="col-cpr" style="text-align: right;">${formatCurrency(ad.cost_per_result)}</td>
        <td class="col-results" style="text-align: right;">${formatNumber(ad.results)}</td>
        <td class="col-actions">${appealBtnHtml}</td>
    `;

    return row;
}

// Toggle ad group status (placeholder - implement if needed)
async function toggleAdgroupStatus(adgroupId, currentStatus) {
    showToast('Ad group status toggle coming soon', 'info');
}

// Toggle ad status (placeholder - implement if needed)
async function toggleAdStatus(adId, currentStatus) {
    showToast('Ad status toggle coming soon', 'info');
}

// Open the appeal modal for a rejected ad
function openAppealModal(adId, adName, advertiserId) {
    let modal = document.getElementById('appeal-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'appeal-modal';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="appeal-modal-content">
                <div class="appeal-modal-header">
                    <h3>Appeal Rejected Ad</h3>
                    <button class="appeal-modal-close" onclick="closeAppealModal()">&times;</button>
                </div>
                <div class="appeal-modal-body">
                    <p class="appeal-ad-name" id="appeal-ad-name"></p>
                    <p class="appeal-ad-details" id="appeal-ad-details" style="font-size:11px;color:#94a3b8;margin-top:-8px;margin-bottom:12px;"></p>
                    <label for="appeal-reason-input">Appeal Reason (required):</label>
                    <textarea id="appeal-reason-input" rows="5" maxlength="2000"
                              placeholder="Explain why this ad should be approved. Be specific about compliance with TikTok policies..."></textarea>
                    <div class="appeal-char-count"><span id="appeal-char-count">0</span>/2000</div>
                </div>
                <div class="appeal-modal-footer">
                    <button class="btn-secondary" onclick="closeAppealModal()">Cancel</button>
                    <button class="btn-primary" id="appeal-submit-btn" onclick="submitAppeal()">Submit Appeal</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        document.getElementById('appeal-reason-input').addEventListener('input', function() {
            document.getElementById('appeal-char-count').textContent = this.value.length;
        });
    }

    modal.dataset.adId = adId;
    modal.dataset.advertiserId = advertiserId || state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || '';
    document.getElementById('appeal-ad-name').textContent = 'Ad: ' + adName;
    document.getElementById('appeal-ad-details').textContent = 'Ad ID: ' + adId + ' | Advertiser: ' + modal.dataset.advertiserId;
    const defaultReason = 'Similar ads passed the review';
    document.getElementById('appeal-reason-input').value = defaultReason;
    document.getElementById('appeal-char-count').textContent = defaultReason.length;
    document.getElementById('appeal-submit-btn').disabled = false;
    document.getElementById('appeal-submit-btn').textContent = 'Submit Appeal';
    modal.style.display = 'flex';
}

// Close the appeal modal
function closeAppealModal() {
    const modal = document.getElementById('appeal-modal');
    if (modal) modal.style.display = 'none';
}

// Submit the appeal to TikTok
async function submitAppeal() {
    const modal = document.getElementById('appeal-modal');
    const adId = modal.dataset.adId;
    const advertiserId = modal.dataset.advertiserId;
    const reason = document.getElementById('appeal-reason-input').value.trim();

    if (!reason) {
        showToast('Please enter an appeal reason', 'warning');
        return;
    }

    if (reason.length < 10) {
        showToast('Appeal reason must be at least 10 characters', 'warning');
        return;
    }

    const submitBtn = document.getElementById('appeal-submit-btn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    try {
        const result = await apiRequest('appeal_ad', {
            ad_id: adId,
            appeal_reason: reason,
            _advertiser_id: advertiserId
        });

        if (result.success) {
            showToast('Appeal submitted! TikTok will review within 24 hours.', 'success');
            closeAppealModal();

            // Update the ad row status badge to "Appeal Pending"
            const adRow = document.querySelector(`tr[data-ad-id="${adId}"]`);
            if (adRow) {
                const badge = adRow.querySelector('.ad-delivery-badge');
                if (badge) {
                    badge.className = 'ad-delivery-badge under-review';
                    badge.textContent = 'Appeal Pending';
                }
                const appealBtn = adRow.querySelector('.btn-appeal');
                if (appealBtn) appealBtn.style.display = 'none';
            }

            // If showing rejected ads panel, remove the appealed ad and re-render
            if (state.showingRejectedAds) {
                state.rejectedAds = state.rejectedAds.filter(a =>
                    (a.smart_plus_ad_id || a.ad_id) !== adId
                );
                state.rejectedAdsCount = Math.max(0, state.rejectedAdsCount - 1);
                updateRejectedAdsCount();
                renderRejectedAdsList();
            }
        } else {
            showToast('Appeal failed: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (err) {
        showToast('Appeal failed: ' + err.message, 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Appeal';
    }
}

// ============================================
// REJECTED ADS VIEW
// ============================================

// Show rejected ads panel (single-account mode)
async function showRejectedAds() {
    state.showingRejectedAds = true;

    // Update filter button active states
    document.querySelectorAll('.campaign-filter-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.filter === 'rejected') btn.classList.add('active');
    });

    // Hide campaign sections, show rejected ads panel
    const singleCampaigns = document.getElementById('single-account-campaigns');
    const multiContainer = document.getElementById('multi-account-campaigns-container');
    const rejectedPanel = document.getElementById('rejected-ads-panel');
    const bulkBar = document.getElementById('bulk-actions-bar');
    const dateFilter = document.querySelector('.date-range-filter-container');
    const searchBar = document.querySelector('.campaign-search-container');

    if (singleCampaigns) singleCampaigns.style.display = 'none';
    if (multiContainer) multiContainer.style.display = 'none';
    if (bulkBar) bulkBar.style.display = 'none';
    if (dateFilter) dateFilter.style.display = 'none';
    if (searchBar) searchBar.style.display = 'none';
    if (rejectedPanel) rejectedPanel.style.display = 'block';

    const loadingEl = document.getElementById('rejected-ads-loading');
    const emptyEl = document.getElementById('rejected-ads-empty');
    const listEl = document.getElementById('rejected-ads-list');

    // If not loaded yet, fetch from API
    if (!state.rejectedAdsLoaded) {
        if (loadingEl) loadingEl.style.display = 'block';
        if (emptyEl) emptyEl.style.display = 'none';
        if (listEl) listEl.innerHTML = '';

        try {
            const result = await apiRequest('get_rejected_ads');
            if (result.success) {
                state.rejectedAds = result.ads || [];
                state.rejectedAdsCount = result.count || state.rejectedAds.length;
                state.rejectedAdsLoaded = true;
                updateRejectedAdsCount();
            }
        } catch (err) {
            showToast('Failed to load rejected ads: ' + err.message, 'error');
        }
        if (loadingEl) loadingEl.style.display = 'none';
    }

    renderRejectedAdsList();
}

// Hide rejected ads panel and go back to campaigns
function hideRejectedAds() {
    state.showingRejectedAds = false;

    const singleCampaigns = document.getElementById('single-account-campaigns');
    const multiContainer = document.getElementById('multi-account-campaigns-container');
    const rejectedPanel = document.getElementById('rejected-ads-panel');
    const bulkBar = document.getElementById('bulk-actions-bar');
    const dateFilter = document.querySelector('.date-range-filter-container');
    const searchBar = document.querySelector('.campaign-search-container');

    if (rejectedPanel) rejectedPanel.style.display = 'none';
    if (bulkBar) bulkBar.style.display = '';
    if (dateFilter) dateFilter.style.display = '';
    if (searchBar) searchBar.style.display = '';

    // Restore the correct view (single or multi)
    if (window.shellState && window.shellState.multiAccountMode) {
        if (multiContainer) multiContainer.style.display = '';
    } else {
        if (singleCampaigns) singleCampaigns.style.display = '';
    }

    // Re-activate the previous filter
    filterCampaignsByStatus(state.campaignFilter || 'all');
}

// Render the rejected ads list grouped by campaign
function renderRejectedAdsList() {
    const listEl = document.getElementById('rejected-ads-list');
    const emptyEl = document.getElementById('rejected-ads-empty');

    if (!listEl) return;

    if (state.rejectedAds.length === 0) {
        if (emptyEl) emptyEl.style.display = 'block';
        listEl.innerHTML = '';
        return;
    }

    if (emptyEl) emptyEl.style.display = 'none';

    // Group rejected ads by campaign
    const byCampaign = {};
    state.rejectedAds.forEach(ad => {
        const key = ad.campaign_id || 'unknown';
        if (!byCampaign[key]) {
            byCampaign[key] = {
                campaign_name: ad.campaign_name || 'Unknown Campaign',
                campaign_id: key,
                ads: []
            };
        }
        byCampaign[key].ads.push(ad);
    });

    let html = '';
    Object.values(byCampaign).forEach(group => {
        html += `
            <div class="rejected-campaign-group">
                <div class="rejected-group-header">
                    <span class="rejected-group-name">${escapeHtml(group.campaign_name)}</span>
                    <span class="rejected-group-count">${group.ads.length} rejected ad${group.ads.length !== 1 ? 's' : ''}</span>
                </div>
                <div class="rejected-group-body">
                    ${group.ads.map(ad => {
                        const effectiveAdId = ad.smart_plus_ad_id || ad.ad_id;
                        const advId = ad.advertiser_id || state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID || '';
                        const rejectText = ad.reject_reason || 'No reason provided';
                        const appealStatus = ad.appeal_status || 'NOT_APPEALED';
                        const reviewStatus = ad.review_status || 'UNAVAILABLE';

                        // Status badge
                        let statusBadge = '';
                        if (appealStatus === 'APPEALING') {
                            statusBadge = '<span class="ad-delivery-badge under-review">Appeal Pending</span>';
                        } else if (appealStatus === 'APPEAL_SUCCESSFUL') {
                            statusBadge = '<span class="ad-delivery-badge delivering">Appeal Approved</span>';
                        } else if (reviewStatus === 'PART_AVAILABLE') {
                            statusBadge = '<span class="ad-delivery-badge no-budget">Partial</span>';
                        } else {
                            statusBadge = '<span class="ad-delivery-badge rejected">Rejected</span>';
                        }

                        // Show Appeal button only if not already appealing/approved
                        const canAppeal = appealStatus === 'NOT_APPEALED' || appealStatus === 'APPEAL_FAILED';
                        const appealBtn = canAppeal
                            ? `<button class="btn-appeal" onclick="openAppealModal('${escapeHtml(effectiveAdId)}', '${escapeHtml(ad.ad_name).replace(/'/g, "\\'")}', '${escapeHtml(advId)}')" title="Appeal this ad">Appeal</button>`
                            : '';

                        return `
                            <div class="rejected-ad-row" data-ad-id="${escapeHtml(effectiveAdId)}">
                                <div class="rejected-ad-info">
                                    <div class="rejected-ad-name">${escapeHtml(ad.ad_name)} ${statusBadge}</div>
                                    <div class="rejected-ad-reason">Reason: ${escapeHtml(rejectText)}</div>
                                </div>
                                ${appealBtn}
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    });

    listEl.innerHTML = html;
}

// Render single campaign card
function renderCampaignCard(campaign) {
    const isActive = campaign.operation_status === 'ENABLE';
    const statusClass = isActive ? 'active' : 'inactive';
    const statusLabel = isActive ? 'Active' : 'Inactive';
    const toggleClass = isActive ? 'on' : '';
    const isSelected = state.selectedCampaigns.includes(campaign.campaign_id);
    const selectedClass = isSelected ? 'selected' : '';

    // Format budget
    const budget = campaign.budget ? `$${parseFloat(campaign.budget).toFixed(2)}` : 'N/A';
    const budgetMode = campaign.budget_mode === 'BUDGET_MODE_DAY' ? '/day'
        : campaign.budget_mode === 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET' ? '/day (dynamic)'
        : campaign.budget_mode === 'BUDGET_MODE_TOTAL' ? ' total'
        : '';

    // Format date
    const createDate = campaign.create_time
        ? new Date(campaign.create_time).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        : 'N/A';

    // Smart+ badge
    const smartPlusBadge = campaign.is_smart_performance_campaign
        ? '<span class="smart-plus-badge">Smart+</span>'
        : '';

    return `
        <div class="my-campaign-card ${selectedClass}" data-campaign-id="${campaign.campaign_id}">
            <div class="campaign-checkbox-wrapper">
                <input type="checkbox"
                       class="campaign-checkbox"
                       data-campaign-id="${campaign.campaign_id}"
                       ${isSelected ? 'checked' : ''}
                       onchange="toggleCampaignSelection('${campaign.campaign_id}')"
                       onclick="event.stopPropagation()">
            </div>
            <div class="my-campaign-card-info">
                <div class="my-campaign-card-name">
                    ${campaign.campaign_name}
                    ${smartPlusBadge}
                    <span class="campaign-id">#${campaign.campaign_id}</span>
                </div>
                <div class="my-campaign-card-meta">
                    <span class="meta-item">
                        <span class="meta-icon">💰</span>
                        ${budget}${budgetMode}
                    </span>
                    <span class="meta-item">
                        <span class="meta-icon">🎯</span>
                        ${formatObjectiveType(campaign.objective_type)}
                    </span>
                    <span class="meta-item">
                        <span class="meta-icon">📅</span>
                        ${createDate}
                    </span>
                </div>
            </div>
            <div class="my-campaign-card-actions">
                <button class="btn-duplicate-campaign"
                        onclick="openDuplicateCampaignModal('${campaign.campaign_id}', '${campaign.campaign_name.replace(/'/g, "\\'")}'); event.stopPropagation();"
                        title="Duplicate this campaign">
                    📋 Duplicate
                </button>
                <span class="campaign-status-badge ${statusClass}">${statusLabel}</span>
                <div class="campaign-toggle ${toggleClass}"
                     data-campaign-id="${campaign.campaign_id}"
                     data-status="${campaign.operation_status}"
                     onclick="toggleCampaignStatus('${campaign.campaign_id}', '${campaign.operation_status}')"
                     title="${isActive ? 'Click to disable' : 'Click to enable'}">
                    <div class="toggle-slider"></div>
                </div>
            </div>
        </div>
    `;
}

// Format objective type for display
function formatObjectiveType(objectiveType) {
    const types = {
        'LEAD_GENERATION': 'Lead Gen',
        'CONVERSIONS': 'Conversions',
        'TRAFFIC': 'Traffic',
        'APP_INSTALL': 'App Install',
        'REACH': 'Reach',
        'VIDEO_VIEWS': 'Video Views',
        'ENGAGEMENT': 'Engagement',
        'PRODUCT_SALES': 'Product Sales'
    };
    return types[objectiveType] || objectiveType || 'Unknown';
}

// Open inline budget edit
function openInlineBudgetEdit(campaignId, currentBudget) {
    // Close any other open budget editors first
    document.querySelectorAll('.budget-cell.editing').forEach(cell => {
        const otherCampaignId = cell.dataset.campaignId;
        if (otherCampaignId !== campaignId) {
            cancelInlineBudgetEdit(otherCampaignId);
        }
    });

    const budgetCell = document.querySelector(`.budget-cell[data-campaign-id="${campaignId}"]`);
    if (!budgetCell) return;

    budgetCell.classList.add('editing');

    // Replace content with input
    const currentDisplay = budgetCell.querySelector('.budget-display');
    const editBtn = budgetCell.querySelector('.edit-budget-btn');

    if (currentDisplay) currentDisplay.style.display = 'none';
    if (editBtn) editBtn.style.display = 'none';

    // Create inline editor
    const editor = document.createElement('div');
    editor.className = 'inline-budget-editor';
    editor.innerHTML = `
        <div class="budget-input-wrapper">
            <span class="budget-currency">$</span>
            <input type="number" class="budget-input" value="${currentBudget}" min="20" step="1" autofocus>
        </div>
        <div class="budget-actions">
            <button class="budget-save-btn" onclick="saveInlineBudget('${campaignId}'); event.stopPropagation();" title="Save">✓</button>
            <button class="budget-cancel-btn" onclick="cancelInlineBudgetEdit('${campaignId}'); event.stopPropagation();" title="Cancel">✕</button>
        </div>
    `;
    budgetCell.appendChild(editor);

    // Focus input and select all
    const input = editor.querySelector('.budget-input');
    input.focus();
    input.select();

    // Handle Enter and Escape keys
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveInlineBudget(campaignId);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelInlineBudgetEdit(campaignId);
        }
    });
}

// Cancel inline budget edit
function cancelInlineBudgetEdit(campaignId) {
    const budgetCell = document.querySelector(`.budget-cell[data-campaign-id="${campaignId}"]`);
    if (!budgetCell) return;

    budgetCell.classList.remove('editing');

    // Remove editor
    const editor = budgetCell.querySelector('.inline-budget-editor');
    if (editor) editor.remove();

    // Show original elements
    const currentDisplay = budgetCell.querySelector('.budget-display');
    const editBtn = budgetCell.querySelector('.edit-budget-btn');

    if (currentDisplay) currentDisplay.style.display = '';
    if (editBtn) editBtn.style.display = '';
}

// Save inline budget edit
async function saveInlineBudget(campaignId) {
    const budgetCell = document.querySelector(`.budget-cell[data-campaign-id="${campaignId}"]`);
    if (!budgetCell) return;

    const input = budgetCell.querySelector('.budget-input');
    if (!input) return;

    const newBudget = parseFloat(input.value);

    // Validate budget
    if (isNaN(newBudget) || newBudget < 20) {
        alert('Budget must be at least $20');
        input.focus();
        return;
    }

    // Find campaign to check if it's Smart+
    const campaign = state.campaignsList.find(c => c.campaign_id === campaignId);
    const isSmartPlus = campaign?.is_smart_performance_campaign || false;

    // Show loading state
    const saveBtn = budgetCell.querySelector('.budget-save-btn');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = '...';
    }

    try {
        const result = await apiRequest('update_campaign_budget', {
            campaign_id: campaignId,
            budget: newBudget,
            is_smart_plus: isSmartPlus
        });

        if (result.success) {
            // Update local state
            const campaign = state.campaignsList.find(c => c.campaign_id === campaignId);
            if (campaign) {
                campaign.budget = newBudget;
            }

            // Update display
            const budgetDisplay = budgetCell.querySelector('.budget-display');
            if (budgetDisplay) {
                budgetDisplay.textContent = `$${newBudget.toFixed(2)}`;
            }
            budgetCell.dataset.budget = newBudget;

            // Close editor
            cancelInlineBudgetEdit(campaignId);

            addLog('success', `Budget updated to $${newBudget.toFixed(2)}`);
        } else {
            throw new Error(result.message || 'Failed to update budget');
        }
    } catch (error) {
        console.error('Error updating budget:', error);

        // Check if this is an Upgraded Smart+ campaign limitation
        if (error.message && error.message.includes('Upgraded Smart Plus')) {
            addLog('warning', 'Upgraded Smart+ campaigns require TikTok Ads Manager for budget changes.');
            showToast('Upgraded Smart+ budget must be changed in TikTok Ads Manager', 'error');

            // Mark the campaign row to indicate it can't be edited
            const campaign = state.campaignsList.find(c => c.campaign_id === campaignId);
            if (campaign) {
                campaign.is_upgraded_smart_plus = true;
            }

            // Show info modal with link
            showUpgradedSmartPlusInfo();
        } else {
            addLog('error', `Failed to update budget: ${error.message}`);
            showToast('Failed to update budget: ' + error.message, 'error');
        }

        // Re-enable save button
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = '✓';
        }

        // Close the editor
        cancelInlineBudgetEdit(campaignId);
    }
}

// Show info modal for Upgraded Smart+ budget limitation
function showUpgradedSmartPlusInfo() {
    const modalHtml = `
        <div id="upgraded-smart-info-modal" class="modal" style="display: flex;">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>⚠️ Upgraded Smart+ Campaign</h3>
                    <span class="modal-close" onclick="document.getElementById('upgraded-smart-info-modal').remove()">&times;</span>
                </div>
                <div class="modal-body" style="text-align: center; padding: 20px;">
                    <p style="margin-bottom: 16px; color: #4b5563;">
                        This campaign uses TikTok's <strong>Upgraded Smart+</strong> format which doesn't support budget editing through the API.
                    </p>
                    <p style="margin-bottom: 20px; color: #6b7280; font-size: 14px;">
                        To change the budget, please use TikTok Ads Manager directly.
                    </p>
                    <a href="https://ads.tiktok.com/i18n/perf/campaign" target="_blank"
                       style="display: inline-block; background: #1a1a1a; color: white; padding: 10px 24px; border-radius: 6px; text-decoration: none; font-weight: 500;">
                        Open TikTok Ads Manager →
                    </a>
                </div>
                <div class="modal-footer" style="justify-content: center;">
                    <button class="btn-secondary" onclick="document.getElementById('upgraded-smart-info-modal').remove()">Close</button>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if any
    const existing = document.getElementById('upgraded-smart-info-modal');
    if (existing) existing.remove();

    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// Toggle campaign status (ON/OFF)
async function toggleCampaignStatus(campaignId, currentStatus) {
    // Support both table view and legacy card view
    let toggleEl = document.querySelector(`.toggle-table[data-campaign-id="${campaignId}"]`);
    let rowEl = document.querySelector(`tr[data-campaign-id="${campaignId}"]`);

    // Fallback to legacy card view selectors
    if (!toggleEl) {
        toggleEl = document.querySelector(`.campaign-toggle[data-campaign-id="${campaignId}"]`);
        rowEl = document.querySelector(`.my-campaign-card[data-campaign-id="${campaignId}"]`);
    }

    if (!toggleEl) return;

    // Add loading state
    toggleEl.classList.add('loading');

    const newStatus = currentStatus === 'ENABLE' ? 'DISABLE' : 'ENABLE';
    const actionWord = newStatus === 'ENABLE' ? 'Enabling' : 'Disabling';

    addLog('info', `${actionWord} campaign ${campaignId}...`);

    try {
        const result = await apiRequest('update_campaign_status', {
            campaign_id: campaignId,
            status: newStatus
        });

        if (result.success) {
            // Update local state
            const campaign = state.campaignsList.find(c => c.campaign_id === campaignId);
            if (campaign) {
                campaign.operation_status = newStatus;
            }

            // Update UI - toggle element
            toggleEl.classList.remove('loading');
            toggleEl.classList.toggle('on', newStatus === 'ENABLE');
            toggleEl.dataset.status = newStatus;

            // Update status badge (works for both table and card view)
            if (rowEl) {
                const badgeEl = rowEl.querySelector('.status-badge-table') || rowEl.querySelector('.campaign-status-badge');
                if (badgeEl) {
                    badgeEl.className = badgeEl.classList.contains('status-badge-table')
                        ? `status-badge-table ${newStatus === 'ENABLE' ? 'active' : 'inactive'}`
                        : `campaign-status-badge ${newStatus === 'ENABLE' ? 'active' : 'inactive'}`;
                    badgeEl.textContent = newStatus === 'ENABLE' ? 'Active' : 'Paused';
                }
            }

            // Update counts
            updateCampaignCounts();

            // Re-apply filters if needed (campaign might disappear from current filter)
            if (state.campaignFilter !== 'all') {
                applyFiltersAndRender();
            }

            showToast(`Campaign ${newStatus === 'ENABLE' ? 'enabled' : 'disabled'} successfully`, 'success');
            addLog('success', `Campaign ${campaignId} ${newStatus === 'ENABLE' ? 'enabled' : 'disabled'}`);
        } else {
            throw new Error(result.message || 'Failed to update status');
        }
    } catch (error) {
        console.error('Error toggling campaign status:', error);
        toggleEl.classList.remove('loading');
        showToast(`Failed to update campaign: ${error.message}`, 'error');
        addLog('error', `Failed to update campaign ${campaignId}: ${error.message}`);
    }
}

// ============================================
// BULK CAMPAIGN SELECTION & OPERATIONS
// ============================================

// Toggle individual campaign selection
function toggleCampaignSelection(campaignId) {
    const index = state.selectedCampaigns.indexOf(campaignId);
    if (index > -1) {
        state.selectedCampaigns.splice(index, 1);
    } else {
        state.selectedCampaigns.push(campaignId);
    }
    updateBulkSelectionUI();
}

// Toggle select all campaigns
function toggleSelectAllCampaigns() {
    const selectAllCheckbox = document.getElementById('select-all-campaigns');
    if (selectAllCheckbox.checked) {
        // Select all filtered campaigns
        state.selectedCampaigns = state.filteredCampaigns.map(c => c.campaign_id);
    } else {
        // Deselect all
        state.selectedCampaigns = [];
    }
    updateBulkSelectionUI();
    renderCampaignList();
}

// Update bulk selection UI (counters, buttons, checkboxes)
function updateBulkSelectionUI() {
    const selectedCount = state.selectedCampaigns.length;
    const totalFiltered = state.filteredCampaigns.length;

    // Update selected count display
    const countEl = document.getElementById('selected-campaigns-count');
    if (countEl) {
        countEl.textContent = `${selectedCount} selected`;
    }

    // Show/hide bulk action buttons
    const buttonsEl = document.getElementById('bulk-action-buttons');
    if (buttonsEl) {
        buttonsEl.style.display = selectedCount > 0 ? 'flex' : 'none';
    }

    // Update select all checkbox state
    const selectAllCheckbox = document.getElementById('select-all-campaigns');
    if (selectAllCheckbox) {
        if (selectedCount === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (selectedCount === totalFiltered) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }

    // Update individual card checkboxes and highlight
    document.querySelectorAll('.campaign-checkbox').forEach(checkbox => {
        const campaignId = checkbox.dataset.campaignId;
        const isSelected = state.selectedCampaigns.includes(campaignId);
        checkbox.checked = isSelected;

        // Update card highlight
        const card = checkbox.closest('.my-campaign-card');
        if (card) {
            card.classList.toggle('selected', isSelected);
        }
    });
}

// Bulk toggle campaigns (ON/OFF)
async function bulkToggleCampaigns(targetStatus) {
    if (state.selectedCampaigns.length === 0) {
        showToast('Please select at least one campaign', 'warning');
        return;
    }

    const actionWord = targetStatus === 'ENABLE' ? 'enable' : 'disable';
    const count = state.selectedCampaigns.length;

    // Confirm action
    if (!confirm(`Are you sure you want to ${actionWord} ${count} campaign${count > 1 ? 's' : ''}?`)) {
        return;
    }

    addLog('info', `Bulk ${actionWord} started for ${count} campaigns...`);
    showToast(`${actionWord.charAt(0).toUpperCase() + actionWord.slice(1)}ing ${count} campaign${count > 1 ? 's' : ''}...`, 'info');

    // Disable bulk action buttons during operation
    const buttons = document.querySelectorAll('.btn-bulk-action');
    buttons.forEach(btn => btn.disabled = true);

    let successCount = 0;
    let failCount = 0;
    const results = [];

    // Process campaigns sequentially to avoid rate limiting
    for (const campaignId of state.selectedCampaigns) {
        const campaign = state.campaignsList.find(c => c.campaign_id === campaignId);
        if (!campaign) continue;

        // Skip if already in target status
        if (campaign.operation_status === targetStatus) {
            results.push({ campaignId, success: true, skipped: true });
            successCount++;
            continue;
        }

        try {
            const result = await apiRequest('update_campaign_status', {
                campaign_id: campaignId,
                status: targetStatus
            });

            if (result.success) {
                // Update local state
                campaign.operation_status = targetStatus;
                results.push({ campaignId, success: true });
                successCount++;
            } else {
                results.push({ campaignId, success: false, error: result.message });
                failCount++;
            }
        } catch (error) {
            results.push({ campaignId, success: false, error: error.message });
            failCount++;
        }

        // Small delay to avoid rate limiting
        await new Promise(resolve => setTimeout(resolve, 200));
    }

    // Re-enable buttons
    buttons.forEach(btn => btn.disabled = false);

    // Update UI
    updateCampaignCounts();
    renderCampaignList();

    // Clear selection after bulk operation
    state.selectedCampaigns = [];
    updateBulkSelectionUI();

    // Show results
    if (failCount === 0) {
        showToast(`Successfully ${actionWord}d ${successCount} campaign${successCount > 1 ? 's' : ''}`, 'success');
        addLog('success', `Bulk ${actionWord} completed: ${successCount} success, ${failCount} failed`);
    } else if (successCount === 0) {
        showToast(`Failed to ${actionWord} campaigns`, 'error');
        addLog('error', `Bulk ${actionWord} failed: ${failCount} campaigns failed`);
    } else {
        showToast(`${actionWord.charAt(0).toUpperCase() + actionWord.slice(1)}d ${successCount}, failed ${failCount}`, 'warning');
        addLog('warning', `Bulk ${actionWord} partial: ${successCount} success, ${failCount} failed`);
    }
}

// Clear campaign selection when switching views or filters
function clearCampaignSelection() {
    state.selectedCampaigns = [];
    updateBulkSelectionUI();
}

// ============================================
// DUPLICATE CAMPAIGN FEATURE
// ============================================

// State for duplicate modal
let duplicateState = {
    campaignId: null,
    campaignName: null,
    campaignDetails: null, // { campaign, adgroup, ad }
    isLoading: false,
    isProcessing: false,
    // Bulk launch state
    bulkAccounts: [],
    bulkAccountAssets: {},
    bulkSelectedAccounts: []
};

// Open duplicate campaign modal
async function openDuplicateCampaignModal(campaignId, campaignName) {
    duplicateState.campaignId = campaignId;
    duplicateState.campaignName = campaignName;
    duplicateState.campaignDetails = null;
    duplicateState.mode = 'same'; // Default mode

    // Show modal
    const modal = document.getElementById('duplicate-campaign-modal');
    modal.style.display = 'flex';

    // Update campaign info
    document.getElementById('duplicate-campaign-name').textContent = campaignName;
    document.getElementById('duplicate-campaign-id').textContent = campaignId;

    // Reset sections
    document.getElementById('duplicate-loading-state').style.display = 'block';
    document.getElementById('duplicate-mode-section').style.display = 'none';
    document.getElementById('duplicate-details-section').style.display = 'none';
    document.getElementById('duplicate-progress-section').style.display = 'none';
    document.getElementById('duplicate-success-section').style.display = 'none';

    // Reset mode selection to default
    document.getElementById('mode-option-same').classList.add('selected');
    document.getElementById('mode-option-edit').classList.remove('selected');
    document.querySelector('input[name="duplicate_mode"][value="same"]').checked = true;

    // Reset footer buttons
    const footer = document.getElementById('duplicate-modal-footer');
    footer.innerHTML = `
        <button class="btn-secondary" onclick="closeDuplicateCampaignModal()">Cancel</button>
        <button class="btn-primary" id="duplicate-create-btn" onclick="executeDuplicateCampaign()" disabled>
            📋 Create Copies
        </button>
    `;

    // Load media library in background if empty (needed for video thumbnails)
    if (state.mediaLibrary.filter(m => m.type === 'video').length === 0) {
        addLog('info', 'Loading media library for video thumbnails...');
        loadMediaLibrary(true).then(() => {
            addLog('success', `Media library loaded: ${state.mediaLibrary.filter(m => m.type === 'video').length} videos`);
            // Re-render current videos once media library is loaded (for thumbnails)
            if (duplicateState.campaignDetails?.ad) {
                renderDuplicateCurrentVideos();
            }
        }).catch(err => {
            console.error('Failed to load media library:', err);
        });
    }

    // Fetch campaign details
    addLog('info', `Fetching details for campaign ${campaignId}...`);

    try {
        const result = await apiRequest('get_campaign_details', { campaign_id: campaignId });

        if (result.success) {
            duplicateState.campaignDetails = result;

            // Log full response for debugging
            console.log('Full get_campaign_details response:', JSON.stringify(result, null, 2));

            // Check for missing CTA portfolio
            if (result.missing_cta_portfolio) {
                addLog('warning', 'No CTA Portfolio found in database. Please create one first.');
            }

            // Log the call_to_action_id we received
            console.log('call_to_action_id from backend:', result.ad?.call_to_action_id);
            if (result.default_cta_portfolio) {
                console.log('Using default CTA portfolio:', result.default_cta_portfolio);
            }

            // Update structure summary
            document.getElementById('dup-detail-campaign').textContent =
                `${result.campaign?.campaign_name || 'N/A'} (Budget: $${result.campaign?.budget || 0})`;
            document.getElementById('dup-detail-adgroup').textContent =
                result.adgroup?.adgroup_name || 'Not found';
            document.getElementById('dup-detail-ad').textContent =
                result.ad?.ad_name || 'Not found';

            // Populate edit fields with original values
            populateDuplicateEditFields(result);

            // Show mode selection and details section
            document.getElementById('duplicate-loading-state').style.display = 'none';
            document.getElementById('duplicate-mode-section').style.display = 'block';
            document.getElementById('duplicate-details-section').style.display = 'block';

            // Enable create button
            document.getElementById('duplicate-create-btn').disabled = false;

            // Set default count and update preview
            document.getElementById('duplicate-copy-count').value = 1;
            document.getElementById('duplicate-edit-copy-count').value = 1;
            toggleDuplicateMode('same'); // Reset to same mode
            updateDuplicatePreviewList();

            addLog('success', `Campaign details loaded: ${result.campaign?.campaign_name}`);
        } else {
            throw new Error(result.message || 'Failed to load campaign details');
        }
    } catch (error) {
        console.error('Error fetching campaign details:', error);
        document.getElementById('duplicate-loading-state').innerHTML = `
            <div style="color: var(--destructive); text-align: center; padding: 20px;">
                <p style="font-size: 24px;">❌</p>
                <p style="font-weight: 600;">Failed to load campaign details</p>
                <p style="font-size: 13px; margin-top: 10px;">${error.message}</p>
            </div>
        `;
        addLog('error', `Failed to fetch campaign details: ${error.message}`);
    }
}

// Populate edit fields with original campaign values
function populateDuplicateEditFields(result) {
    const { campaign, adgroup, ad } = result;

    // Campaign name (add "Copy" suffix)
    document.getElementById('dup-edit-campaign-name').value =
        (campaign?.campaign_name || '') + ' - Copy';

    // Budget
    document.getElementById('dup-edit-budget').value = campaign?.budget || 50;

    // Landing page URL
    let landingPageUrl = ad?.landing_page_url || '';
    if (!landingPageUrl && ad?.landing_page_url_list?.length > 0) {
        if (typeof ad.landing_page_url_list[0] === 'object') {
            landingPageUrl = ad.landing_page_url_list[0].landing_page_url || '';
        } else {
            landingPageUrl = ad.landing_page_url_list[0] || '';
        }
    }
    document.getElementById('dup-edit-landing-url').value = landingPageUrl;

    // Ad text
    const adTexts = ad?.ad_texts || ad?.ad_text_list || [];
    document.getElementById('dup-edit-ad-text').value =
        Array.isArray(adTexts) ? adTexts.join('\n') : (adTexts || '');

    // Store original schedule data for "same" mode duplication
    duplicateState.originalSchedule = {
        schedule_type: adgroup?.schedule_type || 'SCHEDULE_FROM_NOW',
        schedule_start_time: adgroup?.schedule_start_time || null,
        schedule_end_time: adgroup?.schedule_end_time || null,
        dayparting: adgroup?.dayparting || null
    };
    console.log('Stored original schedule for duplication:', duplicateState.originalSchedule);

    // Initialize videos display
    initDuplicateVideosDisplay();
}

// Toggle between duplicate modes (same vs edit vs bulk)
function toggleDuplicateMode(mode) {
    duplicateState.mode = mode;

    // Update UI selection
    const sameOption = document.getElementById('mode-option-same');
    const editOption = document.getElementById('mode-option-edit');
    const bulkOption = document.getElementById('mode-option-bulk');
    const countSection = document.getElementById('duplicate-count-section');
    const editSection = document.getElementById('duplicate-edit-section');
    const includesSection = document.getElementById('duplicate-includes-section');
    const bulkSection = document.getElementById('duplicate-bulk-section');
    const detailsSection = document.getElementById('duplicate-details-section');

    // Reset all selections
    sameOption?.classList.remove('selected');
    editOption?.classList.remove('selected');
    bulkOption?.classList.remove('selected');

    // Hide all sections
    if (countSection) countSection.style.display = 'none';
    if (editSection) editSection.style.display = 'none';
    if (includesSection) includesSection.style.display = 'none';
    if (bulkSection) bulkSection.style.display = 'none';

    // Show/hide same-mode video section
    const sameVideosSection = document.getElementById('duplicate-same-videos-section');

    if (mode === 'same') {
        sameOption?.classList.add('selected');
        if (countSection) countSection.style.display = 'block';
        if (includesSection) includesSection.style.display = 'block';
        if (detailsSection) detailsSection.style.display = 'block';
        if (sameVideosSection) sameVideosSection.style.display = 'block';
        // Refresh the video display for same mode
        renderDuplicateCurrentVideos();
    } else if (mode === 'edit') {
        editOption?.classList.add('selected');
        if (editSection) editSection.style.display = 'block';
        if (detailsSection) detailsSection.style.display = 'block';
        if (sameVideosSection) sameVideosSection.style.display = 'none';
    } else if (mode === 'bulk') {
        bulkOption?.classList.add('selected');
        if (bulkSection) bulkSection.style.display = 'block';
        if (detailsSection) detailsSection.style.display = 'none'; // Hide details in bulk mode
        // Load accounts for bulk mode
        loadDuplicateBulkAccounts();
    }

    updateDuplicatePreviewList();
}

// Close duplicate modal
function closeDuplicateCampaignModal() {
    const modal = document.getElementById('duplicate-campaign-modal');
    modal.style.display = 'none';

    // Reset state
    duplicateState = {
        campaignId: null,
        campaignName: null,
        campaignDetails: null,
        isLoading: false,
        isProcessing: false,
        bulkAccounts: [],
        bulkAccountAssets: {},
        bulkSelectedAccounts: []
    };

    // Reset loading state for next open
    document.getElementById('duplicate-loading-state').innerHTML = `
        <div class="spinner"></div>
        <p style="margin-top: 15px; color: #666;">Fetching campaign details...</p>
    `;

    // Reset schedule options
    resetDupScheduleOptions();
}

// Reset duplicate schedule options to default
function resetDupScheduleOptions() {
    // Reset to "continuous" option
    const continuousRadio = document.querySelector('input[name="dup_schedule_type"][value="continuous"]');
    if (continuousRadio) {
        continuousRadio.checked = true;
    }

    // Hide date containers
    const startOnlyContainer = document.getElementById('dup-schedule-start-only-container');
    const dateTimeContainer = document.getElementById('dup-schedule-datetime-container');
    if (startOnlyContainer) startOnlyContainer.style.display = 'none';
    if (dateTimeContainer) dateTimeContainer.style.display = 'none';

    // Reset option borders
    document.querySelectorAll('.dup-schedule-option').forEach((opt, index) => {
        opt.style.borderColor = index === 0 ? '#1a1a1a' : '#e2e8f0';
    });

    // Clear datetime inputs
    const inputs = ['dup-schedule-start-only-datetime', 'dup-schedule-start-datetime', 'dup-schedule-end-datetime'];
    inputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) input.value = '';
    });

    // Clear changed videos
    duplicateState.changedVideos = null;
}

// ============================================
// DUPLICATE BULK LAUNCH FUNCTIONS
// ============================================

// Filter duplicate bulk accounts by search query
function filterDupBulkAccounts(query) {
    const searchQuery = query.toLowerCase().trim();
    const container = document.getElementById('dup-bulk-accounts-container');
    const accountCards = container.querySelectorAll('.dup-bulk-account-card');
    let visibleCount = 0;
    let totalCount = 0;

    accountCards.forEach(card => {
        const accountName = card.querySelector('.dup-bulk-account-name')?.textContent?.toLowerCase() || '';
        const accountId = card.querySelector('.dup-bulk-account-id')?.textContent?.toLowerCase() || '';

        totalCount++;

        if (searchQuery === '' || accountName.includes(searchQuery) || accountId.includes(searchQuery)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    // Update search results count
    const resultsCountEl = document.getElementById('dup-bulk-search-results-count');
    if (resultsCountEl) {
        if (searchQuery === '') {
            resultsCountEl.textContent = '';
        } else {
            resultsCountEl.textContent = `Showing ${visibleCount} of ${totalCount} accounts`;
        }
    }
}

// Load accounts for bulk duplicate
async function loadDuplicateBulkAccounts() {
    const container = document.getElementById('dup-bulk-accounts-container');
    container.innerHTML = `
        <div class="loading-state" style="text-align: center; padding: 20px;">
            <div class="spinner"></div>
            <p style="margin-top: 10px; color: #64748b;">Loading accounts...</p>
        </div>
    `;

    try {
        const result = await apiRequest('get_all_advertisers');
        if (result.success && result.data?.list) {
            duplicateState.bulkAccounts = result.data.list;
            duplicateState.bulkSelectedAccounts = [];
            duplicateState.bulkAccountAssets = {};
            renderDuplicateBulkAccounts();
        } else {
            throw new Error(result.message || 'Failed to load accounts');
        }
    } catch (error) {
        container.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #ef4444;">
                <p>❌ ${error.message}</p>
                <button onclick="loadDuplicateBulkAccounts()" style="margin-top: 10px; padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                    Retry
                </button>
            </div>
        `;
    }
}

// Render bulk accounts list
function renderDuplicateBulkAccounts() {
    const container = document.getElementById('dup-bulk-accounts-container');
    const currentAdvertiserId = document.getElementById('advertiser-id')?.textContent || '';

    let html = '';
    duplicateState.bulkAccounts.forEach(account => {
        const isCurrent = account.advertiser_id === currentAdvertiserId;
        const isSelected = duplicateState.bulkSelectedAccounts.some(a => a.advertiser_id === account.advertiser_id);
        const assets = duplicateState.bulkAccountAssets[account.advertiser_id];

        html += `
            <div class="dup-bulk-account-card ${isSelected ? 'selected' : ''} ${isCurrent ? 'current' : ''}"
                 id="dup-bulk-account-${account.advertiser_id}">
                <div class="dup-bulk-account-header">
                    <label class="dup-bulk-checkbox">
                        <input type="checkbox"
                               ${isCurrent ? 'disabled title="This is the source account"' : ''}
                               ${isSelected ? 'checked' : ''}
                               onchange="toggleDupBulkAccount('${account.advertiser_id}')">
                        <span class="checkmark"></span>
                    </label>
                    <div class="dup-bulk-account-info">
                        <span class="dup-bulk-account-name">${account.advertiser_name}</span>
                        <span class="dup-bulk-account-id">${account.advertiser_id}</span>
                        ${isCurrent ? '<span class="source-badge">📍 Source</span>' : ''}
                    </div>
                    ${!isCurrent && !assets && isSelected ? `
                        <button type="button" class="btn-load-assets-small" onclick="loadDupBulkAccountAssets('${account.advertiser_id}')">
                            Load Assets
                        </button>
                    ` : ''}
                </div>
                ${isSelected && !isCurrent ? `
                    <div class="dup-bulk-account-config" id="dup-bulk-config-${account.advertiser_id}">
                        ${assets ? renderDupBulkAccountConfig(account.advertiser_id, assets) : `
                            <div style="padding: 15px; text-align: center; color: #64748b;">
                                <div class="spinner-small"></div>
                                <p style="margin-top: 8px; font-size: 12px;">Loading assets...</p>
                            </div>
                        `}
                    </div>
                ` : ''}
            </div>
        `;
    });

    container.innerHTML = html || '<p style="text-align: center; color: #64748b; padding: 20px;">No accounts found</p>';

    // Re-apply search filter if there's a search query
    const searchInput = document.getElementById('dup-bulk-account-search-input');
    if (searchInput && searchInput.value.trim()) {
        filterDupBulkAccounts(searchInput.value);
    }

    // Add styles if not already present
    if (!document.getElementById('dup-bulk-styles')) {
        const styles = document.createElement('style');
        styles.id = 'dup-bulk-styles';
        styles.textContent = `
            .dup-bulk-account-card {
                border: 2px solid #e2e8f0;
                border-radius: 10px;
                margin-bottom: 12px;
                transition: all 0.2s;
            }
            .dup-bulk-account-card.selected {
                border-color: #3b82f6;
                background: #f0f9ff;
            }
            .dup-bulk-account-card.current {
                opacity: 0.6;
                background: #f8fafc;
            }
            .dup-bulk-account-header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 15px;
            }
            .dup-bulk-checkbox input[type="checkbox"] {
                width: 18px;
                height: 18px;
                cursor: pointer;
            }
            .dup-bulk-account-info {
                flex: 1;
            }
            .dup-bulk-account-name {
                font-weight: 600;
                color: #1e293b;
                display: block;
            }
            .dup-bulk-account-id {
                font-size: 12px;
                color: #64748b;
            }
            .source-badge {
                font-size: 11px;
                background: #fef3c7;
                color: #92400e;
                padding: 2px 8px;
                border-radius: 4px;
                margin-left: 8px;
            }
            .btn-load-assets-small {
                padding: 6px 12px;
                background: #f1f5f9;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                font-size: 12px;
                cursor: pointer;
            }
            .dup-bulk-account-config {
                border-top: 1px solid #e2e8f0;
                padding: 15px;
                background: white;
                border-radius: 0 0 8px 8px;
            }
            .dup-bulk-config-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .dup-bulk-config-item {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .dup-bulk-config-item label {
                font-size: 12px;
                font-weight: 600;
                color: #475569;
            }
            .dup-bulk-config-item input,
            .dup-bulk-config-item select {
                padding: 8px 10px;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                font-size: 13px;
            }
            .dup-bulk-config-item.full-width {
                grid-column: 1 / -1;
            }
        `;
        document.head.appendChild(styles);
    }

    updateDupBulkSummary();
}

// Toggle account selection for bulk duplicate
async function toggleDupBulkAccount(advertiserId) {
    const card = document.getElementById(`dup-bulk-account-${advertiserId}`);
    const checkbox = card.querySelector('input[type="checkbox"]');
    const isSelected = checkbox.checked;

    if (isSelected) {
        // Add to selected
        card.classList.add('selected');
        const account = duplicateState.bulkAccounts.find(a => a.advertiser_id === advertiserId);
        if (account && !duplicateState.bulkSelectedAccounts.some(a => a.advertiser_id === advertiserId)) {
            duplicateState.bulkSelectedAccounts.push({
                advertiser_id: advertiserId,
                advertiser_name: account.advertiser_name,
                campaign_name: (duplicateState.campaignDetails?.campaign?.campaign_name || '') + ' - Copy',
                budget: duplicateState.campaignDetails?.campaign?.budget || 50,
                landing_page_url: '',
                pixel_id: null,
                identity_id: null,
                video_id: null,
                video_ids: [] // Support multiple video selection
            });
        }
        // Load assets if not loaded
        if (!duplicateState.bulkAccountAssets[advertiserId]) {
            await loadDupBulkAccountAssets(advertiserId);
        }
    } else {
        // Remove from selected
        card.classList.remove('selected');
        duplicateState.bulkSelectedAccounts = duplicateState.bulkSelectedAccounts.filter(a => a.advertiser_id !== advertiserId);
    }

    renderDuplicateBulkAccounts();
}

// Load assets for a bulk account
async function loadDupBulkAccountAssets(advertiserId) {
    try {
        const result = await apiRequest('get_account_assets', { target_advertiser_id: advertiserId });
        if (result.success && result.data) {
            duplicateState.bulkAccountAssets[advertiserId] = result.data;
            renderDuplicateBulkAccounts();
        }
    } catch (error) {
        console.error('Error loading assets:', error);
    }
}

// Render config section for a bulk account
function renderDupBulkAccountConfig(advertiserId, assets) {
    const selectedAccount = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    const originalCampaign = duplicateState.campaignDetails?.campaign;
    const originalAd = duplicateState.campaignDetails?.ad;

    // Get original landing page
    let originalLandingUrl = originalAd?.landing_page_url || '';
    if (!originalLandingUrl && originalAd?.landing_page_url_list?.length > 0) {
        originalLandingUrl = typeof originalAd.landing_page_url_list[0] === 'object'
            ? originalAd.landing_page_url_list[0].landing_page_url || ''
            : originalAd.landing_page_url_list[0] || '';
    }

    const pixels = assets.pixels || [];
    const identities = assets.identities || [];
    const videos = assets.videos || [];
    const portfolios = assets.portfolios || [];

    // Support multiple video selection - use video_ids array
    const selectedVideoIds = selectedAccount?.video_ids || [];
    const selectedVideoCount = selectedVideoIds.length;

    return `
        <div class="dup-bulk-config-grid" data-advertiser="${advertiserId}">
            <!-- Campaign Name -->
            <div class="dup-bulk-config-item full-width">
                <label>Campaign Name</label>
                <input type="text" id="dup-bulk-name-${advertiserId}"
                       value="${selectedAccount?.campaign_name || (originalCampaign?.campaign_name || '') + ' - Copy'}"
                       onchange="updateDupBulkAccountConfig('${advertiserId}', 'campaign_name', this.value)"
                       placeholder="Campaign name">
            </div>

            <!-- Budget -->
            <div class="dup-bulk-config-item">
                <label>Daily Budget ($)</label>
                <input type="number" id="dup-bulk-budget-${advertiserId}"
                       value="${selectedAccount?.budget || originalCampaign?.budget || 50}"
                       min="20"
                       onchange="updateDupBulkAccountConfig('${advertiserId}', 'budget', this.value)"
                       placeholder="50">
            </div>

            <!-- Pixel -->
            <div class="dup-bulk-config-item">
                <label>Pixel</label>
                <div style="display: flex; gap: 6px;">
                    <select id="dup-bulk-pixel-${advertiserId}" style="flex: 1;"
                            onchange="updateDupBulkAccountConfig('${advertiserId}', 'pixel_id', this.value)">
                        <option value="">Select Pixel...</option>
                        ${pixels.map(p => `<option value="${p.pixel_id}" ${selectedAccount?.pixel_id === p.pixel_id ? 'selected' : ''}>${p.pixel_name}</option>`).join('')}
                    </select>
                    <button type="button" class="refresh-btn" id="pixel-refresh-btn-${advertiserId}" onclick="refreshPixels('${advertiserId}')" title="Refresh pixels">
                        <span id="pixel-refresh-icon-${advertiserId}">🔄</span>
                    </button>
                </div>
            </div>

            <!-- Identity -->
            <div class="dup-bulk-config-item">
                <label>Identity</label>
                <div style="display: flex; gap: 6px;">
                    <select id="dup-bulk-identity-${advertiserId}" style="flex: 1;"
                            onchange="updateDupBulkIdentity('${advertiserId}', this.value)">
                        <option value="">Select Identity...</option>
                        ${identities.map(i => `<option value="${i.identity_id}" data-type="${i.identity_type || 'CUSTOMIZED_USER'}" data-bc-id="${i.identity_authorized_bc_id || ''}" ${selectedAccount?.identity_id === i.identity_id ? 'selected' : ''}>${i.display_name || i.identity_name}</option>`).join('')}
                    </select>
                    <button type="button" class="refresh-btn" id="identity-refresh-btn-${advertiserId}" onclick="refreshIdentities('${advertiserId}')" title="Refresh identities">
                        <span id="identity-refresh-icon-${advertiserId}">🔄</span>
                    </button>
                    <button type="button" onclick="openDupBulkIdentityCreate('${advertiserId}')"
                            style="padding: 6px 10px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;"
                            title="Create new identity">+ New</button>
                </div>
            </div>

            <!-- CTA Portfolio (optional) -->
            <div class="dup-bulk-config-item">
                <label>CTA Portfolio <span style="color: #94a3b8; font-size: 10px;">(optional)</span></label>
                <div style="display: flex; gap: 6px;">
                    <select id="dup-bulk-portfolio-${advertiserId}" style="flex: 1;"
                            onchange="updateDupBulkAccountConfig('${advertiserId}', 'portfolio_id', this.value)">
                        <option value="">None (use default CTA)</option>
                        ${portfolios.map(p => `<option value="${p.portfolio_id}" ${selectedAccount?.portfolio_id === p.portfolio_id ? 'selected' : ''}>${p.portfolio_name}</option>`).join('')}
                    </select>
                    <button type="button" onclick="openDupBulkPortfolioCreate('${advertiserId}')"
                            style="padding: 6px 10px; background: #8b5cf6; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;"
                            title="Create new portfolio">+ New</button>
                </div>
            </div>

            <!-- Videos (Multi-select) -->
            <div class="dup-bulk-config-item full-width">
                <label>Videos <span id="dup-bulk-video-count-${advertiserId}" style="font-weight: normal; color: #64748b;">(${selectedVideoCount} selected)</span></label>
                <div class="dup-bulk-video-actions" style="display: flex; gap: 8px; margin-bottom: 8px;">
                    <button type="button" onclick="openDupBulkVideoUpload('${advertiserId}')"
                            style="padding: 6px 12px; font-size: 12px; background: linear-gradient(135deg, #fe2c55, #25f4ee); color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Upload Videos
                    </button>
                    <button type="button" id="refresh-btn-${advertiserId}" onclick="refreshDupBulkVideos('${advertiserId}')"
                            style="padding: 6px 12px; font-size: 12px; background: #10b981; color: white; border: none; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <span class="refresh-icon" style="display: inline-block;">&#x21bb;</span> Refresh from TikTok
                    </button>
                    <button type="button" onclick="toggleDupBulkVideoList('${advertiserId}')"
                            style="padding: 6px 12px; font-size: 12px; background: #64748b; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        ${selectedVideoCount > 0 ? 'Edit Selection' : 'Select Videos'}
                    </button>
                </div>
                <!-- Upload Progress Bar -->
                <div id="dup-bulk-upload-progress-${advertiserId}" style="display: none; margin-bottom: 12px; padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-weight: 600; font-size: 13px; color: #1a1a1a;">Uploading Videos</span>
                        <span id="dup-bulk-upload-count-${advertiserId}" style="font-size: 12px; color: #64748b;">0/0</span>
                    </div>
                    <div style="background: #e2e8f0; border-radius: 4px; height: 6px; overflow: hidden;">
                        <div id="dup-bulk-upload-bar-${advertiserId}" style="background: linear-gradient(90deg, #fe2c55, #25f4ee); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                    </div>
                    <div id="dup-bulk-upload-list-${advertiserId}" style="max-height: 120px; overflow-y: auto; margin-top: 8px;"></div>
                </div>
                <div id="dup-bulk-video-list-${advertiserId}" class="dup-bulk-video-list" style="display: none; max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px;">
                    ${videos.length === 0 ? '<p style="color: #64748b; text-align: center; margin: 8px 0;">No videos available. Upload or refresh.</p>' : ''}
                    ${videos.map(v => {
                        const isSelected = selectedVideoIds.includes(v.video_id);
                        const displayName = (v.file_name || v.video_id || '').substring(0, 30) + ((v.file_name || v.video_id || '').length > 30 ? '...' : '');
                        const thumbnailUrl = v.video_cover_url || v.cover_image_url || v.preview_url || v.thumbnail_url || v.poster_url || '';
                        return `
                            <label class="dup-bulk-video-item ${isSelected ? 'selected' : ''}" style="display: flex; align-items: center; gap: 10px; padding: 8px; cursor: pointer; border-radius: 6px; margin-bottom: 6px; background: ${isSelected ? '#e0f2fe' : '#f8fafc'}; border: 2px solid ${isSelected ? '#3b82f6' : 'transparent'}; transition: all 0.2s;">
                                <input type="checkbox"
                                       value="${v.video_id}"
                                       ${isSelected ? 'checked' : ''}
                                       onchange="toggleDupBulkVideoSelection('${advertiserId}', '${v.video_id}')"
                                       style="width: 18px; height: 18px; flex-shrink: 0;">
                                <div style="width: 60px; height: 45px; flex-shrink: 0; border-radius: 4px; overflow: hidden; background: #1a1a1a; display: flex; align-items: center; justify-content: center;">
                                    ${thumbnailUrl ?
                                        `<img src="${thumbnailUrl}" alt="Video thumbnail" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.parentElement.innerHTML='<span style=\\'color:#64748b;font-size:18px;\\'>🎬</span>';">` :
                                        '<span style="color:#64748b;font-size:18px;">🎬</span>'
                                    }
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-size: 12px; font-weight: 500; color: #1a1a1a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${displayName}</div>
                                    ${v.duration ? `<div style="font-size: 10px; color: #64748b;">${Math.floor(v.duration / 60)}:${String(v.duration % 60).padStart(2, '0')}</div>` : ''}
                                </div>
                                ${v.is_new ? '<span style="background: #22c55e; color: white; font-size: 10px; padding: 2px 6px; border-radius: 4px; flex-shrink: 0;">NEW</span>' : ''}
                            </label>
                        `;
                    }).join('')}
                </div>
                ${selectedVideoCount > 0 ? `
                    <div style="margin-top: 8px;">
                        <div style="font-size: 11px; color: #64748b; margin-bottom: 6px;">Selected Videos:</div>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                            ${selectedVideoIds.map(vid => {
                                const v = videos.find(x => x.video_id === vid);
                                const thumbUrl = v ? (v.video_cover_url || v.cover_image_url || v.preview_url || v.thumbnail_url || v.poster_url || '') : '';
                                const name = v ? (v.file_name || vid).substring(0, 15) : vid.substring(0, 10);
                                return `
                                    <div style="display: flex; align-items: center; gap: 4px; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                        <div style="width: 24px; height: 18px; border-radius: 2px; overflow: hidden; background: #1a1a1a; display: flex; align-items: center; justify-content: center;">
                                            ${thumbUrl ?
                                                '<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:cover;">' :
                                                '<span style="font-size:10px;">🎬</span>'
                                            }
                                        </div>
                                        <span>${name}${name.length < (v?.file_name || vid).length ? '...' : ''}</span>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                ` : ''}
            </div>

            <!-- Landing Page URL -->
            <div class="dup-bulk-config-item full-width">
                <label>Landing Page URL</label>
                <input type="url" id="dup-bulk-url-${advertiserId}"
                       value="${selectedAccount?.landing_page_url || originalLandingUrl}"
                       onchange="updateDupBulkAccountConfig('${advertiserId}', 'landing_page_url', this.value)"
                       placeholder="${originalLandingUrl || 'https://example.com'}">
            </div>

            <!-- Scheduling -->
            <div class="dup-bulk-config-item full-width">
                <label>Schedule</label>
                <div style="display: flex; gap: 16px; margin-bottom: 10px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="radio" name="schedule-${advertiserId}" value="start_now"
                               ${(selectedAccount?.schedule_type || 'start_now') === 'start_now' ? 'checked' : ''}
                               onchange="updateDupBulkSchedule('${advertiserId}', 'start_now')">
                        <span style="font-size: 13px;">Start Now (continuous)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="radio" name="schedule-${advertiserId}" value="scheduled"
                               ${selectedAccount?.schedule_type === 'scheduled' ? 'checked' : ''}
                               onchange="updateDupBulkSchedule('${advertiserId}', 'scheduled')">
                        <span style="font-size: 13px;">Schedule Start & End</span>
                    </label>
                </div>
                <div id="dup-bulk-schedule-dates-${advertiserId}"
                     style="display: ${selectedAccount?.schedule_type === 'scheduled' ? 'flex' : 'none'}; gap: 10px;">
                    <div style="flex: 1;">
                        <label style="font-size: 11px; color: #64748b; display: block; margin-bottom: 4px;">Start Date/Time</label>
                        <input type="datetime-local" id="dup-bulk-start-${advertiserId}"
                               value="${selectedAccount?.schedule_start || ''}"
                               onchange="updateDupBulkAccountConfig('${advertiserId}', 'schedule_start', this.value)"
                               style="width: 100%; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 11px; color: #64748b; display: block; margin-bottom: 4px;">End Date/Time</label>
                        <input type="datetime-local" id="dup-bulk-end-${advertiserId}"
                               value="${selectedAccount?.schedule_end || ''}"
                               onchange="updateDupBulkAccountConfig('${advertiserId}', 'schedule_end', this.value)"
                               style="width: 100%; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;">
                    </div>
                </div>
            </div>

            <!-- Ad Texts (Multiple) -->
            <div class="dup-bulk-config-item full-width">
                <label>Ad Texts <span id="dup-bulk-adtext-count-${advertiserId}" style="font-weight: normal; color: #64748b;">(${(selectedAccount?.ad_texts || getDupBulkOriginalAdTexts(originalAd)).length} texts)</span></label>
                <div id="dup-bulk-adtexts-container-${advertiserId}" style="display: flex; flex-direction: column; gap: 8px;">
                    ${(selectedAccount?.ad_texts || getDupBulkOriginalAdTexts(originalAd)).map((text, idx) => `
                        <div class="dup-bulk-adtext-row" style="display: flex; gap: 8px; align-items: flex-start;">
                            <textarea
                                class="dup-bulk-adtext-input"
                                data-index="${idx}"
                                onchange="updateDupBulkAdText('${advertiserId}', ${idx}, this.value)"
                                placeholder="Ad text ${idx + 1}..."
                                rows="2"
                                style="flex: 1; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; resize: vertical;">${typeof text === 'object' ? (text.ad_text || text.text || '') : text}</textarea>
                            <button type="button" onclick="removeDupBulkAdText('${advertiserId}', ${idx})"
                                    style="padding: 8px 12px; background: #fee2e2; color: #dc2626; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; flex-shrink: 0;"
                                    title="Remove this ad text">&times;</button>
                        </div>
                    `).join('')}
                </div>
                <button type="button" onclick="addDupBulkAdText('${advertiserId}')"
                        style="margin-top: 8px; padding: 6px 12px; background: #f1f5f9; color: #475569; border: 1px dashed #cbd5e1; border-radius: 6px; cursor: pointer; font-size: 12px; width: 100%;">
                    + Add Ad Text
                </button>
                <small style="color: #64748b; font-size: 11px; margin-top: 4px; display: block;">Multiple ad texts allow TikTok to test variations</small>
            </div>
        </div>
    `;
}

// Toggle video list visibility
function toggleDupBulkVideoList(advertiserId) {
    const list = document.getElementById(`dup-bulk-video-list-${advertiserId}`);
    if (list) {
        list.style.display = list.style.display === 'none' ? 'block' : 'none';
    }
}

// Get all ad texts from original ad
function getDupBulkOriginalAdTexts(originalAd) {
    if (!originalAd) return ['Learn More'];

    let adTexts = [];

    // Try different possible formats
    if (originalAd.ad_texts && Array.isArray(originalAd.ad_texts)) {
        adTexts = originalAd.ad_texts.map(t =>
            typeof t === 'object' ? (t.ad_text || t.text || '') : t
        ).filter(t => t);
    } else if (originalAd.ad_text_list && Array.isArray(originalAd.ad_text_list)) {
        adTexts = originalAd.ad_text_list.map(t =>
            typeof t === 'object' ? (t.ad_text || t.text || '') : t
        ).filter(t => t);
    } else if (originalAd.ad_text) {
        adTexts = [originalAd.ad_text];
    }

    return adTexts.length > 0 ? adTexts : ['Learn More'];
}

// Update a specific ad text for an account
function updateDupBulkAdText(advertiserId, index, value) {
    const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!account) return;

    // Initialize ad_texts if not exists
    if (!account.ad_texts) {
        const originalAd = duplicateState.campaignDetails?.ad;
        account.ad_texts = [...getDupBulkOriginalAdTexts(originalAd)];
    }

    if (index >= 0 && index < account.ad_texts.length) {
        account.ad_texts[index] = value;
    }

    // Update count label
    const countSpan = document.getElementById(`dup-bulk-adtext-count-${advertiserId}`);
    if (countSpan) {
        countSpan.textContent = `(${account.ad_texts.length} texts)`;
    }
}

// Add a new ad text field
function addDupBulkAdText(advertiserId) {
    const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!account) return;

    // Initialize ad_texts if not exists
    if (!account.ad_texts) {
        const originalAd = duplicateState.campaignDetails?.ad;
        account.ad_texts = [...getDupBulkOriginalAdTexts(originalAd)];
    }

    // Add new empty ad text
    account.ad_texts.push('');

    // Re-render just the ad texts container
    renderDupBulkAdTextsContainer(advertiserId);
}

// Remove an ad text
function removeDupBulkAdText(advertiserId, index) {
    const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!account || !account.ad_texts) return;

    // Don't remove if only 1 left
    if (account.ad_texts.length <= 1) {
        showToast('At least one ad text is required', 'warning');
        return;
    }

    account.ad_texts.splice(index, 1);

    // Re-render just the ad texts container
    renderDupBulkAdTextsContainer(advertiserId);
}

// Re-render ad texts container without full re-render
function renderDupBulkAdTextsContainer(advertiserId) {
    const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    const container = document.getElementById(`dup-bulk-adtexts-container-${advertiserId}`);
    const countSpan = document.getElementById(`dup-bulk-adtext-count-${advertiserId}`);

    if (!container || !account) return;

    const adTexts = account.ad_texts || ['Learn More'];

    container.innerHTML = adTexts.map((text, idx) => `
        <div class="dup-bulk-adtext-row" style="display: flex; gap: 8px; align-items: flex-start;">
            <textarea
                class="dup-bulk-adtext-input"
                data-index="${idx}"
                onchange="updateDupBulkAdText('${advertiserId}', ${idx}, this.value)"
                placeholder="Ad text ${idx + 1}..."
                rows="2"
                style="flex: 1; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; resize: vertical;">${typeof text === 'object' ? (text.ad_text || text.text || '') : text}</textarea>
            <button type="button" onclick="removeDupBulkAdText('${advertiserId}', ${idx})"
                    style="padding: 8px 12px; background: #fee2e2; color: #dc2626; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; flex-shrink: 0;"
                    title="Remove this ad text">&times;</button>
        </div>
    `).join('');

    if (countSpan) {
        countSpan.textContent = `(${adTexts.length} texts)`;
    }
}

// Update schedule type for an account
function updateDupBulkSchedule(advertiserId, type) {
    const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (account) {
        account.schedule_type = type;
    }

    // Show/hide date fields
    const datesDiv = document.getElementById(`dup-bulk-schedule-dates-${advertiserId}`);
    if (datesDiv) {
        datesDiv.style.display = type === 'scheduled' ? 'flex' : 'none';
    }
}

// Toggle video selection in duplicate bulk
function toggleDupBulkVideoSelection(advertiserId, videoId) {
    const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!account) return;

    // Initialize video_ids array if not exists
    if (!account.video_ids) {
        account.video_ids = [];
    }

    const index = account.video_ids.indexOf(videoId);
    if (index > -1) {
        // Remove if already selected
        account.video_ids.splice(index, 1);
    } else {
        // Add if not selected
        account.video_ids.push(videoId);
    }

    // Update UI WITHOUT re-rendering the entire account list (which would close the dropdown)
    // 1. Update the video item's visual state
    const videoItem = document.querySelector(`#dup-bulk-video-list-${advertiserId} input[value="${videoId}"]`)?.closest('label');
    if (videoItem) {
        const isSelected = account.video_ids.includes(videoId);
        videoItem.style.background = isSelected ? '#e0f2fe' : '#f8fafc';
        videoItem.style.borderColor = isSelected ? '#3b82f6' : 'transparent';
        videoItem.classList.toggle('selected', isSelected);
    }

    // 2. Update the "selected count" label using the specific ID
    const countSpan = document.getElementById(`dup-bulk-video-count-${advertiserId}`);
    if (countSpan) {
        countSpan.textContent = `(${account.video_ids.length} selected)`;
    }

    // 3. Update the selected videos display below the list
    updateDupBulkSelectedVideosDisplay(advertiserId, account.video_ids);

    // 4. Update summary
    updateDupBulkSummary();
}

// Update just the selected videos display without re-rendering entire config
function updateDupBulkSelectedVideosDisplay(advertiserId, selectedVideoIds) {
    const accountAssets = duplicateState.bulkAccountAssets?.[advertiserId];
    const videos = accountAssets?.videos || [];

    // Find the container for selected videos display - it's after the video list
    const videoList = document.getElementById(`dup-bulk-video-list-${advertiserId}`);
    if (!videoList) return;

    // Find or create the selected videos display
    let selectedDisplay = videoList.nextElementSibling;
    if (!selectedDisplay || !selectedDisplay.classList.contains('dup-bulk-selected-display')) {
        // Remove any existing display
        if (selectedDisplay && selectedDisplay.classList.contains('dup-bulk-selected-display')) {
            selectedDisplay.remove();
        }

        // Create new display element
        selectedDisplay = document.createElement('div');
        selectedDisplay.className = 'dup-bulk-selected-display';
        videoList.parentNode.insertBefore(selectedDisplay, videoList.nextSibling);
    }

    if (selectedVideoIds.length === 0) {
        selectedDisplay.innerHTML = '';
        selectedDisplay.style.display = 'none';
        return;
    }

    selectedDisplay.style.display = 'block';
    selectedDisplay.innerHTML = `
        <div style="margin-top: 8px;">
            <div style="font-size: 11px; color: #64748b; margin-bottom: 6px;">Selected Videos:</div>
            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                ${selectedVideoIds.map(vid => {
                    const v = videos.find(x => x.video_id === vid);
                    const thumbUrl = v ? (v.video_cover_url || v.cover_image_url || v.preview_url || v.thumbnail_url || v.poster_url || '') : '';
                    const name = v ? (v.file_name || vid).substring(0, 15) : vid.substring(0, 10);
                    return `
                        <div style="display: flex; align-items: center; gap: 4px; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                            <div style="width: 24px; height: 18px; border-radius: 2px; overflow: hidden; background: #1a1a1a; display: flex; align-items: center; justify-content: center;">
                                ${thumbUrl ?
                                    '<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:cover;">' :
                                    '<span style="font-size:10px;">🎬</span>'
                                }
                            </div>
                            <span>${name}${name.length < (v?.file_name || vid).length ? '...' : ''}</span>
                        </div>
                    `;
                }).join('')}
            </div>
        </div>
    `;
}

// Update bulk account config
function updateDupBulkAccountConfig(advertiserId, field, value) {
    const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (account) {
        account[field] = value;
    }
    updateDupBulkSummary();
}

// Update identity with type info for Smart+ API
function updateDupBulkIdentity(advertiserId, identityId) {
    const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!account) return;

    account.identity_id = identityId;

    // Get identity_type and bc_id from the selected option's data attributes
    const select = document.getElementById(`dup-bulk-identity-${advertiserId}`);
    if (select && select.selectedOptions.length > 0) {
        const option = select.selectedOptions[0];
        account.identity_type = option.dataset.type || 'CUSTOMIZED_USER';
        account.identity_authorized_bc_id = option.dataset.bcId || '';
    } else {
        account.identity_type = 'CUSTOMIZED_USER';
        account.identity_authorized_bc_id = '';
    }

    updateDupBulkSummary();
}

// Update bulk summary
function updateDupBulkSummary() {
    const summary = document.getElementById('dup-bulk-summary');
    const countEl = document.getElementById('dup-bulk-selected-count');
    const totalEl = document.getElementById('dup-bulk-total-campaigns');

    const count = duplicateState.bulkSelectedAccounts.length;
    if (count > 0) {
        summary.style.display = 'block';
        countEl.textContent = count;
        totalEl.textContent = count;
    } else {
        summary.style.display = 'none';
    }

    // Update create button state
    const createBtn = document.getElementById('duplicate-create-btn');
    if (createBtn && duplicateState.mode === 'bulk') {
        createBtn.disabled = count === 0;
    }
}

// Open video upload for bulk duplicate account (supports multiple videos)
function openDupBulkVideoUpload(advertiserId) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'video/*';
    input.multiple = true; // Allow multiple video selection
    input.onchange = async (e) => {
        const files = Array.from(e.target.files);
        if (files.length === 0) return;

        const maxSize = 500 * 1024 * 1024; // 500MB
        const validFiles = files.filter(file => {
            if (!file.type.startsWith('video/')) {
                showToast(`Skipped ${file.name}: Not a video file`, 'warning');
                return false;
            }
            if (file.size > maxSize) {
                showToast(`Skipped ${file.name}: Exceeds 500MB limit`, 'warning');
                return false;
            }
            return true;
        });

        if (validFiles.length === 0) {
            showToast('No valid video files selected', 'error');
            return;
        }

        // Pre-generate thumbnails for all files BEFORE uploading (for instant preview)
        const thumbnailMap = new Map();
        console.log('[DupBulk Upload] Pre-generating thumbnails for', validFiles.length, 'files');
        await Promise.all(validFiles.map(async (file) => {
            const thumbnail = await generateVideoThumbnailSafe(file);
            if (thumbnail) {
                thumbnailMap.set(file, thumbnail);
            }
        }));
        console.log('[DupBulk Upload] Generated', thumbnailMap.size, 'thumbnails');

        // Show progress UI
        const progressContainer = document.getElementById(`dup-bulk-upload-progress-${advertiserId}`);
        const progressBar = document.getElementById(`dup-bulk-upload-bar-${advertiserId}`);
        const progressCount = document.getElementById(`dup-bulk-upload-count-${advertiserId}`);
        const progressList = document.getElementById(`dup-bulk-upload-list-${advertiserId}`);

        if (progressContainer) {
            progressContainer.style.display = 'block';
            progressBar.style.width = '0%';
            progressCount.textContent = `0/${validFiles.length}`;

            // Create list items for each file
            progressList.innerHTML = validFiles.map((file, i) => `
                <div id="dup-upload-item-${advertiserId}-${i}" style="display: flex; align-items: center; gap: 8px; padding: 6px 8px; background: #f1f5f9; border-radius: 4px; margin-bottom: 4px; font-size: 12px;">
                    <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${file.name}</span>
                    <span style="color: #64748b; flex-shrink: 0;">${(file.size / 1024 / 1024).toFixed(1)}MB</span>
                    <span id="dup-upload-status-${advertiserId}-${i}" style="padding: 2px 8px; border-radius: 4px; font-size: 11px; background: #e2e8f0; color: #64748b; flex-shrink: 0;">Pending</span>
                </div>
            `).join('');
        }

        let completed = 0;
        let failed = 0;
        let processing = 0;
        const uploadedVideos = [];

        // Helper function to upload single file
        const uploadSingleFile = async (file, index) => {
            const statusEl = document.getElementById(`dup-upload-status-${advertiserId}-${index}`);

            // Update status to uploading
            if (statusEl) {
                statusEl.textContent = 'Uploading...';
                statusEl.style.background = '#dbeafe';
                statusEl.style.color = '#1d4ed8';
            }

            try {
                const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
                const ext = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.')) : '';
                const baseName = file.name.includes('.') ? file.name.slice(0, file.name.lastIndexOf('.')) : file.name;
                const newFileName = `${baseName}_${timestamp}${ext}`;

                const formData = new FormData();
                formData.append('video', file, newFileName);
                formData.append('target_advertiser_id', advertiserId);

                const response = await fetch('api.php?action=upload_video_to_advertiser', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                    body: formData
                });
                const result = await response.json();

                if (result.success && result.data?.video_id) {
                    // Video uploaded and video_id returned immediately
                    if (statusEl) {
                        statusEl.textContent = '✓ Done';
                        statusEl.style.background = '#dcfce7';
                        statusEl.style.color = '#16a34a';
                    }
                    return {
                        success: true,
                        video: {
                            video_id: result.data.video_id,
                            file_name: newFileName,
                            // Use pre-generated thumbnail for instant preview
                            preview_url: thumbnailMap.get(file) || '',
                            is_new: true
                        }
                    };
                } else if (result.success || (result.message && result.message.toLowerCase().includes('processing'))) {
                    // Video accepted but still processing on TikTok's side
                    if (statusEl) {
                        statusEl.textContent = '⏳ Processing';
                        statusEl.style.background = '#fef3c7';
                        statusEl.style.color = '#d97706';
                    }
                    return {
                        success: true,
                        processing: true,
                        video: {
                            video_id: result.data?.video_id || `processing_${Date.now()}_${index}`,
                            file_name: newFileName,
                            // Use pre-generated thumbnail for instant preview
                            preview_url: thumbnailMap.get(file) || '',
                            is_new: true,
                            is_processing: true
                        }
                    };
                } else {
                    if (statusEl) {
                        statusEl.textContent = '✗ Failed';
                        statusEl.style.background = '#fee2e2';
                        statusEl.style.color = '#dc2626';
                    }
                    console.error(`Upload failed for ${file.name}:`, result);
                    return { success: false };
                }
            } catch (error) {
                if (statusEl) {
                    statusEl.textContent = '✗ Error';
                    statusEl.style.background = '#fee2e2';
                    statusEl.style.color = '#dc2626';
                }
                console.error(`Upload error for ${file.name}:`, error);
                return { success: false };
            }
        };

        // Upload in PARALLEL BATCHES of 2 for reliability
        const BATCH_SIZE = 2;
        const totalBatches = Math.ceil(validFiles.length / BATCH_SIZE);

        for (let batchIndex = 0; batchIndex < totalBatches; batchIndex++) {
            const startIdx = batchIndex * BATCH_SIZE;
            const batch = validFiles.slice(startIdx, startIdx + BATCH_SIZE);

            // Upload entire batch in parallel
            const results = await Promise.all(
                batch.map((file, idx) => uploadSingleFile(file, startIdx + idx))
            );

            // Process batch results
            results.forEach(result => {
                if (result.success) {
                    completed++;
                    if (result.processing) processing++;
                    if (result.video) uploadedVideos.push(result.video);
                } else {
                    failed++;
                }
            });

            // Update progress bar after each batch
            const progress = ((completed + failed) / validFiles.length) * 100;
            if (progressBar) progressBar.style.width = `${progress}%`;
            if (progressCount) progressCount.textContent = `${completed + failed}/${validFiles.length}`;

            // Small delay between batches to prevent server overload
            if (batchIndex < totalBatches - 1) {
                await new Promise(resolve => setTimeout(resolve, 300));
            }
        }

        // Update local state with uploaded videos
        if (uploadedVideos.length > 0) {
            if (!duplicateState.bulkAccountAssets[advertiserId]) {
                duplicateState.bulkAccountAssets[advertiserId] = { videos: [], pixels: [], identities: [] };
            }
            if (!duplicateState.bulkAccountAssets[advertiserId].videos) {
                duplicateState.bulkAccountAssets[advertiserId].videos = [];
            }
            duplicateState.bulkAccountAssets[advertiserId].videos.unshift(...uploadedVideos);

            // Auto-select uploaded videos if none selected
            const account = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
            if (account) {
                const newVideoIds = uploadedVideos.filter(v => !v.is_processing).map(v => v.video_id);
                if (!account.video_ids || account.video_ids.length === 0) {
                    account.video_ids = newVideoIds;
                } else {
                    account.video_ids = [...new Set([...account.video_ids, ...newVideoIds])];
                }
            }

            renderDuplicateBulkAccounts();
        }

        // Show final toast
        if (failed === 0 && processing === 0) {
            showToast(`Successfully uploaded ${completed} video(s)!`, 'success');
        } else if (failed === 0 && processing > 0) {
            showToast(`${completed - processing} uploaded, ${processing} still processing`, 'info');
        } else if (completed > 0) {
            showToast(`Uploaded ${completed}/${validFiles.length} videos (${failed} failed)`, 'warning');
        } else {
            showToast(`Failed to upload all ${validFiles.length} videos`, 'error');
        }

        // Hide progress bar after a delay
        setTimeout(() => {
            if (progressContainer) progressContainer.style.display = 'none';
        }, 3000);

        // Refresh from TikTok to get proper video IDs for processing videos
        if (processing > 0) {
            setTimeout(() => refreshDupBulkVideos(advertiserId), 2000);
        }
    };
    input.click();
}

// Refresh videos for a specific advertiser from TikTok API
async function refreshDupBulkVideos(advertiserId) {
    const btn = document.getElementById(`refresh-btn-${advertiserId}`);
    const icon = btn?.querySelector('.refresh-icon');

    // Show loading state
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
    }
    if (icon) {
        icon.style.animation = 'spin 1s linear infinite';
    }

    showToast('Fetching videos from TikTok...', 'info');

    try {
        // This calls api-smartplus.php which fetches directly from TikTok's /file/video/ad/search/ endpoint
        const result = await apiRequest('get_account_assets', { target_advertiser_id: advertiserId });

        if (result.success && result.data) {
            if (!duplicateState.bulkAccountAssets[advertiserId]) {
                duplicateState.bulkAccountAssets[advertiserId] = {};
            }
            // Store the raw videos from TikTok API (includes video_cover_url for thumbnails)
            duplicateState.bulkAccountAssets[advertiserId].videos = result.data.videos || [];
            duplicateState.bulkAccountAssets[advertiserId].pixels = result.data.pixels || duplicateState.bulkAccountAssets[advertiserId].pixels || [];
            duplicateState.bulkAccountAssets[advertiserId].identities = result.data.identities || duplicateState.bulkAccountAssets[advertiserId].identities || [];

            renderDuplicateBulkAccounts();
            showToast(`Found ${result.data.videos?.length || 0} videos from TikTok`, 'success');
        } else {
            throw new Error(result.message || 'Failed to fetch videos from TikTok');
        }
    } catch (error) {
        showToast('Refresh failed: ' + error.message, 'error');
    } finally {
        // Reset button state
        if (btn) {
            btn.disabled = false;
            btn.style.opacity = '1';
        }
        if (icon) {
            icon.style.animation = '';
        }
    }
}

// Create Identity for Duplicate Bulk Launch
let dupBulkIdentityLogoFile = null;

function openDupBulkIdentityCreate(advertiserId) {
    const account = duplicateState.bulkAccounts?.find(a => a.advertiser_id === advertiserId);
    const accountName = account?.advertiser_name || advertiserId;

    const modalHtml = `
        <div id="dup-bulk-identity-modal" class="modal" style="display: flex; z-index: 10002;">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Create Custom Identity</h3>
                    <span class="modal-close" onclick="closeDupBulkIdentityModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 15px; color: #666;">
                        Creating identity for: <strong>${accountName}</strong>
                    </p>
                    <div class="form-group">
                        <label>Display Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="dup-bulk-identity-name" placeholder="Enter display name (e.g., Your Brand)" maxlength="50">
                        <small style="color: #64748b; font-size: 11px;">This name will appear on your ads</small>
                    </div>
                    <div class="form-group" style="margin-top: 15px;">
                        <label>Profile Logo <span style="color: #94a3b8;">(optional)</span></label>
                        <div class="logo-upload-area" style="border: 2px dashed #e2e8f0; border-radius: 8px; padding: 20px; text-align: center; cursor: pointer;" onclick="document.getElementById('dup-bulk-identity-logo-input').click()">
                            <input type="file" id="dup-bulk-identity-logo-input" accept="image/*" style="display: none;" onchange="previewDupBulkIdentityLogo(this)">
                            <div id="dup-bulk-identity-logo-placeholder">
                                <span style="font-size: 32px;">📷</span>
                                <p style="margin: 10px 0 0; color: #64748b; font-size: 13px;">Click to upload logo</p>
                                <p style="margin: 5px 0 0; color: #94a3b8; font-size: 11px;">Recommended: 100x100px, max 5MB</p>
                            </div>
                            <div id="dup-bulk-identity-logo-preview" style="display: none;">
                                <img id="dup-bulk-identity-logo-img" style="max-width: 100px; max-height: 100px; border-radius: 50%;">
                            </div>
                        </div>
                        <button type="button" id="dup-bulk-identity-logo-remove" style="display: none; margin-top: 10px; padding: 5px 10px; font-size: 12px; background: #ef4444; color: white; border: none; border-radius: 4px; cursor: pointer;" onclick="removeDupBulkIdentityLogo()">Remove Logo</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeDupBulkIdentityModal()">Cancel</button>
                    <button class="btn-primary" onclick="createDupBulkIdentity('${advertiserId}')">Create Identity</button>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if any
    const existing = document.getElementById('dup-bulk-identity-modal');
    if (existing) existing.remove();

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    dupBulkIdentityLogoFile = null;
}

function closeDupBulkIdentityModal() {
    const modal = document.getElementById('dup-bulk-identity-modal');
    if (modal) modal.remove();
    dupBulkIdentityLogoFile = null;
}

function previewDupBulkIdentityLogo(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];

        if (!file.type.startsWith('image/')) {
            showToast('Please select an image file', 'error');
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            showToast('Image too large. Maximum size is 5MB', 'error');
            return;
        }

        dupBulkIdentityLogoFile = file;

        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('dup-bulk-identity-logo-img').src = e.target.result;
            document.getElementById('dup-bulk-identity-logo-preview').style.display = 'block';
            document.getElementById('dup-bulk-identity-logo-placeholder').style.display = 'none';
            document.getElementById('dup-bulk-identity-logo-remove').style.display = 'inline-block';
        };
        reader.readAsDataURL(file);
    }
}

function removeDupBulkIdentityLogo() {
    dupBulkIdentityLogoFile = null;
    document.getElementById('dup-bulk-identity-logo-input').value = '';
    document.getElementById('dup-bulk-identity-logo-preview').style.display = 'none';
    document.getElementById('dup-bulk-identity-logo-placeholder').style.display = 'block';
    document.getElementById('dup-bulk-identity-logo-remove').style.display = 'none';
}

async function createDupBulkIdentity(advertiserId) {
    const displayName = document.getElementById('dup-bulk-identity-name').value.trim();
    if (!displayName) {
        showToast('Please enter a display name', 'error');
        return;
    }

    showToast('Creating identity...', 'info');

    try {
        let profileImageId = null;

        // Upload logo if provided
        if (dupBulkIdentityLogoFile) {
            showToast('Uploading logo...', 'info');

            const formData = new FormData();
            formData.append('image', dupBulkIdentityLogoFile);
            formData.append('advertiser_id', advertiserId);

            const uploadResponse = await fetch('api.php?action=upload_image', {
                method: 'POST',
                headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                body: formData
            });

            const uploadResult = await uploadResponse.json();

            if (uploadResult.success && uploadResult.data?.image_id) {
                profileImageId = uploadResult.data.image_id;
            }
        }

        const params = {
            target_advertiser_id: advertiserId,
            display_name: displayName
        };
        if (profileImageId) {
            params.profile_image_id = profileImageId;
        }

        const result = await apiRequest('create_identity_for_account', params);

        if (result.success && result.identity_id) {
            closeDupBulkIdentityModal();
            showToast('Identity created successfully!', 'success');

            // Add new identity to assets
            if (!duplicateState.bulkAccountAssets[advertiserId]) {
                duplicateState.bulkAccountAssets[advertiserId] = { identities: [], pixels: [], videos: [] };
            }
            if (!duplicateState.bulkAccountAssets[advertiserId].identities) {
                duplicateState.bulkAccountAssets[advertiserId].identities = [];
            }

            duplicateState.bulkAccountAssets[advertiserId].identities.unshift({
                identity_id: result.identity_id,
                display_name: displayName,
                identity_name: displayName,
                identity_type: 'CUSTOMIZED_USER'
            });

            // Auto-select the new identity
            const selectedAccount = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
            if (selectedAccount) {
                selectedAccount.identity_id = result.identity_id;
            }

            // Re-render to show new identity
            renderDuplicateBulkAccounts();
        } else {
            showToast(result.message || 'Failed to create identity', 'error');
        }
    } catch (error) {
        showToast('Error creating identity: ' + error.message, 'error');
    }
}

// Portfolio creation for bulk launch
function openDupBulkPortfolioCreate(advertiserId) {
    const account = duplicateState.bulkAccounts?.find(a => a.advertiser_id === advertiserId);
    const accountName = account?.advertiser_name || advertiserId;

    const modalHtml = `
        <div id="dup-bulk-portfolio-modal" class="modal" style="display: flex; z-index: 10002;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>Create CTA Portfolio</h3>
                    <span class="modal-close" onclick="closeDupBulkPortfolioModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 15px; color: #666;">
                        Creating portfolio for: <strong>${accountName}</strong>
                    </p>
                    <div class="form-group">
                        <label>Portfolio Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="dup-bulk-portfolio-name" placeholder="Enter portfolio name" maxlength="100">
                    </div>
                    <div class="form-group" style="margin-top: 15px;">
                        <label>Call to Action <span style="color: #ef4444;">*</span></label>
                        <select id="dup-bulk-portfolio-cta" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <option value="LEARN_MORE">Learn More</option>
                            <option value="SHOP_NOW">Shop Now</option>
                            <option value="SIGN_UP">Sign Up</option>
                            <option value="CONTACT_US">Contact Us</option>
                            <option value="DOWNLOAD">Download</option>
                            <option value="BOOK_NOW">Book Now</option>
                            <option value="GET_QUOTE">Get Quote</option>
                            <option value="APPLY_NOW">Apply Now</option>
                            <option value="SUBSCRIBE">Subscribe</option>
                            <option value="ORDER_NOW">Order Now</option>
                            <option value="GET_SHOWTIMES">Get Showtimes</option>
                            <option value="LISTEN_NOW">Listen Now</option>
                            <option value="WATCH_NOW">Watch Now</option>
                            <option value="PLAY_GAME">Play Game</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-top: 15px;">
                        <label>Landing Page URL <span style="color: #ef4444;">*</span></label>
                        <input type="url" id="dup-bulk-portfolio-url" placeholder="https://example.com">
                    </div>
                </div>
                <div class="modal-footer" style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn-secondary" onclick="closeDupBulkPortfolioModal()">Cancel</button>
                    <button class="btn-primary" onclick="createDupBulkPortfolio('${advertiserId}')" style="background: #8b5cf6;">Create Portfolio</button>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if any
    const existing = document.getElementById('dup-bulk-portfolio-modal');
    if (existing) existing.remove();

    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeDupBulkPortfolioModal() {
    const modal = document.getElementById('dup-bulk-portfolio-modal');
    if (modal) modal.remove();
}

async function createDupBulkPortfolio(advertiserId) {
    const portfolioName = document.getElementById('dup-bulk-portfolio-name').value.trim();
    const cta = document.getElementById('dup-bulk-portfolio-cta').value;
    const landingUrl = document.getElementById('dup-bulk-portfolio-url').value.trim();

    if (!portfolioName) {
        showToast('Please enter a portfolio name', 'error');
        return;
    }
    if (!landingUrl) {
        showToast('Please enter a landing page URL', 'error');
        return;
    }
    if (!landingUrl.startsWith('http://') && !landingUrl.startsWith('https://')) {
        showToast('Please enter a valid URL starting with http:// or https://', 'error');
        return;
    }

    showToast('Creating portfolio...', 'info');

    try {
        const response = await fetch(`api.php?action=create_portfolio&advertiser_id=${advertiserId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({
                portfolio_name: portfolioName,
                call_to_action: cta,
                landing_page_url: landingUrl
            })
        });

        const result = await response.json();

        if (result.success && result.data?.portfolio_id) {
            showToast('Portfolio created successfully!', 'success');
            closeDupBulkPortfolioModal();

            // Add new portfolio to assets
            if (!duplicateState.bulkAccountAssets[advertiserId]) {
                duplicateState.bulkAccountAssets[advertiserId] = { identities: [], pixels: [], videos: [], portfolios: [] };
            }
            if (!duplicateState.bulkAccountAssets[advertiserId].portfolios) {
                duplicateState.bulkAccountAssets[advertiserId].portfolios = [];
            }

            duplicateState.bulkAccountAssets[advertiserId].portfolios.unshift({
                portfolio_id: result.data.portfolio_id,
                portfolio_name: portfolioName,
                call_to_action: cta,
                landing_page_url: landingUrl
            });

            // Auto-select the new portfolio
            const selectedAccount = duplicateState.bulkSelectedAccounts.find(a => a.advertiser_id === advertiserId);
            if (selectedAccount) {
                selectedAccount.portfolio_id = result.data.portfolio_id;
            }

            // Re-render to show new portfolio
            renderDuplicateBulkAccounts();
        } else {
            showToast(result.message || 'Failed to create portfolio', 'error');
        }
    } catch (error) {
        showToast('Error creating portfolio: ' + error.message, 'error');
    }
}

// Execute bulk duplicate to multiple accounts
async function executeBulkDuplicateCampaign() {
    const selectedAccounts = duplicateState.bulkSelectedAccounts;

    if (selectedAccounts.length === 0) {
        showToast('Please select at least one account', 'error');
        return;
    }

    // Validate each selected account
    for (const account of selectedAccounts) {
        if (!account.campaign_name || account.campaign_name.trim() === '') {
            showToast(`Please enter a campaign name for ${account.advertiser_name}`, 'error');
            return;
        }
        if (!account.budget || account.budget < 20) {
            showToast(`Budget must be at least $20 for ${account.advertiser_name}`, 'error');
            return;
        }
        if (!account.pixel_id) {
            showToast(`Please select a pixel for ${account.advertiser_name}`, 'error');
            return;
        }
        if (!account.identity_id) {
            showToast(`Please select an identity for ${account.advertiser_name}`, 'error');
            return;
        }
        // Support both old video_id and new video_ids array
        const hasVideos = (account.video_ids && account.video_ids.length > 0) || account.video_id;
        if (!hasVideos) {
            showToast(`Please select at least one video for ${account.advertiser_name}`, 'error');
            return;
        }
    }

    duplicateState.isProcessing = true;

    // Hide sections and show progress
    document.getElementById('duplicate-mode-section').style.display = 'none';
    document.getElementById('duplicate-bulk-section').style.display = 'none';
    document.getElementById('duplicate-progress-section').style.display = 'block';

    // Update footer
    const footer = document.getElementById('duplicate-modal-footer');
    footer.innerHTML = `<button class="btn-secondary" disabled>Please wait...</button>`;

    const progressBar = document.getElementById('duplicate-progress-bar');
    const progressText = document.getElementById('duplicate-progress-text');
    const progressLog = document.getElementById('duplicate-progress-log');

    progressLog.innerHTML = '';
    progressText.textContent = `0 / ${selectedAccounts.length}`;
    progressBar.style.width = '0%';

    const { campaign, adgroup, ad } = duplicateState.campaignDetails;
    let successCount = 0;
    let failCount = 0;

    // Process each selected account
    for (let i = 0; i < selectedAccounts.length; i++) {
        const account = selectedAccounts[i];
        const progress = ((i + 1) / selectedAccounts.length) * 100;
        progressBar.style.width = `${progress}%`;
        progressText.textContent = `${i + 1} / ${selectedAccounts.length}`;

        progressLog.innerHTML += `<div class="progress-item pending">🔄 Creating campaign for ${account.advertiser_name}...</div>`;
        progressLog.scrollTop = progressLog.scrollHeight;

        try {
            // Get landing page URL
            let landingUrl = account.landing_page_url;
            if (!landingUrl) {
                landingUrl = ad?.landing_page_url || '';
                if (!landingUrl && ad?.landing_page_url_list?.length > 0) {
                    landingUrl = typeof ad.landing_page_url_list[0] === 'object'
                        ? ad.landing_page_url_list[0].landing_page_url || ''
                        : ad.landing_page_url_list[0] || '';
                }
            }

            // Support both video_ids array and legacy video_id
            const videoIds = account.video_ids && account.video_ids.length > 0
                ? account.video_ids
                : (account.video_id ? [account.video_id] : []);

            // Get ad texts - use account's ad_texts array or fall back to original
            const adTextsToSend = account.ad_texts && account.ad_texts.length > 0
                ? account.ad_texts.filter(t => t && t.trim()) // Filter out empty texts
                : getDupBulkOriginalAdTexts(ad);

            // Prepare Smart+ duplicate request
            const duplicateData = {
                _advertiser_id: account.advertiser_id, // Target account (Smart+ API override)
                campaign_name: account.campaign_name,
                budget: account.budget,
                pixel_id: account.pixel_id,
                identity_id: account.identity_id,
                identity_type: account.identity_type || 'CUSTOMIZED_USER',
                identity_authorized_bc_id: account.identity_authorized_bc_id || '',
                portfolio_id: account.portfolio_id || '', // CTA Portfolio (auto-creates if empty)
                video_ids: videoIds,
                landing_page_url: landingUrl,
                // Scheduling
                schedule_type: account.schedule_type || 'start_now',
                schedule_start: account.schedule_start || null,
                schedule_end: account.schedule_end || null,
                // Carry over dayparting from original campaign
                dayparting: duplicateState.originalSchedule?.dayparting || null,
                // Ad data
                ad_name: ad?.ad_name || account.campaign_name + ' - Ad',
                ad_texts: adTextsToSend
            };

            addLog('request', `Bulk duplicate (Smart+) to ${account.advertiser_id}`, duplicateData);

            const result = await apiRequest('bulk_duplicate_smartplus', duplicateData); // Use Smart+ API

            if (result.success) {
                successCount++;
                progressLog.lastChild.className = 'progress-item success';
                progressLog.lastChild.innerHTML = `✅ ${account.advertiser_name}: Campaign created successfully`;
                addLog('success', `Campaign created in ${account.advertiser_name}`);
            } else {
                throw new Error(result.message || 'Failed to create campaign');
            }
        } catch (error) {
            failCount++;
            progressLog.lastChild.className = 'progress-item error';
            progressLog.lastChild.innerHTML = `❌ ${account.advertiser_name}: ${error.message}`;
            addLog('error', `Failed for ${account.advertiser_name}: ${error.message}`);
        }

        progressLog.scrollTop = progressLog.scrollHeight;

        // Small delay between operations
        if (i < selectedAccounts.length - 1) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }

    // Show completion
    duplicateState.isProcessing = false;
    document.getElementById('duplicate-progress-section').style.display = 'none';
    document.getElementById('duplicate-success-section').style.display = 'block';

    const successSection = document.getElementById('duplicate-success-section');
    successSection.innerHTML = `
        <div style="text-align: center; padding: 20px;">
            <div style="font-size: 48px; margin-bottom: 15px;">${failCount === 0 ? '🎉' : '⚠️'}</div>
            <h4 style="margin-bottom: 10px;">${failCount === 0 ? 'Bulk Launch Complete!' : 'Bulk Launch Completed with Errors'}</h4>
            <p style="color: #64748b;">
                Successfully created <strong style="color: #22c55e;">${successCount}</strong> campaigns
                ${failCount > 0 ? ` • <strong style="color: #ef4444;">${failCount}</strong> failed` : ''}
            </p>
        </div>
    `;

    // Update footer
    footer.innerHTML = `
        <button class="btn-secondary" onclick="closeDuplicateCampaignModal()">Close</button>
        <button class="btn-primary" onclick="refreshCampaignsList()">🔄 Refresh Campaigns</button>
    `;
}

// Render current videos when duplicate modal opens
function initDuplicateVideosDisplay() {
    // Clear any previous changed videos
    duplicateState.changedVideos = null;
    // Render original videos
    renderDuplicateCurrentVideos();
}

// Toggle duplicate schedule type UI
function toggleDupScheduleType() {
    const scheduleType = document.querySelector('input[name="dup_schedule_type"]:checked')?.value || 'continuous';
    const startOnlyContainer = document.getElementById('dup-schedule-start-only-container');
    const dateTimeContainer = document.getElementById('dup-schedule-datetime-container');

    // Hide both containers first
    if (startOnlyContainer) startOnlyContainer.style.display = 'none';
    if (dateTimeContainer) dateTimeContainer.style.display = 'none';

    // Show appropriate container
    if (scheduleType === 'scheduled_start_only' && startOnlyContainer) {
        startOnlyContainer.style.display = 'block';
        const startInput = document.getElementById('dup-schedule-start-only-datetime');
        if (startInput) {
            const minTime = getESTNow();
            minTime.setMinutes(minTime.getMinutes() + 7);
            startInput.min = formatDateTimeLocal(minTime);

            if (!startInput.value) {
                const estNow = getESTNow();
                estNow.setHours(estNow.getHours() + 1);
                estNow.setMinutes(0, 0, 0);
                startInput.value = formatDateTimeLocal(estNow);
            }
        }
    } else if (scheduleType === 'scheduled' && dateTimeContainer) {
        dateTimeContainer.style.display = 'block';
        const startInput = document.getElementById('dup-schedule-start-datetime');
        const endInput = document.getElementById('dup-schedule-end-datetime');
        if (startInput) {
            const minTime = getESTNow();
            minTime.setMinutes(minTime.getMinutes() + 7);
            startInput.min = formatDateTimeLocal(minTime);

            if (!startInput.value) {
                const estNow = getESTNow();
                estNow.setHours(estNow.getHours() + 1);
                estNow.setMinutes(0, 0, 0);
                startInput.value = formatDateTimeLocal(estNow);
            }
        }
        if (endInput && !endInput.value) {
            const endDate = getESTNow();
            endDate.setDate(endDate.getDate() + 7);
            endDate.setHours(23, 59, 0, 0);
            endInput.value = formatDateTimeLocal(endDate);
        }
    }

    // Update option borders
    document.querySelectorAll('.dup-schedule-option').forEach(opt => {
        const radio = opt.querySelector('input[type="radio"]');
        opt.style.borderColor = radio && radio.checked ? '#1a1a1a' : '#e2e8f0';
    });
}

// Get schedule data for duplicate campaign
function getDupScheduleData() {
    const scheduleType = document.querySelector('input[name="dup_schedule_type"]:checked')?.value || 'continuous';

    // Format datetime for TikTok API
    // Format datetime for TikTok API — user enters EST time
    const formatScheduleTime = (dateTimeLocalValue) => {
        if (!dateTimeLocalValue) return null;
        const [datePart, timePart] = dateTimeLocalValue.split('T');
        const result = `${datePart} ${timePart}:00`;
        console.log(`[Dup Schedule] Formatted for API (EST): ${dateTimeLocalValue} -> ${result}`);
        return result;
    };

    if (scheduleType === 'continuous') {
        return { schedule_type: 'SCHEDULE_FROM_NOW' };
    }

    if (scheduleType === 'scheduled_start_only') {
        const startDateTime = document.getElementById('dup-schedule-start-only-datetime')?.value;

        if (!startDateTime) {
            return { schedule_type: 'SCHEDULE_FROM_NOW' };
        }

        return {
            schedule_type: 'SCHEDULE_FROM_NOW',
            schedule_start_time: formatScheduleTime(startDateTime),
        };
    }

    // scheduled (start and end)
    const startDateTime = document.getElementById('dup-schedule-start-datetime')?.value;
    const endDateTime = document.getElementById('dup-schedule-end-datetime')?.value;

    if (!startDateTime || !endDateTime) {
        return { schedule_type: 'SCHEDULE_FROM_NOW' };
    }

    return {
        schedule_type: 'SCHEDULE_START_END',
        schedule_start_time: formatScheduleTime(startDateTime),
        schedule_end_time: formatScheduleTime(endDateTime),
    };
}

// Validate duplicate schedule dates
function validateDupScheduleDates() {
    const scheduleType = document.querySelector('input[name="dup_schedule_type"]:checked')?.value || 'continuous';

    if (scheduleType === 'continuous') {
        return { valid: true };
    }

    const now = getESTNow();

    if (scheduleType === 'scheduled_start_only') {
        const startDateTime = document.getElementById('dup-schedule-start-only-datetime')?.value;
        if (!startDateTime) {
            return { valid: false, message: 'Please select a start date and time' };
        }
        if (new Date(startDateTime) < now) {
            return { valid: false, message: 'Start time must be in the future' };
        }
        return { valid: true };
    }

    // scheduled (start and end)
    const startDateTime = document.getElementById('dup-schedule-start-datetime')?.value;
    const endDateTime = document.getElementById('dup-schedule-end-datetime')?.value;

    if (!startDateTime) {
        return { valid: false, message: 'Please select a start date and time' };
    }
    if (!endDateTime) {
        return { valid: false, message: 'Please select an end date and time' };
    }

    const startDate = new Date(startDateTime);
    const endDate = new Date(endDateTime);

    if (startDate < now) {
        return { valid: false, message: 'Start time must be in the future' };
    }
    if (endDate <= startDate) {
        return { valid: false, message: 'End time must be after start time' };
    }

    return { valid: true };
}

// Adjust duplicate count with +/- buttons
function adjustDuplicateCount(delta) {
    // Determine which input to use based on current mode
    const inputId = duplicateState.mode === 'edit' ? 'duplicate-edit-copy-count' : 'duplicate-copy-count';
    const input = document.getElementById(inputId);
    let value = parseInt(input.value) || 1;
    value = Math.max(1, Math.min(20, value + delta));
    input.value = value;

    // Sync both inputs
    document.getElementById('duplicate-copy-count').value = value;
    document.getElementById('duplicate-edit-copy-count').value = value;

    updateDuplicatePreviewList();
}

// Update the preview list showing campaign names
function updateDuplicatePreviewList() {
    const mode = duplicateState.mode || 'same';
    const countInput = mode === 'edit' ? 'duplicate-edit-copy-count' : 'duplicate-copy-count';
    const count = parseInt(document.getElementById(countInput).value) || 1;

    let baseName;
    if (mode === 'edit') {
        // Use edited name for preview
        baseName = document.getElementById('dup-edit-campaign-name').value || duplicateState.campaignName || 'Campaign';
    } else {
        // Use original name for "same" mode
        baseName = duplicateState.campaignName || 'Campaign';
    }

    const previewList = document.getElementById('duplicate-preview-list');

    let html = '';
    for (let i = 1; i <= Math.min(count, 20); i++) {
        const newName = count === 1 && mode === 'edit' ? baseName : `${baseName} (${i})`;
        html += `
            <div class="preview-item">
                <span class="preview-number">${i}</span>
                <span class="preview-name">${newName}</span>
            </div>
        `;
    }

    previewList.innerHTML = html;
}

// Execute the duplicate operation
async function executeDuplicateCampaign() {
    if (!duplicateState.campaignDetails) {
        showToast('Campaign details not loaded', 'error');
        return;
    }

    const mode = duplicateState.mode || 'same';

    // Handle bulk mode separately
    if (mode === 'bulk') {
        return executeBulkDuplicateCampaign();
    }

    const countInput = mode === 'edit' ? 'duplicate-edit-copy-count' : 'duplicate-copy-count';
    const count = parseInt(document.getElementById(countInput).value) || 1;

    if (count < 1 || count > 20) {
        showToast('Please enter a valid number between 1 and 20', 'error');
        return;
    }

    // Get edited values if in edit mode
    let editedValues = null;
    if (mode === 'edit') {
        const editedName = document.getElementById('dup-edit-campaign-name').value.trim();
        const editedBudget = parseFloat(document.getElementById('dup-edit-budget').value) || 50;
        const editedLandingUrl = document.getElementById('dup-edit-landing-url').value.trim();
        const editedAdText = document.getElementById('dup-edit-ad-text').value.trim();

        // Validation for edit mode
        if (!editedName) {
            showToast('Please enter a campaign name', 'error');
            return;
        }
        if (editedBudget < 20) {
            showToast('Budget must be at least $20', 'error');
            return;
        }
        if (!editedLandingUrl) {
            showToast('Please enter a landing page URL', 'error');
            return;
        }

        // Validate schedule dates in edit mode
        const scheduleValidation = validateDupScheduleDates();
        if (!scheduleValidation.valid) {
            showToast(scheduleValidation.message, 'error');
            return;
        }

        // Get schedule data
        const scheduleData = getDupScheduleData();

        editedValues = {
            campaignName: editedName,
            budget: editedBudget,
            landingPageUrl: editedLandingUrl,
            adTexts: editedAdText ? editedAdText.split('\n').filter(t => t.trim()) : [],
            schedule: scheduleData
        };
    }

    duplicateState.isProcessing = true;

    // Hide details and mode section, show progress
    document.getElementById('duplicate-mode-section').style.display = 'none';
    document.getElementById('duplicate-details-section').style.display = 'none';
    document.getElementById('duplicate-progress-section').style.display = 'block';

    // Update footer
    const footer = document.getElementById('duplicate-modal-footer');
    footer.innerHTML = `
        <button class="btn-secondary" disabled>Please wait...</button>
    `;

    const progressBar = document.getElementById('duplicate-progress-bar');
    const progressText = document.getElementById('duplicate-progress-text');
    const progressLog = document.getElementById('duplicate-progress-log');

    progressLog.innerHTML = '';
    progressText.textContent = `0 / ${count}`;
    progressBar.style.width = '0%';

    const { campaign, adgroup, ad } = duplicateState.campaignDetails;

    // Use edited name if in edit mode, otherwise original name
    const baseName = editedValues ? editedValues.campaignName : campaign.campaign_name;
    const budgetToUse = editedValues ? editedValues.budget : campaign.budget;
    const results = [];

    // CRITICAL DEBUG: Log what we're working with
    console.log('=== DUPLICATE CAMPAIGN DEBUG ===');
    console.log('duplicateState.campaignDetails:', duplicateState.campaignDetails);
    console.log('ad object:', ad);
    console.log('ad.call_to_action_id:', ad?.call_to_action_id);
    console.log('ad.video_id:', ad?.video_id);
    console.log('ad.video_ids:', ad?.video_ids);
    console.log('ad.identity_id:', ad?.identity_id);
    console.log('default_cta_portfolio:', duplicateState.campaignDetails?.default_cta_portfolio);
    console.log('=== END DEBUG ===');

    addLog('info', `Starting duplication: ${count} copies of "${baseName}"`);

    for (let i = 1; i <= count; i++) {
        // If count is 1 and in edit mode, use exact name entered by user (no suffix)
        // Otherwise, append number suffix
        const newName = (count === 1 && mode === 'edit') ? baseName : `${baseName} (${i})`;

        // Update progress
        progressText.textContent = `${i} / ${count}`;
        progressBar.style.width = `${(i / count) * 100}%`;

        // Add pending log entry
        const logId = `dup-log-${i}`;
        progressLog.innerHTML += `
            <div class="progress-log-item pending" id="${logId}">
                <span>⏳</span>
                <span>Creating "${newName}"...</span>
            </div>
        `;
        progressLog.scrollTop = progressLog.scrollHeight;

        try {
            // Step 1: Create Campaign (using correct Smart+ API action)
            addLog('info', `Creating campaign: ${newName}`);
            const campaignResult = await apiRequest('create_smartplus_campaign', {
                campaign_name: newName,
                budget: budgetToUse
            });

            if (!campaignResult.success) {
                throw new Error(campaignResult.message || 'Failed to create campaign');
            }

            const newCampaignId = campaignResult.campaign_id;
            addLog('success', `Campaign created: ${newCampaignId}`);

            // Step 2: Create Ad Group (if original had one)
            let newAdGroupId = null;
            if (adgroup) {
                addLog('info', `Creating ad group for campaign ${newCampaignId}`);

                // Build ad group params
                const adgroupParams = {
                    campaign_id: newCampaignId,
                    adgroup_name: newName,
                    pixel_id: adgroup.pixel_id,
                    optimization_event: adgroup.optimization_event,
                    location_ids: adgroup.location_ids || [],
                    age_groups: adgroup.age_groups || []
                };

                // Determine schedule to use: edit mode uses form values, same mode uses original
                let scheduleToUse = null;
                let timesAlreadyUTC = false;
                if (editedValues && editedValues.schedule) {
                    // Edit mode: use user-customized schedule from form (times in EST)
                    scheduleToUse = editedValues.schedule;
                } else if (mode === 'same' && duplicateState.originalSchedule) {
                    // Same mode: preserve original campaign schedule (times already in UTC from TikTok API)
                    scheduleToUse = duplicateState.originalSchedule;
                    timesAlreadyUTC = true;
                }

                if (scheduleToUse) {
                    adgroupParams.schedule_type = scheduleToUse.schedule_type;
                    if (scheduleToUse.schedule_start_time) {
                        adgroupParams.schedule_start_time = scheduleToUse.schedule_start_time;
                    }
                    if (scheduleToUse.schedule_end_time) {
                        adgroupParams.schedule_end_time = scheduleToUse.schedule_end_time;
                    }
                    if (scheduleToUse.schedule_timezone) {
                        adgroupParams.schedule_timezone = scheduleToUse.schedule_timezone;
                    }
                    if (scheduleToUse.dayparting) {
                        adgroupParams.dayparting = scheduleToUse.dayparting;
                    }
                    // Tell backend times are already in UTC (from TikTok API response)
                    if (timesAlreadyUTC) {
                        adgroupParams.times_already_utc = true;
                    }
                    addLog('info', `Schedule: ${scheduleToUse.schedule_type}${scheduleToUse.schedule_start_time ? ' from ' + scheduleToUse.schedule_start_time : ''}${scheduleToUse.dayparting ? ' (with dayparting)' : ''}${timesAlreadyUTC ? ' [UTC]' : ' [EST→UTC]'}`);
                }

                const adgroupResult = await apiRequest('create_smartplus_adgroup', adgroupParams);

                if (!adgroupResult.success) {
                    throw new Error(adgroupResult.message || 'Failed to create ad group');
                }

                newAdGroupId = adgroupResult.adgroup_id;
                addLog('success', `Ad group created: ${newAdGroupId}`);
            }

            // Step 3: Create Ad (if original had one)
            console.log('Ad data from get_campaign_details:', JSON.stringify(ad, null, 2));
            addLog('info', `Ad data received: ${ad ? 'Yes' : 'No'}, AdGroup ID: ${newAdGroupId}`);

            if (ad && newAdGroupId) {
                addLog('info', `Creating ad for ad group ${newAdGroupId}`);

                // Build creatives array from creative_list (Smart+ format) or video_ids
                let creatives = [];

                // Check if we have creative_list from Smart+ ad/get response
                if (ad.creative_list && ad.creative_list.length > 0) {
                    ad.creative_list.forEach(item => {
                        if (item.creative_info && item.creative_info.video_info) {
                            const creative = {
                                video_id: item.creative_info.video_info.video_id
                            };
                            // Also extract image_id (cover image) if available
                            if (item.creative_info.image_info && item.creative_info.image_info.length > 0) {
                                const imageInfo = item.creative_info.image_info[0];
                                creative.image_id = imageInfo.web_uri || imageInfo.image_id || null;
                            }
                            creatives.push(creative);
                        }
                    });
                    console.log('Extracted creatives from creative_list:', creatives);
                } else if (ad.video_ids && ad.video_ids.length > 0) {
                    // Use video_ids array - try to match with image_ids if available
                    creatives = ad.video_ids.map((vid, index) => {
                        const creative = { video_id: vid };
                        if (ad.image_ids && ad.image_ids[index]) {
                            creative.image_id = ad.image_ids[index];
                        }
                        return creative;
                    });
                    console.log('Extracted creatives from video_ids:', creatives);
                }

                // Log creative extraction details
                console.log('Final creatives:', JSON.stringify(creatives, null, 2));
                console.log('Ad structure:', {
                    has_creative_list: !!(ad.creative_list?.length),
                    creative_list_count: ad.creative_list?.length || 0,
                    video_ids: ad.video_ids,
                    image_ids: ad.image_ids
                });

                // Check if user changed videos in duplicate modal (edit or same mode)
                if ((duplicateState.mode === 'edit' || duplicateState.mode === 'same') && duplicateState.changedVideos && duplicateState.changedVideos.length > 0) {
                    // Use the changed videos instead of original
                    creatives = duplicateState.changedVideos.map(v => ({
                        video_id: v.video_id || v.id
                    }));
                    addLog('info', `Using ${creatives.length} changed video(s) for duplicate`);
                    console.log('Using changed videos:', creatives);
                }

                // Validate we have at least one creative
                if (creatives.length === 0) {
                    addLog('warning', 'No video creatives found in original ad');
                    addLog('error', `Ad structure: creative_list=${ad.creative_list?.length || 0}, video_ids=${JSON.stringify(ad.video_ids)}`);
                    throw new Error('No video creatives found in the original ad to duplicate');
                }

                addLog('info', `Found ${creatives.length} creative(s) to duplicate`);

                // Get landing page URL (now properly returned from /smart_plus/ad/get/)
                let landingPageUrl = ad.landing_page_url;
                let pageId = ad.page_id;

                // Fallback: Try landing_page_url_list if landing_page_url not directly available
                if (!landingPageUrl && ad.landing_page_url_list && ad.landing_page_url_list.length > 0) {
                    if (typeof ad.landing_page_url_list[0] === 'object') {
                        landingPageUrl = ad.landing_page_url_list[0].landing_page_url;
                    } else {
                        landingPageUrl = ad.landing_page_url_list[0];
                    }
                }

                // Get call_to_action_id (now properly returned from /smart_plus/ad/get/)
                let callToActionId = ad.call_to_action_id;

                // Fallback: Check ad_configuration
                if (!callToActionId && ad.ad_configuration?.call_to_action_id) {
                    callToActionId = ad.ad_configuration.call_to_action_id;
                }

                // Fallback: Check default_cta_portfolio from backend
                if (!callToActionId && duplicateState.campaignDetails?.default_cta_portfolio?.id) {
                    callToActionId = duplicateState.campaignDetails.default_cta_portfolio.id;
                }

                // Override with edited values if in edit mode
                if (editedValues) {
                    landingPageUrl = editedValues.landingPageUrl;
                    console.log('Using edited landing page URL:', landingPageUrl);
                }

                console.log('Extracted from Smart+ API:', {
                    landing_page_url: landingPageUrl,
                    call_to_action_id: callToActionId,
                    page_id: pageId,
                    identity_id: ad.identity_id,
                    mode: mode,
                    editedValues: editedValues
                });

                // Validate call_to_action_id
                if (!callToActionId) {
                    addLog('warning', 'No CTA Portfolio ID found');
                    throw new Error('No CTA Portfolio ID available. Please check the original ad has a CTA configured.');
                }

                // Validate destination
                if (!landingPageUrl && !pageId) {
                    addLog('warning', 'No landing page URL found');
                    throw new Error('No landing page URL found in the original ad.');
                }

                // Use edited ad texts if in edit mode, otherwise use original
                const adTextsToUse = editedValues && editedValues.adTexts.length > 0
                    ? editedValues.adTexts
                    : (ad.ad_texts || []);

                // Resolve identity - check multiple fallback sources
                let identityId = ad.identity_id;
                let identityType = ad.identity_type || 'CUSTOMIZED_USER';
                let identityBcId = ad.identity_authorized_bc_id || '';

                // Fallback: check ad_configuration
                if (!identityId && ad.ad_configuration?.identity_id) {
                    identityId = ad.ad_configuration.identity_id;
                    identityType = ad.ad_configuration.identity_type || identityType;
                    identityBcId = ad.ad_configuration.identity_authorized_bc_id || ad.ad_configuration.identity_bc_id || identityBcId;
                    addLog('info', `Using identity from ad_configuration: ${identityId}`);
                }

                // Fallback: check creative_list for identity
                if (!identityId && ad.creative_list?.length > 0) {
                    for (const creative of ad.creative_list) {
                        const cInfo = creative.creative_info || {};
                        if (cInfo.identity_id) {
                            identityId = cInfo.identity_id;
                            identityType = cInfo.identity_type || identityType;
                            identityBcId = cInfo.identity_authorized_bc_id || cInfo.identity_bc_id || identityBcId;
                            addLog('info', `Using identity from creative_info: ${identityId}`);
                            break;
                        }
                    }
                }

                // Validate identity_id
                if (!identityId) {
                    addLog('error', 'No identity_id found in campaign data');
                    throw new Error('No identity found for this campaign. The ad requires an identity (TikTok page or custom identity).');
                }

                addLog('info', `Using identity: ${identityId} (type=${identityType}, bc_id=${identityBcId || 'none'})`);

                const adData = {
                    adgroup_id: newAdGroupId,
                    ad_name: newName,
                    identity_id: identityId,
                    identity_type: identityType,
                    call_to_action_id: callToActionId,
                    creatives: creatives,
                    ad_texts: adTextsToUse
                };

                // Add BC ID for BC_AUTH_TT identities
                if (identityType === 'BC_AUTH_TT' && identityBcId) {
                    adData.identity_authorized_bc_id = identityBcId;
                }

                // Add destination
                if (landingPageUrl) {
                    adData.landing_page_url = landingPageUrl;
                }
                if (pageId && !landingPageUrl) {
                    // Only use page_id if no landing page URL is set
                    adData.page_id = pageId;
                }

                const adResult = await apiRequest('create_smartplus_ad', adData);

                if (!adResult.success) {
                    throw new Error(adResult.message || 'Failed to create ad');
                }

                addLog('success', `Ad created: ${adResult.smart_plus_ad_id || adResult.ad_id}`);
            }

            // Update log entry to success
            document.getElementById(logId).className = 'progress-log-item success';
            document.getElementById(logId).innerHTML = `
                <span>✅</span>
                <span>"${newName}" created successfully</span>
            `;

            results.push({ name: newName, success: true });

        } catch (error) {
            console.error(`Error creating duplicate ${i}:`, error);
            addLog('error', `Failed to create "${newName}": ${error.message}`);

            // Update log entry to error
            document.getElementById(logId).className = 'progress-log-item error';
            document.getElementById(logId).innerHTML = `
                <span>❌</span>
                <span>"${newName}" failed: ${error.message}</span>
            `;

            results.push({ name: newName, success: false, error: error.message });
        }

        // Small delay between creations to avoid rate limiting
        if (i < count) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }

    // Show success section
    duplicateState.isProcessing = false;

    const successCount = results.filter(r => r.success).length;
    const failCount = results.filter(r => !r.success).length;

    document.getElementById('duplicate-progress-section').style.display = 'none';
    document.getElementById('duplicate-success-section').style.display = 'block';

    document.getElementById('duplicate-success-message').textContent =
        `Successfully created ${successCount} of ${count} campaign${count > 1 ? 's' : ''}.`;

    // Show results summary
    const summaryHtml = results.map(r => `
        <div class="result-item ${r.success ? 'success' : 'error'}">
            <span class="result-icon">${r.success ? '✅' : '❌'}</span>
            <span>${r.name}</span>
        </div>
    `).join('');
    document.getElementById('duplicate-results-summary').innerHTML = summaryHtml;

    // Update footer
    footer.innerHTML = `
        <button class="btn-primary" onclick="closeDuplicateCampaignModal(); refreshCampaignList();">
            Done
        </button>
    `;

    // Show toast
    if (failCount === 0) {
        showToast(`Successfully created ${successCount} campaign copies`, 'success');
    } else {
        showToast(`Created ${successCount}, failed ${failCount}`, failCount === count ? 'error' : 'warning');
    }

    addLog('info', `Duplication complete: ${successCount} success, ${failCount} failed`);
}

// ============================================
// VIDEO SELECTION MODAL
// ============================================

// State for video selection modal
let videoModalState = {
    context: null, // 'duplicate', 'step3', etc.
    selectedVideos: [],
    allVideos: [],
    onConfirm: null
};

// Open video selection modal
async function openVideoSelectionModal(context, preselectedVideos = []) {
    videoModalState.context = context;
    videoModalState.selectedVideos = [...preselectedVideos];

    // Show modal immediately
    const modal = document.getElementById('video-selection-modal');
    modal.style.display = 'flex';

    // If media library is empty (e.g. on View Campaigns page), load it first
    if (state.mediaLibrary.filter(m => m.type === 'video').length === 0) {
        // Show loading state in the modal grid
        const grid = document.getElementById('video-modal-grid');
        const emptyState = document.getElementById('video-modal-empty');
        if (grid) grid.innerHTML = '<div style="text-align: center; padding: 40px; color: #94a3b8; grid-column: 1/-1;"><div class="spinner" style="display:inline-block; animation: spin 1s linear infinite;">🔄</div> Loading videos from media library...</div>';
        if (grid) grid.style.display = 'grid';
        if (emptyState) emptyState.style.display = 'none';
        document.getElementById('video-modal-total').textContent = '...';

        addLog('info', 'Media library empty - loading videos...');
        await loadMediaLibrary(true);
        addLog('success', `Loaded ${state.mediaLibrary.filter(m => m.type === 'video').length} videos`);
    }

    // Get videos from media library
    const videos = state.mediaLibrary.filter(m => m.type === 'video');
    videoModalState.allVideos = videos;

    // Render videos
    renderVideoModalGrid(videos);

    // Update counts
    document.getElementById('video-modal-count').textContent = videoModalState.selectedVideos.length;
    document.getElementById('video-modal-total').textContent = videos.length;

    // Handle context-specific initialization
    if (context === 'duplicate' && duplicateState.campaignDetails?.ad) {
        // Use duplicate's changed videos if set, otherwise get from original campaign
        if (duplicateState.changedVideos && duplicateState.changedVideos.length > 0) {
            // User already changed videos - use those
            videoModalState.selectedVideos = duplicateState.changedVideos.map(v => ({
                id: v.video_id || v.id,
                video_id: v.video_id || v.id,
                name: v.name,
                thumbnail_url: v.thumbnail_url
            }));
        } else {
            // Get video IDs from the original campaign ad
            const ad = duplicateState.campaignDetails.ad;
            const currentVideoIds = [];

            if (ad.creative_list && ad.creative_list.length > 0) {
                ad.creative_list.forEach(item => {
                    if (item.creative_info?.video_info?.video_id) {
                        currentVideoIds.push(item.creative_info.video_info.video_id);
                    }
                });
            } else if (ad.video_ids && Array.isArray(ad.video_ids)) {
                currentVideoIds.push(...ad.video_ids);
            }

            // Map video IDs to video objects from media library
            // Use string comparison for video IDs to handle type mismatches
            videoModalState.selectedVideos = currentVideoIds.map(vid => {
                const vidStr = String(vid);
                const video = videos.find(v => {
                    const vId = String(v.id || '');
                    const vVideoId = String(v.video_id || '');
                    return vId === vidStr || vVideoId === vidStr;
                });
                if (video) {
                    return {
                        id: video.id,
                        video_id: video.video_id || video.id,
                        name: video.name || video.file_name,
                        thumbnail_url: video.thumbnail_url || video.cover_image_url || video.url
                    };
                }
                // Video not found in library - create placeholder
                console.log(`Video ${vid} not found in media library - creating placeholder`);
                return { id: vid, video_id: vid, name: `Video ${String(vid).slice(-6)}` };
            });

            console.log('Campaign video IDs:', currentVideoIds);
            console.log('Selected videos after mapping:', videoModalState.selectedVideos);
        }

        // Re-render with selections
        renderVideoModalGrid(videos);
        document.getElementById('video-modal-count').textContent = videoModalState.selectedVideos.length;

        addLog('info', `Preselected ${videoModalState.selectedVideos.length} videos from campaign`);
    }

    addLog('info', `Video selection modal opened for context: ${context}`);
}

// Helper function to check if a video is selected
function isVideoSelected(video) {
    const videoId = String(video.id || video.video_id || '');
    return videoModalState.selectedVideos.some(v => {
        const selectedId = String(v.video_id || v.id || '');
        return selectedId === videoId;
    });
}

// Render video grid in modal
function renderVideoModalGrid(videos) {
    const grid = document.getElementById('video-modal-grid');
    const emptyState = document.getElementById('video-modal-empty');

    if (videos.length === 0) {
        grid.style.display = 'none';
        emptyState.style.display = 'block';
        return;
    }

    grid.style.display = 'grid';
    emptyState.style.display = 'none';

    // Sort videos: selected videos first, then unselected
    const sortedVideos = [...videos].sort((a, b) => {
        const aSelected = isVideoSelected(a);
        const bSelected = isVideoSelected(b);

        if (aSelected && !bSelected) return -1;
        if (!aSelected && bSelected) return 1;
        return 0;
    });

    grid.innerHTML = sortedVideos.map(video => {
        const isSelected = isVideoSelected(video);
        // Get thumbnail from various possible fields
        const thumbnailUrl = video.thumbnail_url || video.cover_image_url || video.url || video.preview_url || '';

        return `
            <div class="video-modal-item ${isSelected ? 'selected' : ''}"
                 onclick="toggleVideoInModal('${video.id}')"
                 data-video-id="${video.id}"
                 data-name="${(video.name || video.file_name || '').toLowerCase()}">
                <div class="video-modal-thumbnail">
                    ${thumbnailUrl ?
                        `<img src="${thumbnailUrl}" alt="${video.name || 'Video'}" onerror="this.parentElement.innerHTML='<div class=\\'video-placeholder\\'>🎬</div>'" />`
                        : '<div class="video-placeholder">🎬</div>'}
                    <div class="video-modal-check">✓</div>
                </div>
                <div class="video-modal-info">
                    <div class="video-modal-name">${video.name || video.file_name || 'Untitled'}</div>
                    ${video.duration ? `<div class="video-modal-duration">${formatDuration(video.duration)}</div>` : ''}
                </div>
            </div>
        `;
    }).join('');
}

// Toggle video selection in modal
function toggleVideoInModal(videoId) {
    const video = videoModalState.allVideos.find(v => v.id === videoId);
    if (!video) return;

    const existingIndex = videoModalState.selectedVideos.findIndex(v =>
        v.id === videoId || v.video_id === videoId
    );

    if (existingIndex >= 0) {
        // Remove from selection
        videoModalState.selectedVideos.splice(existingIndex, 1);
    } else {
        // Add to selection
        videoModalState.selectedVideos.push({
            id: video.id,
            video_id: video.video_id || video.id,
            name: video.name || video.file_name,
            thumbnail_url: video.thumbnail_url || video.cover_image_url || video.url || video.preview_url
        });
    }

    // Update UI
    const item = document.querySelector(`.video-modal-item[data-video-id="${videoId}"]`);
    if (item) {
        item.classList.toggle('selected');
    }

    document.getElementById('video-modal-count').textContent = videoModalState.selectedVideos.length;
}

// Filter videos by search term
function filterVideosInModal() {
    const searchTerm = document.getElementById('video-modal-search').value.toLowerCase().trim();
    const items = document.querySelectorAll('.video-modal-item');

    items.forEach(item => {
        const name = item.getAttribute('data-name') || '';
        const videoId = item.getAttribute('data-video-id') || '';

        if (searchTerm === '' || name.includes(searchTerm) || videoId.includes(searchTerm)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

// Refresh video library in modal
async function refreshVideoModalLibrary() {
    const btn = document.getElementById('video-modal-refresh-btn');
    const icon = document.getElementById('video-modal-refresh-icon');
    const grid = document.getElementById('video-modal-grid');

    // Show loading state
    if (btn) btn.disabled = true;
    if (icon) icon.style.animation = 'spin 1s linear infinite';

    // Show loading in grid
    if (grid) {
        grid.innerHTML = '<div style="text-align: center; padding: 40px; color: #94a3b8; grid-column: 1/-1;"><div style="display:inline-block; animation: spin 1s linear infinite;">&#x21bb;</div> Refreshing videos...</div>';
        grid.style.display = 'grid';
    }
    const emptyState = document.getElementById('video-modal-empty');
    if (emptyState) emptyState.style.display = 'none';

    try {
        await loadMediaLibrary(true);

        const videos = state.mediaLibrary.filter(m => m.type === 'video');
        videoModalState.allVideos = videos;
        renderVideoModalGrid(videos);

        // Update total count
        const totalEl = document.getElementById('video-modal-total');
        if (totalEl) totalEl.textContent = videos.length;

        // Update selected count (keep current selections)
        document.getElementById('video-modal-count').textContent = videoModalState.selectedVideos.length;

        showToast(`Refreshed: ${videos.length} video(s) found`, 'success');
        addLog('success', `Video library refreshed: ${videos.length} videos`);
    } catch (e) {
        console.error('Error refreshing video library:', e);
        showToast('Failed to refresh videos', 'error');
    } finally {
        if (btn) btn.disabled = false;
        if (icon) icon.style.animation = 'none';
    }
}

// Select all videos in modal
function selectAllVideosInModal() {
    const videos = videoModalState.allVideos;
    if (videos.length === 0) return;

    // Add all videos that aren't already selected
    videoModalState.selectedVideos = videos.map(v => ({
        id: v.id,
        video_id: v.video_id || v.id,
        name: v.name || v.file_name,
        thumbnail_url: v.thumbnail_url || v.cover_image_url || v.url || v.preview_url
    }));

    // Update all items in UI
    document.querySelectorAll('.video-modal-item').forEach(item => {
        item.classList.add('selected');
    });

    document.getElementById('video-modal-count').textContent = videoModalState.selectedVideos.length;
    showToast(`Selected all ${videoModalState.selectedVideos.length} video(s)`, 'success');
}

// Clear all video selections in modal
function clearAllVideosInModal() {
    videoModalState.selectedVideos = [];

    // Update all items in UI
    document.querySelectorAll('.video-modal-item').forEach(item => {
        item.classList.remove('selected');
    });

    document.getElementById('video-modal-count').textContent = '0';
    showToast('Cleared all selections', 'info');
}

// Confirm video selection
function confirmVideoSelection() {
    const context = videoModalState.context;
    const selectedVideos = videoModalState.selectedVideos;

    if (selectedVideos.length === 0) {
        showToast('Please select at least one video', 'error');
        return;
    }

    if (context === 'duplicate') {
        // Store changed videos in duplicate state
        duplicateState.changedVideos = selectedVideos.map(v => ({
            video_id: v.video_id || v.id,
            name: v.name,
            thumbnail_url: v.thumbnail_url
        }));

        // Update the current videos display in duplicate modal
        renderDuplicateCurrentVideos();

        showToast(`Selected ${selectedVideos.length} video(s)`, 'success');
        addLog('info', `Changed videos for duplicate: ${selectedVideos.length} selected`);
    } else if (context === 'step3') {
        // Update main state selectedVideos
        state.selectedVideos = selectedVideos;
        state.creatives = selectedVideos.map(v => ({
            video_id: v.video_id || v.id,
            name: v.name
        }));

        // Refresh the video selection grid in step 3
        renderVideoSelectionGrid();
        updateCreativesSection();

        showToast(`Selected ${selectedVideos.length} video(s)`, 'success');
    }

    closeVideoSelectionModal();
}

// Close video selection modal
function closeVideoSelectionModal() {
    const modal = document.getElementById('video-selection-modal');
    modal.style.display = 'none';

    videoModalState = {
        context: null,
        selectedVideos: [],
        allVideos: [],
        onConfirm: null
    };

    // Reset upload UI
    const uploadProgress = document.getElementById('video-modal-upload-progress');
    if (uploadProgress) uploadProgress.style.display = 'none';
    const uploadInput = document.getElementById('video-modal-upload-input');
    if (uploadInput) uploadInput.value = '';
}

// ============================================
// UPLOAD OPTIONS: Single vs Multi-Account
// ============================================

// Show upload options modal (single or multi account)
function showUploadOptions() {
    const modal = document.getElementById('upload-options-modal');
    if (!modal) {
        // Fallback: directly trigger single-account upload
        document.getElementById('video-modal-upload-input').click();
        return;
    }

    // Load accounts if not already loaded
    if (bulkLaunchState.accounts.length === 0) {
        loadBulkAccounts().then(() => renderUploadAccountList());
    } else {
        renderUploadAccountList();
    }

    modal.style.display = 'flex';
}

// Render the account list in multi-account upload modal
function renderUploadAccountList() {
    const container = document.getElementById('upload-account-list');
    if (!container) return;

    const accounts = bulkLaunchState.accounts;
    if (accounts.length === 0) {
        container.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 20px;">No accounts found. Connect ad accounts first.</p>';
        return;
    }

    container.innerHTML = accounts.map(acc => `
        <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: ${acc.is_current ? '#f0f9ff' : '#f8fafc'}; border: 1px solid ${acc.is_current ? '#bae6fd' : '#e2e8f0'}; border-radius: 8px; cursor: pointer; transition: all 0.2s;"
               onmouseover="this.style.borderColor='#93c5fd'" onmouseout="this.style.borderColor='${acc.is_current ? '#bae6fd' : '#e2e8f0'}'">
            <input type="checkbox" class="upload-account-checkbox" value="${acc.advertiser_id}"
                   data-name="${acc.advertiser_name}" ${acc.is_current ? 'checked' : ''}
                   style="width: 18px; height: 18px; accent-color: #2563eb;">
            <div style="flex: 1;">
                <div style="font-weight: 600; font-size: 14px; color: #1e293b;">${acc.advertiser_name}</div>
                <div style="font-size: 12px; color: #94a3b8;">${acc.advertiser_id}${acc.is_current ? ' (current)' : ''}</div>
            </div>
        </label>
    `).join('');
}

// Select/deselect all upload accounts
function toggleAllUploadAccounts(selectAll) {
    document.querySelectorAll('.upload-account-checkbox').forEach(cb => {
        cb.checked = selectAll;
    });
}

// Close upload options modal
function closeUploadOptions() {
    const modal = document.getElementById('upload-options-modal');
    if (modal) modal.style.display = 'none';
}

// User chose "Single Account" upload
function uploadSingleAccount() {
    closeUploadOptions();
    document.getElementById('video-modal-upload-input').click();
}

// User chose "Multiple Accounts" upload - trigger file selection then upload to all
function uploadMultipleAccounts() {
    const checkedBoxes = document.querySelectorAll('.upload-account-checkbox:checked');
    if (checkedBoxes.length === 0) {
        showToast('Please select at least one account', 'error');
        return;
    }

    const selectedAccounts = Array.from(checkedBoxes).map(cb => ({
        advertiser_id: cb.value,
        name: cb.dataset.name
    }));

    // Store selected accounts for use after file selection
    window._multiUploadAccounts = selectedAccounts;
    closeUploadOptions();

    // Create a temporary file input for multi-account upload
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'video/*';
    input.multiple = true;
    input.style.display = 'none';
    input.onchange = (e) => handleMultiAccountUpload(e, selectedAccounts);
    document.body.appendChild(input);
    input.click();
    // Clean up after selection
    setTimeout(() => input.remove(), 60000);
}

// Handle uploading videos to multiple accounts
async function handleMultiAccountUpload(event, accounts) {
    const files = Array.from(event.target.files);
    if (files.length === 0) return;

    // Validate files
    const maxSize = 500 * 1024 * 1024;
    const validFiles = files.filter(f => f.type.startsWith('video/') && f.size <= maxSize);
    if (validFiles.length === 0) {
        showToast('No valid video files selected', 'error');
        return;
    }

    const totalUploads = validFiles.length * accounts.length;
    addLog('info', `Multi-account upload: ${validFiles.length} video(s) to ${accounts.length} account(s) = ${totalUploads} uploads`);

    // Show progress in the video modal
    const progressContainer = document.getElementById('video-modal-upload-progress');
    const progressBar = document.getElementById('bulk-upload-bar');
    const progressTitle = document.getElementById('bulk-upload-title');
    const progressCount = document.getElementById('bulk-upload-count');
    const progressList = document.getElementById('bulk-upload-list');

    if (progressContainer) {
        progressContainer.style.display = 'block';
        if (progressTitle) progressTitle.textContent = `Uploading ${validFiles.length} video(s) to ${accounts.length} account(s)...`;
        if (progressCount) progressCount.textContent = `0/${totalUploads}`;
        if (progressBar) progressBar.style.width = '0%';
    }

    // Build per-account progress items
    if (progressList) {
        progressList.innerHTML = accounts.map((acc, idx) => `
            <div class="upload-item" id="multi-upload-acc-${idx}" style="margin-bottom: 6px;">
                <span class="upload-item-name" style="font-weight: 600;">${acc.name}</span>
                <span class="upload-item-status uploading" id="multi-upload-status-${idx}">0/${validFiles.length}</span>
            </div>
        `).join('');
    }

    let completed = 0;
    let failed = 0;

    // Pre-generate thumbnails
    const thumbnails = new Map();
    for (const file of validFiles) {
        const thumb = await generateVideoThumbnailSafe(file);
        thumbnails.set(file, thumb);
    }

    // Upload to each account sequentially (to avoid rate limits)
    for (let accIdx = 0; accIdx < accounts.length; accIdx++) {
        const account = accounts[accIdx];
        let accCompleted = 0;

        addLog('info', `Uploading to ${account.name} (${account.advertiser_id})...`);

        for (const file of validFiles) {
            // Rename with timestamp
            const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
            const originalName = file.name;
            const lastDot = originalName.lastIndexOf('.');
            const baseName = lastDot > 0 ? originalName.slice(0, lastDot) : originalName;
            const ext = lastDot > 0 ? originalName.slice(lastDot) : '';
            const newFileName = `${baseName}_${timestamp}${ext}`;

            const formData = new FormData();
            formData.append('video', file, newFileName);
            formData.append('target_advertiser_id', account.advertiser_id);

            try {
                const response = await fetch('api.php?action=upload_video_to_advertiser', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    accCompleted++;
                    completed++;

                    // Add to media library if it's the current account
                    const currentAccId = state.currentAdvertiserId || window.TIKTOK_ADVERTISER_ID;
                    if (account.advertiser_id === currentAccId && result.data?.video_id) {
                        const thumbUrl = thumbnails.get(file) || '';
                        state.mediaLibrary.unshift({
                            type: 'video',
                            id: result.data.video_id,
                            url: thumbUrl,
                            name: newFileName,
                            is_new: true
                        });
                    }
                } else {
                    failed++;
                    addLog('error', `Failed ${file.name} -> ${account.name}: ${result.message}`);
                }
            } catch (e) {
                failed++;
                addLog('error', `Error ${file.name} -> ${account.name}: ${e.message}`);
            }

            // Update progress
            const statusEl = document.getElementById(`multi-upload-status-${accIdx}`);
            if (statusEl) statusEl.textContent = `${accCompleted}/${validFiles.length}`;
            if (progressCount) progressCount.textContent = `${completed + failed}/${totalUploads}`;
            if (progressBar) progressBar.style.width = `${((completed + failed) / totalUploads) * 100}%`;
        }

        // Mark account as done
        const statusEl = document.getElementById(`multi-upload-status-${accIdx}`);
        if (statusEl) {
            statusEl.textContent = `Done (${accCompleted}/${validFiles.length})`;
            statusEl.className = 'upload-item-status success';
        }

        // Small delay between accounts
        if (accIdx < accounts.length - 1) {
            await new Promise(r => setTimeout(r, 500));
        }
    }

    // Final status
    if (progressTitle) progressTitle.textContent = `Upload complete! ${completed} succeeded, ${failed} failed`;
    addLog('success', `Multi-account upload done: ${completed} success, ${failed} failed`);
    showToast(`Uploaded ${completed}/${totalUploads} videos across ${accounts.length} account(s)`, completed > 0 ? 'success' : 'error');

    // Refresh video grid for current account
    const videos = state.mediaLibrary.filter(m => m.type === 'video');
    videoModalState.allVideos = videos;
    renderVideoModalGrid(videos);
    const totalEl = document.getElementById('video-modal-total');
    if (totalEl) totalEl.textContent = videos.length;

    // Hide progress after delay
    setTimeout(() => {
        if (progressContainer) progressContainer.style.display = 'none';
    }, 3000);

    // Background refresh
    setTimeout(() => loadMediaLibrary(true), 2000);
}

// Handle single video upload from video selection modal
async function handleVideoModalUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    if (!file.type.startsWith('video/')) {
        showToast('Please select a video file', 'error');
        return;
    }

    // Check file size (max 500MB)
    const maxSize = 500 * 1024 * 1024;
    if (file.size > maxSize) {
        showToast('Video file too large. Maximum size is 500MB', 'error');
        return;
    }

    // Pre-generate thumbnail for instant preview (before upload starts)
    console.log('[VideoModal Upload] Pre-generating thumbnail for', file.name);
    const thumbnailUrl = await generateVideoThumbnailSafe(file);
    console.log('[VideoModal Upload] Thumbnail generated:', thumbnailUrl ? 'success' : 'failed');

    // Add timestamp to filename to prevent duplicates
    const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
    const originalName = file.name;
    const lastDotIndex = originalName.lastIndexOf('.');
    const nameWithoutExt = lastDotIndex > 0 ? originalName.slice(0, lastDotIndex) : originalName;
    const extension = lastDotIndex > 0 ? originalName.slice(lastDotIndex) : '';
    const newFileName = `${nameWithoutExt}_${timestamp}${extension}`;

    // Create renamed file
    const renamedFile = new File([file], newFileName, { type: file.type });

    // Show progress using bulk upload UI (works for single file too)
    const progressContainer = document.getElementById('video-modal-upload-progress');
    const progressBar = document.getElementById('bulk-upload-bar');
    const progressTitle = document.getElementById('bulk-upload-title');
    const progressCount = document.getElementById('bulk-upload-count');
    const progressList = document.getElementById('bulk-upload-list');

    if (progressContainer) {
        progressContainer.style.display = 'block';
        if (progressTitle) progressTitle.textContent = `Uploading ${newFileName}...`;
        if (progressCount) progressCount.textContent = '0/1';
        if (progressBar) progressBar.style.width = '0%';
        if (progressList) progressList.innerHTML = `
            <div class="upload-item" id="upload-item-0">
                <span class="upload-item-name">${file.name}</span>
                <span class="upload-item-size">${(file.size / 1024 / 1024).toFixed(1)}MB</span>
                <span class="upload-item-status uploading">Uploading...</span>
            </div>
        `;
    }

    const formData = new FormData();
    formData.append('video', renamedFile);

    try {
        addLog('request', `Uploading video: ${newFileName}`);

        // Simulate progress
        let progress = 0;
        const progressInterval = setInterval(() => {
            progress += Math.random() * 15;
            if (progress > 90) progress = 90;
            if (progressBar) progressBar.style.width = progress + '%';
        }, 200);

        const response = await fetch('api.php?action=upload_video', {
            method: 'POST',
            headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: formData
        });

        clearInterval(progressInterval);
        if (progressBar) progressBar.style.width = '100%';
        if (progressCount) progressCount.textContent = '1/1';

        const responseText = await response.text();
        let result;

        try {
            result = JSON.parse(responseText);
        } catch (e) {
            console.error('JSON parse error:', e);
            throw new Error('Invalid response from server');
        }

        if (result.success && result.data?.video_id) {
            // Video uploaded and video_id returned immediately
            if (progressTitle) progressTitle.textContent = 'Upload complete!';
            updateUploadItemStatus('upload-item-0', 'success', '✓ Uploaded');
            addLog('success', `Video uploaded: ${result.data.video_id}`);
            showToast('Video uploaded successfully!', 'success');

            // Use pre-generated thumbnail for instant preview (image, not video blob)
            const previewUrl = thumbnailUrl || '';

            // Add new video to state immediately so it shows right away
            // Use format that matches loadMediaLibrary: id, url, name, type
            const newVideo = {
                type: 'video',
                id: result.data.video_id,
                url: previewUrl,
                name: newFileName,
                is_new: true
            };

            // Add to beginning of media library
            state.mediaLibrary.unshift(newVideo);

            // Update video modal state and re-render immediately
            const videos = state.mediaLibrary.filter(m => m.type === 'video');
            videoModalState.allVideos = videos;
            renderVideoModalGrid(videos);

            // Update total count
            const totalEl = document.getElementById('video-modal-total');
            if (totalEl) totalEl.textContent = videos.length;

            // Also update Step 3 video grid
            renderVideoSelectionGrid();

            // Hide progress after a moment
            setTimeout(() => {
                if (progressContainer) progressContainer.style.display = 'none';
            }, 1500);

            // Refresh from API in background with force refresh to get proper thumbnail/metadata
            setTimeout(async () => {
                await loadMediaLibrary(true);  // Force refresh
                const updatedVideos = state.mediaLibrary.filter(m => m.type === 'video');
                videoModalState.allVideos = updatedVideos;
                renderVideoModalGrid(updatedVideos);
            }, 3000);
        } else if (result.success || (result.message && result.message.includes('processing'))) {
            // Video accepted but still processing - this is OK, not an error!
            if (progressTitle) progressTitle.textContent = 'Video accepted - processing...';
            updateUploadItemStatus('upload-item-0', 'processing', '⏳ Processing');
            if (progressContainer) {
                progressContainer.style.background = '#fef3c7';
                progressContainer.style.borderColor = '#fcd34d';
            }
            addLog('info', `Video accepted, processing: ${newFileName}`);
            showToast('Video accepted! It will appear in your library in 1-2 minutes.', 'success');

            // Hide progress after a moment
            setTimeout(() => {
                if (progressContainer) {
                    progressContainer.style.display = 'none';
                    progressContainer.style.background = '#f0f9ff';
                    progressContainer.style.borderColor = '#bae6fd';
                }
            }, 3000);

            // Auto-refresh library after delay to catch the processed video
            setTimeout(async () => {
                await loadMediaLibrary(true);
                const updatedVideos = state.mediaLibrary.filter(m => m.type === 'video');
                videoModalState.allVideos = updatedVideos;
                renderVideoModalGrid(updatedVideos);
                const totalEl = document.getElementById('video-modal-total');
                if (totalEl) totalEl.textContent = updatedVideos.length;
            }, 60000); // Check after 1 minute
        } else {
            throw new Error(result.message || 'Upload failed');
        }
    } catch (error) {
        console.error('Video upload error:', error);
        if (progressTitle) progressTitle.textContent = 'Upload failed!';
        updateUploadItemStatus('upload-item-0', 'failed', '✗ Failed');
        if (progressBar) progressBar.style.width = '0%';
        if (progressContainer) {
            progressContainer.style.background = '#fef2f2';
            progressContainer.style.borderColor = '#fecaca';
        }
        addLog('error', `Video upload failed: ${error.message}`);
        showToast('Upload failed: ' + error.message, 'error');

        // Hide progress after a moment
        setTimeout(() => {
            if (progressContainer) {
                progressContainer.style.display = 'none';
                progressContainer.style.background = '#f0f9ff';
                progressContainer.style.borderColor = '#bae6fd';
            }
        }, 3000);
    }

    // Reset file input
    event.target.value = '';
}

// ============================================
// BULK VIDEO UPLOAD
// ============================================

// Bulk upload state
let bulkUploadState = {
    queue: [],
    completed: 0,
    failed: 0,
    total: 0,
    isUploading: false
};

// Handle bulk video upload from video selection modal
async function handleBulkVideoUpload(event) {
    const files = Array.from(event.target.files);
    if (files.length === 0) return;

    // If only one file, use the simple upload
    if (files.length === 1) {
        return handleVideoModalUpload(event);
    }

    // Validate all files
    const validFiles = [];
    const maxSize = 500 * 1024 * 1024; // 500MB

    for (const file of files) {
        if (!file.type.startsWith('video/')) {
            showToast(`Skipped ${file.name}: Not a video file`, 'warning');
            continue;
        }
        if (file.size > maxSize) {
            showToast(`Skipped ${file.name}: Exceeds 500MB limit`, 'warning');
            continue;
        }
        validFiles.push(file);
    }

    if (validFiles.length === 0) {
        showToast('No valid video files selected', 'error');
        event.target.value = '';
        return;
    }

    // Pre-generate thumbnails for instant preview
    addLog('info', `Generating thumbnails for ${validFiles.length} videos...`);
    const thumbnails = new Map();
    for (const file of validFiles) {
        const thumbnail = await generateVideoThumbnailSafe(file);
        thumbnails.set(file, thumbnail);
    }
    addLog('info', `Generated ${thumbnails.size} thumbnails`);

    // Initialize state
    bulkUploadState = {
        queue: validFiles,
        completed: 0,
        failed: 0,
        total: validFiles.length,
        isUploading: true,
        thumbnails: thumbnails  // Store thumbnails for use during upload
    };

    addLog('info', `Starting bulk upload of ${validFiles.length} videos (parallel batches of 2)`);

    // Show progress UI
    showBulkUploadProgress();

    // Upload files in PARALLEL BATCHES of 2 for reliability
    const BATCH_SIZE = 2;
    const totalBatches = Math.ceil(validFiles.length / BATCH_SIZE);

    for (let batchIndex = 0; batchIndex < totalBatches; batchIndex++) {
        const startIdx = batchIndex * BATCH_SIZE;
        const batch = validFiles.slice(startIdx, startIdx + BATCH_SIZE);

        addLog('info', `Uploading batch ${batchIndex + 1}/${totalBatches} (${batch.length} videos)`);

        // Upload batch in parallel - all 5 videos upload simultaneously
        await Promise.all(
            batch.map((file, idx) => uploadSingleVideoInBulk(file, startIdx + idx))
        );

        // Small delay between batches to avoid rate limiting
        if (batchIndex < totalBatches - 1) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }

    // Complete
    bulkUploadState.isUploading = false;
    finishBulkUpload();

    // Clear file input
    event.target.value = '';
}

// Upload a single video as part of bulk upload - NO AUTO-RETRY to prevent duplicates
async function uploadSingleVideoInBulk(file, index) {
    const itemId = `upload-item-${index}`;
    const uploadTimeout = 300000; // 5 minutes timeout for large videos

    updateUploadItemStatus(itemId, 'uploading', 'Uploading...', 0);

    // Add timestamp to filename
    const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
    const ext = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.')) : '';
    const baseName = file.name.includes('.') ? file.name.slice(0, file.name.lastIndexOf('.')) : file.name;
    const newFileName = `${baseName}_${timestamp}${ext}`;

    // Use FormData with original file, just set the filename
    const formData = new FormData();
    formData.append('video', file, newFileName);

    addLog('request', `Uploading: ${newFileName}`);

    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        let timeoutId;
        let uploadComplete = false; // Track if upload bytes sent successfully

        // Progress tracking
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                updateUploadItemStatus(itemId, 'uploading', `${percent}%`, percent);
                if (percent === 100) {
                    uploadComplete = true;
                    updateUploadItemStatus(itemId, 'uploading', 'Processing...', 100);
                }
            }
        });

        // Success handler
        xhr.addEventListener('load', () => {
            clearTimeout(timeoutId);

            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    let result;
                    try {
                        result = JSON.parse(xhr.responseText);
                    } catch (e) {
                        const jsonMatch = xhr.responseText.match(/\{[\s\S]*"success"[\s\S]*\}/);
                        if (jsonMatch) {
                            result = JSON.parse(jsonMatch[0]);
                        } else {
                            throw new Error('Invalid server response');
                        }
                    }

                    if (result.success && result.data?.video_id) {
                        // Immediate success with video_id
                        bulkUploadState.completed++;
                        updateUploadItemStatus(itemId, 'success', '✓ Uploaded', 100);
                        addLog('success', `Uploaded: ${newFileName} (${result.data.video_id})`);

                        // Get pre-generated thumbnail from bulkUploadState
                        const thumbnailUrl = bulkUploadState.thumbnails?.get(file) || '';

                        // Add to state immediately with thumbnail
                        const newVideo = {
                            type: 'video',
                            id: result.data.video_id,
                            url: thumbnailUrl,  // Use client-side thumbnail for instant preview
                            video_cover_url: thumbnailUrl,
                            preview_url: thumbnailUrl,
                            name: newFileName,
                            is_new: true
                        };
                        state.mediaLibrary.unshift(newVideo);

                        updateBulkUploadProgress();
                        resolve({ success: true, video_id: result.data.video_id });
                    } else if (result.success && result.processing) {
                        // Video accepted but processing - count as SUCCESS, not failure!
                        bulkUploadState.completed++;
                        updateUploadItemStatus(itemId, 'processing', '⏳ Processing', 100);
                        addLog('info', `Video accepted, processing: ${newFileName}`);
                        updateBulkUploadProgress();
                        resolve({ success: true, processing: true });
                    } else if (result.success) {
                        // Success but no video_id (legacy response)
                        bulkUploadState.completed++;
                        updateUploadItemStatus(itemId, 'processing', '⏳ Accepted', 100);
                        addLog('info', `Video accepted: ${newFileName}`);
                        updateBulkUploadProgress();
                        resolve({ success: true, processing: true });
                    } else {
                        // Actual failure
                        const errorMsg = result.message || 'Upload failed - check if video appears in library';
                        handleUploadFailed(errorMsg);
                    }
                } catch (e) {
                    handleUploadFailed('Invalid server response - video may still have uploaded');
                }
            } else {
                handleUploadFailed(`Server error (${xhr.status}) - video may still have uploaded`);
            }
        });

        // Error handler - NO retry, just fail
        xhr.addEventListener('error', () => {
            clearTimeout(timeoutId);
            if (uploadComplete) {
                // Upload bytes sent but no response - video likely uploaded
                handleUploadFailed('Connection lost after upload - check library');
            } else {
                handleUploadFailed('Network error - please try again');
            }
        });

        // Abort handler (timeout) - NO retry
        xhr.addEventListener('abort', () => {
            clearTimeout(timeoutId);
            if (uploadComplete) {
                // File was sent, just waiting for response
                handleUploadFailed('Timeout waiting for response - video may have uploaded, check library');
            } else {
                handleUploadFailed('Upload timeout - please try again with smaller file');
            }
        });

        // Handle upload failure - NO auto-retry to prevent duplicates
        function handleUploadFailed(errorMsg) {
            addLog('error', `Failed: ${file.name} - ${errorMsg}`);
            bulkUploadState.failed++;
            bulkUploadState.failedFiles = bulkUploadState.failedFiles || {};
            bulkUploadState.failedFiles[index] = file;
            // Show truncated error in status for visibility
            const shortError = errorMsg.length > 25 ? errorMsg.substring(0, 25) + '...' : errorMsg;
            updateUploadItemStatus(itemId, 'failed', `✗ ${shortError}`, 0);
            updateBulkUploadProgress();
            resolve({ success: false, error: errorMsg });
        }

        // Set timeout - 5 minutes for large videos
        timeoutId = setTimeout(() => {
            xhr.abort();
        }, uploadTimeout);

        // Send request
        xhr.open('POST', 'api.php?action=upload_video');
        xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || '');
        xhr.send(formData);
    });
}

// Retry a failed upload manually
async function retryFailedUpload(index) {
    const file = bulkUploadState.failedFiles?.[index];
    if (!file) {
        showToast('File not found for retry', 'error');
        return;
    }

    // Reset the failed count for this item
    bulkUploadState.failed--;
    delete bulkUploadState.failedFiles[index];

    // Reset UI
    const actionsEl = document.getElementById(`upload-actions-${index}`);
    if (actionsEl) actionsEl.style.display = 'none';

    addLog('info', `Manual retry for: ${file.name}`);

    // Retry with fresh retry count
    const result = await uploadSingleVideoInBulk(file, index, 0);

    // Update final state
    if (result.success) {
        showToast(`Successfully uploaded ${file.name}`, 'success');
        // Refresh grids
        const videos = state.mediaLibrary.filter(m => m.type === 'video');
        videoModalState.allVideos = videos;
        renderVideoModalGrid(videos);
        renderVideoSelectionGrid();
    }
}

// Show bulk upload progress UI
function showBulkUploadProgress() {
    const container = document.getElementById('video-modal-upload-progress');
    const list = document.getElementById('bulk-upload-list');

    if (!container || !list) return;

    container.style.display = 'block';
    document.getElementById('bulk-upload-count').textContent = `0/${bulkUploadState.total}`;
    document.getElementById('bulk-upload-bar').style.width = '0%';

    // Create item for each file with individual progress bar
    list.innerHTML = bulkUploadState.queue.map((file, i) => `
        <div class="upload-item" id="upload-item-${i}" data-file-index="${i}">
            <div class="upload-item-info">
                <span class="upload-item-name" title="${file.name}">${file.name}</span>
                <span class="upload-item-size">${(file.size / 1024 / 1024).toFixed(1)}MB</span>
                <span class="upload-item-status pending">Pending</span>
            </div>
            <div class="upload-item-progress-container">
                <div class="upload-item-progress-bar" id="progress-bar-${i}" style="width: 0%"></div>
            </div>
            <div class="upload-item-actions" id="upload-actions-${i}" style="display: none;">
                <button class="retry-btn" onclick="retryFailedUpload(${i})" title="Retry upload">
                    ↻ Retry
                </button>
            </div>
        </div>
    `).join('');
}

// Update individual upload item status
function updateUploadItemStatus(itemId, status, text, progress = null) {
    const item = document.getElementById(itemId);
    if (!item) return;

    const statusEl = item.querySelector('.upload-item-status');
    if (statusEl) {
        statusEl.className = `upload-item-status ${status}`;
        statusEl.textContent = text;
    }

    // Update progress bar if provided
    const index = item.dataset.fileIndex;
    const progressBar = document.getElementById(`progress-bar-${index}`);
    if (progressBar && progress !== null) {
        progressBar.style.width = `${progress}%`;
        progressBar.className = `upload-item-progress-bar ${status}`;
    }

    // Show/hide retry button for failed items
    const actionsEl = document.getElementById(`upload-actions-${index}`);
    if (actionsEl) {
        if (status === 'failed') {
            actionsEl.style.display = 'flex';
        } else {
            actionsEl.style.display = 'none';
        }
    }
}

// Update overall bulk upload progress
function updateBulkUploadProgress() {
    const completed = bulkUploadState.completed + bulkUploadState.failed;
    const total = bulkUploadState.total;
    const percent = Math.round((completed / total) * 100);

    const countEl = document.getElementById('bulk-upload-count');
    const barEl = document.getElementById('bulk-upload-bar');

    if (countEl) countEl.textContent = `${completed}/${total}`;
    if (barEl) barEl.style.width = `${percent}%`;
}

// Finish bulk upload and show results
function finishBulkUpload() {
    const { completed, failed, total } = bulkUploadState;

    if (failed === 0) {
        showToast(`Successfully uploaded ${completed} videos!`, 'success');
        addLog('success', `Bulk upload complete: ${completed} videos uploaded`);
    } else if (completed > 0) {
        showToast(`Uploaded ${completed}/${total} videos (${failed} failed)`, 'warning');
        addLog('warning', `Bulk upload complete: ${completed} success, ${failed} failed`);
    } else {
        showToast(`Failed to upload all ${total} videos`, 'error');
        addLog('error', `Bulk upload failed: all ${total} videos failed`);
    }

    // Refresh video grid in modal
    const videos = state.mediaLibrary.filter(m => m.type === 'video');
    videoModalState.allVideos = videos;
    renderVideoModalGrid(videos);

    const totalEl = document.getElementById('video-modal-total');
    if (totalEl) totalEl.textContent = videos.length;

    // Also update Step 3 video grid
    renderVideoSelectionGrid();

    // Hide progress after delay
    setTimeout(() => {
        const container = document.getElementById('video-modal-upload-progress');
        if (container) container.style.display = 'none';
    }, 3000);

    // Background refresh with force_refresh to get proper thumbnails
    setTimeout(() => loadMediaLibrary(true), 2000);
}

// Render current videos in duplicate modal
function renderDuplicateCurrentVideos() {
    const container = document.getElementById('duplicate-current-videos');
    const sameContainer = document.getElementById('duplicate-same-current-videos');

    let videos = [];

    // Check if we have changed videos
    if (duplicateState.changedVideos && duplicateState.changedVideos.length > 0) {
        videos = duplicateState.changedVideos;
    } else if (duplicateState.campaignDetails?.ad) {
        // Get from original campaign
        const ad = duplicateState.campaignDetails.ad;

        if (ad.creative_list && ad.creative_list.length > 0) {
            videos = ad.creative_list.map(item => {
                const videoInfo = item.creative_info?.video_info;
                const videoId = videoInfo?.video_id;
                // First try to get thumbnail from campaign data itself
                const campaignThumbnail = videoInfo?.preview_url || videoInfo?.cover_image_url || videoInfo?.poster_url;
                // Then fallback to media library
                const video = state.mediaLibrary.find(v => String(v.id) === String(videoId) || String(v.video_id) === String(videoId));
                return {
                    video_id: videoId,
                    name: video?.name || video?.file_name || videoInfo?.file_name || `Video ${String(videoId || '').slice(-6)}`,
                    thumbnail_url: campaignThumbnail || video?.thumbnail_url || video?.cover_image_url || video?.url || video?.preview_url
                };
            });
        } else if (ad.video_ids) {
            videos = ad.video_ids.map(vid => {
                const video = state.mediaLibrary.find(v => String(v.id) === String(vid) || String(v.video_id) === String(vid));
                return {
                    video_id: vid,
                    name: video?.name || video?.file_name || `Video ${String(vid || '').slice(-6)}`,
                    thumbnail_url: video?.thumbnail_url || video?.cover_image_url || video?.url || video?.preview_url
                };
            });
        }
    }

    const videoCount = videos.length;
    const emptyHtml = '<p style="color: #94a3b8; font-style: italic;">No videos found</p>';

    if (videoCount === 0) {
        if (container) container.innerHTML = emptyHtml;
        if (sameContainer) sameContainer.innerHTML = emptyHtml;
        return;
    }

    const isChanged = duplicateState.changedVideos && duplicateState.changedVideos.length > 0;
    const changedLabel = isChanged ? `<span style="display: inline-block; margin-left: 8px; padding: 2px 8px; background: #fef3c7; color: #92400e; border-radius: 4px; font-size: 11px; font-weight: 600;">Modified (${videoCount} videos)</span>` : '';

    const videosHtml = videos.map(video => `
        <div class="duplicate-video-item" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; min-width: 150px;">
            ${video.thumbnail_url ?
                `<img src="${video.thumbnail_url}" style="width: 40px; height: 40px; border-radius: 4px; object-fit: cover;" onerror="this.outerHTML='<div style=\\'width:40px;height:40px;background:#e2e8f0;border-radius:4px;display:flex;align-items:center;justify-content:center;\\'>🎬</div>'" />`
                : '<div style="width:40px;height:40px;background:#e2e8f0;border-radius:4px;display:flex;align-items:center;justify-content:center;">🎬</div>'}
            <div style="flex: 1; min-width: 0;">
                <div style="font-weight: 500; font-size: 13px; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    ${video.name || 'Video'}
                </div>
            </div>
        </div>
    `).join('');

    if (container) container.innerHTML = changedLabel + videosHtml;
    if (sameContainer) sameContainer.innerHTML = changedLabel + videosHtml;
}

// Format duration in MM:SS
function formatDuration(seconds) {
    if (!seconds) return '';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

// ============================================
// BUTTON CLICK AND CURSOR FIXES
// Ensure buttons always maintain proper cursor and are clickable
// ============================================

// Fix cursor and click issues on all buttons
document.addEventListener('DOMContentLoaded', function() {
    // Function to fix button styles
    function fixButtonStyles(element) {
        if (element.tagName === 'BUTTON' ||
            element.classList.contains('btn-primary') ||
            element.classList.contains('btn-secondary') ||
            element.classList.contains('btn-success') ||
            element.hasAttribute('onclick')) {
            element.style.cursor = 'pointer';
            element.style.userSelect = 'none';
            element.style.webkitUserSelect = 'none';
        }
    }

    // Fix existing buttons
    document.querySelectorAll('button, [onclick], .btn-primary, .btn-secondary, .btn-success').forEach(fixButtonStyles);

    // Observe DOM changes to fix dynamically added buttons
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) { // Element node
                    fixButtonStyles(node);
                    // Also check children
                    if (node.querySelectorAll) {
                        node.querySelectorAll('button, [onclick], .btn-primary, .btn-secondary, .btn-success').forEach(fixButtonStyles);
                    }
                }
            });
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });

    // Prevent text selection on double-click on buttons
    document.addEventListener('selectstart', function(e) {
        // Guard against non-element targets (text nodes, etc.)
        if (!e.target || typeof e.target.closest !== 'function') {
            return;
        }
        const target = e.target.closest('button, [onclick], .btn-primary, .btn-secondary, .btn-success');
        if (target) {
            e.preventDefault();
        }
    });
});
