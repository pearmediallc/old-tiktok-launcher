<!-- CAMPAIGNS VIEW (My Campaigns) -->
<div id="campaigns-view">
    <!-- Campaigns Header -->
    <div class="campaigns-header">
        <h2 style="font-size:20px;font-weight:700;color:#1e293b;">My Campaigns</h2>
        <div class="campaigns-actions">
            <button class="btn-secondary" onclick="refreshCampaignList()" style="font-size:13px;padding:8px 16px;">Refresh</button>
        </div>
    </div>

    <!-- Campaign Filters -->
    <div class="campaign-filters">
        <button class="campaign-filter-btn active" data-filter="all" onclick="<?php echo ($view === 'campaigns') ? 'filterCampaignsByStatusShell(\'all\')' : 'filterCampaignsByStatus(\'all\')'; ?>">
            All <span class="filter-count" id="count-all">0</span>
        </button>
        <button class="campaign-filter-btn" data-filter="active" onclick="<?php echo ($view === 'campaigns') ? 'filterCampaignsByStatusShell(\'active\')' : 'filterCampaignsByStatus(\'active\')'; ?>">
            Active <span class="filter-count" id="count-active">0</span>
        </button>
        <button class="campaign-filter-btn" data-filter="inactive" onclick="<?php echo ($view === 'campaigns') ? 'filterCampaignsByStatusShell(\'inactive\')' : 'filterCampaignsByStatus(\'inactive\')'; ?>">
            Inactive <span class="filter-count" id="count-inactive">0</span>
        </button>
    </div>

    <!-- Date Range Filter -->
    <div class="date-range-filter-container">
        <div class="date-range-presets">
            <button class="date-preset-btn active" data-preset="today" onclick="setDatePreset('today')">Today</button>
            <button class="date-preset-btn" data-preset="yesterday" onclick="setDatePreset('yesterday')">Yesterday</button>
            <button class="date-preset-btn" data-preset="7days" onclick="setDatePreset('7days')">Last 7 Days</button>
            <button class="date-preset-btn" data-preset="30days" onclick="setDatePreset('30days')">Last 30 Days</button>
            <button class="date-preset-btn" data-preset="custom" onclick="toggleCustomDatePicker()">Custom</button>
        </div>
        <div class="date-range-picker" id="date-range-picker" style="display: none;">
            <div class="date-input-group">
                <label>From</label>
                <input type="date" id="date-from">
            </div>
            <div class="date-input-group">
                <label>To</label>
                <input type="date" id="date-to">
            </div>
            <button class="btn-apply-date" onclick="applyCustomDateRange()">Apply</button>
        </div>
        <div class="date-range-display">
            <span class="date-range-label">Showing data for:</span>
            <span class="date-range-value" id="date-range-display">Today</span>
        </div>
    </div>

    <!-- Bulk Actions Bar -->
    <div class="bulk-actions-bar" id="bulk-actions-bar">
        <div class="bulk-select-controls">
            <label class="select-all-checkbox">
                <input type="checkbox" id="select-all-campaigns" onchange="toggleSelectAllCampaigns()">
                <span>Select All</span>
            </label>
            <span class="selected-count" id="selected-campaigns-count">0 selected</span>
        </div>
        <div class="bulk-action-buttons" id="bulk-action-buttons" style="display: none;">
            <button class="btn-bulk-action btn-enable" onclick="bulkToggleCampaigns('ENABLE')">
                <span class="btn-icon">&#9654;</span> Turn ON Selected
            </button>
            <button class="btn-bulk-action btn-disable" onclick="bulkToggleCampaigns('DISABLE')">
                <span class="btn-icon">&#9208;</span> Turn OFF Selected
            </button>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="campaign-search-container">
        <input type="text"
               id="campaign-search-input"
               class="campaign-search-input"
               placeholder="Search campaigns by name..."
               oninput="<?php echo ($view === 'campaigns') ? 'searchCampaignsShell()' : 'searchCampaigns()'; ?>">
    </div>

    <!-- ============================
         SINGLE ACCOUNT CAMPAIGNS
         ============================ -->
    <div id="single-account-campaigns">
        <!-- Balance card for single account (populated by JS) -->
        <div id="single-account-balance-card" class="single-account-balance-card" style="display:none;"></div>

        <div class="campaign-metrics-table-container">
            <!-- Loading State -->
            <div class="campaign-loading" id="campaign-loading">
                <div class="spinner"></div>
                <p>Loading campaigns with metrics...</p>
            </div>

            <!-- Empty State (hidden by default) -->
            <div class="campaign-empty-state" id="campaign-empty-state" style="display: none;">
                <div class="empty-icon" style="font-size:48px;margin-bottom:15px;">📭</div>
                <h3>No campaigns found</h3>
                <p>You haven't created any campaigns yet, or no campaigns match your filter.</p>
                <a href="app-shell.php?view=create-smart" class="btn-primary" style="text-decoration:none;display:inline-block;margin-top:10px;">Create Your First Campaign</a>
            </div>

            <!-- Metrics Table -->
            <div class="metrics-table-wrapper" id="metrics-table-wrapper" style="display: none;">
                <table class="metrics-table" id="campaign-metrics-table">
                    <thead>
                        <tr>
                            <th class="col-checkbox"><input type="checkbox" id="select-all-campaigns-table" onchange="toggleSelectAllCampaigns()"></th>
                            <th class="col-toggle">On/Off</th>
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
                            <th class="col-results">Results</th>
                            <th class="col-actions">Duplicate</th>
                        </tr>
                    </thead>
                    <tbody id="campaign-table-body">
                        <!-- Campaign rows will be rendered here by JavaScript -->
                    </tbody>
                    <tfoot id="campaign-table-totals">
                        <!-- Totals will be rendered here by JavaScript -->
                    </tfoot>
                </table>
            </div>

            <!-- Legacy Campaign Cards Container (hidden) -->
            <div id="campaign-cards-container" style="display: none;"></div>
        </div>
    </div>

    <!-- ============================
         MULTI-ACCOUNT CAMPAIGNS
         ============================ -->
    <div id="multi-account-campaigns-container" class="multi-account-campaigns-container" style="display: none;">
        <!-- Filled by shell.js when multiple accounts are selected -->
    </div>
</div>
