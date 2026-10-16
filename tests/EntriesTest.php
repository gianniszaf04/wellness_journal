<?php
declare(strict_types=1);

use App\Models\EmotionEntityRelation;

// "όνομα:αναφορές:μέσος όρος:συναίσθημα" για καθε relation του χρηστη
function relations(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT r.entity_name, r.mentioned_count, r.avg_sentiment_score, e.name
        FROM relation_emotion_entity r LEFT JOIN emotions e ON e.id = r.dominant_emotion_id
        WHERE r.user_id = :user_id ORDER BY r.entity_name');
    $stmt->execute([':user_id' => $userId]);
    return array_map(
        static fn (array $r): string => "{$r['entity_name']}:{$r['mentioned_count']}:" . ($r['avg_sentiment_score'] ?? 'NULL') . ':' . ($r['name'] ?? 'NULL'),
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function create_entry(TestUser $user, string $body): array
{
    $r = $user->http->api('POST', 'api/entries', ['body' => $body]);
    assert_same(201, $r->status, "store: {$body}");
    return $r->json();
}

test('relations ξαναϋπολογίζονται σε create, edit και delete', function (): void {
    FakeAi::start();
    $a = make_user();
    $b = make_user();

    $e1 = create_entry($a, 'SCORE=-0.8 EMO=Λύπη ENT=Μαρία')['data']['id'];
    $e2 = create_entry($a, 'SCORE=0.4 EMO=Λύπη ENT=Μαρία,Νίκος')['data']['id'];
    assert_same(['Μαρία:2:-0.2000:Λύπη', 'Νίκος:1:0.4000:Λύπη'], relations($a->id), 'μετά από 2 καταγραφές');

    $e3 = create_entry($a, 'SCORE=-0.9 EMO=Ηρεμία ENT=Νίκος')['data']['id'];
    assert_same(200, $a->http->api('PATCH', "api/entries/{$e2}", ['body' => 'SCORE=0.0 EMO=Ηρεμία ENT=Νίκος'])->status);
    assert_same(['Μαρία:1:-0.8000:Λύπη', 'Νίκος:2:-0.4500:Ηρεμία'], relations($a->id), 'μετά το edit');

    assert_same(200, $a->http->api('DELETE', "api/entries/{$e1}")->status);
    assert_same(['Νίκος:2:-0.4500:Ηρεμία'], relations($a->id), 'μετά το delete');

    create_entry($b, 'SCORE=0.9 EMO=Χαρά ENT=Νίκος');
    assert_same(['Νίκος:1:0.9000:Χαρά'], relations($b->id), 'ο B έχει δικά του');
    assert_same(['Νίκος:2:-0.4500:Ηρεμία'], relations($a->id), 'ο A δεν επηρεάζεται');

    FakeAi::stop();
    $r = $a->http->api('PATCH', "api/entries/{$e3}", ['body' => 'νέο κείμενο χωρίς AI'])->json();
    assert_same([null, null, false], [$r['data']['sentiment_score'], $r['data']['dominant_emotion_id'], $r['ai_available']], 'AI κάτω: σβήνει η παλιά ανάλυση');
    $count = db()->prepare('SELECT COUNT(*) FROM entry_entities WHERE entry_id = :id');
    $count->execute([':id' => $e3]);
    assert_same(0, (int) $count->fetchColumn(), 'AI κάτω: χωρίς entities');
    assert_same(['Νίκος:1:0.0000:Ηρεμία'], relations($a->id), 'AI κάτω: relations χωρίς την παλιά ανάλυση');
});

test('ένα warning την ημέρα, όσες αρνητικές καταγραφές κι αν γίνουν', function (): void {
    $threshold = getenv('SENTIMENT_WARNING_THRESHOLD');
    if (is_numeric($threshold) && (float) $threshold <= -1.0) {
        skip('το SENTIMENT_WARNING_THRESHOLD δεν αφήνει κανένα score από κάτω');
    }
    FakeAi::start();
    $user = make_user();
    create_entry($user, 'SCORE=-1 EMO=Λύπη');
    create_entry($user, 'SCORE=-1 EMO=Λύπη');
    create_entry($user, 'SCORE=-1 EMO=Θυμός');

    $stmt = db()->prepare("SELECT COUNT(*) FROM insights WHERE user_id = :id AND insight_type = 'warning'");
    $stmt->execute([':id' => $user->id]);
    assert_same(1, (int) $stmt->fetchColumn());
});

test('όριο 10.000 χαρακτήρων, ίδιο με το AI service', function (): void {
    FakeAi::start();
    $user = make_user();

    $long = $user->http->api('POST', 'api/entries', ['body' => str_repeat('α', 10001)]);
    assert_same(422, $long->status, '10.001');
    assert_same('Το κείμενο ξεπερνά τους 10000 χαρακτήρες.', $long->json()['error']);

    $max = $user->http->api('POST', 'api/entries', ['body' => str_repeat('α', 10000)]);
    assert_same([201, true], [$max->status, $max->json()['ai_available']], '10.000');

    $entry = $max->json()['data']['id'];
    assert_same(422, $user->http->api('PATCH', "api/entries/{$entry}", ['body' => str_repeat('α', 10001)])->status, 'edit');
});

test('findTrendsInRange και emotionsForEntity βλέπουν μόνο τον χρήστη', function (): void {
    FakeAi::start();
    $a = make_user();
    $b = make_user();
    create_entry($a, 'SCORE=0.0 EMO=Ηρεμία ENT=Νίκος');
    create_entry($b, 'SCORE=0.9 EMO=Χαρά ENT=Νίκος');

    $model = new EmotionEntityRelation();
    $trends = fn (int $id): array => array_map(
        static fn (array $r): array => [$r['entity_name'], (int) $r['total_mentions'], round((float) $r['avg_sentiment'], 4)],
        $model->findTrendsInRange($id, '2000-01-01', '2100-01-01')
    );
    assert_same([['Νίκος', 1, 0.0]], $trends($a->id), 'trends A');
    assert_same([['Νίκος', 1, 0.9]], $trends($b->id), 'trends B');

    $emotions = array_values(array_filter($model->emotionsForEntity($a->id, 'Νίκος'), static fn (array $r): bool => (int) $r['mention_count'] > 0));
    assert_same([['Ηρεμία', 1]], array_map(static fn (array $r): array => [$r['name'], (int) $r['mention_count']], $emotions));
    assert_true(array_key_exists('avg_sentiment', $emotions[0]), 'στήλη avg_sentiment');
});

test('PHP και MySQL συμφωνούν σε ώρα και μέρα', function (): void {
    $row = db()->query('SELECT @@session.time_zone AS tz, CURDATE() AS d')->fetch(PDO::FETCH_ASSOC);
    assert_same((new DateTime())->format('P'), $row['tz'], 'offset');
    assert_same(date('Y-m-d'), $row['d'], 'ημερομηνία');
});
