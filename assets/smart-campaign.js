// Smart+ Campaign JavaScript
// Flow: Step 1 CREATES Campaign -> Step 2 CREATES AdGroup -> Step 4 CREATES Ad
// Supports UPDATE when going back to modify existing resources

// Global state
let state = {
    currentStep: 1,
    campaignId: null,
    campaignName: null,
    campaignBudget: null,
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
    cboEnabled: true,
    ctaPortfolios: [],
    selectedPortfolioId: null,
    selectedPortfolioName: null,
    globalCtaPortfolioId: null,  // Portfolio ID for Lead Gen ads
    adTexts: [],  // Array of ad text variations

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
    selectedCampaigns: []        // Array of selected campaign IDs for bulk operations
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

        // Budget validation
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
    const requestBody = { action, ...data };

    // Log full request details
    addLog('request', `>>> ${action}`, {
        endpoint: apiUrl,
        action: action,
        parameters: data
    });

    try {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestBody)
        });

        const result = await response.json();

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

    loadPixels();
    loadIdentities();
    loadCtaPortfolios();  // Load CTA portfolios for Lead Gen campaigns
    loadMediaLibrary();
    initializeDayparting();
    initializeLocationTargeting();
    initializeAgeTargeting();  // Initialize age selection buttons
    loadBulkAccounts();  // Pre-load accounts for bulk launch feature

    state.cboEnabled = true;

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

// Toggle CBO budget section
function toggleCBOBudget() {
    const cboEnabled = document.getElementById('cbo-enabled').checked;

    // Step 1 elements
    const campaignBudgetSection = document.getElementById('campaign-budget-section');
    const cboDisabledNote = document.getElementById('campaign-cbo-disabled-note');

    // Step 2 elements
    const adGroupBudgetSection = document.getElementById('adgroup-budget-section');
    const cboBudgetNote = document.getElementById('cbo-budget-note');
    const displayBudgetInfo = document.getElementById('display-budget-info');

    state.cboEnabled = cboEnabled;

    if (cboEnabled) {
        // CBO Enabled: Show campaign budget input in Step 1, hide ad group budget in Step 2
        if (campaignBudgetSection) campaignBudgetSection.style.display = 'block';
        if (cboDisabledNote) cboDisabledNote.style.display = 'none';
        if (adGroupBudgetSection) adGroupBudgetSection.style.display = 'none';
        if (cboBudgetNote) cboBudgetNote.style.display = 'block';
        if (displayBudgetInfo) {
            const budgetVal = document.getElementById('campaign-budget')?.value || '50';
            displayBudgetInfo.innerHTML = '<strong>Budget:</strong> $<span id="display-budget">' + budgetVal + '</span>/day (Campaign Level)';
        }
    } else {
        // CBO Disabled: Hide campaign budget in Step 1, show ad group budget input in Step 2
        if (campaignBudgetSection) campaignBudgetSection.style.display = 'none';
        if (cboDisabledNote) cboDisabledNote.style.display = 'block';
        if (adGroupBudgetSection) adGroupBudgetSection.style.display = 'block';
        if (cboBudgetNote) cboBudgetNote.style.display = 'none';
        if (displayBudgetInfo) {
            displayBudgetInfo.innerHTML = '<strong>Budget:</strong> Set at Ad Group level (Step 2)';
        }
    }

    addLog('info', `CBO ${cboEnabled ? 'enabled' : 'disabled'} - Budget will be set at ${cboEnabled ? 'Campaign' : 'Ad Group'} level`);
}

// Load Pixels
async function loadPixels() {
    try {
        const result = await apiRequest('get_pixels');
        const select = document.getElementById('pixel-select');

        if (result.success && result.data && result.data.pixels) {
            select.innerHTML = '<option value="">Select a pixel...</option>';
            result.data.pixels.forEach(pixel => {
                const option = document.createElement('option');
                option.value = pixel.pixel_id;
                option.textContent = pixel.pixel_name || pixel.pixel_id;
                select.appendChild(option);
            });
            addLog('info', `Loaded ${result.data.pixels.length} pixels`);
        } else {
            select.innerHTML = '<option value="">No pixels found</option>';
        }
    } catch (error) {
        console.error('Error loading pixels:', error);
    }
}

// Load Identities
async function loadIdentities() {
    try {
        const result = await apiRequest('get_identities', {}, true);
        const select = document.getElementById('global-identity');

        if (result.success && result.data && result.data.list) {
            state.identities = result.data.list;
            select.innerHTML = '<option value="">Select identity...</option>';

            state.identities.forEach(identity => {
                const option = document.createElement('option');
                option.value = identity.identity_id;
                option.textContent = `${identity.display_name || identity.identity_name} (${identity.identity_type || 'CUSTOMIZED_USER'})`;
                select.appendChild(option);
            });

            addLog('info', `Loaded ${state.identities.length} identities`);
        } else {
            select.innerHTML = '<option value="">No identities found</option>';
            state.identities = [];
        }
    } catch (error) {
        console.error('Error loading identities:', error);
        state.identities = [];
    }
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

// Open Create Portfolio Modal
function openCreatePortfolioModal() {
    document.getElementById('portfolio-name-input').value = '';
    // Uncheck all except default
    document.querySelectorAll('.portfolio-cta-checkbox').forEach(cb => {
        cb.checked = cb.value === 'LEARN_MORE' || cb.value === 'GET_QUOTE';
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
async function loadMediaLibrary() {
    try {
        const [videosResult, imagesResult] = await Promise.all([
            apiRequest('get_videos'),
            apiRequest('get_images')
        ]);

        state.mediaLibrary = [];

        if (videosResult.success && videosResult.data) {
            videosResult.data.forEach(video => {
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
                state.mediaLibrary.push({
                    type: 'image',
                    id: image.image_id,
                    url: image.image_url,
                    name: image.file_name || image.image_id
                });
            });
        }

        addLog('info', `Loaded ${state.mediaLibrary.length} media items`);
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
        item.style.cssText = 'border: 2px solid #4fc3f7; border-radius: 8px; overflow: hidden; background: white;';

        item.innerHTML = `
            <div style="position: relative; height: 80px; background: #f5f5f5;">
                ${image.url ? `<img src="${image.url}" alt="${image.name}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='<div style=\\'background: linear-gradient(135deg, #4fc3f7, #29b6f6); width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px;\\'>🖼️</div>'">` : '<div style="background: linear-gradient(135deg, #4fc3f7, #29b6f6); width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px;">🖼️</div>'}
                <span style="position: absolute; top: 3px; right: 3px; background: #4fc3f7; color: white; padding: 2px 5px; border-radius: 3px; font-size: 9px; font-weight: bold;">IMG</span>
            </div>
            <div style="padding: 5px; font-size: 10px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #333;">
                ${(image.name || 'Image').substring(0, 15)}${image.name && image.name.length > 15 ? '...' : ''}
            </div>
        `;

        container.appendChild(item);
    });
}

// Refresh Media Library
async function refreshMediaLibrary() {
    showToast('Refreshing media library...', 'info');
    await loadMediaLibrary();
    showToast('Media library refreshed!', 'success');
}

// =====================
// Upload Functionality
// =====================
let currentUploadType = 'video';

function openUploadModal(type) {
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

    if (type === 'video') {
        title.textContent = '📹 Upload Video';
        icon.textContent = '🎬';
        hint.textContent = 'Supported: MP4, MOV, AVI (Max 500MB)';
        fileInput.accept = 'video/*';
    } else {
        title.textContent = '🖼️ Upload Image';
        icon.textContent = '📷';
        hint.textContent = 'Supported: JPG, PNG, GIF (Max 10MB)';
        fileInput.accept = 'image/*';
    }

    modal.style.display = 'flex';
}

function closeUploadModal() {
    document.getElementById('upload-modal').style.display = 'none';
    document.getElementById('media-file-input').value = '';
}

async function handleSmartMediaUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    const isVideo = file.type.startsWith('video/');
    const isImage = file.type.startsWith('image/');

    if (!isImage && !isVideo) {
        showToast('Please upload an image or video file', 'error');
        return;
    }

    // Check file size
    const maxSize = isVideo ? 500 * 1024 * 1024 : 10 * 1024 * 1024;
    if (file.size > maxSize) {
        showToast(`File too large. Maximum size is ${isVideo ? '500MB' : '10MB'}`, 'error');
        return;
    }

    // Show progress
    document.getElementById('upload-area').style.display = 'none';
    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-success').style.display = 'none';
    document.getElementById('upload-status').textContent = `Uploading ${file.name}...`;

    const formData = new FormData();
    formData.append(isVideo ? 'video' : 'image', file);

    try {
        addLog('request', `Uploading ${isVideo ? 'video' : 'image'}: ${file.name}`);

        // Simulate progress
        let progress = 0;
        const progressBar = document.getElementById('upload-progress-bar');
        const progressInterval = setInterval(() => {
            progress += Math.random() * 15;
            if (progress > 90) progress = 90;
            progressBar.style.width = progress + '%';
        }, 200);

        const response = await fetch(`api.php?action=${isVideo ? 'upload_video' : 'upload_image'}`, {
            method: 'POST',
            body: formData
        });

        clearInterval(progressInterval);
        progressBar.style.width = '100%';

        const result = await response.json();

        addLog(result.success ? 'response' : 'error', `Upload ${result.success ? 'successful' : 'failed'}`, result);

        if (result.success) {
            // Show success
            document.getElementById('upload-progress').style.display = 'none';
            document.getElementById('upload-success').style.display = 'block';
            document.getElementById('upload-success-name').textContent = `${file.name} uploaded successfully!`;

            showToast(`${isVideo ? 'Video' : 'Image'} uploaded successfully!`, 'success');

            // Reload media library
            await loadMediaLibrary();

            // Auto-close after 2 seconds
            setTimeout(() => {
                closeUploadModal();
            }, 2000);
        } else {
            document.getElementById('upload-area').style.display = 'block';
            document.getElementById('upload-progress').style.display = 'none';
            showToast(result.message || 'Upload failed', 'error');
        }
    } catch (error) {
        document.getElementById('upload-area').style.display = 'block';
        document.getElementById('upload-progress').style.display = 'none';
        addLog('error', 'Upload failed', { error: error.message });
        showToast('Error uploading file: ' + error.message, 'error');
    }

    event.target.value = '';
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
}

function selectAllStates() {
    document.querySelectorAll('.state-checkbox').forEach(cb => cb.checked = true);
    updateStatesCount();
}

function clearAllStates() {
    document.querySelectorAll('.state-checkbox').forEach(cb => cb.checked = false);
    updateStatesCount();
}

function updateStatesCount() {
    const count = document.querySelectorAll('.state-checkbox:checked').length;
    const countEl = document.getElementById('selected-states-count');
    if (countEl) countEl.textContent = count;
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

    days.forEach((day, dayIndex) => {
        const tr = document.createElement('tr');
        const dayCell = document.createElement('td');
        dayCell.innerHTML = `<strong>${day}</strong>`;
        tr.appendChild(dayCell);

        for (let hour = 0; hour <= 24; hour++) {
            const td = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'hour-checkbox';
            checkbox.dataset.day = dayIndex;
            checkbox.dataset.hour = hour;
            checkbox.title = `${day} ${hour}:00`;
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
                cb.checked = (day >= 1 && day <= 5 && hour >= 8 && hour < 17);
                break;

            case 'prime':
                // Prime Time: 6PM-11PM (hours 18-22), all days
                cb.checked = (hour >= 18 && hour < 23);
                break;

            case 'evening':
                // Evening: 5PM-12AM (hours 17-23), all days
                cb.checked = (hour >= 17 && hour <= 23);
                break;

            case 'daytime':
                // Daytime: 6AM-6PM (hours 6-17), all days
                cb.checked = (hour >= 6 && hour < 18);
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
            const displayBudgetEl = document.getElementById('display-budget');
            const displayCampaignIdEl = document.getElementById('display-campaign-id');
            const cboBudgetDisplay = document.getElementById('cbo-budget-display');

            if (displayNameEl) displayNameEl.textContent = campaignName;
            if (displayBudgetEl) displayBudgetEl.textContent = campaignBudget;
            if (displayCampaignIdEl) displayCampaignIdEl.textContent = result.campaign_id;
            if (cboBudgetDisplay) cboBudgetDisplay.textContent = campaignBudget;

            // Update Step 2 budget info based on CBO state
            const displayBudgetInfo = document.getElementById('display-budget-info');
            if (displayBudgetInfo) {
                if (state.cboEnabled) {
                    displayBudgetInfo.innerHTML = '<strong>Budget:</strong> $<span id="display-budget">' + campaignBudget + '</span>/day (Campaign Level)';
                } else {
                    displayBudgetInfo.innerHTML = '<strong>Budget:</strong> Set at Ad Group level below';
                }
            }

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

    // For LEAD_GENERATION objective: budget is ALWAYS at AdGroup level (not campaign)
    // Get budget from either campaign budget field (if CBO was enabled in step 1) or adgroup budget field
    let adGroupBudget;
    if (state.cboEnabled) {
        // If CBO was "enabled", the budget was entered in campaign budget field but still goes to AdGroup
        adGroupBudget = state.budget || parseFloat(document.getElementById('campaign-budget')?.value) || 50;
        addLog('info', `Using Campaign-level budget for AdGroup: $${adGroupBudget}`);
    } else {
        // If CBO was disabled, get from adgroup budget field
        adGroupBudget = parseFloat(document.getElementById('adgroup-budget')?.value) || 50;
        addLog('info', `Using AdGroup-level budget: $${adGroupBudget}`);
    }

    if (!pixelId) {
        showToast('Please select a pixel', 'error');
        return;
    }

    if (!adGroupBudget || adGroupBudget < 20) {
        showToast('Minimum budget is $20', 'error');
        return;
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
            return await updateAdGroup(adGroupBudget, locationIds, dayparting);
        }
    }

    showLoading('Creating Ad Group...');
    addLog('info', '=== Creating Smart+ Ad Group ===');

    try {
        // For LEAD_GENERATION: budget ALWAYS goes to AdGroup level
        const result = await apiRequest('create_smartplus_adgroup', {
            campaign_id: state.campaignId,
            adgroup_name: state.campaignName + ' - Ad Group',
            pixel_id: pixelId,
            optimization_event: optimizationEvent,
            location_ids: locationIds,
            age_groups: state.ageGroups,  // Age targeting
            dayparting: dayparting,
            budget: adGroupBudget  // Always at AdGroup level for LEAD_GENERATION
        });

        hideLoading();

        if (result.success && result.adgroup_id) {
            state.adGroupId = result.adgroup_id;
            state.pixelId = pixelId;
            state.optimizationEvent = optimizationEvent;
            state.locationIds = locationIds;
            state.dayparting = dayparting;
            state.adGroupBudget = adGroupBudget;
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
async function updateAdGroup(budget, locationIds, dayparting) {
    showLoading('Updating Ad Group...');
    addLog('info', '=== Updating Smart+ Ad Group ===');

    try {
        const result = await apiRequest('update_smartplus_adgroup', {
            adgroup_id: state.adGroupId,
            budget: budget,
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
            state.adGroupBudget = budget;

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
                ${video.url ? `<img src="${video.url}" alt="${video.name}">` : '<div class="no-preview">No Preview</div>'}
                <span class="video-badge">🎬</span>
                ${isSelected ? '<span class="selected-badge">✓</span>' : ''}
            </div>
            <div class="video-name">${(video.name || '').substring(0, 20)}${video.name && video.name.length > 20 ? '...' : ''}</div>
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

    const identity = state.identities.find(i => i.identity_id === identityId);
    const identityName = identity ? (identity.display_name || identity.identity_name) : identityId;

    // Populate review summaries
    const budgetDisplay = state.cboEnabled
        ? `$${state.budget}/day (Campaign Level)`
        : `$${state.adGroupBudget}/day (Ad Group Level)`;

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

    showLoading('Creating Smart+ Ad...');
    addLog('info', '=== Creating Smart+ Ad ===');

    try {
        const identity = state.identities.find(i => i.identity_id === state.globalIdentityId);
        const identityType = identity?.identity_type || 'CUSTOMIZED_USER';

        const creativeList = state.creatives.map(creative => ({
            video_id: creative.video_id,
            ad_text: creative.ad_text,
            image_id: creative.image_id || null
        }));

        // Log detailed creative list to verify each video is unique
        addLog('info', `Creating ad with ${creativeList.length} creatives and portfolio ${state.globalCtaPortfolioId}`);
        addLog('info', 'Creative list details:', creativeList.map((c, i) => `Creative ${i+1}: video_id=${c.video_id}`).join(', '));
        addLog('info', `Ad text variations: ${state.adTexts.length}`, state.adTexts);

        const result = await apiRequest('create_smartplus_ad', {
            adgroup_id: state.adGroupId,
            ad_name: state.campaignName + ' - Ad',
            identity_id: state.globalIdentityId,
            identity_type: identityType,
            landing_page_url: state.globalLandingUrl,
            call_to_action_id: state.globalCtaPortfolioId,  // Lead Gen requires Dynamic CTA Portfolio
            creatives: creativeList,
            ad_texts: state.adTexts  // Send unique ad text variations separately
        });

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
        const identity = state.identities.find(i => i.identity_id === state.globalIdentityId);
        const identityType = identity?.identity_type || 'CUSTOMIZED_USER';

        const creativeList = state.creatives.map(creative => ({
            video_id: creative.video_id,
            ad_text: creative.ad_text,
            image_id: creative.image_id || null
        }));

        const result = await apiRequest('update_smartplus_ad', {
            smart_plus_ad_id: state.adId,
            ad_name: state.campaignName + ' - Ad',
            ad_text_list: state.adTexts.map(text => ({ ad_text: text })),
            landing_page_url_list: state.globalLandingUrl ? [{ landing_page_url: state.globalLandingUrl }] : [],
            ad_configuration: {
                call_to_action_id: state.globalCtaPortfolioId,
                identity_id: state.globalIdentityId,
                identity_type: identityType
            }
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
function openCreateIdentityModal() {
    document.getElementById('identity-display-name').value = '';
    document.getElementById('identity-char-count').textContent = '0';
    document.getElementById('create-identity-modal').style.display = 'flex';
}

function closeCreateIdentityModal() {
    document.getElementById('create-identity-modal').style.display = 'none';
}

async function createCustomIdentity() {
    const displayName = document.getElementById('identity-display-name').value.trim();

    if (!displayName) {
        showToast('Please enter a display name', 'error');
        return;
    }

    showLoading('Creating identity...');

    try {
        const result = await apiRequest('create_identity', { display_name: displayName });

        if (result.success && result.identity_id) {
            const newIdentity = {
                identity_id: result.identity_id,
                display_name: displayName,
                identity_type: 'CUSTOMIZED_USER'
            };
            state.identities.push(newIdentity);

            const select = document.getElementById('global-identity');
            const option = document.createElement('option');
            option.value = result.identity_id;
            option.textContent = `${displayName} (CUSTOMIZED_USER)`;
            option.selected = true;
            select.appendChild(option);

            closeCreateIdentityModal();
            showToast('Identity created successfully!', 'success');
        } else {
            showToast(result.message || 'Failed to create identity', 'error');
        }
    } catch (error) {
        showToast('Error creating identity: ' + error.message, 'error');
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

function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
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

// Finish and redirect to advertiser selection page
function finishAndReset() {
    // Remove success modal
    const modal = document.getElementById('success-modal');
    if (modal) {
        modal.remove();
    }

    // Redirect to advertiser selection page (home)
    window.location.href = 'select-advertiser-oauth.php';
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
    document.getElementById('bulk-campaign-budget').textContent = state.budget || state.adGroupBudget || '0';

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

    for (const account of bulkLaunchState.accounts) {
        // Skip current account (it's already used for the primary campaign)
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
                <button type="button" class="btn-load-assets" onclick="loadAccountAssets('${account.advertiser_id}')"
                        ${assets ? 'style="display:none;"' : ''}>
                    Load Assets
                </button>
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

// Render asset dropdowns for an account
function renderAccountAssetsDropdowns(advertiserId, assets) {
    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    const selectedPixelId = selectedAccount?.pixel_id || '';
    const selectedIdentityId = selectedAccount?.identity_id || '';
    const errors = assets.errors || {};

    // Check if we have pixels or if there was an error
    const pixelsArray = assets.pixels || [];
    const identitiesArray = assets.identities || [];
    const hasPixelError = errors.pixels;
    const hasIdentityError = errors.identities;

    let html = '';

    // Show any API errors at the top
    if (Object.keys(errors).length > 0) {
        html += `<div class="asset-errors" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 6px; padding: 10px; margin-bottom: 10px; font-size: 12px;">
            <strong style="color: #ef4444;">⚠ API Issues:</strong>
            <ul style="margin: 5px 0 0 0; padding-left: 20px; color: #ef4444;">
                ${Object.entries(errors).map(([key, msg]) => `<li>${key}: ${msg}</li>`).join('')}
            </ul>
        </div>`;
    }

    html += `
        <div class="asset-row">
            <label>Pixel:</label>
            <select id="pixel-${advertiserId}" onchange="updateAccountAssetSelection('${advertiserId}')">
                ${pixelsArray.length === 0
                    ? `<option value="">${hasPixelError ? '⚠ Error loading pixels' : 'No pixels found'}</option>`
                    : `<option value="">Select Pixel...</option>
                       ${pixelsArray.map(p =>
                           `<option value="${p.pixel_id}" ${p.pixel_id === selectedPixelId ? 'selected' : ''}>${p.pixel_name || p.pixel_id}</option>`
                       ).join('')}`
                }
            </select>
        </div>
        <div class="asset-row">
            <label>Identity:</label>
            <select id="identity-${advertiserId}" onchange="updateAccountAssetSelection('${advertiserId}')">
                ${identitiesArray.length === 0
                    ? `<option value="">${hasIdentityError ? '⚠ Error loading identities' : 'No identities found'}</option>`
                    : `<option value="">Select Identity...</option>
                       ${identitiesArray.map(i =>
                           `<option value="${i.identity_id}" data-type="${i.identity_type || 'CUSTOMIZED_USER'}" ${i.identity_id === selectedIdentityId ? 'selected' : ''}>${i.display_name || i.identity_name || i.identity_id}</option>`
                       ).join('')}`
                }
            </select>
        </div>
    `;

    // Video matching status
    const videoMatch = bulkLaunchState.accountAssets[advertiserId]?.videoMatch;
    if (videoMatch) {
        const matchRate = videoMatch.match_rate || 0;
        const statusClass = matchRate === 100 ? 'success' : matchRate > 0 ? 'warning' : 'error';
        html += `
            <div class="asset-row video-match-status ${statusClass}">
                <span class="match-icon">${matchRate === 100 ? '✓' : matchRate > 0 ? '⚠' : '✗'}</span>
                <span>Videos: ${videoMatch.matched?.length || 0}/${state.selectedVideos.length} matched (${matchRate}%)</span>
                <button type="button" class="btn-toggle-library" onclick="toggleMediaLibrary('${advertiserId}')">
                    📁 View Library
                </button>
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

    // Sort videos alphabetically by filename
    const videos = [...accountAssets.videos].sort((a, b) => {
        const nameA = (a.file_name || '').toLowerCase();
        const nameB = (b.file_name || '').toLowerCase();
        return nameA.localeCompare(nameB);
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
        grid.innerHTML = `
            <div class="picker-empty-state">
                <div class="empty-icon">🎬</div>
                <p>${videoPickerState.searchTerm ? 'No videos match your search' : 'No videos in this account'}</p>
            </div>
        `;
        return;
    }

    let html = '';
    filteredVideos.forEach(video => {
        const isSelected = videoPickerState.currentSelection === video.video_id;
        const fileName = video.file_name || video.video_id || 'Untitled';
        const coverUrl = video.video_cover_url || '';

        html += `
            <div class="picker-video-item ${isSelected ? 'selected' : ''}"
                 onclick="selectVideoFromPicker('${video.video_id}', '${fileName.replace(/'/g, "\\'")}')">
                ${coverUrl
                    ? `<img src="${coverUrl}" alt="${fileName}" loading="lazy">`
                    : '<div class="no-preview">🎬</div>'}
                <div class="picker-video-info">
                    <span class="picker-video-name">${fileName}</span>
                </div>
                <div class="selected-badge">✓</div>
                <div class="select-hint">${isSelected ? 'Selected' : 'Click to select'}</div>
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

    const hasPixel = !!account.pixel_id;
    const hasIdentity = !!account.identity_id;
    const videoMatch = bulkLaunchState.accountAssets[advertiserId]?.videoMatch;
    const hasVideos = videoMatch && videoMatch.match_rate === 100;

    if (hasPixel && hasIdentity && hasVideos) {
        return '<span class="status-ready">✓ Ready to launch</span>';
    } else {
        const missing = [];
        if (!hasPixel) missing.push('pixel');
        if (!hasIdentity) missing.push('identity');
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
            // Sort videos alphabetically by filename before storing
            if (result.data.videos && Array.isArray(result.data.videos)) {
                result.data.videos.sort((a, b) => {
                    const nameA = (a.file_name || '').toLowerCase();
                    const nameB = (b.file_name || '').toLowerCase();
                    return nameA.localeCompare(nameB);
                });
            }

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

            // Render dropdowns
            assetsContainer.innerHTML = renderAccountAssetsDropdowns(advertiserId, result.data);

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

    const selectedAccount = bulkLaunchState.selectedAccounts.find(a => a.advertiser_id === advertiserId);
    if (!selectedAccount) return;

    if (pixelSelect) {
        selectedAccount.pixel_id = pixelSelect.value;
    }

    if (identitySelect) {
        selectedAccount.identity_id = identitySelect.value;
        const selectedOption = identitySelect.options[identitySelect.selectedIndex];
        selectedAccount.identity_type = selectedOption?.dataset?.type || 'CUSTOMIZED_USER';
    }

    // Update status
    const statusEl = document.getElementById(`status-${advertiserId}`);
    if (statusEl) {
        statusEl.innerHTML = getAccountStatus(advertiserId);
    }

    updateBulkModalCounts();
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

// Update counts in the modal
function updateBulkModalCounts() {
    const selectedCount = bulkLaunchState.selectedAccounts.length;
    const readyCount = bulkLaunchState.selectedAccounts.filter(a => {
        const assets = bulkLaunchState.accountAssets[a.advertiser_id];
        const videoMatch = assets?.videoMatch;
        return a.pixel_id && a.identity_id && videoMatch && videoMatch.match_rate === 100;
    }).length;

    const budget = parseFloat(state.budget || state.adGroupBudget || 0);
    const totalBudget = budget * selectedCount;

    // Modal counts
    document.getElementById('modal-selected-count').textContent = selectedCount;
    document.getElementById('modal-total-accounts').textContent = bulkLaunchState.accounts.filter(a => !a.is_current).length;
    document.getElementById('modal-ready-accounts').textContent = readyCount;
    document.getElementById('modal-total-budget').textContent = `$${totalBudget.toFixed(2)}`;

    // Enable/disable confirm button
    const confirmBtn = document.getElementById('confirm-bulk-config-btn');
    if (confirmBtn) {
        confirmBtn.disabled = selectedCount === 0;
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

    // Check if all selected accounts are ready
    const notReady = bulkLaunchState.selectedAccounts.filter(a => {
        const assets = bulkLaunchState.accountAssets[a.advertiser_id];
        const videoMatch = assets?.videoMatch;
        return !a.pixel_id || !a.identity_id || !videoMatch || videoMatch.match_rate < 100;
    });

    if (notReady.length > 0) {
        const names = notReady.map(a => a.advertiser_name).join(', ');
        showToast(`Some accounts are not ready: ${names}. Please configure pixel, identity, and ensure videos are matched.`, 'warning');
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
    const budget = parseFloat(state.budget || state.adGroupBudget || 0);
    document.getElementById('bulk-selected-count').textContent = bulkLaunchState.selectedAccounts.length;
    document.getElementById('bulk-total-budget').textContent = `$${(budget * bulkLaunchState.selectedAccounts.length).toFixed(2)}`;
    document.getElementById('bulk-ready-count').textContent = bulkLaunchState.selectedAccounts.length;

    // Render accounts list
    accountsList.innerHTML = bulkLaunchState.selectedAccounts.map(a => `
        <div class="bulk-account-item">
            <span class="account-name">${a.advertiser_name}</span>
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

    // Get identity type
    const identity = state.identities.find(i => i.identity_id === state.globalIdentityId);
    const identityType = identity?.identity_type || 'CUSTOMIZED_USER';

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

                const campaignResult = await apiRequest('create_smartplus_campaign', {
                    campaign_name: campaignName,
                    budget: state.budget,
                    budget_mode: 'BUDGET_MODE_DAY',
                    cbo_enabled: state.cboEnabled
                });

                if (!campaignResult.success) {
                    throw new Error(campaignResult.message || 'Failed to create campaign');
                }

                newCampaignId = campaignResult.campaign_id || campaignResult.data?.campaign_id;

                // Generate ad group name based on campaign name
                const adGroupName = campaignName + ' Ad Group';

                addLog('info', `Creating ad group: "${adGroupName}"`);

                // Create ad group for this campaign
                const adGroupResult = await apiRequest('create_smartplus_adgroup', {
                    campaign_id: newCampaignId,
                    adgroup_name: adGroupName,
                    pixel_id: state.pixelId,
                    optimization_event: state.optimizationEvent,
                    location_ids: state.locationIds,
                    age_groups: state.ageGroups,
                    dayparting: state.dayparting,
                    budget: state.cboEnabled ? null : state.adGroupBudget
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
            const adResult = await apiRequest('create_smartplus_ad', {
                adgroup_id: newAdGroupId,
                ad_name: adName,
                identity_id: state.globalIdentityId,
                identity_type: identityType,
                landing_page_url: state.globalLandingUrl,
                call_to_action_id: state.globalCtaPortfolioId,
                creatives: creativeList,
                ad_texts: state.adTexts
            });

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

    // Show progress modal
    const progressModal = document.getElementById('bulk-progress-modal');
    const progressList = document.getElementById('bulk-progress-list');
    const progressBar = document.getElementById('bulk-progress-bar');
    const progressFooter = document.getElementById('bulk-progress-footer');

    progressModal.style.display = 'flex';
    progressFooter.style.display = 'none';
    progressList.innerHTML = '';
    progressBar.style.width = '0%';

    // Update progress stats
    document.getElementById('progress-total').textContent = bulkLaunchState.selectedAccounts.length;
    document.getElementById('progress-completed').textContent = '0';
    document.getElementById('progress-success').textContent = '0';
    document.getElementById('progress-failed').textContent = '0';

    // Add initial progress items
    bulkLaunchState.selectedAccounts.forEach(account => {
        progressList.innerHTML += `
            <div class="progress-item" id="progress-item-${account.advertiser_id}">
                <span class="progress-account-name">${account.advertiser_name}</span>
                <span class="progress-status pending">Pending...</span>
            </div>
        `;
    });

    addLog('info', `Starting bulk launch to ${bulkLaunchState.selectedAccounts.length} accounts`);

    // Get duplicate settings
    const duplicatesEnabled = document.getElementById('bulk-enable-duplicates')?.checked || false;
    const duplicateCount = duplicatesEnabled ? parseInt(document.getElementById('bulk-duplicate-count')?.value) || 1 : 1;

    // Build campaign config
    const campaignConfig = {
        campaign_name: state.campaignName,
        budget: state.budget || state.adGroupBudget,
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
        duplicate_count: duplicateCount  // Add duplicate count to config
    };

    // Prepare accounts with video mappings
    const accountsToLaunch = bulkLaunchState.selectedAccounts.map(account => ({
        advertiser_id: account.advertiser_id,
        advertiser_name: account.advertiser_name,
        pixel_id: account.pixel_id,
        identity_id: account.identity_id,
        identity_type: account.identity_type,
        video_mapping: account.video_mapping || {}
    }));

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

            // Update progress UI
            let completed = 0;
            let successCount = 0;
            let failedCount = 0;

            // Update success items
            (data.success || []).forEach(item => {
                completed++;
                successCount++;
                updateProgressItem(item.advertiser_id, 'success', `✓ Campaign: ${item.campaign_id}`);
            });

            // Update failed items
            (data.failed || []).forEach(item => {
                completed++;
                failedCount++;
                updateProgressItem(item.advertiser_id, 'failed', `✗ ${item.error}`);
            });

            // Update stats
            document.getElementById('progress-completed').textContent = completed;
            document.getElementById('progress-success').textContent = successCount;
            document.getElementById('progress-failed').textContent = failedCount;
            progressBar.style.width = '100%';

            // Show footer
            progressFooter.style.display = 'block';

            addLog('info', `Bulk launch completed: ${successCount} success, ${failedCount} failed`);

            if (failedCount === 0) {
                showToast(`Successfully launched to ${successCount} accounts!`, 'success');
            } else if (successCount > 0) {
                showToast(`Launched to ${successCount} accounts, ${failedCount} failed`, 'warning');
            } else {
                showToast(`Bulk launch failed for all accounts`, 'error');
            }
        } else {
            showToast('Bulk launch failed: ' + (result.message || 'Unknown error'), 'error');
            progressFooter.style.display = 'block';
        }
    } catch (error) {
        addLog('error', 'Bulk launch error: ' + error.message);
        showToast('Error during bulk launch: ' + error.message, 'error');
        progressFooter.style.display = 'block';
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
    }
}

// Close bulk progress modal
function closeBulkProgressModal() {
    document.getElementById('bulk-progress-modal').style.display = 'none';

    // Optionally refresh or redirect
    showSuccessModalBulk();
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
    bulkLaunchState.duplicateCount = enabled ? (parseInt(document.getElementById('bulk-duplicate-count')?.value) || 2) : 1;

    updateBulkModalCounts();
    addLog('info', `Bulk duplicates ${enabled ? 'enabled' : 'disabled'}, count: ${bulkLaunchState.duplicateCount}`);
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

// Switch between Create and My Campaigns views
function switchMainView(view) {
    state.currentView = view;

    const createView = document.getElementById('create-view');
    const campaignsView = document.getElementById('campaigns-view');
    const tabCreate = document.getElementById('tab-create');
    const tabCampaigns = document.getElementById('tab-campaigns');

    if (view === 'create') {
        createView.style.display = 'block';
        campaignsView.style.display = 'none';
        tabCreate.classList.add('active');
        tabCampaigns.classList.remove('active');
    } else if (view === 'campaigns') {
        createView.style.display = 'none';
        campaignsView.style.display = 'block';
        tabCreate.classList.remove('active');
        tabCampaigns.classList.add('active');

        // Load campaigns if not already loaded
        if (!state.campaignsLoaded) {
            loadCampaigns();
        }
    }

    addLog('info', `Switched to ${view} view`);
}

// Load campaigns from API
async function loadCampaigns() {
    const loadingEl = document.getElementById('campaign-loading');
    const emptyEl = document.getElementById('campaign-empty-state');
    const cardsContainer = document.getElementById('campaign-cards-container');

    // Show loading state
    loadingEl.style.display = 'flex';
    emptyEl.style.display = 'none';
    cardsContainer.innerHTML = '';

    addLog('info', 'Loading campaigns...');

    try {
        const result = await apiRequest('get_campaigns', {});

        if (result.success) {
            state.campaignsList = result.campaigns || [];
            state.campaignsLoaded = true;

            addLog('success', `Loaded ${state.campaignsList.length} campaigns`);

            // Apply current filter and render
            applyFiltersAndRender();
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
    loadCampaigns();
    showToast('Refreshing campaigns...', 'info');
}

// Filter campaigns by status
function filterCampaignsByStatus(status) {
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
}

// Render campaign list
function renderCampaignList() {
    const loadingEl = document.getElementById('campaign-loading');
    const emptyEl = document.getElementById('campaign-empty-state');
    const cardsContainer = document.getElementById('campaign-cards-container');

    // Hide loading
    loadingEl.style.display = 'none';

    // Check if empty
    if (state.filteredCampaigns.length === 0) {
        emptyEl.style.display = 'block';
        emptyEl.querySelector('h3').textContent = 'No campaigns found';
        emptyEl.querySelector('p').textContent =
            state.campaignSearchQuery
                ? 'No campaigns match your search. Try a different keyword.'
                : state.campaignFilter !== 'all'
                    ? `No ${state.campaignFilter} campaigns found.`
                    : "You haven't created any campaigns yet.";
        cardsContainer.innerHTML = '';
        return;
    }

    emptyEl.style.display = 'none';

    // Render campaign cards
    cardsContainer.innerHTML = state.filteredCampaigns.map(campaign => renderCampaignCard(campaign)).join('');
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

// Toggle campaign status (ON/OFF)
async function toggleCampaignStatus(campaignId, currentStatus) {
    const toggleEl = document.querySelector(`.campaign-toggle[data-campaign-id="${campaignId}"]`);
    const cardEl = document.querySelector(`.my-campaign-card[data-campaign-id="${campaignId}"]`);

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

            // Update UI
            toggleEl.classList.remove('loading');
            toggleEl.classList.toggle('on', newStatus === 'ENABLE');
            toggleEl.dataset.status = newStatus;

            // Update status badge
            const badgeEl = cardEl.querySelector('.campaign-status-badge');
            if (badgeEl) {
                badgeEl.className = `campaign-status-badge ${newStatus === 'ENABLE' ? 'active' : 'inactive'}`;
                badgeEl.textContent = newStatus === 'ENABLE' ? 'Active' : 'Inactive';
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
    isProcessing: false
};

// Open duplicate campaign modal
async function openDuplicateCampaignModal(campaignId, campaignName) {
    duplicateState.campaignId = campaignId;
    duplicateState.campaignName = campaignName;
    duplicateState.campaignDetails = null;

    // Show modal
    const modal = document.getElementById('duplicate-campaign-modal');
    modal.style.display = 'flex';

    // Update campaign info
    document.getElementById('duplicate-campaign-name').textContent = campaignName;
    document.getElementById('duplicate-campaign-id').textContent = campaignId;

    // Reset sections
    document.getElementById('duplicate-loading-state').style.display = 'block';
    document.getElementById('duplicate-details-section').style.display = 'none';
    document.getElementById('duplicate-progress-section').style.display = 'none';
    document.getElementById('duplicate-success-section').style.display = 'none';

    // Reset footer buttons
    const footer = document.getElementById('duplicate-modal-footer');
    footer.innerHTML = `
        <button class="btn-secondary" onclick="closeDuplicateCampaignModal()">Cancel</button>
        <button class="btn-primary" id="duplicate-create-btn" onclick="executeDuplicateCampaign()" disabled>
            📋 Create Copies
        </button>
    `;

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

            // Show details section
            document.getElementById('duplicate-loading-state').style.display = 'none';
            document.getElementById('duplicate-details-section').style.display = 'block';

            // Enable create button
            document.getElementById('duplicate-create-btn').disabled = false;

            // Set default count and update preview
            document.getElementById('duplicate-copy-count').value = 1;
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
        isProcessing: false
    };

    // Reset loading state for next open
    document.getElementById('duplicate-loading-state').innerHTML = `
        <div class="spinner"></div>
        <p style="margin-top: 15px; color: #666;">Fetching campaign details...</p>
    `;
}

// Adjust duplicate count with +/- buttons
function adjustDuplicateCount(delta) {
    const input = document.getElementById('duplicate-copy-count');
    let value = parseInt(input.value) || 1;
    value = Math.max(1, Math.min(20, value + delta));
    input.value = value;
    updateDuplicatePreviewList();
}

// Update the preview list showing campaign names
function updateDuplicatePreviewList() {
    const count = parseInt(document.getElementById('duplicate-copy-count').value) || 1;
    const baseName = duplicateState.campaignName || 'Campaign';
    const previewList = document.getElementById('duplicate-preview-list');

    let html = '';
    for (let i = 1; i <= Math.min(count, 20); i++) {
        const newName = `${baseName} (${i})`;
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

    const count = parseInt(document.getElementById('duplicate-copy-count').value) || 1;
    if (count < 1 || count > 20) {
        showToast('Please enter a valid number between 1 and 20', 'error');
        return;
    }

    duplicateState.isProcessing = true;

    // Hide details, show progress
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
    const baseName = campaign.campaign_name;
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
        const newName = `${baseName} (${i})`;

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
                budget: campaign.budget
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
                const adgroupResult = await apiRequest('create_smartplus_adgroup', {
                    campaign_id: newCampaignId,
                    adgroup_name: newName,
                    pixel_id: adgroup.pixel_id,
                    optimization_event: adgroup.optimization_event,
                    location_ids: adgroup.location_ids || [],
                    age_groups: adgroup.age_groups || []
                });

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

                // Build creatives array from video_ids or smart_creative_request
                let creatives = [];

                // Check if we have smart_creative_request (Smart+ ad format)
                if (ad.smart_creative_request && ad.smart_creative_request.creative_list) {
                    // Extract video IDs AND image IDs from smart_creative_request
                    ad.smart_creative_request.creative_list.forEach(item => {
                        if (item.creative_info && item.creative_info.video_info) {
                            const creative = {
                                video_id: item.creative_info.video_info.video_id
                            };
                            // Also extract image_id (cover image) if available
                            if (item.creative_info.image_info && item.creative_info.image_info.length > 0) {
                                // image_info can contain web_uri or image_id
                                const imageInfo = item.creative_info.image_info[0];
                                creative.image_id = imageInfo.web_uri || imageInfo.image_id || null;
                            }
                            creatives.push(creative);
                        }
                    });
                } else if (ad.video_ids && ad.video_ids.length > 0) {
                    // Use video_ids array - try to match with image_ids if available
                    creatives = ad.video_ids.map((vid, index) => {
                        const creative = { video_id: vid };
                        // Try to get corresponding image_id if available
                        if (ad.image_ids && ad.image_ids[index]) {
                            creative.image_id = ad.image_ids[index];
                        }
                        return creative;
                    });
                } else if (ad.video_id) {
                    // Single video_id
                    const creative = { video_id: ad.video_id };
                    // Try to get image_id if available
                    if (ad.image_ids && ad.image_ids.length > 0) {
                        creative.image_id = ad.image_ids[0];
                    }
                    creatives = [creative];
                }

                // Log creative extraction details
                console.log('Creatives extracted:', JSON.stringify(creatives, null, 2));
                console.log('Ad structure:', {
                    has_smart_creative_request: !!ad.smart_creative_request,
                    has_creative_list: !!(ad.smart_creative_request?.creative_list),
                    video_ids: ad.video_ids,
                    video_id: ad.video_id,
                    image_ids: ad.image_ids
                });

                // Validate we have at least one creative
                if (creatives.length === 0) {
                    addLog('warning', 'No video creatives found in original ad, skipping ad creation');
                    addLog('error', `Ad structure: smart_creative_request=${!!ad.smart_creative_request}, video_ids=${JSON.stringify(ad.video_ids)}, video_id=${ad.video_id}`);
                    throw new Error('No video creatives found in the original ad to duplicate');
                }

                addLog('info', `Found ${creatives.length} creative(s) to duplicate`);

                // Get landing page URL
                let landingPageUrl = ad.landing_page_url;
                if (!landingPageUrl && ad.landing_page_urls && ad.landing_page_urls.length > 0) {
                    // Extract from landing_page_urls array
                    if (typeof ad.landing_page_urls[0] === 'object') {
                        landingPageUrl = ad.landing_page_urls[0].landing_page_url;
                    } else {
                        landingPageUrl = ad.landing_page_urls[0];
                    }
                }

                // Prepare ad data for Smart+ ad creation
                // Get call_to_action_id from multiple possible sources
                let callToActionId = ad.call_to_action_id;

                // Fallback 1: Check ad_configuration
                if (!callToActionId && ad.ad_configuration?.call_to_action_id) {
                    callToActionId = ad.ad_configuration.call_to_action_id;
                    console.log('Using call_to_action_id from ad_configuration');
                }

                // Fallback 2: Check default_cta_portfolio from backend
                if (!callToActionId && duplicateState.campaignDetails?.default_cta_portfolio?.id) {
                    callToActionId = duplicateState.campaignDetails.default_cta_portfolio.id;
                    console.log('Using call_to_action_id from default_cta_portfolio:', callToActionId);
                }

                console.log('Final call_to_action_id:', callToActionId);

                if (!callToActionId) {
                    addLog('warning', 'No CTA Portfolio ID found');
                    addLog('error', 'Backend did not provide a CTA portfolio. Check server logs.');
                    throw new Error('No CTA Portfolio ID available. The system could not find or create one.');
                }

                const adData = {
                    adgroup_id: newAdGroupId,
                    ad_name: newName,
                    identity_id: ad.identity_id,
                    call_to_action_id: callToActionId,
                    landing_page_url: landingPageUrl,
                    creatives: creatives,
                    ad_texts: ad.ad_texts || (ad.ad_text ? [ad.ad_text] : [])
                };

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
