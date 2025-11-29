// Smart+ Campaign JavaScript
// Uses /smart_plus/ endpoints for campaign, adgroup, and ad creation
// Flow: Campaign -> AdGroup -> Ad (with multiple creatives in creative_list)

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
    // Creatives array - each creative has video_id and ad_text
    creatives: [],
    identities: [],
    mediaLibrary: [],
    selectedVideos: [],
    cboEnabled: true
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
    loadMediaLibrary();
    initializeDayparting();
    initializeLocationTargeting();

    // Initialize CBO state (default is enabled)
    state.cboEnabled = true;

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

// Toggle CBO budget section
function toggleCBOBudget() {
    const cboEnabled = document.getElementById('cbo-enabled').checked;
    const campaignBudgetSection = document.getElementById('campaign-budget-section');
    const adGroupBudgetSection = document.getElementById('adgroup-budget-section');
    const cboBudgetNote = document.getElementById('cbo-budget-note');
    const displayBudgetInfo = document.getElementById('display-budget-info');

    // Store CBO state
    state.cboEnabled = cboEnabled;

    if (cboEnabled) {
        if (campaignBudgetSection) campaignBudgetSection.style.display = 'block';
        if (adGroupBudgetSection) adGroupBudgetSection.style.display = 'none';
        if (cboBudgetNote) cboBudgetNote.style.display = 'block';
        if (displayBudgetInfo) {
            displayBudgetInfo.innerHTML = '<strong>Budget:</strong> $<span id="display-budget">-</span>/day (Campaign Level - CBO Enabled)';
        }
    } else {
        if (campaignBudgetSection) campaignBudgetSection.style.display = 'none';
        if (adGroupBudgetSection) adGroupBudgetSection.style.display = 'block';
        if (cboBudgetNote) cboBudgetNote.style.display = 'none';
        if (displayBudgetInfo) {
            displayBudgetInfo.innerHTML = '<strong>Budget:</strong> Set at Ad Group level (CBO Disabled)';
        }
    }
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
            select.innerHTML = '<option value="">No identities found - Create one</option>';
            state.identities = [];
        }
    } catch (error) {
        console.error('Error loading identities:', error);
        state.identities = [];
    }
}

// Load Media Library (Videos and Images)
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

        addLog('info', `Loaded ${state.mediaLibrary.length} media items (${state.mediaLibrary.filter(m => m.type === 'video').length} videos, ${state.mediaLibrary.filter(m => m.type === 'image').length} images)`);

        // Render video grid for selection
        renderVideoSelectionGrid();
    } catch (error) {
        console.error('Error loading media:', error);
        addLog('error', 'Failed to load media library');
    }
}

// =====================
// Location Targeting Functions
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

function selectPopularStates() {
    const popular = ['CA', 'TX', 'FL', 'NY', 'PA', 'IL', 'OH', 'GA', 'NC', 'MI'];
    document.querySelectorAll('.state-checkbox').forEach(cb => {
        const stateName = cb.dataset.name;
        const stateItem = US_STATES.find(s => s.name === stateName);
        cb.checked = stateItem && popular.includes(stateItem.abbr);
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
        return ['6252001'];
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
// STEP 1: Save Campaign Settings
// =====================
function saveCampaignSettings() {
    const campaignName = document.getElementById('campaign-name').value.trim();
    const cboEnabled = document.getElementById('cbo-enabled')?.checked ?? true;
    const campaignBudget = cboEnabled ? (parseFloat(document.getElementById('campaign-budget').value) || 50) : null;

    if (!campaignName) {
        showToast('Please enter a campaign name', 'error');
        return;
    }

    if (cboEnabled && campaignBudget < 20) {
        showToast('Minimum budget is $20', 'error');
        return;
    }

    state.campaignName = campaignName;
    state.cboEnabled = cboEnabled;
    state.budget = campaignBudget;

    const displayNameEl = document.getElementById('display-campaign-name');
    const displayBudgetEl = document.getElementById('display-budget');
    if (displayNameEl) displayNameEl.textContent = campaignName;
    if (displayBudgetEl && cboEnabled) displayBudgetEl.textContent = campaignBudget;

    addLog('info', `Campaign settings saved: ${campaignName}, Budget: $${campaignBudget}`);
    showToast('Campaign settings saved!', 'success');
    nextStep();
}

// =====================
// STEP 2: Save Ad Group Settings
// =====================
function saveAdGroupSettings() {
    const pixelId = document.getElementById('pixel-select').value;
    const optimizationEvent = document.getElementById('optimization-event').value;
    const locationIds = getSelectedLocationIds();
    const dayparting = getDaypartingData();

    const cboEnabled = state.cboEnabled;
    const adGroupBudget = !cboEnabled ? (parseFloat(document.getElementById('adgroup-budget')?.value) || 50) : null;

    if (!pixelId) {
        showToast('Please select a pixel', 'error');
        return;
    }

    if (!cboEnabled && (!adGroupBudget || adGroupBudget < 20)) {
        showToast('Minimum ad group budget is $20', 'error');
        return;
    }

    state.pixelId = pixelId;
    state.optimizationEvent = optimizationEvent;
    state.locationIds = locationIds.length > 0 ? locationIds : ['6252001'];
    state.dayparting = dayparting;
    state.adGroupBudget = adGroupBudget;

    addLog('info', `Ad Group settings saved: Pixel ${pixelId}, Event: ${optimizationEvent}`);
    showToast('Ad Group settings saved!', 'success');
    nextStep();
}

// =====================
// STEP 3: Select Videos and Create Creatives
// Each creative = {video_id, ad_text}
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

// Render creatives list - each video gets an ad_text input
function renderCreativesList() {
    const container = document.getElementById('creatives-list');
    if (!container) return;

    if (state.selectedVideos.length === 0) {
        container.innerHTML = '<p style="text-align: center; padding: 20px; color: #666;">Select videos above to add creatives</p>';
        return;
    }

    container.innerHTML = '';

    state.selectedVideos.forEach((video, index) => {
        // Find existing creative data if any
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

    // Update state.creatives to match selectedVideos
    state.creatives = state.selectedVideos.map(video => {
        const existing = state.creatives.find(c => c.video_id === video.id);
        return {
            video_id: video.id,
            video_name: video.name,
            video_url: video.url,
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

    // Update ad texts from form
    state.creatives.forEach(creative => {
        const textarea = document.getElementById(`ad-text-${creative.video_id}`);
        if (textarea) {
            creative.ad_text = textarea.value;
        }
    });

    // Validate creatives
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
        <p><strong>CBO:</strong> ${state.cboEnabled ? 'Enabled' : 'Disabled'}</p>
        ${state.cboEnabled ? `<p><strong>Campaign Budget:</strong> $${state.budget}/day</p>` : ''}
        <p><strong>Type:</strong> Smart+ Lead Generation</p>
    `;

    document.getElementById('adgroup-summary').innerHTML = `
        <p><strong>Pixel ID:</strong> ${state.pixelId}</p>
        <p><strong>Optimization Event:</strong> ${state.optimizationEvent}</p>
        ${!state.cboEnabled ? `<p><strong>Ad Group Budget:</strong> $${state.adGroupBudget}/day</p>` : ''}
        <p><strong>Location:</strong> ${state.locationIds.length === 1 && state.locationIds[0] === '6252001' ? 'United States' : state.locationIds.length + ' locations'}</p>
    `;

    let adsSummaryHtml = `
        <div class="summary-item" style="background: #f0f4ff; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
            <p><strong>Identity:</strong> ${identityName}</p>
            <p><strong>Landing Page:</strong> ${landingUrl}</p>
            <p><strong>CTA:</strong> ${cta}</p>
            <p><strong>Total Creatives:</strong> ${state.creatives.length} videos in ONE ad</p>
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

// Publish - Creates Campaign -> AdGroup -> Ad (with all creatives in creative_list)
async function publishAll() {
    showLoading('Creating Smart+ Campaign...');
    addLog('info', '=== Creating Smart+ Campaign ===');

    try {
        const identity = state.identities.find(i => i.identity_id === state.globalIdentityId);
        const identityType = identity?.identity_type || 'CUSTOMIZED_USER';

        // Build creative_list: each creative has video_id and ad_text
        const creativeList = state.creatives.map(creative => ({
            video_id: creative.video_id,
            ad_text: creative.ad_text
        }));

        addLog('info', `Identity: ${identity?.display_name || identity?.identity_name} (${identityType})`);
        addLog('info', `Creatives: ${creativeList.length} videos in ONE ad`);
        addLog('info', 'creative_list: ' + JSON.stringify(creativeList));

        // Call the orchestrated API - Campaign -> AdGroup -> Ad
        const result = await apiRequest('create_full_smartplus', {
            campaign_name: state.campaignName,
            cbo_enabled: state.cboEnabled,
            budget: state.budget,
            adgroup_budget: state.adGroupBudget,
            pixel_id: state.pixelId,
            optimization_event: state.optimizationEvent,
            location_ids: state.locationIds,
            dayparting: state.dayparting,
            identity_id: state.globalIdentityId,
            identity_type: identityType,
            landing_page_url: state.globalLandingUrl,
            call_to_action: state.globalCta,
            // Send creative_list directly - array of {video_id, ad_text}
            creative_list: creativeList
        });

        hideLoading();

        if (result.success && result.campaign_id) {
            state.campaignId = result.campaign_id;
            state.adGroupId = result.adgroup_id;

            showToast('Smart+ Campaign created successfully!', 'success');
            addLog('info', `Campaign: ${result.campaign_id}, AdGroup: ${result.adgroup_id}, Ad: ${result.smart_plus_ad_id}`);

            let alertMessage = `Smart+ Campaign Published!\n\n`;
            alertMessage += `Campaign ID: ${result.campaign_id}\n`;
            alertMessage += `Ad Group ID: ${result.adgroup_id}\n`;
            alertMessage += `Smart+ Ad ID: ${result.smart_plus_ad_id || 'N/A'}\n`;
            alertMessage += `Creatives: ${creativeList.length} videos\n`;

            alert(alertMessage);
        } else {
            const errorStep = result.step ? ` (Step: ${result.step})` : '';
            showToast('Failed: ' + (result.message || 'Unknown error') + errorStep, 'error');
            addLog('error', 'Failed to create campaign', result);
        }
    } catch (error) {
        hideLoading();
        showToast('Error: ' + error.message, 'error');
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
