<?php
declare(strict_types=1);

// Χωρις server: καλουν τον κωδικα κατευθειαν.

use App\Core\Crypt;
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

test('AiClient::scored: label με την πιθανότητά του, null αν λείπει ή είναι άκυρη', function (): void {
    assert_same(
        ['Λύπη' => 0.8123, 'Άγχος' => null, 'Φόβος' => null],
        AiClient::scored(['Λύπη', 3, 'Άγχος', 'Φόβος'], ['Λύπη' => 0.81234, 'Άγχος' => 1.5, 'Φόβος' => '0.7'])
    );
    assert_same(['Λύπη' => null], AiClient::scored(['Λύπη'], null), 'χωρίς πιθανότητες');
    assert_same([], AiClient::scored('Λύπη', ['Λύπη' => 0.9]), 'όχι λίστα');
});

test('AiClient::detected: το detected που στέλνει το μοντέλο, null αν λείπει ή δεν είναι boolean', function (): void {
    $data = ['analysis' => [
        'irony' => ['probability' => 0.9, 'detected' => true],
        'distortion' => ['probability' => 0.9, 'detected' => false],
        'risk' => ['probability' => 0.9],
    ]];
    assert_same(true, AiClient::detected($data, 'irony'));
    assert_same(false, AiClient::detected($data, 'distortion'), 'δεν το βγάζει από την πιθανότητα');
    assert_same(null, AiClient::detected($data, 'risk'), 'χωρίς detected');
    assert_same(null, AiClient::detected(['analysis' => ['irony' => ['detected' => 1]]], 'irony'), 'όχι boolean');
    assert_same(null, AiClient::detected([], 'irony'), 'χωρίς analysis');
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

test('AiClient::withoutHiddenEmotions βγάζει την Κούραση και κρατά τα υπόλοιπα', function (): void {
    $data = AiClient::withoutHiddenEmotions([
        'sentiment_score' => -0.4,
        'dominant_emotion' => 'Κούραση',
        'emotions' => ['Κούραση', 'Λύπη'],
        'analysis' => ['emotion' => [
            'label' => 'Κούραση',
            'labels' => ['Κούραση', 'Λύπη'],
            'probabilities' => ['Κούραση' => 0.9, 'Λύπη' => 0.7],
        ]],
    ]);
    assert_same(['Λύπη', ['Λύπη']], [$data['dominant_emotion'], $data['emotions']]);
    assert_same(['label' => 'Λύπη', 'labels' => ['Λύπη'], 'probabilities' => ['Λύπη' => 0.7]], $data['analysis']['emotion']);
    assert_same(-0.4, $data['sentiment_score']);

    $only = AiClient::withoutHiddenEmotions(['dominant_emotion' => 'Κούραση', 'emotions' => ['Κούραση']]);
    assert_same([null, []], [$only['dominant_emotion'], $only['emotions']], 'μόνο Κούραση');

    $plain = ['dominant_emotion' => 'Χαρά', 'emotions' => ['Χαρά'], 'analysis' => ['emotion' => ['probabilities' => ['Χαρά' => 0.9]]]];
    assert_same($plain, AiClient::withoutHiddenEmotions($plain), 'χωρίς Κούραση: ίδιο');
    assert_same([], AiClient::withoutHiddenEmotions([]), 'άδειο');
});

test('Crypt: κρυπτογραφεί με τυχαίο nonce, διαβάζει παλιό απλό κείμενο, απορρίπτει αλλαγμένο', function (): void {
    $text = 'Σήμερα μίλησα με τη Μαρία. 🙂';
    $a = Crypt::encrypt($text);
    $b = Crypt::encrypt($text);

    assert_true(Crypt::isEncrypted($a) && !str_contains($a, 'Μαρία'), 'δεν φαίνεται το κείμενο');
    assert_true($a !== $b, 'άλλο αποτέλεσμα κάθε φορά');
    assert_same([$text, $text], [Crypt::decrypt($a), Crypt::decrypt($b)]);
    assert_same('', Crypt::decrypt(Crypt::encrypt('')), 'άδειο κείμενο');
    assert_same($text, Crypt::decrypt($text), 'παλιά γραμμή χωρίς κρυπτογράφηση');
    assert_same(null, Crypt::encryptNullable(null));
    assert_same(['body' => $text, 'other' => $a, 'empty' => null], Crypt::decryptColumns(['body' => $a, 'other' => $a, 'empty' => null], ['body', 'empty']));

    $raw = base64_decode(substr($a, strlen(Crypt::PREFIX)));
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'x' ? 'y' : 'x';
    $failed = [];
    foreach ([Crypt::PREFIX . base64_encode($raw), Crypt::PREFIX . 'όχι base64', Crypt::PREFIX . base64_encode('κοντό')] as $bad) {
        try {
            Crypt::decrypt($bad);
            $failed[] = false;
        } catch (RuntimeException) {
            $failed[] = true;
        }
    }
    assert_same([true, true, true], $failed, 'αλλαγμένο ή κατεστραμμένο');

    $key = (string) getenv('APP_KEY');
    putenv('APP_KEY=' . Crypt::generateKey());
    try {
        Crypt::decrypt($a);
        $wrongKey = false;
    } catch (RuntimeException) {
        $wrongKey = true;
    } finally {
        putenv("APP_KEY={$key}");
    }
    assert_true($wrongKey, 'άλλο κλειδί δεν διαβάζει');
    assert_same($text, Crypt::decrypt($a), 'το κλειδί επανήλθε');
});
