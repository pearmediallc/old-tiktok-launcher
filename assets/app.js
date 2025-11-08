// Global state
let state = {
    currentStep: 1,
    campaignId: null,
    adGroupId: null,
    ads: [],
    identities: [],
    mediaLibrary: [],
    selectedMedia: [],
    currentAdIndex: null,
    mediaSelectionMode: 'multiple' // Allow multiple selection
};

// API Logger Functions
function addLog(type, message, details = null) {
    const logsContent = document.getElementById('logs-content');
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
        toggleBtn.textContent = '▲';
    } else {
        toggleIcon.textContent = '▼ Hide Logs';
        toggleBtn.textContent = '▼';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeDayparting();
    initializeLocationTargeting();
    loadIdentities();
    loadMediaLibrary();
    loadPixels();  // Load available pixels
    addFirstAd();

    // Set default start date to tomorrow for both campaign and ad group (Colombia Time)
    const now = new Date();
    const colombiaTime = new Date(now.getTime() - (5 * 60 * 60 * 1000)); // UTC-5 for Colombia
    const tomorrow = new Date(colombiaTime);
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(9, 0, 0, 0); // Set to 9:00 AM Colombia time

    // Campaign start date
    if (document.getElementById('campaign-start-date')) {
        document.getElementById('campaign-start-date').value = formatDateTimeLocal(tomorrow);
    }

    // Ad group start date
    if (document.getElementById('start-date')) {
        document.getElementById('start-date').value = formatDateTimeLocal(tomorrow);
    }
});

// Format date for datetime-local input (Colombia Time UTC-05:00)
function formatDateTimeLocal(date) {
    // Convert to Colombia time (UTC-5) for display in datetime-local inputs
    const colombiaDate = new Date(date.getTime() - (5 * 60 * 60 * 1000));
    
    const year = colombiaDate.getUTCFullYear();
    const month = String(colombiaDate.getUTCMonth() + 1).padStart(2, '0');
    const day = String(colombiaDate.getUTCDate()).padStart(2, '0');
    const hours = String(colombiaDate.getUTCHours()).padStart(2, '0');
    const minutes = String(colombiaDate.getUTCMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Convert Colombia Time to UTC for TikTok API
function convertColombiaToUTC(colombiaDateTimeString) {
    if (!colombiaDateTimeString) return null;
    
    // The datetime-local input gives us a local time string (what user sees as Colombia time)
    // We need to treat this as Colombia time and convert to UTC
    
    // Parse the input as if it's Colombia time
    const [datePart, timePart] = colombiaDateTimeString.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hours, minutes] = timePart.split(':').map(Number);
    
    // Create UTC date object from Colombia time components
    // Add 5 hours to convert Colombia (UTC-5) to UTC
    const utcTime = new Date();
    utcTime.setUTCFullYear(year);
    utcTime.setUTCMonth(month - 1); // Month is 0-indexed
    utcTime.setUTCDate(day);
    utcTime.setUTCHours(hours + 5); // Colombia is UTC-5, so add 5 hours
    utcTime.setUTCMinutes(minutes);
    utcTime.setUTCSeconds(0);
    utcTime.setUTCMilliseconds(0);
    
    // Return in format expected by TikTok API
    return utcTime.toISOString().replace('T', ' ').substring(0, 19);
}

// Initialize dayparting grid
function initializeDayparting() {
    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const tbody = document.getElementById('dayparting-body');

    days.forEach((day, dayIndex) => {
        const tr = document.createElement('tr');
        
        // Create the day cell with "Select All" checkbox
        const dayCell = document.createElement('td');
        dayCell.innerHTML = `
            <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 4px;">
                <strong>${day}</strong>
                <label style="font-size: 11px; display: flex; align-items: center; gap: 4px; cursor: pointer;">
                    <input type="checkbox" class="day-select-all" data-day="${dayIndex}" 
                           onchange="toggleDayHours(${dayIndex})" style="transform: scale(0.8);">
                    <span>Select All</span>
                </label>
            </div>`;
        tr.appendChild(dayCell);

        for (let hour = 0; hour <= 24; hour++) {
            const td = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'hour-checkbox';
            checkbox.dataset.day = dayIndex;
            checkbox.dataset.hour = hour;
            checkbox.title = hour === 24 ? `${day} 24:00 (next day)` : `${day} ${hour}:00-${hour+1}:00`;
            checkbox.onchange = () => updateDaySelectAllState(dayIndex);
            // Don't check any by default
            checkbox.checked = false;
            td.appendChild(checkbox);
            tr.appendChild(td);
        }

        tbody.appendChild(tr);
    });
}

// Dayparting helper functions
function selectAllHours() {
    document.querySelectorAll('.hour-checkbox').forEach(cb => cb.checked = true);
    // Update all day select-all checkboxes
    document.querySelectorAll('.day-select-all').forEach(cb => cb.checked = true);
}

function clearAllHours() {
    document.querySelectorAll('.hour-checkbox').forEach(cb => cb.checked = false);
    // Update all day select-all checkboxes
    document.querySelectorAll('.day-select-all').forEach(cb => cb.checked = false);
}

function selectBusinessHours() {
    clearAllHours();
    document.querySelectorAll('.hour-checkbox').forEach(cb => {
        const hour = parseInt(cb.dataset.hour);
        const day = parseInt(cb.dataset.day);
        // Monday-Friday (1-5), 8am-5pm (8-17)
        cb.checked = (day >= 1 && day <= 5 && hour >= 8 && hour < 17);
    });
    // Update day select-all states
    for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
        updateDaySelectAllState(dayIndex);
    }
}

function selectPrimeTime() {
    clearAllHours();
    document.querySelectorAll('.hour-checkbox').forEach(cb => {
        const hour = parseInt(cb.dataset.hour);
        // All days, 6pm-10pm (18-22)
        cb.checked = (hour >= 18 && hour < 22);
    });
    // Update day select-all states
    for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
        updateDaySelectAllState(dayIndex);
    }
}

// Toggle all hours for a specific day
function toggleDayHours(dayIndex) {
    const daySelectAll = document.querySelector(`.day-select-all[data-day="${dayIndex}"]`);
    const dayHours = document.querySelectorAll(`.hour-checkbox[data-day="${dayIndex}"]`);
    
    dayHours.forEach(cb => {
        cb.checked = daySelectAll.checked;
    });
}

// Update the state of a day's "Select All" checkbox based on individual hour selections
function updateDaySelectAllState(dayIndex) {
    const daySelectAll = document.querySelector(`.day-select-all[data-day="${dayIndex}"]`);
    const dayHours = document.querySelectorAll(`.hour-checkbox[data-day="${dayIndex}"]`);
    
    const checkedHours = Array.from(dayHours).filter(cb => cb.checked);
    
    if (checkedHours.length === 0) {
        daySelectAll.checked = false;
        daySelectAll.indeterminate = false;
    } else if (checkedHours.length === dayHours.length) {
        daySelectAll.checked = true;
        daySelectAll.indeterminate = false;
    } else {
        daySelectAll.checked = false;
        daySelectAll.indeterminate = true;
    }
}

// Toggle dayparting section
function toggleDayparting() {
    const enabled = document.getElementById('enable-dayparting').checked;
    document.getElementById('dayparting-section').style.display = enabled ? 'block' : 'none';
}

// Toggle CBO budget section
function toggleCBOBudget() {
    const cboEnabled = document.getElementById('cbo-enabled').checked;
    const budgetSection = document.getElementById('campaign-budget-section');
    
    if (cboEnabled) {
        budgetSection.style.display = 'block';
    } else {
        budgetSection.style.display = 'none';
    }
}

// Get dayparting data
function getDaypartingData() {
    if (!document.getElementById('enable-dayparting').checked) {
        return {};
    }

    // TikTok format: 336 characters (7 days × 48 half-hour slots)
    // Each character represents a 30-minute slot
    // '1' = enabled, '0' = disabled
    // First char = Monday 00:00-00:30, Second = Monday 00:30-01:00, etc.
    let dayparting = '';

    // TikTok API expects: Monday to Sunday ordering
    // Our UI shows: Sunday to Saturday with hourly checkboxes
    // We need to reorder the days and duplicate each hour for two 30-min slots
    
    // Process in TikTok order: Monday (1), Tuesday (2), ..., Sunday (0)
    const tikTokDayOrder = [1, 2, 3, 4, 5, 6, 0]; // Mon, Tue, Wed, Thu, Fri, Sat, Sun
    
    for (let tikTokDay = 0; tikTokDay < 7; tikTokDay++) {
        const uiDay = tikTokDayOrder[tikTokDay];
        for (let hour = 0; hour <= 24; hour++) {
            // Convert Colombia Time (UTC-05:00) to UTC for TikTok
            let utcHour = hour + 5; // Colombia is UTC-05:00, so add 5 to get UTC
            let targetDay = uiDay;
            
            // Handle day overflow/underflow
            if (utcHour >= 24) {
                utcHour -= 24;
                targetDay = (uiDay + 1) % 7; // Next day
            } else if (utcHour < 0) {
                utcHour += 24;
                targetDay = (uiDay - 1 + 7) % 7; // Previous day
            }
            
            // Skip hour 24 for TikTok format (only 0-23)
            if (hour === 24) {
                continue;
            }
            
            const checkbox = document.querySelector(`.hour-checkbox[data-day="${uiDay}"][data-hour="${hour}"]`);
            const isChecked = checkbox && checkbox.checked;
            // Each hour creates two 30-minute slots
            dayparting += isChecked ? '11' : '00';
        }
    }

    // Must be exactly 336 characters (7 × 48)
    if (dayparting.length !== 336) {
        console.error('Dayparting string length is not 336:', dayparting.length);
        return {};
    }
    
    // Log for debugging
    console.log('Dayparting enabled, string length:', dayparting.length);
    console.log('First 48 chars (Monday):', dayparting.substring(0, 48));
    
    return {
        dayparting: dayparting
    };
}


// Step navigation
function nextStep() {
    if (state.currentStep < 4) {
        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.remove('active');
        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.add('completed');
        document.getElementById(`step-${state.currentStep}`).classList.remove('active');

        state.currentStep++;

        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.add('active');
        document.getElementById(`step-${state.currentStep}`).classList.add('active');

        window.scrollTo(0, 0);
    }
}

function prevStep() {
    if (state.currentStep > 1) {
        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.remove('active');
        document.getElementById(`step-${state.currentStep}`).classList.remove('active');

        state.currentStep--;

        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.remove('completed');
        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.add('active');
        document.getElementById(`step-${state.currentStep}`).classList.add('active');

        window.scrollTo(0, 0);
    }
}

// Campaign creation
async function createCampaign() {
    const campaignName = document.getElementById('campaign-name').value.trim();
    const startDate = document.getElementById('campaign-start-date').value;
    const endDate = document.getElementById('campaign-end-date').value;
    
    // Get CBO settings
    const cboEnabled = document.getElementById('cbo-enabled').checked;
    const campaignBudget = cboEnabled ? (parseFloat(document.getElementById('campaign-budget').value) || 0) : null;

    // Validate required fields
    if (!campaignName) {
        showToast('Please enter campaign name', 'error');
        return;
    }
    
    if (cboEnabled && (!campaignBudget || campaignBudget < 20)) {
        showToast('Campaign budget must be at least $20 when CBO is enabled', 'error');
        return;
    }

    showLoading();

    try {
        const params = {
            campaign_name: campaignName,
            cbo_enabled: cboEnabled
        };
        
        // Set budget parameters based on CBO setting
        if (cboEnabled) {
            params.budget = campaignBudget;
            params.budget_mode = 'BUDGET_MODE_DAY';
            params.budget_optimize_on = true;
        } else {
            params.budget_mode = 'BUDGET_MODE_INFINITE';
            params.budget_optimize_on = false;
        }
        
        // Add schedule times if provided (convert from Colombia Time to UTC)
        if (startDate) {
            params.schedule_start_time = convertColombiaToUTC(startDate);
        }

        // Store budget mode for ad group to use
        state.campaignBudgetMode = 'BUDGET_MODE_DAY';

        // Add end time if provided (convert from Colombia Time to UTC)
        if (endDate) {
            params.schedule_end_time = convertColombiaToUTC(endDate);
        }

        const response = await apiRequest('create_campaign', params);

        if (response.success && response.data && response.data.campaign_id) {
            state.campaignId = response.data.campaign_id;
            // Display campaign ID on ad group step
            document.getElementById('display-campaign-id').textContent = state.campaignId;
            showToast('Campaign created successfully', 'success');
            nextStep();
        } else {
            showToast(response.message || 'Failed to create campaign', 'error');
        }
    } catch (error) {
        showToast('Error creating campaign: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Helper function to format date to TikTok format
function formatToTikTokDateTime(date) {
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');
    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');
    const seconds = '00';
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

// Ad Group creation
async function createAdGroup() {
    const adGroupName = document.getElementById('adgroup-name').value.trim();

    // Get pixel ID from either dropdown or manual input based on selection
    const pixelMethodRadio = document.querySelector('input[name="pixel-method"]:checked');
    const pixelMethod = pixelMethodRadio ? pixelMethodRadio.value : 'dropdown';
    const pixelId = pixelMethod === 'manual'
        ? document.getElementById('pixel-manual-input').value.trim()
        : document.getElementById('lead-gen-form-id').value.trim();

    const budgetMode = document.getElementById('budget-mode').value;
    const budget = parseFloat(document.getElementById('budget').value);
    const startDate = document.getElementById('start-date').value;
    const bidPrice = parseFloat(document.getElementById('bid-price').value);

    console.log('=== AD GROUP CREATION DEBUG ===');
    console.log('Pixel Method:', pixelMethod);
    console.log('Pixel ID:', pixelId);
    console.log('Pixel ID type:', typeof pixelId);
    console.log('Pixel ID length:', pixelId ? pixelId.length : 0);
    console.log('Dropdown value:', document.getElementById('lead-gen-form-id').value);
    console.log('Manual input value:', document.getElementById('pixel-manual-input').value);
    console.log('================================');

    // Validate age group selection
    const selectedAgeGroups = getSelectedAgeGroups();
    if (selectedAgeGroups.length === 0) {
        showToast('Please select at least one age group for targeting', 'error');
        return;
    }

    // Validate location targeting
    const selectedLocationIds = getSelectedLocationIds();
    console.log('Validation - selectedLocationIds:', selectedLocationIds);
    
    if (!selectedLocationIds || selectedLocationIds.length === 0) {
        showToast('Please select locations for targeting or upload a location file', 'error');
        return;
    }

    if (!adGroupName || !pixelId || !budget || !startDate) {
        showToast('Please fill in all required fields (including pixel ID)', 'error');
        console.error('Missing fields - Pixel ID:', pixelId);
        return;
    }

    // Validate pixel_id is numeric
    if (!/^\d+$/.test(pixelId)) {
        showToast('Pixel ID must be numeric (e.g., 1234567890)', 'error');
        console.error('Invalid pixel ID format:', pixelId);
        return;
    }

    if (budget < 20) {
        showToast('Minimum budget is $20', 'error');
        return;
    }

    showLoading();

    try {
        // Convert Colombia Time to UTC for TikTok API
        const scheduleStartTime = convertColombiaToUTC(startDate);

        // Based on TikTok screenshots: Complete ad group configuration
        const params = {
            // BASIC INFO
            campaign_id: state.campaignId,
            adgroup_name: adGroupName,

            // OPTIMIZATION (Lead Generation via Website Forms)
            promotion_type: 'LEAD_GENERATION',  // LEAD_GENERATION for lead gen campaigns
            promotion_target_type: 'EXTERNAL_WEBSITE',  // External website for lead generation
            pixel_id: pixelId,  // Pixel ID for tracking form conversions
            optimization_goal: 'CONVERT',  // CONVERT for lead forms
            optimization_event: 'FORM',  // FORM event for lead generation
            billing_event: 'OCPM',

            // ATTRIBUTION SETTINGS (Required for Lead Generation)
            click_attribution_window: 'SEVEN_DAYS',  // 7-day click attribution
            view_attribution_window: 'ONE_DAY',  // 1-day view attribution
            attribution_event_count: 'EVERY',  // Count every conversion

            // PLACEMENTS
            placement_type: 'PLACEMENT_TYPE_NORMAL',  // Select placement
            placements: ['PLACEMENT_TIKTOK'],  // TikTok only

            // DEMOGRAPHICS - TARGETING
            location_ids: selectedLocationIds,  // User-selected locations
            age_groups: selectedAgeGroups,  // User-selected age groups
            gender: 'GENDER_UNLIMITED',  // All genders

            // BUDGET AND SCHEDULE (use campaign's budget mode if set, otherwise use ad group's)
            budget_mode: state.campaignBudgetMode || budgetMode,  // Use campaign's budget mode if available
            budget: budget,
            schedule_type: 'SCHEDULE_FROM_NOW',  // Set start time and run continuously
            schedule_start_time: scheduleStartTime,

            // PACING
            pacing: 'PACING_MODE_SMOOTH',  // Standard delivery

            // DAYPARTING (optional)
            ...getDaypartingData()
        };

        // Set bidding type based on whether bid price is provided
        if (bidPrice && bidPrice > 0) {
            params.bid_type = 'BID_TYPE_CUSTOM';
            params.conversion_bid_price = bidPrice;  // Target CPA for conversions
        } else {
            params.bid_type = 'BID_TYPE_NO_BID';  // Let TikTok optimize automatically
        }

        console.log('Sending ad group params:', JSON.stringify(params, null, 2));

        const response = await apiRequest('create_adgroup', params);

        console.log('Ad group API response:', response);

        if (response.success && response.data && response.data.adgroup_id) {
            state.adGroupId = response.data.adgroup_id;
            showToast('Ad group created successfully', 'success');
            nextStep();
        } else {
            const errorMsg = response.message || 'Failed to create ad group';
            console.error('Ad group creation failed:', errorMsg);
            console.error('Full error response:', response);
            showToast(errorMsg, 'error');
        }
    } catch (error) {
        showToast('Error creating ad group: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Add first ad
function addFirstAd() {
    const adIndex = state.ads.length;
    addAdForm(adIndex);
    state.ads.push({ index: adIndex });
}

// Add ad form
function addAdForm(index, duplicateFrom = null) {
    const container = document.getElementById('ads-container');

    const adCard = document.createElement('div');
    adCard.className = 'ad-card';
    adCard.id = `ad-${index}`;

    const allCTAs = [
        'APPLY_NOW', 'BOOK_NOW', 'CALL_NOW', 'CHECK_AVAILABLILITY', 'CONTACT_US',
        'DOWNLOAD_NOW', 'EXPERIENCE_NOW', 'GET_QUOTE', 'GET_SHOWTIMES', 'GET_TICKETS_NOW',
        'INSTALL_NOW', 'INTERESTED', 'LEARN_MORE', 'LISTEN_NOW', 'ORDER_NOW',
        'PLAY_GAME', 'PREORDER_NOW', 'READ_MORE', 'SEND_MESSAGE', 'SHOP_NOW',
        'SIGN_UP', 'SUBSCRIBE', 'VIEW_NOW', 'VIEW_PROFILE', 'VISIT_STORE',
        'WATCH_LIVE', 'WATCH_NOW', 'JOIN_THIS_HASHTAG', 'SHOOT_WITH_THIS_EFFECT', 
        'VIEW_VIDEO_WITH_THIS_EFFECT'
    ];

    adCard.innerHTML = `
        <div class="ad-card-header">
            <h3>Ad #${index + 1}</h3>
            <div class="ad-card-actions">
                ${index > 0 ? '<button class="btn-icon" onclick="removeAd(' + index + ')" title="Delete">🗑️</button>' : ''}
            </div>
        </div>

        <div class="form-group">
            <label>Ad Name</label>
            <input type="text" id="ad-name-${index}" placeholder="Enter ad name" required>
        </div>

        <div class="form-group">
            <label>Creative Media (Video or Image)</label>
            <div class="creative-placeholder" onclick="openMediaModal(${index}, 'primary')">
                <span id="creative-placeholder-${index}">Click to select video or image</span>
            </div>
            <img id="creative-preview-${index}" class="creative-preview" style="display: none;">
            <input type="hidden" id="creative-id-${index}">
            <input type="hidden" id="creative-type-${index}">
        </div>

        <div class="form-group" id="cover-image-group-${index}" style="display: none;">
            <label>Cover Image (Required for Video Ads)</label>
            <div style="margin-bottom: 10px;">
                <button type="button" class="btn-secondary" onclick="useVideoThumbnail(${index})" 
                        id="use-thumbnail-btn-${index}" style="width: 100%;">
                    🎬 Use Video Thumbnail
                </button>
            </div>
            <div class="creative-placeholder" style="border-color: #667eea;">
                <span id="cover-placeholder-${index}">Click "Use Video Thumbnail" above</span>
            </div>
            <img id="cover-preview-${index}" class="creative-preview" style="display: none;">
            <input type="hidden" id="cover-image-id-${index}">
        </div>

        <div class="form-group">
            <label>Identity</label>
            <select id="identity-${index}" required>
                <option value="">Select identity...</option>
            </select>
        </div>

        <div class="form-group">
            <label>Ad Copy (Text)</label>
            <textarea id="ad-text-${index}" placeholder="Enter your ad copy" rows="3" required></textarea>
        </div>

        <div class="form-group">
            <label>Call to Action</label>
            <div class="cta-chips" id="cta-chips-${index}">
                ${allCTAs.map(cta => `
                    <div class="cta-chip" data-cta="${cta}" onclick="selectCTA(${index}, '${cta}')">
                        ${cta.replace(/_/g, ' ')}
                    </div>
                `).join('')}
            </div>
            <input type="hidden" id="cta-${index}" value="LEARN_MORE">
        </div>

        <div class="form-group">
            <label>Destination URL (Optional - Only for tracking)</label>
            <input type="text" id="destination-url-${index}" placeholder="https://example.com/thank-you (optional for Lead Gen)">
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="auto-url-params-${index}" checked>
                Automatically add URL parameters
            </label>
        </div>
    `;

    container.appendChild(adCard);

    // Populate identities
    populateIdentitiesForAd(index);

    // Select LEARN_MORE as default CTA
    setTimeout(() => selectCTA(index, 'LEARN_MORE'), 100);

    // If duplicating, copy values
    if (duplicateFrom !== null) {
        setTimeout(() => {
            document.getElementById(`ad-name-${index}`).value =
                document.getElementById(`ad-name-${duplicateFrom}`).value + ' (Copy)';
            document.getElementById(`ad-text-${index}`).value =
                document.getElementById(`ad-text-${duplicateFrom}`).value;
            document.getElementById(`destination-url-${index}`).value =
                document.getElementById(`destination-url-${duplicateFrom}`).value;
            document.getElementById(`identity-${index}`).value =
                document.getElementById(`identity-${duplicateFrom}`).value;

            const creativeId = document.getElementById(`creative-id-${duplicateFrom}`).value;
            const creativeType = document.getElementById(`creative-type-${duplicateFrom}`).value;

            if (creativeId) {
                document.getElementById(`creative-id-${index}`).value = creativeId;
                document.getElementById(`creative-type-${index}`).value = creativeType;

                const preview = document.getElementById(`creative-preview-${duplicateFrom}`);
                if (preview && preview.style.display !== 'none') {
                    const newPreview = document.getElementById(`creative-preview-${index}`);
                    newPreview.src = preview.src;
                    newPreview.style.display = 'block';
                    document.getElementById(`creative-placeholder-${index}`).parentElement.style.display = 'none';
                }
            }
        }, 100);
    }
}

// Duplicate ad
function duplicateAd() {
    const lastAdIndex = state.ads.length - 1;
    const newIndex = state.ads.length;

    addAdForm(newIndex, lastAdIndex);
    state.ads.push({ index: newIndex });

    showToast('Ad duplicated', 'success');
}

// Remove ad
function removeAd(index) {
    if (confirm('Are you sure you want to delete this ad?')) {
        const adCard = document.getElementById(`ad-${index}`);
        adCard.remove();

        state.ads = state.ads.filter(ad => ad.index !== index);

        showToast('Ad removed', 'success');
    }
}

// Select CTA (single selection)
function selectCTA(adIndex, cta) {
    // Remove selected class from all CTAs for this ad
    const chips = document.querySelectorAll(`#cta-chips-${adIndex} .cta-chip`);
    chips.forEach(chip => chip.classList.remove('selected'));

    // Add selected class to clicked CTA
    const selectedChip = document.querySelector(`#cta-chips-${adIndex} .cta-chip[data-cta="${cta}"]`);
    if (selectedChip) {
        selectedChip.classList.add('selected');
    }

    // Update hidden input
    document.getElementById(`cta-${adIndex}`).value = cta;
}

// Media modal
function openMediaModal(adIndex, selectionType = 'primary') {
    state.currentAdIndex = adIndex;
    state.currentSelectionType = selectionType;
    state.selectedMedia = []; // Reset selection

    const modal = document.getElementById('media-modal');
    modal.classList.add('show');

    // Update modal title and tabs based on selection type
    const modalTitle = modal.querySelector('.modal-header h3');
    const modalTabs = modal.querySelector('.modal-tabs');
    
    if (selectionType === 'cover') {
        modalTitle.innerHTML = 'Select Cover Image <span style="font-size: 14px; color: #667eea; margin-left: 10px;">(Images only)</span>';
        
        // Update tabs for image-only selection
        modalTabs.innerHTML = `
            <button class="tab-btn active" onclick="switchMediaTab('library', event)">Image Library</button>
            <button class="tab-btn" onclick="switchMediaTab('upload', event)">Upload Image</button>
            <button class="btn-secondary btn-sm" onclick="loadImageLibrary()" style="margin-left: auto;">🔄 Refresh</button>
            <button class="btn-secondary btn-sm" onclick="syncImagesFromTikTok()">📥 Sync from TikTok</button>
        `;
        
        loadImageLibrary(); // Load only images for cover selection
    } else {
        modalTitle.innerHTML = 'Select Media <span id="selection-counter" style="font-size: 14px; color: #667eea; margin-left: 10px; display: none;"></span>';
        
        // Restore default tabs
        modalTabs.innerHTML = `
            <button class="tab-btn active" onclick="switchMediaTab('library', event)">Library</button>
            <button class="tab-btn" onclick="switchMediaTab('upload', event)">Upload New</button>
            <button class="btn-secondary btn-sm" onclick="refreshMediaLibrary()" style="margin-left: auto;">🔄 Refresh</button>
            <button class="btn-secondary btn-sm" onclick="syncTikTokLibrary()">📥 Sync from TikTok</button>
        `;
        
        loadMediaLibrary(); // Load all media
    }
    
    updateSelectionCounter();
}

function closeMediaModal() {
    const modal = document.getElementById('media-modal');
    modal.classList.remove('show');
    state.currentAdIndex = null;
    state.selectedMedia = [];
}

function switchMediaTab(tab, evt) {
    // Get event from parameter
    const clickEvent = evt;
    
    // Remove active class from all tabs and buttons
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.media-tab').forEach(tabContent => tabContent.classList.remove('active'));

    // Add active class to clicked button (if event exists) and corresponding tab
    if (clickEvent && clickEvent.target) {
        clickEvent.target.classList.add('active');
    } else {
        // If no event, find the button by text content
        document.querySelectorAll('.tab-btn').forEach(btn => {
            if (btn.textContent.toLowerCase().includes(tab.toLowerCase())) {
                btn.classList.add('active');
            }
        });
    }
    
    // Show the correct tab content
    const tabElement = document.getElementById(`media-${tab}-tab`);
    if (tabElement) {
        tabElement.classList.add('active');
    }
}

// Load media library
async function loadImageLibrary() {
    const mediaGrid = document.getElementById('media-grid');
    mediaGrid.innerHTML = '<div class="loading">Loading images from TikTok...</div>';

    try {
        console.log('🚀 STARTING API REQUEST for get_images...');
        const response = await apiRequest('get_images', {}, 'GET');
        
        console.log('============ API RESPONSE DEBUG ============');
        console.log('📥 Complete API Response:', response);
        console.log('📥 Response type:', typeof response);
        console.log('📥 Response.success:', response.success);
        console.log('📥 Response.data:', response.data);
        console.log('📥 Response.data type:', typeof response.data);
        
        if (response.data) {
            console.log('📥 Response.data.list:', response.data.list);
            console.log('📥 Response.data.list type:', typeof response.data.list);
            console.log('📥 Response.data.list is array:', Array.isArray(response.data.list));
            if (response.data.list) {
                console.log('📥 Response.data.list length:', response.data.list.length);
            }
        }
        console.log('============ END API RESPONSE DEBUG ============');
        
        if (response.success && response.data && response.data.list) {
            const images = response.data.list;
            
            console.log('✅ Processing images array with', images.length, 'items');
            
            if (images.length === 0) {
                mediaGrid.innerHTML = `
                    <div style="grid-column: 1 / -1;">
                        <div style="padding: 15px; background: #f0f8ff; border-radius: 8px; margin-bottom: 15px;">
                            <h4 style="margin: 0 0 10px 0; color: #333;">Manual Image ID Entry</h4>
                            <p style="font-size: 12px; color: #666; margin: 0 0 10px 0;">
                                Enter your image ID from TikTok Ads Manager:
                            </p>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" id="manual-image-id" placeholder="Enter TikTok Image ID" 
                                       style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <button onclick="useManualImageId()" class="btn-primary" style="padding: 8px 16px;">
                                    Use This Image
                                </button>
                            </div>
                        </div>
                        <div class="empty-state">
                            <p>No images found in TikTok library</p>
                            <small>Upload images to TikTok first or enter an image ID manually above</small>
                        </div>
                    </div>`;
                return;
            }

            console.log(`Loaded ${images.length} images from library`);
            
            // Display images for visual selection
            const imagesHtml = images.map(image => {
                // Create a safe object for selection with the image_id
                const safeImage = {
                    image_id: image.image_id,
                    url: image.url || '',
                    file_name: image.file_name || `Image ${image.image_id}`,
                    type: 'image'
                };
                
                return `
                <div class="media-item" onclick='selectMedia(${JSON.stringify(safeImage)})' data-id="${image.image_id}" 
                     style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;"
                     onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'"
                     onmouseout="this.style.transform='scale(1)'; this.style.boxShadow=''">
                    ${image.url ? 
                        `<img src="${image.url}" alt="${image.file_name || 'Image'}" 
                              style="width: 100%; height: 100%; object-fit: cover;" 
                              onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'media-placeholder\\' style=\\'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;\\'>🖼️</div>'">` : 
                        `<div class="media-placeholder" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); position: relative;">
                            <div class="media-icon">🖼️</div>
                            <div style="position: absolute; bottom: 5px; right: 5px; font-size: 9px; background: rgba(0,0,0,0.5); color: white; padding: 2px 4px; border-radius: 3px;">
                                Image
                            </div>
                        </div>`
                    }
                    <div class="media-info">
                        <div class="media-name" title="${image.file_name || 'Image'}">${(image.file_name || 'Image').substring(0, 20)}${(image.file_name || '').length > 20 ? '...' : ''}</div>
                        <div class="media-type" style="color: #667eea;">Click to Select</div>
                    </div>
                </div>`;
            }).join('');
            
            // Only show manual entry as a small option at the bottom if needed
            const manualInputHtml = `
                <div style="grid-column: 1 / -1; padding: 10px; background: #f9f9f9; border-radius: 6px; margin-top: 15px; border: 1px dashed #ddd;">
                    <details>
                        <summary style="cursor: pointer; font-size: 13px; color: #666;">Can't see your image? Enter ID manually</summary>
                        <div style="margin-top: 10px; display: flex; gap: 10px;">
                            <input type="text" id="manual-image-id" placeholder="Enter TikTok Image ID" 
                                   style="flex: 1; padding: 6px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">
                            <button onclick="useManualImageId()" class="btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                Use ID
                            </button>
                        </div>
                    </details>
                </div>
            `;
            
            mediaGrid.innerHTML = imagesHtml + (images.length > 0 ? manualInputHtml : '');
            
            if (images.length > 0) {
                showToast(`Found ${images.length} images. Click any image to select it.`, 'success');
            }
        } else {
            mediaGrid.innerHTML = `
                <div class="error">
                    <p>Failed to load images from TikTok</p>
                    <button class="btn-secondary" onclick="loadImageLibrary()">Try Again</button>
                </div>`;
        }
    } catch (error) {
        console.error('Error loading images:', error);
        mediaGrid.innerHTML = `
            <div class="error">
                <p>Error loading images: ${error.message}</p>
                <button class="btn-secondary" onclick="loadImageLibrary()">Try Again</button>
            </div>`;
    }
}

async function loadMediaLibrary() {
    try {
        showToast('Loading media library...', 'info');
        
        const [imagesResponse, videosResponse] = await Promise.all([
            apiRequest('get_images', {}, 'GET'),
            apiRequest('get_videos', {}, 'GET')
        ]);

        state.mediaLibrary = [];

        console.log('GET IMAGES API Response:', imagesResponse);
        
        if (imagesResponse.success && imagesResponse.data && imagesResponse.data.list) {
            console.log('✅ Images loaded:', imagesResponse.data.list.length, imagesResponse.data.list);
            state.mediaLibrary.push(...imagesResponse.data.list.map(img => ({
                ...img,
                type: 'image'
            })));
        } else {
            console.log('❌ Images response failed or empty:', imagesResponse);
            console.log('Images response success:', imagesResponse.success);
            console.log('Images response data:', imagesResponse.data);
            console.log('Images response message:', imagesResponse.message);
        }

        if (videosResponse.success && videosResponse.data && videosResponse.data.list) {
            console.log('Videos loaded:', videosResponse.data.list.length, videosResponse.data.list);
            state.mediaLibrary.push(...videosResponse.data.list.map(vid => ({
                ...vid,
                type: 'video'
            })));
        } else {
            console.log('Videos response failed or empty:', videosResponse);
        }

        console.log('Final media library array:', state.mediaLibrary);
        console.log('Images in library:', state.mediaLibrary.filter(m => m.type === 'image').length);
        console.log('Videos in library:', state.mediaLibrary.filter(m => m.type === 'video').length);
        
        renderMediaGrid();
        updateMediaCount();
        
        if (state.mediaLibrary.length === 0) {
            showToast('No media found. Upload files to add to library.', 'info');
        } else {
            const imageCount = state.mediaLibrary.filter(m => m.type === 'image').length;
            const videoCount = state.mediaLibrary.filter(m => m.type === 'video').length;
            showToast(`Loaded ${state.mediaLibrary.length} media file(s) (${imageCount} images, ${videoCount} videos)`, 'success');
        }
    } catch (error) {
        console.error('Error loading media library:', error);
        showToast('Error loading media library', 'error');
    }
}

// Use video thumbnail as cover image
async function useVideoThumbnail(adIndex) {
    const creativeId = document.getElementById(`creative-id-${adIndex}`).value;
    const creativeType = document.getElementById(`creative-type-${adIndex}`).value;
    
    if (creativeType !== 'video' || !creativeId) {
        showToast('Please select a video first', 'error');
        return;
    }
    
    // Find the video data to get thumbnail URL
    let videoData = null;
    let thumbnailUrl = null;
    
    // Check if we have stored video data
    if (state.selectedVideoData && state.selectedVideoData[creativeId]) {
        videoData = state.selectedVideoData[creativeId];
    } else {
        // Try to find from current media library (if loaded)
        showToast('Loading video information...', 'info');
        
        try {
            const response = await apiRequest('get_videos', {}, 'GET');
            if (response.success && response.data && response.data.list) {
                const video = response.data.list.find(v => v.video_id === creativeId);
                if (video) {
                    videoData = video;
                }
            }
        } catch (error) {
            console.error('Error fetching video data:', error);
        }
    }
    
    // Look for thumbnail URL in multiple possible fields
    if (videoData) {
        thumbnailUrl = videoData.video_cover_url || 
                      videoData.cover_url || 
                      videoData.preview_url || 
                      videoData.thumbnail_url || 
                      videoData.poster_url;
    }
    
    if (!thumbnailUrl) {
        // Try to generate a thumbnail using the video file itself
        showToast('Generating thumbnail from video...', 'info');
        
        try {
            const response = await apiRequest('generate_video_thumbnail', {
                video_id: creativeId
            });
            
            if (response.success && response.data && response.data.thumbnail_url) {
                thumbnailUrl = response.data.thumbnail_url;
            }
        } catch (error) {
            console.error('Error generating thumbnail:', error);
        }
    }
    
    if (!thumbnailUrl) {
        showToast('No thumbnail available for this video. Try uploading a cover image manually.', 'error');
        return;
    }
    
    showLoading('Uploading video thumbnail to TikTok...');
    
    try {
        const response = await apiRequest('upload_thumbnail_as_cover', {
            video_id: creativeId,
            thumbnail_url: thumbnailUrl
        });
        
        if (response.success && response.data && response.data.image_id) {
            const imageId = response.data.image_id;
            
            // Set the cover image ID
            document.getElementById(`cover-image-id-${adIndex}`).value = imageId;
            
            // Update the UI
            const coverPlaceholder = document.getElementById(`cover-placeholder-${adIndex}`);
            const coverContainer = coverPlaceholder.parentElement;
            
            coverContainer.classList.add('has-media');
            coverContainer.style.backgroundImage = `url(${thumbnailUrl})`;
            coverContainer.style.backgroundSize = 'cover';
            coverContainer.style.backgroundPosition = 'center';
            
            coverPlaceholder.innerHTML = `
                <div class="media-selected-info">
                    <div class="media-type-badge">🎬</div>
                    <div class="media-name">Video Thumbnail</div>
                </div>`;
            
            showToast('Video thumbnail uploaded and set as cover image!', 'success');
        } else {
            showToast(response.message || 'Failed to upload thumbnail', 'error');
        }
    } catch (error) {
        console.error('Error uploading thumbnail:', error);
        showToast('Error uploading thumbnail: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Use manually entered image ID
function useManualImageId() {
    const imageIdInput = document.getElementById('manual-image-id');
    if (!imageIdInput) return;
    
    const imageId = imageIdInput.value.trim();
    if (!imageId) {
        showToast('Please enter an image ID', 'error');
        return;
    }
    
    // Create a media object with just the ID
    const manualImage = {
        image_id: imageId,
        url: '',
        file_name: `Manual Image (${imageId})`,
        type: 'image'
    };
    
    // Select this image
    selectMedia(manualImage);
    
    showToast(`Selected image ID: ${imageId}`, 'success');
    
    // Clear the input
    imageIdInput.value = '';
}

// Sync images from TikTok
async function syncImagesFromTikTok() {
    showLoading();
    try {
        const response = await apiRequest('sync_images_from_tiktok');
        
        if (response.success) {
            showToast(response.message || 'Images synced successfully', 'success');
            // Reload the image library
            if (state.currentSelectionType === 'cover') {
                loadImageLibrary();
            }
        } else {
            showToast(response.message || 'Failed to sync images', 'error');
        }
    } catch (error) {
        showToast('Error syncing images: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Refresh media library
async function refreshMediaLibrary() {
    await loadMediaLibrary();
}

// Sync with TikTok library
async function syncTikTokLibrary() {
    try {
        showToast('Syncing with TikTok library...', 'info');
        
        const response = await apiRequest('sync_tiktok_library', {}, 'POST');
        
        if (response.success) {
            const videoMsg = response.total_videos ? ` (${response.total_videos} videos` : ' (0 videos';
            const imageMsg = response.total_images ? `, ${response.total_images} images)` : ', 0 images)';
            showToast(response.message + videoMsg + imageMsg, 'success');
            // Reload the media library to show new items
            await loadMediaLibrary();
        } else {
            showToast('Failed to sync with TikTok', 'error');
        }
    } catch (error) {
        console.error('Error syncing with TikTok:', error);
        showToast('Error syncing with TikTok library', 'error');
    }
}

// Render media grid
function renderMediaGrid() {
    const grid = document.getElementById('media-grid');
    grid.innerHTML = '';

    const filteredMedia = getFilteredMedia();
    console.log('Rendering media grid with', filteredMedia.length, 'filtered items (', state.mediaLibrary.length, 'total)');

    if (state.mediaLibrary.length === 0) {
        grid.innerHTML = '<p style="text-align: center; color: #999;">No media in library. Upload some files to get started.</p>';
        return;
    }

    if (filteredMedia.length === 0) {
        grid.innerHTML = '<p style="text-align: center; color: #999;">No media matches the current filter.</p>';
        return;
    }

    filteredMedia.forEach((media, index) => {
        console.log(`Rendering media ${index}:`, media.type, media.file_name || 'unnamed');
        const item = document.createElement('div');
        item.className = 'media-item';
        item.dataset.id = media.video_id || media.image_id || media.id;
        item.dataset.type = media.type;
        item.onclick = () => selectMedia(media);

        if (media.type === 'image') {
            // Prioritize TikTok's image_url field first, then fallback to other fields
            const imgUrl = media.image_url || media.url || media.preview_url || media.thumbnail_url;
            
            console.log('============ FRONTEND IMAGE DEBUG ============');
            console.log('📷 Processing image media object:', media);
            console.log('🔍 Complete media object structure:', JSON.stringify(media, null, 2));
            
            // Check each URL field individually
            console.log('🌐 URL Fields Analysis:');
            console.log('  media.image_url:', typeof media.image_url, '=', media.image_url);
            console.log('  media.url:', typeof media.url, '=', media.url);
            console.log('  media.preview_url:', typeof media.preview_url, '=', media.preview_url);
            console.log('  media.thumbnail_url:', typeof media.thumbnail_url, '=', media.thumbnail_url);
            
            // Check what imgUrl was selected
            console.log('🎯 URL Selection Logic:');
            if (media.image_url) {
                console.log('  ✅ Using media.image_url:', media.image_url);
            } else if (media.url) {
                console.log('  ⚠️  Falling back to media.url:', media.url);
            } else if (media.preview_url) {
                console.log('  ⚠️  Falling back to media.preview_url:', media.preview_url);
            } else if (media.thumbnail_url) {
                console.log('  ⚠️  Falling back to media.thumbnail_url:', media.thumbnail_url);
            } else {
                console.log('  ❌ NO URL AVAILABLE!');
            }
            
            console.log('🏆 FINAL SELECTED imgUrl:', imgUrl);
            console.log('🏆 imgUrl type:', typeof imgUrl);
            console.log('🏆 imgUrl length:', imgUrl ? imgUrl.length : 'NULL/UNDEFINED');
            console.log('🏆 imgUrl empty check:', imgUrl === '' ? 'EMPTY STRING' : 'NOT EMPTY');
            console.log('============ END FRONTEND IMAGE DEBUG ============');
            
            if (imgUrl && imgUrl !== '') {
                item.innerHTML = `
                    <div style="position: relative; width: 100%; height: 150px; border: 2px solid #4fc3f7; border-radius: 8px; overflow: hidden;">
                        <img src="${imgUrl}" 
                             alt="${media.file_name || 'Image'}" 
                             style="width: 100%; height: 100%; object-fit: cover;"
                             onload="console.log('✅ Image loaded successfully:', '${media.file_name}', 'URL:', '${imgUrl.substring(0, 50)}...')"
                             onerror="console.error('❌ Image failed to load:', '${media.file_name}', 'URL:', '${imgUrl.substring(0, 50)}...'); this.style.display='none'; this.parentElement.innerHTML='<div style=&quot;background: linear-gradient(135deg, #ff5722, #f44336); width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: white;&quot;><div style=&quot;font-size: 30px;&quot;>❌</div><small>Image Failed</small><br><tiny>${media.file_name || 'Unknown'}</tiny></div>'">
                        <div style="position: absolute; top: 5px; right: 5px; background: #4fc3f7; 
                                    padding: 2px 6px; border-radius: 3px; font-size: 10px; color: white; font-weight: bold;">
                            📷 IMAGE
                        </div>
                    </div>
                    <div class="media-info" style="padding: 5px; background: rgba(79, 195, 247, 0.1); border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
                        <div style="font-weight: 600; font-size: 12px; color: #0277bd;">${media.file_name || 'Image'}</div>
                        <div style="font-size: 10px; color: #0288d1;">${media.width && media.height ? `${media.width}×${media.height}px` : 'Image file'}</div>
                    </div>`;
            } else {
                item.innerHTML = `
                    <div style="width: 100%; height: 150px; background: linear-gradient(135deg, #4fc3f7, #29b6f6); 
                                display: flex; flex-direction: column; align-items: center; justify-content: center; color: white; position: relative;">
                        <div style="font-size: 40px; margin-bottom: 5px;">🖼️</div>
                        <div style="font-size: 12px; font-weight: 600;">${media.file_name || 'Image'}</div>
                        <div style="font-size: 10px; opacity: 0.8; margin-top: 4px;">No preview available</div>
                    </div>
                    <div class="media-info" style="padding: 5px; background: rgba(0,0,0,0.05);">
                        <div style="font-weight: 600; font-size: 12px;">${media.file_name || 'Image'}</div>
                    </div>`;
            }
        } else {
            // For videos, show preview image or placeholder
            const previewUrl = media.preview_url || media.thumbnail_url || media.poster_url || media.cover_url;
            const videoUrl = media.url || media.video_url;
            
            if (previewUrl && previewUrl !== '') {
                item.innerHTML = `
                    <div style="position: relative; width: 100%; height: 150px; border: 2px solid #667eea; border-radius: 8px; overflow: hidden;">
                        <img src="${previewUrl}" 
                             alt="Video: ${media.file_name || media.video_id}" 
                             style="width: 100%; height: 100%; object-fit: cover;"
                             onerror="this.style.display='none';">
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                                    background: rgba(0,0,0,0.6); border-radius: 50%; width: 40px; height: 40px; 
                                    display: flex; align-items: center; justify-content: center;">
                            <span style="color: white; font-size: 18px; margin-left: 2px;">▶</span>
                        </div>
                        <div style="position: absolute; top: 5px; right: 5px; background: #667eea; 
                                    padding: 2px 6px; border-radius: 3px; font-size: 10px; color: white; font-weight: bold;">
                            🎬 VIDEO
                        </div>
                        <div style="position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.7); 
                                    padding: 2px 6px; border-radius: 3px; font-size: 10px; color: white;">
                            ${media.duration ? `${Math.round(media.duration)}s` : 'Video'}
                        </div>
                    </div>
                    <div class="media-info" style="padding: 5px; background: rgba(102, 126, 234, 0.1); border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
                        <div style="font-weight: 600; font-size: 12px; color: #3f51b5;">${media.file_name || 'Video'}</div>
                        <div style="font-size: 10px; color: #5c6bc0;">${media.width && media.height ? `${media.width}×${media.height}px` : 'Video file'} ${media.duration ? `• ${Math.round(media.duration)}s` : ''}</div>
                    </div>`;
            } else if (videoUrl) {
                item.innerHTML = `
                    <div style="position: relative; width: 100%; height: 150px; background: #000;">
                        <video src="${videoUrl}" 
                               style="width: 100%; height: 100%; object-fit: cover;"
                               muted
                               onloadedmetadata="this.currentTime=1"
                               onerror="this.style.display='none'; this.parentElement.classList.add('video-no-preview');"></video>
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                                    background: rgba(0,0,0,0.6); border-radius: 50%; width: 40px; height: 40px; 
                                    display: flex; align-items: center; justify-content: center;">
                            <span style="color: white; font-size: 18px; margin-left: 2px;">▶</span>
                        </div>
                    </div>
                    <div class="media-info">
                        <span class="media-name">${media.file_name || 'Video'}</span>
                        ${media.duration ? `<span class="media-duration">${Math.round(media.duration)}s</span>` : ''}
                    </div>`;
            } else {
                // Fallback display for videos without thumbnails
                item.innerHTML = `
                    <div style="width: 100%; height: 150px; background: linear-gradient(135deg, #667eea, #764ba2); 
                                display: flex; flex-direction: column; align-items: center; justify-content: center; color: white; position: relative;">
                        <div style="font-size: 40px; margin-bottom: 5px;">🎬</div>
                        <div style="font-size: 12px; font-weight: 600;">${media.file_name || 'Video'}</div>
                        <div style="font-size: 10px; opacity: 0.8; margin-top: 4px;">Click to generate thumbnail</div>
                        ${media.duration ? `<div style="position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.5); 
                                                        padding: 2px 6px; border-radius: 3px; font-size: 10px;">
                            ${Math.round(media.duration)}s</div>` : ''}
                    </div>
                    <div class="media-info" style="padding: 5px; background: rgba(0,0,0,0.05);">
                        <div style="font-weight: 600; font-size: 12px;">${media.file_name || 'Video'}</div>
                    </div>`;
                
                // Add click handler to generate thumbnail
                item.addEventListener('click', async () => {
                    if (media.type === 'video' && !previewUrl) {
                        try {
                            showToast('Generating thumbnail...', 'info');
                            const response = await apiRequest('generate_video_thumbnail', {
                                video_id: media.video_id
                            });
                            
                            if (response.success && response.data && response.data.thumbnail_url) {
                                // Update the media object and re-render
                                media.preview_url = response.data.thumbnail_url;
                                renderMediaGrid();
                                showToast('Thumbnail generated successfully!', 'success');
                            }
                        } catch (error) {
                            console.error('Error generating thumbnail:', error);
                        }
                    }
                });
            }
        }

        // Add file info as tooltip
        const dimensions = (media.width && media.height) ? ` (${media.width}x${media.height})` : '';
        item.title = `${media.file_name || 'Media'}${dimensions}`;

        grid.appendChild(item);
    });
}

// Media filtering functionality
let currentMediaFilter = 'all';

function filterMedia(filterType) {
    currentMediaFilter = filterType;
    
    // Update filter button states
    document.querySelectorAll('.media-filter').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.filter === filterType) {
            btn.classList.add('active');
        }
    });
    
    // Filter and render media
    renderMediaGrid();
    updateMediaCount();
}

function updateMediaCount() {
    const countElement = document.getElementById('media-count');
    if (!countElement) return;
    
    const totalImages = state.mediaLibrary.filter(m => m.type === 'image').length;
    const totalVideos = state.mediaLibrary.filter(m => m.type === 'video').length;
    const visibleItems = getFilteredMedia().length;
    
    let countText = '';
    if (currentMediaFilter === 'all') {
        countText = `Showing ${visibleItems} items (${totalImages} images, ${totalVideos} videos)`;
    } else if (currentMediaFilter === 'image') {
        countText = `Showing ${visibleItems} images`;
    } else if (currentMediaFilter === 'video') {
        countText = `Showing ${visibleItems} videos`;
    }
    
    countElement.textContent = countText;
}

function getFilteredMedia() {
    if (currentMediaFilter === 'all') {
        return state.mediaLibrary;
    }
    return state.mediaLibrary.filter(media => media.type === currentMediaFilter);
}

// Select media from library
function selectMedia(media) {
    const mediaId = media.video_id || media.image_id || media.id;
    
    // For cover image selection, only allow single selection
    if (state.currentSelectionType === 'cover') {
        // Only allow images for cover selection
        if (media.type !== 'image') {
            showToast('Please select an image for the cover', 'error');
            return;
        }
        
        // Single selection for cover
        state.selectedMedia = [media];
        
        // Update UI - clear all selections and select only this one
        document.querySelectorAll('.media-item').forEach(item => {
            item.classList.remove('selected');
        });
        document.querySelector(`.media-item[data-id="${mediaId}"]`)?.classList.add('selected');
    } else {
        // For primary media, single selection
        state.selectedMedia = [media];
        
        // Update UI - clear all selections and select only this one
        document.querySelectorAll('.media-item').forEach(item => {
            item.classList.remove('selected');
        });
        document.querySelector(`.media-item[data-id="${mediaId}"]`)?.classList.add('selected');
    }
    
    // Update selection counter
    updateSelectionCounter();
}

// Update selection counter in modal
function updateSelectionCounter() {
    const counter = document.getElementById('selection-counter');
    if (counter) {
        const count = state.selectedMedia.length;
        counter.textContent = count > 0 ? `${count} selected` : '';
        counter.style.display = count > 0 ? 'inline' : 'none';
    }
}

// Confirm media selection
function confirmMediaSelection() {
    if (!state.selectedMedia || state.selectedMedia.length === 0) {
        showToast('Please select media', 'error');
        return;
    }

    const adIndex = state.currentAdIndex;
    const selectionType = state.currentSelectionType;
    const selectedMedia = state.selectedMedia[0]; // Single selection

    if (selectionType === 'cover') {
        // Handle cover image selection
        if (selectedMedia.type !== 'image') {
            showToast('Please select an image for the cover', 'error');
            return;
        }

        const coverImageId = selectedMedia.image_id;
        document.getElementById(`cover-image-id-${adIndex}`).value = coverImageId;
        
        // Update cover placeholder
        const coverPlaceholder = document.getElementById(`cover-placeholder-${adIndex}`);
        const coverContainer = coverPlaceholder.parentElement;
        
        coverContainer.classList.add('has-media');
        if (selectedMedia.url) {
            coverContainer.style.backgroundImage = `url(${selectedMedia.url})`;
            coverContainer.style.backgroundSize = 'cover';
            coverContainer.style.backgroundPosition = 'center';
        }
        
        coverPlaceholder.innerHTML = `
            <div class="media-selected-info">
                <div class="media-type-badge">🖼️</div>
                <div class="media-name">${selectedMedia.file_name || 'Cover Image'}</div>
            </div>`;

        closeMediaModal();
        showToast('Cover image selected successfully', 'success');
        
    } else {
        // Handle primary media selection
        const mediaId = selectedMedia.video_id || selectedMedia.image_id;
        if (!mediaId) {
            showToast('Invalid media selection', 'error');
            return;
        }

        document.getElementById(`creative-id-${adIndex}`).value = mediaId;
        document.getElementById(`creative-type-${adIndex}`).value = selectedMedia.type;

        // Store video data for thumbnail access
        if (selectedMedia.type === 'video') {
            if (!state.selectedVideoData) {
                state.selectedVideoData = {};
            }
            state.selectedVideoData[mediaId] = selectedMedia;
        }

        // Update primary media placeholder
        const placeholder = document.getElementById(`creative-placeholder-${adIndex}`);
        const placeholderContainer = placeholder.parentElement;
        
        placeholderContainer.classList.add('has-media');
        
        // Show or hide cover image field based on media type
        const coverImageGroup = document.getElementById(`cover-image-group-${adIndex}`);
        
        if (selectedMedia.type === 'video') {
            // Show cover image field for video
            if (coverImageGroup) {
                coverImageGroup.style.display = 'block';
            }
            
            const previewUrl = selectedMedia.preview_url || selectedMedia.thumbnail_url || selectedMedia.video_cover_url;
            if (previewUrl) {
                placeholderContainer.style.backgroundImage = `url(${previewUrl})`;
                placeholderContainer.style.backgroundSize = 'cover';
            } else {
                placeholderContainer.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
            }
            
            placeholder.innerHTML = `
                <div class="media-selected-info">
                    <div class="media-type-badge">🎥</div>
                    <div class="media-name">${selectedMedia.file_name || 'Video'}</div>
                    ${selectedMedia.duration ? `<div style="font-size: 11px;">⏱ ${Math.round(selectedMedia.duration)}s</div>` : ''}
                </div>`;
                
            showToast('Video selected. Now select a cover image below.', 'info');
            
        } else {
            // Hide cover image field for image ads
            if (coverImageGroup) {
                coverImageGroup.style.display = 'none';
            }
            
            if (selectedMedia.url) {
                placeholderContainer.style.backgroundImage = `url(${selectedMedia.url})`;
                placeholderContainer.style.backgroundSize = 'cover';
            }
            
            placeholder.innerHTML = `
                <div class="media-selected-info">
                    <div class="media-type-badge">📷</div>
                    <div class="media-name">${selectedMedia.file_name || 'Image'}</div>
                </div>`;
                
            showToast('Image selected successfully', 'success');
        }

        closeMediaModal();
    }
}

// Handle media upload
async function handleMediaUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    const isVideo = file.type.startsWith('video/');
    const isImage = file.type.startsWith('image/');

    if (!isImage && !isVideo) {
        showToast('Please upload an image or video file', 'error');
        return;
    }

    // Check file size
    const maxSize = isVideo ? 500 * 1024 * 1024 : 10 * 1024 * 1024; // 500MB for video, 10MB for image
    if (file.size > maxSize) {
        showToast(`File too large. Maximum size is ${isVideo ? '500MB' : '10MB'}`, 'error');
        return;
    }

    const formData = new FormData();
    formData.append(isVideo ? 'video' : 'image', file);

    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-area').style.display = 'none';
    
    // Add upload status message
    const progressDiv = document.getElementById('upload-progress');
    progressDiv.innerHTML = `<p>Uploading ${file.name}...</p><div class="progress-bar"><div class="progress-fill" style="width: 0%"></div></div>`;

    try {
        addLog('request', `Uploading ${isVideo ? 'video' : 'image'}: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`);
        
        const response = await fetch(`api.php?action=${isVideo ? 'upload_video' : 'upload_image'}`, {
            method: 'POST',
            body: formData
        });

        // Check if response is ok first
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        // Get response text first to handle empty responses
        const responseText = await response.text();
        
        if (!responseText || responseText.trim() === '') {
            throw new Error('Empty response from server');
        }

        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response Text:', responseText);
            throw new Error('Invalid JSON response from server');
        }
        
        addLog(result.success ? 'response' : 'error', `Upload ${result.success ? 'successful' : 'failed'}`, result);

        if (result.success) {
            showToast(`${isVideo ? 'Video' : 'Image'} uploaded successfully`, 'success');

            // Reload media library to show the new upload
            await loadMediaLibrary();

            // Switch to library tab to show the uploaded file
            document.querySelector('.tab-btn[onclick*="library"]').click();

            // Reset upload form
            event.target.value = '';
        } else {
            const errorMsg = result.message || `Failed to upload ${isVideo ? 'video' : 'image'}`;
            showToast(errorMsg, 'error');
            console.error('Upload error:', result);
        }
    } catch (error) {
        addLog('error', 'Upload failed', { error: error.message });
        showToast('Error uploading file: ' + error.message, 'error');
        console.error('Upload exception:', error);
    } finally {
        document.getElementById('upload-progress').style.display = 'none';
        document.getElementById('upload-area').style.display = 'block';
        progressDiv.innerHTML = '<p>Processing...</p>';
    }
}

// Load identities
async function loadIdentities() {
    try {
        const response = await apiRequest('get_identities', {}, 'GET');
        
        console.log('Identities Response:', response);

        if (response.success && response.data && response.data.list) {
            state.identities = response.data.list;
            console.log('Loaded identities:', state.identities);
            
            // Re-populate all existing ad forms with identities
            state.ads.forEach(ad => {
                populateIdentitiesForAd(ad.index);
            });
        } else {
            console.warn('No identities found in response');
            state.identities = [];
        }
    } catch (error) {
        console.error('Error loading identities:', error);
        state.identities = [];
    }
}

// Load pixels from TikTok account
async function loadPixels() {
    const pixelSelect = document.getElementById('lead-gen-form-id');

    try {
        const response = await apiRequest('get_pixels', {}, 'GET');

        console.log('Pixels API Response:', response);

        // Clear loading state
        pixelSelect.innerHTML = '<option value="">Select a pixel...</option>';

        if (response.success && response.data) {
            // TikTok API might return pixels in different formats
            const pixels = response.data.list || response.data.pixels || [response.data];

            if (pixels && pixels.length > 0) {
                pixels.forEach(pixel => {
                    const option = document.createElement('option');
                    option.value = pixel.pixel_id;  // Use the numeric pixel_id
                    option.textContent = `${pixel.pixel_name || 'Unnamed Pixel'} (${pixel.pixel_code || pixel.pixel_id})`;
                    pixelSelect.appendChild(option);
                });
            } else {
                pixelSelect.innerHTML = '<option value="">No pixels found - Check your account</option>';
            }
        } else {
            console.error('Pixel API failed:', response);
            const errorMsg = response.message || 'No pixels found - Check your account';
            pixelSelect.innerHTML = `<option value="">Error: ${errorMsg}</option>`;
        }
    } catch (error) {
        console.error('Error loading pixels:', error);
        pixelSelect.innerHTML = '<option value="">Error loading pixels</option>';
    }
}

// Populate identities dropdown for an ad
function populateIdentitiesForAd(adIndex) {
    const select = document.getElementById(`identity-${adIndex}`);
    
    // Clear existing options except the first one
    while (select.options.length > 1) {
        select.remove(1);
    }

    if (state.identities && state.identities.length > 0) {
        state.identities.forEach(identity => {
            const option = document.createElement('option');
            option.value = identity.identity_id;
            option.setAttribute('data-identity-type', identity.identity_type || 'CUSTOMIZED_USER');
            
            // Show both identity name and display name if different
            const name = identity.identity_name || identity.display_name || 'Custom Identity';
            const displayName = identity.display_name || '';
            const typeLabel = identity.identity_type === 'TT_USER' ? ' (TikTok)' : ' (Custom)';
            
            if (displayName && displayName !== name) {
                option.textContent = `${name} (${displayName})${typeLabel}`;
            } else {
                option.textContent = name + typeLabel;
            }
            select.appendChild(option);
        });
        
        // Add "Create new custom identity" option
        const createOption = document.createElement('option');
        createOption.value = 'CREATE_NEW';
        createOption.textContent = '+ Create new custom identity';
        createOption.style.fontWeight = 'bold';
        createOption.style.color = '#667eea';
        select.appendChild(createOption);
        
        // Select first identity by default if available
        if (state.identities.length > 0 && select.options.length > 2) {
            select.selectedIndex = 1;
        }
    } else {
        // Add "Create new custom identity" option even when no identities
        const createOption = document.createElement('option');
        createOption.value = 'CREATE_NEW';
        createOption.textContent = '+ Create new custom identity';
        createOption.style.fontWeight = 'bold';
        createOption.style.color = '#667eea';
        select.appendChild(createOption);
        
        // Add helpful messages for no identities
        const option1 = document.createElement('option');
        option1.value = '';
        option1.textContent = '⚠️ No identities found';
        option1.disabled = true;
        select.appendChild(option1);
        
        const option2 = document.createElement('option');
        option2.value = '';
        option2.textContent = '→ Create one above or in TikTok Ads Manager';
        option2.disabled = true;
        select.appendChild(option2);
        
        const option3 = document.createElement('option');
        option3.value = '';
        option3.textContent = '→ Or link a TikTok account';
        option3.disabled = true;
        select.appendChild(option3);
    }
    
    // Add event listener for identity selection change
    select.onchange = function() {
        if (this.value === 'CREATE_NEW') {
            openCreateIdentityModal(adIndex);
        }
    };
}

// Create Identity Modal Functions
let currentIdentityAdIndex = null;

function openCreateIdentityModal(adIndex) {
    currentIdentityAdIndex = adIndex;
    const modal = document.getElementById('create-identity-modal');
    const input = document.getElementById('identity-display-name');
    const charCount = document.getElementById('identity-char-count');
    
    // Reset form
    input.value = '';
    charCount.textContent = '0';
    
    // Add character counter
    input.oninput = function() {
        charCount.textContent = this.value.length;
    };
    
    modal.style.display = 'block';
    input.focus();
    
    // Reset dropdown to first option to avoid confusion
    const select = document.getElementById(`identity-${adIndex}`);
    if (select.options.length > 1) {
        select.selectedIndex = 0;
    }
}

function closeCreateIdentityModal() {
    const modal = document.getElementById('create-identity-modal');
    modal.style.display = 'none';
    currentIdentityAdIndex = null;
}

async function createCustomIdentity() {
    const displayName = document.getElementById('identity-display-name').value.trim();
    const createBtn = document.getElementById('create-identity-btn');
    
    if (!displayName) {
        showToast('Please enter a display name', 'error');
        return;
    }
    
    if (displayName.length > 40) {
        showToast('Display name must be 40 characters or less', 'error');
        return;
    }
    
    createBtn.disabled = true;
    createBtn.textContent = 'Creating...';
    
    try {
        const params = {
            display_name: displayName
        };
        
        // Add image_id if an avatar was selected
        if (window.selectedAvatarImageId) {
            params.image_id = window.selectedAvatarImageId;
        }
        
        const response = await apiRequest('create_identity', params);
        
        if (response.success && response.data && response.data.identity_id) {
            showToast('Custom identity created successfully!', 'success');
            
            // Add new identity to state
            const newIdentity = {
                identity_id: response.data.identity_id,
                display_name: displayName,
                identity_name: displayName,
                identity_type: 'CUSTOMIZED_USER'
            };
            
            if (!state.identities) {
                state.identities = [];
            }
            state.identities.push(newIdentity);
            
            // Refresh the identity dropdown for the current ad
            if (currentIdentityAdIndex !== null) {
                populateIdentitiesForAd(currentIdentityAdIndex);
                
                // Select the newly created identity
                const select = document.getElementById(`identity-${currentIdentityAdIndex}`);
                const newOption = Array.from(select.options).find(option => option.value === newIdentity.identity_id);
                if (newOption) {
                    select.value = newIdentity.identity_id;
                }
            }
            
            // Refresh all other ad identity dropdowns too
            state.ads.forEach(ad => {
                if (ad.index !== currentIdentityAdIndex) {
                    populateIdentitiesForAd(ad.index);
                }
            });
            
            closeCreateIdentityModal();
        } else {
            showToast(response.message || 'Failed to create identity', 'error');
        }
    } catch (error) {
        console.error('Error creating identity:', error);
        showToast('Error creating identity: ' + error.message, 'error');
    } finally {
        createBtn.disabled = false;
        createBtn.textContent = 'Create';
    }
}

// Review ads before publishing
function reviewAds() {
    console.log('=====================================');
    console.log('Review Ads button clicked');
    console.log('Current state:', state);
    console.log('Number of ads:', state.ads.length);
    console.log('Campaign ID:', state.campaignId);
    console.log('Ad Group ID:', state.adGroupId);
    console.log('=====================================');
    
    // Check if we have campaign and ad group
    if (!state.campaignId) {
        showToast('Please create a campaign first (Step 1)', 'error');
        console.error('No campaign ID found');
        return;
    }
    
    if (!state.adGroupId) {
        showToast('Please create an ad group first (Step 2)', 'error');
        console.error('No ad group ID found');
        return;
    }
    
    // Check if we have any ads
    if (state.ads.length === 0) {
        showToast('Please add at least one ad before continuing', 'error');
        console.error('No ads found');
        return;
    }
    
    // Validate all ads
    let allValid = true;

    for (let i = 0; i < state.ads.length; i++) {
        const adIndex = state.ads[i].index;
        console.log(`Validating ad index ${adIndex}`);

        const adNameEl = document.getElementById(`ad-name-${adIndex}`);
        const adTextEl = document.getElementById(`ad-text-${adIndex}`);
        const creativeIdEl = document.getElementById(`creative-id-${adIndex}`);
        const identityEl = document.getElementById(`identity-${adIndex}`);
        const destinationUrlEl = document.getElementById(`destination-url-${adIndex}`);
        
        if (!adNameEl || !adTextEl || !creativeIdEl || !destinationUrlEl) {
            console.error(`Missing form elements for ad ${adIndex}`);
            showToast(`Error: Missing form elements for Ad #${adIndex + 1}`, 'error');
            allValid = false;
            break;
        }
        
        const adName = adNameEl.value.trim();
        const adText = adTextEl.value.trim();
        const creativeId = creativeIdEl.value;
        const creativeType = document.getElementById(`creative-type-${adIndex}`).value;
        const coverImageId = document.getElementById(`cover-image-id-${adIndex}`).value;
        const identityId = identityEl ? identityEl.value : '';
        const destinationUrl = destinationUrlEl.value.trim();

        if (!adName) {
            showToast(`Please enter ad name for Ad #${adIndex + 1}`, 'error');
            allValid = false;
            break;
        }
        if (!adText) {
            showToast(`Please enter ad copy for Ad #${adIndex + 1}`, 'error');
            allValid = false;
            break;
        }
        if (!creativeId) {
            showToast(`Please select media for Ad #${adIndex + 1}`, 'error');
            allValid = false;
            break;
        }
        // Check for cover image on video ads
        if (creativeType === 'video' && !coverImageId) {
            showToast(`Please select a cover image for video Ad #${adIndex + 1}. Cover image is required for video ads.`, 'error');
            allValid = false;
            break;
        }
        // Destination URL is optional for Lead Generation campaigns
        // Identity is REQUIRED according to TikTok API docs
        if (!identityId) {
            showToast(`Please select an identity for Ad #${adIndex + 1}. Identity is required for ad creation.`, 'error');
            allValid = false;
            break;
        }
    }

    if (!allValid) return;

    // Generate review summaries
    generateReviewSummary();

    nextStep();
}

// Generate review summary
function generateReviewSummary() {
    // Campaign summary
    const campaignSummary = document.getElementById('campaign-summary');
    const campaignBudget = document.getElementById('campaign-budget').value;
    
    campaignSummary.innerHTML = `
        <p><strong>Campaign Name:</strong> ${document.getElementById('campaign-name').value}</p>
        <p><strong>Objective:</strong> Lead Generation</p>
        <p><strong>Type:</strong> Manual Campaign</p>
        <p><strong>Campaign Budget:</strong> $${campaignBudget}/day</p>
    `;

    // Ad Group summary
    const adGroupSummary = document.getElementById('adgroup-summary');
    const startDate = new Date(document.getElementById('start-date').value);
    adGroupSummary.innerHTML = `
        <p><strong>Ad Group Name:</strong> ${document.getElementById('adgroup-name').value}</p>
        <p><strong>Daily Budget:</strong> $${document.getElementById('budget').value}</p>
        <p><strong>Start Date:</strong> ${startDate.toLocaleString()}</p>
        <p><strong>Bid Price:</strong> $${document.getElementById('bid-price').value}</p>
        <p><strong>Location:</strong> United States</p>
        <p><strong>Placement:</strong> TikTok</p>
    `;

    // Ads summary
    const adsSummary = document.getElementById('ads-summary');
    adsSummary.innerHTML = '';

    state.ads.forEach(ad => {
        const adIndex = ad.index;
        const adName = document.getElementById(`ad-name-${adIndex}`).value;
        const adText = document.getElementById(`ad-text-${adIndex}`).value;
        const cta = document.getElementById(`cta-${adIndex}`).value;
        const destinationUrl = document.getElementById(`destination-url-${adIndex}`).value;

        const adItem = document.createElement('div');
        adItem.className = 'summary-ad-item';
        adItem.innerHTML = `
            <h4>${adName}</h4>
            <p><strong>Ad Copy:</strong> ${adText.substring(0, 100)}${adText.length > 100 ? '...' : ''}</p>
            <p><strong>CTA:</strong> ${cta.replace(/_/g, ' ')}</p>
            <p><strong>URL:</strong> ${destinationUrl}</p>
        `;

        adsSummary.appendChild(adItem);
    });
}

// Publish all ads
async function publishAll() {
    console.log('=====================================');
    console.log('Publish All button clicked');
    console.log('State before publishing:', state);
    console.log('=====================================');
    
    if (!confirm('Are you sure you want to publish all ads? This cannot be undone.')) {
        console.log('User cancelled publish');
        return;
    }

    console.log('Starting ad creation process...');
    showLoading();

    try {
        const createdAdIds = [];

        // Create all ads
        for (let i = 0; i < state.ads.length; i++) {
            const adIndex = state.ads[i].index;

            const identitySelect = document.getElementById(`identity-${adIndex}`);
            const selectedIdentity = identitySelect.value;
            const selectedOption = identitySelect.options[identitySelect.selectedIndex];
            const identityType = selectedOption ? selectedOption.getAttribute('data-identity-type') : 'CUSTOMIZED_USER';
            
            const adData = {
                adgroup_id: state.adGroupId,
                ad_name: document.getElementById(`ad-name-${adIndex}`).value,
                ad_text: document.getElementById(`ad-text-${adIndex}`).value,
                call_to_action: document.getElementById(`cta-${adIndex}`).value,
                landing_page_url: document.getElementById(`destination-url-${adIndex}`).value,
                identity_id: selectedIdentity,
                identity_type: identityType || 'CUSTOMIZED_USER',
                promotion_type: 'WEBSITE'  // Using WEBSITE for Lead Gen campaigns with landing pages
            };

            const creativeType = document.getElementById(`creative-type-${adIndex}`).value;
            const creativeId = document.getElementById(`creative-id-${adIndex}`).value;
            const coverImageId = document.getElementById(`cover-image-id-${adIndex}`)?.value;

            if (creativeType === 'video') {
                adData.video_id = creativeId;
                adData.ad_format = 'SINGLE_VIDEO';
                
                // For video ads, image_ids is REQUIRED for the video cover
                if (coverImageId) {
                    adData.image_ids = [coverImageId];
                    console.log(`Using cover image_id for video ad: ${coverImageId}`);
                } else {
                    console.warn('No cover image_id for video ad - this may cause the ad creation to fail');
                    // Try to use a default or placeholder image_id if available
                    adData.image_ids = []; // This will likely fail, but TikTok will provide error details
                }
            } else {
                adData.image_ids = [creativeId];
                adData.ad_format = 'SINGLE_IMAGE';
            }

            console.log(`Creating ad ${i+1}/${state.ads.length}:`, adData);
            const response = await apiRequest('create_ad', adData);
            console.log(`Ad creation response:`, response);

            if (response.success && response.data && response.data.ad_ids && response.data.ad_ids.length > 0) {
                createdAdIds.push(...response.data.ad_ids);
                showToast(`Ad ${i+1} created successfully`, 'success');
            } else {
                console.error('Ad creation failed:', response);
                throw new Error(`Failed to create ad ${i+1}: ${response.message || 'Unknown error'}`);
            }
        }

        // Ads are created with ENABLE status by default, so they're already published
        if (createdAdIds.length > 0) {
            showToast('All ads created and published successfully! 🎉', 'success');
            
            // Log success
            console.log(`Successfully created ${createdAdIds.length} ads:`, createdAdIds);
            
            // Optional: Try to explicitly enable ads (but continue even if this fails)
            try {
                const publishResponse = await apiRequest('publish_ads', {
                    ad_ids: createdAdIds
                });
                
                if (!publishResponse.success) {
                    console.log('Note: Ads are already enabled by default. Status update not required.');
                }
            } catch (e) {
                // This is not critical - ads are already enabled by default
                console.log('Status update skipped - ads are enabled by default');
            }

            // Show success modal
            setTimeout(() => {
                showSuccessModal();
            }, 1500);
        }
    } catch (error) {
        showToast('Error publishing ads: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// API request helper
async function apiRequest(action, data = {}, method = 'POST') {
    const url = `api.php?action=${action}`;

    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json'
        }
    };

    if (method === 'POST') {
        options.body = JSON.stringify(data);
    }

    // Log the request
    addLog('request', `${method} ${action}`, method === 'POST' ? data : null);

    try {
        const response = await fetch(url, options);

        if (!response.ok) {
            addLog('error', `HTTP ${response.status} error for ${action}`);
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const jsonResponse = await response.json();

        // Log the response
        if (jsonResponse.success === false) {
            addLog('error', `API Error: ${action}`, jsonResponse);
        } else {
            addLog('response', `${action} completed`, jsonResponse);
        }

        return jsonResponse;
    } catch (error) {
        addLog('error', `Request failed: ${error.message}`, { action, error: error.message });
        throw error;
    }
}

// Logout
async function logout() {
    if (confirm('Are you sure you want to logout?')) {
        await apiRequest('logout');
        window.location.href = 'index.php';
    }
}

// Show loading overlay
function showLoading() {
    document.getElementById('loading-overlay').classList.add('show');
}

// Hide loading overlay
function hideLoading() {
    document.getElementById('loading-overlay').classList.remove('show');
}

// Show toast notification
function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast show ${type}`;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// Toggle between pixel dropdown and manual input
function togglePixelInput() {
    const pixelMethod = document.querySelector('input[name="pixel-method"]:checked')?.value;
    const dropdownContainer = document.getElementById('pixel-dropdown-container');
    const manualContainer = document.getElementById('pixel-manual-container');

    console.log('Toggle pixel input - Method:', pixelMethod);

    if (!dropdownContainer || !manualContainer) {
        console.error('Pixel containers not found');
        return;
    }

    if (pixelMethod === 'manual') {
        dropdownContainer.style.display = 'none';
        manualContainer.style.display = 'block';
        console.log('Showing manual input');
    } else {
        dropdownContainer.style.display = 'block';
        manualContainer.style.display = 'none';
        console.log('Showing dropdown');
    }
}

// Show success modal with thank you message
function showSuccessModal() {
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
                <h2 style="color: #10b981; margin-bottom: 10px; font-size: 32px;">Thank You!</h2>
                <p style="color: #333; margin-bottom: 20px; font-size: 18px; font-weight: 500;">
                    Campaign Launched Successfully!
                </p>
                <p style="color: #666; margin-bottom: 30px; font-size: 14px;">
                    Your TikTok ad campaign has been created and is now live. It may take a few minutes for the campaign to appear in your TikTok Ads Manager.
                </p>
                <p style="color: #666; margin-bottom: 30px; font-size: 14px; font-weight: 600;">
                    Would you like to create another campaign?
                </p>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button onclick="createNewCampaign()" style="
                        background: #1a1a1a;
                        color: white;
                        border: 2px solid #1a1a1a;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                        box-shadow: 0 4px 15px rgba(26, 26, 26, 0.3);
                        transition: all 0.3s;
                    " onmouseover="this.style.background='#2d2d2d'; this.style.transform='translateY(-2px)'" onmouseout="this.style.background='#1a1a1a'; this.style.transform='translateY(0)'">
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
                        No, Go to Home
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
    window.location.href = 'select-advertiser.php';
}

// Age targeting functions
function selectAllAges() {
    const checkboxes = document.querySelectorAll('.age-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
}

function clearAllAges() {
    const checkboxes = document.querySelectorAll('.age-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
}

function selectDefaultAges() {
    // Clear all first
    clearAllAges();
    
    // Select default ages (18+ excluding 13-17)
    const defaultAges = ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'];
    defaultAges.forEach(age => {
        const checkbox = document.querySelector(`.age-checkbox[value="${age}"]`);
        if (checkbox) {
            checkbox.checked = true;
        }
    });
}

function getSelectedAgeGroups() {
    const selectedAges = [];
    const checkboxes = document.querySelectorAll('.age-checkbox:checked');
    
    checkboxes.forEach(checkbox => {
        selectedAges.push(checkbox.value);
    });
    
    return selectedAges;
}

// Location targeting functions

// US States data with location IDs
const US_STATES = [
    { name: 'Alabama', id: '4829764' },
    { name: 'Alaska', id: '5879092' },
    { name: 'Arizona', id: '5551752' },
    { name: 'Arkansas', id: '4099753' },
    { name: 'California', id: '5332921' },
    { name: 'Colorado', id: '5417618' },
    { name: 'Connecticut', id: '4831725' },
    { name: 'Delaware', id: '4142224' },
    { name: 'Florida', id: '4155751' },
    { name: 'Georgia', id: '4197000' },
    { name: 'Hawaii', id: '5855797' },
    { name: 'Idaho', id: '5596512' },
    { name: 'Illinois', id: '4896861' },
    { name: 'Indiana', id: '4921868' },
    { name: 'Iowa', id: '4862182' },
    { name: 'Kansas', id: '4273857' },
    { name: 'Kentucky', id: '6254925' },
    { name: 'Louisiana', id: '4331987' },
    { name: 'Maine', id: '4971068' },
    { name: 'Maryland', id: '4361885' },
    { name: 'Massachusetts', id: '6254926' },
    { name: 'Michigan', id: '5001836' },
    { name: 'Minnesota', id: '5037779' },
    { name: 'Mississippi', id: '4436296' },
    { name: 'Missouri', id: '4398678' },
    { name: 'Montana', id: '5667009' },
    { name: 'Nebraska', id: '5073708' },
    { name: 'Nevada', id: '5509151' },
    { name: 'New Hampshire', id: '5090174' },
    { name: 'New Jersey', id: '5101760' },
    { name: 'New Mexico', id: '5481136' },
    { name: 'New York', id: '5128638' },
    { name: 'North Carolina', id: '4482348' },
    { name: 'North Dakota', id: '5690763' },
    { name: 'Ohio', id: '5165418' },
    { name: 'Oklahoma', id: '4544379' },
    { name: 'Oregon', id: '5744337' },
    { name: 'Pennsylvania', id: '6254927' },
    { name: 'Rhode Island', id: '5224323' },
    { name: 'South Carolina', id: '4597040' },
    { name: 'South Dakota', id: '5769223' },
    { name: 'Tennessee', id: '4662168' },
    { name: 'Texas', id: '4736286' },
    { name: 'Utah', id: '5549030' },
    { name: 'Vermont', id: '5242283' },
    { name: 'Virginia', id: '6254928' },
    { name: 'Washington', id: '5815135' },
    { name: 'West Virginia', id: '4826850' },
    { name: 'Wisconsin', id: '5279468' },
    { name: 'Wyoming', id: '5843591' },
    { name: 'District of Columbia', id: '4138106' }
];

const POPULAR_STATES = ['California', 'Texas', 'New York', 'Florida', 'Illinois', 'Pennsylvania', 'Ohio', 'Georgia', 'North Carolina', 'Michigan'];

function toggleLocationMethod() {
    const methodElement = document.querySelector('input[name="location_method"]:checked');
    if (!methodElement) {
        console.error('No location method radio button found');
        return;
    }
    
    const method = methodElement.value;
    const countryOption = document.getElementById('country-targeting');
    const statesOption = document.getElementById('states-targeting');
    
    if (!countryOption || !statesOption) {
        console.error('Location targeting elements not found');
        return;
    }
    
    console.log('Toggling location method to:', method);
    
    if (method === 'country') {
        countryOption.style.display = 'block';
        countryOption.classList.add('active');
        statesOption.style.display = 'none';
        statesOption.classList.remove('active');
    } else if (method === 'states') {
        countryOption.style.display = 'none';
        countryOption.classList.remove('active');
        statesOption.style.display = 'block';
        statesOption.classList.add('active');
        
        // Initialize states grid if not already done
        populateStatesGrid();
    }
}








// Initialize location targeting on page load
function initializeLocationTargeting() {
    // Ensure the default country option is displayed
    const countryRadio = document.querySelector('input[name="location_method"][value="country"]');
    if (countryRadio) {
        countryRadio.checked = true;
        toggleLocationMethod();
        console.log('Location targeting initialized - default to country');
    }
    
    // Auto-populate states grid when states option is available
    const statesRadio = document.querySelector('input[name="location_method"][value="states"]');
    if (statesRadio) {
        // Pre-populate the grid so it's ready when user switches to states
        populateStatesGrid();
    }
}

function getSelectedLocationIds() {
    const methodElement = document.querySelector('input[name="location_method"]:checked');
    
    // If no radio button is found or checked, default to country
    if (!methodElement) {
        console.log('No location method selected, defaulting to country');
        return ['6252001']; // Default to United States
    }
    
    const method = methodElement.value;
    console.log('Selected location method:', method);
    
    if (method === 'country') {
        return ['6252001']; // United States
    } else if (method === 'states') {
        // Get selected state checkboxes
        const selectedCheckboxes = document.querySelectorAll('input[name="state_selection"]:checked');
        
        if (selectedCheckboxes.length === 0) {
            console.log('States method selected but no states selected');
            return null; // Error case - will be handled by validation
        }
        
        const locations = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
        console.log('Returning selected state location IDs:', locations);
        return locations;
    }
    
    return ['6252001']; // Fallback to US
}

// State selection functions
function populateStatesGrid() {
    const statesGrid = document.getElementById('states-grid');
    if (!statesGrid) {
        console.error('States grid element not found');
        return;
    }
    
    // Only populate if not already done
    if (statesGrid.children.length > 0) {
        return;
    }
    
    console.log('Populating states grid with', US_STATES.length, 'states');
    
    US_STATES.forEach(state => {
        const stateItem = document.createElement('div');
        stateItem.className = 'state-item';
        
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'state_selection';
        checkbox.value = state.id;
        checkbox.id = `state_${state.id}`;
        checkbox.checked = true; // All states selected by default
        checkbox.addEventListener('change', updateStatesCount);
        
        const label = document.createElement('label');
        label.htmlFor = `state_${state.id}`;
        label.textContent = state.name;
        
        stateItem.appendChild(checkbox);
        stateItem.appendChild(label);
        statesGrid.appendChild(stateItem);
    });
    
    updateStatesCount();
}

function selectAllStates() {
    const checkboxes = document.querySelectorAll('input[name="state_selection"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    updateStatesCount();
    console.log('All states selected');
}

function clearAllStates() {
    const checkboxes = document.querySelectorAll('input[name="state_selection"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateStatesCount();
    console.log('All states cleared');
}

function selectPopularStates() {
    // First clear all
    clearAllStates();
    
    // Then select popular states
    POPULAR_STATES.forEach(stateName => {
        const state = US_STATES.find(s => s.name === stateName);
        if (state) {
            const checkbox = document.getElementById(`state_${state.id}`);
            if (checkbox) {
                checkbox.checked = true;
            }
        }
    });
    updateStatesCount();
    console.log('Popular states selected');
}

function updateStatesCount() {
    const selectedCheckboxes = document.querySelectorAll('input[name="state_selection"]:checked');
    const countElement = document.getElementById('selected-states-count');
    if (countElement) {
        countElement.textContent = selectedCheckboxes.length;
    }
    console.log('States count updated:', selectedCheckboxes.length);
}

// Avatar Selection Functions
let selectedAvatarImageId = null;

function selectIdentityAvatar() {
    const modal = document.getElementById('avatar-selection-modal');
    modal.style.display = 'block';
    
    // Load TikTok library images
    loadAvatarLibrary();
}

function closeAvatarSelectionModal() {
    const modal = document.getElementById('avatar-selection-modal');
    modal.style.display = 'none';
    selectedAvatarImageId = null;
}

function switchAvatarTab(tab, event) {
    // Remove active class from all tabs
    document.querySelectorAll('#avatar-selection-modal .tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Hide all tab contents
    document.querySelectorAll('#avatar-selection-modal .media-tab').forEach(content => {
        content.style.display = 'none';
    });
    
    // Activate clicked tab
    event.target.classList.add('active');
    
    // Show corresponding content
    if (tab === 'library') {
        document.getElementById('avatar-library-tab').style.display = 'block';
    } else if (tab === 'upload') {
        document.getElementById('avatar-upload-tab').style.display = 'block';
    }
}

async function loadAvatarLibrary() {
    try {
        const response = await apiRequest('get_images', {}, 'GET');
        const grid = document.getElementById('avatar-library-grid');
        grid.innerHTML = '';
        
        if (response.success && response.data && response.data.list && response.data.list.length > 0) {
            // Show all images for avatar selection (will be auto-cropped if not square)
            response.data.list.forEach(image => {
                    const item = document.createElement('div');
                    item.className = 'media-item avatar-item';
                    item.style.cursor = 'pointer';
                    item.onclick = () => selectAvatarImage(image);
                    
                    // Create image preview
                    const imgUrl = image.url || image.image_url || image.preview_url || image.thumbnail_url;
                    if (imgUrl && imgUrl !== '') {
                        item.innerHTML = `
                            <div style="position: relative; width: 100%; height: 120px;">
                                <img src="${imgUrl}" alt="${image.file_name || 'Image'}" 
                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;"
                                     onload="console.log('Avatar image loaded:', '${imgUrl}')"
                                     onerror="this.parentNode.innerHTML='<div style=&quot;width: 100%; height: 100%; background: linear-gradient(135deg, #4fc3f7, #29b6f6); display: flex; flex-direction: column; align-items: center; justify-content: center; color: white;&quot;><div style=&quot;font-size: 30px; margin-bottom: 5px;&quot;>🖼️</div><div style=&quot;font-size: 10px; text-align: center; padding: 0 5px;&quot;>${image.file_name || 'Image'}</div></div>'">
                                <div style="position: absolute; bottom: 2px; left: 2px; background: rgba(0,0,0,0.7); color: white; padding: 2px 4px; font-size: 10px; border-radius: 2px;">
                                    ${image.width}x${image.height}
                                </div>
                            </div>
                        `;
                    } else {
                        item.innerHTML = `
                            <div style="position: relative; width: 100%; height: 120px;">
                                <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #4fc3f7, #29b6f6); 
                                            display: flex; flex-direction: column; align-items: center; justify-content: center; color: white;">
                                    <div style="font-size: 30px; margin-bottom: 5px;">🖼️</div>
                                    <div style="font-size: 10px; text-align: center; padding: 0 5px;">${image.file_name || 'Image'}</div>
                                </div>
                            </div>
                        `;
                    }
                    
                    grid.appendChild(item);
                });
        } else {
            grid.innerHTML = '<p style="text-align: center; color: #999; padding: 20px;">No images in TikTok library. Upload some images first or sync from TikTok.</p>';
        }
    } catch (error) {
        console.error('Error loading avatar library:', error);
        document.getElementById('avatar-library-grid').innerHTML = '<p style="text-align: center; color: #f00; padding: 20px;">Error loading images.</p>';
    }
}

async function selectAvatarImage(image) {
    // Remove selection from other items
    document.querySelectorAll('.avatar-item').forEach(item => {
        item.style.border = 'none';
        item.style.boxShadow = 'none';
    });
    
    // Highlight selected item
    event.target.closest('.avatar-item').style.border = '3px solid #667eea';
    event.target.closest('.avatar-item').style.boxShadow = '0 0 10px rgba(102, 126, 234, 0.3)';
    
    console.log('🖼️ Image selected:', image.image_id, 'Dimensions:', image.width + 'x' + image.height);
    
    // Check if image needs cropping for avatar use
    if (image.width && image.height && image.width !== image.height) {
        showToast(`Creating square version of ${image.width}x${image.height} image...`, 'info');
        console.log('🔄 Non-square image detected, creating cropped version...');
        
        try {
            // Auto-crop and upload the image
            const cropResponse = await apiRequest('auto_crop_and_upload', {
                image_id: image.image_id,
                image_url: image.image_url || image.url || image.preview_url || image.thumbnail_url,
                file_name: image.file_name || 'avatar_image'
            });
            
            if (cropResponse.success) {
                const dimensions = `${cropResponse.width}x${cropResponse.height}`;
                showToast(`✅ Square version (${dimensions}) created and uploaded to TikTok!`, 'success');
                console.log('✅ Cropped image uploaded:', cropResponse.image_id, 'Dimensions:', dimensions);
                
                // Use the cropped image ID
                selectedAvatarImageId = cropResponse.image_id;
                
                // Reload the avatar library to show the new cropped image
                setTimeout(() => {
                    loadAvatarLibrary();
                }, 1500);
                
                // Enable confirm button
                document.getElementById('confirm-avatar-btn').disabled = false;
            } else {
                throw new Error(cropResponse.message || 'Failed to create cropped version');
            }
        } catch (error) {
            console.error('❌ Error creating cropped version:', error);
            showToast('Failed to create square version. You can still use the original image.', 'warning');
            
            // Allow user to continue with original image
            selectedAvatarImageId = image.image_id;
            document.getElementById('confirm-avatar-btn').disabled = false;
        }
    } else if (image.width && image.height) {
        showToast(`Perfect! Selected square image (${image.width}x${image.height}) ready for avatar use`, 'success');
        selectedAvatarImageId = image.image_id;
        console.log('✅ Square image ready. ID:', image.image_id);
        document.getElementById('confirm-avatar-btn').disabled = false;
    } else {
        // Unknown dimensions, assume it's safe to use
        console.log('⚠️ Unknown dimensions, using original image ID:', image.image_id);
        selectedAvatarImageId = image.image_id;
        document.getElementById('confirm-avatar-btn').disabled = false;
    }
}

function handleAvatarUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    // Validate file type
    if (!file.type.startsWith('image/')) {
        showToast('Please select an image file', 'error');
        return;
    }
    
    // Automatically crop image to square for avatar use
    const img = new Image();
    const reader = new FileReader();
    
    reader.onload = function(e) {
        img.onload = function() {
            // Create canvas to crop image to square
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // Determine the crop size (use the smaller dimension)
            const cropSize = Math.min(img.width, img.height);
            canvas.width = cropSize;
            canvas.height = cropSize;
            
            // Calculate crop position (center crop)
            const offsetX = (img.width - cropSize) / 2;
            const offsetY = (img.height - cropSize) / 2;
            
            // Draw the cropped image
            ctx.drawImage(img, offsetX, offsetY, cropSize, cropSize, 0, 0, cropSize, cropSize);
            
            // Convert canvas to blob and show preview
            canvas.toBlob(function(blob) {
                const croppedUrl = URL.createObjectURL(blob);
                document.getElementById('avatar-preview-img').src = croppedUrl;
                document.getElementById('avatar-upload-preview').style.display = 'block';
                
                // Store the cropped blob for upload
                window.croppedAvatarBlob = blob;
                
                if (img.width !== img.height) {
                    showToast(`Image automatically cropped to square (${cropSize}x${cropSize}) for avatar use`, 'info');
                } else {
                    showToast('Avatar image ready for upload', 'success');
                }
            }, file.type, 0.9);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

async function uploadAvatarImage() {
    const fileInput = document.getElementById('avatar-file-input');
    const originalFile = fileInput.files[0];
    
    if (!originalFile) {
        showToast('Please select an image file', 'error');
        return;
    }
    
    // Use cropped blob if available, otherwise use original file
    const fileToUpload = window.croppedAvatarBlob || originalFile;
    const fileName = originalFile.name.replace(/\.[^/.]+$/, '') + '_avatar.jpg'; // Add avatar suffix
    
    try {
        showToast('Uploading avatar image...', 'info');
        
        const formData = new FormData();
        formData.append('image', fileToUpload, fileName);
        formData.append('action', 'upload_image');
        
        const response = await fetch('api.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.data && result.data.image_id) {
            selectedAvatarImageId = result.data.image_id;
            showToast('Avatar image uploaded successfully', 'success');
            
            // Enable confirm button
            document.getElementById('confirm-avatar-btn').disabled = false;
        } else {
            showToast('Failed to upload avatar image: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Error uploading avatar:', error);
        showToast('Error uploading avatar image', 'error');
    }
}

function confirmAvatarSelection() {
    if (!selectedAvatarImageId) {
        showToast('Please select or upload an avatar image', 'error');
        return;
    }
    
    // Update the preview in the identity modal
    const previewImg = document.getElementById('identity-avatar-img');
    if (previewImg) {
        // For now, just show a placeholder since we have the image_id
        previewImg.style.display = 'none';
        previewImg.parentElement.innerHTML = `
            <div style="background: linear-gradient(135deg, #667eea, #764ba2); width: 100%; height: 100%; 
                        display: flex; align-items: center; justify-content: center; color: white; 
                        font-size: 24px; font-weight: bold; border-radius: 50%;">✓</div>
        `;
    }
    
    // Store the selected image ID for identity creation
    window.selectedAvatarImageId = selectedAvatarImageId;
    
    closeAvatarSelectionModal();
    showToast('Avatar image selected', 'success');
}
