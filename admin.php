<?php
require_once __DIR__ . '/includes/Security.php';
Security::init();
Security::enforceHttps();
session_start();

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: app-shell.php');
    exit;
}

$lastActivity = $_SESSION['last_activity'] ?? 0;
if (time() - $lastActivity > 604800) {
    session_destroy();
    session_start();
    header('Location: index.php');
    exit;
}
$_SESSION['last_activity'] = time();

$currentUser = $_SESSION['username'] ?? '';
$tab = $_GET['tab'] ?? 'users';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel — TikTok Launcher</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body { background: var(--background); color: var(--foreground); font-family: inherit; }
        .admin-wrap { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .admin-header h1 { font-size: 24px; font-weight: 700; }
        .admin-tabs { display: flex; gap: 8px; border-bottom: 2px solid var(--border); margin-bottom: 24px; flex-wrap: wrap; }
        .admin-tab { padding: 10px 20px; border: none; background: none; font-size: 15px; color: var(--muted-foreground); cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; font-weight: 500; }
        .admin-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 20px; }
        .card h2 { font-size: 18px; margin-bottom: 16px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; padding: 10px 12px; border-bottom: 2px solid var(--border); color: var(--muted-foreground); font-weight: 600; font-size: 13px; }
        td { padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:hover td { background: var(--accent); }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-admin { background: #fef3c7; color: #92400e; }
        .badge-user { background: #dbeafe; color: #1e40af; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive, .badge-suspended { background: #fee2e2; color: #991b1b; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-error, .badge-denied { background: #fee2e2; color: #991b1b; }
        .badge-expired { background: #fef3c7; color: #92400e; }
        .btn-sm { padding: 5px 12px; font-size: 13px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-danger { background: var(--destructive); color: white; }
        .btn-secondary { background: var(--accent); color: var(--foreground); border: 1px solid var(--border); }
        .btn-sm:hover { opacity: 0.85; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: var(--card); border-radius: var(--radius); padding: 28px; width: 420px; max-width: 95vw; }
        .modal h3 { font-size: 18px; margin-bottom: 20px; font-weight: 600; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: var(--muted-foreground); }
        .form-group input, .form-group select { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--background); color: var(--foreground); font-size: 14px; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .filter-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; align-items: flex-end; }
        .filter-row input, .filter-row select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--background); color: var(--foreground); font-size: 13px; }
        .log-details { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12px; color: var(--muted-foreground); cursor: pointer; }
        .back-link { color: var(--primary); text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
        #log-table td, #log-table th { font-size: 12px; }
        .loading-spinner { text-align: center; padding: 40px; color: var(--muted-foreground); }
    </style>
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="app-shell.php" class="back-link">← Back to App</a>
            <h1 style="margin-top:8px;">Admin Panel</h1>
        </div>
        <span style="font-size:14px;color:var(--muted-foreground);">Logged in as <strong><?php echo htmlspecialchars($currentUser); ?></strong></span>
    </div>

    <div class="admin-tabs">
        <button class="admin-tab <?php echo $tab === 'users' ? 'active' : ''; ?>" onclick="switchTab('users')">Users</button>
        <button class="admin-tab <?php echo $tab === 'logs' ? 'active' : ''; ?>" onclick="switchTab('logs')">Activity Logs</button>
        <button class="admin-tab <?php echo $tab === 'connections' ? 'active' : ''; ?>" onclick="switchTab('connections')">TikTok Connections</button>
        <button class="admin-tab <?php echo $tab === 'jobs' ? 'active' : ''; ?>" onclick="switchTab('jobs')">Bulk Jobs</button>
    </div>

    <!-- Users Tab -->
    <div class="tab-content <?php echo $tab === 'users' ? 'active' : ''; ?>" id="tab-users">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h2 style="margin:0;">Users</h2>
                <button class="btn-sm btn-primary" onclick="openCreateUser()">+ Add User</button>
            </div>
            <div id="users-loading" class="loading-spinner">Loading users...</div>
            <table id="users-table" style="display:none;">
                <thead>
                    <tr>
                        <th>ID</th><th>Username</th><th>Full Name</th><th>Email</th>
                        <th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody id="users-tbody"></tbody>
            </table>
        </div>
    </div>

    <!-- Logs Tab -->
    <div class="tab-content <?php echo $tab === 'logs' ? 'active' : ''; ?>" id="tab-logs">
        <div class="card">
            <h2>Activity Logs</h2>
            <div class="filter-row">
                <input type="text" id="log-filter-user" placeholder="Username" style="width:150px;">
                <input type="text" id="log-filter-action" placeholder="Action" style="width:150px;">
                <select id="log-filter-status" style="width:130px;">
                    <option value="">All statuses</option>
                    <option value="success">success</option>
                    <option value="error">error</option>
                    <option value="denied">denied</option>
                </select>
                <input type="date" id="log-filter-from">
                <input type="date" id="log-filter-to">
                <select id="log-limit" style="width:100px;">
                    <option value="100">100 rows</option>
                    <option value="200" selected>200 rows</option>
                    <option value="500">500 rows</option>
                </select>
                <button class="btn-sm btn-primary" onclick="loadLogs()">Filter</button>
            </div>
            <div id="logs-loading" class="loading-spinner" style="display:none;">Loading logs...</div>
            <div style="overflow-x:auto;">
                <table id="log-table">
                    <thead>
                        <tr>
                            <th>Time</th><th>User</th><th>IP</th><th>Action</th>
                            <th>Endpoint</th><th>Advertiser</th><th>Status</th><th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="logs-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TikTok Connections Tab -->
    <div class="tab-content <?php echo $tab === 'connections' ? 'active' : ''; ?>" id="tab-connections">
        <div class="card">
            <h2>TikTok Connections</h2>
            <div id="connections-loading" class="loading-spinner">Loading...</div>
            <div style="overflow-x:auto;">
                <table id="connections-table" style="display:none;">
                    <thead>
                        <tr>
                            <th>ID</th><th>User</th><th>Advertiser ID</th><th>Advertiser Name</th>
                            <th>Status</th><th>Token Expires</th><th>Last Sync</th><th>Created</th>
                        </tr>
                    </thead>
                    <tbody id="connections-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Bulk Jobs Tab -->
    <div class="tab-content <?php echo $tab === 'jobs' ? 'active' : ''; ?>" id="tab-jobs">
        <div class="card">
            <h2>Bulk Campaign Jobs</h2>
            <div id="jobs-loading" class="loading-spinner">Loading...</div>
            <div style="overflow-x:auto;">
                <table id="jobs-table" style="display:none;">
                    <thead>
                        <tr>
                            <th>Job ID</th><th>User</th><th>Name</th><th>Status</th>
                            <th>Accounts</th><th>Success/Failed</th><th>Created</th><th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="jobs-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal-overlay" id="create-user-modal">
    <div class="modal">
        <h3>Add New User</h3>
        <div class="form-group"><label>Username *</label><input type="text" id="new-username" placeholder="username"></div>
        <div class="form-group"><label>Password *</label><input type="password" id="new-password" placeholder="min 6 characters"></div>
        <div class="form-group"><label>Full Name</label><input type="text" id="new-fullname" placeholder="Full Name"></div>
        <div class="form-group"><label>Email</label><input type="email" id="new-email" placeholder="email@example.com"></div>
        <div class="form-group">
            <label>Role</label>
            <select id="new-role">
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div id="create-user-error" style="color:var(--destructive);font-size:13px;display:none;margin-bottom:10px;"></div>
        <div class="modal-actions">
            <button class="btn-sm btn-secondary" onclick="closeModal('create-user-modal')">Cancel</button>
            <button class="btn-sm btn-primary" onclick="createUser()">Create User</button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="edit-user-modal">
    <div class="modal">
        <h3>Edit User</h3>
        <input type="hidden" id="edit-user-id">
        <div class="form-group"><label>Full Name</label><input type="text" id="edit-fullname"></div>
        <div class="form-group"><label>Email</label><input type="email" id="edit-email"></div>
        <div class="form-group">
            <label>Role</label>
            <select id="edit-role">
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div class="form-group">
            <label>Status</label>
            <select id="edit-status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
            </select>
        </div>
        <div class="modal-actions">
            <button class="btn-sm btn-secondary" onclick="closeModal('edit-user-modal')">Cancel</button>
            <button class="btn-sm btn-primary" onclick="saveUser()">Save</button>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="reset-pw-modal">
    <div class="modal">
        <h3>Reset Password</h3>
        <input type="hidden" id="reset-pw-user-id">
        <p style="font-size:14px;margin-bottom:16px;">Set a new password for <strong id="reset-pw-username"></strong></p>
        <div class="form-group"><label>New Password *</label><input type="password" id="reset-pw-value" placeholder="min 6 characters"></div>
        <div id="reset-pw-error" style="color:var(--destructive);font-size:13px;display:none;margin-bottom:10px;"></div>
        <div class="modal-actions">
            <button class="btn-sm btn-secondary" onclick="closeModal('reset-pw-modal')">Cancel</button>
            <button class="btn-sm btn-primary" onclick="resetPassword()">Reset Password</button>
        </div>
    </div>
</div>

<!-- Job Results Modal -->
<div class="modal-overlay" id="job-results-modal">
    <div class="modal" style="width:700px;max-height:80vh;overflow-y:auto;">
        <h3 id="job-results-title">Job Results</h3>
        <div id="job-results-body"></div>
        <div class="modal-actions">
            <button class="btn-sm btn-secondary" onclick="closeModal('job-results-modal')">Close</button>
        </div>
    </div>
</div>

<script>
const CSRF = '<?php echo Security::generateCSRFToken(); ?>';

function switchTab(tab) {
    document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
    if (tab === 'users') loadUsers();
    if (tab === 'logs') loadLogs();
    if (tab === 'connections') loadConnections();
    if (tab === 'jobs') loadJobs();
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── Users ────────────────────────────────────────────────
async function loadUsers() {
    document.getElementById('users-loading').style.display = 'block';
    document.getElementById('users-table').style.display = 'none';
    const res = await fetch('api-admin.php?action=list_users');
    const data = await res.json();
    document.getElementById('users-loading').style.display = 'none';
    if (!data.success) return;
    const tbody = document.getElementById('users-tbody');
    tbody.innerHTML = data.users.map(u => `
        <tr>
            <td>${u.id}</td>
            <td><strong>${esc(u.username)}</strong></td>
            <td>${esc(u.full_name || '—')}</td>
            <td>${esc(u.email || '—')}</td>
            <td><span class="badge badge-${u.role}">${u.role}</span></td>
            <td><span class="badge badge-${u.status}">${u.status}</span></td>
            <td style="font-size:12px;">${u.last_login ? u.last_login.substring(0,16) : 'Never'}</td>
            <td style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="btn-sm btn-secondary" onclick="openEditUser(${u.id},'${esc(u.full_name||'')}','${esc(u.email||'')}','${u.role}','${u.status}')">Edit</button>
                <button class="btn-sm btn-secondary" onclick="openResetPw(${u.id},'${esc(u.username)}')">Reset PW</button>
                <button class="btn-sm btn-danger" onclick="deleteUser(${u.id},'${esc(u.username)}')">Delete</button>
            </td>
        </tr>
    `).join('');
    document.getElementById('users-table').style.display = 'table';
}

function openCreateUser() {
    document.getElementById('new-username').value = '';
    document.getElementById('new-password').value = '';
    document.getElementById('new-fullname').value = '';
    document.getElementById('new-email').value = '';
    document.getElementById('new-role').value = 'user';
    document.getElementById('create-user-error').style.display = 'none';
    openModal('create-user-modal');
}

async function createUser() {
    const errEl = document.getElementById('create-user-error');
    errEl.style.display = 'none';
    const body = new FormData();
    body.append('action', 'create_user');
    body.append('username', document.getElementById('new-username').value.trim());
    body.append('password', document.getElementById('new-password').value);
    body.append('full_name', document.getElementById('new-fullname').value.trim());
    body.append('email', document.getElementById('new-email').value.trim());
    body.append('role', document.getElementById('new-role').value);
    const res = await fetch('api-admin.php', { method: 'POST', body });
    const data = await res.json();
    if (data.error) { errEl.textContent = data.error; errEl.style.display = 'block'; return; }
    closeModal('create-user-modal');
    loadUsers();
}

function openEditUser(id, fullName, email, role, status) {
    document.getElementById('edit-user-id').value = id;
    document.getElementById('edit-fullname').value = fullName;
    document.getElementById('edit-email').value = email;
    document.getElementById('edit-role').value = role;
    document.getElementById('edit-status').value = status;
    openModal('edit-user-modal');
}

async function saveUser() {
    const body = new FormData();
    body.append('action', 'update_user');
    body.append('user_id', document.getElementById('edit-user-id').value);
    body.append('full_name', document.getElementById('edit-fullname').value.trim());
    body.append('email', document.getElementById('edit-email').value.trim());
    body.append('role', document.getElementById('edit-role').value);
    body.append('status', document.getElementById('edit-status').value);
    await fetch('api-admin.php', { method: 'POST', body });
    closeModal('edit-user-modal');
    loadUsers();
}

function openResetPw(id, username) {
    document.getElementById('reset-pw-user-id').value = id;
    document.getElementById('reset-pw-username').textContent = username;
    document.getElementById('reset-pw-value').value = '';
    document.getElementById('reset-pw-error').style.display = 'none';
    openModal('reset-pw-modal');
}

async function resetPassword() {
    const errEl = document.getElementById('reset-pw-error');
    const pw = document.getElementById('reset-pw-value').value;
    if (pw.length < 6) { errEl.textContent = 'Min 6 characters'; errEl.style.display = 'block'; return; }
    const body = new FormData();
    body.append('action', 'reset_password');
    body.append('user_id', document.getElementById('reset-pw-user-id').value);
    body.append('new_password', pw);
    const res = await fetch('api-admin.php', { method: 'POST', body });
    const data = await res.json();
    if (data.error) { errEl.textContent = data.error; errEl.style.display = 'block'; return; }
    closeModal('reset-pw-modal');
    alert('Password reset successfully.');
}

async function deleteUser(id, username) {
    if (!confirm(`Delete user "${username}"? This cannot be undone.`)) return;
    const body = new FormData();
    body.append('action', 'delete_user');
    body.append('user_id', id);
    const res = await fetch('api-admin.php', { method: 'POST', body });
    const data = await res.json();
    if (data.error) { alert(data.error); return; }
    loadUsers();
}

// ── Logs ─────────────────────────────────────────────────
async function loadLogs() {
    const limit  = document.getElementById('log-limit')?.value || 200;
    const user   = document.getElementById('log-filter-user')?.value || '';
    const action = document.getElementById('log-filter-action')?.value || '';
    const status = document.getElementById('log-filter-status')?.value || '';
    const from   = document.getElementById('log-filter-from')?.value || '';
    const to     = document.getElementById('log-filter-to')?.value || '';

    document.getElementById('logs-loading').style.display = 'block';
    const params = new URLSearchParams({ action: 'get_logs', limit, username: user, action_filter: action, status, date_from: from, date_to: to });
    const res = await fetch('api-admin.php?' + params);
    const data = await res.json();
    document.getElementById('logs-loading').style.display = 'none';
    if (!data.success) return;
    const tbody = document.getElementById('logs-tbody');
    tbody.innerHTML = data.logs.map(l => `
        <tr>
            <td style="white-space:nowrap;">${(l.created_at||'').substring(0,16)}</td>
            <td>${esc(l.username||'—')}</td>
            <td style="font-size:11px;">${esc(l.ip_address||'—')}</td>
            <td>${esc(l.action||'')}</td>
            <td style="font-size:11px;">${esc(l.endpoint||'—')}</td>
            <td style="font-size:11px;">${esc(l.advertiser_id||'—')}</td>
            <td><span class="badge badge-${l.status||'success'}">${esc(l.status||'')}</span></td>
            <td class="log-details" title="${esc(typeof l.details === 'object' ? JSON.stringify(l.details) : (l.details||''))}">${esc(typeof l.details === 'object' ? JSON.stringify(l.details) : (l.details||'—'))}</td>
        </tr>
    `).join('');
}

// ── TikTok Connections ────────────────────────────────────
async function loadConnections() {
    document.getElementById('connections-loading').style.display = 'block';
    document.getElementById('connections-table').style.display = 'none';
    const res = await fetch('api-admin.php?action=get_tiktok_connections');
    const data = await res.json();
    document.getElementById('connections-loading').style.display = 'none';
    if (!data.success) return;
    const tbody = document.getElementById('connections-tbody');
    tbody.innerHTML = data.connections.map(c => `
        <tr>
            <td>${c.id}</td>
            <td>${esc(c.username||'—')}</td>
            <td style="font-size:12px;">${esc(c.advertiser_id||'—')}</td>
            <td>${esc(c.advertiser_name||'—')}</td>
            <td><span class="badge badge-${c.connection_status}">${c.connection_status}</span></td>
            <td style="font-size:12px;">${(c.token_expires_at||'—').substring(0,16)}</td>
            <td style="font-size:12px;">${(c.last_sync_at||'Never').substring(0,16)}</td>
            <td style="font-size:12px;">${(c.created_at||'').substring(0,16)}</td>
        </tr>
    `).join('');
    document.getElementById('connections-table').style.display = 'table';
}

// ── Bulk Jobs ─────────────────────────────────────────────
async function loadJobs() {
    document.getElementById('jobs-loading').style.display = 'block';
    document.getElementById('jobs-table').style.display = 'none';
    const res = await fetch('api-admin.php?action=get_bulk_jobs&limit=100');
    const data = await res.json();
    document.getElementById('jobs-loading').style.display = 'none';
    if (!data.success) return;
    const tbody = document.getElementById('jobs-tbody');
    tbody.innerHTML = data.jobs.map(j => `
        <tr>
            <td style="font-size:11px;">${esc(j.job_id||'').substring(0,8)}…</td>
            <td>${esc(j.username||'—')}</td>
            <td>${esc(j.job_name||'—')}</td>
            <td><span class="badge badge-${j.status}">${j.status}</span></td>
            <td>${j.total_accounts}</td>
            <td>${j.success_count||0} / ${j.failed_count||0}</td>
            <td style="font-size:12px;">${(j.created_at||'').substring(0,16)}</td>
            <td><button class="btn-sm btn-secondary" onclick="viewJobResults('${esc(j.job_id)}')">View</button></td>
        </tr>
    `).join('');
    document.getElementById('jobs-table').style.display = 'table';
}

async function viewJobResults(jobId) {
    document.getElementById('job-results-title').textContent = 'Results: ' + jobId.substring(0,8) + '…';
    document.getElementById('job-results-body').innerHTML = '<div class="loading-spinner">Loading...</div>';
    openModal('job-results-modal');
    const res = await fetch('api-admin.php?action=get_job_results&job_id=' + encodeURIComponent(jobId));
    const data = await res.json();
    if (!data.success || !data.results.length) {
        document.getElementById('job-results-body').innerHTML = '<p style="color:var(--muted-foreground);">No results found.</p>';
        return;
    }
    document.getElementById('job-results-body').innerHTML = `
        <table style="font-size:12px;width:100%;">
            <thead><tr><th>Advertiser</th><th>Status</th><th>Campaign ID</th><th>Error</th></tr></thead>
            <tbody>${data.results.map(r => `
                <tr>
                    <td>${esc(r.advertiser_name||r.advertiser_id)}</td>
                    <td><span class="badge badge-${r.status}">${r.status||'—'}</span></td>
                    <td style="font-size:11px;">${esc(r.campaign_id||'—')}</td>
                    <td style="font-size:11px;color:var(--destructive);">${esc(r.error_message||'')}</td>
                </tr>
            `).join('')}</tbody>
        </table>`;
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-load active tab
window.addEventListener('DOMContentLoaded', () => {
    const active = document.querySelector('.tab-content.active');
    if (active && active.id === 'tab-users') loadUsers();
    else if (active && active.id === 'tab-logs') loadLogs();
    else if (active && active.id === 'tab-connections') loadConnections();
    else if (active && active.id === 'tab-jobs') loadJobs();
});
</script>
</body>
</html>
