<?php
require_once __DIR__ . '/../Database.php';

/**
 * User Model
 * Handles user authentication and management
 */
class User {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new user
     */
    public function create($username, $password, $email = null, $fullName = null, $role = 'user') {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $userId = $this->db->insert('users', [
                'username' => $username,
                'password_hash' => $passwordHash,
                'email' => $email,
                'full_name' => $fullName,
                'role' => in_array($role, ['admin', 'user']) ? $role : 'user',
                'status' => 'active'
            ]);

            return $userId;
        } catch (PDOException $e) {
            error_log("Failed to create user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Authenticate user
     */
    public function authenticate($username, $password) {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE username = :username AND status = 'active'",
            ['username' => $username]
        );

        if ($user && password_verify($password, $user['password_hash'])) {
            // Update last login
            $this->db->update(
                'users',
                ['last_login' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $user['id']]
            );

            return $user;
        }

        return false;
    }

    /**
     * Get user by ID
     */
    public function getById($id) {
        return $this->db->fetchOne(
            "SELECT id, username, email, full_name, role, created_at, last_login, status FROM users WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Get user by username
     */
    public function getByUsername($username) {
        return $this->db->fetchOne(
            "SELECT id, username, email, full_name, role, created_at, last_login, status FROM users WHERE username = :username",
            ['username' => $username]
        );
    }

    /**
     * Get all users (admin only)
     */
    public function getAll() {
        return $this->db->fetchAll(
            "SELECT id, username, email, full_name, role, created_at, last_login, status FROM users ORDER BY created_at DESC"
        );
    }

    /**
     * Delete user
     */
    public function delete($id) {
        return $this->db->delete('users', 'id = :id', ['id' => $id]);
    }

    /**
     * Update user
     */
    public function update($id, $data) {
        // Remove sensitive fields that shouldn't be updated directly
        unset($data['password_hash'], $data['id'], $data['created_at']);

        return $this->db->update('users', $data, 'id = :id', ['id' => $id]);
    }

    /**
     * Change password
     */
    public function changePassword($id, $newPassword) {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        return $this->db->update(
            'users',
            ['password_hash' => $passwordHash],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * Check if username exists
     */
    public function usernameExists($username) {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM users WHERE username = :username",
            ['username' => $username]
        );

        return $result['count'] > 0;
    }

    /**
     * Check if email exists
     */
    public function emailExists($email) {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM users WHERE email = :email",
            ['email' => $email]
        );

        return $result['count'] > 0;
    }
}
