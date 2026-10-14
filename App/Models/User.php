<?php 
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class User {
    private PDO $db;

    public function __construct() {
        $this->db = (new Database())->getConnection();
    }

    public function findDbEmail(string $email): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE processed_email = LOWER(TRIM(:email)) LIMIT 1');
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function create(string $username, string $name, string $email, string $password): bool {
        $username = trim($username);
        $name = trim($name);
        $email = trim($email);

        // Validate input
        // mb_strlen: μετραει χαρακτηρες, οχι bytes (τα ελληνικα πιανουν 2 bytes το καθενα)
        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            return false;
        }
        if (mb_strlen($name) < 1 || mb_strlen($name) > 70) {
            return false;
        }
        if (strlen($email) < 5 || strlen($email) > 254) {
            return false;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('INSERT INTO users (username, name, email, password_hash) VALUES (:username, :name, :email, :password_hash)');

            return $stmt->execute([
                ':username' => $username,
                ':name' => $name,
                ':email' => $email,
                ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ]);
        } catch (\PDOException $e) {
            error_log('User::create error: ' . $e->getMessage());
            return false;
        }
    }

    public function emailExists(string $email): bool {
        return $this->findDbEmail($email) !== null;
    }

    public function usernameExists(string $username): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $stmt->execute([':username' => trim($username)]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function updateStatus(int $id, int $status): bool {
        if ($status < 0 || $status > 1) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('UPDATE users SET status = :status WHERE id = :id');
            return $stmt->execute([':status' => $status, ':id' => $id]);
        } catch (\PDOException $e) {
            error_log('User::updateStatus error: ' . $e->getMessage());
            return false;
        }
    }
}