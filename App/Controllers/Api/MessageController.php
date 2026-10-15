<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Models\DailyMessage;

class MessageController
{
    // GET /api/messages/today -> τα μηνυματα ηρεμιας της σημερας, τα αδιαβαστα πρωτα
    public function today(): void
    {
        $rows = (new DailyMessage())->byDateForUser((int) current_user_id(), date('Y-m-d'));

        json_response(['success' => true, 'data' => $rows]);
    }

    // POST /api/messages/{id}/seen
    public function markSeen(string $id): void
    {
        if (!(new DailyMessage())->markSeen((int) $id, (int) current_user_id())) {
            json_response(['success' => false, 'error' => 'Το μήνυμα δεν βρέθηκε.'], 404);
        }

        json_response(['success' => true]);
    }
}
