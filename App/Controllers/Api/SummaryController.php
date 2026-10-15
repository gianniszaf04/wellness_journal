<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Models\DailySummary;

class SummaryController
{
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS = 366;

    // GET /api/summaries?from=2026-09-01&to=2026-09-30 (προεπιλογη: οι τελευταιες 30 μερες)
    public function index(): void
    {
        $to = $this->dateParam('to') ?? new \DateTimeImmutable('today');
        $from = $this->dateParam('from') ?? $to->modify('-' . (self::DEFAULT_DAYS - 1) . ' days');

        if ($from > $to) {
            json_response(['success' => false, 'error' => 'Το from πρέπει να είναι πριν το to.'], 422);
        }
        if ($from->diff($to)->days >= self::MAX_DAYS) {
            json_response(['success' => false, 'error' => 'Το διάστημα μπορεί να είναι έως ' . self::MAX_DAYS . ' μέρες.'], 422);
        }

        $rows = (new DailySummary())->rangeForUser((int) current_user_id(), $from->format('Y-m-d'), $to->format('Y-m-d'));

        json_response([
            'success' => true,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'data' => $rows,
        ]);
    }

    private function dateParam(string $key): ?\DateTimeImmutable
    {
        $value = (string) ($_GET[$key] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($value !== '' && ($date === false || $date->format('Y-m-d') !== $value)) {
            json_response(['success' => false, 'error' => "Μη έγκυρη ημερομηνία στο {$key} (YYYY-MM-DD)."], 422);
        }
        return $value === '' ? null : $date;
    }
}
