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
    ads: [],
    identities: [],
    mediaLibrary: [],
    selectedMedia: [],
    currentAdIndex: null,
    mediaSelectionMode: 'multiple'
};

const API_URL = 'api-smartplus.php';

// API Request function
async function apiRequest(action, data = {}, method = 'POST') {
    addLog('request', `Calling ${action}`, data);

    try {
        const options = {
            method: method,
            headers: { 'Content-Type': 'application/json' }
        };

        if (method === 'POST') {
            options.body = JSON.stringify({ action, ...data });
        }

        const response = await fetch(API_URL, options);
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
            <span class="log-message">Logs cleared - Ready for new requests</span>
        </div>
    `;
}

function toggleLogsPanel() {
    const logsPanel = document.getElementById('logs-panel');
    const toggleIcon = document.getElementById('logs-toggle-icon');
    const toggleBtn = document.querySelector('.btn-toggle-logs');

    logsPanel.classList.toggle('collapsed');

    if (logsPanel.classList.contains('collapsed')) {
        toggleIcon.textContent = '▲ Show Logs';
        if (toggleBtn) toggleBtn.textContent = '▲';
    } else {
        toggleIcon.textContent = '▼ Hide Logs';
        if (toggleBtn) toggleBtn.textContent = '▼';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Smart+ Campaign JS loaded');
    addLog('info', 'Smart+ Campaign initialized');

    loadPixels();
    loadIdentities();
    addFirstAd();

    // Update timezone status
    const statusElement = document.getElementById('timezone-status');
    if (statusElement) {
        statusElement.innerHTML = '<span style="color: #22c55e;">✓</span> Smart+ Campaign Mode';
        statusElement.style.color = '#22c55e';
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
        document.getElementById('pixel-select').innerHTML = '<option value="">Error loading pixels</option>';
    }
}

// Load Identities
async function loadIdentities() {
    try {
        const result = await apiRequest('get_identities');

        if (result.success && result.data && result.data.list) {
            state.identities = result.data.list;
            addLog('info', `Loaded ${state.identities.length} identities`);
        } else {
            state.identities = [];
        }
    } catch (error) {
        console.error('Error loading identities:', error);
        state.identities = [];
    }
}

// Step Navigation
function goToStep(stepNumber) {
    // Update step indicators
    document.querySelectorAll('.step').forEach((step, index) => {
        step.classList.remove('active', 'completed');
        if (index + 1 < stepNumber) {
            step.classList.add('completed');
        } else if (index + 1 === stepNumber) {
            step.classList.add('active');
        }
    });

    // Show/hide step content
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

    showLoading();
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

            // Pre-fill ad group name
            document.getElementById('adgroup-name').value = campaignName + ' - Ad Group';

            showToast('Campaign created successfully!', 'success');
            addLog('info', `Campaign created: ${result.campaign_id}`);

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

    if (!adGroupName) {
        showToast('Please enter an ad group name', 'error');
        return;
    }

    if (!pixelId) {
        showToast('Please select a pixel', 'error');
        return;
    }

    if (!state.campaignId) {
        showToast('No campaign found. Please go back and create a campaign.', 'error');
        return;
    }

    showLoading();
    addLog('info', '=== Creating Smart+ Ad Group ===');

    try {
        const result = await apiRequest('create_adgroup', {
            campaign_id: state.campaignId,
            adgroup_name: adGroupName,
            pixel_id: pixelId,
            optimization_event: optimizationEvent
        });

        if (result.success && result.adgroup_id) {
            state.adGroupId = result.adgroup_id;
            state.adGroupName = adGroupName;
            state.pixelId = pixelId;
            state.optimizationEvent = optimizationEvent;

            document.getElementById('display-adgroup-id').textContent = result.adgroup_id;

            showToast('Ad Group created successfully!', 'success');
            addLog('info', `Ad Group created: ${result.adgroup_id}`);

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
        name: '',
        identity_id: '',
        video_id: '',
        image_id: '',
        ad_text: '',
        landing_page_url: '',
        call_to_action: 'LEARN_MORE'
    }];
    renderAds();
}

function renderAds() {
    const container = document.getElementById('ads-container');
    container.innerHTML = '';

    state.ads.forEach((ad, index) => {
        const adForm = createAdForm(index, ad);
        container.appendChild(adForm);
    });
}

function createAdForm(index, ad) {
    const adDiv = document.createElement('div');
    adDiv.className = 'ad-form';
    adDiv.id = `ad-form-${index}`;

    // Build identity options
    let identityOptions = '<option value="">Select identity...</option>';
    if (state.identities && state.identities.length > 0) {
        state.identities.forEach(identity => {
            const selected = ad.identity_id === identity.identity_id ? 'selected' : '';
            identityOptions += `<option value="${identity.identity_id}" ${selected}>${identity.display_name || identity.identity_id}</option>`;
        });
    }
    identityOptions += '<option value="create_new">+ Create New Identity</option>';

    // CTA options
    const ctaOptions = [
        { value: 'LEARN_MORE', label: 'Learn More' },
        { value: 'SIGN_UP', label: 'Sign Up' },
        { value: 'CONTACT_US', label: 'Contact Us' },
        { value: 'APPLY_NOW', label: 'Apply Now' },
        { value: 'GET_QUOTE', label: 'Get Quote' },
        { value: 'DOWNLOAD', label: 'Download' },
        { value: 'SHOP_NOW', label: 'Shop Now' },
        { value: 'BOOK_NOW', label: 'Book Now' }
    ];

    let ctaOptionsHtml = '';
    ctaOptions.forEach(cta => {
        const selected = ad.call_to_action === cta.value ? 'selected' : '';
        ctaOptionsHtml += `<option value="${cta.value}" ${selected}>${cta.label}</option>`;
    });

    adDiv.innerHTML = `
        <div class="ad-form-header">
            <h3>Ad ${index + 1}</h3>
            ${index > 0 ? `<button class="btn-remove" onclick="removeAd(${index})">Remove</button>` : ''}
        </div>

        <div class="form-group">
            <label>Ad Name</label>
            <input type="text" id="ad-name-${index}" value="${ad.name || `Ad ${index + 1}`}"
                   onchange="updateAd(${index}, 'name', this.value)" placeholder="Enter ad name">
        </div>

        <div class="form-group">
            <label>Identity</label>
            <select id="ad-identity-${index}" onchange="handleIdentityChange(${index}, this.value)">
                ${identityOptions}
            </select>
        </div>

        <div class="form-group">
            <label>Media (Video + Cover Image)</label>
            <div class="media-selection">
                <button class="btn-secondary" onclick="openMediaModal(${index})">Select Media</button>
                <div class="selected-media" id="selected-media-${index}">
                    ${ad.video_id ? `<span class="media-tag video">Video: ${ad.video_id.substring(0, 10)}...</span>` : ''}
                    ${ad.image_id ? `<span class="media-tag image">Image: ${ad.image_id.substring(0, 10)}...</span>` : ''}
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Ad Text</label>
            <textarea id="ad-text-${index}" rows="3" placeholder="Enter ad text (12-100 characters)"
                      onchange="updateAd(${index}, 'ad_text', this.value)">${ad.ad_text || ''}</textarea>
        </div>

        <div class="form-group">
            <label>Landing Page URL</label>
            <input type="url" id="ad-url-${index}" value="${ad.landing_page_url || ''}"
                   onchange="updateAd(${index}, 'landing_page_url', this.value)" placeholder="https://example.com">
        </div>

        <div class="form-group">
            <label>Call to Action</label>
            <select id="ad-cta-${index}" onchange="updateAd(${index}, 'call_to_action', this.value)">
                ${ctaOptionsHtml}
            </select>
        </div>
    `;

    return adDiv;
}

function updateAd(index, field, value) {
    if (state.ads[index]) {
        state.ads[index][field] = value;
    }
}

function handleIdentityChange(index, value) {
    if (value === 'create_new') {
        openCreateIdentityModal(index);
        // Reset select to previous value
        document.getElementById(`ad-identity-${index}`).value = state.ads[index].identity_id || '';
    } else {
        updateAd(index, 'identity_id', value);
    }
}

function removeAd(index) {
    if (state.ads.length > 1) {
        state.ads.splice(index, 1);
        renderAds();
    } else {
        showToast('You need at least one ad', 'error');
    }
}

function duplicateAd(count = 1) {
    if (state.ads.length === 0) return;

    const lastAd = state.ads[state.ads.length - 1];
    for (let i = 0; i < count; i++) {
        const newAd = { ...lastAd };
        newAd.name = `Ad ${state.ads.length + 1}`;
        state.ads.push(newAd);
    }
    renderAds();
    showToast(`Duplicated ${count} ad(s)`, 'success');
}

function duplicateAdBulk() {
    const count = parseInt(document.getElementById('bulk-duplicate-count').value) || 5;
    if (count > 0 && count <= 50) {
        duplicateAd(count);
    } else {
        showToast('Please enter a number between 1 and 50', 'error');
    }
}

// =====================
// STEP 4: Review & Publish
// =====================
function reviewAds() {
    // Validate ads
    for (let i = 0; i < state.ads.length; i++) {
        const ad = state.ads[i];
        if (!ad.identity_id) {
            showToast(`Ad ${i + 1}: Please select an identity`, 'error');
            return;
        }
        if (!ad.video_id) {
            showToast(`Ad ${i + 1}: Please select a video`, 'error');
            return;
        }
        if (!ad.ad_text || ad.ad_text.length < 1) {
            showToast(`Ad ${i + 1}: Please enter ad text`, 'error');
            return;
        }
        if (!ad.landing_page_url) {
            showToast(`Ad ${i + 1}: Please enter a landing page URL`, 'error');
            return;
        }
    }

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
    `;

    let adsSummaryHtml = '';
    state.ads.forEach((ad, index) => {
        const identity = state.identities.find(i => i.identity_id === ad.identity_id);
        adsSummaryHtml += `
            <div class="summary-item">
                <h4>${ad.name || `Ad ${index + 1}`}</h4>
                <p><strong>Identity:</strong> ${identity ? identity.display_name : ad.identity_id}</p>
                <p><strong>Video:</strong> ${ad.video_id ? 'Selected' : 'Not selected'}</p>
                <p><strong>Ad Text:</strong> ${ad.ad_text.substring(0, 50)}${ad.ad_text.length > 50 ? '...' : ''}</p>
                <p><strong>Landing Page:</strong> ${ad.landing_page_url}</p>
                <p><strong>CTA:</strong> ${ad.call_to_action}</p>
            </div>
        `;
    });
    document.getElementById('ads-summary').innerHTML = adsSummaryHtml;

    nextStep();
}

async function publishAll() {
    showLoading();
    addLog('info', '=== Publishing Smart+ Ads ===');

    const results = {
        success: [],
        failed: []
    };

    for (let i = 0; i < state.ads.length; i++) {
        const ad = state.ads[i];

        try {
            const result = await apiRequest('create_ad', {
                adgroup_id: state.adGroupId,
                ad_name: ad.name || `Ad ${i + 1}`,
                identity_id: ad.identity_id,
                video_id: ad.video_id,
                image_id: ad.image_id,
                ad_text: ad.ad_text,
                landing_page_url: ad.landing_page_url,
                call_to_action: ad.call_to_action
            });

            if (result.success) {
                results.success.push({
                    name: ad.name,
                    ad_id: result.ad_id
                });
                addLog('info', `Ad ${i + 1} created: ${result.ad_id}`);
            } else {
                results.failed.push({
                    name: ad.name,
                    error: result.message
                });
                addLog('error', `Ad ${i + 1} failed: ${result.message}`);
            }
        } catch (error) {
            results.failed.push({
                name: ad.name,
                error: error.message
            });
            addLog('error', `Ad ${i + 1} error: ${error.message}`);
        }
    }

    hideLoading();

    // Show results
    if (results.success.length > 0) {
        let message = `Successfully created ${results.success.length} ad(s)!`;
        if (results.failed.length > 0) {
            message += `\n${results.failed.length} ad(s) failed.`;
        }
        showToast(message, results.failed.length > 0 ? 'warning' : 'success');

        // Show detailed results
        let alertMessage = `Smart+ Campaign Published!\n\n`;
        alertMessage += `Campaign ID: ${state.campaignId}\n`;
        alertMessage += `Ad Group ID: ${state.adGroupId}\n\n`;
        alertMessage += `Ads Created: ${results.success.length}\n`;
        results.success.forEach(ad => {
            alertMessage += `  - ${ad.name}: ${ad.ad_id}\n`;
        });

        if (results.failed.length > 0) {
            alertMessage += `\nFailed Ads: ${results.failed.length}\n`;
            results.failed.forEach(ad => {
                alertMessage += `  - ${ad.name}: ${ad.error}\n`;
            });
        }

        alert(alertMessage);
    } else {
        showToast('Failed to create ads. Check the logs for details.', 'error');
    }
}

// =====================
// Media Modal
// =====================
function openMediaModal(adIndex) {
    state.currentAdIndex = adIndex;
    state.selectedMedia = [];

    // Pre-select current media
    if (state.ads[adIndex].video_id) {
        state.selectedMedia.push({ type: 'video', id: state.ads[adIndex].video_id });
    }
    if (state.ads[adIndex].image_id) {
        state.selectedMedia.push({ type: 'image', id: state.ads[adIndex].image_id });
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
        // Load videos and images in parallel
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
        addLog('error', 'Error loading media: ' + error.message);
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
            <div class="media-name">${media.name.substring(0, 15)}...</div>
        `;

        grid.appendChild(item);
    });

    // Update count
    const countEl = document.getElementById('media-count');
    if (countEl) {
        countEl.textContent = `${filteredMedia.length} items`;
    }
}

function toggleMediaSelection(media) {
    const existingIndex = state.selectedMedia.findIndex(s => s.id === media.id);

    if (existingIndex > -1) {
        state.selectedMedia.splice(existingIndex, 1);
    } else {
        // For video ads: allow 1 video + 1 image
        const hasVideo = state.selectedMedia.some(s => s.type === 'video');
        const hasImage = state.selectedMedia.some(s => s.type === 'image');

        if (media.type === 'video' && hasVideo) {
            // Replace existing video
            state.selectedMedia = state.selectedMedia.filter(s => s.type !== 'video');
        }
        if (media.type === 'image' && hasImage) {
            // Replace existing image
            state.selectedMedia = state.selectedMedia.filter(s => s.type !== 'image');
        }

        state.selectedMedia.push({ type: media.type, id: media.id });
    }

    renderMediaGrid(document.querySelector('.media-filter.active')?.dataset.filter || 'all');
    updateSelectionCounter();
}

function updateSelectionCounter() {
    const counter = document.getElementById('selection-counter');
    if (counter) {
        const videoCount = state.selectedMedia.filter(s => s.type === 'video').length;
        const imageCount = state.selectedMedia.filter(s => s.type === 'image').length;
        counter.textContent = `Selected: ${videoCount} video, ${imageCount} image`;
        counter.style.display = 'inline';
    }
}

function filterMedia(filter) {
    document.querySelectorAll('.media-filter').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`.media-filter[data-filter="${filter}"]`).classList.add('active');
    renderMediaGrid(filter);
}

function confirmMediaSelection() {
    if (state.currentAdIndex === null) return;

    const video = state.selectedMedia.find(s => s.type === 'video');
    const image = state.selectedMedia.find(s => s.type === 'image');

    state.ads[state.currentAdIndex].video_id = video ? video.id : '';
    state.ads[state.currentAdIndex].image_id = image ? image.id : '';

    // Update display
    const selectedMediaDiv = document.getElementById(`selected-media-${state.currentAdIndex}`);
    if (selectedMediaDiv) {
        selectedMediaDiv.innerHTML = `
            ${video ? `<span class="media-tag video">Video: ${video.id.substring(0, 10)}...</span>` : ''}
            ${image ? `<span class="media-tag image">Image: ${image.id.substring(0, 10)}...</span>` : ''}
        `;
    }

    closeMediaModal();
    showToast('Media selected', 'success');
}

function switchMediaTab(tab, event) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');

    document.querySelectorAll('.media-tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`media-${tab}-tab`).classList.add('active');
}

// Handle media upload - redirect to main api.php
async function handleMediaUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    showToast('Media upload uses api.php. Please upload via the main dashboard.', 'info');
}

// =====================
// Identity Modal
// =====================
function openCreateIdentityModal(adIndex) {
    state.currentAdIndex = adIndex;
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

    showLoading();

    try {
        const result = await apiRequest('create_identity', {
            display_name: displayName
        });

        if (result.success && result.identity_id) {
            // Add to identities list
            const newIdentity = {
                identity_id: result.identity_id,
                display_name: displayName
            };
            state.identities.push(newIdentity);

            // Update the ad's identity
            if (state.currentAdIndex !== null) {
                state.ads[state.currentAdIndex].identity_id = result.identity_id;
            }

            // Re-render ads to update dropdowns
            renderAds();

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

// Character counter for identity name
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('identity-display-name');
    const counter = document.getElementById('identity-char-count');

    if (input && counter) {
        input.addEventListener('input', function() {
            counter.textContent = this.value.length;
        });
    }
});

// =====================
// Utility Functions
// =====================
function showLoading() {
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

// Avatar modal functions (placeholders)
function selectIdentityAvatar() {
    showToast('Avatar selection coming soon', 'info');
}

function closeAvatarSelectionModal() {
    document.getElementById('avatar-selection-modal').style.display = 'none';
}

function switchAvatarTab(tab, event) {
    // Placeholder
}

function confirmAvatarSelection() {
    // Placeholder
}
