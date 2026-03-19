<?php
/**
 * Admin API — user management, logs, campaign details
 * Access restricted to users with role = 'admin'
 */
require_once __DIR__ . '/includes/Security.php';
Security::init();
Security::enforceHttps();
session_start();

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: admin only']);
    exit;
}

require_once __DIR__ . '/database/Database.php';
require_once __DIR__ . '/database/models/User.php';
require_once __DIR__ . '/includes/ActivityLogger.php';

$db = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── User Management ──────────────────────────────────
    case 'list_users':
        $userModel = new User();
        $users = $userModel->getAll();
        echo json_encode(['success' => true, 'users' => $users]);
        break;

    case 'create_user':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email    = trim($_POST['email'] ?? '') ?: null;
        $fullName = trim($_POST['full_name'] ?? '') ?: null;
        $role     = $_POST['role'] ?? 'user';

        if (empty($username) || empty($password)) {
            echo json_encode(['error' => 'Username and password required']);
            break;
        }
        if (strlen($password) < 6) {
            echo json_encode(['error' => 'Password must be at least 6 characters']);
            break;
        }

        $userModel = new User();
        if ($userModel->usernameExists($username)) {
            echo json_encode(['error' => 'Username already exists']);
            break;
        }

        $userId = $userModel->create($username, $password, $email, $fullName, $role);
        if ($userId) {
            ActivityLogger::log('admin_create_user', 'api-admin.php', ['new_user' => $username, 'role' => $role], 'success');
            echo json_encode(['success' => true, 'user_id' => $userId]);
        } else {
            echo json_encode(['error' => 'Failed to create user']);
        }
        break;

    case 'update_user':
        $userId   = intval($_POST['user_id'] ?? 0);
        $email    = trim($_POST['email'] ?? '') ?: null;
        $fullName = trim($_POST['full_name'] ?? '') ?: null;
        $role     = $_POST['role'] ?? null;
        $status   = $_POST['status'] ?? null;

        if (!$userId) {
            echo json_encode(['error' => 'user_id required']);
            break;
        }

        $data = [];
        if ($email !== null)    $data['email']     = $email;
        if ($fullName !== null) $data['full_name']  = $fullName;
        if ($role && in_array($role, ['admin', 'user'])) $data['role'] = $role;
        if ($status && in_array($status, ['active', 'inactive', 'suspended'])) $data['status'] = $status;

        $userModel = new User();
        $userModel->update($userId, $data);
        ActivityLogger::log('admin_update_user', 'api-admin.php', ['target_user_id' => $userId, 'changes' => array_keys($data)], 'success');
        echo json_encode(['success' => true]);
        break;

    case 'reset_password':
        $userId      = intval($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if (!$userId || strlen($newPassword) < 6) {
            echo json_encode(['error' => 'user_id and new_password (min 6 chars) required']);
            break;
        }

        $userModel = new User();
        $userModel->changePassword($userId, $newPassword);
        ActivityLogger::log('admin_reset_password', 'api-admin.php', ['target_user_id' => $userId], 'success');
        echo json_encode(['success' => true]);
        break;

    case 'delete_user':
        $userId = intval($_POST['user_id'] ?? 0);
        if (!$userId) {
            echo json_encode(['error' => 'user_id required']);
            break;
        }
        // Prevent self-deletion
        if ($userId === intval($_SESSION['user_id'] ?? 0)) {
            echo json_encode(['error' => 'Cannot delete your own account']);
            break;
        }

        $userModel = new User();
        $userModel->delete($userId);
        ActivityLogger::log('admin_delete_user', 'api-admin.php', ['target_user_id' => $userId], 'success');
        echo json_encode(['success' => true]);
        break;

    // ── Activity Logs ─────────────────────────────────────
    case 'get_logs':
        $limit    = min(intval($_GET['limit'] ?? 200), 1000);
        $username = trim($_GET['username'] ?? '');
        $action_f = trim($_GET['action_filter'] ?? '');
        $status   = trim($_GET['status'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to'] ?? '');

        $filters = [];
        if ($username)  $filters['username']    = $username;
        if ($action_f)  $filters['action']      = $action_f;
        if ($status)    $filters['status']      = $status;
        if ($dateFrom)  $filters['date_from']   = $dateFrom;
        if ($dateTo)    $filters['date_to']     = $dateTo;

        $logs = ActivityLogger::getLogs($limit, $filters);
        echo json_encode(['success' => true, 'logs' => $logs]);
        break;

    // ── Campaign / Job Details ────────────────────────────
    case 'get_bulk_jobs':
        $limit = min(intval($_GET['limit'] ?? 50), 500);
        try {
            $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');
            $jobs = $db->fetchAll(
                "SELECT bcj.*, u.username
                 FROM bulk_campaign_jobs bcj
                 LEFT JOIN users u ON u.id = bcj.user_id
                 ORDER BY bcj.created_at DESC
                 LIMIT $limit"
            );
            echo json_encode(['success' => true, 'jobs' => $jobs]);
        } catch (Exception $e) {
            echo json_encode(['success' => true, 'jobs' => [], 'note' => 'bulk_campaign_jobs table not found']);
        }
        break;

    case 'get_job_results':
        $jobId = trim($_GET['job_id'] ?? '');
        if (empty($jobId)) {
            echo json_encode(['error' => 'job_id required']);
            break;
        }
        try {
            $results = $db->fetchAll(
                "SELECT * FROM bulk_campaign_results WHERE job_id = :job_id ORDER BY created_at ASC",
                ['job_id' => $jobId]
            );
            echo json_encode(['success' => true, 'results' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => true, 'results' => []]);
        }
        break;

    case 'get_tiktok_connections':
        try {
            $connections = $db->fetchAll(
                "SELECT tc.id, tc.user_id, u.username, tc.advertiser_id, tc.advertiser_name,
                        tc.connection_status, tc.token_expires_at, tc.last_sync_at, tc.last_refresh_at,
                        tc.created_at
                 FROM tiktok_connections tc
                 LEFT JOIN users u ON u.id = tc.user_id
                 ORDER BY tc.created_at DESC"
            );
            echo json_encode(['success' => true, 'connections' => $connections]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
}
