<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\ThoughtRecord;
use App\Services\ThinkingTraps;
use App\Services\ThoughtPatterns;

class ThoughtRecordController
{
    private ThoughtRecord $records;

    public function __construct()
    {
        $this->records = new ThoughtRecord();
    }

    public function index(): void
    {
        if ($this->records->countForUser((int) current_user_id()) === 0) {
            $this->about();
            return;
        }
        $this->notebook();
    }

    public function about(): void
    {
        view('thoughts/intro', ['title' => 'Οι έντονες στιγμές']);
    }

    public function notebook(): void
    {
        view('thoughts/notebook', [
            'title'    => 'Μάθε για τον εαυτό σου',
            'scripts'  => ['api.js', 'thoughts.js'],
            'today'    => greek_date(date('Y-m-d')),
            'patterns' => ThoughtPatterns::from($this->records->allForUser((int) current_user_id())),
            'limits'   => [
                'event' => ThoughtRecord::MAX_EVENT,
                'thought' => ThoughtRecord::MAX_THOUGHT,
                'reframe' => ThoughtRecord::MAX_REFRAME,
                'balanced' => ThoughtRecord::MAX_BALANCED,
                'emotions' => ThoughtRecord::MAX_EMOTIONS,
                'words' => ThoughtRecord::MIN_THOUGHT_WORDS,
            ],
        ]);
    }

    public function pages(): void
    {
        view('thoughts/pages', [
            'title' => 'Οι σελίδες σου',
            'pages' => array_reverse($this->records->allForUser((int) current_user_id())),
        ]);
    }

    public function show(string $id): void
    {
        $record = ctype_digit($id) ? $this->records->findByIdForUser((int) $id, (int) current_user_id()) : null;
        if ($record === null) {
            http_response_code(404);
            view('entry/not-found', ['title' => 'Η σελίδα δεν βρέθηκε']);
            return;
        }

        view('thoughts/show', [
            'title'  => greek_date((string) $record['created_at']),
            'record' => $record,
            'note'   => $record['observed_type'] !== null && ThinkingTraps::exists((string) $record['observed_type'])
                ? ThinkingTraps::describe((string) $record['observed_type']) : null,
            'feel'   => ThinkingTraps::feelLine((string) $record['thought'], $record['thought_mood'], $record['emotions'] ?? []),
        ]);
    }
}
