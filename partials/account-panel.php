<?php if ($isConnected && !empty($advertiserIds)): ?>
<div class="account-panel" id="account-panel">
    <span class="account-panel-label">Ad Account:</span>

    <?php if ($view === 'campaigns'): ?>
    <!-- =============================================
         MULTI-SELECT DROPDOWN (Campaigns View)
         ============================================= -->
    <div class="multi-select-dropdown" id="multi-select-dropdown-wrapper">
        <div class="multi-select-trigger" onclick="toggleMultiSelect()">
            <span id="multi-select-label">
                <?php
                if ($currentAdvertiserId) {
                    $details = $advertiserDetails[$currentAdvertiserId] ?? null;
                    $name = $details['name'] ?? '';
                    echo htmlspecialchars($name && $name !== 'Account' ? $name : 'Account ' . substr($currentAdvertiserId, -6));
                } else {
                    echo 'Select accounts...';
                }
                ?>
            </span>
            <span style="display:flex;align-items:center;gap:6px;">
                <span class="multi-select-count" id="multi-select-count-badge" style="display:<?php echo $currentAdvertiserId ? 'inline' : 'none'; ?>">
                    <?php echo $currentAdvertiserId ? '1' : '0'; ?>
                </span>
                <span class="multi-select-arrow">&#9662;</span>
            </span>
        </div>
        <div class="multi-select-options" id="multi-select-options" style="display: none;">
            <div class="multi-select-search">
                <input type="text" placeholder="Search accounts..." oninput="filterMultiAccounts(this.value)">
            </div>
            <label class="multi-select-option select-all">
                <input type="checkbox" id="select-all-accounts" onchange="toggleAllAccounts()">
                <span class="option-name">Select All</span>
            </label>
            <?php
            $accountIndex = 1;
            foreach ($advertiserIds as $advId):
                $details = $advertiserDetails[$advId] ?? null;
                $advName = $details['name'] ?? '';
                if (!$advName || $advName === 'Account') {
                    $advName = 'Ad Account #' . $accountIndex;
                }
                $isCurrentAccount = ($advId === $currentAdvertiserId);
            ?>
            <label class="multi-select-option" data-search="<?php echo htmlspecialchars(strtolower($advName . ' ' . $advId)); ?>">
                <input type="checkbox"
                       class="account-checkbox"
                       value="<?php echo htmlspecialchars($advId); ?>"
                       data-name="<?php echo htmlspecialchars($advName); ?>"
                       <?php echo $isCurrentAccount ? 'checked' : ''; ?>
                       onchange="updateMultiAccountSelection()">
                <span class="option-name"><?php echo htmlspecialchars($advName); ?></span>
                <span class="option-id">ID: <?php echo htmlspecialchars(substr($advId, -6)); ?></span>
            </label>
            <?php
                $accountIndex++;
            endforeach;
            ?>
        </div>
    </div>

    <?php else: ?>
    <!-- =============================================
         SINGLE-SELECT DROPDOWN (Create Views)
         ============================================= -->
    <div class="account-dropdown-wrapper">
        <input type="text"
               id="shell-account-search"
               class="account-search-input"
               placeholder="Search accounts..."
               oninput="filterAccountOptions()"
               onfocus="showAccountDropdown()"
               value="<?php
                   if ($currentAdvertiserId) {
                       $details = $advertiserDetails[$currentAdvertiserId] ?? null;
                       $advName = $details['name'] ?? '';
                       if ($advName && $advName !== 'Account') {
                           echo htmlspecialchars($advName . ' - ID: ' . substr($currentAdvertiserId, -6));
                       } else {
                           echo htmlspecialchars('Account ' . substr($currentAdvertiserId, -6));
                       }
                   }
               ?>"
               autocomplete="off">
        <div class="account-dropdown" id="shell-account-dropdown" style="display: none;">
            <?php
            $accountIndex = 1;
            foreach ($advertiserIds as $advId):
                $details = $advertiserDetails[$advId] ?? null;
                $advName = $details['name'] ?? '';
                if ($advName && $advName !== 'Account') {
                    $displayName = $advName . ' - ID: ' . substr($advId, -6);
                } else {
                    $displayName = 'Ad Account #' . $accountIndex . ' - ID: ' . substr($advId, -6);
                }
                $isSelected = ($advId === $currentAdvertiserId);
            ?>
            <div class="account-option <?php echo $isSelected ? 'selected' : ''; ?>"
                 data-advertiser-id="<?php echo htmlspecialchars($advId); ?>"
                 data-search="<?php echo htmlspecialchars(strtolower($displayName . ' ' . $advId)); ?>"
                 onclick="selectAccount('<?php echo htmlspecialchars($advId); ?>', '<?php echo htmlspecialchars(addslashes($displayName)); ?>')">
                <div class="account-option-name"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="account-option-id">Full ID: <?php echo htmlspecialchars($advId); ?></div>
            </div>
            <?php
                $accountIndex++;
            endforeach;
            ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="account-info-badge">
        <span class="count"><?php echo count($advertiserIds); ?></span>
        accounts linked
    </div>

    <!-- Account Balance / Spending Info -->
    <div class="account-balance-display" id="account-balance-display" style="display:none;">
        <div class="balance-item balance-available">
            <span class="balance-label">Available Balance</span>
            <span class="balance-value" id="balance-available">--</span>
        </div>
        <div class="balance-item balance-spent">
            <span class="balance-label">Total Spent</span>
            <span class="balance-value" id="balance-total-spent">--</span>
        </div>
        <div class="balance-item balance-grant" id="balance-grant-wrapper" style="display:none;">
            <span class="balance-label">Credits</span>
            <span class="balance-value" id="balance-grant">--</span>
        </div>
    </div>
</div>
<?php endif; ?>
