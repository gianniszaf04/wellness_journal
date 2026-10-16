<?php
declare(strict_types=1);

test('εξαγωγή δεδομένων: όλα του χρήστη, τίποτα άλλου, χωρίς hash', function (): void {
    FakeAi::start();
    $user = make_user();
    $other = make_user();
    create_entry($user, 'SCORE=-1 EMO=Λύπη EMOS=Λύπη,Φόβος DIST=0.9 TYPES=Υπεργενίκευση ENT=Μαρία');
    create_entry($user, 'SCORE=0.5 EMO=Χαρά ENT=Μαρία,Νίκος');
    $user->http->api('PATCH', 'api/profile', ['theme' => 'dark_theme', 'preferences' => ['lang' => 'el']]);
    create_entry($other, 'SCORE=0.9 EMO=Χαρά ENT=ΜυστικόΤουΆλλου');

    $r = $user->http->get('account/export');
    assert_same(200, $r->status);
    assert_same('attachment; filename="wellness-journal-' . date('Y-m-d') . '.json"', $r->header('content-disposition'));
    assert_same('no-store', $r->header('cache-control'));

    $data = $r->json();
    $keys = array_keys($data);
    sort($keys);
    assert_same(['daily_messages', 'daily_summaries', 'entity_relations', 'entries', 'exported_at', 'insights', 'profile', 'user'], $keys);
    assert_same($user->email, $data['user']['email']);
    assert_same(false, array_key_exists('password_hash', $data['user']), 'χωρίς password_hash');
    assert_same(false, str_contains($r->body, '$2y$'), 'κανένα bcrypt hash');
    assert_same(false, str_contains($r->body, 'ΜυστικόΤουΆλλου'), 'τίποτα από τον άλλο χρήστη');

    $entities = array_map(static function (array $e): array {
        $names = array_column($e['entities'], 'name');
        sort($names);
        return $names;
    }, $data['entries']);
    assert_same([['Μαρία'], ['Μαρία', 'Νίκος']], $entities, 'καταγραφές με entities');
    $labels = array_map(
        static fn (array $e): array => [array_column($e['emotions'], 'name'), array_column($e['distortion_types'], 'name')],
        $data['entries']
    );
    assert_same([[['Λύπη', 'Φόβος'], ['Υπεργενίκευση']], [[], []]], $labels, 'καταγραφές με emotions και τύπους διαστρέβλωσης');
    assert_same(1, count($data['insights']), 'insights');
    assert_same(1, count($data['daily_summaries']), 'summaries');
    assert_true($data['daily_messages'] !== [] && !in_array('', array_column($data['daily_messages'], 'message_text'), true), 'μηνύματα με κείμενο');
    $relations = array_column($data['entity_relations'], 'entity_name');
    sort($relations);
    assert_same(['Μαρία', 'Νίκος'], $relations, 'relations');
    assert_same(['dark_theme', ['lang' => 'el']], [$data['profile']['theme'], $data['profile']['preferences']], 'προφίλ');
});

test('εξαγωγή: χωρίς σύνδεση πάει στο login, link στο προφίλ', function (): void {
    $r = (new Http())->get('account/export');
    assert_same([302, 'login'], [$r->status, $r->redirect()]);
    assert_true(str_contains(make_user()->http->get('profile')->body, 'account/export'), 'link στο προφίλ');
});
