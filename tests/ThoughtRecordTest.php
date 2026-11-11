<?php
declare(strict_types=1);

use App\Models\ThoughtRecord;
use App\Services\ThinkingTraps;

const TR_CONFIDENT = 'Τα έκανα πάλι θάλασσα. DIST=0.9 TYPEP=Ανάγνωση_σκέψης:0.95,Ταμπέλες:0.3';
const TR_ASK = 'Τα έκανα πάλι θάλασσα. DIST=0.7 TYPEP=Ταμπέλες:0.6,Υπεργενίκευση:0.5,Πρέπει:0.1';
const TR_MOOD = 'Σίγουρα όλοι πιστεύουν ότι δεν ξέρω τι κάνω.';

function tr_suggest(TestUser $user, string $thought, array $extra = []): Response
{
    return $user->http->api('POST', 'api/thought-records/suggest', $extra + [
        'event' => 'Ο καθηγητής διόρθωσε τον κώδικά μου μπροστά σε όλη την τάξη.',
        'emotions' => [['emotion' => 'Ντροπή', 'before' => 80], ['emotion' => 'Άγχος', 'before' => 55]],
        'thought' => $thought,
        'thought_mood' => TR_MOOD,
    ]);
}

function tr_token(TestUser $user, string $thought, array $extra = []): array
{
    $r = tr_suggest($user, $thought, $extra);
    assert_same(200, $r->status, $r->body);
    return $r->json()['data'];
}

function tr_store(TestUser $user, string $token, array $answers, ?string $response, array $extra = []): Response
{
    return $user->http->api('POST', 'api/thought-records', $extra + [
        'token' => $token,
        'answers' => $answers,
        'response' => $response,
        'reframe' => 'Στοιχεία δεν έχω.',
        'balanced_thought' => 'Ένα λάθος δεν λέει ποιος είμαι.',
        'after' => ['Ντροπή' => 45, 'Άγχος' => 35],
    ]);
}

function tr_saved(Response $r): array
{
    assert_same(201, $r->status, $r->body);
    $stmt = db()->prepare('SELECT * FROM thought_records WHERE id = :id');
    $stmt->execute([':id' => (int) $r->json()['data']['id']]);
    return (array) $stmt->fetch(PDO::FETCH_ASSOC);
}

function tr_pick(array $row, array $keys): array
{
    return array_map(static fn (string $key): ?string => $row[$key] === null ? null : (string) $row[$key], $keys);
}

test('ThinkingTraps: σειρά, σίγουρο με Δ = 0,39, πιο έντονο συναίσθημα, παράθεση, εισαγωγικά', function (): void {
    $ranking = ThinkingTraps::ranking(['Πρέπει' => 0.2, 'Ταμπέλες' => 0.7, 'Άγνωστο' => 0.9, 'Προφητεία' => 'high', 'Υπεργενίκευση' => 0.4]);
    assert_same(['Ταμπέλες', 'Υπεργενίκευση', 'Πρέπει'], array_column($ranking, 'type'), 'σειρά');
    $pair = fn (float $a, float $b): array => [['type' => 'Ταμπέλες', 'probability' => $a], ['type' => 'Πρέπει', 'probability' => $b]];
    assert_same(
        [true, false, false, false, false],
        [
            ThinkingTraps::isConfident(true, $pair(0.8, 0.41)),
            ThinkingTraps::isConfident(true, $pair(0.8, 0.4101)),
            ThinkingTraps::isConfident(false, $pair(0.95, 0.1)),
            ThinkingTraps::isConfident(null, $pair(0.95, 0.1)),
            ThinkingTraps::isConfident(true, [['type' => 'Ταμπέλες', 'probability' => 0.99]]),
        ],
        'σίγουρο'
    );

    $emotions = fn (array $pairs): array => array_map(static fn (array $p): array => ['emotion' => $p[0], 'before' => $p[1]], $pairs);
    assert_same('Ντροπή', ThinkingTraps::strongest($emotions([['Ντροπή', 70], ['Άγχος', 70]]))['emotion'], 'ισοπαλία: το πρώτο');
    assert_same(
        ['others', 'meaning', 'fear', 'meaning'],
        [
            ThinkingTraps::moodKey($emotions([['Ντροπή', 70], ['Άγχος', 70]])),
            ThinkingTraps::moodKey($emotions([['Άγχος', 50], ['Λύπη', 80]])),
            ThinkingTraps::moodKey($emotions([['Φόβος', 10]])),
            ThinkingTraps::moodKey($emotions([['Ενοχή', 90], ['Θυμός', 20]])),
        ],
        'δεύτερη ερώτηση'
    );

    assert_same(['θα με απολύσουν', 'θα αποτύχω', 'Ότιδήποτε κάνω'], [
        ThinkingTraps::quote('ότι θα με απολύσουν.'),
        ThinkingTraps::quote('Πως θα αποτύχω'),
        ThinkingTraps::quote('Ότιδήποτε κάνω.'),
    ], 'ότι/πως και τελεία');
    $long = ThinkingTraps::quote(str_repeat('πάντα τα κάνω λάθος ', 10));
    assert_true(mb_strlen($long) <= 81 && str_ends_with($long, '…') && !str_contains($long, ' …'), "κομμένη παράθεση: {$long}");

    assert_same('«“Πάντα” και “ποτέ”»', ThinkingTraps::describe('Υπεργενίκευση')['quoted']);
    assert_same('«Τα “πρέπει”»', ThinkingTraps::describe('Πρέπει')['quoted']);
    assert_same(
        'Όταν λες στον εαυτό σου «Σίγουρα όλοι πιστεύουν ότι δεν ξέρω τι κάνω», τι κάνει αυτό στην ντροπή σου;',
        ThinkingTraps::feelLine('Τα έκανα θάλασσα.', TR_MOOD, $emotions([['Ντροπή', 80], ['Άγχος', 55]]))
    );
    assert_same(
        'Όταν λες στον εαυτό σου «Τα έκανα θάλασσα», τι κάνει αυτό στις ενοχές σου;',
        ThinkingTraps::feelLine('Τα έκανα θάλασσα.', null, $emotions([['Ενοχή', 60]])),
        'χωρίς δεύτερη απάντηση'
    );
    assert_same(['trap', 'neutral'], array_column(ThinkingTraps::marginQuestion('Πρέπει', 1)['options'], 'answer'));
    assert_same(['neutral', 'trap'], array_column(ThinkingTraps::marginQuestion('Πρέπει', 2)['options'], 'answer'));
    assert_same([3, 2, 0], [ThoughtRecord::wordCount('θα τα κάνω'), ThoughtRecord::wordCount('  δεν  μπορώ… '), ThoughtRecord::wordCount('— !')]);
});

test('ThinkingTraps::modelText: τελεία ανάμεσα στις δύο απαντήσεις, μόνο όταν λείπει', function (): void {
    foreach ([
        [['Τα έκανα πάλι θάλασσα', 'να με απολύσουν'], 'Τα έκανα πάλι θάλασσα. να με απολύσουν'],
        [['Τα έκανα πάλι θάλασσα.', 'να με απολύσουν'], 'Τα έκανα πάλι θάλασσα. να με απολύσουν'],
        [['Γιατί πάντα εμένα;', 'ότι γελάνε μαζί μου'], 'Γιατί πάντα εμένα; ότι γελάνε μαζί μου'],
        [['Δεν θα τα καταφέρω!', 'να μείνω πίσω'], 'Δεν θα τα καταφέρω! να μείνω πίσω'],
        [['Είπα «είμαι άχρηστος»', 'ότι γελάνε'], 'Είπα «είμαι άχρηστος». ότι γελάνε'],
        [['Τα έκανα θάλασσα, ', 'πάλι'], 'Τα έκανα θάλασσα. πάλι'],
        [['  Πάλι λάθος  ', '  ότι δεν μπορώ  '], 'Πάλι λάθος. ότι δεν μπορώ'],
        [['Δεν άκουσε κανείς τον Μιχαήλ', 'ότι φταίω'], 'Δεν άκουσε κανείς τον Μιχαήλ. ότι φταίω'],
        [['Τα έκανα θάλασσα', ''], 'Τα έκανα θάλασσα'],
        [['Τα έκανα θάλασσα', null], 'Τα έκανα θάλασσα'],
    ] as [[$thought, $mood], $expected]) {
        assert_same($expected, ThinkingTraps::modelText($thought, $mood), $thought);
    }
});

test('τετράδιο: σίγουρο μοντέλο, σημείωση χωρίς ερωτήσεις, «Ναι», «Όχι τώρα», «Δεν μου ταιριάζει»', function (): void {
    FakeAi::start();
    $user = make_user();

    $data = tr_token($user, TR_CONFIDENT);
    assert_same(['confident', false], [$data['mode'], isset($data['questions'])]);
    assert_same(['Ανάγνωση σκέψης', '«Διαβάζω το μυαλό των άλλων»'], [$data['note']['type'], $data['note']['quoted']]);
    assert_same('Όταν λες στον εαυτό σου «Σίγουρα όλοι πιστεύουν ότι δεν ξέρω τι κάνω», τι κάνει αυτό στην ντροπή σου;', $data['feel']);

    assert_same(422, tr_store($user, $data['token'], ['trap'], 'yes')->status, 'απαντήσεις χωρίς ερωτήσεις');
    assert_same(422, tr_store($user, $data['token'], [], null)->status, 'χωρίς απάντηση στη σημείωση');
    assert_same(422, tr_store($user, $data['token'], [], 'maybe')->status, 'άγνωστη απάντηση');
    assert_same(422, tr_store($user, $data['token'], [], 'yes', ['after' => ['Ντροπή' => 45]])->status, 'λείπει ένταση');

    $r = tr_store($user, $data['token'], [], 'yes');
    $row = tr_saved($r);
    assert_same(
        [(string) $user->id, 'confident', 'Ανάγνωση σκέψης', null, 'yes', 'others', TR_MOOD, 'Στοιχεία δεν έχω.', 'Ένα λάθος δεν λέει ποιος είμαι.', '0', '1', '0.9000', 'mtl-greekbert-v2-fake'],
        tr_pick($row, ['user_id', 'observation_source', 'observed_type', 'answers', 'response', 'thought_mood_key', 'thought_mood', 'reframe', 'balanced_thought', 'crisis', 'model_detected', 'model_probability', 'model_version'])
    );
    assert_same([['emotion' => 'Ντροπή', 'before' => 80, 'after' => 45], ['emotion' => 'Άγχος', 'before' => 55, 'after' => 35]], json_decode($row['emotions'], true));
    assert_same(['Ντροπή', 80, 45, true], array_values(array_intersect_key($r->json()['data']['closing'], array_flip(['emotion', 'before', 'after', 'fell']))));
    assert_same(null, $r->json()['support']);
    assert_same(409, tr_store($user, $data['token'], [], 'yes')->status, 'το ίδιο token δεύτερη φορά');
    assert_same(409, tr_store(make_user(), tr_token($user, TR_CONFIDENT)['token'], [], 'yes')->status, 'token άλλου χρήστη');

    $row = tr_saved(tr_store($user, tr_token($user, TR_CONFIDENT)['token'], [], 'not_now'));
    assert_same(['not_now', 'Στοιχεία δεν έχω.', null], tr_pick($row, ['response', 'reframe', 'balanced_thought']), '«Όχι τώρα»: γενική ερώτηση, χωρίς «πιο δίκαια»');
    $row = tr_saved(tr_store($user, tr_token($user, TR_CONFIDENT)['token'], [], 'rejected'));
    assert_same(['rejected', 'Ανάγνωση σκέψης', null], tr_pick($row, ['response', 'observed_type', 'balanced_thought']), '«Δεν μου ταιριάζει»');
});

test('τετράδιο: διαφορά ακριβώς 0,39 ή «Όχι» διαστρέβλωσης', function (): void {
    FakeAi::start();
    $user = make_user();
    assert_same('confident', tr_token($user, 'Τα έκανα πάλι θάλασσα. DIST=0.9 TYPEP=Ταμπέλες:0.8,Υπεργενίκευση:0.41')['mode'], 'διαφορά 0,39');
    assert_same('ask', tr_token($user, 'Τα έκανα πάλι θάλασσα. DIST=0.9 TYPEP=Ταμπέλες:0.8,Υπεργενίκευση:0.4101')['mode'], 'διαφορά 0,3899');
    assert_same('offer', tr_token($user, 'Τα έκανα πάλι θάλασσα. DIST=0.2 TYPEP=Ταμπέλες:0.95,Υπεργενίκευση:0.1')['mode'], 'detected Όχι: προσφορά ερωτήσεων, όχι «ρεαλιστική»');
    assert_same('offer', tr_token($user, 'Τα έκανα πάλι θάλασσα. DIST=0.2 TYPEP=Ταμπέλες:0.01,Υπεργενίκευση:0.001')['mode'], 'Όχι, 1ος τύπος ακριβώς 0,01');
});

test('τετράδιο: «Όχι» με όλους τους τύπους κάτω από 0,01, χωρίς ερωτήσεις', function (): void {
    FakeAi::start();
    $user = make_user();
    $quiet = 'Ο σκύλος μου είναι άρρωστος. DIST=0.02 TYPEP=Ταμπέλες:0.0099,Υπεργενίκευση:0.001';
    $data = tr_token($user, $quiet);
    assert_same(['none', false], [$data['mode'], array_key_exists('questions', $data)], 'κατευθείαν «Δεν ξεχωρίζω»');
    assert_same(422, tr_store($user, $data['token'], ['neutral'], 'yes')->status, 'απαντήσεις χωρίς ερωτήσεις');
    assert_same(422, tr_store($user, $data['token'], [], 'rejected')->status, '«Δεν μου ταιριάζει» χωρίς σημείωση');
    assert_same(['none', null, 'not_now'], tr_pick(tr_saved(tr_store($user, $data['token'], [], 'not_now')), ['observation_source', 'observed_type', 'response']));
    $row = tr_saved(tr_store($user, tr_token($user, $quiet)['token'], [], 'yes'));
    assert_same(['none', null, 'yes'], tr_pick($row, ['observation_source', 'observed_type', 'response']), '«Ναι»: γενική ερώτηση');
});

test('τετράδιο: «Όχι» με 1ο τύπο από 0,01, οι ερωτήσεις μόνο μετά το «Ναι»', function (): void {
    FakeAi::start();
    $user = make_user();
    $offer = 'Τα έκανα πάλι θάλασσα. DIST=0.2 TYPEP=Ταμπέλες:0.6,Υπεργενίκευση:0.5,Πρέπει:0.1';

    $data = tr_token($user, $offer);
    assert_same(['offer', ['Ταμπέλες', 'Υπεργενίκευση']], [$data['mode'], array_column($data['questions'], 'type')], 'οι ερωτήσεις έρχονται μαζί με την προσφορά');
    foreach (['yes' => 'yes', 'rejected' => 'rejected', 'χωρίς απάντηση' => null] as $label => $response) {
        assert_same(422, tr_store($user, $data['token'], [], $response)->status, "χωρίς απαντήσεις μόνο «Όχι τώρα»: {$label}");
    }
    assert_same(422, tr_store($user, $data['token'], ['neutral'], 'yes')->status, 'μετά το «Ναι» και οι δύο ερωτήσεις, αν δεν βρεθεί παγίδα');
    $row = tr_saved(tr_store($user, $data['token'], [], 'not_now'));
    assert_same(['declined', null, 'not_now', null, null, '0'], tr_pick($row, ['observation_source', 'observed_type', 'response', 'answers', 'reframe', 'model_detected']), '«Όχι τώρα»: κατευθείαν στο κλείσιμο');

    $row = tr_saved(tr_store($user, tr_token($user, $offer)['token'], ['neutral', 'trap'], 'yes'));
    assert_same(['asked', 'Υπεργενίκευση', 'yes', '0'], tr_pick($row, ['observation_source', 'observed_type', 'response', 'model_detected']), '«Ναι» και παγίδα: asked με detected Όχι');

    $row = tr_saved(tr_store($user, tr_token($user, $offer)['token'], ['unknown', 'neutral'], 'yes'));
    assert_same(['none', null, 'yes', 'Στοιχεία δεν έχω.', null], tr_pick($row, ['observation_source', 'observed_type', 'response', 'reframe', 'balanced_thought']), '«Ναι» χωρίς παγίδα: ερώτηση του φίλου');
});

test('τετράδιο: ερωτήσεις στο περιθώριο, ανακάτεμα από τον server, σταματά στο πρώτο trap', function (): void {
    FakeAi::start();
    $user = make_user();

    $data = tr_token($user, TR_ASK);
    assert_same('ask', $data['mode']);
    assert_same(['Ταμπέλες', 'Υπεργενίκευση'], array_column($data['questions'], 'type'), 'οι 2 πρώτοι, με τη σειρά');
    assert_same('Μιλάς για αυτή τη μία φορά, ή για το πώς πάνε πάντα τα πράγματα;', $data['questions'][1]['ask']);
    foreach ($data['questions'] as $question) {
        $answers = array_column($question['options'], 'answer');
        sort($answers);
        assert_same(['neutral', 'trap'], $answers, 'μία ουδέτερη και μία παγίδα');
    }

    foreach ([
        'καμία' => [], 'μετά το trap' => ['trap', 'neutral'], 'μία χωρίς trap' => ['neutral'],
        'τρεις' => ['neutral', 'neutral', 'neutral'], 'άγνωστη' => ['maybe'], 'όχι λίστα' => ['a' => 'trap'],
    ] as $label => $answers) {
        assert_same(422, tr_store($user, $data['token'], $answers, 'yes')->status, $label);
    }
    assert_same(422, tr_store($user, $data['token'], [], 'not_now')->status, 'όταν το μοντέλο λέει «Ναι», οι ερωτήσεις δεν παραλείπονται');

    $row = tr_saved(tr_store($user, $data['token'], ['trap'], 'yes'));
    $position = array_search('trap', array_column($data['questions'][0]['options'], 'answer'), true) + 1;
    assert_same(['asked', 'Ταμπέλες', 'yes'], tr_pick($row, ['observation_source', 'observed_type', 'response']));
    assert_same([['type' => 'Ταμπέλες', 'answer' => 'trap', 'trap_position' => $position]], json_decode($row['answers'], true), 'θέση από τον server');

    $row = tr_saved(tr_store($user, tr_token($user, TR_ASK)['token'], ['neutral', 'trap'], 'not_now'));
    assert_same(['asked', 'Υπεργενίκευση', 'not_now', null], tr_pick($row, ['observation_source', 'observed_type', 'response', 'balanced_thought']));
    assert_same(['neutral', 'trap'], array_column(json_decode($row['answers'], true), 'answer'));

    $token = tr_token($user, TR_ASK)['token'];
    assert_same(422, tr_store($user, $token, ['unknown', 'neutral'], 'rejected')->status, 'χωρίς παγίδα δεν υπάρχει «Δεν μου ταιριάζει»');
    $row = tr_saved(tr_store($user, $token, ['unknown', 'neutral'], 'yes'));
    assert_same(['none', null, 'yes', 'Στοιχεία δεν έχω.', null], tr_pick($row, ['observation_source', 'observed_type', 'response', 'reframe', 'balanced_thought']));

    $row = tr_saved(tr_store($user, tr_token($user, TR_ASK)['token'], ['neutral', 'neutral'], 'not_now'));
    assert_same(['none', 'not_now', null], tr_pick($row, ['observation_source', 'response', 'reframe']), '«Όχι τώρα»: κατευθείαν στο κλείσιμο');
});

test('τετράδιο: έλεγχος των πεδίων', function (): void {
    FakeAi::start();
    $user = make_user();
    $thought = 'Θα τα κάνω θάλασσα αύριο.';

    assert_same(422, tr_suggest($user, 'θα αποτύχω')->status, 'σκέψη με 2 λέξεις');
    assert_same(422, tr_suggest($user, str_repeat('λέξη ', 61))->status, 'σκέψη > 300');
    assert_same(422, tr_suggest($user, $thought, ['thought_mood' => str_repeat('λ', 301)])->status, 'δεύτερη > 300');
    assert_same(422, tr_suggest($user, $thought, ['thought_mood' => ['x']])->status, 'δεύτερη πίνακας');
    assert_same(422, tr_suggest($user, $thought, ['event' => '  '])->status, 'κενό γεγονός');
    assert_same(422, tr_suggest($user, $thought, ['event' => str_repeat('α', 1001)])->status, 'γεγονός > 1000');
    foreach ([
        'κανένα' => [],
        'από τα 12 αλλά όχι από τα 7' => [['emotion' => 'Χαρά', 'before' => 50]],
        'τέσσερα' => [['emotion' => 'Άγχος', 'before' => 1], ['emotion' => 'Λύπη', 'before' => 1], ['emotion' => 'Θυμός', 'before' => 1], ['emotion' => 'Φόβος', 'before' => 1]],
        'διπλό' => [['emotion' => 'Άγχος', 'before' => 50], ['emotion' => 'Άγχος', 'before' => 60]],
        'ένταση 101' => [['emotion' => 'Άγχος', 'before' => 101]],
        'ένταση string' => [['emotion' => 'Άγχος', 'before' => '50']],
    ] as $label => $emotions) {
        assert_same(422, tr_suggest($user, $thought, ['emotions' => $emotions])->status, "συναισθήματα: {$label}");
    }
    assert_same(200, tr_suggest($user, $thought, ['thought_mood' => ''])->status, 'η δεύτερη είναι προαιρετική');
    assert_same(409, tr_store($user, 'nope', [], 'yes')->status, 'άγνωστο token');
    assert_same(401, (new Http())->api('POST', 'api/thought-records/suggest', ['event' => 'x'], false)->status, 'χωρίς σύνδεση');
    assert_same(0, (int) db()->query("SELECT COUNT(*) FROM thought_records WHERE user_id = {$user->id}")->fetchColumn(), 'τίποτα αποθηκευμένο');
});

test('τετράδιο: κρίση πριν από το περιθώριο, σε γεγονός ή σε μία από τις δύο απαντήσεις', function (): void {
    FakeAi::start();
    $user = make_user();

    foreach ([
        'λέξεις στο γεγονός' => [TR_CONFIDENT, ['event' => 'Σκέφτομαι να πεθάνω.']],
        'κίνδυνος στο γεγονός' => [TR_CONFIDENT, ['event' => 'Ήρθαν όλα μαζί. RISK=0.4']],
        'λέξεις στη δεύτερη απάντηση' => ['Τα έκανα θάλασσα πάλι.', ['thought_mood' => 'Καλύτερα να μην υπήρχα καθόλου.']],
        'κίνδυνος στη σκέψη' => ['Δεν έχει νόημα τίποτα. RISK=0.35 DIST=0.9 TYPEP=Ταμπέλες:0.95', []],
    ] as $label => [$thought, $extra]) {
        $r = tr_suggest($user, $thought, $extra);
        assert_same(201, $r->status, "{$label}: {$r->body}");
        assert_same([true, 'crisis', false], [$r->json()['data']['crisis'], $r->json()['support']['level'], isset($r->json()['data']['token'])], $label);
        assert_same(['crisis', null, null, null, '1', 'others'], tr_pick(tr_saved($r), ['observation_source', 'observed_type', 'response', 'answers', 'crisis', 'thought_mood_key']), $label);
    }

    $r = tr_suggest($user, TR_CONFIDENT . ' RISK=0.29');
    assert_same([200, false], [$r->status, $r->json()['data']['crisis']], 'κίνδυνος κάτω από 0,3');
});

test('τετράδιο: κρίση μετά το περιθώριο κρατά τη σημείωση, πάνω από 80 και στις δύο δίνει 10306', function (): void {
    FakeAi::start();
    $user = make_user();

    $r = tr_store($user, tr_token($user, TR_CONFIDENT)['token'], [], 'yes', ['balanced_thought' => 'Δεν αντέχω άλλο να ζω έτσι.']);
    assert_same(['crisis', true], [$r->json()['support']['level'], $r->json()['data']['crisis']]);
    assert_same(['confident', 'Ανάγνωση σκέψης', 'yes', '1'], tr_pick(tr_saved($r), ['observation_source', 'observed_type', 'response', 'crisis']));

    $r = tr_store($user, tr_token($user, TR_ASK)['token'], ['neutral', 'neutral'], 'yes', ['reframe' => 'Θέλω να πεθάνω.']);
    assert_same(['none', '1'], tr_pick(tr_saved($r), ['observation_source', 'crisis']), 'κρίση στη γενική ερώτηση');

    $intense = ['emotions' => [['emotion' => 'Λύπη', 'before' => 90]]];
    $r = tr_store($user, tr_token($user, TR_CONFIDENT, $intense)['token'], [], 'yes', ['after' => ['Λύπη' => 85]]);
    assert_same(['low_mood', ['10306']], [$r->json()['support']['level'], array_column($r->json()['support']['helplines'], 'number')]);
    $r = tr_store($user, tr_token($user, TR_CONFIDENT, $intense)['token'], [], 'yes', ['after' => ['Λύπη' => 60]]);
    assert_same([null, true], [$r->json()['support'], $r->json()['data']['closing']['fell']], 'έπεσε κάτω από 80');
});

test('τετράδιο: με κλειστό AI ai_down, εκτός αν οι λέξεις-κλειδιά βρουν κίνδυνο', function (): void {
    FakeAi::stop();
    $user = make_user();

    $data = tr_token($user, 'Θα τα κάνω θάλασσα αύριο.');
    assert_same(['ai_down', 'Τι θα έλεγα σε έναν φίλο που περνάει το ίδιο;'], [$data['mode'], $data['friend']]);
    assert_same(422, tr_store($user, $data['token'], [], 'yes')->status, 'χωρίς σημείωση δεν υπάρχει απάντηση');
    $row = tr_saved(tr_store($user, $data['token'], [], null));
    assert_same(['ai_down', null, null, null, null, null, 'Στοιχεία δεν έχω.', null], tr_pick($row, [
        'observation_source', 'observed_type', 'response', 'model_detected', 'model_ranking', 'model_version', 'reframe', 'balanced_thought',
    ]));

    $r = tr_suggest($user, 'Θέλω να πεθάνω, δεν το αντέχω.');
    assert_same([201, 'crisis'], [$r->status, tr_saved($r)['observation_source']], 'κρίση και ai_down: κρίση');
});

test('τετράδιο: πρώτη σελίδα, νέα σελίδα, «Οι σελίδες σου» και ανάγνωση', function (): void {
    FakeAi::start();
    $user = make_user();

    $intro = $user->http->get('thoughts');
    assert_true(str_contains($intro->body, 'Οι έντονες στιγμές') && str_contains($intro->body, 'Άνοιξε το τετράδιο'), 'πρώτη σελίδα χωρίς records');
    $notebook = $user->http->get('thoughts/new')->body;
    assert_same(7, substr_count($notebook, 'class="feeling"'), 'τα 7 δύσκολα συναισθήματα');
    assert_true(!str_contains($notebook, 'χαρά') && !str_contains($notebook, 'Βήμα'), 'χωρίς χαρά και χωρίς βήματα');
    assert_true(str_contains($user->http->get('dashboard')->body, '>Σκέψεις</a>'), 'στο μενού');

    $first = tr_saved(tr_store($user, tr_token($user, TR_CONFIDENT)['token'], [], 'yes'));
    $crisis = tr_saved(tr_suggest($user, 'Πρώτα σκέφτηκα ότι θέλω να πεθάνω.'));

    assert_true(str_contains($user->http->get('thoughts')->body, 'id="notebook"'), 'με records: νέα σελίδα');
    assert_true(str_contains($user->http->get('thoughts/about')->body, 'Άνοιξε το τετράδιο'), '«Τι είναι αυτό;»');

    $pages = $user->http->get('thoughts/pages')->body;
    $newer = strpos($pages, 'thoughts/' . $crisis['id'] . '"');
    $older = strpos($pages, 'thoughts/' . $first['id'] . '"');
    assert_true($newer !== false && $older !== false && $newer < $older, 'η νεότερη πρώτη, μαζί με τη σελίδα κρίσης');

    $read = $user->http->get('thoughts/' . $first['id'])->body;
    foreach (['«Διαβάζω το μυαλό των άλλων»', 'τι κάνει αυτό στην ντροπή σου;', 'Στοιχεία δεν έχω.', 'Ένα λάθος δεν λέει ποιος είμαι.', 'Ξεκίνησες με ντροπή'] as $text) {
        assert_true(str_contains(html_entity_decode($read), $text), "ανάγνωση: {$text}");
    }
    assert_true(!str_contains($read, '<textarea'), 'μόνο για ανάγνωση');
    assert_true(str_contains($user->http->get('thoughts/' . $crisis['id'])->body, 'Πρώτα σκέφτηκα'), 'σελίδα κρίσης');
    assert_same(404, make_user()->http->get('thoughts/' . $first['id'])->status, 'σελίδα άλλου χρήστη');
    assert_same(404, $user->http->get('thoughts/abc')->status, 'όχι αριθμός');
});

test('τετράδιο: τα μοτίβα από 5 σελίδες χωρίς κρίση', function (): void {
    $user = make_user();
    $model = new ThoughtRecord();
    $add = function (array $page) use ($model, $user): void {
        $model->create($user->id, $page + ['event' => 'Κάτι έγινε.', 'thought' => 'Μια σκέψη μου.', 'thought_mood_key' => 'others']);
    };
    $feel = fn (array ...$list): array => array_map(static fn (array $e): array => ['emotion' => $e[0], 'before' => $e[1], 'after' => $e[2]], $list);

    $add(['observation_source' => 'asked', 'observed_type' => 'Ανάγνωση σκέψης', 'response' => 'yes', 'emotions' => $feel(['Ντροπή', 80, 40], ['Άγχος', 60, 50])]);
    $add(['observation_source' => 'confident', 'observed_type' => 'Ανάγνωση σκέψης', 'response' => 'yes', 'reframe' => 'Δεν ξέρω τι σκέφτονται.', 'emotions' => $feel(['Ντροπή', 70, 50])]);
    $add(['observation_source' => 'confident', 'observed_type' => 'Ταμπέλες', 'response' => 'yes', 'emotions' => $feel(['Λύπη', 60, 60])]);
    $add(['observation_source' => 'asked', 'observed_type' => 'Ταμπέλες', 'response' => 'not_now', 'emotions' => $feel(['Άγχος', 50, 30], ['Λύπη', 50, 10])]);
    $add(['observation_source' => 'crisis', 'crisis' => true, 'emotions' => [['emotion' => 'Λύπη', 'before' => 100]]]);

    assert_true(!str_contains($user->http->get('thoughts')->body, 'Τα μοτίβα σου'), '4 σελίδες χωρίς κρίση δεν φτάνουν');

    $add(['observation_source' => 'none', 'response' => 'yes', 'reframe' => 'Θα του έλεγα να ηρεμήσει.', 'emotions' => $feel(['Θυμός', 40, 20])]);
    $body = html_entity_decode($user->http->get('thoughts')->body);
    assert_true(str_contains($body, 'Τα μοτίβα σου'), 'με 5 σελίδες');
    assert_true(str_contains($body, 'Από τις 5 σελίδες σου, στις 2 εμφανίστηκε το «Διαβάζω το μυαλό των άλλων».'), 'παγίδα με 2 σελίδες');
    assert_true(str_contains($body, 'Συνήθως όταν νιώθεις ντροπή ή άγχος.'), 'συναισθήματα της παγίδας');
    assert_true(!str_contains($body, '«Ταμπέλες»'), 'confident χωρίς εμβάθυνση και «Όχι τώρα» δεν μετράνε');
    assert_true(str_contains($body, 'η ένταση ήταν κατά μέσο όρο από 60 σε 40.'), 'ένα ζευγάρι ανά σελίδα, ισοπαλία: το πρώτο');
});

test('τετράδιο: εξαγωγή και διαγραφή, χωρίς συγκατάθεση για training', function (): void {
    FakeAi::start();
    $user = make_user();

    assert_same(false, array_key_exists('training_consent', $user->http->api('GET', 'api/profile')->json()['data']), 'όχι στο API');
    assert_true(!str_contains($user->http->get('profile')->body, 'training-consent'), 'όχι στο προφίλ');

    tr_saved(tr_store($user, tr_token($user, TR_ASK)['token'], ['trap'], 'yes'));
    $export = $user->http->get('account/export')->json();
    assert_same(false, array_key_exists('training_consent', $export['profile']), 'όχι στην εξαγωγή');
    assert_same(['Ταμπέλες'], array_column($export['thought_records'], 'observed_type'));
    assert_same('trap', $export['thought_records'][0]['answers'][0]['answer'], 'answers ως JSON');
    assert_same(80, $export['thought_records'][0]['emotions'][0]['before'], 'emotions ως JSON');

    db()->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $user->id]);
    assert_same(0, (int) db()->query("SELECT COUNT(*) FROM thought_records WHERE user_id = {$user->id}")->fetchColumn(), 'σβήνονται με τον χρήστη');
});
