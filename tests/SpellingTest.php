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
    assert_same(50, count($spelling(array_fill(0, 60, $item('πεπατημα', 4, 12)))), 'έως 50 λέξεις');
    assert_same([], AiClient::spelling([], $text), 'χωρίς πεδίο spelling');
    assert_same([], AiClient::spelling(['spelling' => 'όχι λίστα'], $text), 'όχι λίστα');
});

test('AiClient::serviceUrl: το /spelling δίπλα στο /analyze', function (): void {
    $saved = getenv('AI_SERVICE_URL');
    try {
        putenv('AI_SERVICE_URL=http://127.0.0.1:8000/analyze');
        assert_same('http://127.0.0.1:8000/analyze', AiClient::serviceUrl('analyze'));
        assert_same('http://127.0.0.1:8000/spelling', AiClient::serviceUrl('spelling'));
        putenv('AI_SERVICE_URL=https://ai.example.test/v1/analyze');
        assert_same('https://ai.example.test/v1/spelling', AiClient::serviceUrl('spelling'), 'με διαδρομή');
    } finally {
        putenv($saved === false ? 'AI_SERVICE_URL' : "AI_SERVICE_URL={$saved}");
    }
});

test('ορθογραφικά: POST api/spelling επιστρέφει θέσεις στο κείμενο όπως στάλθηκε', function (): void {
    FakeAi::start();
    $user = make_user();
    $check = fn (mixed $text): Response => $user->http->api('POST', 'api/spelling', ['text' => $text]);

    $text = "  Σήμερα πιγα, πηγασπιτι \nSPELL=πιγα>πια|πήγα|λίγα,πηγασπιτι>πήγα_σπίτι";
    $r = $check($text);
    assert_same(200, $r->status);
    assert_same([
        ['word' => 'πιγα', 'start' => 9, 'end' => 13, 'suggestions' => ['πια', 'πήγα', 'λίγα']],
        ['word' => 'πηγασπιτι', 'start' => 15, 'end' => 24, 'suggestions' => ['πήγα σπίτι']],
    ], $r->json()['data'], 'χωρίς trim, θέσεις σε χαρακτήρες');
    assert_same(true, $r->json()['ai_available']);

    assert_same([], $check('   ')->json()['data'], 'κενό κείμενο');
    assert_same(422, $check(['πιγα'])->status, 'πίνακας');
    assert_same(401, (new Http())->api('POST', 'api/spelling', ['text' => 'πιγα'], false)->status, 'χωρίς σύνδεση');

    $stored = $user->http->api('POST', 'api/entries', ['body' => 'Σήμερα πιγα. SPELL=πιγα>πήγα']);
    assert_same(false, array_key_exists('spelling', $stored->json()), 'η αποθήκευση δεν φέρνει πια κουτί');
    assert_same('Σήμερα πιγα. SPELL=πιγα>πήγα', $stored->json()['data']['body'], 'το κείμενο δεν αλλάζει μόνο του');

    FakeAi::stop();
    $down = $check('Σήμερα πιγα. SPELL=πιγα>πήγα')->json();
    assert_same([[], false], [$down['data'], $down['ai_available']], 'AI κάτω');
});

test('ορθογραφικά: ✓ κρατά τη λέξη στο προσωπικό λεξικό και δεν ξαναρωτιέται', function (): void {
    FakeAi::start();
    $user = make_user();
    $text = 'Ήμουν σαν λούζερ σήμερα. SPELL=λούζερ>λουθερ';
    $words = fn (TestUser $u): array => array_column($u->http->api('POST', 'api/spelling', ['text' => $text])->json()['data'], 'word');
    assert_same(['λούζερ'], $words($user), 'πριν');

    assert_same(201, $user->http->api('POST', 'api/words', ['word' => 'λούζερ'])->status);
    assert_same(201, $user->http->api('POST', 'api/words', ['word' => 'λούζερ'])->status, 'δεύτερη φορά');
    assert_same(422, $user->http->api('POST', 'api/words', ['word' => 'πήγα σπίτι'])->status, 'το λεξικό μένει χωρίς κενό');
    assert_same(422, $user->http->api('POST', 'api/words', ['word' => ['λούζερ']])->status, 'πίνακας');
    assert_same(422, $user->http->api('POST', 'api/words', ['word' => str_repeat('α', 101)])->status, 'πάνω από 100');

    $stmt = db()->prepare('SELECT word FROM user_words WHERE user_id = :id');
    $stmt->execute([':id' => $user->id]);
    assert_same(['λούζερ'], $stmt->fetchAll(PDO::FETCH_COLUMN), 'μία γραμμή');

    assert_same([], $words($user), 'δεν ξαναρωτιέται');
    assert_same(['λούζερ'], $words(make_user()), 'ο άλλος χρήστης ρωτιέται');
    assert_same(['λούζερ'], $user->http->get('account/export')->json()['words'], 'στην εξαγωγή');
});

test('ορθογραφικά: γραμμή προτάσεων κάτω από τα textarea, χωρίς κουτί μετά την αποθήκευση', function (): void {
    FakeAi::start();
    $user = make_user();
    $editor = $user->http->get('entry/editor')->body;
    assert_true(str_contains($editor, 'data-suggest-for="entry-body"'), 'editor');
    assert_true(!str_contains($editor, 'Μήπως εννοούσες'), 'χωρίς το παλιό κουτί');

    $id = $user->http->api('POST', 'api/entries', ['body' => 'Μια ήσυχη μέρα'])->json()['data']['id'];
    assert_true(str_contains($user->http->get("entry/{$id}")->body, 'data-suggest-for="edit-body"'), 'επεξεργασία');
});
