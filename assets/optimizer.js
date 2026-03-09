/**
 * Optimizer Page JavaScript
 * Handles rules, monitored campaigns, logs, and dashboard
 */

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    loadOptimizerData();
    loadAccountRtCampaign();
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
// SAFE FETCH HELPERS
// ============================================

async function optGet(url) {
    const response = await fetch(url);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
}

async function optPost(action, data = {}) {
    if (typeof apiFetch === 'function') {
        return apiFetch('api-optimizer.php', {
            method: 'POST',
            body: JSON.stringify({ action, ...data })
        });
    }
    // Fallback if apiFetch not loaded
    const response = await fetch('api-optimizer.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.CSRF_TOKEN || '',
        },
        body: JSON.stringify({ action, ...data })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
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
        const result = await optGet('api-optimizer.php?action=get_dashboard_stats');

        if (result.success && result.data) {
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
    lp_ctr: 'LP CTR (%)', lp_clicks: 'LP Clicks', lp_views: 'LP Views',
    impressions: 'Impressions', clicks: 'Clicks',
};
const RULE_GROUP_LABELS = {
    home_insurance: 'Home Insurance',
    medicare: 'Medicare',
};

async function loadRules() {
    try {
        const result = await optGet('api-optimizer.php?action=get_rules');

        if (result.success && result.data && result.data.length > 0) {
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
        html += `<tr><td colspan="6" style="background:#f8fafc;padding:10px 16px;font-weight:700;font-size:13px;color:${groupColor};border-bottom:2px solid ${groupColor}20;letter-spacing:0.5px;">${escapeHtmlOpt(groupLabel)} Rules</td></tr>`;

        html += groupRules.map(rule => {
            const opLabel = OPERATOR_LABELS[rule.operator] || rule.operator;
            const metricLabel = METRIC_LABELS[rule.metric_field] || rule.metric_field;
            const source = rule.metric_source;

            let conditionText = `${escapeHtmlOpt(metricLabel)} ${opLabel}`;
            let thresholdHtml = `<input type="number" class="opt-threshold-input" value="${parseFloat(rule.threshold)}" step="0.01" onchange="updateRuleThreshold(${parseInt(rule.id)}, this.value)" title="Edit threshold">`;

            // If has secondary condition
            if (rule.secondary_metric) {
                const secMetric = METRIC_LABELS[rule.secondary_metric] || rule.secondary_metric;
                const secOp = OPERATOR_LABELS[rule.secondary_operator] || rule.secondary_operator;
                conditionText += ` <span style="color:#94a3b8">AND</span> ${escapeHtmlOpt(secMetric)} ${secOp}`;
                thresholdHtml += `<br><input type="number" class="opt-threshold-input" value="${parseFloat(rule.secondary_threshold)}" step="0.01" onchange="updateRuleSecondaryThreshold(${parseInt(rule.id)}, this.value)" title="Edit secondary threshold" style="margin-top:4px;">`;
            }

            const isOn = parseInt(rule.enabled);

            return `
                <tr>
                    <td style="font-weight:600;">${escapeHtmlOpt(rule.rule_name)}</td>
                    <td><span class="opt-source-badge ${escapeHtmlOpt(source)}">${source === 'tiktok' ? 'TikTok' : 'RedTrack'}</span></td>
                    <td>${conditionText}</td>
                    <td>${thresholdHtml}</td>
                    <td>
                        <div class="opt-toggle ${isOn ? 'on' : ''}" onclick="toggleRule(${parseInt(rule.id)})" title="${isOn ? 'Click to disable' : 'Click to enable'}">
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
        const result = await optPost('update_rule', { rule_id: ruleId, threshold: parseFloat(value) });
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
        const result = await optPost('update_rule', { rule_id: ruleId, secondary_threshold: parseFloat(value) });
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
        const result = await optPost('toggle_rule', { rule_id: ruleId });
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
        const result = await optGet('api-optimizer.php?action=get_monitored_campaigns');

        if (result.success && result.data && result.data.length > 0) {
            renderMonitoredCampaigns(result.data);
        } else {
            document.getElementById('opt-monitored-body').innerHTML =
                '<tr><td colspan="8" class="opt-empty"><div class="opt-empty-icon">No campaigns being monitored</div><p>Go to View Campaigns and click the shield icon to start monitoring.</p></td></tr>';
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
        const cid = escapeHtmlOpt(mc.campaign_id);
        const aid = escapeHtmlOpt(mc.advertiser_id);

        let actions = '';
        if (isPaused) {
            actions = `
                <button class="opt-btn opt-btn-success" onclick="manualResume('${cid}', '${aid}')">Resume Now</button>
                <span style="font-size:11px;color:#64748b;display:block;margin-top:4px;">Review at: ${resumeAt}</span>
            `;
        } else {
            actions = `
                <button class="opt-btn opt-btn-danger" onclick="manualPause('${cid}', '${aid}')">Pause</button>
                <button class="opt-btn" onclick="removeFromMonitoring('${cid}', '${aid}')" style="margin-left:4px;">Remove</button>
            `;
        }

        const rtCampaign = mc.redtrack_campaign_name || '-';

        // Phase badge
        const phase = mc.optimizer_phase || 'phase1';
        const phaseLabel = phase === 'phase2' ? 'Phase 2' : 'Phase 1';
        const phaseColor = phase === 'phase2' ? '#7c3aed' : '#0369a1';
        let phaseDetail = '';
        if (phase === 'phase1' && mc.spend !== null && mc.spend !== undefined) {
            phaseDetail = `<div style="font-size:10px;color:#64748b;">$${parseFloat(mc.spend).toFixed(2)} / $30</div>`;
        }

        // Profit cell
        let profitCell = '-';
        if (mc.profit !== null && mc.profit !== undefined) {
            const profitVal = parseFloat(mc.profit);
            const profitColor = profitVal >= 0 ? '#16a34a' : '#dc2626';
            const profitSign = profitVal >= 0 ? '+' : '';
            profitCell = `<span style="font-weight:700;color:${profitColor};">${profitSign}$${profitVal.toFixed(2)}</span>`;
            if (mc.revenue !== null && mc.spend !== null) {
                profitCell += `<div style="font-size:10px;color:#64748b;">Rev $${parseFloat(mc.revenue).toFixed(2)} - Cost $${parseFloat(mc.spend).toFixed(2)}</div>`;
            }
        }

        return `
            <tr>
                <td>
                    <div style="font-weight:600;">${escapeHtmlOpt(mc.campaign_name || mc.campaign_id)}</div>
                    <div style="font-size:11px;color:#94a3b8;">${cid}</div>
                </td>
                <td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;background:${phaseColor}15;color:${phaseColor};">${phaseLabel}</span>${phaseDetail}</td>
                <td>${profitCell}</td>
                <td style="font-size:12px;color:#475569;">${escapeHtmlOpt(rtCampaign)}</td>
                <td><span class="opt-status-dot ${statusDot}"></span>${statusText}</td>
                <td style="font-size:12px;color:#64748b;">${lastChecked}</td>
                <td>${violation !== '-' ? `<span class="opt-severity-badge warning">${escapeHtmlOpt(violation)}</span>` : '-'}</td>
                <td>${actions}</td>
            </tr>
        `;
    }).join('');
}

async function manualPause(campaignId, advertiserId) {
    if (!confirm('Pause this campaign?')) return;

    try {
        const result = await optPost('manual_pause', { campaign_id: campaignId, advertiser_id: advertiserId });
        showOptToast(result.success ? 'Campaign paused' : (result.message || 'Failed'), result.success ? 'success' : 'error');
        loadOptimizerData();
    } catch (e) {
        showOptToast('Error pausing campaign', 'error');
    }
}

async function manualResume(campaignId, advertiserId) {
    try {
        const result = await optPost('manual_resume', { campaign_id: campaignId, advertiser_id: advertiserId });
        showOptToast(result.success ? 'Campaign resumed' : (result.message || 'Failed'), result.success ? 'success' : 'error');
        loadOptimizerData();
    } catch (e) {
        showOptToast('Error resuming campaign', 'error');
    }
}

async function removeFromMonitoring(campaignId, advertiserId) {
    if (!confirm('Remove this campaign from monitoring?')) return;

    try {
        const result = await optPost('toggle_monitoring', { campaign_id: campaignId, advertiser_id: advertiserId });
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

        const result = await optGet('api-optimizer.php?' + params);

        if (result.success && result.data && result.data.length > 0) {
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
        const actionClass = escapeHtmlOpt(log.action);
        const actionLabel = log.action === 'rule_check' ? 'Check' : log.action.charAt(0).toUpperCase() + log.action.slice(1);
        const ruleKey = log.rule_key || '-';
        const details = log.rule_details || '-';
        const success = parseInt(log.success) ? '<span style="color:#16a34a;">OK</span>' : '<span style="color:#dc2626;">Failed</span>';

        return `
            <tr>
                <td style="font-size:12px;color:#64748b;white-space:nowrap;">${time}</td>
                <td style="font-weight:500;">${escapeHtmlOpt(log.campaign_id)}</td>
                <td><span class="opt-action-badge ${actionClass}">${escapeHtmlOpt(actionLabel)}</span></td>
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
        const result = await optPost('force_check');

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
    div.textContent = String(str);
    return div.innerHTML;
}

// ============================================
// ACCOUNT-LEVEL REDTRACK CAMPAIGN
// ============================================

function getOptAdvertiserId() {
    // Try multiple sources for advertiser ID
    return (typeof state !== 'undefined' && state.currentAdvertiserId)
        || window.TIKTOK_ADVERTISER_ID
        || '';
}

async function loadAccountRtCampaign() {
    const advId = getOptAdvertiserId();
    if (!advId) return;

    try {
        const result = await optGet(`api-optimizer.php?action=get_account_rt_campaign&advertiser_id=${encodeURIComponent(advId)}`);

        const input = document.getElementById('opt-account-rt-input');
        if (input && result.success && result.redtrack_campaign_name) {
            input.value = result.redtrack_campaign_name;
        }
    } catch (e) {
        console.error('Error loading account RT campaign:', e);
    }
}

async function saveAccountRtCampaign() {
    const input = document.getElementById('opt-account-rt-input');
    const rtName = input ? input.value.trim() : '';
    const advId = getOptAdvertiserId();

    if (!rtName) {
        showOptToast('Enter a RedTrack campaign name', 'error');
        return;
    }

    try {
        const result = await optPost('set_account_rt_campaign', {
            advertiser_id: advId,
            redtrack_campaign_name: rtName
        });

        if (result.success) {
            showOptToast(result.message || 'Account RT campaign saved', 'success');
            const status = document.getElementById('opt-account-rt-status');
            if (status) {
                status.textContent = 'Saved!';
                status.style.display = 'block';
                setTimeout(() => status.style.display = 'none', 3000);
            }
        } else {
            showOptToast(result.message || 'Failed to save', 'error');
        }
    } catch (e) {
        showOptToast('Error saving account RT campaign', 'error');
    }
}

async function clearAccountRtCampaign() {
    const advId = getOptAdvertiserId();

    try {
        const result = await optPost('set_account_rt_campaign', {
            advertiser_id: advId,
            redtrack_campaign_name: ''
        });

        if (result.success) {
            const input = document.getElementById('opt-account-rt-input');
            if (input) input.value = '';
            showOptToast('Account RT campaign cleared', 'success');
        }
    } catch (e) {
        showOptToast('Error clearing account RT campaign', 'error');
    }
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
