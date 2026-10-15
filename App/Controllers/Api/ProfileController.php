<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Core\Request;
use App\Models\UserProfile;

class ProfileController
{
    private const MAX_PREFERENCES_BYTES = 10000;

    // GET /api/profile
    public function show(): void
    {
        json_response(['success' => true, 'data' => $this->profile((int) current_user_id())]);
    }

    // PATCH /api/profile {"theme": "...", "sound": "...", "preferences": {...}} - οποιοδηποτε υποσυνολο
    public function update(): void
    {
        $userId = (int) current_user_id();
        $request = new Request();
        $model = new UserProfile();
        $model->getOrCreate($userId);

        if (!$request->has('theme') && !$request->has('sound') && !$request->has('preferences')) {
            json_response(['success' => false, 'error' => 'Στείλε τουλάχιστον ένα από theme, sound, preferences.'], 422);
        }

        // ελεγχος ολων πριν γραψουμε, ωστε να μη μεινει μισο update
        $theme = $request->input('theme');
        if ($theme !== null && !in_array($theme, UserProfile::VALID_THEMES, true)) {
            json_response(['success' => false, 'error' => 'Μη έγκυρο theme. Επιτρέπονται: ' . implode(', ', UserProfile::VALID_THEMES) . '.'], 422);
        }

        $sound = $request->input('sound');
        if ($sound !== null && !in_array($sound, UserProfile::VALID_SOUNDS, true)) {
            json_response(['success' => false, 'error' => 'Μη έγκυρος ήχος. Επιτρέπονται: ' . implode(', ', UserProfile::VALID_SOUNDS) . '.'], 422);
        }

        $preferences = $request->input('preferences');
        if ($preferences !== null && (!is_array($preferences) || (array_is_list($preferences) && $preferences !== [])
            || strlen((string) json_encode($preferences, JSON_UNESCAPED_UNICODE)) > self::MAX_PREFERENCES_BYTES)) {
            json_response(['success' => false, 'error' => 'Το preferences πρέπει να είναι αντικείμενο JSON έως ' . self::MAX_PREFERENCES_BYTES . ' bytes.'], 422);
        }

        $ok = ($theme === null || $model->updateTheme($userId, $theme))
            && ($sound === null || $model->updateSound($userId, $sound))
            && ($preferences === null || $model->updatePreferences($userId, $preferences));

        if (!$ok) {
            json_response(['success' => false, 'error' => 'Η αποθήκευση του προφίλ απέτυχε.'], 500);
        }

        json_response(['success' => true, 'data' => $this->profile($userId)]);
    }

    private function profile(int $userId): array
    {
        $model = new UserProfile();
        $profile = $model->getOrCreate($userId);

        return [
            'theme' => $profile['theme_background'] ?? UserProfile::DEFAULT_THEME,
            'sound' => $profile['sound'] ?? UserProfile::DEFAULT_SOUND,
            'preferences' => (object) $model->getPreferences($userId),
            'updated_at' => $profile['updated_at'] ?? null,
            'options' => ['themes' => UserProfile::VALID_THEMES, 'sounds' => UserProfile::VALID_SOUNDS],
        ];
    }
}
