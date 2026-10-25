<?php
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class VerificationCode {
    public const PURPOSE_EMAIL = 'email';
    public const PURPOSE_PASSWORD = 'password';
    public const CODE_TTL_MIN = 15;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SEC = 60;

    private PDO $db;

    public function __construct() {
        $this->db = (new Database())->getConnection();
    }

    public function issue(int $userId, string $purpose): string {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $stmt = $this->db->prepare(
            'INSERT INTO verification_codes (user_id, purpose, code_hash, expires_at, attempts, sent_at)
            VALUES (:user_id, :purpose, :code_hash, NOW() + INTERVAL ' . self::CODE_TTL_MIN . ' MINUTE, 0, NOW())
            ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at),
            attempts = 0, sent_at = VALUES(sent_at)');
        $stmt->execute([
            ':user_id' => $userId,
            ':purpose' => $purpose,
            ':code_hash' => password_hash($code, PASSWORD_BCRYPT),
        ]);

        return $code;
    }

    public function secondsUntilResend(int $userId, string $purpose): int {
        $stmt = $this->db->prepare(
            'SELECT GREATEST(0, ' . self::RESEND_COOLDOWN_SEC . ' - TIMESTAMPDIFF(SECOND, sent_at, NOW()))
            FROM verification_codes WHERE user_id = :user_id AND purpose = :purpose');
        $stmt->execute([':user_id' => $userId, ':purpose' => $purpose]);

        return (int) $stmt->fetchColumn();
    }

    public function check(int $userId, string $purpose, string $code): string {
        $stmt = $this->db->prepare(
            'UPDATE verification_codes SET attempts = attempts + 1
            WHERE user_id = :user_id AND purpose = :purpose
            AND attempts < ' . self::MAX_ATTEMPTS . ' AND expires_at > NOW()');
        $stmt->execute([':user_id' => $userId, ':purpose' => $purpose]);

        if ($stmt->rowCount() === 0) {
            return 'expired';
        }

        $stmt = $this->db->prepare('SELECT code_hash FROM verification_codes WHERE user_id = :user_id AND purpose = :purpose');
        $stmt->execute([':user_id' => $userId, ':purpose' => $purpose]);
        $hash = (string) $stmt->fetchColumn();

        return password_verify($code, $hash) ? 'ok' : 'wrong';
    }

    public function clear(int $userId, string $purpose): void {
        $stmt = $this->db->prepare('DELETE FROM verification_codes WHERE user_id = :user_id AND purpose = :purpose');
        $stmt->execute([':user_id' => $userId, ':purpose' => $purpose]);
    }
}
