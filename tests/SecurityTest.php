<?php
declare(strict_types=1);

// αποτυχημενο login με τυχαιο email: 302 ή 429
function failed_login(Http $http, string $path = 'login', ?string $email = null): int
{
    $email ??= 'nobody' . bin2hex(random_bytes(4)) . '@' . TEST_EMAIL_DOMAIN;
    return $http->form($path, ['email' => $email, 'password' => 'wrong-password'], 'login')->status;
}

test('ευαίσθητα αρχεία δεν σερβίρονται, οι σελίδες ναι', function (): void {
    $http = new Http();
    @mkdir(rate_limit_dir(), 0775, true);
    file_put_contents(rate_limit_dir() . '/tests-probe.json', '[]');

    try {
        $hidden = ['.env', '.git/config', '.git/HEAD', 'database/schema.sql', 'database/migrate.php', 'bin/maintenance.php',
            'Python/training/dataset.csv', 'App/Core/helpers.php', 'Config/database.php', 'storage/ratelimit/tests-probe.json',
            'tests/run.php', 'README.md'];
        foreach ($hidden as $path) {
            assert_same(404, $http->get($path)->status, $path);
        }
        foreach (['', 'login', 'register'] as $path) {
            assert_same(200, $http->get($path)->status, "/{$path}");
        }
    } finally {
        @unlink(rate_limit_dir() . '/tests-probe.json');
    }
});

test('rate limit: το query string δεν δίνει νέο μετρητή', function (): void {
    clear_rate_limits();
    $http = new Http();
    $codes = [];
    for ($i = 1; $i <= 8; $i++) {
        $codes[] = failed_login($http, "login?x={$i}");
    }
    assert_same([302, 302, 302, 302, 302, 429, 429, 429], $codes);
});

test('rate limit: ταυτόχρονα αιτήματα δεν ξεπερνούν το όριο', function (): void {
    clear_rate_limits();
    // ξεχωριστα sessions, αλλιως το lock του session τα σειριοποιει πριν φτασουν στο rate limit
    $clients = [];
    $multi = curl_multi_init();
    for ($i = 0; $i < 20; $i++) {
        $http = new Http();
        $body = http_build_query(['_csrf' => $http->csrf('login'), 'email' => "par{$i}@" . TEST_EMAIL_DOMAIN, 'password' => 'wrong']);
        $ch = $http->handle('POST', 'login', $body);
        curl_multi_add_handle($multi, $ch);
        $clients[] = [$http, $ch];
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi);
        }
    } while ($running && $status === CURLM_OK);

    $codes = [];
    foreach ($clients as [, $ch]) {
        $codes[] = Http::response($ch, curl_multi_getcontent($ch))->status;
        curl_multi_remove_handle($multi, $ch);
    }
    curl_multi_close($multi);

    $counts = array_count_values($codes);
    ksort($counts);
    assert_same([302 => 5, 429 => 15], $counts);
});

test('rate limit: μετράει και ανά email, από διαφορετικές IP', function (): void {
    $v4 = new Http(base_url_with_host('127.0.0.1'));
    $v6 = new Http(base_url_with_host('[::1]'));
    try {
        $v6->get('login');
    } catch (RuntimeException) {
        skip('ο server δεν ακούει στο [::1]');
    }

    clear_rate_limits();
    $victim = 'victim' . bin2hex(random_bytes(4)) . '@' . TEST_EMAIL_DOMAIN;
    $codes = [];
    for ($i = 0; $i < 3; $i++) {
        $codes[] = failed_login($v4, 'login', $victim);
    }
    for ($i = 0; $i < 3; $i++) {
        $codes[] = failed_login($v6, 'login', $victim);
    }
    assert_same([302, 302, 302, 302, 302, 429], $codes, 'ίδιο email');
    assert_same(302, failed_login($v6), 'άλλο email από την ίδια IP');
});

test('sessions ακυρώνονται με status=0 ή αίτημα διαγραφής', function (): void {
    $user = make_user();
    [$second] = $user->login();
    $update = fn (string $sql) => db()->prepare($sql . ' WHERE id = :id')->execute([':id' => $user->id]);

    assert_same(200, $user->http->get('dashboard')->status, 'πριν');
    assert_same(200, $second->get('dashboard')->status, 'πριν, δεύτερη συσκευή');

    $update('UPDATE users SET status = 0');
    $r = $user->http->get('dashboard');
    assert_same([302, 'login'], [$r->status, $r->redirect()], 'status=0');
    assert_same(401, $second->get('api/entries')->status, 'status=0, API');

    $update('UPDATE users SET status = 1');
    assert_same(302, $user->http->get('dashboard')->status, 'το ακυρωμένο session δεν ξαναζωντανεύει');

    [$third] = $user->login();
    $update('UPDATE users SET deletion_requested_at = NOW()');
    assert_same(302, $third->get('dashboard')->status, 'αίτημα διαγραφής');
});

test('login: ίδιος χρόνος απάντησης όταν το email δεν υπάρχει', function (): void {
    $user = make_user();
    $missing = 'missing-' . bin2hex(random_bytes(4)) . '@' . TEST_EMAIL_DOMAIN;

    // μεσος χρονος (διαμεσος) 5 αποτυχημενων login
    $median = function (string $email): float {
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            clear_rate_limits();
            $start = hrtime(true);
            $r = (new Http())->form('login', ['email' => $email, 'password' => 'wrong-password'], 'login');
            $times[] = (hrtime(true) - $start) / 1e6;
            assert_same('login', $r->redirect(), "αποτυχημένο login {$email}");
        }
        sort($times);
        return $times[2];
    };

    $existing = $median($user->email);
    $notExisting = $median($missing);

    // χωρις το dummy hash το λαθος email απαντα ~10 φορες πιο γρηγορα (δεν τρεχει bcrypt)
    assert_true($notExisting > $existing * 0.6,
        sprintf('email που δεν υπάρχει %.0f ms, υπαρκτό %.0f ms', $notExisting, $existing));

    $dummy = (new ReflectionClassConstant(\App\Controllers\AuthController::class, 'DUMMY_HASH'))->getValue();
    $real = (string) db()->query('SELECT password_hash FROM users WHERE id = ' . $user->id)->fetchColumn();
    assert_same(password_get_info($real)['options'], password_get_info($dummy)['options'], 'ίδιο bcrypt cost με τα πραγματικά');
});
