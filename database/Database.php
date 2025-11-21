<?php
/**
 * Database connection and query helper class
 * Singleton pattern for single connection across the app
 */
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $this->connect();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Establish database connection
     */
    private function connect() {
        // Load environment variables
        if (file_exists(__DIR__ . '/../.env')) {
            $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($name, $value) = explode('=', $line, 2);
                    $_ENV[trim($name)] = trim($value);
                }
            }
        }

        // Get environment variables (Render uses getenv(), local uses $_ENV)
        $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');
        $dbUrl = getenv('DB_URL') ?: ($_ENV['DB_URL'] ?? '');

        // Check if DATABASE_URL is provided (Render.com style)
        if (!empty($dbUrl)) {
            // Parse the DATABASE_URL
            $parsed = parse_url($dbUrl);
            $host = $parsed['host'] ?? 'localhost';
            $port = $parsed['port'] ?? 5432;  // Default PostgreSQL port
            $dbname = ltrim($parsed['path'] ?? '/tiktok_launcher', '/');
            $username = $parsed['user'] ?? 'root';
            $password = $parsed['pass'] ?? '';
        } else {
            // Use individual environment variables
            $host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost');
            $dbname = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'tiktok_launcher');
            $username = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root');
            $password = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
            $port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306');
        }

        // Build DSN based on database driver
        if ($driver === 'pgsql') {
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
        } else {
            $charset = 'utf8mb4';
            $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed. Please check your configuration.");
        }
    }

    /**
     * Get PDO connection
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * Execute a query and return results
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query failed: " . $e->getMessage() . " | SQL: " . $sql);
            throw $e;
        }
    }

    /**
     * Fetch single row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Insert record and return last insert ID
     */
    public function insert($table, $data) {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = ':' . implode(', :', $keys);

        $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
        $this->query($sql, $data);

        return $this->connection->lastInsertId();
    }

    /**
     * Update record
     */
    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach (array_keys($data) as $key) {
            $set[] = "$key = :$key";
        }
        $setClause = implode(', ', $set);

        $sql = "UPDATE $table SET $setClause WHERE $where";
        $params = array_merge($data, $whereParams);

        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete record
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        return $this->connection->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->connection->rollBack();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Database-agnostic upsert (insert or update)
     * Works with both MySQL and PostgreSQL
     */
    public function upsert($table, $data, $uniqueColumns) {
        $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');

        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = ':' . implode(', :', $keys);

        if ($driver === 'pgsql') {
            // PostgreSQL: INSERT ... ON CONFLICT ... DO UPDATE
            $conflictColumns = implode(', ', $uniqueColumns);
            $updateSet = [];
            foreach ($keys as $key) {
                if (!in_array($key, $uniqueColumns)) {
                    $updateSet[] = "$key = EXCLUDED.$key";
                }
            }
            $updateSet[] = "updated_at = CURRENT_TIMESTAMP";
            $updateClause = implode(', ', $updateSet);

            $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)
                    ON CONFLICT ($conflictColumns) DO UPDATE SET $updateClause";
        } else {
            // MySQL: INSERT ... ON DUPLICATE KEY UPDATE
            $updateSet = [];
            foreach ($keys as $key) {
                if (!in_array($key, $uniqueColumns)) {
                    $updateSet[] = "$key = VALUES($key)";
                }
            }
            $updateSet[] = "updated_at = CURRENT_TIMESTAMP";
            $updateClause = implode(', ', $updateSet);

            $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)
                    ON DUPLICATE KEY UPDATE $updateClause";
        }

        return $this->query($sql, $data);
    }
}
