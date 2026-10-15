<?php 
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;
use PDOException;

class UserProfile {
    private PDO $db;

    public const DEFAULT_THEME = 'default_theme';
    public const DEFAULT_SOUND = 'none';
    public const VALID_THEMES = ['default_theme', 'dark_theme', 'light_theme'];
    public const VALID_SOUNDS = ['none', 'soft', 'calm', 'nature'];

    public function __construct(){
        $this->db= (new Database()) ->getConnection();
    }

    public function findByUserId(int $userId): ?array {
        if($userId <=0) {
            return null;
        }
        try {
            $stmt= $this->db->prepare(
                'SELECT user_id, preferences, theme_background, sound, updated_at
                FROM user_profiles
                WHERE user_id = :user_id
                LIMIT 1');
                $stmt->execute([':user_id' => $userId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                return $result ?: null;
        } catch (PDOException $e) {
            error_log('UserProfile::findByUserId error: ' . $e->getMessage());
            return null;
        }
    }

    public function createDefault(int $userId):bool {
        if ($userId <= 0) {
            return false ;
        }
    try {
        $stmt = $this->db->prepare('INSERT INTO user_profiles (user_id, theme_background, sound) 
        VALUES (:user_id, :theme, :sound)');

        return $stmt->execute([
            ':user_id' => $userId,
            ':theme' => self::DEFAULT_THEME,
            ':sound' => self::DEFAULT_SOUND,
        ]);
    } catch (PDOException $e) {
        error_log('UserProfile::createDefault error: ' . $e->getMessage());
        return false;
    }
}
    public function getOrCreate(int $userId): array {

        if ($userId <= 0 ) {
            return [];
        }

        $profile = $this->findByUserId($userId);

        if ($profile === null) {
            $this->createDefault($userId);
            $profile=$this->findByUserId($userId);
        }
        return $profile ?? [];
    }

    public function update(int $userId, string $theme, string $sound, ?array $preferences=null): bool {

        if ($userId <=0) {
            return false ;
        }

        $theme = trim($theme);
        $sound = trim($sound);

        if (!$this->isValidTheme($theme)) {
            error_log("UserProfile::update - Invalid theme: {$theme}");
            return false ;
        }

        if (!$this->isValidSound($sound)) {
            error_log("UserProfile::update - Invalid sound: {$sound}");
            return false ;
        }

        if ($preferences !== null  && !$this->isValidPreferences($preferences)) {
            error_log("UserProfile::update - Invalid preferences structure");
            return false ;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE user_profiles SET theme_background = :theme,
                sound = :sound, preferences = :prefs,
                updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
                ');
            
            return $stmt->execute([
                ':theme' => $theme,
                ':sound' => $sound,
                ':prefs' => $preferences !== null
                 ? json_encode($preferences, JSON_UNESCAPED_UNICODE) : null,
                 ':user_id' => $userId,
            ]);
        } catch (PDOException $e) {
            error_log('UserProfile::update error: ' . $e->getMessage());
            return false ;
        }
    }


    public function updateTheme(int $userId, string $theme ) : bool {

        if ($userId <= 0) {
            return false ;
        }

        $theme =trim($theme);

        if (!$this -> isValidTheme($theme)) {
            return false ;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE user_profiles
                SET  theme_background = :theme,
                updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
                ');

                return $stmt->execute([
                    ':theme' => $theme,
                    ':user_id' => $userId,
                ]);
        } catch (PDOException $e) {
            error_log('UserProfile::updateTheme error: ' . $e->getMessage());
            return false;
        }  
    }

    public function updateSound(int $userId, string $sound ) : bool {

        if ($userId <= 0) {
            return false ;
        }

        $sound =trim($sound);

        if (!$this -> isValidSound($sound)) {
            return false ;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE user_profiles
                SET  sound = :sound,
                updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
                ');

                return $stmt->execute([
                    ':sound' => $sound,
                    ':user_id' => $userId,
                ]);
        } catch (PDOException $e) {
            error_log('UserProfile::updateSound error: ' . $e->getMessage());
            return false;
        }  
    }

    public function updatePreferences(int $userId, array $preferences) : bool {

        if ($userId <= 0) {
            return false ;
        }

        if (!$this -> isValidPreferences($preferences)) {
            return false ;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE user_profiles
                SET  preferences = :prefs,
                updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
                ');

                return $stmt->execute([
                    ':prefs' => json_encode($preferences, JSON_UNESCAPED_UNICODE),
                    ':user_id' => $userId,
                ]);
        } catch (PDOException $e) {
            error_log('UserProfile::updatePreferences error: ' . $e->getMessage());
            return false;
        }  
    }



    public function getPreferences(int $userId): array {
        $profile = $this->findByUserId($userId);

        if ($profile === null || empty($profile['preferences'])) {
            return [];
        }

        $decoded = json_decode((string) $profile['preferences'], true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isValidTheme(string $theme): bool {
        return in_array($theme, self::VALID_THEMES, true);
    }
    private function isValidSound(string $sound): bool {
        return in_array($sound, self::VALID_SOUNDS, true);
    }


    private function isValidPreferences(array $preferences): bool {
        if (empty($preferences)) {
            return true;
        }

        return json_encode($preferences, JSON_UNESCAPED_UNICODE) !== false;
    }


    public function delete(int $userId):bool {
        if ($userId <= 0) {
            return false ;
        }

        try {
            $stmt=$this->db->prepare('DELETE FROM user_profiles WHERE user_id = :user_id');
            return $stmt->execute([':user_id' => $userId]);
        }catch (PDOException $e) {
            error_log('UserProfile::delete error ' . $e->getMessage());
            return false ;
        }
    }

    public static function getAvailableThemes(): array {
        return self::VALID_THEMES;
    }

    public static function getAvailableSounds(): array {
        return self::VALID_SOUNDS;
    }

}