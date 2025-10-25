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
        }
    } catch (error) {
        console.error('Error loading identities:', error);
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
let smartUploadedLocations = [];

function toggleSmartLocationMethod() {
    const methodElement = document.querySelector('input[name="smart_location_method"]:checked');
    if (!methodElement) {
        console.error('No Smart+ location method radio button found');
        return;
    }
    
    const method = methodElement.value;
    const countryOption = document.getElementById('smart-country-targeting');
    const bulkOption = document.getElementById('smart-bulk-targeting');
    
    if (!countryOption || !bulkOption) {
        console.error('Smart+ location targeting elements not found');
        return;
    }
    
    console.log('Toggling Smart+ location method to:', method);
    
    if (method === 'country') {
        countryOption.style.display = 'block';
        countryOption.classList.add('active');
        bulkOption.style.display = 'none';
        bulkOption.classList.remove('active');
        smartUploadedLocations = []; // Clear uploaded locations
    } else {
        countryOption.style.display = 'none';
        countryOption.classList.remove('active');
        bulkOption.style.display = 'block';
        bulkOption.classList.add('active');
    }
}

function handleSmartLocationFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    const fileName = file.name.toLowerCase();
    const validExtensions = ['.xlsx', '.xls', '.csv'];
    const isValidFile = validExtensions.some(ext => fileName.endsWith(ext));
    
    if (!isValidFile) {
        showToast('Please upload a valid Excel (.xlsx, .xls) or CSV (.csv) file', 'error');
        event.target.value = '';
        return;
    }
    
    // For CSV files, parse directly
    if (fileName.endsWith('.csv')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                parseSmartLocationCSV(e.target.result);
            } catch (error) {
                showToast('Error parsing CSV file: ' + error.message, 'error');
            }
        };
        reader.readAsText(file);
    } else {
        // For Excel files, we'll need to handle on backend
        showToast('For Excel files, please save as CSV format and re-upload', 'info');
    }
}

function parseSmartLocationCSV(csvText) {
    const lines = csvText.split('\n').filter(line => line.trim());
    if (lines.length < 2) {
        throw new Error('CSV must have at least a header and one data row');
    }
    
    const header = lines[0].toLowerCase().trim();
    const locations = [];
    
    // Check if it's state names or location IDs
    const isLocationIds = header.includes('location_id') || header.includes('id');
    const isStateNames = header.includes('state') || header.includes('name');
    
    if (!isLocationIds && !isStateNames) {
        throw new Error('CSV header must contain either "State" or "location_id"');
    }
    
    for (let i = 1; i < lines.length; i++) {
        const value = lines[i].trim();
        if (value) {
            if (isLocationIds) {
                // Validate that it's a number
                if (!/^\d+$/.test(value)) {
                    throw new Error(`Invalid location ID: ${value}. Must be numeric.`);
                }
                locations.push({
                    id: value,
                    name: `Location ID: ${value}`,
                    type: 'id'
                });
            } else {
                locations.push({
                    id: null,
                    name: value,
                    type: 'state'
                });
            }
        }
    }
    
    if (locations.length === 0) {
        throw new Error('No valid locations found in CSV');
    }
    
    if (locations.length > 3000) {
        throw new Error('Maximum 3,000 locations allowed per ad group');
    }
    
    smartUploadedLocations = locations;
    displaySmartLocationPreview(locations);
    showToast(`Successfully loaded ${locations.length} locations for Smart+`, 'success');
}

function displaySmartLocationPreview(locations) {
    const preview = document.getElementById('smart-location-preview');
    const locationList = document.getElementById('smart-location-list');
    
    locationList.innerHTML = '';
    locations.slice(0, 50).forEach(location => { // Show first 50
        const div = document.createElement('div');
        div.className = 'location-item';
        div.textContent = location.name;
        locationList.appendChild(div);
    });
    
    if (locations.length > 50) {
        const div = document.createElement('div');
        div.className = 'location-item';
        div.style.fontStyle = 'italic';
        div.style.color = '#666';
        div.textContent = `... and ${locations.length - 50} more locations`;
        locationList.appendChild(div);
    }
    
    preview.style.display = 'block';
}

function clearSmartLocationFile() {
    document.getElementById('smart-location-file').value = '';
    document.getElementById('smart-location-preview').style.display = 'none';
    smartUploadedLocations = [];
    showToast('Smart+ location file cleared', 'info');
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
    } else {
        if (smartUploadedLocations.length === 0) {
            console.log('Smart+ bulk method selected but no locations uploaded');
            return null; // Error case - will be handled by validation
        }
        
        // If using state names, we'll need to convert them to location IDs on backend
        // For now, return the uploaded data
        const locations = smartUploadedLocations.map(loc => loc.id || loc.name);
        console.log('Returning Smart+ uploaded locations:', locations);
        return locations;
    }
}