<?php
declare(strict_types=1);

// Χωρις server: καλουν τον κωδικα κατευθειαν.

use App\Core\Router;
use App\Middleware\RateLimit;
use App\Services\AiClient;

test('AiClient::probability δέχεται μόνο αριθμούς στο [0,1]', function (): void {
    $ai = fn (mixed $p): array => ['analysis' => ['irony' => ['probability' => $p]]];

    assert_same(0.8123, AiClient::probability($ai(0.81234), 'irony'));
    assert_same(0.0, AiClient::probability($ai(0), 'irony'), 'ακέραιο 0');
    assert_same(1.0, AiClient::probability($ai(1), 'irony'), 'ακέραιο 1');
    assert_same(null, AiClient::probability($ai(1.2), 'irony'), 'πάνω από 1');
    assert_same(null, AiClient::probability($ai(-0.1), 'irony'), 'αρνητικό');
    assert_same(null, AiClient::probability($ai('0.5'), 'irony'), 'string');
    assert_same(null, AiClient::probability([], 'distortion'), 'λείπει');
});

test('AiClient::detected: πάνω από 0.5, όπως το argmax του μοντέλου', function (): void {
    assert_same(false, AiClient::detected(0.5));
    assert_same(true, AiClient::detected(0.5001));
    assert_same(null, AiClient::detected(null));
});

test('query_int κρατά την προεπιλογή όταν λείπει ή είναι εκτός ορίων', function (): void {
    $saved = $_GET;
    try {
        $_GET = ['a' => '7', 'b' => 'abc', 'c' => '0', 'd' => '101'];
        assert_same(7, query_int('a', 1, 1, 100));
        assert_same(1, query_int('b', 1, 1, 100), 'όχι αριθμός');
        assert_same(1, query_int('c', 1, 1, 100), 'κάτω από το min');
        assert_same(1, query_int('d', 1, 1, 100), 'πάνω από το max');
        assert_same(5, query_int('missing', 5, 1, 100), 'λείπει');
    } finally {
        $_GET = $saved;
    }
});

test('RateLimit::purgeStale σβήνει μόνο αρχεία παλιότερα από το παράθυρο', function (): void {
    $dir = rate_limit_dir();
    @mkdir($dir, 0775, true);
    $stale = $dir . DIRECTORY_SEPARATOR . 'tests-stale.json';
    $fresh = $dir . DIRECTORY_SEPARATOR . 'tests-fresh.json';
    file_put_contents($stale, '[]');
    file_put_contents($fresh, '[]');
    touch($stale, time() - 20 * 60);

    try {
        assert_true(RateLimit::purgeStale() >= 1, 'έσβησε τουλάχιστον ένα');
        assert_same(false, is_file($stale), 'το παλιό σβήστηκε');
        assert_same(true, is_file($fresh), 'το πρόσφατο έμεινε');
    } finally {
        @unlink($stale);
        @unlink($fresh);
    }
});

test('Router: exception σε middleware δίνει 500, JSON για /api', function (): void {
    if (!class_exists('TestsThrowingMiddleware', false)) {
        eval('class TestsThrowingMiddleware { public function handle(): bool { throw new RuntimeException("db down"); } }');
    }
    $router = new Router();
    $router->middleware(['TestsThrowingMiddleware'])->get('/api/x', fn () => print('reached'));
    $router->middleware(['TestsThrowingMiddleware'])->get('/x', fn () => print('reached'));

    $savedLog = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    try {
        foreach (['/api/x' => '{"success":false', '/x' => '<!doctype html>'] as $uri => $start) {
            ob_start();
            $router->dispatch('GET', $uri);
            $out = trim((string) ob_get_clean());
            assert_same(500, http_response_code(), "status {$uri}");
            assert_true(str_starts_with($out, $start), "σώμα {$uri}: " . mb_substr($out, 0, 40));
            assert_true(!str_contains($out, 'reached'), "δεν έφτασε στο action {$uri}");
        }
    } finally {
        ini_set('error_log', (string) $savedLog);
        http_response_code(200);
    }
});
