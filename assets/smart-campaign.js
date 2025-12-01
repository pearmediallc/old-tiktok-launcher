// Smart+ Campaign JavaScript
// Flow: Step 1 CREATES Campaign -> Step 2 CREATES AdGroup -> Step 4 CREATES Ad

// Global state
let state = {
    currentStep: 1,
    campaignId: null,
    campaignName: null,
    campaignBudget: null,
    adGroupId: null,
    pixelId: null,
    optimizationEvent: null,
    locationIds: [],
    dayparting: null,
    creatives: [],
    identities: [],
    mediaLibrary: [],
    selectedVideos: [],
    cboEnabled: true,
    ctaPortfolios: [],
    selectedPortfolioId: null,
    selectedPortfolioName: null,
    globalCtaPortfolioId: null  // Portfolio ID for Lead Gen ads
};

// US States with TikTok location IDs
const US_STATES = [
    { id: '5128638', name: 'New York', abbr: 'NY' },
    { id: '5332921', name: 'California', abbr: 'CA' },
    { id: '4736286', name: 'Texas', abbr: 'TX' },
    { id: '4155751', name: 'Florida', abbr: 'FL' },
    { id: '6254926', name: 'Massachusetts', abbr: 'MA' },
    { id: '4597040', name: 'South Carolina', abbr: 'SC' },
    { id: '4831725', name: 'Connecticut', abbr: 'CT' },
    { id: '5001836', name: 'Ohio', abbr: 'OH' },
    { id: '4662168', name: 'Tennessee', abbr: 'TN' },
    { id: '4138106', name: 'District of Columbia', abbr: 'DC' },
    { id: '4361885', name: 'Maryland', abbr: 'MD' },
    { id: '4566966', name: 'Puerto Rico', abbr: 'PR' },
    { id: '4099753', name: 'Arkansas', abbr: 'AR' },
    { id: '4398678', name: 'Missouri', abbr: 'MO' },
    { id: '4273857', name: 'Kansas', abbr: 'KS' },
    { id: '5509151', name: 'Nevada', abbr: 'NV' },
    { id: '5549030', name: 'Utah', abbr: 'UT' },
    { id: '5481136', name: 'New Mexico', abbr: 'NM' },
    { id: '5073708', name: 'Nebraska', abbr: 'NE' },
    { id: '5769223', name: 'South Dakota', abbr: 'SD' },
    { id: '5690763', name: 'North Dakota', abbr: 'ND' },
    { id: '5667009', name: 'Montana', abbr: 'MT' },
    { id: '5843591', name: 'Wyoming', abbr: 'WY' },
    { id: '5417618', name: 'Colorado', abbr: 'CO' },
    { id: '5551752', name: 'Arizona', abbr: 'AZ' },
    { id: '5596512', name: 'Idaho', abbr: 'ID' },
    { id: '5815135', name: 'Washington', abbr: 'WA' },
    { id: '5744337', name: 'Oregon', abbr: 'OR' },
    { id: '4862182', name: 'Iowa', abbr: 'IA' },
    { id: '5037779', name: 'Minnesota', abbr: 'MN' },
    { id: '5279468', name: 'Wisconsin', abbr: 'WI' },
    { id: '4896861', name: 'Illinois', abbr: 'IL' },
    { id: '4921868', name: 'Indiana', abbr: 'IN' },
    { id: '4998796', name: 'Michigan', abbr: 'MI' },
    { id: '6254925', name: 'Pennsylvania', abbr: 'PA' },
    { id: '5101760', name: 'New Jersey', abbr: 'NJ' },
    { id: '4142224', name: 'Delaware', abbr: 'DE' },
    { id: '4826850', name: 'West Virginia', abbr: 'WV' },
    { id: '4752186', name: 'Virginia', abbr: 'VA' },
    { id: '4482348', name: 'North Carolina', abbr: 'NC' },
    { id: '4197000', name: 'Georgia', abbr: 'GA' },
    { id: '4829764', name: 'Alabama', abbr: 'AL' },
    { id: '4436296', name: 'Mississippi', abbr: 'MS' },
    { id: '4331987', name: 'Louisiana', abbr: 'LA' },
    { id: '4544379', name: 'Oklahoma', abbr: 'OK' },
    { id: '6254926', name: 'Kentucky', abbr: 'KY' },
    { id: '5090174', name: 'New Hampshire', abbr: 'NH' },
    { id: '5224323', name: 'Rhode Island', abbr: 'RI' },
    { id: '5242283', name: 'Vermont', abbr: 'VT' },
    { id: '4971068', name: 'Maine', abbr: 'ME' },
    { id: '5855797', name: 'Hawaii', abbr: 'HI' },
    { id: '5879092', name: 'Alaska', abbr: 'AK' }
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

    state.cboEnabled = true;

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
    } catch (error) {
        console.error('Error loading media:', error);
        addLog('error', 'Failed to load media library');
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
    if (method === 'country') {
        return ['6252001'];
    }

    const selected = [];
    document.querySelectorAll('.state-checkbox:checked').forEach(cb => {
        selected.push(cb.value);
    });
    return selected.length > 0 ? selected : ['6252001'];
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

function selectAllHours() {
    document.querySelectorAll('.hour-checkbox').forEach(cb => cb.checked = true);
}

function clearAllHours() {
    document.querySelectorAll('.hour-checkbox').forEach(cb => cb.checked = false);
}

function selectBusinessHours() {
    clearAllHours();
    document.querySelectorAll('.hour-checkbox').forEach(cb => {
        const hour = parseInt(cb.dataset.hour);
        const day = parseInt(cb.dataset.day);
        cb.checked = (day >= 1 && day <= 5 && hour >= 8 && hour < 17);
    });
}

function selectPrimeTime() {
    clearAllHours();
    document.querySelectorAll('.hour-checkbox').forEach(cb => {
        const hour = parseInt(cb.dataset.hour);
        cb.checked = (hour >= 18 && hour < 22);
    });
}

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
// STEP 1: CREATE CAMPAIGN (Actually creates via API)
// =====================
async function createCampaign() {
    const campaignName = document.getElementById('campaign-name').value.trim();
    const campaignBudget = parseFloat(document.getElementById('campaign-budget').value) || 50;

    if (!campaignName) {
        showToast('Please enter a campaign name', 'error');
        return;
    }

    if (campaignBudget < 20) {
        showToast('Minimum budget is $20', 'error');
        return;
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

            showToast(`Campaign created! ID: ${result.campaign_id}`, 'success');
            addLog('info', `Campaign created: ${result.campaign_id}`);
            nextStep();
        } else {
            showToast('Failed to create campaign: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        hideLoading();
        showToast('Error creating campaign: ' + error.message, 'error');
    }
}

// =====================
// STEP 2: CREATE AD GROUP (Actually creates via API with campaign_id)
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

            // Update display
            const displayAdGroupIdEl = document.getElementById('display-adgroup-id');
            if (displayAdGroupIdEl) displayAdGroupIdEl.textContent = result.adgroup_id;

            // Also update campaign ID in step 3
            const displayCampaignIdStep3 = document.getElementById('display-campaign-id-step3');
            if (displayCampaignIdStep3) displayCampaignIdStep3.textContent = state.campaignId;

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
    renderCreativesList();
}

function selectAllVideos() {
    const videos = state.mediaLibrary.filter(m => m.type === 'video');
    state.selectedVideos = [...videos];
    renderVideoSelectionGrid();
    renderCreativesList();
}

function clearVideoSelection() {
    state.selectedVideos = [];
    renderVideoSelectionGrid();
    renderCreativesList();
}

function updateSelectedVideosCount() {
    const countEl = document.getElementById('selected-videos-count');
    if (countEl) countEl.textContent = state.selectedVideos.length;
}

function renderCreativesList() {
    const container = document.getElementById('creatives-list');
    if (!container) return;

    if (state.selectedVideos.length === 0) {
        container.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;">Select videos above to add creatives</p>';
        return;
    }

    container.innerHTML = '';

    state.selectedVideos.forEach((video, index) => {
        const existingCreative = state.creatives.find(c => c.video_id === video.id);
        const adText = existingCreative?.ad_text || '';

        const item = document.createElement('div');
        item.className = 'creative-item';
        item.innerHTML = `
            <div class="creative-header">
                <div class="creative-video-info">
                    ${video.url ? `<img src="${video.url}" class="creative-thumbnail">` : ''}
                    <span class="creative-number">Creative #${index + 1}</span>
                    <span class="creative-video-name">${video.name || video.id}</span>
                </div>
                <button type="button" class="btn-remove" onclick="removeCreative('${video.id}')">✕</button>
            </div>
            <div class="creative-body">
                <label>Ad Text (12-100 characters)</label>
                <textarea
                    id="ad-text-${video.id}"
                    placeholder="Enter your ad copy..."
                    rows="2"
                    onchange="updateCreativeText('${video.id}', this.value)"
                >${adText}</textarea>
            </div>
        `;
        container.appendChild(item);
    });

    state.creatives = state.selectedVideos.map(video => {
        const existing = state.creatives.find(c => c.video_id === video.id);
        // Find matching image cover from image library (by file_name pattern)
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

    // Update ad texts from form
    state.creatives.forEach(creative => {
        const textarea = document.getElementById(`ad-text-${creative.video_id}`);
        if (textarea) {
            creative.ad_text = textarea.value;
        }
    });

    if (state.creatives.length === 0) {
        showToast('Please select at least one video', 'error');
        return;
    }

    for (let i = 0; i < state.creatives.length; i++) {
        if (!state.creatives[i].ad_text || state.creatives[i].ad_text.length < 1) {
            showToast(`Creative ${i + 1}: Please enter ad text`, 'error');
            return;
        }
    }

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
            <p><strong>Total Creatives:</strong> ${state.creatives.length} videos</p>
        </div>
    `;

    state.creatives.forEach((creative, index) => {
        adsSummaryHtml += `
            <div class="summary-item">
                <h4>Creative #${index + 1}</h4>
                <p><strong>Video:</strong> ${creative.video_name || creative.video_id}</p>
                <p><strong>Ad Text:</strong> ${creative.ad_text}</p>
            </div>
        `;
    });

    document.getElementById('ads-summary').innerHTML = adsSummaryHtml;

    nextStep();
}

// Create Ad (Final step - actually creates the ad)
async function createAd() {
    if (!state.adGroupId) {
        showToast('Ad Group not created. Please complete previous steps.', 'error');
        return;
    }

    if (!state.globalCtaPortfolioId) {
        showToast('CTA Portfolio is required. Please go back and select a portfolio.', 'error');
        return;
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

        const result = await apiRequest('create_smartplus_ad', {
            adgroup_id: state.adGroupId,
            ad_name: state.campaignName + ' - Ad',
            identity_id: state.globalIdentityId,
            identity_type: identityType,
            landing_page_url: state.globalLandingUrl,
            call_to_action_id: state.globalCtaPortfolioId,  // Lead Gen requires Dynamic CTA Portfolio
            creatives: creativeList
        });

        hideLoading();

        if (result.success && result.smart_plus_ad_id) {
            showToast('Smart+ Ad created successfully!', 'success');
            addLog('info', `Smart+ Ad created: ${result.smart_plus_ad_id}`);

            // Show success modal after a short delay
            setTimeout(() => {
                showSuccessModal(result.smart_plus_ad_id, creativeList.length);
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
function showSuccessModal(adId, creativesCount) {
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
                    <p style="margin: 5px 0; font-size: 13px;"><strong>Creatives:</strong> ${creativesCount} videos</p>
                </div>
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
