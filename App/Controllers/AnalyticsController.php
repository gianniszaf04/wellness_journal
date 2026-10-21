<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\EmotionEntityRelation;
use App\Models\Entry;

class AnalyticsController {
    private const RANGES = [30 => 'Τελευταίος μήνας', 90 => '3 μήνες', 365 => 'Χρόνος'];

    public function index(): void {
        $userId = (int) current_user_id();
        $entryModel = new Entry();

        $days = (int) ($_GET['days'] ?? 30);
        if (!isset(self::RANGES[$days])) {
            $days = 30;
        }

        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $previousStart = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days'));

        $daily = [];
        $previous = [];
        foreach ($entryModel->dailyScores($userId, 2 * $days) as $row) {
            $score = round(((float) $row['avg_score'] + 1) * 5, 1);
            if ($row['date'] >= $start) {
                $daily[] = [
                    'date'  => $row['date'],
                    'label' => greek_date($row['date']),
                    'value' => $score,
                    'mood'  => mood_label(mood_level((float) $row['avg_score'])),
                    'level' => mood_level((float) $row['avg_score']),
                    'count' => (int) $row['count'],
                ];
            } elseif ($row['date'] >= $previousStart) {
                $previous[] = $score;
            }
        }

        $average = $daily ? round(array_sum(array_column($daily, 'value')) / count($daily), 1) : null;
        $previousAverage = $previous ? round(array_sum($previous) / count($previous), 1) : null;

        $weekdays = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $w) {
            $values = array_column(array_filter($daily, static fn (array $d): bool => (int) date('w', strtotime($d['date'])) === $w), 'value');
            $weekdays[] = [
                'label' => greek_weekday($w, true),
                'name'  => greek_weekday($w),
                'value' => $values ? round(array_sum($values) / count($values), 1) : null,
                'count' => count($values),
            ];
        }

        $emotions = array_map(static fn (array $row): array => [
            'label' => (string) $row['emotion'],
            'value' => (int) $row['count'],
        ], array_slice($entryModel->emotionDistribution($userId, $days), 0, 8));

        $entities = array_map(static fn (array $row): array => [
            'name'     => (string) $row['entity_name'],
            'mentions' => (int) $row['mentioned_count'],
            'level'    => $row['avg_sentiment_score'] !== null ? mood_level((float) $row['avg_sentiment_score']) : 0,
            'emotion'  => $row['emotion_name'],
        ], (new EmotionEntityRelation())->topForUser($userId, 8));

        view('analytics', [
            'title'           => 'Στατιστικά',
            'scripts'         => ['charts.js'],
            'ranges'          => self::RANGES,
            'days'            => $days,
            'daily'           => $daily,
            'pages'           => array_sum(array_column($daily, 'count')),
            'average'         => $average,
            'delta'           => $average !== null && $previousAverage !== null ? round($average - $previousAverage, 1) : null,
            'streak'          => $entryModel->currentStreak($userId),
            'topEmotion'      => $emotions[0]['label'] ?? null,
            'weekdays'        => $weekdays,
            'emotions'        => $emotions,
            'entities'        => $entities,
        ]);
    }
}
