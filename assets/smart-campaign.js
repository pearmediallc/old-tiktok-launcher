// Smart+ Campaign JavaScript - Single Page Form
// Uses the NEW /campaign/spc/create/ endpoint that creates everything in ONE API call

// State management
const state = {
    selectedMedia: [],
    selectedCTAs: ['LEARN_MORE'],
    ctaPortfolioId: null
};

// Initialize on page load
window.addEventListener('DOMContentLoaded', async () => {
    console.log('=== Smart+ Campaign Single Page Form Initialization ===');

    try {
        await loadAdvertiserInfo();
        await loadPixels();
        await loadIdentities();
        loadVideos();
    } catch (error) {
        console.error('Initialization error:', error);
        showToast('Failed to initialize page', 'error');
    }
});

// Load advertiser info
async function loadAdvertiserInfo() {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_selected_advertiser' })
        });

        const data = await response.json();

        if (data.success && data.advertiser) {
            document.getElementById('advertiser-name').textContent = data.advertiser.advertiser_name || 'Selected Advertiser';
            localStorage.setItem('advertiser_name', data.advertiser.advertiser_name);
            localStorage.setItem('advertiser_id', data.advertiser.advertiser_id);
        } else {
            console.error('No selected advertiser found');
            window.location.href = 'select-advertiser.php';
        }
    } catch (error) {
        console.error('Error loading advertiser info:', error);
        window.location.href = 'select-advertiser.php';
    }
}

// Load pixels
async function loadPixels() {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_pixels' })
        });

        const data = await response.json();
        const select = document.getElementById('pixel-select');
        select.innerHTML = '<option value="">Select a pixel (optional)</option>';

        if (data.success && data.data && data.data.pixels) {
            data.data.pixels.forEach(pixel => {
                const option = document.createElement('option');
                option.value = pixel.pixel_id;
                option.textContent = pixel.pixel_name || pixel.pixel_id;
                select.appendChild(option);
            });
        }

        // Show event dropdown when pixel is selected
        select.addEventListener('change', () => {
            document.getElementById('pixel-event-group').style.display = select.value ? 'block' : 'none';
        });
    } catch (error) {
        console.error('Error loading pixels:', error);
        document.getElementById('pixel-select').innerHTML = '<option value="">No pixels available</option>';
    }
}

// Load identities
async function loadIdentities() {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_identities' })
        });

        const data = await response.json();
        const select = document.getElementById('identity-select');
        select.innerHTML = '<option value="">Select an identity</option>';

        if (data.success && data.data) {
            data.data.forEach(identity => {
                const option = document.createElement('option');
                option.value = identity.identity_id;
                option.textContent = identity.display_name || identity.identity_name || 'Unnamed Identity';
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading identities:', error);
        document.getElementById('identity-select').innerHTML = '<option value="">No identities available</option>';
    }
}

// Load videos from media library
async function loadVideos() {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_videos' })
        });

        const data = await response.json();
        const grid = document.getElementById('media-grid');
        grid.innerHTML = '';

        if (data.success && data.data && data.data.list) {
            data.data.list.forEach(video => {
                const item = document.createElement('div');
                item.className = 'media-item';
                item.dataset.videoId = video.video_id;
                item.dataset.url = video.video_cover_url || video.preview_url || '';
                item.onclick = () => toggleMediaSelection(video);

                // Check if already selected
                if (state.selectedMedia.find(m => m.video_id === video.video_id)) {
                    item.classList.add('selected');
                }

                item.innerHTML = `
                    <div class="media-preview">
                        ${video.video_cover_url ?
                            `<img src="${video.video_cover_url}" alt="${video.file_name || 'Video'}">` :
                            `<div style="background:#333;width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:white;">VIDEO</div>`
                        }
                        <div class="media-type">VIDEO</div>
                    </div>
                    <div class="media-info">
                        <p>${video.file_name || 'Unnamed Video'}</p>
                        <small>${video.width || '?'}x${video.height || '?'}</small>
                    </div>
                `;

                grid.appendChild(item);
            });
        } else {
            grid.innerHTML = '<p style="text-align:center;padding:30px;color:#666;">No videos found. Click "Sync from TikTok" to load videos.</p>';
        }
    } catch (error) {
        console.error('Error loading videos:', error);
        document.getElementById('media-grid').innerHTML = '<p style="text-align:center;padding:30px;color:#f00;">Error loading videos</p>';
    }
}

// Toggle media selection
function toggleMediaSelection(video) {
    const index = state.selectedMedia.findIndex(m => m.video_id === video.video_id);

    if (index > -1) {
        // Deselect
        state.selectedMedia.splice(index, 1);
    } else if (state.selectedMedia.length < 30) {
        // Select
        state.selectedMedia.push(video);
    } else {
        showToast('Maximum 30 videos allowed', 'error');
        return;
    }

    // Update visual selection in modal
    document.querySelectorAll('.media-item').forEach(item => {
        if (item.dataset.videoId === video.video_id) {
            item.classList.toggle('selected', state.selectedMedia.find(m => m.video_id === video.video_id));
        }
    });

    // Update counter
    document.getElementById('selection-counter').textContent = `${state.selectedMedia.length} selected`;
}

// Open media modal
function openMediaModal() {
    document.getElementById('media-modal').style.display = 'block';
    document.getElementById('selection-counter').textContent = `${state.selectedMedia.length} selected`;
    loadVideos();
}

// Close media modal
function closeMediaModal() {
    document.getElementById('media-modal').style.display = 'none';
}

// Confirm media selection
function confirmMediaSelection() {
    if (state.selectedMedia.length === 0) {
        showToast('Please select at least one video', 'error');
        return;
    }

    // Update the display grid
    const grid = document.getElementById('selected-media-grid');
    grid.innerHTML = '';

    state.selectedMedia.forEach(video => {
        const item = document.createElement('div');
        item.className = 'selected-media-item';
        item.innerHTML = `
            ${video.video_cover_url ?
                `<img src="${video.video_cover_url}" alt="${video.file_name || 'Video'}">` :
                `<div style="background:#333;width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:white;">VIDEO</div>`
            }
            <button class="remove-btn" onclick="removeSelectedMedia('${video.video_id}')">&times;</button>
        `;
        grid.appendChild(item);
    });

    closeMediaModal();
    showToast(`${state.selectedMedia.length} video(s) selected`, 'success');
}

// Remove selected media
function removeSelectedMedia(videoId) {
    state.selectedMedia = state.selectedMedia.filter(m => m.video_id !== videoId);
    confirmMediaSelection();
}

// Switch media tab
function switchMediaTab(tab, event) {
    event.preventDefault();
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
}

// Sync TikTok Library
async function syncTikTokLibrary() {
    showLoading();
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'sync_tiktok_media' })
        });

        const data = await response.json();
        if (data.success) {
            showToast('Media synced successfully', 'success');
            loadVideos();
        } else {
            showToast(data.message || 'Failed to sync media', 'error');
        }
    } catch (error) {
        showToast('Error syncing media', 'error');
    } finally {
        hideLoading();
    }
}

// Add ad text variation
function addAdText() {
    const container = document.getElementById('ad-texts-container');
    const count = container.children.length;

    if (count >= 10) {
        showToast('Maximum 10 text variations', 'error');
        return;
    }

    const item = document.createElement('div');
    item.className = 'ad-text-item';
    item.innerHTML = `
        <input type="text" class="ad-text-input" placeholder="Enter ad text (12-100 characters)" maxlength="100" minlength="12">
        <button class="remove-text-btn" onclick="removeAdText(this)">&times;</button>
    `;
    container.appendChild(item);
}

// Remove ad text variation
function removeAdText(btn) {
    const container = document.getElementById('ad-texts-container');
    if (container.children.length > 1) {
        btn.parentElement.remove();
    } else {
        showToast('At least one ad text is required', 'error');
    }
}

// Select CTA
function selectCTA(element) {
    // Toggle selection
    element.classList.toggle('selected');

    // Update state
    state.selectedCTAs = [];
    document.querySelectorAll('.cta-item.selected').forEach(item => {
        state.selectedCTAs.push(item.dataset.cta);
    });

    // Ensure at least one is selected
    if (state.selectedCTAs.length === 0) {
        element.classList.add('selected');
        state.selectedCTAs.push(element.dataset.cta);
    }

    document.getElementById('selected-ctas').value = state.selectedCTAs.join(',');
}

// Create CTA portfolio
async function createCTAPortfolio() {
    if (state.selectedCTAs.length === 0) {
        return null;
    }

    try {
        // Build portfolio content
        const portfolioContent = state.selectedCTAs.map(cta => ({
            asset_content: cta,
            asset_ids: state.selectedMedia.map(m => m.video_id)
        }));

        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_cta_portfolio',
                portfolio_content: portfolioContent
            })
        });

        const data = await response.json();
        if (data.success && data.data && (data.data.portfolio_id || data.data.creative_portfolio_id)) {
            return data.data.portfolio_id || data.data.creative_portfolio_id;
        } else {
            console.error('CTA Portfolio creation failed:', data);
            return null;
        }
    } catch (error) {
        console.error('Error creating CTA portfolio:', error);
        return null;
    }
}

// Open create identity modal
function openCreateIdentityModal() {
    document.getElementById('create-identity-modal').style.display = 'block';
    document.getElementById('new-identity-name').value = '';
    document.getElementById('new-identity-name').focus();
}

// Close create identity modal
function closeCreateIdentityModal() {
    document.getElementById('create-identity-modal').style.display = 'none';
}

// Create new identity
async function createNewIdentity() {
    const displayName = document.getElementById('new-identity-name').value.trim();

    if (!displayName) {
        showToast('Please enter a display name', 'error');
        return;
    }

    showLoading();
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_identity',
                display_name: displayName
            })
        });

        const data = await response.json();
        if (data.success && data.data && data.data.identity_id) {
            showToast('Identity created successfully', 'success');
            closeCreateIdentityModal();
            await loadIdentities();

            // Select the new identity
            document.getElementById('identity-select').value = data.data.identity_id;
        } else {
            showToast(data.message || 'Failed to create identity', 'error');
        }
    } catch (error) {
        showToast('Error creating identity', 'error');
    } finally {
        hideLoading();
    }
}

// MAIN: Publish Smart+ Campaign
async function publishSmartCampaign() {
    console.log('=== PUBLISHING SMART+ CAMPAIGN ===');

    // Validate all fields
    const campaignName = document.getElementById('campaign-name').value.trim();
    const budget = parseFloat(document.getElementById('budget').value);
    const ageTargeting = document.getElementById('age-targeting').value;
    const pixelId = document.getElementById('pixel-select').value;
    const pixelEvent = document.getElementById('pixel-event').value;
    const identityId = document.getElementById('identity-select').value;
    const landingPageUrl = document.getElementById('landing-page-url').value.trim();

    // Collect ad texts
    const adTexts = [];
    document.querySelectorAll('.ad-text-input').forEach(input => {
        const text = input.value.trim();
        if (text && text.length >= 12) {
            adTexts.push(text);
        }
    });

    // Validation
    if (!campaignName) {
        showToast('Please enter a campaign name', 'error');
        return;
    }
    if (budget < 20) {
        showToast('Minimum budget is $20', 'error');
        return;
    }
    if (!identityId) {
        showToast('Please select an identity', 'error');
        return;
    }
    if (state.selectedMedia.length === 0) {
        showToast('Please select at least one video', 'error');
        return;
    }
    if (adTexts.length === 0) {
        showToast('Please enter at least one ad text (min 12 characters)', 'error');
        return;
    }
    if (!landingPageUrl) {
        showToast('Please enter a landing page URL', 'error');
        return;
    }

    // Disable publish button
    const publishBtn = document.getElementById('publish-btn');
    publishBtn.disabled = true;
    publishBtn.textContent = 'Publishing...';

    showLoading();

    try {
        // Step 1: Create CTA Portfolio
        console.log('Creating CTA Portfolio...');
        const ctaPortfolioId = await createCTAPortfolio();
        if (ctaPortfolioId) {
            console.log('CTA Portfolio created:', ctaPortfolioId);
        } else {
            console.log('No CTA Portfolio created, proceeding without it');
        }

        // Step 2: Build media_info_list for Smart+ API
        const mediaInfoList = state.selectedMedia.map(video => ({
            video_id: video.video_id
        }));

        // Step 3: Calculate schedule times (UTC)
        const now = new Date();
        const startTime = new Date(now.getTime() + 3600000); // 1 hour from now
        const endTime = new Date(now.getTime() + 365 * 24 * 3600000); // 1 year from now

        const formatUTC = (date) => {
            return date.toISOString().replace('T', ' ').substring(0, 19);
        };

        // Step 4: Build the complete Smart+ Campaign payload
        const payload = {
            action: 'publish_smart_plus_campaign',
            campaign_name: campaignName,
            budget: budget,
            spc_audience_age: ageTargeting,
            identity_id: identityId,
            media_info_list: mediaInfoList,
            title_list: adTexts,
            landing_page_url: landingPageUrl,
            location_ids: ['6252001'], // United States
            schedule_start_time: formatUTC(startTime),
            schedule_end_time: formatUTC(endTime)
        };

        // Add pixel if selected
        if (pixelId) {
            payload.pixel_id = pixelId;
            payload.optimization_event = pixelEvent;
        }

        // Add CTA portfolio if created
        if (ctaPortfolioId) {
            payload.call_to_action_id = ctaPortfolioId;
        }

        console.log('Smart+ Campaign Payload:', JSON.stringify(payload, null, 2));

        // Step 5: Call the API
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        console.log('API Response:', result);

        if (result.success) {
            showToast('Smart+ Campaign published successfully!', 'success');

            // Show success message with campaign ID
            if (result.data && result.data.campaign_id) {
                alert(`Campaign Created Successfully!\n\nCampaign ID: ${result.data.campaign_id}\n\nYour Smart+ Campaign is now live with AI-powered optimization.`);
            }

            // Redirect back to campaign selection
            setTimeout(() => {
                window.location.href = 'campaign-select.php';
            }, 2000);
        } else {
            showToast(result.message || 'Failed to publish campaign', 'error');
            console.error('Campaign creation failed:', result);

            // Show detailed error
            if (result.error) {
                console.error('Error details:', result.error);
            }
        }
    } catch (error) {
        console.error('Error publishing campaign:', error);
        showToast('Error publishing campaign: ' + error.message, 'error');
    } finally {
        hideLoading();
        publishBtn.disabled = false;
        publishBtn.textContent = 'Publish Smart+ Campaign';
    }
}

// Utility functions
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
