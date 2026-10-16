<?php
declare(strict_types=1);

// τρεις καταγραφες σημερα: Μαρια 2 φορες, Νικος και Ελενη απο μια, μεσος ορος -0.1
function user_with_entries(): array
{
    FakeAi::start();
    $user = make_user();
    $first = create_entry($user, 'SCORE=-1 EMO=Λύπη ENT=Μαρία');
    create_entry($user, 'SCORE=0.5 EMO=Χαρά ENT=Μαρία,Νίκος');
    create_entry($user, 'SCORE=0.2 EMO=Ηρεμία ENT=Ελένη');
    return [$user, $first];
}

test('GET /api/entries με σελίδες', function (): void {
    [$user] = user_with_entries();
    $other = make_user();

    $page1 = $user->http->api('GET', 'api/entries?per_page=2&page=1')->json();
    assert_same(2, count($page1['data']), 'σελίδα 1');
    assert_same(['page' => 1, 'per_page' => 2, 'total' => 3, 'total_pages' => 2], $page1['meta']);
    assert_same(1, count($user->http->api('GET', 'api/entries?per_page=2&page=2')->json()['data']), 'σελίδα 2');
    assert_same([], $user->http->api('GET', 'api/entries?page=99')->json()['data'], 'εκτός σελίδων');
    assert_same(20, $user->http->api('GET', 'api/entries?per_page=abc')->json()['meta']['per_page'], 'προεπιλογή per_page');
    assert_same(0, $other->http->api('GET', 'api/entries')->json()['meta']['total'], 'άλλος χρήστης');
});

test('μήνυμα ηρεμίας στο store και σήμανση ως διαβασμένο', function (): void {
    [$user, $first] = user_with_entries();
    $other = make_user();
    $id = dig($first, 'message.id');
    assert_true(is_int($id), 'το POST /api/entries επιστρέφει message.id (υπάρχουν μηνύματα στο seed;)');
    assert_true((string) dig($first, 'message.message_text') !== '', 'μήνυμα με κείμενο');

    $today = fn (): array => $user->http->api('GET', 'api/messages/today')->json()['data'];
    assert_true(in_array($id, array_column($today(), 'id'), true), 'υπάρχει στο /today');

    assert_same(404, $other->http->api('POST', "api/messages/{$id}/seen")->status, 'μήνυμα άλλου');
    assert_same(200, $user->http->api('POST', "api/messages/{$id}/seen")->status, 'seen');
    assert_same(200, $user->http->api('POST', "api/messages/{$id}/seen")->status, 'seen ξανά');

    $rows = $today();
    $seen = array_column($rows, 'user_saw_message', 'id');
    assert_same(1, $seen[$id], 'σημειώθηκε');
    $order = array_column($rows, 'user_saw_message');
    $sorted = $order;
    sort($sorted);
    assert_same($sorted, $order, 'τα αδιάβαστα πρώτα');
});

test('insights: λίστα και σήμανση ως διαβασμένο', function (): void {
    [$user] = user_with_entries();
    $other = make_user();

    $list = $user->http->api('GET', 'api/insights')->json();
    assert_same(1, $list['unseen_count'], 'warning από το SCORE=-1');
    $id = $list['data'][0]['id'];

    assert_same(404, $other->http->api('POST', "api/insights/{$id}/read")->status, 'insight άλλου');
    assert_same(404, $user->http->api('POST', 'api/insights/999999999/read')->status, 'ανύπαρκτο');
    assert_same(200, $user->http->api('POST', "api/insights/{$id}/read")->status);

    $unseen = $user->http->api('GET', 'api/insights?unseen=1')->json();
    assert_same([0, []], [$unseen['unseen_count'], $unseen['data']]);
});

test('summaries και έλεγχος ημερομηνιών', function (): void {
    [$user] = user_with_entries();

    assert_same(3, dig($user->http->api('GET', 'api/summaries')->json(), 'data.0.total_entries_count'), 'σημερινό summary');
    foreach (['from=2026-02-01&to=2026-01-01' => 'from μετά το to', 'from=2026-02-30' => 'ανύπαρκτη μέρα',
        'from=2024-01-01&to=2026-01-01' => 'πάνω από 366 μέρες'] as $query => $label) {
        assert_same(422, $user->http->api('GET', "api/summaries?{$query}")->status, $label);
    }
});

test('analytics', function (): void {
    [$user] = user_with_entries();
    $get = fn (string $path): array => $user->http->api('GET', "api/analytics/{$path}")->json()['data'];

    $emotions = array_column($get('emotions'), 'count', 'emotion');
    ksort($emotions);
    assert_same(['Ηρεμία' => 1, 'Λύπη' => 1, 'Χαρά' => 1], $emotions, 'emotions');
    assert_same([-0.1, 3], [dig($get('weekly'), '0.avg_score'), dig($get('weekly'), '0.count')], 'weekly');
    assert_same(1, $get('streak')['streak'], 'streak');
    assert_same(3, $get('monthly')['current_count'], 'monthly');
    assert_same(['Μαρία', 2], [dig($get('entities?limit=1'), '0.name'), dig($get('entities?limit=1'), '0.mentions')], 'top entity');
});

test('profile: GET και PATCH', function (): void {
    $user = make_user();
    $other = make_user();
    $profile = fn (TestUser $u): array => $u->http->api('GET', 'api/profile')->json()['data'];

    $start = $profile($user);
    assert_same(['default_theme', 'none', []], [$start['theme'], $start['sound'], $start['preferences']], 'προεπιλογές');

    $patched = $user->http->api('PATCH', 'api/profile', ['theme' => 'dark_theme', 'preferences' => ['lang' => 'el']])->json()['data'];
    assert_same(['dark_theme', 'none', ['lang' => 'el']], [$patched['theme'], $patched['sound'], $patched['preferences']], 'patch');

    assert_same(422, $user->http->api('PATCH', 'api/profile', ['theme' => 'pink', 'sound' => 'calm'])->status, 'λάθος theme');
    assert_same(['dark_theme', 'none'], [$profile($user)['theme'], $profile($user)['sound']], 'τίποτα δεν άλλαξε');
    assert_same(422, $user->http->api('PATCH', 'api/profile', ['preferences' => [1, 2]])->status, 'preferences λίστα');
    assert_same(422, $user->http->api('PATCH', 'api/profile', [])->status, 'άδειο');
    assert_same(403, $user->http->api('PATCH', 'api/profile', ['sound' => 'calm'], false)->status, 'χωρίς csrf');
    assert_same('default_theme', $profile($other)['theme'], 'άλλος χρήστης');
});
