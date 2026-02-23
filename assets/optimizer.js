/**
 * Optimizer Page JavaScript
 * Handles rules, monitored campaigns, logs, and dashboard
 */

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    loadOptimizerData();
});

async function loadOptimizerData() {
    await Promise.all([
        loadDashboardStats(),
        loadRules(),
        loadMonitoredCampaigns(),
        loadLogs(),
    ]);
}

// ============================================
// TABS
// ============================================

function switchOptTab(tab) {
    document.querySelectorAll('.opt-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.opt-panel').forEach(p => p.classList.remove('active'));

    document.querySelector(`.opt-tab[data-tab="${tab}"]`).classList.add('active');
    document.getElementById(`opt-panel-${tab}`).classList.add('active');
}

// ============================================
// DASHBOARD STATS
// ============================================

async function loadDashboardStats() {
    try {
        const response = await fetch('api-optimizer.php?action=get_dashboard_stats');
        const result = await response.json();

        if (result.success) {
            const d = result.data;
            document.getElementById('opt-stat-monitored').textContent = d.monitored;
            document.getElementById('opt-stat-paused').textContent = d.paused;
            document.getElementById('opt-stat-pauses-today').textContent = d.pauses_today;
            document.getElementById('opt-stat-resumes-today').textContent = d.resumes_today;
            document.getElementById('opt-stat-rules').textContent = d.active_rules;
        }
    } catch (e) {
        console.error('Error loading dashboard stats:', e);
    }
}

// ============================================
// RULES
// ============================================

const OPERATOR_LABELS = { gt: '>', lt: '<', gte: '>=', lte: '<=', eq: '=' };
const METRIC_LABELS = {
    spend: 'Spend ($)', cpc: 'CPC ($)', ctr: 'CTR (%)', conversions: 'Conversions',
    lp_ctr: 'LP CTR (%)', impressions: 'Impressions', clicks: 'Clicks',
};
const RULE_GROUP_LABELS = {
    home_insurance: 'Home Insurance',
    medicare: 'Medicare',
};

async function loadRules() {
    try {
        const response = await fetch('api-optimizer.php?action=get_rules');
        const result = await response.json();

        if (result.success && result.data.length > 0) {
            renderRules(result.data);
        } else {
            document.getElementById('opt-rules-body').innerHTML =
                '<tr><td colspan="5" class="opt-empty"><div class="opt-empty-icon">No rules configured</div></td></tr>';
        }
    } catch (e) {
        console.error('Error loading rules:', e);
    }
}

function renderRules(rules) {
    const tbody = document.getElementById('opt-rules-body');

    // Group rules by rule_group
    const groups = {};
    rules.forEach(rule => {
        const group = rule.rule_group || 'home_insurance';
        if (!groups[group]) groups[group] = [];
        groups[group].push(rule);
    });

    let html = '';
    for (const [group, groupRules] of Object.entries(groups)) {
        const groupLabel = RULE_GROUP_LABELS[group] || group;
        const groupColor = group === 'medicare' ? '#7c3aed' : '#0369a1';

        // Group header row
        html += `<tr><td colspan="6" style="background:#f8fafc;padding:10px 16px;font-weight:700;font-size:13px;color:${groupColor};border-bottom:2px solid ${groupColor}20;letter-spacing:0.5px;">${groupLabel} Rules</td></tr>`;

        html += groupRules.map(rule => {
            const opLabel = OPERATOR_LABELS[rule.operator] || rule.operator;
            const metricLabel = METRIC_LABELS[rule.metric_field] || rule.metric_field;
            const source = rule.metric_source;

            let conditionText = `${metricLabel} ${opLabel}`;
            let thresholdHtml = `<input type="number" class="opt-threshold-input" value="${rule.threshold}" step="0.01" onchange="updateRuleThreshold(${rule.id}, this.value)" title="Edit threshold">`;

            // If has secondary condition
            if (rule.secondary_metric) {
                const secMetric = METRIC_LABELS[rule.secondary_metric] || rule.secondary_metric;
                const secOp = OPERATOR_LABELS[rule.secondary_operator] || rule.secondary_operator;
                conditionText += ` <span style="color:#94a3b8">AND</span> ${secMetric} ${secOp}`;
                thresholdHtml += `<br><input type="number" class="opt-threshold-input" value="${rule.secondary_threshold}" step="0.01" onchange="updateRuleSecondaryThreshold(${rule.id}, this.value)" title="Edit secondary threshold" style="margin-top:4px;">`;
            }

            const isOn = parseInt(rule.enabled);

            return `
                <tr>
                    <td style="font-weight:600;">${escapeHtmlOpt(rule.rule_name)}</td>
                    <td><span class="opt-source-badge ${source}">${source === 'tiktok' ? 'TikTok' : 'RedTrack'}</span></td>
                    <td>${conditionText}</td>
                    <td>${thresholdHtml}</td>
                    <td>
                        <div class="opt-toggle ${isOn ? 'on' : ''}" onclick="toggleRule(${rule.id})" title="${isOn ? 'Click to disable' : 'Click to enable'}">
                            <div class="opt-toggle-dot"></div>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    tbody.innerHTML = html;
}

async function updateRuleThreshold(ruleId, value) {
    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_rule', rule_id: ruleId, threshold: parseFloat(value) })
        });
        const result = await response.json();
        if (result.success) {
            showOptToast('Threshold updated', 'success');
        } else {
            showOptToast(result.message || 'Failed to update', 'error');
        }
    } catch (e) {
        showOptToast('Error updating threshold', 'error');
    }
}

async function updateRuleSecondaryThreshold(ruleId, value) {
    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_rule', rule_id: ruleId, secondary_threshold: parseFloat(value) })
        });
        const result = await response.json();
        if (result.success) {
            showOptToast('Threshold updated', 'success');
        } else {
            showOptToast(result.message || 'Failed to update', 'error');
        }
    } catch (e) {
        showOptToast('Error updating threshold', 'error');
    }
}

async function toggleRule(ruleId) {
    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'toggle_rule', rule_id: ruleId })
        });
        const result = await response.json();
        if (result.success) {
            showOptToast(`Rule ${result.data.enabled ? 'enabled' : 'disabled'}`, 'success');
            loadRules();
            loadDashboardStats();
        }
    } catch (e) {
        showOptToast('Error toggling rule', 'error');
    }
}

// ============================================
// MONITORED CAMPAIGNS
// ============================================

async function loadMonitoredCampaigns() {
    try {
        const response = await fetch('api-optimizer.php?action=get_monitored_campaigns');
        const result = await response.json();

        if (result.success && result.data.length > 0) {
            renderMonitoredCampaigns(result.data);
        } else {
            document.getElementById('opt-monitored-body').innerHTML =
                '<tr><td colspan="6" class="opt-empty"><div class="opt-empty-icon">No campaigns being monitored</div><p>Go to View Campaigns and click the shield icon to start monitoring.</p></td></tr>';
        }
    } catch (e) {
        console.error('Error loading monitored campaigns:', e);
    }
}

function renderMonitoredCampaigns(campaigns) {
    const tbody = document.getElementById('opt-monitored-body');

    tbody.innerHTML = campaigns.map(mc => {
        const isPaused = parseInt(mc.paused_by_optimizer);
        const statusDot = isPaused ? 'paused' : 'active';
        const statusText = isPaused ? 'Paused by Rule' : 'Active & Monitoring';
        const lastChecked = mc.last_checked_at ? formatOptDate(mc.last_checked_at) : 'Never';
        const resumeAt = mc.resume_at ? formatOptDate(mc.resume_at) : '';
        const violation = mc.last_violation_rule || '-';

        let actions = '';
        if (isPaused) {
            actions = `
                <button class="opt-btn opt-btn-success" onclick="manualResume('${mc.campaign_id}', '${mc.advertiser_id}')">Resume Now</button>
                <span style="font-size:11px;color:#64748b;display:block;margin-top:4px;">Auto-resume: ${resumeAt}</span>
            `;
        } else {
            actions = `
                <button class="opt-btn opt-btn-danger" onclick="manualPause('${mc.campaign_id}', '${mc.advertiser_id}')">Pause</button>
                <button class="opt-btn" onclick="removeFromMonitoring('${mc.campaign_id}', '${mc.advertiser_id}')" style="margin-left:4px;">Remove</button>
            `;
        }

        const ruleGroup = mc.rule_group || 'home_insurance';
        const groupLabel = ruleGroup === 'medicare' ? 'Medicare' : 'Home Insurance';
        const groupColor = ruleGroup === 'medicare' ? '#7c3aed' : '#0369a1';

        return `
            <tr>
                <td>
                    <div style="font-weight:600;">${escapeHtmlOpt(mc.campaign_name || mc.campaign_id)}</div>
                    <div style="font-size:11px;color:#94a3b8;">${mc.campaign_id}</div>
                </td>
                <td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;background:${groupColor}15;color:${groupColor};">${groupLabel}</span></td>
                <td><span class="opt-status-dot ${statusDot}"></span>${statusText}</td>
                <td style="font-size:12px;color:#64748b;">${lastChecked}</td>
                <td>${violation !== '-' ? `<span class="opt-severity-badge warning">${violation}</span>` : '-'}</td>
                <td>${actions}</td>
            </tr>
        `;
    }).join('');
}

async function manualPause(campaignId, advertiserId) {
    if (!confirm('Pause this campaign?')) return;

    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'manual_pause', campaign_id: campaignId, advertiser_id: advertiserId })
        });
        const result = await response.json();
        showOptToast(result.success ? 'Campaign paused' : (result.message || 'Failed'), result.success ? 'success' : 'error');
        loadOptimizerData();
    } catch (e) {
        showOptToast('Error pausing campaign', 'error');
    }
}

async function manualResume(campaignId, advertiserId) {
    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'manual_resume', campaign_id: campaignId, advertiser_id: advertiserId })
        });
        const result = await response.json();
        showOptToast(result.success ? 'Campaign resumed' : (result.message || 'Failed'), result.success ? 'success' : 'error');
        loadOptimizerData();
    } catch (e) {
        showOptToast('Error resuming campaign', 'error');
    }
}

async function removeFromMonitoring(campaignId, advertiserId) {
    if (!confirm('Remove this campaign from monitoring?')) return;

    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'toggle_monitoring', campaign_id: campaignId, advertiser_id: advertiserId })
        });
        const result = await response.json();
        showOptToast('Campaign removed from monitoring', 'success');
        loadOptimizerData();
    } catch (e) {
        showOptToast('Error removing campaign', 'error');
    }
}

// ============================================
// LOGS
// ============================================

let currentLogFilter = '';

async function loadLogs(actionFilter = '') {
    currentLogFilter = actionFilter;
    try {
        const params = new URLSearchParams({ action: 'get_logs', limit: '100' });
        if (actionFilter) params.append('action_filter', actionFilter);

        const response = await fetch('api-optimizer.php?' + params);
        const result = await response.json();

        if (result.success && result.data.length > 0) {
            renderLogs(result.data);
        } else {
            document.getElementById('opt-logs-body').innerHTML =
                '<tr><td colspan="6" class="opt-empty"><div class="opt-empty-icon">No logs yet</div><p>Logs will appear once the optimizer starts checking campaigns.</p></td></tr>';
        }
    } catch (e) {
        console.error('Error loading logs:', e);
    }
}

function filterOptLogs(filter) {
    loadLogs(filter);
}

function renderLogs(logs) {
    const tbody = document.getElementById('opt-logs-body');

    tbody.innerHTML = logs.map(log => {
        const time = formatOptDate(log.created_at);
        const actionClass = log.action;
        const actionLabel = log.action === 'rule_check' ? 'Check' : log.action.charAt(0).toUpperCase() + log.action.slice(1);
        const ruleKey = log.rule_key || '-';
        const details = log.rule_details || '-';
        const success = parseInt(log.success) ? '<span style="color:#16a34a;">OK</span>' : '<span style="color:#dc2626;">Failed</span>';

        return `
            <tr>
                <td style="font-size:12px;color:#64748b;white-space:nowrap;">${time}</td>
                <td style="font-weight:500;">${log.campaign_id}</td>
                <td><span class="opt-action-badge ${actionClass}">${actionLabel}</span></td>
                <td><span style="font-size:12px;">${escapeHtmlOpt(ruleKey)}</span></td>
                <td style="font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtmlOpt(details)}">${escapeHtmlOpt(details)}</td>
                <td>${success}</td>
            </tr>
        `;
    }).join('');
}

// ============================================
// FORCE CHECK
// ============================================

async function forceOptimizerCheck() {
    const btn = document.getElementById('opt-force-check-btn');
    btn.disabled = true;
    btn.textContent = 'Checking...';

    try {
        const response = await fetch('api-optimizer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'force_check' })
        });
        const result = await response.json();

        if (result.success) {
            showOptToast(result.message, 'success');
        } else {
            showOptToast(result.message || 'Check failed', 'error');
        }

        loadOptimizerData();
    } catch (e) {
        showOptToast('Error running check', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Run Check Now';
    }
}

// ============================================
// UTILITIES
// ============================================

function formatOptDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    const now = new Date();
    const diffMin = Math.round((now - d) / 60000);

    if (diffMin < 1) return 'Just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffMin < 1440) return `${Math.floor(diffMin / 60)}h ago`;

    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function escapeHtmlOpt(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showOptToast(message, type = 'success') {
    // Use existing showToast if available (from smart-campaign.js)
    if (typeof showToast === 'function') {
        showToast(message, type);
        return;
    }

    // Fallback toast
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; bottom: 20px; right: 20px; padding: 12px 20px;
        background: ${type === 'error' ? '#dc2626' : '#16a34a'}; color: white;
        border-radius: 8px; font-size: 14px; font-weight: 600; z-index: 99999;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2); transition: opacity 0.3s;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
}
