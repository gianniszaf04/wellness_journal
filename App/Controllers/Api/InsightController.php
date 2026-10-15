<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Models\Insight;

class InsightController
{
    // GET /api/insights?unseen=1&limit=20
    public function index(): void
    {
        $userId = (int) current_user_id();
        $model = new Insight();

        $data = query_int('unseen', 0, 0, 1) === 1
            ? $model->unseenForUser($userId)
            : $model->recentForUser($userId, query_int('limit', 20, 1, 100));

        json_response(['success' => true, 'data' => $data, 'unseen_count' => $model->unseenCount($userId)]);
    }

    // POST /api/insights/{id}/read
    public function markRead(string $id): void
    {
        if (!(new Insight())->markRead((int) $id, (int) current_user_id())) {
            json_response(['success' => false, 'error' => 'Το insight δεν βρέθηκε.'], 404);
        }

        json_response(['success' => true]);
    }
}
