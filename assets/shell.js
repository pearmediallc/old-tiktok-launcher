// ============================================
// APP SHELL JAVASCRIPT
// Account management, multi-account campaigns,
// sidebar logic, and search filtering.
// ============================================
(function() {
    'use strict';

    // ============================================
    // STATE
    // ============================================
    window.shellState = {
        selectedAccountIds: [],
        multiAccountCampaigns: [],
        multiAccountMode: false
    };

    // ============================================
    // LOGOUT
    // ============================================
    window.shellLogout = function() {
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'logout' })
        }).then(() => {
            localStorage.removeItem('tiktok_oauth_token');
            window.location.href = 'index.php';
        }).catch(() => {
            window.location.href = 'index.php';
        });
    };

    // ============================================
    // SINGLE-ACCOUNT SELECTION (Create views)
    // ============================================
    window.showAccountDropdown = function() {
        const dropdown = document.getElementById('shell-account-dropdown');
        if (dropdown) dropdown.style.display = 'block';
    };

    window.hideAccountDropdown = function() {
        const dropdown = document.getElementById('shell-account-dropdown');
        if (dropdown) dropdown.style.display = 'none';
    };

    window.filterAccountOptions = function() {
        const input = document.getElementById('shell-account-search');
        if (!input) return;
        const term = input.value.toLowerCase();
        const options = document.querySelectorAll('#shell-account-dropdown .account-option');
        options.forEach(opt => {
            const search = (opt.dataset.search || '').toLowerCase();
            opt.style.display = search.includes(term) ? '' : 'none';
        });
        showAccountDropdown();
    };

    window.selectAccount = function(advertiserId, displayName) {
        const input = document.getElementById('shell-account-search');
        if (input) input.value = displayName;
        hideAccountDropdown();

        // Determine campaign type from current view
        const urlParams = new URLSearchParams(window.location.search);
        const currentView = urlParams.get('view') || 'campaigns';
        let campaignType = 'smart';
        if (currentView === 'create-manual') campaignType = 'manual';

        // Call API to set the advertiser in session
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'set_oauth_advertiser',
                advertiser_id: advertiserId,
                campaign_type: campaignType
            })
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                // Reload to reflect new account
                window.location.href = 'app-shell.php?view=' + currentView;
            } else {
                alert('Failed to switch account: ' + (result.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Error switching account:', err);
            alert('Failed to switch account');
        });
    };

    // ============================================
    // MULTI-SELECT DROPDOWN (Campaigns view)
    // ============================================
    window.toggleMultiSelect = function() {
        const options = document.getElementById('multi-select-options');
        const trigger = document.querySelector('.multi-select-trigger');
        if (!options) return;

        const isOpen = options.style.display !== 'none';
        options.style.display = isOpen ? 'none' : 'block';
        if (trigger) trigger.classList.toggle('open', !isOpen);
    };

    window.filterMultiAccounts = function(term) {
        term = term.toLowerCase();
        const options = document.querySelectorAll('#multi-select-options .multi-select-option:not(.select-all)');
        options.forEach(opt => {
            const search = (opt.dataset.search || '').toLowerCase();
            opt.style.display = search.includes(term) ? '' : 'none';
        });
    };

    window.toggleAllAccounts = function() {
        const selectAll = document.getElementById('select-all-accounts');
        if (!selectAll) return;
        const checkboxes = document.querySelectorAll('.account-checkbox');
        checkboxes.forEach(cb => { cb.checked = selectAll.checked; });
        updateMultiAccountSelection();
    };

    window.updateMultiAccountSelection = function() {
        const checkboxes = document.querySelectorAll('.account-checkbox:checked');
        const selectedIds = Array.from(checkboxes).map(cb => cb.value);
        const selectedNames = Array.from(checkboxes).map(cb => cb.dataset.name);
        window.shellState.selectedAccountIds = selectedIds;

        // Update trigger label
        const label = document.getElementById('multi-select-label');
        const countBadge = document.getElementById('multi-select-count-badge');
        if (label) {
            if (selectedIds.length === 0) {
                label.textContent = 'Select accounts...';
            } else if (selectedIds.length === 1) {
                label.textContent = selectedNames[0] || 'Account';
            } else {
                label.textContent = selectedIds.length + ' accounts selected';
            }
        }
        if (countBadge) {
            countBadge.textContent = selectedIds.length;
            countBadge.style.display = selectedIds.length > 0 ? 'inline' : 'none';
        }

        // Update "select all" checkbox state
        const allCheckboxes = document.querySelectorAll('.account-checkbox');
        const selectAllCb = document.getElementById('select-all-accounts');
        if (selectAllCb) {
            selectAllCb.checked = selectedIds.length === allCheckboxes.length && allCheckboxes.length > 0;
            selectAllCb.indeterminate = selectedIds.length > 0 && selectedIds.length < allCheckboxes.length;
        }

        // Float selected accounts to the top of the dropdown
        const optionsContainer = document.getElementById('multi-select-options');
        if (optionsContainer) {
            const accountOptions = Array.from(optionsContainer.querySelectorAll('.multi-select-option:not(.select-all)'));
            accountOptions.sort((a, b) => {
                const aChecked = a.querySelector('.account-checkbox')?.checked ? 1 : 0;
                const bChecked = b.querySelector('.account-checkbox')?.checked ? 1 : 0;
                return bChecked - aChecked; // checked first
            });
            accountOptions.forEach(opt => optionsContainer.appendChild(opt));
        }

        // Decide mode: single vs multi
        if (selectedIds.length <= 1 && selectedIds.length > 0) {
            // Single account mode - use smart-campaign.js native loading
            window.shellState.multiAccountMode = false;
            const singleContainer = document.getElementById('single-account-campaigns');
            const multiContainer = document.getElementById('multi-account-campaigns-container');
            if (singleContainer) singleContainer.style.display = '';
            if (multiContainer) { multiContainer.style.display = 'none'; multiContainer.innerHTML = ''; }

            // Switch account if different from current
            const currentAdvId = window.TIKTOK_ADVERTISER_ID || '';
            if (selectedIds[0] !== currentAdvId) {
                switchAndReload(selectedIds[0]);
            }
        } else if (selectedIds.length > 1) {
            // Multi-account mode
            window.shellState.multiAccountMode = true;
            const singleContainer = document.getElementById('single-account-campaigns');
            const multiContainer = document.getElementById('multi-account-campaigns-container');
            if (singleContainer) singleContainer.style.display = 'none';
            if (multiContainer) multiContainer.style.display = 'block';

            loadMultiAccountCampaigns();
        } else {
            // Nothing selected
            window.shellState.multiAccountMode = false;
            const singleContainer = document.getElementById('single-account-campaigns');
            const multiContainer = document.getElementById('multi-account-campaigns-container');
            if (singleContainer) singleContainer.style.display = '';
            if (multiContainer) { multiContainer.style.display = 'none'; multiContainer.innerHTML = ''; }
        }
    };

    function switchAndReload(advertiserId) {
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'set_oauth_advertiser',
                advertiser_id: advertiserId,
                campaign_type: 'smart'
            })
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                window.location.href = 'app-shell.php?view=campaigns';
            }
        });
    }

    // ============================================
    // MULTI-ACCOUNT CAMPAIGN LOADING
    // ============================================
    window.loadMultiAccountCampaigns = async function() {
        const selectedIds = window.shellState.selectedAccountIds;
        if (selectedIds.length < 2) return;

        const container = document.getElementById('multi-account-campaigns-container');
        if (!container) return;

        // Show loading for each account
        container.innerHTML = selectedIds.map(id => {
            const name = getAccountName(id);
            return `
                <div class="account-group" data-advertiser-id="${escapeAttr(id)}">
                    <div class="account-group-header">
                        <h3 class="account-group-name">${escapeHtml(name)}</h3>
                        <div class="account-group-meta">
                            <span class="account-group-count">Loading...</span>
                        </div>
                    </div>
                    <div class="account-group-body">
                        <div class="campaign-loading" style="display: flex; flex-direction: column; align-items: center; padding: 30px;">
                            <div class="spinner"></div>
                            <p style="margin-top:10px;color:#64748b;">Loading campaigns...</p>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Get date range from smart-campaign.js state (if available) or default to today
        let dateRange;
        if (typeof getCurrentDateRange === 'function') {
            dateRange = getCurrentDateRange();
        } else {
            const today = new Date().toISOString().split('T')[0];
            dateRange = { start_date: today, end_date: today };
        }

        // Get current filter and search state from smart-campaign.js
        const statusFilter = (typeof state !== 'undefined' && state.campaignFilter) ? state.campaignFilter : 'all';
        const searchQuery = (typeof state !== 'undefined' && state.campaignSearchQuery) ? state.campaignSearchQuery : '';

        // Fetch campaigns for all selected accounts in parallel
        const promises = selectedIds.map(async (advertiserId) => {
            try {
                const response = await fetch('api-smartplus.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_campaigns_with_metrics',
                        _advertiser_id: advertiserId,
                        ...dateRange
                    })
                });
                const result = await response.json();
                return {
                    advertiserId,
                    accountName: getAccountName(advertiserId),
                    campaigns: result.success ? (result.campaigns || []) : [],
                    error: result.success ? null : (result.message || 'Failed to load')
                };
            } catch (err) {
                return {
                    advertiserId,
                    accountName: getAccountName(advertiserId),
                    campaigns: [],
                    error: err.message
                };
            }
        });

        const results = await Promise.all(promises);
        window.shellState.multiAccountCampaigns = results;

        // Fetch balances for all selected accounts in parallel
        const balancePromises = selectedIds.map(async (id) => {
            const bal = await fetchAccountBalance(id);
            if (bal) {
                window.shellState.accountBalances = window.shellState.accountBalances || {};
                window.shellState.accountBalances[id] = bal;
            }
        });
        await Promise.all(balancePromises);

        // Render
        renderMultiAccountCampaigns(results, statusFilter, searchQuery);
    };

    window.renderMultiAccountCampaigns = function(accountResults, statusFilter, searchQuery) {
        const container = document.getElementById('multi-account-campaigns-container');
        if (!container) return;

        statusFilter = statusFilter || 'all';
        searchQuery = searchQuery || '';

        container.innerHTML = accountResults.map(account => {
            let campaigns = [...account.campaigns];

            // Apply status filter
            if (statusFilter === 'active') {
                campaigns = campaigns.filter(c => c.operation_status === 'ENABLE');
            } else if (statusFilter === 'inactive') {
                campaigns = campaigns.filter(c => c.operation_status === 'DISABLE');
            }

            // Apply search
            if (searchQuery) {
                const q = searchQuery.toLowerCase();
                campaigns = campaigns.filter(c =>
                    (c.campaign_name || '').toLowerCase().includes(q) ||
                    (c.campaign_id || '').toLowerCase().includes(q)
                );
            }

            // Sort by spend descending
            campaigns.sort((a, b) => (parseFloat(b.spend) || 0) - (parseFloat(a.spend) || 0));

            // Calculate totals
            const totalSpend = campaigns.reduce((sum, c) => sum + (parseFloat(c.spend) || 0), 0);
            const activeCount = account.campaigns.filter(c => c.operation_status === 'ENABLE').length;
            const inactiveCount = account.campaigns.filter(c => c.operation_status === 'DISABLE').length;

            // Get balance info if available — use campaign spend as fallback for totalCost
            const balances = window.shellState.accountBalances || {};
            const bal = balances[account.advertiserId];
            const spendAmount = bal ? (bal.totalCost > 0 ? bal.totalCost : totalSpend) : totalSpend;
            const spendCurrency = bal ? bal.currency : 'USD';
            const balanceHtml = bal ? `
                <div class="account-group-balance-card">
                    <span class="agb-item agb-available">Bal: ${formatCurrency(bal.balance, spendCurrency)}</span>
                    <span class="agb-item agb-spent">Spent: ${formatCurrency(spendAmount, spendCurrency)}</span>
                    ${bal.grantBalance > 0 ? `<span class="agb-item agb-credits">Credits: ${formatCurrency(bal.grantBalance, spendCurrency)}</span>` : ''}
                </div>` : `
                <div class="account-group-balance-card">
                    <span class="agb-item agb-spent">Spent: $${totalSpend.toFixed(2)}</span>
                </div>`;

            // Render group
            return `
                <div class="account-group" data-advertiser-id="${escapeAttr(account.advertiserId)}">
                    <div class="account-group-header">
                        <h3 class="account-group-name">${escapeHtml(account.accountName)}</h3>
                        <div class="account-group-meta">
                            ${balanceHtml}
                            <span class="account-group-count">${campaigns.length} campaign${campaigns.length !== 1 ? 's' : ''}</span>
                        </div>
                    </div>
                    <div class="account-group-body">
                        ${account.error ? `<div style="padding:20px;color:#dc2626;text-align:center;">Error: ${escapeHtml(account.error)}</div>` : ''}
                        ${campaigns.length === 0 && !account.error
                            ? `<div style="padding:30px;text-align:center;color:#94a3b8;">No campaigns found</div>`
                            : campaigns.length > 0
                                ? renderMultiAccountTable(campaigns, account.advertiserId)
                                : ''
                        }
                    </div>
                </div>
            `;
        }).join('');

        // Update the filter counts to reflect all accounts combined
        updateMultiAccountCounts(accountResults, statusFilter);
    };

    function renderMultiAccountTable(campaigns, advertiserId) {
        const rows = campaigns.map(campaign => {
            const isActive = campaign.operation_status === 'ENABLE';
            const statusClass = isActive ? 'active' : 'inactive';
            const statusLabel = isActive ? 'Active' : 'Paused';
            const toggleClass = isActive ? 'on' : '';
            const budget = campaign.budget ? '$' + parseFloat(campaign.budget).toFixed(2) : '-';

            const smartPlusBadge = campaign.is_smart_performance_campaign
                ? '<span class="smart-badge-small">Smart+</span>'
                : '';

            return `
                <tr class="row-campaign" data-campaign-id="${escapeAttr(campaign.campaign_id)}">
                    <td class="col-toggle">
                        <div class="toggle-multi ${toggleClass}"
                             data-campaign-id="${escapeAttr(campaign.campaign_id)}"
                             data-advertiser-id="${escapeAttr(advertiserId)}"
                             data-status="${campaign.operation_status}"
                             onclick="toggleMultiAccountCampaign('${escapeAttr(campaign.campaign_id)}', '${campaign.operation_status}', '${escapeAttr(advertiserId)}')"
                             title="${isActive ? 'Click to disable' : 'Click to enable'}">
                            <div class="toggle-slider"></div>
                        </div>
                    </td>
                    <td class="col-name">
                        <div class="name-cell">
                            <span class="entity-icon">📢</span>
                            <span class="entity-name">${escapeHtml(campaign.campaign_name)}</span>
                            ${smartPlusBadge}
                        </div>
                    </td>
                    <td class="col-status">
                        <span class="status-badge-table ${statusClass}">${statusLabel}</span>
                    </td>
                    <td class="col-budget" style="text-align: right;">${budget}</td>
                    <td class="col-spend" style="text-align: right;">$${(parseFloat(campaign.spend) || 0).toFixed(2)}</td>
                    <td class="col-cpc" style="text-align: right;">$${(parseFloat(campaign.cpc) || 0).toFixed(2)}</td>
                    <td class="col-impressions" style="text-align: right;">${formatShellNumber(campaign.impressions)}</td>
                    <td class="col-clicks" style="text-align: right;">${formatShellNumber(campaign.clicks)}</td>
                    <td class="col-ctr" style="text-align: right;">${formatShellPercent(campaign.ctr)}</td>
                    <td class="col-conversions" style="text-align: right;">${formatShellNumber(campaign.conversions)}</td>
                    <td class="col-cpr" style="text-align: right;">$${(parseFloat(campaign.cost_per_result) || 0).toFixed(2)}</td>
                </tr>
            `;
        }).join('');

        return `
            <div class="metrics-table-wrapper" style="display:block;">
                <table class="metrics-table">
                    <thead>
                        <tr>
                            <th class="col-toggle">ON/OFF</th>
                            <th class="col-name">Name</th>
                            <th class="col-status">Status</th>
                            <th class="col-budget">Budget</th>
                            <th class="col-spend">Cost</th>
                            <th class="col-cpc">CPC</th>
                            <th class="col-impressions">Impressions</th>
                            <th class="col-clicks">Clicks</th>
                            <th class="col-ctr">CTR</th>
                            <th class="col-conversions">Conversions</th>
                            <th class="col-cpr">Cost/Result</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    function updateMultiAccountCounts(accountResults, currentFilter) {
        let allCount = 0, activeCount = 0, inactiveCount = 0;
        accountResults.forEach(acc => {
            allCount += acc.campaigns.length;
            activeCount += acc.campaigns.filter(c => c.operation_status === 'ENABLE').length;
            inactiveCount += acc.campaigns.filter(c => c.operation_status === 'DISABLE').length;
        });

        const countAll = document.getElementById('count-all');
        const countActive = document.getElementById('count-active');
        const countInactive = document.getElementById('count-inactive');
        if (countAll) countAll.textContent = allCount;
        if (countActive) countActive.textContent = activeCount;
        if (countInactive) countInactive.textContent = inactiveCount;
    }

    // ============================================
    // MULTI-ACCOUNT: Toggle individual campaign ON/OFF
    // ============================================
    window.toggleMultiAccountCampaign = async function(campaignId, currentStatus, advertiserId) {
        const toggleEl = document.querySelector(`.toggle-multi[data-campaign-id="${campaignId}"][data-advertiser-id="${advertiserId}"]`);
        const rowEl = toggleEl ? toggleEl.closest('tr') : null;
        if (!toggleEl) return;

        toggleEl.classList.add('loading');

        const newStatus = currentStatus === 'ENABLE' ? 'DISABLE' : 'ENABLE';

        try {
            const response = await fetch('api-smartplus.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_campaign_status',
                    campaign_id: campaignId,
                    status: newStatus,
                    _advertiser_id: advertiserId
                })
            });
            const result = await response.json();

            if (result.success) {
                // Update local state in multiAccountCampaigns
                const accountData = window.shellState.multiAccountCampaigns.find(a => a.advertiserId === advertiserId);
                if (accountData) {
                    const campaign = accountData.campaigns.find(c => c.campaign_id === campaignId);
                    if (campaign) campaign.operation_status = newStatus;
                }

                // Update toggle UI
                toggleEl.classList.remove('loading');
                toggleEl.classList.toggle('on', newStatus === 'ENABLE');
                toggleEl.dataset.status = newStatus;
                toggleEl.setAttribute('onclick', `toggleMultiAccountCampaign('${campaignId}', '${newStatus}', '${advertiserId}')`);
                toggleEl.title = newStatus === 'ENABLE' ? 'Click to disable' : 'Click to enable';

                // Update status badge in same row
                if (rowEl) {
                    const badge = rowEl.querySelector('.status-badge-table');
                    if (badge) {
                        badge.className = `status-badge-table ${newStatus === 'ENABLE' ? 'active' : 'inactive'}`;
                        badge.textContent = newStatus === 'ENABLE' ? 'Active' : 'Paused';
                    }
                }

                // Update filter counts
                updateMultiAccountCounts(window.shellState.multiAccountCampaigns);

                if (typeof showToast === 'function') {
                    showToast(`Campaign ${newStatus === 'ENABLE' ? 'enabled' : 'disabled'} successfully`, 'success');
                }
            } else {
                throw new Error(result.message || 'Failed to update status');
            }
        } catch (error) {
            toggleEl.classList.remove('loading');
            console.error('Error toggling campaign:', error);
            if (typeof showToast === 'function') {
                showToast('Failed to update campaign: ' + error.message, 'error');
            }
        }
    };

    // ============================================
    // MULTI-ACCOUNT: Hook into filter/search
    // ============================================
    // Override filter and search to also update multi-account view
    const originalFilterByStatus = window.filterCampaignsByStatus;
    window.filterCampaignsByStatusShell = function(status) {
        // Call original if available (for single-account mode)
        if (typeof originalFilterByStatus === 'function' && !window.shellState.multiAccountMode) {
            originalFilterByStatus(status);
        }

        // Update multi-account if in that mode
        if (window.shellState.multiAccountMode && window.shellState.multiAccountCampaigns.length > 0) {
            // Update button states
            document.querySelectorAll('.campaign-filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.filter === status) btn.classList.add('active');
            });

            const searchQuery = (typeof state !== 'undefined' && state.campaignSearchQuery) ? state.campaignSearchQuery : '';
            renderMultiAccountCampaigns(window.shellState.multiAccountCampaigns, status, searchQuery);
        }
    };

    window.searchCampaignsShell = function() {
        const searchInput = document.getElementById('campaign-search-input');
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

        if (window.shellState.multiAccountMode && window.shellState.multiAccountCampaigns.length > 0) {
            const activeBtn = document.querySelector('.campaign-filter-btn.active');
            const statusFilter = activeBtn ? activeBtn.dataset.filter : 'all';
            renderMultiAccountCampaigns(window.shellState.multiAccountCampaigns, statusFilter, query);
        }

        // Also call original for single-account mode
        if (typeof searchCampaigns === 'function' && !window.shellState.multiAccountMode) {
            searchCampaigns();
        }
    };

    // ============================================
    // MULTI-ACCOUNT: Hook into date range changes
    // ============================================
    // Listen for date range changes and reload multi-account
    window.onShellDateRangeChange = function() {
        if (window.shellState.multiAccountMode) {
            loadMultiAccountCampaigns();
        }
    };

    // ============================================
    // ACCOUNT BALANCE / SPENDING DISPLAY
    // ============================================

    function formatCurrency(amount, currency) {
        currency = currency || 'USD';
        const symbols = { 'USD': '$', 'EUR': '€', 'GBP': '£', 'JPY': '¥', 'CNY': '¥', 'KRW': '₩', 'INR': '₹' };
        const sym = symbols[currency] || currency + ' ';
        if (amount >= 1000000) return sym + (amount / 1000000).toFixed(2) + 'M';
        if (amount >= 10000) return sym + (amount / 1000).toFixed(1) + 'K';
        return sym + amount.toFixed(2);
    }

    // Fetch and display balance for the currently selected account
    window.loadAccountBalance = async function(advertiserId) {
        advertiserId = advertiserId || window.TIKTOK_ADVERTISER_ID || '';
        if (!advertiserId) return;

        const display = document.getElementById('account-balance-display');
        if (!display) return;

        try {
            const response = await fetch('api-smartplus.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_account_balance',
                    _advertiser_id: advertiserId
                })
            });
            const result = await response.json();

            if (result.success && result.data) {
                const balance = parseFloat(result.data.total_balance) || 0;
                const totalCost = parseFloat(result.data.total_cost) || 0;
                const grantBalance = parseFloat(result.data.grant_balance) || 0;
                const currency = result.data.currency || 'USD';

                // Update display
                document.getElementById('balance-available').textContent = formatCurrency(balance, currency);
                document.getElementById('balance-total-spent').textContent = formatCurrency(totalCost, currency);

                if (grantBalance > 0) {
                    document.getElementById('balance-grant').textContent = formatCurrency(grantBalance, currency);
                    document.getElementById('balance-grant-wrapper').style.display = '';
                } else {
                    document.getElementById('balance-grant-wrapper').style.display = 'none';
                }

                display.style.display = 'flex';

                // Store for later use
                window.shellState.accountBalances = window.shellState.accountBalances || {};
                window.shellState.accountBalances[advertiserId] = { balance, totalCost, grantBalance, currency };
            }
        } catch (err) {
            console.warn('Could not load account balance:', err);
        }
    };

    // Fetch balance for a specific advertiser (used by multi-account view)
    async function fetchAccountBalance(advertiserId) {
        try {
            const response = await fetch('api-smartplus.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_account_balance',
                    _advertiser_id: advertiserId
                })
            });
            const result = await response.json();
            if (result.success && result.data) {
                return {
                    balance: parseFloat(result.data.total_balance) || 0,
                    totalCost: parseFloat(result.data.total_cost) || 0,
                    grantBalance: parseFloat(result.data.grant_balance) || 0,
                    currency: result.data.currency || 'USD'
                };
            }
        } catch (err) {
            console.warn('Could not fetch balance for', advertiserId, err);
        }
        return null;
    }

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================
    function getAccountName(advertiserId) {
        // Read from the multi-select option's data attribute
        const option = document.querySelector(`.account-checkbox[value="${advertiserId}"]`);
        if (option && option.dataset.name) return option.dataset.name;
        // Fallback: try the single-select options
        const singleOpt = document.querySelector(`.account-option[data-advertiser-id="${advertiserId}"]`);
        if (singleOpt) return singleOpt.querySelector('.account-option-name')?.textContent || 'Account ' + advertiserId;
        return 'Account ' + advertiserId;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeAttr(text) {
        if (!text) return '';
        return text.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function formatShellNumber(value) {
        const num = parseInt(value) || 0;
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
        return num.toLocaleString();
    }

    function formatShellPercent(value) {
        const num = parseFloat(value) || 0;
        return num.toFixed(2) + '%';
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            // Single-select dropdown
            const singleWrapper = document.querySelector('.account-dropdown-wrapper');
            if (singleWrapper && !singleWrapper.contains(e.target)) {
                hideAccountDropdown();
            }

            // Multi-select dropdown
            const multiDropdown = document.getElementById('multi-select-dropdown-wrapper');
            if (multiDropdown && !multiDropdown.contains(e.target)) {
                const options = document.getElementById('multi-select-options');
                const trigger = document.querySelector('.multi-select-trigger');
                if (options) options.style.display = 'none';
                if (trigger) trigger.classList.remove('open');
            }
        });

        // Pre-check current account in multi-select
        const currentAdvId = window.TIKTOK_ADVERTISER_ID || '';
        if (currentAdvId) {
            const cb = document.querySelector(`.account-checkbox[value="${currentAdvId}"]`);
            if (cb && !cb.checked) {
                cb.checked = true;
                // Update label but don't trigger multi-account load for single selection
                const label = document.getElementById('multi-select-label');
                if (label && cb.dataset.name) label.textContent = cb.dataset.name;
                window.shellState.selectedAccountIds = [currentAdvId];
            }

            // Load balance for current account
            loadAccountBalance(currentAdvId);
        }
    });

})();
