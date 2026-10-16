<?php
declare(strict_types=1);

// αλλαγη κωδικου απο το $http, επιστρεφει [redirect, μηνυμα στο προφιλ]
function change_password(Http $http, string $current, string $new, string $confirmation): array
{
    $r = $http->form('account/password', [
        'current_password' => $current, 'new_password' => $new, 'new_password_confirmation' => $confirmation,
    ], 'profile');
    preg_match('/<p style="color:(?:red|green);">([^<]+)/u', $http->get('profile')->body, $m);
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
