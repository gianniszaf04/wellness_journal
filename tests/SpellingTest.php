<?php
declare(strict_types=1);

use App\Services\AiClient;

test('AiClient::payload στέλνει known_words μόνο όταν υπάρχουν', function (): void {
    assert_same(['text' => 'κείμενο'], AiClient::payload('κείμενο'));
    assert_same(['text' => 'κείμενο'], AiClient::payload('κείμενο', ['', 3]), 'μόνο άκυρα');
    assert_same(['text' => 'κείμενο', 'known_words' => ['λούζερ', 'ποστάρω']], AiClient::payload('κείμενο', ['λούζερ', 'ποστάρω']));
});

test('AiClient::spelling κρατά μόνο λέξεις που υπάρχουν στις θέσεις τους', function (): void {
    $text = 'Ένα πεπατημα και ένα λούζερ σήμερα';
    $item = fn (string $word, mixed $start, mixed $end, mixed $suggestions = ['περπάτημα']): array
        => ['word' => $word, 'start' => $start, 'end' => $end, 'suggestions' => $suggestions];
    $spelling = fn (array $items, array $known = []): array => AiClient::spelling(['spelling' => $items], $text, $known);

    assert_same([$item('πεπατημα', 4, 12)], $spelling([$item('πεπατημα', 4, 12)]), 'σωστές θέσεις σε χαρακτήρες');
    assert_same([], $spelling([$item('πεπατημα', 5, 13)]), 'λάθος θέσεις');
    assert_same([], $spelling([$item('πεπατημα', 4, 100)]), 'εκτός κειμένου');
    assert_same([], $spelling([$item('πεπατημα', '4', '12')]), 'θέσεις ως string');
    assert_same([], $spelling([$item('λούζερ', 21, 27, ['λουθερ'])], ['Λούζερ']), 'λέξη του λεξικού');
    assert_same([], $spelling([$item('πεπατημα', 4, 12, ['πεπατημα', 'τρεις λέξεις εδώ', 'δύο  κενά', ' αρχή', 'τέλος ', '<b>'])]), 'καμία έγκυρη πρόταση');
    assert_same([$item('πεπατημα', 4, 12, ['πήγα σπίτι'])], $spelling([$item('πεπατημα', 4, 12, ['πήγα σπίτι'])]), 'δύο λέξεις με ένα κενό');
    assert_same([$item('πεπατημα', 4, 12, ['α', 'β', 'γ'])], $spelling([$item('πεπατημα', 4, 12, ['α', 'β', 'α', 'γ', 'δ'])]), 'έως 3 χωρίς διπλές');
    assert_same(5, count($spelling(array_fill(0, 7, $item('πεπατημα', 4, 12)))), 'έως 5 λέξεις');
    assert_same([], AiClient::spelling([], $text), 'χωρίς πεδίο spelling');
    assert_same([], AiClient::spelling(['spelling' => 'όχι λίστα'], $text), 'όχι λίστα');
});

test('ορθογραφικά: πρόταση μετά την αποθήκευση και αλλαγή μόνο με κλικ', function (): void {
    FakeAi::start();
    $user = make_user();
    $stored = $user->http->api('POST', 'api/entries', ['body' => 'Σήμερα πεπατημα στη θάλασσα. SCORE=0.5 SPELL=πεπατημα>περπάτημα|πατήματα']);
    assert_same(201, $stored->status);
    $id = $stored->json()['data']['id'];
    $item = ['word' => 'πεπατημα', 'start' => 7, 'end' => 15, 'suggestions' => ['περπάτημα', 'πατήματα']];
    assert_same([$item], $stored->json()['spelling'], 'store');
    assert_same(true, str_contains($user->http->api('GET', "api/entries/{$id}")->json()['data']['body'], 'Σήμερα πεπατημα'), 'το κείμενο δεν άλλαξε μόνο του');

    $fix = fn (array $data): Response => $user->http->api('POST', "api/entries/{$id}/spelling", $data);
    $base = ['word' => 'πεπατημα', 'start' => 7, 'end' => 15];

    assert_same(409, $fix(['start' => 8, 'end' => 16] + $base + ['replacement' => 'περπάτημα'])->status, 'άλλες θέσεις');
    assert_same(422, $fix($base + ['replacement' => '<b>x</b>'])->status, 'άκυρη αντικατάσταση');
    assert_same(422, $fix($base + ['replacement' => 'τρεις λέξεις εδώ'])->status, 'τρεις λέξεις');
    assert_same(422, $fix(['start' => 15, 'end' => 7] + $base + ['replacement' => 'περπάτημα'])->status, 'ανάποδες θέσεις');
    assert_same(404, make_user()->http->api('POST', "api/entries/{$id}/spelling", $base + ['replacement' => 'περπάτημα'])->status, 'άλλου χρήστη');

    $fixed = $fix($base + ['replacement' => 'περπάτημα']);
    assert_same(200, $fixed->status);
    $data = $fixed->json();
    assert_same('Σήμερα περπάτημα στη θάλασσα. SCORE=0.5 SPELL=πεπατημα>περπάτημα|πατήματα', $data['data']['body']);
    assert_same('0.5000', $data['data']['sentiment_score'], 'ξανατρέχει η ανάλυση');
    assert_same([], $data['spelling'], 'η λέξη διορθώθηκε');
    assert_same(409, $fix($base + ['replacement' => 'πατήματα'])->status, 'δεύτερη φορά στην ίδια θέση');
});

test('ορθογραφικά: κολλημένες λέξεις χωρίζονται με ένα κενό', function (): void {
    FakeAi::start();
    $user = make_user();
    $stored = $user->http->api('POST', 'api/entries', ['body' => 'Μετά πηγασπιτι νωρίς. SPELL=πηγασπιτι>πήγα_σπίτι']);
    $id = $stored->json()['data']['id'];
    $item = ['word' => 'πηγασπιτι', 'start' => 5, 'end' => 14, 'suggestions' => ['πήγα σπίτι']];
    assert_same([$item], $stored->json()['spelling']);

    $fixed = $user->http->api('POST', "api/entries/{$id}/spelling", ['word' => 'πηγασπιτι', 'start' => 5, 'end' => 14, 'replacement' => 'πήγα σπίτι']);
    assert_same(200, $fixed->status);
    assert_same('Μετά πήγα σπίτι νωρίς. SPELL=πηγασπιτι>πήγα_σπίτι', $fixed->json()['data']['body']);
    assert_same(422, $user->http->api('POST', 'api/words', ['word' => 'πήγα σπίτι'])->status, 'το λεξικό μένει χωρίς κενό');
});

test('ορθογραφικά: «Είναι σωστό» κρατά τη λέξη στο προσωπικό λεξικό', function (): void {
    FakeAi::start();
    $user = make_user();
    $body = 'Ήμουν σαν λούζερ σήμερα. SPELL=λούζερ>λουθερ';
    assert_same('λούζερ', $user->http->api('POST', 'api/entries', ['body' => $body])->json()['spelling'][0]['word'] ?? null, 'πριν');

    assert_same(201, $user->http->api('POST', 'api/words', ['word' => 'λούζερ'])->status);
    assert_same(201, $user->http->api('POST', 'api/words', ['word' => 'λούζερ'])->status, 'δεύτερη φορά');
    assert_same(422, $user->http->api('POST', 'api/words', ['word' => 'δύο λέξεις'])->status);
    assert_same(422, $user->http->api('POST', 'api/words', ['word' => ['λούζερ']])->status, 'πίνακας');
    assert_same(422, $user->http->api('POST', 'api/words', ['word' => str_repeat('α', 101)])->status, 'πάνω από 100');

    $stmt = db()->prepare('SELECT word FROM user_words WHERE user_id = :id');
    $stmt->execute([':id' => $user->id]);
    assert_same(['λούζερ'], $stmt->fetchAll(PDO::FETCH_COLUMN), 'μία γραμμή');

    assert_same([], $user->http->api('POST', 'api/entries', ['body' => $body])->json()['spelling'], 'δεν ξαναρωτιέται');
    assert_same('λούζερ', make_user()->http->api('POST', 'api/entries', ['body' => $body])->json()['spelling'][0]['word'] ?? null, 'ο άλλος χρήστης ρωτιέται');
    assert_same(['λούζερ'], $user->http->get('account/export')->json()['words'], 'στην εξαγωγή');
});

test('ορθογραφικά: χωρίς πεδίο spelling από το AI το κουτί δεν εμφανίζεται', function (): void {
    FakeAi::start();
    $user = make_user();
    assert_same([], $user->http->api('POST', 'api/entries', ['body' => 'Μια ήσυχη μέρα SCORE=0.2'])->json()['spelling']);

    FakeAi::stop();
    assert_same([], $user->http->api('POST', 'api/entries', ['body' => 'Μια ήσυχη μέρα πεπατημα'])->json()['spelling'], 'AI κάτω');

    $html = $user->http->get('entry/editor')->body;
    assert_true(preg_match('/<section class="spelling" id="spelling" hidden/', $html) === 1, 'κρυφό κουτί στον editor');
});
