<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Core\Request;
use App\Models\UserWord;
use App\Services\AiClient;

class SpellingController
{
    private const MAX_LENGTH = 10000;

    public function check(): void
    {
        $text = (new Request())->input('text', '');
        if (!is_string($text)) {
            json_response(['success' => false, 'error' => 'Μη έγκυρο κείμενο.'], 422);
        }
        $text = mb_substr($text, 0, self::MAX_LENGTH);
        $userId = (int) current_user_id();
        release_session();

        if (trim($text) === '') {
            json_response(['success' => true, 'data' => []]);
        }

        try {
            $spelling = (new AiClient())->checkSpelling($text, (new UserWord())->allForUser($userId));
        } catch (\Exception $e) {
            error_log('Spelling check error: ' . $e->getMessage());
            $spelling = null;
        }

        json_response(['success' => true, 'data' => $spelling ?? [], 'ai_available' => $spelling !== null]);
    }

    public function keepWord(): void
    {
        $word = (new Request())->input('word');
        if (!is_string($word) || !UserWord::isValid($word)) {
            json_response(['success' => false, 'error' => 'Μη έγκυρη λέξη.'], 422);
        }

        if (!(new UserWord())->add((int) current_user_id(), $word)) {
            json_response(['success' => false, 'error' => 'Η λέξη δεν αποθηκεύτηκε.'], 500);
        }

        json_response(['success' => true, 'data' => ['word' => $word]], 201);
    }
}
