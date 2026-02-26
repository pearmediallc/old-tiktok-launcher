<?php
session_start();

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}

// Check if advertiser is selected
if (!isset($_SESSION['selected_advertiser_id'])) {
    header('Location: select-advertiser.php');
    exit;
}

// Get available advertiser accounts for the dropdown
$advertiserIds = $_SESSION['oauth_advertiser_ids'] ?? [];
$advertiserDetails = $_SESSION['oauth_advertiser_details'] ?? [];
$currentAdvertiserId = $_SESSION['selected_advertiser_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart+ Campaign - TikTok Campaign Launcher</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
    <style>
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
            content: ' ✓';
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
        /* Row Levels (Campaign, Ad Group, Ad) */
        .metrics-table .row-campaign { background: #fff; }
        .metrics-table .row-adgroup { background: #f8fafc; }
        .metrics-table .row-ad { background: #f1f5f9; }
        .metrics-table .row-adgroup td:first-child,
        .metrics-table .row-ad td:first-child { padding-left: 20px; }
        /* Totals Footer - Sticky at bottom */
        .metrics-table tfoot {
            background: #f8fafc;
            border-top: 2px solid #e2e8f0;
            position: sticky;
            bottom: 0;
            z-index: 5;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
        }
        .metrics-table tfoot tr {
            font-weight: 600;
        }
        .metrics-table tfoot td {
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .metrics-table tfoot .totals-label {
            font-weight: 700;
            color: #1e293b;
        }
        .metrics-table tfoot .totals-row-all {
            background: #e0f2fe;
        }
        .metrics-table tfoot .totals-row-active {
            background: #dcfce7;
        }
        .metrics-table tfoot .totals-row-inactive {
            background: #fef3c7;
        }
        .metrics-table tfoot .totals-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 6px;
        }
        .metrics-table tfoot .badge-all {
            background: #0284c7;
            color: white;
        }
        .metrics-table tfoot .badge-active {
            background: #16a34a;
            color: white;
        }
        .metrics-table tfoot .badge-inactive {
            background: #d97706;
            color: white;
        }
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
        /* Name Cell with Icon */
        .name-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .name-cell .entity-icon {
            font-size: 16px;
            width: 24px;
            text-align: center;
        }
        .name-cell .entity-name {
            font-weight: 500;
            color: #1e293b;
        }
        .name-cell .entity-id {
            font-size: 11px;
            color: #94a3b8;
            margin-left: 8px;
        }
        .name-cell .smart-badge-small {
            font-size: 10px;
            padding: 2px 6px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 4px;
            margin-left: 8px;
        }
        /* Status Badge in Table */
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
        .ad-reject-reason {
            font-size: 11px;
            color: #dc2626;
            margin-top: 3px;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: help;
        }
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
        }
        .btn-appeal:hover { background: #fee2e2; border-color: #f87171; }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
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
        .appeal-modal-header h3 { font-size: 16px; font-weight: 700; color: #1e293b; }
        .appeal-modal-close {
            background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer;
        }
        .appeal-modal-close:hover { color: #475569; }
        .appeal-modal-body { padding: 20px; }
        .appeal-ad-name {
            font-size: 13px; font-weight: 600; color: #475569;
            margin-bottom: 12px; padding: 8px 12px; background: #f8fafc; border-radius: 6px;
        }
        .appeal-modal-body label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .appeal-modal-body textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px;
            font-size: 13px; font-family: inherit; resize: vertical; min-height: 100px;
        }
        .appeal-modal-body textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .appeal-char-count { text-align: right; font-size: 11px; color: #94a3b8; margin-top: 4px; }
        .appeal-modal-footer {
            display: flex; justify-content: flex-end; gap: 8px;
            padding: 16px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc;
        }

        /* Rejected Ads Filter Button */
        .btn-rejected-filter { border-color: #fca5a5 !important; color: #dc2626 !important; }
        .btn-rejected-filter.active { background: #fef2f2 !important; border-color: #dc2626 !important; }

        /* Rejected Ads Panel */
        .rejected-ads-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .rejected-campaign-group { margin-bottom: 16px; border: 1px solid #fecaca; border-radius: 10px; overflow: hidden; transition: box-shadow 0.2s; }
        .rejected-campaign-group:hover { box-shadow: 0 2px 8px rgba(220, 38, 38, 0.1); }
        .rejected-group-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; background: #fef2f2; border-bottom: 1px solid #fecaca; }
        .rejected-group-name { font-weight: 700; color: #991b1b; font-size: 14px; }
        .rejected-group-count { font-size: 12px; color: #dc2626; font-weight: 600; }
        .rejected-group-body { background: white; }
        .rejected-ad-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #fee2e2; }
        .rejected-ad-row:last-child { border-bottom: none; }
        .rejected-ad-info { flex: 1; min-width: 0; }
        .rejected-ad-name { font-weight: 600; font-size: 13px; color: #1e293b; }
        .rejected-ad-reason { font-size: 11px; color: #dc2626; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

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
        /* Action Buttons in Table */
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
        .action-btn-table.optimizer-monitor-btn { background: #f8fafc; border-color: #e2e8f0; }
        .action-btn-table.optimizer-monitor-btn:hover { background: #ecfdf5; border-color: #6ee7b7; color: #059669; }
        .action-btn-table.optimizer-monitor-btn:hover svg { stroke: #059669; }
        .action-btn-table.optimizer-monitor-btn.monitoring { background: #ecfdf5; border-color: #6ee7b7; color: #059669; }
        .action-btn-table.optimizer-monitor-btn.monitoring svg { stroke: #059669; fill: #d1fae5; }
        .action-btn-table.optimizer-monitor-btn.paused-by-opt { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }
        .action-btn-table.optimizer-monitor-btn.paused-by-opt svg { stroke: #dc2626; fill: #fee2e2; }
        @keyframes optPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .action-btn-table.optimizer-monitor-btn.paused-by-opt { animation: optPulse 2s ease-in-out infinite; }

        /* Budget Cell and Edit Button */
        .budget-cell {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }
        .edit-budget-btn {
            background: transparent;
            border: none;
            padding: 4px;
            cursor: pointer;
            opacity: 0;
            transition: all 0.2s;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
        }
        .budget-cell:hover .edit-budget-btn { opacity: 1; }
        .edit-budget-btn:hover { background: #e0f2fe; color: #1e9df1; }
        .edit-budget-btn svg { width: 12px; height: 12px; }

        /* Inline Budget Editor */
        .inline-budget-editor {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .budget-input-wrapper {
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #1e9df1;
            border-radius: 6px;
            padding: 2px 8px;
        }
        .budget-currency {
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
        }
        .budget-input {
            width: 70px;
            border: none;
            outline: none;
            font-size: 13px;
            font-weight: 500;
            padding: 4px;
            text-align: right;
            background: transparent;
        }
        .budget-input::-webkit-outer-spin-button,
        .budget-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .budget-input[type=number] { -moz-appearance: textfield; }
        .budget-actions {
            display: flex;
            gap: 2px;
        }
        .budget-save-btn, .budget-cancel-btn {
            width: 24px;
            height: 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .budget-save-btn {
            background: #10b981;
            color: white;
        }
        .budget-save-btn:hover { background: #059669; }
        .budget-save-btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .budget-cancel-btn {
            background: #f1f5f9;
            color: #64748b;
        }
        .budget-cancel-btn:hover { background: #e2e8f0; color: #475569; }

        /* Loading Row */
        .loading-row td { text-align: center; color: #94a3b8; padding: 20px; }
        .loading-row .mini-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        /* Indent for nested rows */
        .indent-1 { padding-left: 30px !important; }
        .indent-2 { padding-left: 50px !important; }

        /* Date Range Filter Styles */
        .date-range-filter-container {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 15px 20px;
            background: #fff;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            flex-wrap: wrap;
        }
        .date-range-presets {
            display: flex;
            gap: 8px;
        }
        .date-preset-btn {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
        }
        .date-preset-btn:hover {
            border-color: #1e9df1;
            color: #1e9df1;
            background: #f0f9ff;
        }
        .date-preset-btn.active {
            background: linear-gradient(135deg, #1e9df1, #1a8ad8);
            color: #fff;
            border-color: #1e9df1;
        }
        .date-range-picker {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .date-input-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .date-input-group label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }
        .date-input-group input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
            color: #1e293b;
            background: #fff;
            cursor: pointer;
        }
        .date-input-group input[type="date"]:focus {
            outline: none;
            border-color: #1e9df1;
            box-shadow: 0 0 0 3px rgba(30, 157, 241, 0.1);
        }
        .btn-apply-date {
            padding: 8px 16px;
            background: linear-gradient(135deg, #1e9df1, #1a8ad8);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-apply-date:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 157, 241, 0.3);
        }
        .date-range-display {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
            padding: 8px 15px;
            background: #f0f9ff;
            border-radius: 8px;
            border: 1px solid #bae6fd;
        }
        .date-range-label {
            font-size: 12px;
            color: #0369a1;
        }
        .date-range-value {
            font-size: 13px;
            font-weight: 600;
            color: #0c4a6e;
        }
        @media (max-width: 768px) {
            .date-range-filter-container {
                flex-direction: column;
                align-items: stretch;
            }
            .date-range-presets {
                flex-wrap: wrap;
            }
            .date-range-display {
                margin-left: 0;
                justify-content: center;
            }
            /* Metrics table mobile */
            .metrics-table-wrapper {
                max-height: calc(100vh - 300px);
            }
            .metrics-table {
                font-size: 11px;
            }
            .metrics-table th,
            .metrics-table td {
                padding: 8px 5px;
            }
            .metrics-table .col-name {
                min-width: 120px;
            }
            .metrics-table .col-cpc,
            .metrics-table .col-cpr,
            .metrics-table .col-ctr {
                display: none;
            }
            /* Ad account selector mobile */
            .ad-account-selector-container {
                flex-direction: column;
                align-items: stretch;
                padding: 10px;
            }
            .ad-account-select {
                max-width: 100%;
            }
            /* Date picker mobile */
            .date-range-picker {
                flex-direction: column;
                gap: 10px;
            }
            .date-input-group {
                width: 100%;
            }
            .date-input-group input[type="date"] {
                flex: 1;
            }
            /* Date preset buttons */
            .date-preset-btn {
                padding: 8px 10px;
                font-size: 11px;
            }
            /* Filter tabs mobile */
            .campaign-filter-tabs {
                flex-wrap: wrap;
                gap: 6px;
            }
            .campaign-filter-tabs button {
                flex: 1;
                min-width: 70px;
                font-size: 11px;
                padding: 8px 10px;
            }
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
            .metrics-table {
                font-size: 10px;
            }
            .metrics-table th,
            .metrics-table td {
                padding: 6px 3px;
            }
            .metrics-table .col-impressions,
            .metrics-table .col-clicks {
                display: none;
            }
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

        /* Ad Account Selector Styles */
        .ad-account-selector-container {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 12px;
            margin-bottom: 15px;
            border: 1px solid #bae6fd;
        }
        .ad-account-label {
            font-size: 13px;
            font-weight: 600;
            color: #0369a1;
            white-space: nowrap;
        }
        .ad-account-select {
            flex: 1;
            max-width: 400px;
            padding: 10px 35px 10px 15px;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%230369a1' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 12px center;
            border: 2px solid #0ea5e9;
            border-radius: 8px;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            transition: all 0.2s;
        }
        .ad-account-select:hover {
            border-color: #0284c7;
            box-shadow: 0 2px 8px rgba(14, 165, 233, 0.2);
        }
        .ad-account-select:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
        }
        .ad-account-select option {
            padding: 10px;
        }
        .ad-account-info {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #fff;
            border-radius: 6px;
            font-size: 12px;
            color: #64748b;
        }
        .ad-account-info .account-count {
            font-weight: 600;
            color: #0369a1;
        }
        .ad-account-switching {
            display: none;
            align-items: center;
            gap: 8px;
            color: #0369a1;
            font-size: 13px;
        }
        .ad-account-switching .mini-spinner {
            width: 16px;
            height: 16px;
            border-width: 2px;
        }

        /* Ad Account Search Dropdown Styles */
        .ad-account-option:hover {
            background: #f1f5f9 !important;
        }
        .ad-account-option.selected {
            background: #e0f2fe !important;
            border-left: 3px solid #0284c7;
        }
        .ad-account-option:last-child {
            border-bottom: none !important;
        }
        .ad-account-search-input:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }

        /* Refresh Button Styles */
        .refresh-btn {
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 12px;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            min-width: 32px;
            justify-content: center;
        }
        .refresh-btn:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #334155;
        }
        .refresh-btn:disabled, .refresh-btn.loading {
            pointer-events: none;
            opacity: 0.6;
        }
        .refresh-btn .spinner {
            display: inline-block;
            animation: refreshSpin 1s linear infinite;
        }
        @keyframes refreshSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Processing status for video uploads */
        .upload-item-status.processing {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1>🚀 TikTok Campaign Launcher <span class="smart-badge">Smart+</span></h1>
            <div class="header-info">
                <div id="advertiser-timezone-info" style="font-size: 0.9rem; color: #666; margin-right: 15px;">
                    <span id="timezone-status">Loading...</span>
                </div>
                <button class="btn-secondary" onclick="window.location.href='app-shell.php?view=campaigns'" style="margin-right: 10px;">Back</button>
                <button class="btn-logout" onclick="logout()">Logout</button>
            </div>
        </header>

        <!-- Main View Tabs -->
        <div class="main-view-tabs">
            <button class="main-view-tab active" id="tab-create" onclick="switchMainView('create')">
                <span class="tab-icon">✏️</span> Create Campaign
            </button>
            <button class="main-view-tab" id="tab-campaigns" onclick="switchMainView('campaigns')">
                <span class="tab-icon">📋</span> My Campaigns
            </button>
            <button class="main-view-tab" id="tab-media" onclick="switchMainView('media')">
                <span class="tab-icon">🎬</span> Media Library
            </button>
        </div>

        <!-- CREATE VIEW (existing functionality) -->
        <div id="create-view">

        <!-- Progress Steps -->
        <div class="progress-steps">
            <div class="step active" data-step="1">
                <div class="step-number">1</div>
                <div class="step-label">Campaign</div>
            </div>
            <div class="step" data-step="2">
                <div class="step-number">2</div>
                <div class="step-label">Ad Group</div>
            </div>
            <div class="step" data-step="3">
                <div class="step-number">3</div>
                <div class="step-label">Ads</div>
            </div>
            <div class="step" data-step="4">
                <div class="step-number">4</div>
                <div class="step-label">Review & Publish</div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <!-- Step 1: Campaign Creation -->
            <div class="step-content active" id="step-1">
                <h2>Create Smart+ Campaign</h2>
                <div class="form-group">
                    <label>Campaign Name</label>
                    <input type="text" id="campaign-name" placeholder="Enter campaign name" required>
                </div>

                <div class="form-section">
                    <h3>Budget Settings</h3>
                    <div class="form-group">
                        <label>Daily Budget ($)</label>
                        <input type="number" id="campaign-budget" value="50" min="20" placeholder="50">
                        <small>Minimum $20 daily budget. TikTok will optimize spend across ad groups.</small>
                    </div>
                </div>

                <div class="form-info smart-info">
                    <p><strong>Objective:</strong> Lead Generation</p>
                    <p><strong>Type:</strong> Smart+ Campaign (AI-Optimized)</p>
                    <p><strong>Budget Mode:</strong> Dynamic Daily Budget (CBO)</p>
                </div>
                <button class="btn-primary" onclick="createCampaign()">Create Campaign →</button>
            </div>

            <!-- Step 2: Ad Group Creation -->
            <div class="step-content" id="step-2">
                <!-- Top Navigation with Back Button -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <button class="btn-secondary" onclick="prevStep()" style="display: flex; align-items: center; gap: 6px;">
                        <span>←</span> Back to Campaign
                    </button>
                    <span style="color: #666; font-size: 14px;">Step 2 of 4</span>
                </div>
                <h2>Smart+ Ad Group Settings</h2>
                <div class="form-info" style="margin-bottom: 20px; background: #e8f5e9; padding: 12px; border-radius: 6px;">
                    <p><strong>Campaign:</strong> <span id="display-campaign-name">-</span></p>
                    <p><strong>Campaign ID:</strong> <span id="display-campaign-id" style="color: #22c55e; font-weight: bold;">-</span></p>
                    <p><strong>Budget:</strong> $<span id="display-budget">-</span>/day (Campaign Level)</p>
                </div>

                <div class="form-section">
                    <h3>Schedule</h3>
                    <!-- Schedule Options -->
                    <div class="form-group" style="margin-top: 20px;">
                        <label style="font-weight: 600; margin-bottom: 12px; display: block;">Schedule</label>
                        <div class="schedule-options-container" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px;">
                            <!-- Option 1: Start Now, Run Continuously -->
                            <label class="schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px; background: white; border: 2px solid #1a1a1a; border-radius: 8px; cursor: pointer; margin-bottom: 10px;">
                                <input type="radio" name="schedule_type" value="continuous" checked onchange="toggleScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="color: #1e293b;">Start now and run continuously</strong>
                                    <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">Ad group will start immediately and run until manually turned off</p>
                                </div>
                            </label>

                            <!-- Option 2: Schedule Start Time Only (No End) -->
                            <label class="schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px; background: white; border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer; margin-bottom: 10px;">
                                <input type="radio" name="schedule_type" value="scheduled_start_only" onchange="toggleScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="color: #1e293b;">Schedule start time (run continuously)</strong>
                                    <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">Ad group will start at a specific date/time and run until manually turned off</p>
                                </div>
                            </label>

                            <!-- Option 3: Set Start and End Time -->
                            <label class="schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px; background: white; border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer;">
                                <input type="radio" name="schedule_type" value="scheduled" onchange="toggleScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="color: #1e293b;">Set start and end time</strong>
                                    <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">Ad group will run during the specified time period only</p>
                                </div>
                            </label>

                            <!-- Start Time Only Picker (for scheduled_start_only) -->
                            <div id="schedule-start-only-container" style="display: none; margin-top: 15px; padding: 15px; background: white; border: 1px solid #e2e8f0; border-radius: 8px;">
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label style="font-weight: 500; color: #475569; font-size: 14px;">Start Date & Time <span style="font-weight: 400; color: #3b82f6;">(Your Local Time)</span></label>
                                    <input type="datetime-local" id="schedule-start-only-datetime" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                    <div style="margin-top: 6px; padding: 8px 12px; background: #eff6ff; border-radius: 6px; display: flex; align-items: center; gap: 8px;">
                                        <span style="font-size: 16px;">🕐</span>
                                        <span style="font-size: 12px; color: #1e40af; font-weight: 500;">Your Local Time - auto-converted to EST for TikTok Ads Manager</span>
                                    </div>
                                </div>

                                <p style="margin: 0; color: #64748b; font-size: 12px; display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 14px;">ℹ️</span>
                                    Ad group will start at this time and run until you manually turn it off
                                </p>
                            </div>

                            <!-- DateTime Pickers for Start AND End (for scheduled) -->
                            <div id="schedule-datetime-container" style="display: none; margin-top: 15px; padding: 15px; background: white; border: 1px solid #e2e8f0; border-radius: 8px;">
                                <div style="margin-bottom: 12px; padding: 8px 12px; background: #eff6ff; border-radius: 6px; display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 16px;">🕐</span>
                                    <span style="font-size: 12px; color: #1e40af; font-weight: 500;">Your Local Time - auto-converted to EST for TikTok Ads Manager</span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <!-- Start Date/Time -->
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label style="font-weight: 500; color: #475569; font-size: 14px;">Start Date & Time <span style="font-weight: 400; color: #3b82f6;">(Your Local Time)</span></label>
                                        <input type="datetime-local" id="schedule-start-datetime" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                    </div>

                                    <!-- End Date/Time -->
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label style="font-weight: 500; color: #475569; font-size: 14px;">End Date & Time <span style="font-weight: 400; color: #3b82f6;">(Your Local Time)</span></label>
                                        <input type="datetime-local" id="schedule-end-datetime" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                    </div>
                                </div>

                                <p style="margin: 12px 0 0; color: #64748b; font-size: 12px; display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 14px;">ℹ️</span>
                                    Ad group will automatically start and stop at the specified times
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Pixel Configuration</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Pixel for Form Tracking</label>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <select id="pixel-select" style="flex: 1;">
                                    <option value="">Loading pixels...</option>
                                </select>
                                <button type="button" class="refresh-btn" id="pixel-refresh-btn" onclick="refreshPixels()" title="Refresh pixel list">
                                    <span id="pixel-refresh-icon">🔄</span>
                                </button>
                            </div>
                            <small>Required for External Website optimization</small>
                        </div>
                        <div class="form-group">
                            <label>Optimization Event</label>
                            <select id="optimization-event">
                                <option value="FORM">Form Submission</option>
                                <option value="COMPLETE_PAYMENT">Complete Payment</option>
                                <option value="REGISTRATION">Registration</option>
                                <option value="CONTACT">Contact</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Audience Targeting</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Age Targeting</label>
                            <div class="age-radio-container" id="age-selection-container">
                                <label class="age-radio-option">
                                    <input type="radio" name="age_targeting" value="18+" checked onchange="updateAgeSelection('18+')">
                                    <span class="age-radio-label">
                                        <strong>18+</strong>
                                        <small>All Adults (18-24, 25-34, 35-44, 45-54, 55+)</small>
                                    </span>
                                </label>
                                <label class="age-radio-option">
                                    <input type="radio" name="age_targeting" value="25+" onchange="updateAgeSelection('25+')">
                                    <span class="age-radio-label">
                                        <strong>25+</strong>
                                        <small>Older Adults (25-34, 35-44, 45-54, 55+)</small>
                                    </span>
                                </label>
                            </div>
                            <small>Select minimum age for targeting (matches TikTok Ads Manager options)</small>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Location Targeting</h3>
                    <div class="form-group">
                        <div class="location-method-container">
                            <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                <input type="radio" name="location_method" value="country" checked onchange="toggleLocationMethod()">
                                <span>Target Entire United States</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="radio" name="location_method" value="states" onchange="toggleLocationMethod()">
                                <span>Target Specific States</span>
                            </label>
                        </div>

                        <div id="country-targeting" style="margin-top: 10px;">
                            <div class="form-info">
                                <p><strong>Target:</strong> United States (Location ID: 6252001)</p>
                            </div>
                        </div>

                        <div id="states-targeting" style="display: none; margin-top: 10px;">
                            <!-- Bulk State Input Section -->
                            <div style="margin-bottom: 15px; padding: 15px; background: #e3f2fd; border-radius: 8px; border: 1px solid #90caf9;">
                                <label style="font-weight: 600; display: block; margin-bottom: 8px; color: #1565c0;">
                                    Quick Add States (Paste or Type)
                                </label>
                                <div style="display: flex; gap: 10px;">
                                    <input type="text" id="bulk-state-input"
                                           placeholder="Enter state names separated by commas (e.g., California, Texas, New York)"
                                           style="flex: 1; padding: 10px 12px; border: 1px solid #90caf9; border-radius: 6px; font-size: 14px;"
                                           onkeypress="if(event.key === 'Enter') { applyBulkStates(); event.preventDefault(); }">
                                    <button type="button" class="btn-primary" onclick="applyBulkStates()" style="white-space: nowrap;">
                                        Apply States
                                    </button>
                                </div>
                                <small style="display: block; margin-top: 8px; color: #666;">
                                    Paste comma-separated state names or abbreviations (e.g., "CA, TX, NY" or "California, Texas, New York"). Press Enter or click Apply.
                                </small>
                                <div id="bulk-state-feedback" style="display: none; margin-top: 10px; padding: 8px 12px; border-radius: 6px; font-size: 13px;"></div>
                            </div>

                            <div style="margin-bottom: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="button" class="btn-secondary" onclick="selectAllStates()">Select All States</button>
                                <button type="button" class="btn-secondary" onclick="clearAllStates()">Clear All</button>
                            </div>
                            <div class="states-grid" id="states-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; max-height: 300px; overflow-y: auto; padding: 10px; background: #f9f9f9; border-radius: 6px;">
                                <!-- States will be populated by JavaScript -->
                            </div>
                            <p style="margin-top: 10px;"><span id="selected-states-count">0</span> states selected</p>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Dayparting (Optional)</h3>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="enable-dayparting" onchange="toggleDayparting()">
                            Enable Dayparting (Select specific hours)
                        </label>
                    </div>

                    <div id="dayparting-section" style="display: none;">
                        <div id="account-timezone-display" style="margin-bottom: 10px; padding: 8px 12px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;"></div>
                        <p style="margin-bottom: 10px; font-size: 13px; color: #64748b;">Note: Each column represents a 1-hour slot. For example, "9A" covers 9:00 AM - 10:00 AM. Times are interpreted in your account's timezone.</p>
                        <div style="margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 10px;">
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('all')" title="All hours, all days">24/7 (All Hours)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('business')" title="8AM-5PM, Monday-Friday">Business (8AM-5PM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('office')" title="9AM-5PM, Monday-Friday">Office (9AM-5PM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('prime')" title="6PM-11PM, all days">Prime Time (6PM-11PM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('evening')" title="5PM-12AM, all days">Evening (5PM-12AM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('daytime')" title="6AM-6PM, all days">Daytime (6AM-6PM)</button>
                            <button type="button" class="btn-secondary" onclick="setDaypartingPreset('none')" title="Clear all selections">Clear All</button>
                        </div>
                        <div class="dayparting-grid">
                            <table class="dayparting-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th title="12:00 AM">12A</th>
                                        <th title="1:00 AM">1A</th>
                                        <th title="2:00 AM">2A</th>
                                        <th title="3:00 AM">3A</th>
                                        <th title="4:00 AM">4A</th>
                                        <th title="5:00 AM">5A</th>
                                        <th title="6:00 AM">6A</th>
                                        <th title="7:00 AM">7A</th>
                                        <th title="8:00 AM">8A</th>
                                        <th title="9:00 AM">9A</th>
                                        <th title="10:00 AM">10A</th>
                                        <th title="11:00 AM">11A</th>
                                        <th title="12:00 PM">12P</th>
                                        <th title="1:00 PM">1P</th>
                                        <th title="2:00 PM">2P</th>
                                        <th title="3:00 PM">3P</th>
                                        <th title="4:00 PM">4P</th>
                                        <th title="5:00 PM">5P</th>
                                        <th title="6:00 PM">6P</th>
                                        <th title="7:00 PM">7P</th>
                                        <th title="8:00 PM">8P</th>
                                        <th title="9:00 PM">9P</th>
                                        <th title="10:00 PM">10P</th>
                                        <th title="11:00 PM">11P</th>
                                    </tr>
                                </thead>
                                <tbody id="dayparting-body">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Optimization & Placement</h3>
                    <div class="form-info smart-info">
                        <p><strong>Promotion Type:</strong> Lead Generation (External Website)</p>
                        <p><strong>Optimization Goal:</strong> Conversions</p>
                        <p><strong>Billing Event:</strong> OCPM</p>
                        <p><strong>Placement:</strong> TikTok</p>
                    </div>
                </div>

                <div class="button-row">
                    <button class="btn-secondary" onclick="prevStep()">← Back</button>
                    <button class="btn-primary" onclick="createAdGroup()">Create Ad Group →</button>
                </div>
            </div>

            <!-- Step 3: Ads Creation -->
            <div class="step-content" id="step-3">
                <!-- Top Navigation with Back Button -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <button class="btn-secondary" onclick="prevStep()" style="display: flex; align-items: center; gap: 6px;">
                        <span>←</span> Back to Ad Group
                    </button>
                    <span style="color: #666; font-size: 14px;">Step 3 of 4</span>
                </div>
                <h2>Create Smart+ Ad</h2>
                <div class="form-info" style="margin-bottom: 20px; background: #e8f5e9; padding: 12px; border-radius: 6px;">
                    <p><strong>Campaign ID:</strong> <span id="display-campaign-id-step3" style="color: #22c55e; font-weight: bold;">-</span></p>
                    <p><strong>Ad Group ID:</strong> <span id="display-adgroup-id" style="color: #22c55e; font-weight: bold;">-</span></p>
                </div>
                <div class="form-info smart-info" style="margin-bottom: 20px;">
                    <p><strong>Smart+ Ad:</strong> Select multiple videos below. All videos will be combined into ONE ad with multiple creatives.</p>
                </div>

                <!-- Global Settings -->
                <div class="form-section" style="background: #f8f9ff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 2px solid #667eea;">
                    <h3 style="margin-top: 0; color: #667eea;">Ad Settings</h3>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Identity</label>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <select id="global-identity" required style="flex: 1;">
                                    <option value="">Select identity...</option>
                                </select>
                                <button type="button" class="refresh-btn" id="identity-refresh-btn" onclick="refreshIdentities()" title="Refresh identity list">
                                    <span id="identity-refresh-icon">🔄</span>
                                </button>
                            </div>
                            <button type="button" class="btn-secondary" onclick="openCreateIdentityModal()" style="margin-top: 8px; width: 100%;">+ Create New Identity</button>
                        </div>
                        <div class="form-group">
                            <label>Dynamic CTA Portfolio <span style="color: #ff0050;">*</span></label>
                            <select id="cta-portfolio-select" required>
                                <option value="">Loading portfolios...</option>
                            </select>
                            <div style="display: flex; gap: 10px; margin-top: 8px;">
                                <button type="button" class="btn-secondary" onclick="createLearnMorePortfolio()" style="flex: 1;">+ Create Learn_More</button>
                                <button type="button" class="btn-secondary" onclick="openCreatePortfolioModal()" style="flex: 1;">+ Create Portfolio</button>
                            </div>
                            <div id="selected-portfolio-info" style="display: none; margin-top: 10px; padding: 10px; background: #e8f5e9; border-radius: 6px;">
                                <strong>Selected:</strong> <span id="portfolio-name-display"></span><br>
                                <small>CTAs: <span id="portfolio-ctas-display"></span></small>
                            </div>
                            <small style="color: #666;">Lead Gen campaigns require a Dynamic CTA Portfolio. TikTok will optimize which CTA to show.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Landing Page URL</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="url" id="global-landing-url" placeholder="https://example.com/landing-page" required style="flex: 1;">
                            <button type="button" class="btn-secondary" onclick="testLandingUrl()">Test URL</button>
                        </div>
                    </div>
                </div>

                <!-- Media Library Section -->
                <div class="form-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0;">Media Library</h3>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn-secondary" onclick="refreshMediaLibrary()" title="Refresh from TikTok">🔄 Refresh</button>
                            <button class="btn-primary" onclick="openUploadModal('video')" style="background: linear-gradient(135deg, #667eea, #764ba2);">📹 Upload Video</button>
                            <button class="btn-primary" onclick="openUploadModal('image')" style="background: linear-gradient(135deg, #4fc3f7, #29b6f6);">🖼️ Upload Image</button>
                        </div>
                    </div>

                    <!-- Videos Section -->
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: #667eea;">🎬 Videos (<span id="selected-videos-count">0</span> selected)</h4>
                            <div>
                                <button class="btn-secondary btn-sm" onclick="selectAllVideos()">Select All</button>
                                <button class="btn-secondary btn-sm" onclick="clearVideoSelection()">Clear</button>
                            </div>
                        </div>
                        <div id="video-selection-grid" class="video-selection-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; max-height: 500px; overflow-y: auto; padding: 10px; background: #f9f9f9; border-radius: 8px; border: 2px solid #667eea;">
                            <p style="text-align: center; padding: 20px; color: #666;">Loading videos...</p>
                        </div>
                    </div>

                    <!-- Images Section -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: #4fc3f7;">🖼️ Images (<span id="images-count">0</span> available)</h4>
                        </div>
                        <div id="image-selection-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; max-height: 200px; overflow-y: auto; padding: 10px; background: #f0f9ff; border-radius: 8px; border: 2px solid #4fc3f7;">
                            <p style="text-align: center; padding: 20px; color: #666;">Loading images...</p>
                        </div>
                        <small style="color: #666; display: block; margin-top: 8px;">Images are used as cover images for videos. TikTok will auto-match or you can upload matching covers.</small>
                    </div>
                </div>

                <!-- Ad Text Section (Single text field like TikTok Ads Manager) -->
                <div class="form-section">
                    <h3>Identity and Text for your Ad</h3>
                    <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Your TikTok posts in this campaign will use the creator's original identity and text.</p>

                    <!-- Selected Videos Summary -->
                    <div id="selected-videos-summary" style="margin-bottom: 20px; padding: 15px; background: #f8f9ff; border-radius: 8px; border: 2px solid #667eea;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-weight: 600; color: #333;">Creative assets (<span id="creative-assets-count">0</span>/<span>50</span>)</span>
                            <button type="button" class="btn-secondary btn-sm" onclick="scrollToMediaSection()">✏️ Edit selections</button>
                        </div>
                        <div id="selected-videos-preview" style="display: flex; gap: 10px; flex-wrap: wrap; max-height: 120px; overflow-y: auto;">
                            <p style="color: #666; font-size: 13px;">No videos selected yet</p>
                        </div>
                    </div>

                    <!-- Ad Text Fields -->
                    <div class="form-group">
                        <label style="font-weight: 600;">Text <span style="color: #999; font-weight: normal;">(0/100)</span></label>
                        <div id="ad-text-fields" style="display: flex; flex-direction: column; gap: 10px;">
                            <div class="ad-text-field" style="display: flex; align-items: center; gap: 10px;">
                                <input type="text" id="ad-text-1" class="ad-text-input" placeholder="Enter text for your ad" maxlength="100" style="flex: 1;" oninput="updateTextCount(this)">
                                <span class="text-count" style="color: #999; font-size: 12px;">0/100</span>
                            </div>
                        </div>
                        <button type="button" id="add-text-btn" onclick="addAdTextField()" style="margin-top: 10px; background: none; border: none; color: #1e9df1; cursor: pointer; font-size: 14px; padding: 5px 0;">
                            + Add text
                        </button>
                        <small style="display: block; margin-top: 8px; color: #666;">Add multiple text variations. TikTok will automatically optimize which text performs best.</small>
                    </div>
                </div>

                <div class="button-row" style="margin-top: 20px;">
                    <button class="btn-secondary" onclick="prevStep()">← Back</button>
                    <button class="btn-primary" onclick="reviewAds()">Review & Publish →</button>
                </div>
            </div>

            <!-- Step 4: Review & Publish -->
            <div class="step-content" id="step-4">
                <h2>Review & Publish</h2>

                <div class="review-section">
                    <h3>Campaign Summary</h3>
                    <div id="campaign-summary" class="summary-card">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <div class="review-section">
                    <h3>Ad Group Summary</h3>
                    <div id="adgroup-summary" class="summary-card">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <div class="review-section">
                    <h3>Ads Summary</h3>
                    <div id="ads-summary" class="summary-list">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <!-- Duplicate Campaign Section (Single Launch) -->
                <div class="review-section" style="margin-top: 30px;">
                    <h3>Campaign Copies</h3>
                    <div class="duplicate-campaign-section" style="padding: 20px; background: #f8f9ff; border-radius: 8px; border: 2px solid #e0e0e0;">
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="enable-duplicates" onchange="toggleDuplicates()" style="width: 20px; height: 20px; accent-color: #667eea;">
                                <span style="font-weight: 600;">Create multiple copies of this campaign</span>
                            </label>
                            <small style="display: block; margin-top: 8px; margin-left: 30px; color: #666;">
                                Launch several identical campaigns at once with auto-numbered names.
                            </small>
                        </div>
                        <div id="duplicate-settings" style="display: none;">
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label style="font-weight: 500;">Total number of campaigns to create:</label>
                                <div style="display: flex; align-items: center; gap: 15px; margin-top: 8px;">
                                    <input type="number" id="duplicate-count" min="1" max="20" value="2"
                                           style="width: 80px; padding: 10px; border: 2px solid #667eea; border-radius: 6px; font-size: 16px; text-align: center;"
                                           onchange="updateDuplicatePreview()" oninput="updateDuplicatePreview()">
                                    <span style="color: #666;">campaigns (1-20)</span>
                                </div>
                                <small style="display: block; margin-top: 8px; color: #888;">
                                    Enter 1 to create just the original campaign, or more to create multiple copies.
                                </small>
                            </div>
                            <div class="duplicate-preview" style="margin-top: 15px; padding: 12px; background: white; border-radius: 6px; border: 1px solid #ddd;">
                                <p style="margin: 0 0 8px 0; font-weight: 500; color: #333;">Preview:</p>
                                <div id="duplicate-preview-names" style="font-size: 13px; color: #666;">
                                    <!-- Will be populated by JS -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Launch Options Section -->
                <div class="review-section" style="margin-top: 30px;">
                    <h3>Launch Options</h3>

                    <div class="launch-options-container">
                        <!-- Option 1: Single Account -->
                        <div class="launch-option-card" id="single-launch-option">
                            <div class="launch-option-header">
                                <input type="radio" name="launch_mode" value="single" id="launch-mode-single" checked onchange="toggleLaunchMode()">
                                <label for="launch-mode-single">
                                    <span class="launch-option-icon">🚀</span>
                                    <span class="launch-option-title">Launch to Current Account Only</span>
                                </label>
                            </div>
                            <div class="launch-option-body">
                                <p class="launch-option-desc">Launch campaign to the currently selected advertiser account.</p>
                                <div class="current-account-info">
                                    <span class="account-label">Account:</span>
                                    <span class="account-name" id="current-account-name">Loading...</span>
                                </div>
                            </div>
                        </div>

                        <!-- Option 2: Bulk Launch -->
                        <div class="launch-option-card" id="bulk-launch-option">
                            <div class="launch-option-header">
                                <input type="radio" name="launch_mode" value="bulk" id="launch-mode-bulk" onchange="toggleLaunchMode()">
                                <label for="launch-mode-bulk">
                                    <span class="launch-option-icon">⚡</span>
                                    <span class="launch-option-title">Bulk Launch to Multiple Accounts</span>
                                </label>
                            </div>
                            <div class="launch-option-body">
                                <p class="launch-option-desc">Launch the same campaign to multiple advertiser accounts at once.</p>
                                <div id="bulk-accounts-preview" style="display: none;">
                                    <span class="accounts-count"><span id="available-accounts-count">0</span> accounts available</span>
                                    <button type="button" class="btn-configure-bulk" onclick="openBulkLaunchModal()">Configure Accounts →</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Launch Summary (shows after configuration) -->
                    <div id="bulk-launch-summary" style="display: none; margin-top: 20px;">
                        <div class="bulk-summary-card">
                            <h4>Bulk Launch Configuration</h4>
                            <div class="bulk-summary-stats">
                                <div class="stat-item">
                                    <span class="stat-value" id="bulk-selected-count">0</span>
                                    <span class="stat-label">Accounts Selected</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-value" id="bulk-total-budget">$0</span>
                                    <span class="stat-label">Total Daily Budget</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-value" id="bulk-ready-count">0</span>
                                    <span class="stat-label">Ready to Launch</span>
                                </div>
                            </div>
                            <div id="bulk-accounts-list" class="bulk-accounts-list">
                                <!-- Populated by JavaScript -->
                            </div>
                            <button type="button" class="btn-secondary" onclick="openBulkLaunchModal()">Edit Configuration</button>
                        </div>
                    </div>
                </div>

                <div class="button-row">
                    <button class="btn-secondary" onclick="prevStep()">← Back</button>
                    <button class="btn-success" id="launch-button" onclick="handleLaunch()">🚀 Launch Campaign</button>
                </div>
            </div>
        </div>

        <!-- Upload Modal -->
        <div id="upload-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3 id="upload-modal-title">Upload Media</h3>
                    <span class="modal-close" onclick="closeUploadModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="upload-area" id="upload-area" style="text-align: center; padding: 40px; border: 2px dashed #ddd; border-radius: 8px; cursor: pointer;">
                        <input type="file" id="media-file-input" accept="image/*,video/*" multiple style="display: none;" onchange="handleSmartMediaUpload(event)">
                        <div onclick="document.getElementById('media-file-input').click()">
                            <div id="upload-icon" style="font-size: 50px; margin-bottom: 10px;">📁</div>
                            <p id="upload-text" style="font-size: 16px; color: #333;">Click to select files or drag and drop</p>
                            <p id="upload-hint" style="font-size: 12px; color: #666;">Supported: MP4, MOV, JPG, PNG (Multiple files allowed)</p>
                        </div>
                    </div>
                    <div id="upload-progress" style="display: none; margin-top: 20px;">
                        <div class="bulk-upload-header" style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span id="upload-status" style="font-weight: 600;">Uploading...</span>
                            <span id="upload-count">0/0</span>
                        </div>
                        <div style="background: #e0e0e0; border-radius: 10px; height: 8px; overflow: hidden;">
                            <div id="upload-progress-bar" style="background: linear-gradient(135deg, #667eea, #764ba2); height: 100%; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <div id="upload-file-list" style="max-height: 200px; overflow-y: auto; margin-top: 15px;">
                            <!-- Individual file progress items -->
                        </div>
                    </div>
                    <div id="upload-success" style="display: none; margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 8px; border: 2px solid #4caf50;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="font-size: 30px;">✅</div>
                            <div>
                                <p style="margin: 0; font-weight: 600; color: #2e7d32;">Upload Successful!</p>
                                <p id="upload-success-name" style="margin: 5px 0 0 0; font-size: 13px; color: #666;"></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeUploadModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- Bulk Launch Modal -->
        <div id="bulk-launch-modal" class="modal" style="display: none;">
            <div class="modal-content bulk-launch-modal-content">
                <div class="modal-header">
                    <h3>⚡ Bulk Launch Configuration</h3>
                    <span class="modal-close" onclick="closeBulkLaunchModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <!-- Campaign Info Banner -->
                    <div class="bulk-campaign-info">
                        <strong>Campaign:</strong> <span id="bulk-campaign-name">-</span> |
                        <strong>Budget:</strong> $<span id="bulk-campaign-budget">0</span>/day per account
                    </div>

                    <!-- Duplicate Campaign Section -->
                    <div class="bulk-section">
                        <h4>📋 Campaign Copies</h4>
                        <p class="bulk-section-desc">Create multiple copies of this campaign per account.</p>
                        <div class="duplicate-options">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" id="bulk-enable-duplicates" onchange="toggleBulkDuplicates()" style="width: 18px; height: 18px;">
                                    <span>Create multiple campaign copies per account</span>
                                </label>
                            </div>
                            <div id="bulk-duplicate-settings" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9ff; border-radius: 8px; border: 1px solid #667eea;">
                                <div class="form-group" style="margin-bottom: 10px;">
                                    <label>Total campaigns per account:</label>
                                    <input type="number" id="bulk-duplicate-count" min="1" max="10" value="1" style="width: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                <p style="margin: 0; font-size: 12px; color: #666;">
                                    Campaign names will be auto-numbered: "Campaign Name (1)", "Campaign Name (2)", etc.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Video Distribution Section -->
                    <div class="bulk-section">
                        <h4>📹 Video Distribution</h4>
                        <p class="bulk-section-desc">Your selected videos need to exist in each target account.</p>
                        <div class="video-options">
                            <label class="video-option">
                                <input type="radio" name="video_distribution" value="match" checked onchange="toggleVideoDistribution()">
                                <span>Videos already exist in all accounts (match by file name)</span>
                            </label>
                            <label class="video-option">
                                <input type="radio" name="video_distribution" value="upload" onchange="toggleVideoDistribution()">
                                <span>Upload videos to selected accounts now</span>
                            </label>
                        </div>

                        <!-- Video Upload UI (shown when upload option selected) -->
                        <div id="video-upload-section" style="display: none; margin-top: 15px;">
                            <div class="video-upload-info" style="padding: 15px; background: #fff8e6; border-radius: 8px; border: 1px solid #ffc107; margin-bottom: 15px;">
                                <p style="margin: 0 0 10px 0; font-weight: 600; color: #856404;">
                                    <span style="font-size: 18px;">📤</span> Video Upload Required
                                </p>
                                <p style="margin: 0; font-size: 13px; color: #856404;">
                                    Your <span id="upload-video-count">0</span> selected videos will be uploaded to each selected account before launching.
                                </p>
                            </div>

                            <!-- Upload Progress Container -->
                            <div id="video-upload-progress-container" style="display: none;">
                                <div class="upload-progress-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <span style="font-weight: 600;">Uploading Videos...</span>
                                    <span id="upload-progress-text">0 / 0</span>
                                </div>
                                <div class="progress-bar-container" style="background: #e0e0e0; border-radius: 10px; height: 12px; overflow: hidden; margin-bottom: 10px;">
                                    <div id="video-upload-bar" class="progress-bar-fill" style="background: linear-gradient(135deg, #667eea, #764ba2); height: 100%; width: 0%; transition: width 0.3s;"></div>
                                </div>
                                <div id="video-upload-details" style="max-height: 150px; overflow-y: auto; font-size: 12px; background: #f9f9f9; border-radius: 6px; padding: 10px;">
                                    <!-- Upload status per account will be shown here -->
                                </div>
                            </div>

                            <!-- Upload Button -->
                            <button type="button" id="start-upload-btn" class="btn-primary" onclick="startBulkVideoUpload()" style="width: 100%; margin-top: 10px; background: linear-gradient(135deg, #667eea, #764ba2);">
                                📤 Upload Videos to Selected Accounts
                            </button>

                            <!-- Upload Complete Status -->
                            <div id="upload-complete-status" style="display: none; padding: 15px; background: #e8f5e9; border-radius: 8px; border: 1px solid #4caf50; margin-top: 15px;">
                                <p style="margin: 0; color: #2e7d32; font-weight: 600;">
                                    <span style="font-size: 18px;">✅</span> Videos uploaded successfully!
                                </p>
                                <p id="upload-complete-details" style="margin: 5px 0 0 0; font-size: 13px; color: #2e7d32;"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule Options Section (moved before accounts for visibility) -->
                    <div class="bulk-section">
                        <h4>📅 Schedule</h4>
                        <p class="bulk-section-desc">Choose when your campaigns should run.</p>
                        <div class="bulk-schedule-options" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                            <!-- Same as Original Option -->
                            <label class="bulk-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #1a1a1a; border-radius: 6px; cursor: pointer; margin-bottom: 8px;">
                                <input type="radio" name="bulk_schedule_type" value="same_as_original" checked onchange="toggleBulkScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="display: block; margin-bottom: 2px;">Same as Original Campaign</strong>
                                    <span id="bulk-original-schedule-info" style="font-size: 12px; color: #64748b;">Use the same schedule settings as the original campaign</span>
                                </div>
                            </label>
                            <label class="bulk-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 6px; cursor: pointer; margin-bottom: 8px;">
                                <input type="radio" name="bulk_schedule_type" value="continuous" onchange="toggleBulkScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="display: block; margin-bottom: 2px;">Start Immediately</strong>
                                    <span style="font-size: 12px; color: #64748b;">Campaign runs continuously with no end date</span>
                                </div>
                            </label>
                            <label class="bulk-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 6px; cursor: pointer; margin-bottom: 8px;">
                                <input type="radio" name="bulk_schedule_type" value="scheduled_start_only" onchange="toggleBulkScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="display: block; margin-bottom: 2px;">Scheduled Start</strong>
                                    <span style="font-size: 12px; color: #64748b;">Start at a specific time, run continuously</span>
                                </div>
                            </label>
                            <label class="bulk-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 6px; cursor: pointer;">
                                <input type="radio" name="bulk_schedule_type" value="scheduled" onchange="toggleBulkScheduleType()" style="margin-top: 3px;">
                                <div>
                                    <strong style="display: block; margin-bottom: 2px;">Scheduled Start & End</strong>
                                    <span style="font-size: 12px; color: #64748b;">Set both start and end dates</span>
                                </div>
                            </label>

                            <!-- Start Time Only Picker -->
                            <div id="bulk-schedule-start-only-container" style="display: none; margin-top: 12px; padding: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px;">Start Date & Time (Your Local Time):</label>
                                <input type="datetime-local" id="bulk-schedule-start-only-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                <p style="margin: 6px 0 0; font-size: 11px; color: #64748b;">Campaign will start at this time and run indefinitely</p>
                            </div>

                            <!-- Start AND End DateTime Pickers -->
                            <div id="bulk-schedule-datetime-container" style="display: none; margin-top: 12px; padding: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px;">Start Date & Time (Your Local Time):</label>
                                        <input type="datetime-local" id="bulk-schedule-start-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px;">End Date & Time (Your Local Time):</label>
                                        <input type="datetime-local" id="bulk-schedule-end-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    </div>
                                </div>
                                <p style="margin: 6px 0 0; font-size: 11px; color: #64748b;">Campaign will run between these dates (auto-converted to EST for TikTok)</p>
                            </div>
                        </div>
                    </div>

                    <!-- Accounts Selection Section -->
                    <div class="bulk-section">
                        <h4>Select Accounts & Configure</h4>
                        <div class="bulk-accounts-header">
                            <button type="button" class="btn-sm" onclick="selectAllBulkAccounts()">Select All</button>
                            <button type="button" class="btn-sm" onclick="deselectAllBulkAccounts()">Deselect All</button>
                            <span class="accounts-selected-text"><span id="modal-selected-count">0</span> selected</span>
                        </div>

                        <!-- Search Bar for Accounts -->
                        <div class="bulk-account-search" style="margin: 12px 0;">
                            <input type="text"
                                   id="bulk-account-search-input"
                                   placeholder="Search accounts by name or ID..."
                                   oninput="filterBulkAccounts(this.value)"
                                   style="width: 100%; padding: 10px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                            <span id="bulk-search-results-count" style="display: block; margin-top: 6px; font-size: 12px; color: #6b7280;"></span>
                        </div>

                        <div id="bulk-accounts-container" class="bulk-accounts-container">
                            <!-- Account cards will be populated by JavaScript -->
                            <div class="loading-accounts">
                                <div class="spinner-small"></div>
                                <span>Loading accounts...</span>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Section -->
                    <div class="bulk-modal-summary">
                        <div class="summary-item">
                            <span class="summary-label">Total Accounts:</span>
                            <span class="summary-value" id="modal-total-accounts">0</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Ready to Launch:</span>
                            <span class="summary-value" id="modal-ready-accounts">0</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Total Daily Budget:</span>
                            <span class="summary-value" id="modal-total-budget">$0</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeBulkLaunchModal()">Cancel</button>
                    <button class="btn-primary" id="confirm-bulk-config-btn" onclick="confirmBulkConfiguration()">Confirm Configuration</button>
                </div>
            </div>
        </div>

        <!-- Bulk Launch Progress Modal -->
        <div id="bulk-progress-modal" class="modal" style="display: none;">
            <div class="modal-content bulk-progress-modal-content">
                <div class="modal-header">
                    <h3>🚀 Launching Campaigns...</h3>
                </div>
                <div class="modal-body">
                    <div class="bulk-progress-stats">
                        <div class="progress-stat">
                            <span class="progress-stat-value" id="progress-completed">0</span>
                            <span class="progress-stat-label">Completed</span>
                        </div>
                        <div class="progress-stat">
                            <span class="progress-stat-value" id="progress-total">0</span>
                            <span class="progress-stat-label">Total</span>
                        </div>
                        <div class="progress-stat success">
                            <span class="progress-stat-value" id="progress-success">0</span>
                            <span class="progress-stat-label">Success</span>
                        </div>
                        <div class="progress-stat failed">
                            <span class="progress-stat-value" id="progress-failed">0</span>
                            <span class="progress-stat-label">Failed</span>
                        </div>
                    </div>

                    <div class="bulk-progress-bar-container">
                        <div id="bulk-progress-bar" class="bulk-progress-bar" style="width: 0%;"></div>
                    </div>

                    <div id="bulk-progress-list" class="bulk-progress-list">
                        <!-- Progress items will be added here -->
                    </div>
                </div>
                <div class="modal-footer" id="bulk-progress-footer" style="display: none;">
                    <button class="btn-primary" onclick="closeBulkProgressModal()">Done</button>
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div id="loading-overlay" class="loading-overlay">
            <div class="spinner"></div>
            <p id="loading-text">Processing...</p>
        </div>

        <!-- Toast Notification -->
        <div id="toast" class="toast"></div>

        <!-- Create Identity Modal -->
        <div id="create-identity-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>Create New Custom Identity</h3>
                    <span class="modal-close" onclick="closeCreateIdentityModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Display Name <span style="color: #dc2626;">*</span></label>
                        <input type="text" id="identity-display-name" placeholder="Enter display name" maxlength="40" required>
                        <div style="text-align: right; font-size: 12px; color: #666; margin-top: 5px;">
                            <span id="identity-char-count">0</span>/40
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 16px;">
                        <label>Profile Logo (Optional)</label>
                        <div id="identity-logo-upload-area" style="border: 2px dashed #d1d5db; border-radius: 8px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.2s;" onclick="document.getElementById('identity-logo-input').click()">
                            <div id="identity-logo-preview" style="display: none; margin-bottom: 10px;">
                                <img id="identity-logo-img" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;">
                            </div>
                            <div id="identity-logo-placeholder">
                                <span style="font-size: 32px;">📷</span>
                                <p style="margin: 8px 0 0; color: #6b7280; font-size: 14px;">Click to upload logo</p>
                                <p style="margin: 4px 0 0; color: #9ca3af; font-size: 12px;">Recommended: 100x100px, PNG or JPG</p>
                            </div>
                            <input type="file" id="identity-logo-input" accept="image/*" style="display: none;" onchange="previewIdentityLogo(this)">
                        </div>
                        <button type="button" id="identity-logo-remove-btn" style="display: none; margin-top: 8px; font-size: 12px; color: #dc2626; background: none; border: none; cursor: pointer;" onclick="removeIdentityLogo()">✕ Remove logo</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeCreateIdentityModal()">Cancel</button>
                    <button class="btn-primary" id="create-identity-btn" onclick="createCustomIdentity()">Create Identity</button>
                </div>
            </div>
        </div>

        <!-- Create Portfolio Modal -->
        <div id="create-portfolio-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>Create CTA Portfolio</h3>
                    <span class="modal-close" onclick="closeCreatePortfolioModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Portfolio Name</label>
                        <input type="text" id="portfolio-name-input" placeholder="My CTA Portfolio" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label>Select CTAs (1-5)</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; max-height: 200px; overflow-y: auto; padding: 10px; background: #f9f9f9; border-radius: 6px;">
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="LEARN_MORE" checked> Learn More
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="GET_QUOTE"> Get Quote
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="SIGN_UP"> Sign Up
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="CONTACT_US"> Contact Us
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="APPLY_NOW"> Apply Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="DOWNLOAD"> Download
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="SHOP_NOW"> Shop Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="ORDER_NOW"> Order Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="BOOK_NOW"> Book Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" class="portfolio-cta-checkbox" value="GET_STARTED"> Get Started
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeCreatePortfolioModal()">Cancel</button>
                    <button class="btn-primary" onclick="createCtaPortfolio()">Create Portfolio</button>
                </div>
            </div>
        </div>

        </div><!-- End of create-view -->

        <!-- CAMPAIGNS VIEW (My Campaigns) -->
        <div id="campaigns-view" style="display: none;">
            <!-- Campaigns Header -->
            <div class="campaigns-header">
                <h2>📋 My Campaigns</h2>
                <div class="campaigns-actions">
                    <button class="btn-secondary" onclick="refreshCampaignList()">🔄 Refresh</button>
                </div>
            </div>

            <!-- Ad Account Selector -->
            <?php if (count($advertiserIds) > 1): ?>
            <div class="ad-account-selector-container">
                <span class="ad-account-label">🏢 Ad Account:</span>
                <div class="ad-account-search-wrapper" style="position: relative; display: inline-block;">
                    <input type="text"
                           id="ad-account-search"
                           class="ad-account-search-input"
                           placeholder="🔍 Search accounts..."
                           oninput="filterAdAccountOptions()"
                           onfocus="showAdAccountDropdown()"
                           style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; width: 280px;">
                    <div id="ad-account-dropdown" class="ad-account-dropdown" style="display: none; position: absolute; top: 100%; left: 0; width: 100%; max-height: 300px; overflow-y: auto; background: white; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; margin-top: 4px;">
                        <?php
                        $accountIndex = 1;
                        foreach ($advertiserIds as $advId):
                            $details = $advertiserDetails[$advId] ?? null;
                            $advName = $details['name'] ?? '';
                            if ($advName && $advName !== 'Account') {
                                $displayName = $advName . ' • ID: ' . $advId;
                            } else {
                                $displayName = 'Ad Account #' . $accountIndex . ' • ID: ' . $advId;
                            }
                            $isSelected = ($advId === $currentAdvertiserId);
                            $accountIndex++;
                        ?>
                        <div class="ad-account-option <?php echo $isSelected ? 'selected' : ''; ?>"
                             data-advertiser-id="<?php echo htmlspecialchars($advId); ?>"
                             data-name="<?php echo htmlspecialchars(strtolower($displayName)); ?>"
                             onclick="selectAdAccount('<?php echo htmlspecialchars($advId); ?>', '<?php echo htmlspecialchars(addslashes($displayName)); ?>')"
                             style="padding: 12px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.2s;">
                            <div style="font-weight: 500; color: #1e293b;"><?php echo htmlspecialchars($displayName); ?></div>
                            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">ID: <?php echo htmlspecialchars($advId); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ad-account-info">
                    <span class="account-count"><?php echo count($advertiserIds); ?></span> accounts linked
                </div>
                <div class="ad-account-switching" id="ad-account-switching">
                    <span class="mini-spinner"></span>
                    <span>Switching account...</span>
                </div>
            </div>
            <?php else: ?>
            <div class="ad-account-selector-container" style="justify-content: center;">
                <span class="ad-account-label">🏢 Ad Account:</span>
                <span style="font-weight: 600; color: #1e293b;">
                    <?php
                    $details = $advertiserDetails[$currentAdvertiserId] ?? null;
                    $advName = $details['name'] ?? '';
                    if ($advName && $advName !== 'Account') {
                        echo htmlspecialchars($advName . ' • ID: ' . $currentAdvertiserId);
                    } else {
                        echo 'ID: ' . htmlspecialchars($currentAdvertiserId);
                    }
                    ?>
                </span>
            </div>
            <?php endif; ?>

            <!-- Campaign Filters -->
            <div class="campaign-filters">
                <button class="campaign-filter-btn active" data-filter="all" onclick="filterCampaignsByStatus('all')">
                    All <span class="filter-count" id="count-all">0</span>
                </button>
                <button class="campaign-filter-btn" data-filter="active" onclick="filterCampaignsByStatus('active')">
                    Active <span class="filter-count" id="count-active">0</span>
                </button>
                <button class="campaign-filter-btn" data-filter="inactive" onclick="filterCampaignsByStatus('inactive')">
                    Inactive <span class="filter-count" id="count-inactive">0</span>
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
                    <span class="date-range-label">📅 Showing data for:</span>
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
                        <span class="btn-icon">▶</span> Turn ON Selected
                    </button>
                    <button class="btn-bulk-action btn-disable" onclick="bulkToggleCampaigns('DISABLE')">
                        <span class="btn-icon">⏸</span> Turn OFF Selected
                    </button>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="campaign-search-container">
                <input type="text"
                       id="campaign-search-input"
                       class="campaign-search-input"
                       placeholder="🔍 Search campaigns by name..."
                       oninput="searchCampaigns()">
            </div>

            <!-- Campaign Metrics Table -->
            <div class="campaign-metrics-table-container">
                <!-- Loading State -->
                <div class="campaign-loading" id="campaign-loading">
                    <div class="spinner"></div>
                    <p>Loading campaigns with metrics...</p>
                </div>

                <!-- Empty State (hidden by default) -->
                <div class="campaign-empty-state" id="campaign-empty-state" style="display: none;">
                    <div class="empty-icon">📭</div>
                    <h3>No campaigns found</h3>
                    <p>You haven't created any campaigns yet, or no campaigns match your filter.</p>
                    <button class="btn-primary" onclick="switchMainView('create')">Create Your First Campaign</button>
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
                <div id="campaign-cards-container" style="display: none;">
                </div>
            </div>
        </div>

        <!-- MEDIA LIBRARY VIEW -->
        <div id="media-view" style="display: none;">
            <div class="campaigns-header">
                <h2>🎬 Media Library</h2>
                <div class="campaigns-actions">
                    <input type="file" id="media-upload-input" accept="video/*" multiple style="display: none;" onchange="handleMediaLibraryUpload(event)">
                    <button class="btn-primary" onclick="document.getElementById('media-upload-input').click()">
                        📤 Upload Video
                    </button>
                    <button class="btn-secondary" onclick="refreshMediaViewLibrary()">🔄 Refresh</button>
                </div>
            </div>

            <!-- Upload Progress -->
            <div id="media-upload-progress" style="display: none; margin: 20px 0; padding: 20px; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span id="media-upload-title" style="font-weight: 600;">Uploading...</span>
                    <span id="media-upload-count" style="color: #64748b;">0/0</span>
                </div>
                <div style="background: #e2e8f0; border-radius: 4px; height: 8px; overflow: hidden;">
                    <div id="media-upload-bar" style="background: linear-gradient(90deg, #fe2c55, #25f4ee); height: 100%; width: 0%; transition: width 0.3s;"></div>
                </div>
                <div id="media-upload-list" style="margin-top: 15px; max-height: 200px; overflow-y: auto;">
                    <!-- Upload items will be rendered here -->
                </div>
            </div>

            <!-- Media Library Stats -->
            <div style="margin: 20px 0; padding: 15px 20px; background: linear-gradient(135deg, rgba(254, 44, 85, 0.05), rgba(37, 244, 238, 0.05)); border-radius: 12px; display: flex; gap: 30px; align-items: center;">
                <div>
                    <span style="font-size: 24px; font-weight: 700; color: #1e293b;" id="media-video-count">0</span>
                    <span style="color: #64748b; margin-left: 5px;">Videos</span>
                </div>
                <div>
                    <span style="font-size: 24px; font-weight: 700; color: #1e293b;" id="media-image-count">0</span>
                    <span style="color: #64748b; margin-left: 5px;">Images</span>
                </div>
                <div style="margin-left: auto; color: #64748b; font-size: 13px;">
                    Account: <strong id="media-account-id"><?php echo htmlspecialchars($currentAdvertiserId); ?></strong>
                </div>
            </div>

            <!-- Video Grid -->
            <div style="margin-bottom: 20px;">
                <h3 style="margin-bottom: 15px; color: #1e293b;">📹 Videos</h3>
                <div id="media-video-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px;">
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">Loading videos...</div>
                </div>
            </div>

            <!-- Image Grid -->
            <div>
                <h3 style="margin-bottom: 15px; color: #1e293b;">🖼️ Images</h3>
                <div id="media-image-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px;">
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">Loading images...</div>
                </div>
            </div>
        </div>

        <!-- API Logs Panel (Hidden in production) -->
        <div id="logs-panel" class="logs-panel collapsed" style="display: none;">
            <div class="logs-header" onclick="toggleLogsPanel()" style="cursor: pointer;">
                <h3>📋 API Request Logs <span id="logs-toggle-icon">▲ Show Logs</span></h3>
                <div class="logs-controls" onclick="event.stopPropagation();">
                    <button class="btn-clear-logs" onclick="clearLogs()">Clear</button>
                    <button class="btn-toggle-logs" onclick="toggleLogsPanel()">▲</button>
                </div>
            </div>
            <div class="logs-content" id="logs-content">
                <div class="log-entry log-info">
                    <span class="log-time"><?php echo date('H:i:s'); ?></span>
                    <span class="log-message">Smart+ API Logger initialized</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Video Picker Modal -->
    <div id="video-picker-modal" class="modal video-picker-modal" style="display: none;">
        <div class="modal-content video-picker-modal-content">
            <div class="modal-header">
                <h3>📹 Select Video from Library</h3>
                <span class="modal-close" onclick="closeVideoPickerModal()">&times;</span>
            </div>
            <div class="modal-body">
                <!-- Source Video Info -->
                <div class="picker-source-info" id="picker-source-info">
                    <span class="picker-label">Mapping for:</span>
                    <span class="picker-source-name" id="picker-source-name">-</span>
                </div>

                <!-- Search Input -->
                <div class="picker-search-container">
                    <input type="text"
                           id="video-picker-search"
                           class="picker-search-input"
                           placeholder="🔍 Search videos by name..."
                           oninput="filterVideoPickerResults()">
                    <span class="picker-video-count" id="picker-video-count">0 videos</span>
                </div>

                <!-- Upload Section - Always visible -->
                <div class="picker-upload-section" id="picker-upload-section" style="margin-bottom: 15px; padding: 12px; background: linear-gradient(135deg, rgba(254, 44, 85, 0.05), rgba(37, 244, 238, 0.05)); border-radius: 10px; border: 1px dashed rgba(254, 44, 85, 0.3);">
                    <input type="file"
                           id="video-picker-upload-input"
                           accept="video/*"
                           multiple
                           style="display: none;"
                           onchange="handlePickerVideoUpload(event)">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <span style="font-size: 13px; color: #64748b;">Don't see your video? Upload directly:</span>
                        <div style="display: flex; gap: 8px;">
                            <button type="button"
                                    onclick="refreshVideoPickerList()"
                                    style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                <span>🔄</span> Refresh
                            </button>
                            <button type="button"
                                    id="picker-upload-btn"
                                    onclick="document.getElementById('video-picker-upload-input').click()"
                                    style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: linear-gradient(135deg, #fe2c55, #25f4ee); color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                <span>📤</span> Upload Video
                            </button>
                        </div>
                    </div>
                    <div id="picker-upload-progress" style="display: none; margin-top: 12px; padding: 12px; background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1)); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span id="picker-upload-status" style="font-size: 13px; color: #3b82f6; font-weight: 600;">📤 Uploading...</span>
                            <span id="picker-upload-count" style="font-size: 13px; color: #3b82f6; font-weight: 600;">0/0</span>
                        </div>
                        <div style="background: #e2e8f0; border-radius: 6px; height: 8px; overflow: hidden;">
                            <div id="picker-upload-bar" style="background: linear-gradient(90deg, #3b82f6, #8b5cf6); height: 100%; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <p style="margin: 8px 0 0; font-size: 11px; color: #64748b; text-align: center;">Please wait while your video is being uploaded...</p>
                    </div>
                </div>

                <!-- Video Grid -->
                <div class="picker-video-grid" id="picker-video-grid">
                    <!-- Videos will be rendered here -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeVideoPickerModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Duplicate Campaign Modal -->
    <div id="duplicate-campaign-modal" class="modal" style="display: none;">
        <div class="modal-content duplicate-modal-content">
            <div class="modal-header">
                <h3>📋 Duplicate Campaign</h3>
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

                    <!-- Search Bar for Accounts -->
                    <div class="dup-bulk-account-search" style="margin-bottom: 12px;">
                        <input type="text"
                               id="dup-bulk-account-search-input"
                               placeholder="Search accounts by name or ID..."
                               oninput="filterDupBulkAccounts(this.value)"
                               style="width: 100%; padding: 10px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                        <span id="dup-bulk-search-results-count" style="display: block; margin-top: 6px; font-size: 12px; color: #6b7280;"></span>
                    </div>

                    <!-- Account List -->
                    <div id="dup-bulk-accounts-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px;">
                        <div class="loading-state" style="text-align: center; padding: 20px;">
                            <div class="spinner"></div>
                            <p style="margin-top: 10px; color: #64748b;">Loading accounts...</p>
                        </div>
                    </div>

                    <!-- Bulk Settings Summary -->
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

                        <!-- Campaign Name -->
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">Campaign Name</label>
                            <input type="text" id="dup-edit-campaign-name" placeholder="Enter campaign name"
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;"
                                   oninput="updateDuplicatePreviewList()">
                        </div>

                        <!-- Budget -->
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">Daily Budget ($)</label>
                            <input type="number" id="dup-edit-budget" placeholder="50" min="20"
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                            <small style="color: #666; margin-top: 4px; display: block;">Minimum $20 daily budget</small>
                        </div>

                        <!-- Landing Page URL -->
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">Landing Page URL</label>
                            <input type="url" id="dup-edit-landing-url" placeholder="https://example.com/landing-page"
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                        </div>

                        <!-- Ad Text -->
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">Ad Text</label>
                            <textarea id="dup-edit-ad-text" placeholder="Enter ad text" rows="3"
                                      style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical;"></textarea>
                        </div>

                        <!-- Schedule Options for Duplicate -->
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">Ad Group Schedule</label>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                                <!-- Option 1: Start Now -->
                                <label class="dup-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #1a1a1a; border-radius: 6px; cursor: pointer; margin-bottom: 8px;">
                                    <input type="radio" name="dup_schedule_type" value="continuous" checked onchange="toggleDupScheduleType()" style="margin-top: 3px;">
                                    <div>
                                        <strong style="color: #1e293b; font-size: 13px;">Start now and run continuously</strong>
                                        <p style="margin: 2px 0 0; color: #64748b; font-size: 12px;">Ad group will start immediately</p>
                                    </div>
                                </label>

                                <!-- Option 2: Schedule Start Only -->
                                <label class="dup-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 6px; cursor: pointer; margin-bottom: 8px;">
                                    <input type="radio" name="dup_schedule_type" value="scheduled_start_only" onchange="toggleDupScheduleType()" style="margin-top: 3px;">
                                    <div>
                                        <strong style="color: #1e293b; font-size: 13px;">Schedule start time (run continuously)</strong>
                                        <p style="margin: 2px 0 0; color: #64748b; font-size: 12px;">Start at a specific date/time</p>
                                    </div>
                                </label>

                                <!-- Option 3: Start and End -->
                                <label class="dup-schedule-option" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 6px; cursor: pointer;">
                                    <input type="radio" name="dup_schedule_type" value="scheduled" onchange="toggleDupScheduleType()" style="margin-top: 3px;">
                                    <div>
                                        <strong style="color: #1e293b; font-size: 13px;">Set start and end time</strong>
                                        <p style="margin: 2px 0 0; color: #64748b; font-size: 12px;">Run during a specific time period</p>
                                    </div>
                                </label>

                                <!-- Start Only DateTime Picker -->
                                <div id="dup-schedule-start-only-container" style="display: none; margin-top: 12px; padding: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 6px;">
                                    <div class="form-group" style="margin-bottom: 10px;">
                                        <label style="font-weight: 500; color: #475569; font-size: 13px;">Start Date & Time <span style="font-weight: 400; color: #3b82f6;">(Your Local Time)</span></label>
                                        <input type="datetime-local" id="dup-schedule-start-only-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    </div>
                                    <div style="padding: 6px 10px; background: #eff6ff; border-radius: 4px; display: flex; align-items: center; gap: 6px;">
                                        <span style="font-size: 14px;">🕐</span>
                                        <span style="font-size: 11px; color: #1e40af; font-weight: 500;">Your Local Time - auto-converted to EST for TikTok Ads Manager</span>
                                    </div>
                                </div>

                                <!-- Start AND End DateTime Pickers -->
                                <div id="dup-schedule-datetime-container" style="display: none; margin-top: 12px; padding: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 6px;">
                                    <div style="margin-bottom: 10px; padding: 6px 10px; background: #eff6ff; border-radius: 4px; display: flex; align-items: center; gap: 6px;">
                                        <span style="font-size: 14px;">🕐</span>
                                        <span style="font-size: 11px; color: #1e40af; font-weight: 500;">Your Local Time - auto-converted to EST for TikTok Ads Manager</span>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <label style="font-weight: 500; color: #475569; font-size: 13px;">Start <span style="font-weight: 400; color: #3b82f6;">(Local Time)</span></label>
                                            <input type="datetime-local" id="dup-schedule-start-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                        </div>
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <label style="font-weight: 500; color: #475569; font-size: 13px;">End <span style="font-weight: 400; color: #3b82f6;">(Local Time)</span></label>
                                            <input type="datetime-local" id="dup-schedule-end-datetime" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Number of copies for edit mode -->
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

                        <!-- Video/Creative Change Section -->
                        <div class="form-group" style="margin-bottom: 15px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">
                                <span>🎬</span> Videos/Creatives
                            </label>
                            <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;">
                                Current videos from the original campaign. You can change them for the duplicates.
                            </p>

                            <!-- Current Videos Display -->
                            <div id="duplicate-current-videos" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                                <!-- Videos will be rendered here -->
                            </div>

                            <!-- Change Videos Button -->
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
                            <!-- Preview items will be rendered here -->
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

                <!-- Progress Section (shown during duplication) -->
                <div id="duplicate-progress-section" style="display: none;">
                    <div class="duplicate-progress-header">
                        <span>Creating duplicates...</span>
                        <span id="duplicate-progress-text">0 / 0</span>
                    </div>
                    <div class="duplicate-progress-bar-container">
                        <div class="duplicate-progress-bar" id="duplicate-progress-bar" style="width: 0%;"></div>
                    </div>
                    <div class="duplicate-progress-log" id="duplicate-progress-log">
                        <!-- Progress log items will be added here -->
                    </div>
                </div>

                <!-- Success Section (shown after completion) -->
                <div id="duplicate-success-section" style="display: none;">
                    <div class="duplicate-success-icon">✅</div>
                    <h4>Duplication Complete!</h4>
                    <p id="duplicate-success-message">Successfully created 0 campaigns.</p>
                    <div class="duplicate-results-summary" id="duplicate-results-summary">
                        <!-- Results will be shown here -->
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

    <!-- Video Selection Modal -->
    <div id="video-selection-modal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 900px; max-height: 85vh;">
            <div class="modal-header">
                <h3>🎬 Select Videos</h3>
                <button class="modal-close" onclick="closeVideoSelectionModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <!-- Search, Upload, Refresh and Filter -->
                <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center;">
                    <input type="text" id="video-modal-search" placeholder="Search videos by name..."
                           oninput="filterVideosInModal()"
                           style="flex: 1; min-width: 200px; padding: 10px 15px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                    <button onclick="showUploadOptions()"
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

                <!-- Bulk Upload Progress (hidden by default) -->
                <div id="video-modal-upload-progress" style="display: none; margin-bottom: 20px; padding: 15px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                    <div class="bulk-upload-header">
                        <span id="bulk-upload-title">Uploading videos...</span>
                        <span id="bulk-upload-count">0/0</span>
                    </div>
                    <div class="bulk-upload-bar-container">
                        <div id="bulk-upload-bar" class="bulk-upload-bar"></div>
                    </div>
                    <div id="bulk-upload-list" class="bulk-upload-list">
                        <!-- Individual file progress items will be added here -->
                    </div>
                </div>

                <!-- Video Grid -->
                <div id="video-modal-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; max-height: 450px; overflow-y: auto; padding: 5px;">
                    <!-- Videos will be rendered here -->
                </div>

                <!-- Empty State -->
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

    <!-- Upload Options Modal (Single vs Multi Account) -->
    <div id="upload-options-modal" class="modal" style="display: none; z-index: 10001;">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3>Upload Videos</h3>
                <button class="modal-close" onclick="closeUploadOptions()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <p style="color: #475569; margin-bottom: 20px;">Choose where to upload your videos:</p>
                <div onclick="uploadSingleAccount()" style="display: flex; align-items: center; gap: 15px; padding: 16px; background: #f0fdf4; border: 2px solid #bbf7d0; border-radius: 10px; cursor: pointer; margin-bottom: 12px; transition: all 0.2s;"
                     onmouseover="this.style.borderColor='#22c55e'" onmouseout="this.style.borderColor='#bbf7d0'">
                    <div style="width: 44px; height: 44px; background: #22c55e; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; flex-shrink: 0;">1</div>
                    <div>
                        <div style="font-weight: 700; font-size: 15px; color: #166534;">Current Account</div>
                        <div style="font-size: 13px; color: #4ade80;">Upload to the currently selected ad account</div>
                    </div>
                </div>
                <div style="padding: 16px; background: #eff6ff; border: 2px solid #bfdbfe; border-radius: 10px;">
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 12px;">
                        <div style="width: 44px; height: 44px; background: #2563eb; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; flex-shrink: 0;">+N</div>
                        <div>
                            <div style="font-weight: 700; font-size: 15px; color: #1e40af;">Multiple Accounts</div>
                            <div style="font-size: 13px; color: #60a5fa;">Upload same videos to multiple ad accounts</div>
                        </div>
                    </div>
                    <div style="max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px;" id="upload-account-list">
                        <p style="color: #94a3b8; text-align: center; padding: 15px;">Loading accounts...</p>
                    </div>
                    <div style="display: flex; gap: 8px; margin-top: 10px;">
                        <button onclick="toggleAllUploadAccounts(true)" style="padding: 5px 12px; background: #dbeafe; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 6px; cursor: pointer; font-size: 12px;">Select All</button>
                        <button onclick="toggleAllUploadAccounts(false)" style="padding: 5px 12px; background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; cursor: pointer; font-size: 12px;">Clear All</button>
                        <div style="flex: 1;"></div>
                        <button onclick="uploadMultipleAccounts()" style="padding: 8px 20px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px;"
                                onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                            Upload to Selected
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pass current advertiser ID to JavaScript (tab-specific, prevents cross-tab contamination) -->
    <script>
        window.TIKTOK_ADVERTISER_ID = '<?php echo htmlspecialchars($currentAdvertiserId); ?>';
    </script>
    <script src="assets/smart-campaign.js?v=<?php echo time(); ?>"></script>
</body>
</html>
