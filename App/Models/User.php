<?php 
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class User {
    // μετα απο τοσες μερες ο λογαριασμος σβηνεται οριστικα
    public const DELETION_GRACE_DAYS = 30;
    public const UNVERIFIED_TTL_HOURS = 24;
    public const MIN_PASSWORD_LENGTH = 8;
    // το bcrypt αγνοει οτι υπαρχει μετα τα 72 bytes (ενα ελληνικο γραμμα = 2 bytes)
    public const MAX_PASSWORD_BYTES = 72;

    // κοινος κανονας για εγγραφη και αλλαγη κωδικου, null αν ο κωδικος ειναι ενταξει
    public static function passwordError(string $password): ?string {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Ο κωδικός πρέπει να έχει τουλάχιστον ' . self::MIN_PASSWORD_LENGTH . ' χαρακτήρες.';
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return 'Ο κωδικός είναι πολύ μεγάλος: έως ' . self::MAX_PASSWORD_BYTES
                . ' λατινικοί χαρακτήρες ή ' . intdiv(self::MAX_PASSWORD_BYTES, 2) . ' ελληνικοί.';
        }
        return null;
    }

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
        if (self::passwordError($password) !== null) {
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

    public function markEmailVerified(int $id): bool {
        try {
            $stmt = $this->db->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id AND email_verified_at IS NULL');
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('User::markEmailVerified error: ' . $e->getMessage());
            return false;
        }
    }

    public function updatePassword(int $id, string $password): bool {
        if (self::passwordError($password) !== null) {
            return false;
        }
        try {
            $stmt = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $stmt->execute([':hash' => password_hash($password, PASSWORD_BCRYPT), ':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('User::updatePassword error: ' . $e->getMessage());
            return false;
        }
    }

    // μπαινει στο session στο login. Αν αλλαξει ο κωδικος, τα sessions με το παλιο αποτυπωμα ακυρωνονται
    public static function passwordFingerprint(string $passwordHash): string {
        return substr(hash('sha256', $passwordHash), 0, 32);
    }

    public function requestDeletion(int $id): bool {
        try {
            $stmt = $this->db->prepare(
                'UPDATE users SET deletion_requested_at = NOW()
                WHERE id = :id AND deletion_requested_at IS NULL');
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('User::requestDeletion error: ' . $e->getMessage());
            return false;
        }
    }

    public function cancelDeletion(int $id): bool {
        try {
            // μονο αν δεν εχουν περασει οι μερες αναμονης
            $stmt = $this->db->prepare(
                'UPDATE users SET deletion_requested_at = NULL
                WHERE id = :id AND deletion_requested_at IS NOT NULL
                AND deletion_requested_at > NOW() - INTERVAL ' . self::DELETION_GRACE_DAYS . ' DAY');
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('User::cancelDeletion error: ' . $e->getMessage());
            return false;
        }
    }

    // οριστικη διαγραφη οσων ζητησαν διαγραφη πριν απο DELETION_GRACE_DAYS μερες
    // (τα δεδομενα τους σβηνονται μεσω ON DELETE CASCADE). Επιστρεφει ποσους εσβησε.
    public function purgeExpiredDeletions(): int {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM users
                WHERE deletion_requested_at IS NOT NULL
                AND deletion_requested_at <= NOW() - INTERVAL ' . self::DELETION_GRACE_DAYS . ' DAY');
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            error_log('User::purgeExpiredDeletions error: ' . $e->getMessage());
            return 0;
        }
    }

    public function purgeUnverified(): int {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM users
                WHERE email_verified_at IS NULL
                AND created_at <= NOW() - INTERVAL ' . self::UNVERIFIED_TTL_HOURS . ' HOUR');
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            error_log('User::purgeUnverified error: ' . $e->getMessage());
            return 0;
        }
    }

    // ημερομηνια οριστικης διαγραφης (Y-m-d) ή null αν δεν εχει ζητηθει διαγραφη
    public function deletionDate(int $id): ?string {
        $stmt = $this->db->prepare(
            'SELECT DATE(DATE_ADD(deletion_requested_at, INTERVAL ' . self::DELETION_GRACE_DAYS . ' DAY))
            FROM users WHERE id = :id AND deletion_requested_at IS NOT NULL');
        $stmt->execute([':id' => $id]);
        $date = $stmt->fetchColumn();

        return $date !== false ? (string) $date : null;
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