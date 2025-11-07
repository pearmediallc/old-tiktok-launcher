// Smart+ Campaign JavaScript

// State management for Smart+ Campaign
const smartState = {
    currentStep: 1,
    campaignId: null,
    adGroupId: null,
    ads: [],
    selectedAdvertiserId: null,
    mediaLibrary: [],
    currentAdIndex: null,
    currentMediaSelection: []
};

// Initialize on page load
window.addEventListener('DOMContentLoaded', async () => {
    console.log('=== Smart+ Campaign Initialization ===');
    
    try {
        await loadAdvertiserInfo();
        await loadLeadGenForms();
        initializeSmartLocationTargeting();
        initializeSmartAd();
        
        // Set default start date to tomorrow (exactly like manual campaign)
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(0, 0, 0, 0);
        
        console.log('Setting default date to tomorrow:', tomorrow);
        
        // Campaign start date
        if (document.getElementById('campaign-start-date')) {
            document.getElementById('campaign-start-date').value = formatDateTimeLocal(tomorrow);
            console.log('Campaign start date set');
        } else {
            console.error('Campaign start date input not found!');
        }
        
        // Campaign end date (optional)
        if (document.getElementById('campaign-end-date')) {
            console.log('Campaign end date input found');
        } else {
            console.error('Campaign end date input not found!');
        }
    } catch (error) {
        console.error('Initialization error:', error);
    }
});

// Format date for datetime-local input (copied from manual campaign)
function formatDateTimeLocal(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Load advertiser info
async function loadAdvertiserInfo() {
    try {
        console.log('Loading advertiser info for Smart+ Campaign...');
        
        // Get the selected advertiser info from the session via API
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_selected_advertiser' })
        });
        
        const data = await response.json();
        console.log('Selected advertiser response:', data);
        
        if (data.success && data.advertiser) {
            const advertiser = data.advertiser;
            console.log('Using selected advertiser:', advertiser);
            
            document.getElementById('advertiser-name').textContent = advertiser.advertiser_name || 'Selected Advertiser';
            smartState.selectedAdvertiserId = advertiser.advertiser_id;
            
            // Store for later use
            localStorage.setItem('advertiser_name', advertiser.advertiser_name || 'Selected Advertiser');
            localStorage.setItem('advertiser_id', advertiser.advertiser_id);
            
            console.log('Smart+ Campaign will use advertiser:', advertiser.advertiser_id, '(' + advertiser.advertiser_name + ')');
        } else {
            console.error('No selected advertiser found, redirecting to selection page');
            window.location.href = 'select-advertiser.php';
        }
    } catch (error) {
        console.error('Error loading advertiser info:', error);
        // Fallback to advertiser selection
        window.location.href = 'select-advertiser.php';
    }
}

// Load lead generation forms
async function loadLeadGenForms() {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_lead_forms' })
        });
        
        const data = await response.json();
        
        const select = document.getElementById('lead-gen-form-id');
        select.innerHTML = '<option value="">Select a lead form</option>';
        
        if (data.success && data.data && data.data.list) {
            data.data.list.forEach(form => {
                const option = document.createElement('option');
                option.value = form.page_id;
                option.textContent = form.page_name || 'Unnamed Form';
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading lead forms:', error);
    }
}

// Toggle pixel method
function togglePixelMethod() {
    const manualInput = document.getElementById('pixel-manual-input');
    const radioManual = document.querySelector('input[name="pixel-method"][value="manual"]');
    
    if (radioManual && radioManual.checked) {
        manualInput.style.display = 'block';
    } else {
        manualInput.style.display = 'none';
    }
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

// Step navigation
function nextStep() {
    if (smartState.currentStep < 4) {
        document.querySelector(`.step[data-step="${smartState.currentStep}"]`).classList.remove('active');
        document.querySelector(`.step[data-step="${smartState.currentStep}"]`).classList.add('completed');
        document.getElementById(`step-${smartState.currentStep}`).classList.remove('active');

        smartState.currentStep++;

        document.querySelector(`.step[data-step="${smartState.currentStep}"]`).classList.add('active');
        document.getElementById(`step-${smartState.currentStep}`).classList.add('active');

        window.scrollTo(0, 0);
    }
}

function prevStep() {
    if (smartState.currentStep > 1) {
        document.querySelector(`.step[data-step="${smartState.currentStep}"]`).classList.remove('active');
        document.getElementById(`step-${smartState.currentStep}`).classList.remove('active');

        smartState.currentStep--;

        document.querySelector(`.step[data-step="${smartState.currentStep}"]`).classList.remove('completed');
        document.querySelector(`.step[data-step="${smartState.currentStep}"]`).classList.add('active');
        document.getElementById(`step-${smartState.currentStep}`).classList.add('active');

        window.scrollTo(0, 0);
    }
}

// Create Smart+ Campaign
async function createSmartCampaign() {
    const campaignName = document.getElementById('campaign-name').value.trim();
    const startDate = document.getElementById('campaign-start-date').value;
    const endDate = document.getElementById('campaign-end-date').value;
    
    console.log('=== SMART+ CAMPAIGN CREATION ===');
    console.log('Campaign Name:', campaignName);
    console.log('Start Date Input:', startDate);
    console.log('End Date Input:', endDate);
    
    // Get Smart+ features
    const autoTargeting = document.getElementById('auto-targeting').checked;
    const autoPlacement = document.getElementById('auto-placement').checked;
    const creativeOptimization = document.getElementById('creative-optimization').checked;
    
    // Get CBO settings
    const cboEnabled = document.getElementById('cbo-enabled').checked;
    const campaignBudget = parseFloat(document.getElementById('campaign-budget').value) || 50;
    
    console.log('Smart Features:', { autoTargeting, autoPlacement, creativeOptimization });
    console.log('CBO Settings:', { cboEnabled, campaignBudget });

    // Validate
    if (!campaignName) {
        showToast('Please enter campaign name', 'error');
        return;
    }

    showLoading();

    try {
        const params = {
            campaign_name: campaignName,
            smart_features: {
                auto_targeting: autoTargeting,
                auto_placement: autoPlacement,
                creative_optimization: creativeOptimization
            },
            cbo_enabled: cboEnabled,
            campaign_budget: cboEnabled ? campaignBudget : null
        };

        // Add schedule times if provided
        if (startDate) {
            const startDateTime = new Date(startDate);
            params.schedule_start_time = formatToTikTokDateTime(startDateTime);
            console.log('Formatted Start Time:', params.schedule_start_time);
        }

        if (endDate) {
            const endDateTime = new Date(endDate);
            params.schedule_end_time = formatToTikTokDateTime(endDateTime);
            console.log('Formatted End Time:', params.schedule_end_time);
        }
        
        console.log('API Request Params:', JSON.stringify(params, null, 2));

        const response = await apiRequest('create_smart_campaign', params);
        
        console.log('API Response:', response);

        if (response.success && response.data && response.data.campaign_id) {
            smartState.campaignId = response.data.campaign_id;
            document.getElementById('display-campaign-id').textContent = smartState.campaignId;
            showToast('Smart+ Campaign created successfully', 'success');
            nextStep();
        } else {
            console.error('Campaign creation failed:', response);
            showToast(response.message || response.error || 'Failed to create Smart+ campaign', 'error');
        }
    } catch (error) {
        console.error('Error creating campaign:', error);
        showToast('Error creating campaign: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Create Smart+ Ad Group
async function createSmartAdGroup() {
    const adGroupName = document.getElementById('adgroup-name').value.trim();
    const pixelMethod = document.querySelector('input[name="pixel-method"]:checked')?.value || 'dropdown';
    const pixelId = pixelMethod === 'manual'
        ? document.getElementById('pixel-manual-input').value.trim()
        : document.getElementById('lead-gen-form-id').value.trim();
    const budget = parseFloat(document.getElementById('budget').value);
    const bidPrice = parseFloat(document.getElementById('bid-price').value);

    // Validate age group selection
    const selectedAgeGroups = getSmartSelectedAgeGroups();
    if (selectedAgeGroups.length === 0) {
        showToast('Please select at least one age group for targeting', 'error');
        return;
    }

    // Validate location targeting
    const selectedLocationIds = getSmartSelectedLocationIds();
    console.log('Smart+ Validation - selectedLocationIds:', selectedLocationIds);
    
    if (!selectedLocationIds || selectedLocationIds.length === 0) {
        showToast('Please select locations for Smart+ targeting or upload a location file', 'error');
        return;
    }

    if (!adGroupName || !pixelId || !budget) {
        showToast('Please fill in all required fields', 'error');
        return;
    }

    if (budget < 50) {
        showToast('Smart+ Ad Group budget must be at least $50', 'error');
        return;
    }

    showLoading();

    try {
        const scheduleStartTime = formatToTikTokDateTime(new Date());

        const params = {
            campaign_id: smartState.campaignId,
            adgroup_name: adGroupName,
            promotion_type: 'LEAD_GENERATION',
            promotion_target_type: 'EXTERNAL_WEBSITE',
            pixel_id: pixelId,
            optimization_goal: 'CONVERT',
            optimization_event: 'FORM',
            billing_event: 'OCPM',
            
            // Smart+ specific settings
            smart_optimization: true,
            auto_targeting: true,
            placement_type: 'PLACEMENT_TYPE_AUTOMATIC', // Auto placement for Smart+
            
            // Demographics - Smart+ will optimize these
            location_ids: selectedLocationIds, // User-selected locations
            age_groups: selectedAgeGroups, // User-selected age groups
            gender: 'GENDER_UNLIMITED',
            
            // Budget
            budget_mode: 'BUDGET_MODE_DAY',
            budget: budget,
            schedule_type: 'SCHEDULE_FROM_NOW',
            schedule_start_time: scheduleStartTime,
            
            // Pacing
            pacing: 'PACING_MODE_SMOOTH'
        };

        // Set bidding type based on whether bid price is provided
        if (bidPrice && bidPrice > 0) {
            params.bid_type = 'BID_TYPE_CUSTOM';
            params.conversion_bid_price = bidPrice;
        } else {
            params.bid_type = 'BID_TYPE_NO_BID';  // Let TikTok Smart+ optimize automatically
        }

        const response = await apiRequest('create_smart_adgroup', params);

        if (response.success && response.data && response.data.adgroup_id) {
            smartState.adGroupId = response.data.adgroup_id;
            showToast('Smart+ Ad Group created successfully', 'success');
            nextStep();
        } else {
            showToast(response.message || 'Failed to create ad group', 'error');
        }
    } catch (error) {
        showToast('Error creating ad group: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Initialize Smart+ Ad
function initializeSmartAd() {
    const container = document.getElementById('ads-container');
    const adIndex = smartState.ads.length + 1;
    
    const adCard = document.createElement('div');
    adCard.className = 'smart-ad-card';
    adCard.id = `ad-card-${adIndex}`;
    adCard.innerHTML = `
        <div class="smart-ad-header">
            <h3>
                <span class="badge-smart">Smart+</span>
                Creative Set ${adIndex}
            </h3>
            ${adIndex > 1 ? `<button class="remove-ad-btn" onclick="removeSmartAd(${adIndex})">Remove</button>` : ''}
        </div>
        
        <div class="form-group">
            <label>Ad Name</label>
            <input type="text" id="ad-name-${adIndex}" placeholder="Enter ad name" required>
        </div>
        
        <div class="form-section">
            <h4>Creative Assets (Add Multiple for AI Optimization)</h4>
            <div class="creative-assets-grid" id="creative-grid-${adIndex}">
                <button class="add-creative-btn" onclick="openMediaModal(${adIndex}, true)">
                    + Add Videos/Images<br>
                    <small>Up to 10 assets</small>
                </button>
            </div>
        </div>
        
        <div class="form-section">
            <h4>Ad Texts (Multiple variations for testing)</h4>
            <div class="creative-texts-container" id="texts-container-${adIndex}">
                <div class="creative-text-item">
                    <label>Primary Text 1</label>
                    <textarea id="ad-text-${adIndex}-1" placeholder="Enter your ad text" rows="3" required></textarea>
                </div>
            </div>
            <button class="btn-secondary" onclick="addTextVariation(${adIndex})">+ Add Text Variation</button>
        </div>
        
        <div class="form-section">
            <h4>Identity & CTA</h4>
            <div class="identity-per-creative">
                <div class="form-group">
                    <label>Identity</label>
                    <select id="identity-${adIndex}" required>
                        <option value="">Loading identities...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Call to Action</label>
                    <select id="cta-${adIndex}" required>
                        <option value="LEARN_MORE">Learn More</option>
                        <option value="SIGN_UP">Sign Up</option>
                        <option value="GET_QUOTE">Get Quote</option>
                        <option value="APPLY_NOW">Apply Now</option>
                        <option value="CONTACT_US">Contact Us</option>
                        <option value="DOWNLOAD">Download</option>
                        <option value="SUBSCRIBE">Subscribe</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label>Destination URL</label>
            <input type="url" id="destination-url-${adIndex}" placeholder="https://example.com" required>
        </div>
        
        <input type="hidden" id="media-${adIndex}" value="">
    `;
    
    container.appendChild(adCard);
    
    // Add to state
    smartState.ads.push({
        index: adIndex,
        media: [],
        texts: [1],
        textCount: 1
    });
    
    // Load identities
    loadIdentitiesForAd(adIndex);
}

// Add Smart+ Ad
function addSmartAd() {
    initializeSmartAd();
}

// Remove Smart+ Ad
function removeSmartAd(adIndex) {
    const adCard = document.getElementById(`ad-card-${adIndex}`);
    if (adCard) {
        adCard.remove();
    }
    
    // Remove from state
    smartState.ads = smartState.ads.filter(ad => ad.index !== adIndex);
}

// Add text variation
function addTextVariation(adIndex) {
    const ad = smartState.ads.find(a => a.index === adIndex);
    if (!ad) return;
    
    ad.textCount++;
    const textNum = ad.textCount;
    ad.texts.push(textNum);
    
    const container = document.getElementById(`texts-container-${adIndex}`);
    const textItem = document.createElement('div');
    textItem.className = 'creative-text-item';
    textItem.id = `text-item-${adIndex}-${textNum}`;
    textItem.innerHTML = `
        <label>Text Variation ${textNum}</label>
        <textarea id="ad-text-${adIndex}-${textNum}" placeholder="Enter your ad text variation" rows="3"></textarea>
        <button class="remove-text" onclick="removeTextVariation(${adIndex}, ${textNum})">Remove</button>
    `;
    
    container.appendChild(textItem);
}

// Remove text variation
function removeTextVariation(adIndex, textNum) {
    const textItem = document.getElementById(`text-item-${adIndex}-${textNum}`);
    if (textItem) {
        textItem.remove();
    }
    
    const ad = smartState.ads.find(a => a.index === adIndex);
    if (ad) {
        ad.texts = ad.texts.filter(t => t !== textNum);
    }
}

// Load identities for ad
async function loadIdentitiesForAd(adIndex) {
    try {
        const response = await apiRequest('get_identities', {});
        
        if (response.success && response.data) {
            const select = document.getElementById(`identity-${adIndex}`);
            select.innerHTML = '<option value="">Select identity</option>';
            
            response.data.forEach(identity => {
                const option = document.createElement('option');
                option.value = identity.identity_id;
                option.textContent = identity.display_name || identity.identity_name || 'Unnamed Identity';
                select.appendChild(option);
            });
            
            // Add "Create new custom identity" option
            const createOption = document.createElement('option');
            createOption.value = 'CREATE_NEW';
            createOption.textContent = '+ Create new custom identity';
            createOption.style.fontWeight = 'bold';
            createOption.style.color = '#667eea';
            select.appendChild(createOption);
        } else {
            // Add "Create new custom identity" option even when no identities
            const select = document.getElementById(`identity-${adIndex}`);
            const createOption = document.createElement('option');
            createOption.value = 'CREATE_NEW';
            createOption.textContent = '+ Create new custom identity';
            createOption.style.fontWeight = 'bold';
            createOption.style.color = '#667eea';
            select.appendChild(createOption);
        }
        
        // Add event listener for identity selection change
        const select = document.getElementById(`identity-${adIndex}`);
        select.onchange = function() {
            if (this.value === 'CREATE_NEW') {
                openCreateIdentityModal(adIndex);
            }
        };
    } catch (error) {
        console.error('Error loading identities:', error);
    }
}

// Create Identity Modal Functions (Smart Campaign)
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
            
            // Reload identities for the current ad
            if (currentIdentityAdIndex !== null) {
                await loadIdentitiesForAd(currentIdentityAdIndex);
                
                // Select the newly created identity
                const select = document.getElementById(`identity-${currentIdentityAdIndex}`);
                const newOption = Array.from(select.options).find(option => option.value === response.data.identity_id);
                if (newOption) {
                    select.value = response.data.identity_id;
                }
            }
            
            // Reload identities for all other ads too
            smartState.ads.forEach(async (ad) => {
                if (ad.index !== currentIdentityAdIndex) {
                    await loadIdentitiesForAd(ad.index);
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

// Open media modal for Smart+ (allows multiple selection)
function openMediaModal(adIndex, allowMultiple = true) {
    smartState.currentAdIndex = adIndex;
    smartState.currentMediaSelection = [];
    
    const modal = document.getElementById('media-modal');
    modal.style.display = 'block';
    
    // Update selection counter
    const counter = document.getElementById('selection-counter');
    if (allowMultiple) {
        counter.style.display = 'inline';
        counter.textContent = '0 selected';
    }
    
    // Load media library
    loadMediaLibrary(allowMultiple);
}

// Load media library
async function loadMediaLibrary(allowMultiple = true) {
    try {
        const response = await apiRequest('get_media_library', {});
        
        if (response.success && response.data) {
            smartState.mediaLibrary = response.data;
            displayMediaGrid(response.data, allowMultiple);
        }
    } catch (error) {
        console.error('Error loading media library:', error);
    }
}

// Display media grid
function displayMediaGrid(media, allowMultiple) {
    const grid = document.getElementById('media-grid');
    grid.innerHTML = '';
    
    media.forEach(item => {
        const mediaItem = document.createElement('div');
        mediaItem.className = 'media-item';
        mediaItem.onclick = () => selectMediaForSmartAd(item, allowMultiple);
        
        const isVideo = item.material_type === 'VIDEO';
        
        mediaItem.innerHTML = `
            <div class="media-preview">
                ${isVideo ? 
                    `<video src="${item.url}" muted></video>` : 
                    `<img src="${item.url}" alt="${item.file_name}">`
                }
                <div class="media-type">${isVideo ? 'VIDEO' : 'IMAGE'}</div>
            </div>
            <div class="media-info">
                <p>${item.file_name || 'Unnamed'}</p>
                <small>${item.width}x${item.height}</small>
            </div>
        `;
        
        grid.appendChild(mediaItem);
    });
}

// Select media for Smart+ ad
function selectMediaForSmartAd(media, allowMultiple) {
    if (allowMultiple) {
        // Toggle selection
        const index = smartState.currentMediaSelection.findIndex(m => m.file_id === media.file_id);
        
        if (index > -1) {
            smartState.currentMediaSelection.splice(index, 1);
        } else if (smartState.currentMediaSelection.length < 10) {
            smartState.currentMediaSelection.push(media);
        } else {
            showToast('Maximum 10 assets per ad', 'error');
            return;
        }
        
        // Update counter
        const counter = document.getElementById('selection-counter');
        counter.textContent = `${smartState.currentMediaSelection.length} selected`;
        
        // Update visual selection
        updateMediaSelectionVisual();
    } else {
        // Single selection
        smartState.currentMediaSelection = [media];
        confirmMediaSelection();
    }
}

// Update media selection visual
function updateMediaSelectionVisual() {
    const items = document.querySelectorAll('.media-item');
    items.forEach(item => {
        item.classList.remove('selected');
    });
    
    smartState.currentMediaSelection.forEach(media => {
        // Find and mark as selected
        items.forEach(item => {
            if (item.innerHTML.includes(media.file_name)) {
                item.classList.add('selected');
            }
        });
    });
}

// Confirm media selection
function confirmMediaSelection() {
    if (smartState.currentMediaSelection.length === 0) {
        showToast('Please select at least one asset', 'error');
        return;
    }
    
    const adIndex = smartState.currentAdIndex;
    const ad = smartState.ads.find(a => a.index === adIndex);
    
    if (ad) {
        ad.media = smartState.currentMediaSelection;
        
        // Update creative grid display
        const grid = document.getElementById(`creative-grid-${adIndex}`);
        grid.innerHTML = '';
        
        smartState.currentMediaSelection.forEach(media => {
            const assetDiv = document.createElement('div');
            assetDiv.className = 'creative-asset-item';
            
            const isVideo = media.material_type === 'VIDEO';
            
            assetDiv.innerHTML = `
                ${isVideo ? 
                    `<video src="${media.url}" muted></video>` : 
                    `<img src="${media.url}" alt="${media.file_name}">`
                }
                <span class="asset-type">${isVideo ? 'VIDEO' : 'IMAGE'}</span>
                <button class="remove-asset" onclick="removeAsset(${adIndex}, '${media.file_id}')">×</button>
            `;
            
            grid.appendChild(assetDiv);
        });
        
        // Add button to add more
        if (ad.media.length < 10) {
            const addBtn = document.createElement('button');
            addBtn.className = 'add-creative-btn';
            addBtn.onclick = () => openMediaModal(adIndex, true);
            addBtn.innerHTML = `+ Add More<br><small>${10 - ad.media.length} slots remaining</small>`;
            grid.appendChild(addBtn);
        }
        
        // Store media IDs
        document.getElementById(`media-${adIndex}`).value = ad.media.map(m => m.file_id).join(',');
    }
    
    closeMediaModal();
}

// Remove asset
function removeAsset(adIndex, fileId) {
    const ad = smartState.ads.find(a => a.index === adIndex);
    if (ad) {
        ad.media = ad.media.filter(m => m.file_id !== fileId);
        
        // Refresh display
        smartState.currentAdIndex = adIndex;
        smartState.currentMediaSelection = ad.media;
        confirmMediaSelection();
    }
}

// Close media modal
function closeMediaModal() {
    document.getElementById('media-modal').style.display = 'none';
    smartState.currentMediaSelection = [];
}

// Switch media tab
function switchMediaTab(tab, event) {
    event.preventDefault();
    
    // Update tabs
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    // Update content
    document.querySelectorAll('.media-tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`media-${tab}-tab`).classList.add('active');
}

// Review Smart+ ads
function reviewSmartAds() {
    if (smartState.ads.length === 0) {
        showToast('Please create at least one ad', 'error');
        return;
    }
    
    // Validate all ads
    for (const ad of smartState.ads) {
        const adName = document.getElementById(`ad-name-${ad.index}`).value;
        
        if (!adName) {
            showToast(`Please enter ad name for Creative Set ${ad.index}`, 'error');
            return;
        }
        
        if (!ad.media || ad.media.length === 0) {
            showToast(`Please add media for Creative Set ${ad.index}`, 'error');
            return;
        }
        
        // Check at least one text
        let hasText = false;
        for (const textNum of ad.texts) {
            const text = document.getElementById(`ad-text-${ad.index}-${textNum}`)?.value;
            if (text && text.trim()) {
                hasText = true;
                break;
            }
        }
        
        if (!hasText) {
            showToast(`Please add at least one text for Creative Set ${ad.index}`, 'error');
            return;
        }
    }
    
    generateSmartReviewSummary();
    nextStep();
}

// Generate review summary for Smart+ campaign
function generateSmartReviewSummary() {
    // Campaign summary
    const campaignSummary = document.getElementById('campaign-summary');
    const autoTargeting = document.getElementById('auto-targeting').checked;
    const autoPlacement = document.getElementById('auto-placement').checked;
    const creativeOptimization = document.getElementById('creative-optimization').checked;
    
    campaignSummary.innerHTML = `
        <p><strong>Campaign Name:</strong> ${document.getElementById('campaign-name').value}</p>
        <p><strong>Campaign Type:</strong> <span class="badge-smart">Smart+</span></p>
        <p><strong>Objective:</strong> Lead Generation</p>
        <p><strong>Budget:</strong> Set at Ad Group Level</p>
        <p><strong>AI Features:</strong></p>
        <ul style="margin-left: 20px;">
            ${autoTargeting ? '<li>✓ Automated Audience Targeting</li>' : ''}
            ${autoPlacement ? '<li>✓ Smart Placement Optimization</li>' : ''}
            ${creativeOptimization ? '<li>✓ Creative Optimization</li>' : ''}
        </ul>
    `;
    
    // Ad Group summary
    const adGroupSummary = document.getElementById('adgroup-summary');
    adGroupSummary.innerHTML = `
        <p><strong>Ad Group Name:</strong> ${document.getElementById('adgroup-name').value}</p>
        <p><strong>Daily Budget:</strong> $${document.getElementById('budget').value}</p>
        <p><strong>Smart Bid:</strong> $${document.getElementById('bid-price').value}</p>
        <p><strong>Targeting:</strong> AI-Optimized (United States)</p>
        <p><strong>Placement:</strong> Automatic (All TikTok placements)</p>
    `;
    
    // Ads summary
    const adsSummary = document.getElementById('ads-summary');
    adsSummary.innerHTML = '';
    
    smartState.ads.forEach(ad => {
        const adName = document.getElementById(`ad-name-${ad.index}`).value;
        const cta = document.getElementById(`cta-${ad.index}`).value;
        
        // Count text variations
        let textCount = 0;
        ad.texts.forEach(textNum => {
            const text = document.getElementById(`ad-text-${ad.index}-${textNum}`)?.value;
            if (text && text.trim()) textCount++;
        });
        
        const adItem = document.createElement('div');
        adItem.className = 'summary-ad-item';
        adItem.innerHTML = `
            <h4>${adName}</h4>
            <p><strong>Creative Assets:</strong> ${ad.media.length} ${ad.media.length > 1 ? 'assets' : 'asset'}</p>
            <p><strong>Text Variations:</strong> ${textCount} variation${textCount > 1 ? 's' : ''}</p>
            <p><strong>CTA:</strong> ${cta.replace('_', ' ')}</p>
            <div style="margin-top: 10px;">
                ${ad.media.map(m => `
                    <span style="display: inline-block; margin: 2px; padding: 3px 8px; background: #f0f0f0; border-radius: 4px; font-size: 12px;">
                        ${m.material_type === 'VIDEO' ? '🎥' : '🖼️'} ${m.file_name || 'Asset'}
                    </span>
                `).join('')}
            </div>
        `;
        adsSummary.appendChild(adItem);
    });
}

// Publish Smart+ Campaign
async function publishSmartCampaign() {
    showLoading();
    
    try {
        // Create all ads
        const adPromises = [];
        
        for (const ad of smartState.ads) {
            const adName = document.getElementById(`ad-name-${ad.index}`).value;
            const identity = document.getElementById(`identity-${ad.index}`).value;
            const cta = document.getElementById(`cta-${ad.index}`).value;
            const destinationUrl = document.getElementById(`destination-url-${ad.index}`).value;
            
            // Collect all text variations
            const texts = [];
            ad.texts.forEach(textNum => {
                const text = document.getElementById(`ad-text-${ad.index}-${textNum}`)?.value;
                if (text && text.trim()) {
                    texts.push(text.trim());
                }
            });
            
            // Create ad for each media asset (Smart+ supports multiple)
            const adData = {
                adgroup_id: smartState.adGroupId,
                ad_name: adName,
                ad_format: 'SINGLE_VIDEO', // Will be determined by media type
                media_list: ad.media.map(m => m.file_id),
                ad_texts: texts,
                identity_id: identity,
                call_to_action: cta,
                landing_page_url: destinationUrl,
                smart_creative: true // Flag for Smart+ creative
            };
            
            adPromises.push(apiRequest('create_smart_ad', adData));
        }
        
        // Execute all ad creations
        const results = await Promise.all(adPromises);
        
        // Check results
        const successCount = results.filter(r => r.success).length;
        const failCount = results.length - successCount;
        
        if (successCount > 0) {
            showToast(`Smart+ Campaign published! ${successCount} ad${successCount > 1 ? 's' : ''} created successfully`, 'success');
            
            // Redirect after success
            setTimeout(() => {
                window.location.href = 'campaign-select.php';
            }, 2000);
        } else {
            showToast('Failed to create ads. Please check your settings.', 'error');
        }
        
        if (failCount > 0) {
            console.error('Some ads failed:', results.filter(r => !r.success));
        }
        
    } catch (error) {
        showToast('Error publishing campaign: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Utility functions
function formatToTikTokDateTime(date) {
    // Use UTC time to match TikTok API requirements
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');
    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');
    const seconds = '00';
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

async function apiRequest(action, data) {
    try {
        const requestPayload = {
            action: action,
            ...data
        };
        
        console.log('=== API REQUEST DETAILS ===');
        console.log('Endpoint URL:', 'api.php');
        console.log('HTTP Method:', 'POST');
        console.log('Action:', action);
        console.log('Request Headers:', { 'Content-Type': 'application/json' });
        console.log('Full Request Payload:', JSON.stringify(requestPayload, null, 2));
        console.log('==============================');
        
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestPayload)
        });
        
        console.log('=== API RESPONSE DETAILS ===');
        console.log('Response Status:', response.status);
        console.log('Response Status Text:', response.statusText);
        console.log('Response Headers:', Object.fromEntries(response.headers.entries()));
        
        const responseText = await response.text();
        console.log('Raw Response Text Length:', responseText.length);
        console.log('Raw Response Text:', responseText);
        console.log('===============================');
        
        // Try to parse JSON
        try {
            const jsonResponse = JSON.parse(responseText);
            console.log('Parsed JSON Response:', JSON.stringify(jsonResponse, null, 2));
            return jsonResponse;
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError.message);
            console.error('Failed to parse response:', responseText);
            throw new Error('Invalid JSON response from server: ' + responseText.substring(0, 200));
        }
    } catch (error) {
        console.error('API Request Exception:', error);
        throw error;
    }
}

function showLoading() {
    document.getElementById('loading').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loading').style.display = 'none';
}

function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast show ' + type;
    
    setTimeout(() => {
        toast.className = 'toast';
    }, 3000);
}

// File upload functions
function refreshMediaLibrary() {
    loadMediaLibrary(true);
}

async function syncTikTokLibrary() {
    showLoading();
    try {
        const response = await apiRequest('sync_tiktok_media', {});
        
        if (response.success) {
            showToast('Media synced successfully', 'success');
            loadMediaLibrary(true);
        } else {
            showToast(response.message || 'Failed to sync media', 'error');
        }
    } catch (error) {
        showToast('Error syncing media: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Smart Campaign age targeting functions
function selectAllSmartAges() {
    const checkboxes = document.querySelectorAll('.smart-age-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
}

function clearAllSmartAges() {
    const checkboxes = document.querySelectorAll('.smart-age-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
}

function selectDefaultSmartAges() {
    // Clear all first
    clearAllSmartAges();
    
    // Select default ages (18+ excluding 13-17)
    const defaultAges = ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'];
    defaultAges.forEach(age => {
        const checkbox = document.querySelector(`.smart-age-checkbox[value="${age}"]`);
        if (checkbox) {
            checkbox.checked = true;
        }
    });
}

function getSmartSelectedAgeGroups() {
    const selectedAges = [];
    const checkboxes = document.querySelectorAll('.smart-age-checkbox:checked');
    
    checkboxes.forEach(checkbox => {
        selectedAges.push(checkbox.value);
    });
    
    return selectedAges;
}

// Smart Campaign location targeting functions

function toggleSmartLocationMethod() {
    const methodElement = document.querySelector('input[name="smart_location_method"]:checked');
    if (!methodElement) {
        console.error('No Smart+ location method radio button found');
        return;
    }
    
    const method = methodElement.value;
    const countryOption = document.getElementById('smart-country-targeting');
    const statesOption = document.getElementById('smart-states-targeting');
    
    if (!countryOption || !statesOption) {
        console.error('Smart+ location targeting elements not found');
        return;
    }
    
    console.log('Toggling Smart+ location method to:', method);
    
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
        populateSmartStatesGrid();
    }
}







// Initialize Smart+ location targeting on page load
function initializeSmartLocationTargeting() {
    // Ensure the default country option is displayed
    const countryRadio = document.querySelector('input[name="smart_location_method"][value="country"]');
    if (countryRadio) {
        countryRadio.checked = true;
        toggleSmartLocationMethod();
        console.log('Smart+ location targeting initialized - default to country');
    }
    
    // Auto-populate states grid when states option is available
    const statesRadio = document.querySelector('input[name="smart_location_method"][value="states"]');
    if (statesRadio) {
        // Pre-populate the grid so it's ready when user switches to states
        populateSmartStatesGrid();
    }
}

function getSmartSelectedLocationIds() {
    const methodElement = document.querySelector('input[name="smart_location_method"]:checked');
    
    // If no radio button is found or checked, default to country
    if (!methodElement) {
        console.log('No Smart+ location method selected, defaulting to country');
        return ['6252001']; // Default to United States
    }
    
    const method = methodElement.value;
    console.log('Selected Smart+ location method:', method);
    
    if (method === 'country') {
        return ['6252001']; // United States
    } else if (method === 'states') {
        // Get selected state checkboxes
        const selectedCheckboxes = document.querySelectorAll('input[name="smart_state_selection"]:checked');
        
        if (selectedCheckboxes.length === 0) {
            console.log('Smart+ states method selected but no states selected');
            return null; // Error case - will be handled by validation
        }
        
        const locations = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
        console.log('Returning Smart+ selected state location IDs:', locations);
        return locations;
    }
    
    return ['6252001']; // Fallback to US
}

// US States data for Smart+ Campaign (same as manual campaign)
const SMART_US_STATES = [
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

const SMART_POPULAR_STATES = ['California', 'Texas', 'New York', 'Florida', 'Illinois', 'Pennsylvania', 'Ohio', 'Georgia', 'North Carolina', 'Michigan'];

// Smart+ state selection functions
function populateSmartStatesGrid() {
    const statesGrid = document.getElementById('smart-states-grid');
    if (!statesGrid) {
        console.error('Smart+ states grid element not found');
        return;
    }
    
    // Only populate if not already done
    if (statesGrid.children.length > 0) {
        return;
    }
    
    console.log('Populating Smart+ states grid with', SMART_US_STATES.length, 'states');
    
    SMART_US_STATES.forEach(state => {
        const stateItem = document.createElement('div');
        stateItem.className = 'state-item';
        
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'smart_state_selection';
        checkbox.value = state.id;
        checkbox.id = `smart_state_${state.id}`;
        checkbox.checked = true; // All states selected by default
        checkbox.addEventListener('change', updateSmartStatesCount);
        
        const label = document.createElement('label');
        label.htmlFor = `smart_state_${state.id}`;
        label.textContent = state.name;
        
        stateItem.appendChild(checkbox);
        stateItem.appendChild(label);
        statesGrid.appendChild(stateItem);
    });
    
    updateSmartStatesCount();
}

function selectAllSmartStates() {
    const checkboxes = document.querySelectorAll('input[name="smart_state_selection"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    updateSmartStatesCount();
    console.log('All Smart+ states selected');
}

function clearAllSmartStates() {
    const checkboxes = document.querySelectorAll('input[name="smart_state_selection"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateSmartStatesCount();
    console.log('All Smart+ states cleared');
}

function selectPopularSmartStates() {
    // First clear all
    clearAllSmartStates();
    
    // Then select popular states
    SMART_POPULAR_STATES.forEach(stateName => {
        const state = SMART_US_STATES.find(s => s.name === stateName);
        if (state) {
            const checkbox = document.getElementById(`smart_state_${state.id}`);
            if (checkbox) {
                checkbox.checked = true;
            }
        }
    });
    updateSmartStatesCount();
    console.log('Popular Smart+ states selected');
}

function updateSmartStatesCount() {
    const selectedCheckboxes = document.querySelectorAll('input[name="smart_state_selection"]:checked');
    const countElement = document.getElementById('smart-selected-states-count');
    if (countElement) {
        countElement.textContent = selectedCheckboxes.length;
    }
    console.log('Smart+ states count updated:', selectedCheckboxes.length);
}

// Avatar Selection Functions (duplicate for Smart+ Campaign)
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
        const response = await smartApiRequest('get_images', {}, 'GET');
        const grid = document.getElementById('avatar-library-grid');
        grid.innerHTML = '';
        
        if (response.success && response.data && response.data.list && response.data.list.length > 0) {
            // Filter for square images only (for avatar use)
            const squareImages = response.data.list.filter(image => {
                return image.width && image.height && image.width === image.height;
            });
            
            if (squareImages.length > 0) {
                squareImages.forEach(image => {
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
                grid.innerHTML = '<p style="text-align: center; color: #999; padding: 20px;">No square images available for avatars. Upload square images (equal width and height) or crop existing images.</p>';
            }
        } else {
            grid.innerHTML = '<p style="text-align: center; color: #999; padding: 20px;">No images in TikTok library. Upload some images first or sync from TikTok.</p>';
        }
    } catch (error) {
        console.error('Error loading avatar library:', error);
        document.getElementById('avatar-library-grid').innerHTML = '<p style="text-align: center; color: #f00; padding: 20px;">Error loading images.</p>';
    }
}

function selectAvatarImage(image) {
    selectedAvatarImageId = image.image_id;
    
    // Remove selection from other items
    document.querySelectorAll('.avatar-item').forEach(item => {
        item.style.border = 'none';
        item.style.boxShadow = 'none';
    });
    
    // Highlight selected item
    event.target.closest('.avatar-item').style.border = '3px solid #667eea';
    event.target.closest('.avatar-item').style.boxShadow = '0 0 10px rgba(102, 126, 234, 0.3)';
    
    // Enable confirm button
    document.getElementById('confirm-avatar-btn').disabled = false;
}

function handleAvatarUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    // Validate file type
    if (!file.type.startsWith('image/')) {
        showSmartToast('Please select an image file', 'error');
        return;
    }
    
    // Validate image dimensions (TikTok requires square avatars)
    const img = new Image();
    const reader = new FileReader();
    
    reader.onload = function(e) {
        img.onload = function() {
            if (img.width !== img.height) {
                showSmartToast('Avatar images must be square (equal width and height). Please use an image editor to crop your image to square dimensions.', 'error');
                event.target.value = ''; // Clear the file input
                return;
            }
            
            // Show preview if dimensions are valid
            document.getElementById('avatar-preview-img').src = e.target.result;
            document.getElementById('avatar-upload-preview').style.display = 'block';
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

async function uploadAvatarImage() {
    const fileInput = document.getElementById('avatar-file-input');
    const file = fileInput.files[0];
    
    if (!file) {
        showSmartToast('Please select an image file', 'error');
        return;
    }
    
    try {
        showSmartToast('Uploading avatar image...', 'info');
        
        const formData = new FormData();
        formData.append('image', file);
        formData.append('action', 'upload_image');
        
        const response = await fetch('api.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.data && result.data.image_id) {
            selectedAvatarImageId = result.data.image_id;
            showSmartToast('Avatar image uploaded successfully', 'success');
            
            // Enable confirm button
            document.getElementById('confirm-avatar-btn').disabled = false;
        } else {
            showSmartToast('Failed to upload avatar image: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Error uploading avatar:', error);
        showSmartToast('Error uploading avatar image', 'error');
    }
}

function confirmAvatarSelection() {
    if (!selectedAvatarImageId) {
        showSmartToast('Please select or upload an avatar image', 'error');
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
    showSmartToast('Avatar image selected', 'success');
}