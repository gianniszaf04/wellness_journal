<?php
declare(strict_types=1);

// Τρεχει τα tests/*Test.php:
//   php tests/run.php            ολα
//   php tests/run.php password   μονο οσα το αρχειο ή το ονομα τους περιεχει "password"
// Τα HTTP tests μιλανε στον τοπικο Apache (APP_URL ή TEST_BASE_URL) και στη βαση του .env. Φτιαχνουν χρηστες
// *@tests.invalid και τους σβηνουν στο τελος, και αδειαζουν το storage/ratelimit. Μονο για τοπικο dev.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/lib.php';

$filter = mb_strtolower($argv[1] ?? '');
$files = glob(__DIR__ . DIRECTORY_SEPARATOR . '*Test.php') ?: [];
sort($files);
foreach ($files as $file) {
    require $file;
}

$tests = array_values(array_filter(
    $GLOBALS['__tests'],
    static fn (array $t): bool => $filter === '' || str_contains(mb_strtolower($t['file'] . ' ' . $t['name']), $filter)
));

$probe = @file_get_contents(base_url() . '/login');
if ($probe === false) {
    fwrite(STDERR, 'Ο server δεν απαντά στο ' . base_url() . '. Ξεκίνα τον Apache ή όρισε TEST_BASE_URL.' . PHP_EOL);
    exit(1);
}

$counts = ['ok' => 0, 'fail' => 0, 'skip' => 0];
$currentFile = '';

cleanup_test_data();
try {
    foreach ($tests as $t) {
        if ($t['file'] !== $currentFile) {
            $currentFile = $t['file'];
            echo PHP_EOL . $currentFile . PHP_EOL;
        }
        try {
            ($t['fn'])();
            $counts['ok']++;
            echo "  ok    {$t['name']}" . PHP_EOL;
        } catch (SkipTest $e) {
            $counts['skip']++;
            echo "  skip  {$t['name']} ({$e->getMessage()})" . PHP_EOL;
        } catch (Throwable $e) {
            $counts['fail']++;
            $where = $e instanceof AssertionFailed ? '' : ' [' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            echo "  FAIL  {$t['name']}: {$e->getMessage()}{$where}" . PHP_EOL;
        } finally {
            FakeAi::stop();
        }
    }
} finally {
    FakeAi::stop();
    cleanup_test_data();
}

echo PHP_EOL . "{$counts['ok']} ok, {$counts['fail']} failed, {$counts['skip']} skipped" . PHP_EOL;
exit($counts['fail'] > 0 ? 1 : 0);
