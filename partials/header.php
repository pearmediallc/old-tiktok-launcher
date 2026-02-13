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
        <button class="btn-header-logout" onclick="shellLogout()">Logout</button>
    </div>
</header>
