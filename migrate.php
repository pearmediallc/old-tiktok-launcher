<?php
/**
 * One-time migration script
 * Run once via browser, then it auto-deletes itself
 */
require_once __DIR__ . '/includes/Security.php';
Security::init();
Security::enforceHttps();
session_start();

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/database/Database.php';

$db = Database::getInstance();
$driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');
$results = [];

// 1. Add role column to users
try {
    if ($driver === 'pgsql') {
        $db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) DEFAULT 'user' CHECK (role IN ('admin','user'))");
    } else {
        $db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','user') DEFAULT 'user'");
    }
    $results[] = ['ok', 'Added role column to users table'];
} catch (Exception $e) {
    $results[] = ['info', 'role column: ' . $e->getMessage()];
}

// 2. Set Sunny as admin
try {
    $db->query("UPDATE users SET role = 'admin' WHERE username = 'Sunny'");
    $results[] = ['ok', 'Set Sunny role = admin'];
} catch (Exception $e) {
    $results[] = ['err', 'Set admin: ' . $e->getMessage()];
}

// 3. Add remember_me_tokens table
try {
    if ($driver === 'pgsql') {
        $db->query("CREATE TABLE IF NOT EXISTS remember_me_tokens (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $db->query("CREATE INDEX IF NOT EXISTS idx_rmt_token ON remember_me_tokens(token_hash)");
        $db->query("CREATE INDEX IF NOT EXISTS idx_rmt_user ON remember_me_tokens(user_id)");
    } else {
        $db->query("CREATE TABLE IF NOT EXISTS remember_me_tokens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rmt_token (token_hash),
            INDEX idx_rmt_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $results[] = ['ok', 'Created remember_me_tokens table'];
} catch (Exception $e) {
    $results[] = ['info', 'remember_me_tokens: ' . $e->getMessage()];
}

// 4. Add user_slack_connections table
try {
    if ($driver === 'pgsql') {
        $db->query("CREATE TABLE IF NOT EXISTS user_slack_connections (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            team_id VARCHAR(100),
            team_name VARCHAR(255),
            webhook_url TEXT NOT NULL,
            channel VARCHAR(255),
            channel_id VARCHAR(100),
            bot_user_id VARCHAR(100),
            scope TEXT,
            authed_user_id VARCHAR(100),
            access_token TEXT,
            connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $db->query("CREATE INDEX IF NOT EXISTS idx_usc_user_id ON user_slack_connections(user_id)");
    } else {
        $db->query("CREATE TABLE IF NOT EXISTS user_slack_connections (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL UNIQUE,
            team_id VARCHAR(100),
            team_name VARCHAR(255),
            webhook_url TEXT NOT NULL,
            channel VARCHAR(255),
            channel_id VARCHAR(100),
            bot_user_id VARCHAR(100),
            scope TEXT,
            authed_user_id VARCHAR(100),
            access_token TEXT,
            connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_usc_user_id (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $results[] = ['ok', 'Created user_slack_connections table'];
} catch (Exception $e) {
    $results[] = ['info', 'user_slack_connections: ' . $e->getMessage()];
}

// 5. Update session with new role (so admin link appears without re-login)
try {
    $user = $db->fetchOne("SELECT id, username, role FROM users WHERE username = :u", ['u' => $_SESSION['username']]);
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role']    = $user['role'];
        $results[] = ['ok', 'Session updated: user_id=' . $user['id'] . ', role=' . $user['role']];
    }
} catch (Exception $e) {
    $results[] = ['err', 'Session update: ' . $e->getMessage()];
}

// Self-delete after running
@unlink(__FILE__);
$results[] = ['ok', 'migrate.php deleted (one-time use)'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Migration</title>
    <style>
        body { font-family: monospace; padding: 30px; background: #0f172a; color: #e2e8f0; }
        .ok  { color: #22c55e; } .err { color: #ef4444; } .info { color: #94a3b8; }
        h2 { color: #1e9df1; }
        a { color: #1e9df1; }
    </style>
</head>
<body>
    <h2>Migration Results</h2>
    <?php foreach ($results as [$type, $msg]): ?>
        <p class="<?= $type ?>">
            <?= $type === 'ok' ? '✅' : ($type === 'err' ? '❌' : 'ℹ️') ?>
            <?= htmlspecialchars($msg) ?>
        </p>
    <?php endforeach; ?>
    <br>
    <a href="app-shell.php">← Back to App (Admin Panel should now be visible)</a>
</body>
</html>
