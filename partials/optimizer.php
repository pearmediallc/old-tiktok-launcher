<!-- OPTIMIZER VIEW -->
<style>
    .opt-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .opt-header h2 { font-size: 20px; font-weight: 700; color: #1e293b; }
    .opt-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
    .opt-stat-card { background: white; border-radius: 10px; padding: 16px; border: 1px solid #e2e8f0; }
    .opt-stat-label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .opt-stat-value { font-size: 28px; font-weight: 700; color: #1e293b; margin-top: 4px; }
    .opt-stat-value.green { color: #16a34a; }
    .opt-stat-value.red { color: #dc2626; }
    .opt-stat-value.blue { color: #2563eb; }
    .opt-stat-value.orange { color: #d97706; }

    .opt-tabs { display: flex; gap: 0; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; }
    .opt-tab { padding: 10px 20px; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
    .opt-tab:hover { color: #1e293b; }
    .opt-tab.active { color: #1e9df1; border-bottom-color: #1e9df1; }

    .opt-panel { display: none; }
    .opt-panel.active { display: block; }

    /* Rules Table */
    .opt-rules-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; }
    .opt-rules-table th { padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; }
    .opt-rules-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
    .opt-rules-table tr:hover { background: #f8fafc; }

    .opt-threshold-input { width: 80px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; font-weight: 600; text-align: center; }
    .opt-threshold-input:focus { outline: none; border-color: #1e9df1; box-shadow: 0 0 0 3px rgba(30,157,241,0.1); }

    .opt-toggle { width: 44px; height: 24px; background: #e2e8f0; border-radius: 12px; position: relative; cursor: pointer; transition: all 0.2s; }
    .opt-toggle.on { background: #22c55e; }
    .opt-toggle .opt-toggle-dot { width: 20px; height: 20px; background: white; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
    .opt-toggle.on .opt-toggle-dot { left: 22px; }

    .opt-source-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; }
    .opt-source-badge.tiktok { background: #e0f2fe; color: #0284c7; }
    .opt-source-badge.redtrack { background: #fef3c7; color: #b45309; }

    .opt-severity-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; }
    .opt-severity-badge.warning { background: #fef3c7; color: #b45309; }
    .opt-severity-badge.critical { background: #fee2e2; color: #dc2626; }

    /* Logs Table */
    .opt-logs-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; }
    .opt-logs-table th { padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; }
    .opt-logs-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .opt-logs-table tr:hover { background: #f8fafc; }

    .opt-action-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .opt-action-badge.pause { background: #fee2e2; color: #dc2626; }
    .opt-action-badge.resume { background: #dcfce7; color: #16a34a; }
    .opt-action-badge.rule_check { background: #f1f5f9; color: #64748b; }

    /* Monitored Campaigns */
    .opt-monitored-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; }
    .opt-monitored-table th { padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; }
    .opt-monitored-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .opt-monitored-table tr:hover { background: #f8fafc; }

    .opt-status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
    .opt-status-dot.active { background: #22c55e; }
    .opt-status-dot.paused { background: #dc2626; animation: pulse 1.5s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

    .opt-btn { padding: 6px 14px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
    .opt-btn:hover { transform: translateY(-1px); }
    .opt-btn-primary { background: #1e9df1; color: white; border-color: #1e9df1; }
    .opt-btn-primary:hover { background: #1a8ad8; }
    .opt-btn-danger { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
    .opt-btn-danger:hover { background: #dc2626; color: white; }
    .opt-btn-success { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
    .opt-btn-success:hover { background: #16a34a; color: white; }

    .opt-empty { text-align: center; padding: 40px; color: #94a3b8; }
    .opt-empty-icon { font-size: 48px; margin-bottom: 10px; }

    .opt-save-btn { padding: 8px 20px; background: #1e9df1; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .opt-save-btn:hover { background: #1a8ad8; }
    .opt-save-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    .opt-info-banner { padding: 12px 16px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; margin-bottom: 16px; font-size: 13px; color: #0369a1; display: flex; align-items: center; gap: 8px; }
</style>

<div id="optimizer-view">
    <div class="opt-header">
        <h2>Campaign Optimizer</h2>
        <div style="display: flex; gap: 8px;">
            <button class="opt-btn opt-btn-primary" onclick="forceOptimizerCheck()" id="opt-force-check-btn">
                Run Check Now
            </button>
            <button class="opt-btn" onclick="loadOptimizerData()">Refresh</button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="opt-stats">
        <div class="opt-stat-card">
            <div class="opt-stat-label">Monitored</div>
            <div class="opt-stat-value blue" id="opt-stat-monitored">0</div>
        </div>
        <div class="opt-stat-card">
            <div class="opt-stat-label">Paused by Rules</div>
            <div class="opt-stat-value red" id="opt-stat-paused">0</div>
        </div>
        <div class="opt-stat-card">
            <div class="opt-stat-label">Pauses Today</div>
            <div class="opt-stat-value orange" id="opt-stat-pauses-today">0</div>
        </div>
        <div class="opt-stat-card">
            <div class="opt-stat-label">Resumes Today</div>
            <div class="opt-stat-value green" id="opt-stat-resumes-today">0</div>
        </div>
        <div class="opt-stat-card">
            <div class="opt-stat-label">Active Rules</div>
            <div class="opt-stat-value" id="opt-stat-rules">0</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="opt-tabs">
        <div class="opt-tab active" data-tab="rules" onclick="switchOptTab('rules')">Rules</div>
        <div class="opt-tab" data-tab="monitored" onclick="switchOptTab('monitored')">Monitored Campaigns</div>
        <div class="opt-tab" data-tab="logs" onclick="switchOptTab('logs')">Action Logs</div>
    </div>

    <!-- Rules Panel -->
    <div class="opt-panel active" id="opt-panel-rules">
        <div class="opt-info-banner">
            Campaigns that violate any enabled rule will be automatically paused. After 30 minutes, they are turned back on for re-evaluation.
        </div>
        <table class="opt-rules-table">
            <thead>
                <tr>
                    <th>Rule</th>
                    <th>Source</th>
                    <th>Condition</th>
                    <th>Threshold</th>
                    <th>Enabled</th>
                </tr>
            </thead>
            <tbody id="opt-rules-body">
                <tr><td colspan="5" class="opt-empty"><div class="opt-empty-icon">Loading...</div></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Monitored Campaigns Panel -->
    <div class="opt-panel" id="opt-panel-monitored">
        <div class="opt-info-banner">
            Add campaigns to monitoring from the "View Campaigns" page using the shield button on each campaign row.
        </div>
        <table class="opt-monitored-table">
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>Last Checked</th>
                    <th>Last Violation</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="opt-monitored-body">
                <tr><td colspan="5" class="opt-empty"><div class="opt-empty-icon">Loading...</div></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Logs Panel -->
    <div class="opt-panel" id="opt-panel-logs">
        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
            <button class="opt-btn" onclick="filterOptLogs('')" style="font-size: 12px;">All</button>
            <button class="opt-btn" onclick="filterOptLogs('pause')" style="font-size: 12px;">Pauses</button>
            <button class="opt-btn" onclick="filterOptLogs('resume')" style="font-size: 12px;">Resumes</button>
        </div>
        <table class="opt-logs-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Campaign</th>
                    <th>Action</th>
                    <th>Rule</th>
                    <th>Details</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="opt-logs-body">
                <tr><td colspan="6" class="opt-empty"><div class="opt-empty-icon">Loading...</div></td></tr>
            </tbody>
        </table>
    </div>
</div>
