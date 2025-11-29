// Smart+ Campaign JavaScript
// Uses /smart_plus/ endpoints for campaign, adgroup, and ad creation

// Global state
let state = {
    currentStep: 1,
    campaignId: null,
    campaignName: null,
    campaignBudget: null,
    adGroupId: null,
    adGroupName: null,
    pixelId: null,
    optimizationEvent: null,
    ageGroups: [],
    locationIds: [],
    dayparting: null,
    ads: [],
    identities: [],
    mediaLibrary: [],
    selectedMedia: [],
    currentAdIndex: null
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
    { id: '5332921', name: 'Colorado', abbr: 'CO' },
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
    { id: '4099753', name: 'Arkansas', abbr: 'AR' },
    { id: '4273857', name: 'Kentucky', abbr: 'KY' },
    { id: '5090174', name: 'New Hampshire', abbr: 'NH' },
    { id: '5224323', name: 'Rhode Island', abbr: 'RI' },
    { id: '5242283', name: 'Vermont', abbr: 'VT' },
    { id: '4971068', name: 'Maine', abbr: 'ME' },
    { id: '5855797', name: 'Hawaii', abbr: 'HI' },
    { id: '5879092', name: 'Alaska', abbr: 'AK' }
];

const SMARTPLUS_API = 'api-smartplus.php';
const MAIN_API = 'api.php';

// API Request function
async function apiRequest(action, data = {}, useMainApi = false) {
    const apiUrl = useMainApi ? MAIN_API : SMARTPLUS_API;
    addLog('request', `Calling ${action}`, data);

    try {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...data })
        });

        const result = await response.json();

        if (result.success) {
            addLog('response', `${action} successful`, result);
        } else {
            addLog('error', `${action} failed: ${result.message}`, result);
        }

        return result;
    } catch (error) {
        addLog('error', `${action} error: ${error.message}`);
        throw error;
    }
}

// API Logger Functions
function addLog(type, message, details = null) {
    const logsContent = document.getElementById('logs-content');
    if (!logsContent) return;

    const logEntry = document.createElement('div');
    logEntry.className = `log-entry log-${type}`;

    const now = new Date();
    const time = now.toTimeString().split(' ')[0];

    let typeLabel = '';
    if (type === 'request' || type === 'response' || type === 'error') {
        typeLabel = `<span class="log-type ${type}">${type.toUpperCase()}</span>`;
    }

    logEntry.innerHTML = `
        <span class="log-time">${time}</span>
        ${typeLabel}
        <span class="log-message">${message}</span>
    `;

    if (details) {
        const detailsDiv = document.createElement('div');
        detailsDiv.className = 'log-details';
        detailsDiv.innerHTML = `<pre>${JSON.stringify(details, null, 2)}</pre>`;
        logEntry.appendChild(detailsDiv);
    }

    logsContent.appendChild(logEntry);
    logsContent.scrollTop = logsContent.scrollHeight;
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
    initializeDayparting();
    initializeLocationTargeting();
    addFirstAd();

    // Update timezone status
    const statusElement = document.getElementById('timezone-status');
    if (statusElement) {
        statusElement.innerHTML = '<span style="color: #22c55e;">✓</span> Smart+ Campaign Mode';
        statusElement.style.color = '#22c55e';
    }

    // Character counter for identity name
    const identityInput = document.getElementById('identity-display-name');
    const charCounter = document.getElementById('identity-char-count');
    if (identityInput && charCounter) {
        identityInput.addEventListener('input', function() {
            charCounter.textContent = this.value.length;
        });
    }
});

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

// Load Identities - use main api.php which has better identity handling
async function loadIdentities() {
    try {
        // Use main api.php for identities as it handles both TT_USER and CUSTOMIZED_USER
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
            select.innerHTML = '<option value="">No identities found - Create one</option>';
            state.identities = [];
        }
    } catch (error) {
        console.error('Error loading identities:', error);
        state.identities = [];
    }
}

// =====================
// Age Targeting Functions
// =====================
function selectAllAges() {
    document.querySelectorAll('.age-checkbox').forEach(cb => cb.checked = true);
}

function clearAllAges() {
    document.querySelectorAll('.age-checkbox').forEach(cb => cb.checked = false);
}

function selectDefaultAges() {
    document.querySelectorAll('.age-checkbox').forEach(cb => {
        cb.checked = cb.value !== 'AGE_13_17';
    });
}

function getSelectedAgeGroups() {
    const selected = [];
    document.querySelectorAll('.age-checkbox:checked').forEach(cb => {
        selected.push(cb.value);
    });
    return selected;
}

// =====================
// Location Targeting Functions
// =====================
function initializeLocationTargeting() {
    const grid = document.getElementById('states-grid');
    if (!grid) return;

    grid.innerHTML = '';

    US_STATES.forEach(state => {
        const item = document.createElement('div');
        item.className = 'state-item';
        item.innerHTML = `
            <label>
                <input type="checkbox" class="state-checkbox" value="${state.id}" data-name="${state.name}" checked>
                <span>${state.abbr} - ${state.name}</span>
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

function selectPopularStates() {
    const popular = ['CA', 'TX', 'FL', 'NY', 'PA', 'IL', 'OH', 'GA', 'NC', 'MI'];
    document.querySelectorAll('.state-checkbox').forEach(cb => {
        const stateName = cb.dataset.name;
        const state = US_STATES.find(s => s.name === stateName);
        cb.checked = state && popular.includes(state.abbr);
    });
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
        return ['6252001']; // US country code
    }

    const selected = [];
    document.querySelectorAll('.state-checkbox:checked').forEach(cb => {
        selected.push(cb.value);
    });
    return selected.length > 0 ? selected : ['6252001'];
}

// =====================
// Dayparting Functions
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
    const dayOrder = [1, 2, 3, 4, 5, 6, 0]; // Mon-Sun

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
// STEP 1: Create Campaign
// =====================
async function createSmartCampaign() {
    const campaignName = document.getElementById('campaign-name').value.trim();
    const budget = parseFloat(document.getElementById('campaign-budget').value) || 50;

    if (!campaignName) {
        showToast('Please enter a campaign name', 'error');
        return;
    }

    if (budget < 20) {
        showToast('Minimum budget is $20', 'error');
        return;
    }

    showLoading('Creating Smart+ Campaign...');
    addLog('info', '=== Creating Smart+ Campaign ===');

    try {
        const result = await apiRequest('create_campaign', {
            campaign_name: campaignName,
            budget: budget
        });

        if (result.success && result.campaign_id) {
            state.campaignId = result.campaign_id;
            state.campaignName = campaignName;
            state.campaignBudget = budget;

            document.getElementById('display-campaign-id').textContent = result.campaign_id;
            document.getElementById('adgroup-name').value = campaignName + ' - Ad Group';

            showToast('Campaign created successfully!', 'success');
            nextStep();
        } else {
            showToast(result.message || 'Failed to create campaign', 'error');
        }
    } catch (error) {
        showToast('Error creating campaign: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// =====================
// STEP 2: Create Ad Group
// =====================
async function createSmartAdGroup() {
    const adGroupName = document.getElementById('adgroup-name').value.trim();
    const pixelId = document.getElementById('pixel-select').value;
    const optimizationEvent = document.getElementById('optimization-event').value;
    const ageGroups = getSelectedAgeGroups();
    const locationIds = getSelectedLocationIds();
    const dayparting = getDaypartingData();

    if (!adGroupName) {
        showToast('Please enter an ad group name', 'error');
        return;
    }

    if (!pixelId) {
        showToast('Please select a pixel', 'error');
        return;
    }

    if (ageGroups.length === 0) {
        showToast('Please select at least one age group', 'error');
        return;
    }

    if (!state.campaignId) {
        showToast('No campaign found. Please go back and create a campaign.', 'error');
        return;
    }

    showLoading('Creating Smart+ Ad Group...');
    addLog('info', '=== Creating Smart+ Ad Group ===');

    try {
        const result = await apiRequest('create_adgroup', {
            campaign_id: state.campaignId,
            adgroup_name: adGroupName,
            pixel_id: pixelId,
            optimization_event: optimizationEvent,
            age_groups: ageGroups,
            location_ids: locationIds,
            dayparting: dayparting
        });

        if (result.success && result.adgroup_id) {
            state.adGroupId = result.adgroup_id;
            state.adGroupName = adGroupName;
            state.pixelId = pixelId;
            state.optimizationEvent = optimizationEvent;
            state.ageGroups = ageGroups;
            state.locationIds = locationIds;
            state.dayparting = dayparting;

            document.getElementById('display-adgroup-id').textContent = result.adgroup_id;

            showToast('Ad Group created successfully!', 'success');
            nextStep();
        } else {
            showToast(result.message || 'Failed to create ad group', 'error');
        }
    } catch (error) {
        showToast('Error creating ad group: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// =====================
// STEP 3: Create Ads
// =====================
function addFirstAd() {
    state.ads = [{
        name: 'Ad 1',
        video_id: '',
        video_name: '',
        image_id: '',
        image_name: '',
        ad_text: ''
    }];
    renderAds();
}

function addNewAd() {
    const newAd = {
        name: `Ad ${state.ads.length + 1}`,
        video_id: '',
        video_name: '',
        image_id: '',
        image_name: '',
        ad_text: ''
    };
    state.ads.push(newAd);
    renderAds();
}

function renderAds() {
    const container = document.getElementById('ads-container');
    container.innerHTML = '';

    state.ads.forEach((ad, index) => {
        const adCard = createAdCard(index, ad);
        container.appendChild(adCard);
    });
}

function createAdCard(index, ad) {
    const card = document.createElement('div');
    card.className = 'ad-card';
    card.id = `ad-card-${index}`;

    const hasMedia = ad.video_id || ad.image_id;

    card.innerHTML = `
        <div class="ad-card-header">
            <h3>Ad ${index + 1}</h3>
            ${index > 0 ? `<button class="btn-remove" onclick="removeAd(${index})">✕ Remove</button>` : ''}
        </div>

        <div class="ad-card .form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label>Ad Name</label>
                <input type="text" id="ad-name-${index}" value="${ad.name}"
                       onchange="updateAd(${index}, 'name', this.value)" placeholder="Enter ad name">
            </div>
            <div class="form-group">
                <label>Ad Text</label>
                <input type="text" id="ad-text-${index}" value="${ad.ad_text}"
                       onchange="updateAd(${index}, 'ad_text', this.value)" placeholder="Enter ad text (12-100 chars)">
            </div>
        </div>

        <div class="form-group">
            <label>Media (Video + Cover Image)</label>
            <div class="media-preview-box ${hasMedia ? 'has-media' : ''}" onclick="openMediaModal(${index})">
                <div class="icon">${hasMedia ? '✅' : '📁'}</div>
                <p>${hasMedia ? 'Media Selected - Click to change' : 'Click to select video and cover image'}</p>
                ${hasMedia ? `
                    <div class="media-tags">
                        ${ad.video_id ? `<span class="media-tag video">🎬 ${ad.video_name || 'Video selected'}</span>` : ''}
                        ${ad.image_id ? `<span class="media-tag image">🖼️ ${ad.image_name || 'Image selected'}</span>` : ''}
                    </div>
                ` : ''}
            </div>
        </div>
    `;

    return card;
}

function updateAd(index, field, value) {
    if (state.ads[index]) {
        state.ads[index][field] = value;
    }
}

function removeAd(index) {
    if (state.ads.length > 1) {
        state.ads.splice(index, 1);
        // Rename remaining ads
        state.ads.forEach((ad, i) => {
            ad.name = `Ad ${i + 1}`;
        });
        renderAds();
    } else {
        showToast('You need at least one ad', 'error');
    }
}

function duplicateAdBulk() {
    const count = parseInt(document.getElementById('bulk-duplicate-count').value) || 5;
    if (count < 1 || count > 50) {
        showToast('Please enter a number between 1 and 50', 'error');
        return;
    }

    if (state.ads.length === 0) return;

    const lastAd = state.ads[state.ads.length - 1];
    for (let i = 0; i < count; i++) {
        const newAd = { ...lastAd };
        newAd.name = `Ad ${state.ads.length + 1}`;
        state.ads.push(newAd);
    }
    renderAds();
    showToast(`Added ${count} ads`, 'success');
}

function testLandingUrl() {
    const url = document.getElementById('global-landing-url').value;
    if (url) {
        window.open(url, '_blank');
    } else {
        showToast('Please enter a URL first', 'error');
    }
}

// =====================
// STEP 4: Review & Publish
// =====================
function reviewAds() {
    const identityId = document.getElementById('global-identity').value;
    const landingUrl = document.getElementById('global-landing-url').value.trim();
    const cta = document.getElementById('global-cta').value;

    if (!identityId) {
        showToast('Please select an identity', 'error');
        return;
    }

    if (!landingUrl) {
        showToast('Please enter a landing page URL', 'error');
        return;
    }

    // Validate ads have media and text
    for (let i = 0; i < state.ads.length; i++) {
        const ad = state.ads[i];
        if (!ad.video_id) {
            showToast(`Ad ${i + 1}: Please select a video`, 'error');
            return;
        }
        if (!ad.ad_text || ad.ad_text.length < 1) {
            showToast(`Ad ${i + 1}: Please enter ad text`, 'error');
            return;
        }
    }

    // Store global values
    state.globalIdentityId = identityId;
    state.globalLandingUrl = landingUrl;
    state.globalCta = cta;

    // Get identity name
    const identity = state.identities.find(i => i.identity_id === identityId);
    const identityName = identity ? (identity.display_name || identity.identity_name) : identityId;

    // Populate review summaries
    document.getElementById('campaign-summary').innerHTML = `
        <p><strong>Campaign Name:</strong> ${state.campaignName}</p>
        <p><strong>Campaign ID:</strong> ${state.campaignId}</p>
        <p><strong>Budget:</strong> $${state.campaignBudget}/day</p>
        <p><strong>Type:</strong> Smart+ Lead Generation</p>
    `;

    document.getElementById('adgroup-summary').innerHTML = `
        <p><strong>Ad Group Name:</strong> ${state.adGroupName}</p>
        <p><strong>Ad Group ID:</strong> ${state.adGroupId}</p>
        <p><strong>Pixel ID:</strong> ${state.pixelId}</p>
        <p><strong>Optimization Event:</strong> ${state.optimizationEvent}</p>
        <p><strong>Age Groups:</strong> ${state.ageGroups.join(', ')}</p>
        <p><strong>Location:</strong> ${state.locationIds.length === 1 && state.locationIds[0] === '6252001' ? 'United States' : state.locationIds.length + ' states'}</p>
    `;

    document.getElementById('ads-count').textContent = state.ads.length;

    let adsSummaryHtml = `
        <div class="summary-item" style="background: #f0f4ff; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
            <p><strong>Identity:</strong> ${identityName}</p>
            <p><strong>Landing Page:</strong> ${landingUrl}</p>
            <p><strong>CTA:</strong> ${cta}</p>
        </div>
    `;

    state.ads.forEach((ad, index) => {
        adsSummaryHtml += `
            <div class="summary-item">
                <h4>${ad.name}</h4>
                <p><strong>Video:</strong> ${ad.video_name || 'Selected'}</p>
                <p><strong>Cover:</strong> ${ad.image_name || (ad.image_id ? 'Selected' : 'Auto-generated')}</p>
                <p><strong>Ad Text:</strong> ${ad.ad_text}</p>
            </div>
        `;
    });

    document.getElementById('ads-summary').innerHTML = adsSummaryHtml;

    nextStep();
}

async function publishAll() {
    showLoading('Publishing Smart+ Ads...');
    addLog('info', '=== Publishing Smart+ Ads ===');

    const results = {
        success: [],
        failed: []
    };

    for (let i = 0; i < state.ads.length; i++) {
        const ad = state.ads[i];

        try {
            document.getElementById('loading-text').textContent = `Creating Ad ${i + 1} of ${state.ads.length}...`;

            const result = await apiRequest('create_ad', {
                adgroup_id: state.adGroupId,
                ad_name: ad.name,
                identity_id: state.globalIdentityId,
                video_id: ad.video_id,
                image_id: ad.image_id,
                ad_text: ad.ad_text,
                landing_page_url: state.globalLandingUrl,
                call_to_action: state.globalCta
            });

            if (result.success) {
                results.success.push({ name: ad.name, ad_id: result.ad_id });
                addLog('info', `Ad ${i + 1} created: ${result.ad_id}`);
            } else {
                results.failed.push({ name: ad.name, error: result.message });
                addLog('error', `Ad ${i + 1} failed: ${result.message}`);
            }
        } catch (error) {
            results.failed.push({ name: ad.name, error: error.message });
            addLog('error', `Ad ${i + 1} error: ${error.message}`);
        }
    }

    hideLoading();

    // Show results
    let message = '';
    if (results.success.length > 0) {
        message = `✅ Successfully created ${results.success.length} ad(s)!`;
        if (results.failed.length > 0) {
            message += `\n⚠️ ${results.failed.length} ad(s) failed.`;
        }
        showToast(message, results.failed.length > 0 ? 'warning' : 'success');

        let alertMessage = `Smart+ Campaign Published!\n\n`;
        alertMessage += `Campaign ID: ${state.campaignId}\n`;
        alertMessage += `Ad Group ID: ${state.adGroupId}\n\n`;
        alertMessage += `Ads Created: ${results.success.length}\n`;
        results.success.forEach(ad => {
            alertMessage += `  ✓ ${ad.name}: ${ad.ad_id}\n`;
        });

        if (results.failed.length > 0) {
            alertMessage += `\nFailed: ${results.failed.length}\n`;
            results.failed.forEach(ad => {
                alertMessage += `  ✗ ${ad.name}: ${ad.error}\n`;
            });
        }

        alert(alertMessage);
    } else {
        showToast('Failed to create ads. Check the logs.', 'error');
    }
}

// =====================
// Media Modal
// =====================
function openMediaModal(adIndex) {
    state.currentAdIndex = adIndex;
    state.selectedMedia = [];

    const ad = state.ads[adIndex];
    if (ad.video_id) {
        state.selectedMedia.push({ type: 'video', id: ad.video_id, name: ad.video_name });
    }
    if (ad.image_id) {
        state.selectedMedia.push({ type: 'image', id: ad.image_id, name: ad.image_name });
    }

    loadMediaLibrary();
    document.getElementById('media-modal').style.display = 'flex';
}

function closeMediaModal() {
    document.getElementById('media-modal').style.display = 'none';
    state.currentAdIndex = null;
}

async function loadMediaLibrary() {
    const grid = document.getElementById('media-grid');
    grid.innerHTML = '<p style="text-align: center; padding: 20px;">Loading media...</p>';

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
                    name: video.file_name || video.video_id
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

        renderMediaGrid();
        addLog('info', `Loaded ${state.mediaLibrary.length} media items`);
    } catch (error) {
        grid.innerHTML = '<p style="text-align: center; padding: 20px; color: red;">Error loading media</p>';
    }
}

function renderMediaGrid(filter = 'all') {
    const grid = document.getElementById('media-grid');
    grid.innerHTML = '';

    const filteredMedia = filter === 'all'
        ? state.mediaLibrary
        : state.mediaLibrary.filter(m => m.type === filter);

    if (filteredMedia.length === 0) {
        grid.innerHTML = '<p style="text-align: center; padding: 20px;">No media found</p>';
        return;
    }

    filteredMedia.forEach(media => {
        const isSelected = state.selectedMedia.some(s => s.id === media.id);
        const item = document.createElement('div');
        item.className = `media-item ${media.type} ${isSelected ? 'selected' : ''}`;
        item.onclick = () => toggleMediaSelection(media);

        item.innerHTML = `
            <div class="media-preview">
                ${media.url ? `<img src="${media.url}" alt="${media.name}">` : '<div class="no-preview">No Preview</div>'}
                <span class="media-type-badge">${media.type === 'video' ? '🎬' : '🖼️'}</span>
            </div>
            <div class="media-name">${(media.name || '').substring(0, 15)}...</div>
        `;

        grid.appendChild(item);
    });

    const countEl = document.getElementById('media-count');
    if (countEl) countEl.textContent = `${filteredMedia.length} items`;

    updateSelectionCounter();
}

function toggleMediaSelection(media) {
    const existingIndex = state.selectedMedia.findIndex(s => s.id === media.id);

    if (existingIndex > -1) {
        state.selectedMedia.splice(existingIndex, 1);
    } else {
        if (media.type === 'video') {
            state.selectedMedia = state.selectedMedia.filter(s => s.type !== 'video');
        }
        if (media.type === 'image') {
            state.selectedMedia = state.selectedMedia.filter(s => s.type !== 'image');
        }
        state.selectedMedia.push({ type: media.type, id: media.id, name: media.name });
    }

    renderMediaGrid(document.querySelector('.media-filter.active')?.dataset.filter || 'all');
}

function updateSelectionCounter() {
    const counter = document.getElementById('selection-counter');
    if (counter) {
        const videoCount = state.selectedMedia.filter(s => s.type === 'video').length;
        const imageCount = state.selectedMedia.filter(s => s.type === 'image').length;
        counter.textContent = `Selected: ${videoCount} video, ${imageCount} image`;
    }
}

function filterMedia(filter) {
    document.querySelectorAll('.media-filter').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`.media-filter[data-filter="${filter}"]`)?.classList.add('active');
    renderMediaGrid(filter);
}

function confirmMediaSelection() {
    if (state.currentAdIndex === null) return;

    const video = state.selectedMedia.find(s => s.type === 'video');
    const image = state.selectedMedia.find(s => s.type === 'image');

    state.ads[state.currentAdIndex].video_id = video ? video.id : '';
    state.ads[state.currentAdIndex].video_name = video ? video.name : '';
    state.ads[state.currentAdIndex].image_id = image ? image.id : '';
    state.ads[state.currentAdIndex].image_name = image ? image.name : '';

    renderAds();
    closeMediaModal();
    showToast('Media selected', 'success');
}

function switchMediaTab(tab, event) {
    document.querySelectorAll('.modal-tabs .tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');

    document.querySelectorAll('.media-tab').forEach(t => {
        t.classList.remove('active');
        t.style.display = 'none';
    });

    const tabEl = document.getElementById(`media-${tab}-tab`);
    if (tabEl) {
        tabEl.classList.add('active');
        tabEl.style.display = 'block';
    }
}

function handleMediaUpload(event) {
    showToast('Please upload media via TikTok Ads Manager or the main dashboard', 'info');
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

            // Update dropdown
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
