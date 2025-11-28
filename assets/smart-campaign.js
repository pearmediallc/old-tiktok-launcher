// Smart+ Campaign JavaScript - Spark Ads Version
// Uses /campaign/spc/create/ with TT_USER/AUTH_CODE and tiktok_item_id

// State management
const state = {
    selectedPosts: [],      // TikTok posts (Spark Ads)
    selectedCTAs: ['LEARN_MORE'],
    ctaPortfolioId: null,
    currentIdentity: null   // Currently selected identity {id, type}
};

// Initialize on page load
window.addEventListener('DOMContentLoaded', async () => {
    console.log('=== Smart+ Campaign (Spark Ads) Initialization ===');

    try {
        await loadAdvertiserInfo();
        await loadPixels();
        await loadIdentities();
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
        select.innerHTML = '<option value="">Select a pixel (required for Website)</option>';

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

// Load identities - Focus on TT_USER for Spark Ads
async function loadIdentities() {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_identities' })
        });

        const data = await response.json();
        const select = document.getElementById('identity-select');
        select.innerHTML = '<option value="">Select a linked TikTok account</option>';

        let hasTTUser = false;

        if (data.success && data.data && data.data.list) {
            data.data.list.forEach(identity => {
                const option = document.createElement('option');
                option.value = JSON.stringify({
                    identity_id: identity.identity_id,
                    identity_type: identity.identity_type || 'TT_USER'
                });

                const typeLabel = identity.identity_type === 'TT_USER' ? '🔗 TikTok Account' :
                                  identity.identity_type === 'CUSTOMIZED_USER' ? '📝 Custom' :
                                  identity.identity_type;
                option.textContent = `${typeLabel}: ${identity.display_name || identity.identity_name || 'Unnamed'}`;

                // Highlight TT_USER identities
                if (identity.identity_type === 'TT_USER') {
                    hasTTUser = true;
                    option.style.fontWeight = 'bold';
                }

                select.appendChild(option);
            });
        }

        // Show warning if no TT_USER identities
        const warningDiv = document.getElementById('identity-warning');
        if (!hasTTUser && warningDiv) {
            warningDiv.style.display = 'block';
            warningDiv.innerHTML = `
                <p style="color:#ff6b00;margin:10px 0;">
                    ⚠️ <strong>No linked TikTok account found!</strong><br>
                    Smart+ Lead Gen requires a linked TikTok account (TT_USER).<br>
                    <a href="https://ads.tiktok.com" target="_blank">Link your TikTok account in Ads Manager</a>
                </p>
            `;
        }

        // When identity changes, load their TikTok posts
        select.addEventListener('change', async () => {
            if (select.value) {
                const identity = JSON.parse(select.value);
                state.currentIdentity = identity;

                // Show/hide posts section based on identity type
                const postsSection = document.getElementById('tiktok-posts-section');
                if (identity.identity_type === 'TT_USER' || identity.identity_type === 'BC_AUTH_TT') {
                    postsSection.style.display = 'block';
                    await loadTikTokPosts(identity.identity_id, identity.identity_type);
                } else if (identity.identity_type === 'CUSTOMIZED_USER') {
                    postsSection.style.display = 'none';
                    showToast('CUSTOMIZED_USER is not supported for Smart+ Lead Gen. Please link a TikTok account.', 'error');
                }
            }
        });
    } catch (error) {
        console.error('Error loading identities:', error);
        document.getElementById('identity-select').innerHTML = '<option value="">No identities available</option>';
    }
}

// Load TikTok posts from linked account
async function loadTikTokPosts(identityId, identityType) {
    const grid = document.getElementById('posts-grid');
    grid.innerHTML = '<p style="text-align:center;padding:30px;">Loading TikTok posts...</p>';

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'get_tiktok_posts',
                identity_id: identityId,
                identity_type: identityType
            })
        });

        const data = await response.json();
        grid.innerHTML = '';

        if (data.success && data.data && data.data.video_list) {
            data.data.video_list.forEach(post => {
                const item = document.createElement('div');
                item.className = 'post-item';
                item.dataset.itemId = post.item_id;
                item.onclick = () => togglePostSelection(post);

                // Check if already selected
                if (state.selectedPosts.find(p => p.tiktok_item_id === post.item_id)) {
                    item.classList.add('selected');
                }

                item.innerHTML = `
                    <div class="post-preview">
                        ${post.video_cover_url ?
                            `<img src="${post.video_cover_url}" alt="TikTok Post">` :
                            `<div style="background:#333;width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:white;">📹</div>`
                        }
                        <div class="post-type">TIKTOK POST</div>
                    </div>
                    <div class="post-info">
                        <p>${post.video_description ? post.video_description.substring(0, 50) + '...' : 'TikTok Video'}</p>
                        <small>ID: ${post.item_id.substring(0, 10)}...</small>
                    </div>
                `;

                grid.appendChild(item);
            });

            if (data.data.video_list.length === 0) {
                grid.innerHTML = '<p style="text-align:center;padding:30px;color:#666;">No TikTok posts found for this account.</p>';
            }
        } else {
            grid.innerHTML = `
                <div style="text-align:center;padding:30px;">
                    <p style="color:#666;">Could not load TikTok posts.</p>
                    <p style="color:#999;font-size:12px;">${data.message || 'Try linking your TikTok account again.'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading TikTok posts:', error);
        grid.innerHTML = '<p style="text-align:center;padding:30px;color:#f00;">Error loading TikTok posts</p>';
    }
}

// Toggle post selection
function togglePostSelection(post) {
    const index = state.selectedPosts.findIndex(p => p.tiktok_item_id === post.item_id);

    if (index > -1) {
        // Deselect
        state.selectedPosts.splice(index, 1);
    } else if (state.selectedPosts.length < 30) {
        // Select
        state.selectedPosts.push({
            tiktok_item_id: post.item_id,
            video_cover_url: post.video_cover_url,
            video_description: post.video_description
        });
    } else {
        showToast('Maximum 30 posts allowed', 'error');
        return;
    }

    // Update visual selection
    document.querySelectorAll('.post-item').forEach(item => {
        if (item.dataset.itemId === post.item_id) {
            item.classList.toggle('selected', state.selectedPosts.find(p => p.tiktok_item_id === post.item_id));
        }
    });

    // Update counter
    updateSelectedPostsDisplay();
}

// Update selected posts display
function updateSelectedPostsDisplay() {
    const counter = document.getElementById('posts-counter');
    if (counter) {
        counter.textContent = `${state.selectedPosts.length} post(s) selected`;
    }

    // Update selected posts grid
    const selectedGrid = document.getElementById('selected-posts-grid');
    if (selectedGrid) {
        selectedGrid.innerHTML = '';
        state.selectedPosts.forEach(post => {
            const item = document.createElement('div');
            item.className = 'selected-post-item';
            item.innerHTML = `
                ${post.video_cover_url ?
                    `<img src="${post.video_cover_url}" alt="Selected Post">` :
                    `<div style="background:#333;width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:white;">📹</div>`
                }
                <button class="remove-btn" onclick="removeSelectedPost('${post.tiktok_item_id}')">&times;</button>
            `;
            selectedGrid.appendChild(item);
        });
    }
}

// Remove selected post
function removeSelectedPost(itemId) {
    state.selectedPosts = state.selectedPosts.filter(p => p.tiktok_item_id !== itemId);
    updateSelectedPostsDisplay();

    // Update visual in grid
    document.querySelectorAll('.post-item').forEach(item => {
        if (item.dataset.itemId === itemId) {
            item.classList.remove('selected');
        }
    });
}

// Add AUTH_CODE manually
async function addAuthCode() {
    const authCodeInput = document.getElementById('auth-code-input');
    const authCode = authCodeInput ? authCodeInput.value.trim() : '';

    if (!authCode) {
        showToast('Please enter an authorization code', 'error');
        return;
    }

    showLoading();
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'get_video_by_auth_code',
                auth_code: authCode
            })
        });

        const data = await response.json();

        if (data.success && data.data) {
            const post = data.data;
            state.selectedPosts.push({
                tiktok_item_id: post.item_id || post.tiktok_item_id,
                video_cover_url: post.video_cover_url,
                video_description: post.video_description || 'Authorized Video'
            });

            // Set identity type to AUTH_CODE
            state.currentIdentity = {
                identity_id: post.identity_id,
                identity_type: 'AUTH_CODE'
            };

            updateSelectedPostsDisplay();
            showToast('Video authorized successfully!', 'success');
            authCodeInput.value = '';
        } else {
            showToast(data.message || 'Invalid authorization code', 'error');
        }
    } catch (error) {
        showToast('Error verifying auth code', 'error');
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
    element.classList.toggle('selected');

    state.selectedCTAs = [];
    document.querySelectorAll('.cta-item.selected').forEach(item => {
        state.selectedCTAs.push(item.dataset.cta);
    });

    if (state.selectedCTAs.length === 0) {
        element.classList.add('selected');
        state.selectedCTAs.push(element.dataset.cta);
    }

    const selectedCtasInput = document.getElementById('selected-ctas');
    if (selectedCtasInput) {
        selectedCtasInput.value = state.selectedCTAs.join(',');
    }
}

// Create CTA portfolio
async function createCTAPortfolio() {
    if (state.selectedCTAs.length === 0) {
        return null;
    }

    try {
        const portfolioContent = state.selectedCTAs.map(cta => ({
            asset_content: cta,
            asset_ids: state.selectedPosts.map(p => p.tiktok_item_id)
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
        }
        return null;
    } catch (error) {
        console.error('Error creating CTA portfolio:', error);
        return null;
    }
}

// MAIN: Publish Smart+ Campaign with Spark Ads
async function publishSmartCampaign() {
    console.log('=== PUBLISHING SMART+ CAMPAIGN (SPARK ADS) ===');

    // Get form values
    const campaignName = document.getElementById('campaign-name').value.trim();
    const budget = parseFloat(document.getElementById('budget').value);
    const ageTargeting = document.getElementById('age-targeting').value;
    const pixelId = document.getElementById('pixel-select').value;
    const pixelEvent = document.getElementById('pixel-event').value;
    const landingPageUrl = document.getElementById('landing-page-url').value.trim();

    // Collect ad texts (not used in Spark Ads, but kept for compatibility)
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
    if (!state.currentIdentity) {
        showToast('Please select a linked TikTok account', 'error');
        return;
    }
    if (state.currentIdentity.identity_type === 'CUSTOMIZED_USER') {
        showToast('Smart+ Lead Gen requires TT_USER or AUTH_CODE (Spark Ads)', 'error');
        return;
    }
    if (state.selectedPosts.length === 0) {
        showToast('Please select at least one TikTok post', 'error');
        return;
    }
    if (!landingPageUrl) {
        showToast('Please enter a landing page URL', 'error');
        return;
    }
    if (!pixelId) {
        showToast('Please select a pixel (required for Website optimization)', 'error');
        return;
    }

    // Disable publish button
    const publishBtn = document.getElementById('publish-btn');
    publishBtn.disabled = true;
    publishBtn.textContent = 'Publishing...';

    showLoading();

    try {
        // Step 1: Create CTA Portfolio (Dynamic CTA)
        console.log('Creating CTA Portfolio...');
        const ctaPortfolioId = await createCTAPortfolio();
        console.log('CTA Portfolio:', ctaPortfolioId || 'Not created');

        // Step 2: Calculate schedule times (UTC)
        const now = new Date();
        const startTime = new Date(now.getTime() + 3600000); // 1 hour from now
        const endTime = new Date(now.getTime() + 365 * 24 * 3600000); // 1 year from now

        const formatUTC = (date) => {
            return date.toISOString().replace('T', ' ').substring(0, 19);
        };

        // Step 3: Build the Smart+ Campaign payload for Spark Ads
        const payload = {
            action: 'publish_smart_plus_campaign',
            campaign_name: campaignName,
            budget: budget,
            spc_audience_age: ageTargeting,

            // Identity (TT_USER, AUTH_CODE, or BC_AUTH_TT)
            identity_id: state.currentIdentity.identity_id,
            identity_type: state.currentIdentity.identity_type,

            // TikTok posts (Spark Ads)
            tiktok_posts: state.selectedPosts,

            // Destination
            landing_page_url: landingPageUrl,

            // Targeting
            location_ids: ['6252001'], // United States

            // Schedule
            schedule_start_time: formatUTC(startTime),
            schedule_end_time: formatUTC(endTime),

            // Pixel (required for Website)
            pixel_id: pixelId,
            optimization_event: pixelEvent || 'FORM',

            // Promotion type
            promotion_target_type: 'EXTERNAL_WEBSITE',
            optimization_goal: 'CONVERT'
        };

        // Add CTA portfolio if created
        if (ctaPortfolioId) {
            payload.call_to_action_id = ctaPortfolioId;
        }

        console.log('Smart+ Campaign Payload:', JSON.stringify(payload, null, 2));

        // Step 4: Call the API
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        console.log('API Response:', result);

        if (result.success) {
            showToast('Smart+ Campaign published successfully!', 'success');

            if (result.data && result.data.campaign_id) {
                alert(`🎉 Campaign Created Successfully!\n\nCampaign ID: ${result.data.campaign_id}\n\nYour Smart+ Lead Generation Campaign is now live with Spark Ads!`);
            }

            setTimeout(() => {
                window.location.href = 'campaign-select.php';
            }, 2000);
        } else {
            showToast(result.message || 'Failed to publish campaign', 'error');
            console.error('Campaign creation failed:', result);
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
    const loading = document.getElementById('loading');
    if (loading) loading.style.display = 'flex';
}

function hideLoading() {
    const loading = document.getElementById('loading');
    if (loading) loading.style.display = 'none';
}

function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    if (toast) {
        toast.textContent = message;
        toast.className = 'toast show ' + type;

        setTimeout(() => {
            toast.className = 'toast';
        }, 3000);
    } else {
        console.log(`[${type}] ${message}`);
    }
}
