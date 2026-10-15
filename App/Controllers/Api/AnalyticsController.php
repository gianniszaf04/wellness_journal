<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Models\EmotionEntityRelation;
use App\Models\Entry;

class AnalyticsController
{
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS = 3650;

    // GET /api/analytics/mood-trends?days=30 -> μεσος ορος sentiment ανα ημερα
    public function getMoodTrends(): void
    {
        $userId = (int) current_user_id();

        $days = query_int('days', self::DEFAULT_DAYS, 1, self::MAX_DAYS);

        $rows = (new Entry())->dailyScores($userId, $days);

        $data = array_map(static fn (array $row): array => [
            'date' => $row['date'],
            'avg_score' => round((float) $row['avg_score'], 4),
            'count' => (int) $row['count'],
        ], $rows);

        json_response(['success' => true, 'days' => $days, 'data' => $data]);
    }

    // GET /api/analytics/emotions?days=30 -> ποσες καταγραφες ανα κυριαρχο συναισθημα
    public function getEmotionDistribution(): void
    {
        $days = query_int('days', self::DEFAULT_DAYS, 1, self::MAX_DAYS);
        $rows = (new Entry())->emotionDistribution((int) current_user_id(), $days);

        $data = array_map(static fn (array $row): array => [
            'emotion_id' => (int) $row['id'],
            'emotion' => $row['emotion'],
            'emoji' => $row['emoji'],
            'color' => $row['color'],
            'count' => (int) $row['count'],
        ], $rows);

        json_response(['success' => true, 'days' => $days, 'data' => $data]);
    }

    // GET /api/analytics/weekly?weeks=12 -> μεσος ορος sentiment ανα εβδομαδα (Δευτερα-Κυριακη)
    public function getWeeklyAverage(): void
    {
        $weeks = query_int('weeks', 12, 1, 260);
        $rows = (new Entry())->weeklyAverage((int) current_user_id(), $weeks);

        $data = array_map(static fn (array $row): array => [
            'week' => (int) $row['week_num'],
            'week_start' => $row['week_start'],
            'avg_score' => round((float) $row['avg_score'], 4),
            'count' => (int) $row['count'],
        ], $rows);

        json_response(['success' => true, 'weeks' => $weeks, 'data' => $data]);
    }

    // GET /api/analytics/streak -> συνεχομενες μερες με καταγραφη ως σημερα (ή χθες)
    public function getStreak(): void
    {
        json_response(['success' => true, 'data' => ['streak' => (new Entry())->currentStreak((int) current_user_id())]]);
    }

    // GET /api/analytics/monthly -> τρεχων μηνας σε σχεση με τον προηγουμενο
    public function getMonthlyComparison(): void
    {
        json_response(['success' => true, 'data' => (new Entry())->monthlyComparison((int) current_user_id())]);
    }

    // GET /api/analytics/entities?limit=10 -> τα entities που αναφερονται πιο συχνα
    public function getTopEntities(): void
    {
        $limit = query_int('limit', 10, 1, 100);
        $rows = (new EmotionEntityRelation())->topForUser((int) current_user_id(), $limit);

        $data = array_map(static fn (array $row): array => [
            'name' => $row['entity_name'],
            'type' => $row['entity_type'],
            'mentions' => (int) $row['mentioned_count'],
            'avg_score' => $row['avg_sentiment_score'] !== null ? round((float) $row['avg_sentiment_score'], 4) : null,
            'emotion' => $row['emotion_name'],
            'emoji' => $row['emotion_emoji'],
            'color' => $row['emotion_color'],
            'last_mentioned_at' => $row['last_mentioned_at'],
        ], $rows);

        json_response(['success' => true, 'data' => $data]);
    }
}
