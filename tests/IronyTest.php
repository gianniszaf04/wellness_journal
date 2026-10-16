<?php
declare(strict_types=1);

// [irony_probability, irony_detected, distortion_probability, distortion_detected] μιας καταγραφης
function irony_fields(Response $r): array
{
    $d = $r->json()['data'];
    return [$d['irony_probability'], $d['irony_detected'], $d['distortion_probability'], $d['distortion_detected']];
}

test('irony και distortion αποθηκεύονται και επιστρέφονται', function (): void {
    FakeAi::start();
    $user = make_user();

    $stored = $user->http->api('POST', 'api/entries', ['body' => 'SCORE=-0.3 EMO=Λύπη IRONY=0.8123 DIST=0.3']);
    $id = $stored->json()['data']['id'];
    assert_same(['0.8123', 1, '0.3000', 0], irony_fields($stored), 'store');
    assert_same(['0.8123', 1, '0.3000', 0], irony_fields($user->http->api('GET', "api/entries/{$id}")), 'show');
    assert_same('0.3000', dig($user->http->api('GET', 'api/entries')->json(), 'data.0.distortion_probability'), 'λίστα');

    $edit = fn (string $body): array => irony_fields($user->http->api('PATCH', "api/entries/{$id}", ['body' => $body]));
    assert_same(['0.5000', 0, '0.5100', 1], $edit('SCORE=0 IRONY=0.5 DIST=0.51'), 'το 0.5 δεν μετράει ως εντοπισμός');
    assert_same([null, null, null, null], $edit('SCORE=0 χωρίς πεδία'), 'χωρίς πεδία');

    $post = fn (string $body): array => irony_fields($user->http->api('POST', 'api/entries', ['body' => $body]));
    assert_same([null, null, null, null], $post('SCORE=0 IRONY=1.7 DIST=-0.2'), 'εκτός ορίων');
    assert_same([null, null, null, null], $post('SCORE=0 BADPROB'), 'όχι αριθμός');

    $edit('IRONY=0.7 DIST=0.6');
    FakeAi::stop();
    assert_same([null, null, null, null], $edit('νέο κείμενο'), 'AI κάτω: σβήνει η παλιά τιμή');
});

test('ζωντανή ανάλυση επιστρέφει irony και distortion', function (): void {
    FakeAi::start();
    $user = make_user();
    $live = fn (string $text): array => $user->http->api('POST', 'api/sentiment/live', ['text' => $text])->json()['data'];

    $data = $live('Ένα αρκετά μεγάλο κείμενο SCORE=0.2 IRONY=0.9 DIST=0.1');
    assert_same(['probability' => 0.9, 'detected' => true], $data['irony']);
    assert_same(['probability' => 0.1, 'detected' => false], $data['distortion']);

    $data = $live('Ένα αρκετά μεγάλο κείμενο χωρίς τίποτα άλλο');
    assert_same([null, null], [$data['irony'], $data['distortion']]);
});
