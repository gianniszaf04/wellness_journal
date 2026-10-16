<?php
declare(strict_types=1);

// ["ονομα:πιθανοτητα"] για τα emotions και τα distortion_types μιας καταγραφης
function labels_of(array $entry): array
{
    $pairs = static fn (array $rows): array => array_map(static fn (array $r): string => "{$r['name']}:{$r['probability']}", $rows);
    return [$pairs($entry['emotions']), $pairs($entry['distortion_types'])];
}

function label_rows(int $entryId): int
{
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM entry_emotions WHERE entry_id = :a) + (SELECT COUNT(*) FROM entry_distortions WHERE entry_id = :b)');
    $stmt->execute([':a' => $entryId, ':b' => $entryId]);
    return (int) $stmt->fetchColumn();
}

test('emotions και τύποι διαστρέβλωσης αποθηκεύονται και επιστρέφονται', function (): void {
    FakeAi::start();
    $user = make_user();

    $stored = create_entry($user, 'SCORE=-0.6 EMO=Άγχος EMOS=Λύπη,Άγχος,Άγνωστο DIST=0.8 TYPES=Πρέπει,Καταστροφολογία,Άγνωστος')['data'];
    $id = $stored['id'];
    $expected = [['Άγχος:0.9000', 'Λύπη:0.7000'], ['Πρέπει:0.9000', 'Καταστροφολογία:0.8000']];
    assert_same($expected, labels_of($stored), 'store');
    assert_same($expected, labels_of($user->http->api('GET', "api/entries/{$id}")->json()['data']), 'show');
    assert_same($expected, labels_of(dig($user->http->api('GET', 'api/entries')->json(), 'data.0')), 'λίστα');
    assert_same(['name', 'emoji', 'color', 'probability'], array_keys($stored['emotions'][0]), 'πεδία emotion');

    $edit = fn (string $body): array => labels_of($user->http->api('PATCH', "api/entries/{$id}", ['body' => $body])->json()['data']);
    assert_same([['Χαρά:0.9000'], []], $edit('SCORE=0.5 EMO=Χαρά EMOS=Χαρά'), 'edit αντικαθιστά');
    assert_same([[], []], $edit('SCORE=0.5 EMO=Χαρά'), 'edit χωρίς λίστες');

    $edit('EMO=Λύπη EMOS=Λύπη DIST=0.7 TYPES=Ταμπέλες');
    FakeAi::stop();
    assert_same([[], []], $edit('νέο κείμενο'), 'AI κάτω: σβήνουν τα παλιά');
    assert_same(0, label_rows($id), 'AI κάτω: ούτε στη βάση');
});

test('emotions και τύποι διαστρέβλωσης φεύγουν με τη διαγραφή', function (): void {
    FakeAi::start();
    $user = make_user();

    $id = create_entry($user, 'EMO=Λύπη EMOS=Λύπη,Φόβος DIST=0.9 TYPES=Ταμπέλες')['data']['id'];
    assert_same(3, label_rows($id), 'πριν');
    assert_same(200, $user->http->api('DELETE', "api/entries/{$id}")->status);
    assert_same(0, label_rows($id), 'μετά');
});
