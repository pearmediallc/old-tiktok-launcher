<header class="app-header">
    <div class="app-header-left">
        <h1>TikTok Campaign Launcher</h1>
    </div>
    <div class="app-header-right">
        <?php if ($isConnected): ?>
            <span class="connection-badge connected">
                <span class="badge-dot"></span>
                Connected
            </span>
        <?php else: ?>
            <a href="oauth-init.php" class="btn-connect-tiktok">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                </svg>
                Connect TikTok
            </a>
        <?php endif; ?>

        <?php if (!empty($slackConnected)): ?>
            <span class="connection-badge connected" title="Slack: <?php echo htmlspecialchars($slackTeamName . ' / ' . $slackChannel); ?>" style="cursor:pointer;" onclick="toggleSlackMenu()">
                <span class="badge-dot" style="background:#22c55e;"></span>
                Slack
            </span>
            <div id="slack-menu" style="display:none;position:absolute;top:52px;right:120px;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;z-index:200;min-width:220px;box-shadow:0 4px 16px rgba(0,0,0,0.15);">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px;"><?php echo htmlspecialchars($slackTeamName); ?></div>
                <div style="font-size:12px;color:var(--muted-foreground);margin-bottom:12px;"><?php echo htmlspecialchars($slackChannel); ?></div>
                <a href="slack-oauth-callback.php?action=disconnect" style="font-size:13px;color:var(--destructive);" onclick="return confirm('Disconnect Slack?')">Disconnect Slack</a>
            </div>
        <?php else: ?>
            <a href="slack-oauth-callback.php" class="btn-connect-tiktok" style="background:#4a154b;border-color:#4a154b;" title="Connect your Slack workspace for optimizer notifications">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                Connect Slack
            </a>
        <?php endif; ?>

        <button class="btn-header-logout" onclick="shellLogout()">Logout</button>
    </div>
</header>
<script>
function toggleSlackMenu() {
    var m = document.getElementById('slack-menu');
    if (m) m.style.display = m.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    var menu = document.getElementById('slack-menu');
    if (!menu) return;
    if (!menu.contains(e.target) && !e.target.closest('.connection-badge[onclick]')) {
        menu.style.display = 'none';
    }
});
</script>
