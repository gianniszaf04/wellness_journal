<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Models\Entry;

class AnalyticsController
{
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS = 3650;

    // GET /api/analytics/mood-trends?days=30 -> μεσος ορος sentiment ανα ημερα
    public function getMoodTrends(): void
    {
        $userId = (int) current_user_id();

        $days = filter_var($_GET['days'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_DAYS],
        ]);
        if ($days === false) {
            $days = self::DEFAULT_DAYS;
        }

        $rows = (new Entry())->dailyScores($userId, $days);

        $data = array_map(static fn (array $row): array => [
            'date' => $row['date'],
            'avg_score' => round((float) $row['avg_score'], 4),
            'count' => (int) $row['count'],
        ], $rows);

        json_response(['success' => true, 'days' => $days, 'data' => $data]);
    }
}
