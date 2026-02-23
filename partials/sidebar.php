<nav class="sidebar" id="sidebar">
    <div class="sidebar-nav">
        <div class="sidebar-section-label">Campaign Management</div>

        <a href="app-shell.php?view=campaigns"
           class="sidebar-item <?php echo $view === 'campaigns' ? 'active' : ''; ?>">
            <span class="sidebar-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3h7v7H3z"></path>
                    <path d="M14 3h7v7h-7z"></path>
                    <path d="M14 14h7v7h-7z"></path>
                    <path d="M3 14h7v7H3z"></path>
                </svg>
            </span>
            <span class="sidebar-label">View Campaigns</span>
        </a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Create Campaign</div>

        <a href="app-shell.php?view=create-smart"
           class="sidebar-item <?php echo $view === 'create-smart' ? 'active' : ''; ?>">
            <span class="sidebar-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                </svg>
            </span>
            <span class="sidebar-label">Create Smart+</span>
        </a>

        <a href="app-shell.php?view=create-manual"
           class="sidebar-item <?php echo $view === 'create-manual' ? 'active' : ''; ?>">
            <span class="sidebar-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </span>
            <span class="sidebar-label">Create Manual</span>
        </a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Optimization</div>

        <a href="app-shell.php?view=optimizer"
           class="sidebar-item <?php echo $view === 'optimizer' ? 'active' : ''; ?>">
            <span class="sidebar-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20V10"></path>
                    <path d="M18 20V4"></path>
                    <path d="M6 20v-4"></path>
                </svg>
            </span>
            <span class="sidebar-label">Optimizer</span>
            <?php if (!empty($optimizerNotifCount) && $optimizerNotifCount > 0): ?>
                <span class="sidebar-notif-badge"><?php echo $optimizerNotifCount; ?></span>
            <?php endif; ?>
        </a>
    </div>

    <div class="sidebar-footer">
        TikTok Launcher v2.0
    </div>
</nav>
