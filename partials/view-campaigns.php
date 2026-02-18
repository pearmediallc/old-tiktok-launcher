<!-- CAMPAIGNS VIEW (My Campaigns) -->
<div id="campaigns-view">
    <!-- Campaigns Header -->
    <div class="campaigns-header">
        <h2 style="font-size:20px;font-weight:700;color:#1e293b;">My Campaigns</h2>
        <div class="campaigns-actions">
            <button class="btn-secondary" onclick="refreshCampaignList()" style="font-size:13px;padding:8px 16px;">Refresh</button>
        </div>
    </div>

    <!-- Business Center Balance Card (populated by JS) -->
    <div id="bc-balance-container" class="bc-balance-container" style="display:none;"></div>

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
        <button class="campaign-filter-btn btn-rejected-filter" data-filter="rejected" onclick="<?php echo ($view === 'campaigns') ? 'showRejectedAdsShell()' : 'showRejectedAds()'; ?>">
            Rejected Ads <span class="filter-count" id="count-rejected" style="background:rgba(220,38,38,0.15);color:#dc2626;">0</span>
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
         REJECTED ADS PANEL
         ============================ -->
    <div id="rejected-ads-panel" style="display:none;">
        <div class="rejected-ads-header">
            <h3 style="font-size:18px;font-weight:700;color:#dc2626;margin:0;">Rejected Ads</h3>
            <button class="btn-secondary" onclick="hideRejectedAds()" style="font-size:13px;padding:6px 14px;">Back to Campaigns</button>
        </div>
        <div id="rejected-ads-loading" style="display:none;text-align:center;padding:30px;">
            <div class="spinner"></div>
            <p style="margin-top:10px;color:#64748b;">Loading rejected ads...</p>
        </div>
        <div id="rejected-ads-empty" style="display:none;text-align:center;padding:40px;color:#94a3b8;">
            <div style="font-size:48px;margin-bottom:15px;">&#10003;</div>
            <h3 style="color:#16a34a;">No Rejected Ads</h3>
            <p>All your ads are in good standing!</p>
        </div>
        <div id="rejected-ads-list"></div>
    </div>

    <!-- ============================
         MULTI-ACCOUNT CAMPAIGNS
         ============================ -->
    <div id="multi-account-campaigns-container" class="multi-account-campaigns-container" style="display: none;">
        <!-- Filled by shell.js when multiple accounts are selected -->
    </div>
</div>

<!-- ============================
     DUPLICATE CAMPAIGN MODAL
     (Must be outside campaigns-view for proper overlay)
     ============================ -->
<div id="duplicate-campaign-modal" class="modal" style="display: none;">
    <div class="modal-content duplicate-modal-content">
        <div class="modal-header">
            <h3>Duplicate Campaign</h3>
            <span class="modal-close" onclick="closeDuplicateCampaignModal()">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Campaign Info -->
            <div class="duplicate-campaign-info">
                <div class="info-row">
                    <span class="info-label">Campaign:</span>
                    <span class="info-value" id="duplicate-campaign-name">-</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Campaign ID:</span>
                    <span class="info-value" id="duplicate-campaign-id">-</span>
                </div>
            </div>

            <!-- Loading State -->
            <div id="duplicate-loading-state" style="display: none; text-align: center; padding: 30px;">
                <div class="spinner"></div>
                <p style="margin-top: 15px; color: #666;">Fetching campaign details...</p>
            </div>

            <!-- Duplicate Mode Selection (shown after loading) -->
            <div id="duplicate-mode-section" style="display: none;">
                <h4 style="margin-bottom: 15px;">Choose Duplication Mode</h4>
                <div class="duplicate-mode-options">
                    <label class="duplicate-mode-option selected" id="mode-option-same">
                        <input type="radio" name="duplicate_mode" value="same" checked onchange="toggleDuplicateMode('same')">
                        <div class="mode-option-content">
                            <span class="mode-icon">📋</span>
                            <div class="mode-details">
                                <span class="mode-title">Duplicate with Same Details</span>
                                <span class="mode-desc">Create exact copies with auto-numbered names</span>
                            </div>
                        </div>
                    </label>
                    <label class="duplicate-mode-option" id="mode-option-edit">
                        <input type="radio" name="duplicate_mode" value="edit" onchange="toggleDuplicateMode('edit')">
                        <div class="mode-option-content">
                            <span class="mode-icon">✏️</span>
                            <div class="mode-details">
                                <span class="mode-title">Duplicate and Edit Details</span>
                                <span class="mode-desc">Customize budget, landing page, and other settings</span>
                            </div>
                        </div>
                    </label>
                    <label class="duplicate-mode-option" id="mode-option-bulk">
                        <input type="radio" name="duplicate_mode" value="bulk" onchange="toggleDuplicateMode('bulk')">
                        <div class="mode-option-content">
                            <span class="mode-icon">🚀</span>
                            <div class="mode-details">
                                <span class="mode-title">Bulk Launch to Other Accounts</span>
                                <span class="mode-desc">Duplicate to multiple ad accounts with asset mapping</span>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Bulk Launch Section (for "bulk" mode) -->
            <div id="duplicate-bulk-section" style="display: none;">
                <h4 style="margin: 20px 0 15px;">Select Target Accounts</h4>
                <p style="color: #64748b; font-size: 13px; margin-bottom: 15px;">
                    Select the ad accounts where you want to duplicate this campaign. Configure each account's settings below.
                </p>
                <div class="dup-bulk-account-search" style="margin-bottom: 12px;">
                    <input type="text" id="dup-bulk-account-search-input"
                           placeholder="Search accounts by name or ID..."
                           oninput="filterDupBulkAccounts(this.value)"
                           style="width: 100%; padding: 10px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                    <span id="dup-bulk-search-results-count" style="display: block; margin-top: 6px; font-size: 12px; color: #6b7280;"></span>
                </div>
                <div id="dup-bulk-accounts-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px;">
                    <div class="loading-state" style="text-align: center; padding: 20px;">
                        <div class="spinner"></div>
                        <p style="margin-top: 10px; color: #64748b;">Loading accounts...</p>
                    </div>
                </div>
                <div id="dup-bulk-summary" style="margin-top: 15px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 600; color: #0284c7;">
                            <span id="dup-bulk-selected-count">0</span> accounts selected
                        </span>
                        <span style="font-size: 13px; color: #64748b;">
                            Total campaigns to create: <strong id="dup-bulk-total-campaigns">0</strong>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Campaign Details (shown after loading) -->
            <div id="duplicate-details-section" style="display: none;">
                <div class="duplicate-details-summary">
                    <h4>Campaign Structure</h4>
                    <div class="structure-item">
                        <span class="structure-icon">📢</span>
                        <span class="structure-label">Campaign:</span>
                        <span class="structure-value" id="dup-detail-campaign">-</span>
                    </div>
                    <div class="structure-item">
                        <span class="structure-icon">📦</span>
                        <span class="structure-label">Ad Group:</span>
                        <span class="structure-value" id="dup-detail-adgroup">-</span>
                    </div>
                    <div class="structure-item">
                        <span class="structure-icon">🎬</span>
                        <span class="structure-label">Ad:</span>
                        <span class="structure-value" id="dup-detail-ad">-</span>
                    </div>
                </div>

                <!-- Video/Creative Change Section (for "same" mode) -->
                <div id="duplicate-same-videos-section" class="form-group" style="margin-bottom: 15px; margin-top: 15px; padding: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">
                        🎬 Videos/Creatives
                    </label>
                    <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;">
                        Current videos from the original campaign. You can add or remove videos for the copies.
                    </p>
                    <div id="duplicate-same-current-videos" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                        <!-- Videos will be rendered here -->
                    </div>
                    <button type="button" onclick="openVideoSelectionModal('duplicate')" class="btn-secondary" style="width: 100%; padding: 12px; font-size: 14px;">
                        🔄 Change Videos
                    </button>
                </div>

                <!-- Number of Copies Input (for "same" mode) -->
                <div class="duplicate-count-section" id="duplicate-count-section">
                    <label for="duplicate-copy-count">Number of copies to create:</label>
                    <div class="count-input-wrapper">
                        <button type="button" class="count-btn minus" onclick="adjustDuplicateCount(-1)">−</button>
                        <input type="number" id="duplicate-copy-count" min="1" max="20" value="1"
                               onchange="updateDuplicatePreviewList()" oninput="updateDuplicatePreviewList()">
                        <button type="button" class="count-btn plus" onclick="adjustDuplicateCount(1)">+</button>
                    </div>
                    <small>Maximum 20 copies at a time</small>
                </div>

                <!-- Edit Details Section (for "edit" mode) -->
                <div id="duplicate-edit-section" style="display: none;">
                    <h4 style="margin-top: 20px; margin-bottom: 15px;">Edit Campaign Details</h4>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Campaign Name</label>
                        <input type="text" id="dup-edit-campaign-name" placeholder="Enter campaign name"
                               style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;"
                               oninput="updateDuplicatePreviewList()">
                    </div>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Daily Budget ($)</label>
                        <input type="number" id="dup-edit-budget" placeholder="50" min="20"
                               style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                        <small style="color: #666; margin-top: 4px; display: block;">Minimum $20 daily budget</small>
                    </div>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Landing Page URL</label>
                        <input type="url" id="dup-edit-landing-url" placeholder="https://example.com/landing-page"
                               style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Ad Text</label>
                        <textarea id="dup-edit-ad-text" placeholder="Enter ad text" rows="3"
                                  style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical;"></textarea>
                    </div>

                    <!-- Schedule Options -->
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Ad Group Schedule</label>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                            <label class="dup-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #1a1a1a; border-radius: 6px; cursor: pointer; margin-bottom: 8px;">
                                <input type="radio" name="dup_schedule_type" value="continuous" checked onchange="toggleDupScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="color: #1e293b; font-size: 13px;">Start now and run continuously</strong>
                                    <p style="margin: 2px 0 0; color: #64748b; font-size: 12px;">Ad group will start immediately</p>
                                </div>
                            </label>
                            <label class="dup-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 6px; cursor: pointer; margin-bottom: 8px;">
                                <input type="radio" name="dup_schedule_type" value="scheduled_start_only" onchange="toggleDupScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="color: #1e293b; font-size: 13px;">Schedule start time (run continuously)</strong>
                                    <p style="margin: 2px 0 0; color: #64748b; font-size: 12px;">Start at a specific date/time</p>
                                </div>
                            </label>
                            <label class="dup-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 6px; cursor: pointer;">
                                <input type="radio" name="dup_schedule_type" value="scheduled" onchange="toggleDupScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="color: #1e293b; font-size: 13px;">Set start and end time</strong>
                                    <p style="margin: 2px 0 0; color: #64748b; font-size: 12px;">Run during a specific time period</p>
                                </div>
                            </label>
                            <div id="dup-schedule-start-only-container" style="display: none; margin-top: 12px; padding: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <div class="form-group" style="margin-bottom: 10px;">
                                    <label style="font-weight: 500; color: #475569; font-size: 13px;">Start Date & Time <span style="font-weight: 400; color: #3b82f6;">(EST)</span></label>
                                    <input type="datetime-local" id="dup-schedule-start-only-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                </div>
                            </div>
                            <div id="dup-schedule-datetime-container" style="display: none; margin-top: 12px; padding: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label style="font-weight: 500; color: #475569; font-size: 13px;">Start <span style="font-weight: 400; color: #3b82f6;">(EST)</span></label>
                                        <input type="datetime-local" id="dup-schedule-start-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    </div>
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label style="font-weight: 500; color: #475569; font-size: 13px;">End <span style="font-weight: 400; color: #3b82f6;">(EST)</span></label>
                                        <input type="datetime-local" id="dup-schedule-end-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Number of copies to create</label>
                        <div class="count-input-wrapper">
                            <button type="button" class="count-btn minus" onclick="adjustDuplicateCount(-1)">−</button>
                            <input type="number" id="duplicate-edit-copy-count" min="1" max="20" value="1"
                                   onchange="updateDuplicatePreviewList()" oninput="updateDuplicatePreviewList()">
                            <button type="button" class="count-btn plus" onclick="adjustDuplicateCount(1)">+</button>
                        </div>
                        <small style="color: #666;">Maximum 20 copies at a time</small>
                    </div>

                    <!-- Video/Creative Change Section (for "edit" mode) -->
                    <div class="form-group" style="margin-bottom: 15px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">
                            🎬 Videos/Creatives
                        </label>
                        <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;">
                            Current videos from the original campaign. You can change them for the duplicates.
                        </p>
                        <div id="duplicate-current-videos" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                        </div>
                        <button type="button" onclick="openVideoSelectionModal('duplicate')" class="btn-secondary" style="width: 100%; padding: 12px; font-size: 14px;">
                            🔄 Change Videos
                        </button>
                    </div>
                </div>

                <!-- Preview of Names -->
                <div class="duplicate-preview-section">
                    <h4>Preview</h4>
                    <p class="preview-description">The following campaigns will be created:</p>
                    <div class="duplicate-preview-list" id="duplicate-preview-list">
                    </div>
                </div>

                <!-- What will be duplicated -->
                <div class="duplicate-includes-section" id="duplicate-includes-section">
                    <h4>Each copy will include:</h4>
                    <ul class="includes-list">
                        <li><span class="check-icon">✓</span> Campaign settings (budget, objective)</li>
                        <li><span class="check-icon">✓</span> Ad Group (targeting, pixel, schedule)</li>
                        <li><span class="check-icon">✓</span> Ad (videos, identity, CTA, landing URL)</li>
                    </ul>
                </div>
            </div>

            <!-- Progress Section -->
            <div id="duplicate-progress-section" style="display: none;">
                <div class="duplicate-progress-header">
                    <span>Creating duplicates...</span>
                    <span id="duplicate-progress-text">0 / 0</span>
                </div>
                <div class="duplicate-progress-bar-container">
                    <div class="duplicate-progress-bar" id="duplicate-progress-bar" style="width: 0%;"></div>
                </div>
                <div class="duplicate-progress-log" id="duplicate-progress-log">
                </div>
            </div>

            <!-- Success Section -->
            <div id="duplicate-success-section" style="display: none;">
                <div class="duplicate-success-icon">✅</div>
                <h4>Duplication Complete!</h4>
                <p id="duplicate-success-message">Successfully created 0 campaigns.</p>
                <div class="duplicate-results-summary" id="duplicate-results-summary">
                </div>
            </div>
        </div>
        <div class="modal-footer" id="duplicate-modal-footer">
            <button class="btn-secondary" onclick="closeDuplicateCampaignModal()">Cancel</button>
            <button class="btn-primary" id="duplicate-create-btn" onclick="executeDuplicateCampaign()" disabled>
                📋 Create Copies
            </button>
        </div>
    </div>
</div>

<!-- ============================
     VIDEO SELECTION MODAL
     (Used by duplicate campaign to change videos)
     ============================ -->
<div id="video-selection-modal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 900px; max-height: 85vh;">
        <div class="modal-header">
            <h3>🎬 Select Videos</h3>
            <button class="modal-close" onclick="closeVideoSelectionModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center;">
                <input type="text" id="video-modal-search" placeholder="Search videos by name..."
                       oninput="filterVideosInModal()"
                       style="flex: 1; min-width: 200px; padding: 10px 15px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                <button onclick="document.getElementById('video-modal-upload-input').click()"
                        style="display: flex; align-items: center; gap: 6px; padding: 10px 16px; background: #22c55e; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;"
                        onmouseover="this.style.background='#16a34a'" onmouseout="this.style.background='#22c55e'">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                    Upload
                </button>
                <button id="video-modal-refresh-btn" onclick="refreshVideoModalLibrary()"
                        style="display: flex; align-items: center; gap: 6px; padding: 10px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;"
                        onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                    <span id="video-modal-refresh-icon">&#x21bb;</span> Refresh
                </button>
                <input type="file" id="video-modal-upload-input" accept="video/*" multiple style="display: none;" onchange="handleBulkVideoUpload(event)">
                <div style="display: flex; align-items: center; gap: 10px; padding: 0 15px; background: #f8fafc; border-radius: 8px; height: 40px;">
                    <span style="font-weight: 600; color: #475569;">Selected:</span>
                    <span id="video-modal-count" style="font-size: 18px; font-weight: 700; color: #1e9df1;">0</span>
                </div>
            </div>
            <div style="display: flex; gap: 8px; margin-bottom: 15px;">
                <button onclick="selectAllVideosInModal()" style="padding: 6px 14px; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Select All</button>
                <button onclick="clearAllVideosInModal()" style="padding: 6px 14px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Clear All</button>
            </div>
            <div id="video-modal-upload-progress" style="display: none; margin-bottom: 20px; padding: 15px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                <div class="bulk-upload-header">
                    <span id="bulk-upload-title">Uploading videos...</span>
                    <span id="bulk-upload-count">0/0</span>
                </div>
                <div class="bulk-upload-bar-container">
                    <div id="bulk-upload-bar" class="bulk-upload-bar"></div>
                </div>
                <div id="bulk-upload-list" class="bulk-upload-list">
                </div>
            </div>
            <div id="video-modal-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; max-height: 450px; overflow-y: auto; padding: 5px;">
            </div>
            <div id="video-modal-empty" style="display: none; text-align: center; padding: 40px;">
                <div style="font-size: 48px; margin-bottom: 15px;">📹</div>
                <p style="color: #64748b; font-size: 16px;">No videos found in your media library.</p>
                <p style="color: #94a3b8; font-size: 14px;">Upload videos via TikTok Ads Manager first.</p>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-top: 1px solid #e2e8f0;">
            <div style="color: #64748b; font-size: 14px;">
                <span id="video-modal-total">0</span> videos available
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn-secondary" onclick="closeVideoSelectionModal()">Cancel</button>
                <button class="btn-primary" id="video-modal-confirm" onclick="confirmVideoSelection()">
                    ✓ Confirm Selection
                </button>
            </div>
        </div>
    </div>
</div>
