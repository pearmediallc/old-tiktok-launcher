<?php
/**
 * Optimizer API Endpoints
 * Handles rules, monitored campaigns, actions, and logs
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('html_errors', '0');

set_error_handler(function($severity, $message, $file, $line) {
    error_log("PHP Error in api-optimizer.php: [$severity] $message in $file on line $line");
    return true;
});

set_exception_handler(function($exception) {
    error_log("Uncaught Exception in api-optimizer.php: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
    exit;
});

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/includes/optimizer-functions.php';
require_once __DIR__ . '/database/Database.php';

// Load environment
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

$accessToken = $_SESSION['oauth_access_token'] ?? $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '';
$advertiserId = $_SESSION['selected_advertiser_id'] ?? '';

$db = Database::getInstance();

// Auto-create optimizer tables if they don't exist
try {
    $db->query("SELECT 1 FROM optimizer_rules LIMIT 1");
} catch (Exception $e) {
    // Tables don't exist — create them using the right schema for the DB driver
    $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');
    $schemaFile = ($driver === 'pgsql')
        ? __DIR__ . '/database/schema-optimizer-pgsql.sql'
        : __DIR__ . '/database/schema-optimizer.sql';

    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        // Split by semicolons and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            // Skip empty lines and comment-only lines
            $clean = trim($stmt);
            if (empty($clean)) continue;
            if (strpos($clean, '--') === 0 && strpos($clean, "\n") === false) continue;
            try {
                $db->query($stmt);
            } catch (Exception $ex) {
                error_log("Optimizer table init: " . $ex->getMessage());
            }
        }
        logOptimizer("Auto-created optimizer database tables (driver: $driver)");
    }
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($action) && !empty($input['action'])) {
    $action = $input['action'];
}

switch ($action) {

    // ============================================
    // RULES
    // ============================================

    case 'get_rules':
        $rules = $db->fetchAll("SELECT * FROM optimizer_rules ORDER BY id ASC");
        echo json_encode(['success' => true, 'data' => $rules]);
        break;

    case 'update_rule':
        $ruleId = intval($input['rule_id'] ?? 0);
        $threshold = $input['threshold'] ?? null;
        $secondaryThreshold = $input['secondary_threshold'] ?? null;
        $enabled = $input['enabled'] ?? null;

        if (!$ruleId) {
            echo json_encode(['success' => false, 'message' => 'Rule ID required']);
            break;
        }

        $updates = [];
        $params = [];

        if ($threshold !== null) { $updates[] = "threshold = ?"; $params[] = floatval($threshold); }
        if ($secondaryThreshold !== null) { $updates[] = "secondary_threshold = ?"; $params[] = floatval($secondaryThreshold); }
        if ($enabled !== null) { $updates[] = "enabled = ?"; $params[] = $enabled ? 1 : 0; }

        if (empty($updates)) {
            echo json_encode(['success' => false, 'message' => 'Nothing to update']);
            break;
        }

        $params[] = $ruleId;
        $db->query("UPDATE optimizer_rules SET " . implode(', ', $updates) . " WHERE id = ?", $params);

        logOptimizer("Updated rule #$ruleId");
        echo json_encode(['success' => true, 'message' => 'Rule updated']);
        break;

    case 'toggle_rule':
        $ruleId = intval($input['rule_id'] ?? 0);
        if (!$ruleId) {
            echo json_encode(['success' => false, 'message' => 'Rule ID required']);
            break;
        }

        $rule = $db->fetchOne("SELECT enabled FROM optimizer_rules WHERE id = ?", [$ruleId]);
        if (!$rule) {
            echo json_encode(['success' => false, 'message' => 'Rule not found']);
            break;
        }

        $newEnabled = $rule['enabled'] ? 0 : 1;
        $db->query("UPDATE optimizer_rules SET enabled = ? WHERE id = ?", [$newEnabled, $ruleId]);

        echo json_encode(['success' => true, 'data' => ['enabled' => $newEnabled], 'message' => 'Rule ' . ($newEnabled ? 'enabled' : 'disabled')]);
        break;

    // ============================================
    // MONITORED CAMPAIGNS
    // ============================================

    case 'get_monitored_campaigns':
        $monitored = $db->fetchAll(
            "SELECT * FROM optimizer_monitored_campaigns ORDER BY created_at DESC"
        );
        echo json_encode(['success' => true, 'data' => $monitored]);
        break;

    case 'toggle_monitoring':
        $campaignId = $input['campaign_id'] ?? '';
        $campaignName = $input['campaign_name'] ?? '';
        $advId = $input['advertiser_id'] ?? $advertiserId;

        if (empty($campaignId) || empty($advId)) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID and advertiser ID required']);
            break;
        }

        // Check if already monitored
        $existing = $db->fetchOne(
            "SELECT * FROM optimizer_monitored_campaigns WHERE campaign_id = ? AND advertiser_id = ?",
            [$campaignId, $advId]
        );

        if ($existing) {
            // Toggle off - remove from monitoring
            $db->delete('optimizer_monitored_campaigns', 'id = ?', [$existing['id']]);
            logOptimizer("Removed campaign $campaignId from monitoring");
            echo json_encode(['success' => true, 'monitoring' => false, 'message' => 'Campaign removed from monitoring']);
        } else {
            // Toggle on - add to monitoring
            $db->insert('optimizer_monitored_campaigns', [
                'campaign_id' => $campaignId,
                'advertiser_id' => $advId,
                'campaign_name' => $campaignName,
                'monitoring_enabled' => 1,
                'paused_by_optimizer' => 0,
            ]);
            logOptimizer("Added campaign $campaignId to monitoring");
            echo json_encode(['success' => true, 'monitoring' => true, 'message' => 'Campaign is now being monitored']);
        }
        break;

    case 'get_monitoring_status':
        // Returns which campaign IDs are being monitored (for badge display)
        $advId = $_GET['advertiser_id'] ?? $advertiserId;
        $monitored = $db->fetchAll(
            "SELECT campaign_id, paused_by_optimizer, paused_at, resume_at, last_violation_rule, last_checked_at FROM optimizer_monitored_campaigns WHERE advertiser_id = ? AND monitoring_enabled = 1",
            [$advId]
        );

        $statusMap = [];
        foreach ($monitored as $mc) {
            $statusMap[$mc['campaign_id']] = [
                'monitoring' => true,
                'paused_by_optimizer' => (bool)$mc['paused_by_optimizer'],
                'paused_at' => $mc['paused_at'],
                'resume_at' => $mc['resume_at'],
                'last_violation_rule' => $mc['last_violation_rule'],
                'last_checked_at' => $mc['last_checked_at'],
            ];
        }

        echo json_encode(['success' => true, 'data' => $statusMap]);
        break;

    // ============================================
    // LOGS
    // ============================================

    case 'get_logs':
        $campaignId = $_GET['campaign_id'] ?? '';
        $actionFilter = $_GET['action_filter'] ?? '';
        $limit = intval($_GET['limit'] ?? 100);

        $where = [];
        $params = [];

        if ($campaignId) { $where[] = "campaign_id = ?"; $params[] = $campaignId; }
        if ($actionFilter) { $where[] = "action = ?"; $params[] = $actionFilter; }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $logs = $db->fetchAll(
            "SELECT * FROM optimizer_logs $whereClause ORDER BY created_at DESC LIMIT $limit",
            $params
        );

        foreach ($logs as &$log) {
            $log['metrics_snapshot'] = json_decode($log['metrics_snapshot'] ?? '{}', true);
            $log['api_response'] = json_decode($log['api_response'] ?? '{}', true);
        }

        echo json_encode(['success' => true, 'data' => $logs]);
        break;

    // ============================================
    // MANUAL ACTIONS
    // ============================================

    case 'manual_pause':
        $campaignId = $input['campaign_id'] ?? '';
        $advId = $input['advertiser_id'] ?? $advertiserId;

        if (empty($campaignId) || empty($advId)) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID required']);
            break;
        }

        $result = pauseCampaignViaApi($advId, $campaignId, $accessToken);

        $db->insert('optimizer_logs', [
            'campaign_id' => $campaignId,
            'advertiser_id' => $advId,
            'action' => 'pause',
            'rule_key' => 'manual',
            'rule_details' => 'Manual pause from optimizer UI',
            'api_response' => json_encode($result['response']),
            'success' => $result['success'] ? 1 : 0,
        ]);

        echo json_encode($result);
        break;

    case 'manual_resume':
        $campaignId = $input['campaign_id'] ?? '';
        $advId = $input['advertiser_id'] ?? $advertiserId;

        if (empty($campaignId) || empty($advId)) {
            echo json_encode(['success' => false, 'message' => 'Campaign ID required']);
            break;
        }

        $result = resumeCampaignViaApi($advId, $campaignId, $accessToken);

        // Also clear optimizer pause state
        $db->query(
            "UPDATE optimizer_monitored_campaigns SET paused_by_optimizer = 0, paused_at = NULL, resume_at = NULL WHERE campaign_id = ? AND advertiser_id = ?",
            [$campaignId, $advId]
        );

        $db->insert('optimizer_logs', [
            'campaign_id' => $campaignId,
            'advertiser_id' => $advId,
            'action' => 'resume',
            'rule_key' => 'manual',
            'rule_details' => 'Manual resume from optimizer UI',
            'api_response' => json_encode($result['response']),
            'success' => $result['success'] ? 1 : 0,
        ]);

        echo json_encode($result);
        break;

    // ============================================
    // FORCE CHECK (manual trigger)
    // ============================================

    case 'force_check':
        if (empty($accessToken)) {
            echo json_encode(['success' => false, 'message' => 'Access token required']);
            break;
        }

        $stats = runOptimizerCheck($db, $accessToken);
        echo json_encode(['success' => true, 'data' => $stats, 'message' => "Checked {$stats['checked']} campaigns, paused {$stats['paused']}, resumed {$stats['resumed']}"]);
        break;

    // ============================================
    // DASHBOARD STATS
    // ============================================

    case 'get_dashboard_stats':
        $totalMonitored = $db->fetchOne("SELECT COUNT(*) as cnt FROM optimizer_monitored_campaigns WHERE monitoring_enabled = 1");
        $totalPaused = $db->fetchOne("SELECT COUNT(*) as cnt FROM optimizer_monitored_campaigns WHERE paused_by_optimizer = 1");
        $todayPauses = $db->fetchOne("SELECT COUNT(*) as cnt FROM optimizer_logs WHERE action = 'pause' AND DATE(created_at) = CURRENT_DATE");
        $todayResumes = $db->fetchOne("SELECT COUNT(*) as cnt FROM optimizer_logs WHERE action = 'resume' AND DATE(created_at) = CURRENT_DATE");
        $totalRules = $db->fetchOne("SELECT COUNT(*) as cnt FROM optimizer_rules WHERE enabled = 1");

        echo json_encode(['success' => true, 'data' => [
            'monitored' => intval($totalMonitored['cnt'] ?? 0),
            'paused' => intval($totalPaused['cnt'] ?? 0),
            'pauses_today' => intval($todayPauses['cnt'] ?? 0),
            'resumes_today' => intval($todayResumes['cnt'] ?? 0),
            'active_rules' => intval($totalRules['cnt'] ?? 0),
        ]]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}
