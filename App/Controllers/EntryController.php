<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\Entry;
use App\Models\UserProfile;

class EntryController
{
    private Entry $entryModel;

    public function __construct()
    {
        $this->entryModel = new Entry();
    }

    public function showEditor(): void
    {
        $today = date('Y-m-d');
        $profile = (new UserProfile())->getOrCreate((int) current_user_id());
        $sound = (string) ($profile['sound'] ?? UserProfile::DEFAULT_SOUND);

        view('entry/editor', [
            'title'      => 'Νέα σελίδα',
            'scripts'    => ['api.js', 'editor.js', 'sound.js'],
            'sound'      => in_array($sound, UserProfile::VALID_SOUNDS, true) ? $sound : UserProfile::DEFAULT_SOUND,
            'today'      => $today,
            'todayLabel' => greek_date($today),
            'maxLength'  => 10000,
            'prompts'    => [
                'Τι σε έκανε να χαμογελάσεις σήμερα;',
                'Τι σε βάραινε περισσότερο;',
                'Για τι νιώθεις ευγνωμοσύνη;',
                'Τι θα ήθελες να κάνεις αύριο για τον εαυτό σου;',
            ],
        ]);
    }

    public function history(): void
    {
        $userId = (int) current_user_id();
        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $mood = query_int('mood', 0, 0, 5);
        $perPage = 20;

        $total = $this->entryModel->countHistory($userId, $search, $mood);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(query_int('page', 1, 1, PHP_INT_MAX), $pages);

        $months = [];
        foreach ($this->entryModel->historyForUser($userId, $search, $mood, $perPage, ($page - 1) * $perPage) as $entry) {
            $ts = strtotime((string) $entry['entry_date']);
            $key = date('Y-m', $ts);
            $months[$key]['label'] ??= greek_month((int) date('n', $ts)) . ' ' . date('Y', $ts);
            $months[$key]['entries'][] = $entry;
        }

        $link = static fn (int $p): string => url('entry/history') . '?' . http_build_query(array_filter(
            ['q' => $search, 'mood' => $mood ?: null, 'page' => $p > 1 ? $p : null],
            static fn ($v): bool => $v !== null && $v !== ''
        ));

        view('entry/history', [
            'title'    => 'Ιστορικό',
            'search'   => $search,
            'mood'     => $mood,
            'total'    => $total,
            'all'      => $search === '' && $mood === 0 ? $total : $this->entryModel->countForUser($userId),
            'months'   => $months,
            'page'     => $page,
            'pages'    => $pages,
            'prevUrl'  => $page > 1 ? $link($page - 1) : null,
            'nextUrl'  => $page < $pages ? $link($page + 1) : null,
            'deleted'  => isset($_GET['deleted']),
        ]);
    }

    public function view(string $id): void
    {
        $userId = (int) current_user_id();
        $entry = $this->entryModel->findByIdForUser((int) $id, $userId);

        if ($entry === null) {
            http_response_code(404);
            view('entry/not-found', ['title' => 'Η σελίδα δεν βρέθηκε']);
            return;
        }

        view('entry/show', [
            'title'   => greek_date((string) $entry['entry_date']),
            'scripts' => ['api.js', 'entry.js'],
            'entry'   => $entry,
            'mood'    => mood_level($entry['sentiment_score']),
        ]);
    }
}
