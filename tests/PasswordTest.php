<?php
declare(strict_types=1);

// αλλαγη κωδικου απο το $http, επιστρεφει [redirect, μηνυμα στο προφιλ]
function change_password(Http $http, string $current, string $new, string $confirmation): array
{
    $r = $http->form('account/password', [
        'current_password' => $current, 'new_password' => $new, 'new_password_confirmation' => $confirmation,
    ], 'profile');
    preg_match('/class="flash flash--(?:error|success)"[^>]*>([^<]+)/u', $http->get('profile')->body, $m);
    return [$r->redirect(), $m[1] ?? null];
}

test('αλλαγή κωδικού: έλεγχοι και αποσύνδεση των άλλων συσκευών', function (): void {
    $old = 'Old-' . bin2hex(random_bytes(4)) . '!';
    $new = 'New-' . bin2hex(random_bytes(4)) . '!';
    $user = make_user($old);
    [$second, $redirect] = $user->login();
    assert_same('dashboard', $redirect, 'δεύτερη συσκευή');

    assert_same(['profile', 'Λάθος τρέχων κωδικός.'], change_password($user->http, 'wrong-pass', $new, $new));
    assert_same(['profile', 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.'], change_password($user->http, $old, 'short', 'short'));
    assert_same(['profile', 'Οι δύο νέοι κωδικοί δεν ταιριάζουν.'], change_password($user->http, $old, $new, $new . 'x'));
    assert_same(['profile', 'Ο νέος κωδικός είναι ίδιος με τον τρέχοντα.'], change_password($user->http, $old, $old, $old));
    assert_same(200, $second->get('dashboard')->status, 'η άλλη συσκευή ακόμα μέσα');

    clear_rate_limits();
    assert_same(['profile', 'Ο κωδικός άλλαξε.'], change_password($user->http, $old, $new, $new));
    assert_same(200, $user->http->get('dashboard')->status, 'αυτή η συσκευή μένει μέσα');
    assert_same(302, $second->get('dashboard')->status, 'η άλλη συσκευή βγαίνει');
    assert_same(401, $second->get('api/entries')->status, 'και από το API');
    assert_same('login', $user->login($old)[1], 'ο παλιός κωδικός δεν δουλεύει');
    assert_same('dashboard', $user->login($new)[1], 'ο νέος δουλεύει');
});

test('αλλαγή κωδικού έχει rate limit', function (): void {
    $user = make_user();
    $codes = [];
    for ($i = 0; $i < 6; $i++) {
        $codes[] = $user->http->form('account/password', ['current_password' => 'wrong'], 'profile')->status;
    }
    assert_same([302, 302, 302, 302, 302, 429], $codes);
});

// εγγραφη με αυτον τον κωδικο, επιστρεφει [δημιουργηθηκε;, μηνυμα στη φορμα]
function register_with(string $password): array
{
    clear_rate_limits();
    $suffix = bin2hex(random_bytes(5));
    $http = new Http();
    $redirect = $http->form('register', ['username' => 't' . $suffix, 'name' => 'Test', 'email' => "t{$suffix}@" . TEST_EMAIL_DOMAIN, 'password' => $password], 'register')->redirect();
    if ($redirect === 'register') {
        preg_match('/class="flash flash--error"[^>]*>([^<]+)/u', $http->get('register')->body, $m);
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
    $stmt->execute([':u' => 't' . $suffix]);
    return [(int) $stmt->fetchColumn() === 1, $m[1] ?? null];
}

test('κωδικός: τουλάχιστον 8 χαρακτήρες και έως 72 bytes (όριο του bcrypt)', function (): void {
    $tooLong = 'Ο κωδικός είναι πολύ μεγάλος: έως 72 λατινικοί χαρακτήρες ή 36 ελληνικοί.';
    $tooShort = 'Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.';

    assert_same([false, $tooShort], register_with('αβγδ'), '4 ελληνικά = 8 bytes αλλά 4 χαρακτήρες');
    assert_same([true, null], register_with('αβγδεζηθ'), '8 ελληνικά');
    assert_same([true, null], register_with(str_repeat('a', 72)), '72 λατινικά');
    assert_same([false, $tooLong], register_with(str_repeat('a', 73)), '73 λατινικά');
    assert_same([true, null], register_with(str_repeat('α', 36)), '36 ελληνικά = 72 bytes');
    assert_same([false, $tooLong], register_with(str_repeat('α', 37)), '37 ελληνικά = 74 bytes');

    $user = make_user();
    $max = str_repeat('b', 72);
    assert_same(['profile', 'Ο νέος κωδικός είναι πολύ μεγάλος: έως 72 λατινικοί χαρακτήρες ή 36 ελληνικοί.'],
        change_password($user->http, $user->password, $max . 'b', $max . 'b'), 'αλλαγή σε 73 bytes');
    assert_same(['profile', 'Ο κωδικός άλλαξε.'], change_password($user->http, $user->password, $max, $max), 'αλλαγή σε 72 bytes');

    // το login δεν ελεγχει το μηκος: οποιος εχει ηδη μεγαλυτερο κωδικο μπαινει κανονικα
    $old = str_repeat('c', 80);
    db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([':h' => password_hash($old, PASSWORD_BCRYPT), ':id' => $user->id]);
    assert_same('dashboard', $user->login($old)[1], 'υπάρχων κωδικός 80 bytes');
});
