<?php
// App Shell - Unified TikTok Campaign Launcher Interface
require_once __DIR__ . '/includes/Security.php';
Security::init();
session_start();

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}

// Check session timeout (1 hour of inactivity)
$lastActivity = $_SESSION['last_activity'] ?? 0;
if (time() - $lastActivity > 3600) {
    session_destroy();
    session_start();
    header('Location: index.php');
    exit;
}
$_SESSION['last_activity'] = time();

// OAuth / Account state
$isConnected = isset($_SESSION['oauth_access_token']) && !empty($_SESSION['oauth_access_token']);
$advertiserIds = $_SESSION['oauth_advertiser_ids'] ?? [];
$advertiserDetails = $_SESSION['oauth_advertiser_details'] ?? [];
$currentAdvertiserId = $_SESSION['selected_advertiser_id'] ?? '';

// Determine current view from query param
$validViews = ['campaigns', 'create-smart', 'create-manual'];
$view = $_GET['view'] ?? 'campaigns';
if (!in_array($view, $validViews)) {
    $view = 'campaigns';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok Campaign Launcher</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/smart-campaign.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/shell.css?v=<?php echo time(); ?>">
    <style>
        /* ============================================
           INLINE STYLES (from smart-campaign.php)
           Required for campaign views & create forms
           ============================================ */
        .smart-badge {
            background: linear-gradient(135deg, rgb(30, 157, 241), rgb(26, 138, 216));
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        .form-info.smart-info {
            background: rgb(227, 236, 246);
            border-left: 4px solid rgb(30, 157, 241);
        }
        /* Video Selection Grid */
        .video-select-item {
            border: 2px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 4px);
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }
        .video-select-item:hover {
            border-color: rgb(30, 157, 241);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 157, 241, 0.1);
        }
        .video-select-item.selected {
            border-color: rgb(0, 184, 122);
            background: rgb(227, 236, 246);
        }
        .video-select-item .video-preview {
            position: relative;
            height: 100px;
            background: rgb(247, 249, 250);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .video-select-item .video-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .video-select-item .video-badge {
            position: absolute;
            top: 5px;
            left: 5px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }
        .video-select-item .selected-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgb(0, 184, 122);
            color: white;
            padding: 4px 8px;
            border-radius: 50%;
            font-size: 14px;
            font-weight: bold;
        }
        .video-select-item .video-name {
            padding: 8px;
            font-size: 12px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Creative Items */
        .creative-item {
            background: white;
            border: 1px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 4px);
            overflow: hidden;
        }
        .creative-item .creative-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: rgb(247, 248, 248);
            border-bottom: 1px solid rgb(225, 234, 239);
        }
        .creative-item .creative-video-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .creative-item .creative-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
        }
        .creative-item .creative-number {
            font-weight: 600;
            color: rgb(30, 157, 241);
        }
        .creative-item .creative-video-name {
            color: rgb(15, 20, 25);
            font-size: 13px;
        }
        .creative-item .btn-remove {
            background: rgb(244, 33, 46);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 5px 10px;
            cursor: pointer;
        }
        .creative-item .creative-body {
            padding: 15px;
        }
        .creative-item .creative-body textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid rgb(225, 234, 239);
            border-radius: calc(1.3rem - 6px);
            resize: vertical;
        }
        /* Age Selection Toggle Buttons */
        .age-selection-container {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .age-toggle-btn {
            padding: 8px 16px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
            color: #333;
            transition: all 0.2s ease;
        }
        .age-toggle-btn:hover {
            border-color: #00b8a9;
        }
        .age-toggle-btn.selected {
            border-color: #00b8a9;
            background: #e6f7f5;
            color: #00b8a9;
        }
        .age-toggle-btn.selected::after {
            content: ' \2713';
            font-size: 12px;
        }
        /* Duplicate Mode Options */
        .duplicate-mode-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .duplicate-mode-option {
            display: block;
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #fff;
        }
        .duplicate-mode-option:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .duplicate-mode-option.selected {
            border-color: #667eea;
            background: #f0f4ff;
        }
        .duplicate-mode-option input[type="radio"] {
            display: none;
        }
        .mode-option-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .mode-icon {
            font-size: 28px;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            border-radius: 10px;
        }
        .duplicate-mode-option.selected .mode-icon {
            background: #e0e7ff;
        }
        .mode-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .mode-title {
            font-weight: 600;
            font-size: 15px;
            color: #1e293b;
        }
        .mode-desc {
            font-size: 13px;
            color: #64748b;
        }
        /* Campaign Metrics Table Styles */
        .campaign-metrics-table-container {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .metrics-table-wrapper {
            overflow-x: auto;
            max-height: calc(100vh - 380px);
            overflow-y: auto;
            position: relative;
        }
        .metrics-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .metrics-table thead {
            background: #f8fafc;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .metrics-table th {
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        .metrics-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .metrics-table tbody tr:hover {
            background: #f8fafc;
        }
        .metrics-table .col-checkbox { width: 40px; text-align: center; }
        .metrics-table .col-toggle { width: 60px; }
        .metrics-table .col-name { min-width: 200px; }
        .metrics-table .col-status { width: 100px; }
        .metrics-table .col-budget,
        .metrics-table .col-spend,
        .metrics-table .col-cpc,
        .metrics-table .col-cpr { width: 90px; text-align: right; }
        .metrics-table .col-impressions,
        .metrics-table .col-clicks,
        .metrics-table .col-conversions,
        .metrics-table .col-results { width: 100px; text-align: right; }
        .metrics-table .col-ctr { width: 70px; text-align: right; }
        .metrics-table .col-actions { width: 100px; text-align: center; }
        /* Row Levels */
        .metrics-table .row-campaign { background: #fff; }
        .metrics-table .row-adgroup { background: #f8fafc; }
        .metrics-table .row-ad { background: #f1f5f9; }
        .metrics-table .row-adgroup td:first-child,
        .metrics-table .row-ad td:first-child { padding-left: 20px; }
        /* Totals Footer */
        .metrics-table tfoot {
            background: #f8fafc;
            border-top: 2px solid #e2e8f0;
            position: sticky;
            bottom: 0;
            z-index: 5;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
        }
        .metrics-table tfoot tr { font-weight: 600; }
        .metrics-table tfoot td { padding: 12px 10px; border-bottom: 1px solid #e2e8f0; }
        .metrics-table tfoot .totals-label { font-weight: 700; color: #1e293b; }
        .metrics-table tfoot .totals-row-all { background: #e0f2fe; }
        .metrics-table tfoot .totals-row-active { background: #dcfce7; }
        .metrics-table tfoot .totals-row-inactive { background: #fef3c7; }
        .metrics-table tfoot .totals-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 6px;
        }
        .metrics-table tfoot .badge-all { background: #0284c7; color: white; }
        .metrics-table tfoot .badge-active { background: #16a34a; color: white; }
        .metrics-table tfoot .badge-inactive { background: #d97706; color: white; }
        /* Expand/Collapse */
        .expand-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            color: #64748b;
            transition: all 0.2s;
        }
        .expand-btn:hover { background: #e2e8f0; color: #1e293b; }
        .expand-btn.expanded { transform: rotate(90deg); }
        /* Name Cell */
        .name-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .name-cell .entity-icon { font-size: 16px; width: 24px; text-align: center; }
        .name-cell .entity-name { font-weight: 500; color: #1e293b; }
        .name-cell .entity-id { font-size: 11px; color: #94a3b8; margin-left: 8px; }
        .name-cell .smart-badge-small {
            font-size: 10px;
            padding: 2px 6px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 4px;
            margin-left: 8px;
        }
        /* Status Badge */
        .status-badge-table {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-badge-table.active { background: #dcfce7; color: #16a34a; }
        .status-badge-table.inactive { background: #fee2e2; color: #dc2626; }
        .status-badge-table.paused { background: #fef3c7; color: #d97706; }

        /* Ad Delivery Status Badges */
        .ad-delivery-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.2px;
        }
        .ad-delivery-badge.delivering { background: #dcfce7; color: #16a34a; }
        .ad-delivery-badge.active { background: #dcfce7; color: #16a34a; }
        .ad-delivery-badge.rejected { background: #fef2f2; color: #dc2626; }
        .ad-delivery-badge.under-review { background: #fef3c7; color: #d97706; }
        .ad-delivery-badge.inactive { background: #f1f5f9; color: #64748b; }
        .ad-delivery-badge.no-budget { background: #fef3c7; color: #b45309; }

        /* Reject reason text */
        .ad-reject-reason {
            font-size: 11px;
            color: #dc2626;
            margin-top: 3px;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: help;
            line-height: 1.3;
        }

        /* Appeal button */
        .btn-appeal {
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 600;
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-appeal:hover { background: #fee2e2; border-color: #f87171; }

        /* Appeal Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }
        .appeal-modal-content {
            background: white;
            border-radius: 12px;
            max-width: 480px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .appeal-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        .appeal-modal-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
        }
        .appeal-modal-close {
            background: none;
            border: none;
            font-size: 22px;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
        }
        .appeal-modal-close:hover { color: #475569; }
        .appeal-modal-body {
            padding: 20px;
        }
        .appeal-ad-name {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 12px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 6px;
        }
        .appeal-modal-body label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .appeal-modal-body textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            transition: border-color 0.15s;
        }
        .appeal-modal-body textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .appeal-char-count {
            text-align: right;
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }
        .appeal-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 16px 20px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        /* Rejected Ads Filter Button */
        .btn-rejected-filter {
            border-color: #fca5a5 !important;
            color: #dc2626 !important;
        }
        .btn-rejected-filter.active {
            background: #fef2f2 !important;
            border-color: #dc2626 !important;
        }

        /* Rejected Ads Panel */
        .rejected-ads-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .rejected-campaign-group {
            margin-bottom: 16px;
            border: 1px solid #fecaca;
            border-radius: 10px;
            overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .rejected-campaign-group:hover {
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.1);
        }
        .rejected-group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            background: #fef2f2;
            border-bottom: 1px solid #fecaca;
        }
        .rejected-group-name {
            font-weight: 700;
            color: #991b1b;
            font-size: 14px;
        }
        .rejected-group-count {
            font-size: 12px;
            color: #dc2626;
            font-weight: 600;
        }
        .rejected-group-body {
            background: white;
        }
        .rejected-ad-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #fee2e2;
        }
        .rejected-ad-row:last-child {
            border-bottom: none;
        }
        .rejected-ad-info {
            flex: 1;
            min-width: 0;
        }
        .rejected-ad-name {
            font-weight: 600;
            font-size: 13px;
            color: #1e293b;
        }
        .rejected-ad-reason {
            font-size: 11px;
            color: #dc2626;
            margin-top: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Rejected badge in multi-account headers */
        .agb-rejected {
            background: rgba(220, 38, 38, 0.1);
            color: #dc2626;
        }
        .agb-rejected:hover {
            background: rgba(220, 38, 38, 0.2);
        }

        /* Toggle in Table */
        .toggle-table {
            width: 44px;
            height: 24px;
            background: #e2e8f0;
            border-radius: 12px;
            position: relative;
            cursor: pointer;
            transition: all 0.2s;
        }
        .toggle-table.on { background: #22c55e; }
        .toggle-table .toggle-slider-table {
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            position: absolute;
            top: 2px;
            left: 2px;
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .toggle-table.on .toggle-slider-table { left: 22px; }
        .toggle-table.loading { opacity: 0.5; pointer-events: none; }
        /* Action Buttons */
        .action-btn-table {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            color: #64748b;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .action-btn-table:hover { background: #e0f2fe; border-color: #1e9df1; color: #1e9df1; }
        .action-btn-table svg { width: 16px; height: 16px; }
        .action-btn-table.duplicate-btn { background: #f0f9ff; border-color: #bae6fd; }
        .action-btn-table.duplicate-btn:hover { background: #1e9df1; border-color: #1e9df1; color: white; }
        .action-btn-table.duplicate-btn:hover svg { stroke: white; }
        /* Budget Cell */
        .budget-cell { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
        .edit-budget-btn {
            background: transparent; border: none; padding: 4px; cursor: pointer;
            opacity: 0; transition: all 0.2s; border-radius: 4px;
            display: flex; align-items: center; justify-content: center; color: #94a3b8;
        }
        .budget-cell:hover .edit-budget-btn { opacity: 1; }
        .edit-budget-btn:hover { background: #e0f2fe; color: #1e9df1; }
        .edit-budget-btn svg { width: 12px; height: 12px; }
        /* Inline Budget Editor */
        .inline-budget-editor { display: flex; align-items: center; gap: 6px; }
        .budget-input-wrapper {
            display: flex; align-items: center; background: white;
            border: 2px solid #1e9df1; border-radius: 6px; padding: 2px 8px;
        }
        .budget-currency { color: #64748b; font-size: 13px; font-weight: 500; }
        .budget-input {
            width: 70px; border: none; outline: none; font-size: 13px;
            font-weight: 500; padding: 4px; text-align: right; background: transparent;
        }
        .budget-input::-webkit-outer-spin-button,
        .budget-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .budget-input[type=number] { -moz-appearance: textfield; }
        .budget-actions { display: flex; gap: 2px; }
        .budget-save-btn, .budget-cancel-btn {
            width: 24px; height: 24px; border: none; border-radius: 4px;
            cursor: pointer; font-size: 14px; display: flex;
            align-items: center; justify-content: center; transition: all 0.2s;
        }
        .budget-save-btn { background: #10b981; color: white; }
        .budget-save-btn:hover { background: #059669; }
        .budget-save-btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .budget-cancel-btn { background: #f1f5f9; color: #64748b; }
        .budget-cancel-btn:hover { background: #e2e8f0; color: #475569; }
        /* Loading Row */
        .loading-row td { text-align: center; color: #94a3b8; padding: 20px; }
        .loading-row .mini-spinner {
            display: inline-block; width: 16px; height: 16px;
            border: 2px solid #e2e8f0; border-top-color: #667eea;
            border-radius: 50%; animation: spin 0.8s linear infinite;
            margin-right: 8px; vertical-align: middle;
        }
        .indent-1 { padding-left: 30px !important; }
        .indent-2 { padding-left: 50px !important; }
        /* Date Range Filter */
        .date-range-filter-container {
            display: flex; align-items: center; gap: 20px; padding: 15px 20px;
            background: #fff; border-radius: 12px; margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08); flex-wrap: wrap;
        }
        .date-range-presets { display: flex; gap: 8px; }
        .date-preset-btn {
            padding: 8px 16px; border: 1px solid #e2e8f0; background: #fff;
            border-radius: 8px; font-size: 13px; font-weight: 500;
            color: #64748b; cursor: pointer; transition: all 0.2s;
        }
        .date-preset-btn:hover { border-color: #1e9df1; color: #1e9df1; background: #f0f9ff; }
        .date-preset-btn.active {
            background: linear-gradient(135deg, #1e9df1, #1a8ad8);
            color: #fff; border-color: #1e9df1;
        }
        .date-range-picker {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;
        }
        .date-input-group { display: flex; align-items: center; gap: 8px; }
        .date-input-group label { font-size: 12px; font-weight: 600; color: #64748b; }
        .date-input-group input[type="date"] {
            padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 13px; color: #1e293b; background: #fff; cursor: pointer;
        }
        .date-input-group input[type="date"]:focus {
            outline: none; border-color: #1e9df1; box-shadow: 0 0 0 3px rgba(30, 157, 241, 0.1);
        }
        .btn-apply-date {
            padding: 8px 16px; background: linear-gradient(135deg, #1e9df1, #1a8ad8);
            color: #fff; border: none; border-radius: 6px; font-size: 13px;
            font-weight: 600; cursor: pointer; transition: all 0.2s;
        }
        .btn-apply-date:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(30, 157, 241, 0.3); }
        .date-range-display {
            display: flex; align-items: center; gap: 8px; margin-left: auto;
            padding: 8px 15px; background: #f0f9ff; border-radius: 8px; border: 1px solid #bae6fd;
        }
        .date-range-label { font-size: 12px; color: #0369a1; }
        .date-range-value { font-size: 13px; font-weight: 600; color: #0c4a6e; }
        /* Campaign Filters & Search */
        .campaigns-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 15px; flex-wrap: wrap; gap: 10px;
        }
        .campaign-filters {
            display: flex; gap: 8px; margin-bottom: 15px;
        }
        .campaign-filter-btn {
            padding: 8px 20px; border: 1px solid #e2e8f0; background: #fff;
            border-radius: 8px; font-size: 13px; font-weight: 500; color: #64748b;
            cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
        }
        .campaign-filter-btn:hover { border-color: #1e9df1; color: #1e9df1; }
        .campaign-filter-btn.active {
            background: linear-gradient(135deg, #1e9df1, #1a8ad8);
            color: #fff; border-color: #1e9df1;
        }
        .filter-count {
            background: rgba(255,255,255,0.25); padding: 1px 8px;
            border-radius: 10px; font-size: 11px;
        }
        .campaign-filter-btn:not(.active) .filter-count {
            background: #f1f5f9; color: #64748b;
        }
        .campaign-search-container { margin-bottom: 15px; }
        .campaign-search-input {
            width: 100%; padding: 10px 16px; border: 2px solid #e2e8f0;
            border-radius: 10px; font-size: 14px; font-family: var(--font-sans);
            transition: border-color 0.2s; background: #fff;
        }
        .campaign-search-input:focus {
            outline: none; border-color: #1e9df1; box-shadow: 0 0 0 3px rgba(30, 157, 241, 0.1);
        }
        /* Bulk Actions */
        .bulk-actions-bar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 16px; background: #f8fafc; border-radius: 8px;
            margin-bottom: 15px; border: 1px solid #e2e8f0;
        }
        .bulk-select-controls { display: flex; align-items: center; gap: 12px; }
        .select-all-checkbox { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; }
        .selected-count { font-size: 12px; color: #64748b; }
        .bulk-action-buttons { display: flex; gap: 8px; }
        .btn-bulk-action {
            padding: 6px 14px; border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.2s;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-bulk-action.btn-enable { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
        .btn-bulk-action.btn-enable:hover { background: #16a34a; color: white; }
        .btn-bulk-action.btn-disable { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .btn-bulk-action.btn-disable:hover { background: #dc2626; color: white; }
        /* Campaign Loading & Empty State */
        .campaign-loading {
            display: flex; flex-direction: column; align-items: center;
            padding: 60px 20px; color: #64748b;
        }
        .campaign-empty-state {
            text-align: center; padding: 60px 20px;
        }
        .campaign-empty-state h3 { color: #1e293b; margin-bottom: 8px; }
        .campaign-empty-state p { color: #64748b; margin-bottom: 16px; }
        /* Ad Account Selector (legacy compat) */
        .ad-account-selector-container {
            display: flex; align-items: center; gap: 12px; padding: 12px 20px;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 12px; margin-bottom: 15px; border: 1px solid #bae6fd;
        }
        .ad-account-label { font-size: 13px; font-weight: 600; color: #0369a1; white-space: nowrap; }
        .ad-account-info {
            display: flex; align-items: center; gap: 8px; padding: 8px 12px;
            background: #fff; border-radius: 6px; font-size: 12px; color: #64748b;
        }
        .ad-account-info .account-count { font-weight: 600; color: #0369a1; }
        .ad-account-switching { display: none; align-items: center; gap: 8px; color: #0369a1; font-size: 13px; }
        .ad-account-switching .mini-spinner { width: 16px; height: 16px; border-width: 2px; }
        .ad-account-option:hover { background: #f1f5f9 !important; }
        .ad-account-option.selected { background: #e0f2fe !important; border-left: 3px solid #0284c7; }
        .ad-account-option:last-child { border-bottom: none !important; }
        .ad-account-search-input:focus { outline: none; border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1); }
        /* Refresh Button */
        .refresh-btn {
            background: transparent; border: 1px solid #e2e8f0; border-radius: 6px;
            padding: 6px 10px; cursor: pointer; font-size: 12px; color: #64748b;
            display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s;
            min-width: 32px; justify-content: center;
        }
        .refresh-btn:hover { background: #f1f5f9; border-color: #cbd5e1; color: #334155; }
        .refresh-btn:disabled, .refresh-btn.loading { pointer-events: none; opacity: 0.6; }
        .refresh-btn .spinner { display: inline-block; animation: refreshSpin 1s linear infinite; }
        @keyframes refreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        /* Processing status */
        .upload-item-status.processing { background: #fef3c7; color: #92400e; }
        /* Responsive */
        @media (max-width: 768px) {
            .date-range-filter-container { flex-direction: column; align-items: stretch; }
            .date-range-presets { flex-wrap: wrap; }
            .date-range-display { margin-left: 0; justify-content: center; }
            .metrics-table-wrapper { max-height: calc(100vh - 300px); }
            .metrics-table { font-size: 11px; }
            .metrics-table th, .metrics-table td { padding: 8px 5px; }
            .metrics-table .col-name { min-width: 120px; }
            .metrics-table .col-cpc, .metrics-table .col-cpr, .metrics-table .col-ctr { display: none; }
            .ad-account-selector-container { flex-direction: column; align-items: stretch; padding: 10px; }
            .date-range-picker { flex-direction: column; gap: 10px; }
            .date-input-group { width: 100%; }
            .date-input-group input[type="date"] { flex: 1; }
            .date-preset-btn { padding: 8px 10px; font-size: 11px; }
            /* Campaign filter buttons responsive */
            .campaign-filters { flex-wrap: wrap; gap: 6px; }
            .campaign-filter-btn { padding: 7px 12px; font-size: 12px; }
            /* Bulk actions responsive */
            .bulk-actions-bar { flex-direction: column; gap: 8px; align-items: stretch; }
            .bulk-action-buttons { justify-content: flex-start; }
            /* Rejected ads panel responsive */
            .rejected-ads-header { gap: 10px; }
            .rejected-ads-header h3 { font-size: 16px; }
            .rejected-group-header { padding: 8px 12px; }
            .rejected-group-name { font-size: 13px; word-break: break-word; }
            .rejected-ad-row { flex-direction: column; align-items: flex-start; gap: 8px; padding: 10px 12px; }
            .rejected-ad-info { width: 100%; }
            .rejected-ad-name { font-size: 12px; word-break: break-word; white-space: normal; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
            .rejected-ad-reason { white-space: normal; word-break: break-word; font-size: 11px; }
            .btn-appeal { align-self: flex-start; }
        }
        @media (max-width: 380px) {
            .metrics-table { font-size: 10px; }
            .metrics-table th, .metrics-table td { padding: 6px 3px; }
            .metrics-table .col-impressions, .metrics-table .col-clicks { display: none; }
            /* Campaign filters extra-small */
            .campaign-filters { gap: 4px; }
            .campaign-filter-btn { padding: 6px 10px; font-size: 11px; gap: 4px; }
            .filter-count { padding: 1px 6px; font-size: 10px; }
            /* Rejected ads extra-small */
            .rejected-ads-header { flex-wrap: wrap; }
            .rejected-ads-header h3 { font-size: 15px; }
            .rejected-group-header { flex-direction: column; align-items: flex-start; gap: 4px; padding: 8px 10px; }
            .rejected-ad-row { padding: 8px 10px; }
            .rejected-ad-name { font-size: 11px; }
        }
    </style>
</head>
<body class="app-shell">
    <!-- Header -->
    <?php include __DIR__ . '/partials/header.php'; ?>

    <div class="app-layout">
        <!-- Sidebar -->
        <?php include __DIR__ . '/partials/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content" id="main-content">

            <?php if (!$isConnected): ?>
                <!-- Not connected: show connect prompt -->
                <div class="shell-empty-state">
                    <div class="empty-icon">🔗</div>
                    <h3>Connect Your TikTok Account</h3>
                    <p>Link your TikTok Ads account to start managing campaigns, viewing metrics, and launching ads.</p>
                    <a href="oauth-init.php" class="btn-connect-large">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                        </svg>
                        Connect TikTok Account
                    </a>
                </div>

            <?php elseif (!$currentAdvertiserId && $isConnected): ?>
                <!-- Connected but no account selected -->
                <?php include __DIR__ . '/partials/account-panel.php'; ?>
                <div class="shell-empty-state">
                    <div class="empty-icon">👆</div>
                    <h3>Select an Ad Account</h3>
                    <p>Choose an ad account from the dropdown above to get started.</p>
                </div>

            <?php else: ?>
                <!-- Connected + account selected: show account panel + active view -->
                <?php include __DIR__ . '/partials/account-panel.php'; ?>

                <?php if ($view === 'campaigns'): ?>
                    <div class="view-panel" id="view-campaigns">
                        <?php include __DIR__ . '/partials/view-campaigns.php'; ?>
                    </div>

                <?php elseif ($view === 'create-smart'): ?>
                    <div class="view-panel" id="view-create-smart">
                        <?php include __DIR__ . '/partials/create-smart-content.php'; ?>
                    </div>

                <?php elseif ($view === 'create-manual'): ?>
                    <div class="view-panel" id="view-create-manual">
                        <?php include __DIR__ . '/partials/create-manual-content.php'; ?>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </main>
    </div>

    <!-- Pass advertiser ID to JavaScript -->
    <script>
        window.TIKTOK_ADVERTISER_ID = '<?php echo htmlspecialchars($currentAdvertiserId); ?>';
        window.APP_SHELL_MODE = true;
    </script>

    <!-- Shell JS (always loaded) -->
    <script src="assets/shell.js?v=<?php echo time(); ?>"></script>

    <!-- View-specific JS (only loaded when connected to avoid API errors) -->
    <?php if ($isConnected && ($view === 'campaigns' || $view === 'create-smart')): ?>
        <script src="assets/smart-campaign.js?v=<?php echo time(); ?>"></script>
    <?php elseif ($isConnected && $view === 'create-manual'): ?>
        <script src="assets/app.js?v=<?php echo time(); ?>"></script>
    <?php endif; ?>
</body>
</html>
