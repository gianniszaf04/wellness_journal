<?php
declare(strict_types=1);

use App\Services\CrisisSupport;

// level του support, ή null
function support_level(array $json): ?string
{
    return $json['support']['level'] ?? null;
}

test('CrisisSupport::mentionsSelfHarm πιάνει φράσεις κινδύνου', function (): void {
    foreach ([
        'Θέλω να πεθάνω.',
        'ΘΕΛΩ ΝΑ ΠΕΘΑΝΩ',
        'σκέφτομαι την αυτοκτονία',
        'Δεν θέλω   άλλο να ζω',
        'δε μπορώ πια να ζω έτσι',
        'η ζωή μου δεν έχει κανένα νόημα',
        'θα ήταν καλύτερα χωρίς εμένα',
        'θέλω να κάνω κακό στον εαυτό μου',
        'μακάρι να μην ξυπνήσω αύριο',
        'thelo na pethano',
        'skeftomai tin aytoktonia',
    ] as $text) {
        assert_same(true, CrisisSupport::mentionsSelfHarm($text), $text);
    }
});

test('CrisisSupport::mentionsSelfHarm αγνοεί καθημερινές εκφράσεις', function (): void {
    foreach ([
        'Πέθανα στα γέλια με την ταινία.',
        'Δεν μπορώ να ζω χωρίς καφέ το πρωί.',
        'Θέλω να τα τελειώσω όλα πριν τις 5.',
        'Η μέρα ήταν κουραστική αλλά καλή.',
    ] as $text) {
        assert_same(false, CrisisSupport::mentionsSelfHarm($text), $text);
    }
});

test('CrisisSupport::forEntry: κρίση, χαμηλή διάθεση ή τίποτα', function (): void {
    $level = fn (?array $s): ?string => $s['level'] ?? null;

    assert_same('crisis', $level(CrisisSupport::forEntry('θέλω να πεθάνω', null, 0.2, -0.5)), 'λέξεις-κλειδιά');
    assert_same('crisis', $level(CrisisSupport::forEntry('ουδέτερο κείμενο', 0.3, 0.2, -0.5)), 'risk στο όριο');
    assert_same(null, $level(CrisisSupport::forEntry('ουδέτερο κείμενο', 0.29, 0.2, -0.5)), 'risk κάτω από το όριο');
    assert_same('low_mood', $level(CrisisSupport::forEntry('δύσκολη μέρα', null, -0.8, -0.5)), 'χαμηλό sentiment');
    assert_same(null, $level(CrisisSupport::forEntry('δύσκολη μέρα', null, null, -0.5)), 'χωρίς AI');

    $numbers = array_column(CrisisSupport::crisis()['helplines'], 'number');
    assert_same(['1018', '10306', '112', '116111'], $numbers, 'γραμμές κρίσης');
    assert_same(['10306'], array_column(CrisisSupport::lowMood()['helplines'], 'number'), 'γραμμές χαμηλής διάθεσης');
});

test('νέα καταγραφή επιστρέφει support και όχι motivational σε κρίση', function (): void {
    FakeAi::start();
    $user = make_user();
    $post = fn (string $body): array => $user->http->api('POST', 'api/entries', ['body' => $body])->json();

    $crisis = $post('SCORE=-0.9 EMO=Λύπη Δεν θέλω άλλο να ζω');
    assert_same('crisis', support_level($crisis), 'λέξεις-κλειδιά');
    assert_same(null, $crisis['message'], 'χωρίς motivational μήνυμα');

    assert_same('crisis', support_level($post('SCORE=-0.2 RISK=0.6 κείμενο')), 'risk head');
    assert_same('low_mood', support_level($post('SCORE=-0.9 EMO=Λύπη κακή μέρα')), 'χαμηλή διάθεση');
    assert_same(null, support_level($post('SCORE=0.5 RISK=0.1 καλή μέρα')), 'τίποτα');

    FakeAi::stop();
    $down = $post('θέλω να πεθάνω');
    assert_same([false, 'crisis'], [$down['ai_available'], support_level($down)], 'AI κάτω');
});

test('επεξεργασία καταγραφής επιστρέφει support', function (): void {
    FakeAi::start();
    $user = make_user();
    $id = $user->http->api('POST', 'api/entries', ['body' => 'SCORE=0.5 καλή μέρα'])->json()['data']['id'];

    $edit = $user->http->api('PATCH', "api/entries/{$id}", ['body' => 'SCORE=0 σκέφτομαι την αυτοκτονία'])->json();
    assert_same('crisis', support_level($edit));
});

test('ζωντανή ανάλυση: support, emotions και τύποι διαστρέβλωσης', function (): void {
    FakeAi::start();
    $user = make_user();
    $live = fn (string $text): array => $user->http->api('POST', 'api/sentiment/live', ['text' => $text])->json();

    $short = $live('θέλω να πεθάνω');
    assert_same([null, 'crisis'], [$short['data'], support_level($short)], 'μικρό κείμενο');

    $data = $live('Ένα αρκετά μεγάλο κείμενο EMOS=Λύπη,Άγχος DIST=0.8 TYPES=Καταστροφολογία RISK=0.1');
    assert_same(['Λύπη', 'Άγχος'], $data['data']['emotions']);
    assert_same(['Καταστροφολογία'], $data['data']['distortion_types']);
    assert_same(null, support_level($data), 'χαμηλό risk');

    assert_same('crisis', support_level($live('Ένα αρκετά μεγάλο κείμενο RISK=0.7')), 'υψηλό risk');
});
