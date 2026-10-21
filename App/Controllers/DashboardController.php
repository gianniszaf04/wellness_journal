<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\DailyMessage;
use App\Models\Entry;
use App\Models\Insight;
use App\Models\User;

class DashboardController {
    private const QUOTES = [
        'Δεν χρειάζεται να είναι όλες οι σελίδες φωτεινές για να είναι όμορφο το βιβλίο.',
        'Μια ανάσα, μια σκέψη, μια σελίδα. Αρκεί για σήμερα.',
        'Τα μικρά βήματα μετράνε, ακόμα κι όταν δεν φαίνονται.',
        'Να είσαι ευγενικός με τον εαυτό σου, όπως θα ήσουν με έναν φίλο.',
        'Κάθε μέρα που γράφεις, γνωρίζεις λίγο καλύτερα τον εαυτό σου.',
    ];

    private const MONTHS = ['Ιανουάριός', 'Φεβρουάριός', 'Μάρτιός', 'Απρίλιός', 'Μάιός', 'Ιούνιός',
        'Ιούλιός', 'Αύγουστός', 'Σεπτέμβριός', 'Οκτώβριός', 'Νοέμβριός', 'Δεκέμβριός'];

    private  Entry $entryModel;
    private User $userModel;
    public function __construct() {
        $this->entryModel= new Entry();
        $this->userModel =  new User();
    }

    public function index(): void {
        $userId= (int)  current_user_id();
        $user = $this->userModel->findById($userId);
        $name = trim((string) ($user['name'] ?? ''));
        $firstName = $name !== '' ? explode(' ', $name)[0] : (string) ($user['username'] ?? '');

        $today = new \DateTimeImmutable('today');
        $scores = [];
        foreach ($this->entryModel->dailyScores($userId, 40) as $row) {
            $scores[$row['date']] = (float) $row['avg_score'];
        }
        $moodOn = static fn (string $date): int => isset($scores[$date]) ? mood_level($scores[$date]) : 0;

        $firstOfMonth = $today->modify('first day of this month');
        $calendar = [];
        for ($day = 1, $last = (int) $today->format('t'); $day <= $last; $day++) {
            $date = $firstOfMonth->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
            $mood = $moodOn($date);
            $calendar[] = [
                'day'    => $day,
                'mood'   => $mood,
                'future' => $date > $today->format('Y-m-d'),
                'today'  => $date === $today->format('Y-m-d'),
                'title'  => greek_date($date) . ($mood ? ' · ' . mood_label($mood) : ''),
            ];
        }

        $week = [];
        $weekScores = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->modify("-{$i} days");
            $key = $date->format('Y-m-d');
            $mood = $moodOn($key);
            if (isset($scores[$key])) {
                $weekScores[] = $scores[$key];
            }
            $week[] = [
                'label' => greek_weekday((int) $date->format('w'), true),
                'mood'  => $mood,
                'title' => greek_date($key) . ($mood ? ' · ' . mood_label($mood) : ''),
            ];
        }
        $weekAverage = $weekScores ? number_format((array_sum($weekScores) / count($weekScores) + 1) * 5, 1, ',', '') : null;

        $messages = (new DailyMessage())->todayForUser($userId);
        $hour = (int) date('G');

        view('dashboard', [
            'title'       => 'Αρχική',
            'firstName'   => $firstName,
            'greeting'    => $hour >= 5 && $hour < 17 ? 'Καλημέρα' : 'Καλησπέρα',
            'today'       => greek_date($today->format('Y-m-d')),
            'quote'       => $messages[0]['message_text'] ?? self::QUOTES[(int) $today->format('z') % count(self::QUOTES)],
            'monthName'   => self::MONTHS[(int) $today->format('n') - 1],
            'leadingDays' => (int) $firstOfMonth->format('N') - 1,
            'calendar'    => $calendar,
            'streak'      => $this->entryModel->currentStreak($userId),
            'total'       => $this->entryModel->countForUser($userId),
            'weekAverage' => $weekAverage,
            'topEmotion'  => $this->entryModel->emotionDistribution($userId, 7)[0]['emotion'] ?? null,
            'week'        => $week,
            'insights'    => (new Insight())->recentForUser($userId, 2),
            'recent'      => $this->entryModel->allForUser($userId, 5, 0),
        ]);
    }
}
