<?php
declare(strict_types=1);

// Εργασιες συντηρησης, για cron μια φορα τη μερα:
//   php bin/maintenance.php
// Τα cleanUpOld* σβηνουν οριστικα οσα ειναι παλιοτερα απο τις μερες που οριζει το καθε model.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/Config/bootstrap.php';

use App\Middleware\RateLimit;
use App\Models\DailyMessage;
use App\Models\EmotionEntityRelation;
use App\Models\Insight;
use App\Models\User;

$tasks = [
    'Λογαριασμοί μετά την περίοδο διαγραφής' => fn (): int => (new User())->purgeExpiredDeletions(),
    'Παλιά daily messages' => fn (): int => (new DailyMessage())->cleanUpOld(),
    'Παλιά insights' => fn (): int => (new Insight())->cleanUpOld(),
    'Relations χωρίς πρόσφατη αναφορά' => fn (): int => (new EmotionEntityRelation())->cleanUpOldRelations(),
    'Ληγμένα αρχεία rate limit' => fn (): int => RateLimit::purgeStale(),
];

$failed = false;

foreach ($tasks as $label => $task) {
    try {
        echo "{$label}: {$task()}" . PHP_EOL;
    } catch (Throwable $e) {
        $failed = true;
        error_log("maintenance: {$label}: " . $e->getMessage());
        fwrite(STDERR, "{$label}: απέτυχε ({$e->getMessage()})" . PHP_EOL);
    }
}

exit($failed ? 1 : 0);
