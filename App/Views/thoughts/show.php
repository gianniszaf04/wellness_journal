<?php
use App\Models\ThoughtRecord;
use App\Services\ThinkingTraps;

$circle = 'M10 23 C 8 9, 90 5, 95 20 C 99 35, 22 41, 8 28 C 3 22, 22 11, 44 8';
$emotions = $record['emotions'] ?? [];
$strongest = ThinkingTraps::strongest($emotions);
$deep = $note !== null && $record['response'] === ThoughtRecord::RESPONSE_YES;
$closed = isset($strongest['after']);
?>
<div class="notebook-wrap">
<?php partial('thought-nav'); ?>

<article class="notebook notebook--read" aria-label="Σελίδα τετραδίου">
    <span class="tape tape--left tape--sage" aria-hidden="true"></span>

    <section class="notebook__page" aria-labelledby="nb-moment-title">
        <header class="notebook__head">
            <p class="notebook__eyebrow"><?= e(greek_date((string) $record['created_at'])) ?></p>
            <h1 class="notebook__title" id="nb-moment-title">Η στιγμή</h1>
        </header>

        <div class="notebook__field">
            <p class="notebook__q">Τι έγινε;</p>
            <p class="notebook__written"><?= e((string) $record['event']) ?></p>
        </div>

        <div class="notebook__field">
            <p class="notebook__q">Τι ένιωσα;</p>
            <div class="feelings">
                <?php foreach ($emotions as $emotion): ?>
                    <span class="feeling is-chosen"><?= e(mb_strtolower((string) $emotion['emotion'])) ?><svg class="feeling__circle" viewBox="0 0 100 44" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="<?= $circle ?>" pathLength="1"/></svg></span>
                <?php endforeach; ?>
            </div>
            <ul class="levels levels--read">
                <?php foreach ($emotions as $emotion): ?>
                    <li><span><?= e(mb_strtolower((string) $emotion['emotion'])) ?></span>
                        <span><?= (int) $emotion['before'] ?><?= isset($emotion['after']) ? ' → ' . (int) $emotion['after'] : '' ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="notebook__page notebook__page--right" aria-labelledby="nb-thought-title">
        <header class="notebook__head">
            <p class="notebook__eyebrow">Τι είπα στον εαυτό μου</p>
            <h2 class="notebook__title" id="nb-thought-title">Η σκέψη</h2>
        </header>

        <div class="notebook__row">
            <div class="notebook__main">
                <div class="notebook__field">
                    <p class="notebook__q"><?= e(ThinkingTraps::FIRST_QUESTION) ?></p>
                    <p class="notebook__written"><?= e((string) $record['thought']) ?></p>
                </div>
                <?php if ($record['thought_mood'] !== null): ?>
                    <div class="notebook__field">
                        <p class="notebook__q"><?= e(ThinkingTraps::MOOD_QUESTIONS[$record['thought_mood_key']] ?? '') ?></p>
                        <p class="notebook__written"><?= e((string) $record['thought_mood']) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($note !== null): ?>
                <aside class="margin sticker" aria-label="Σημείωση στο περιθώριο">
                    <p class="margin__text"><mark class="notebook-mark"><?= e($note['quoted']) ?></mark>: <?= e(mb_strtolower(mb_substr($note['explanation'], 0, 1)) . mb_substr($note['explanation'], 1)) ?></p>
                </aside>
            <?php endif; ?>
        </div>

        <?php if ($deep): ?>
            <div class="notebook__row notebook__row--reverse">
                <aside class="margin sticker" aria-label="Ερώτηση στο περιθώριο">
                    <p class="margin__text"><?= e($feel) ?></p>
                    <p class="margin__text"><?= e($note['question']) ?></p>
                </aside>
                <div class="notebook__main">
                    <p class="notebook__written"><?= $record['reframe'] !== null ? e((string) $record['reframe']) : '<span class="muted">—</span>' ?></p>
                </div>
            </div>
            <?php if ($record['balanced_thought'] !== null): ?>
                <div class="notebook__field">
                    <p class="notebook__q"><?= e(ThinkingTraps::FAIR_QUESTION) ?></p>
                    <p class="notebook__written"><?= e((string) $record['balanced_thought']) ?></p>
                </div>
            <?php endif; ?>
        <?php elseif ($record['reframe'] !== null): ?>
            <div class="notebook__field">
                <p class="notebook__q"><?= e(ThinkingTraps::FRIEND_QUESTION) ?></p>
                <p class="notebook__written"><?= e((string) $record['reframe']) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($closed): ?>
            <p class="margin__text notebook__closing">Ξεκίνησες με <?= e(ThinkingTraps::EMOTIONS[$strongest['emotion']]['with'] ?? mb_strtolower((string) $strongest['emotion'])) ?>
                στο <?= (int) $strongest['before'] ?> και τώρα είσαι στο <?= (int) $strongest['after'] ?>.</p>
        <?php endif; ?>
    </section>
</article>
</div>

<?php partial('thought-helplines'); ?>
