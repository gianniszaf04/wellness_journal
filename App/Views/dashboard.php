<div class="greet">
    <div>
        <p class="greet__date"><?= e($today) ?></p>
        <h1><?= e($greeting) ?><?php if ($firstName !== ''): ?>, <span class="mark" style="--hl: var(--peach)"><?= e($firstName) ?></span><?php endif; ?></h1>
    </div>
    <a class="btn" href="<?= e(url('entry/editor')) ?>">Γράψε τη σημερινή σελίδα</a>
</div>

<div class="note">
    <span class="tape tape--sky" aria-hidden="true"></span>
    <div class="note__label">μήνυμα της ημέρας</div>
    <p class="note__text"><?= e($quote) ?></p>
</div>

<div class="dash-grid">
    <section class="card" aria-labelledby="month-title">
        <span class="tape tape--left tape--sage" aria-hidden="true"></span>
        <h2 class="card__title" id="month-title">Ο <?= e($monthName) ?> σου <small>πώς ένιωσες κάθε μέρα</small></h2>
        <div class="cal">
            <?php foreach (['Δε', 'Τρ', 'Τε', 'Πε', 'Πα', 'Σα', 'Κυ'] as $head): ?>
                <div class="cal__head"><?= $head ?></div>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $leadingDays; $i++): ?>
                <div aria-hidden="true"></div>
            <?php endfor; ?>
            <?php foreach ($calendar as $cell): ?>
                <?php
                $classes = ['cal__day'];
                if ($cell['future']) {
                    $classes[] = 'cal__day--future';
                } elseif ($cell['mood']) {
                    $classes[] = 'cal__day--mood';
                } else {
                    $classes[] = 'cal__day--empty';
                }
                if ($cell['today']) {
                    $classes[] = 'cal__day--today';
                }
                ?>
                <div class="<?= implode(' ', $classes) ?>"<?= $cell['mood'] ? ' style="--mood: var(--mood-' . $cell['mood'] . ')"' : '' ?> title="<?= e($cell['title']) ?>"><?= $cell['day'] ?></div>
            <?php endforeach; ?>
        </div>
        <div class="legend">
            <?php for ($m = 5; $m >= 1; $m--): ?>
                <span><i style="background: var(--mood-<?= $m ?>)"></i><?= e(mood_label($m)) ?></span>
            <?php endfor; ?>
        </div>
        <?php if ($streak > 0): ?>
            <p class="streak"><strong><?= (int) $streak ?></strong> <?= $streak === 1 ? 'μέρα' : 'μέρες' ?> στη σειρά που γράφεις</p>
        <?php else: ?>
            <p class="streak muted">Γράψε σήμερα και ξεκίνα το σερί σου.</p>
        <?php endif; ?>
    </section>

    <div class="side">
        <section class="card" aria-labelledby="week-title">
            <span class="tape tape--right tape--rose" aria-hidden="true"></span>
            <h2 class="card__title" id="week-title">Η εβδομάδα σου</h2>
            <div class="stats">
                <div class="stat"><b><?= (int) $total ?></b><small><?= $total === 1 ? 'σελίδα' : 'σελίδες' ?></small></div>
                <div class="stat"><b><?= e($weekAverage ?? '–') ?></b><small>μέση διάθεση</small></div>
                <div class="stat"><b><?= e($topEmotion ?? '–') ?></b><small>κυρίαρχο</small></div>
            </div>
            <div class="week">
                <?php foreach ($week as $day): ?>
                    <div title="<?= e($day['title']) ?>">
                        <i class="<?= $day['mood'] ? '' : 'empty' ?>"<?= $day['mood'] ? ' style="background: var(--mood-' . $day['mood'] . ')"' : '' ?>></i>
                        <span><?= e($day['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card" aria-labelledby="insights-title">
            <h2 class="card__title" id="insights-title">Τι παρατήρησα</h2>
            <?php if ($insights): ?>
                <?php foreach ($insights as $i => $insight): ?>
                    <div class="insight">
                        <svg style="color: var(<?= $i % 2 ? '--rose' : '--mustard' ?>)" aria-hidden="true"><use href="#spark"/></svg>
                        <p><?= e((string) $insight['insight_text']) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="insight">
                    <svg style="color: var(--mustard)" aria-hidden="true"><use href="#spark"/></svg>
                    <p class="muted">Γράψε μερικές μέρες και θα σου πω τι παρατηρώ στις σελίδες σου.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="card" aria-labelledby="recent-title">
    <span class="tape tape--left tape--mustard" aria-hidden="true"></span>
    <h2 class="card__title" id="recent-title">Πρόσφατες σελίδες <small><a href="<?= e(url('entry/history')) ?>">όλο το ιστορικό</a></small></h2>
    <?php if ($recent): ?>
        <?php foreach ($recent as $entry): ?>
            <?php $mood = mood_level($entry['sentiment_score']); ?>
            <a class="entry" href="<?= e(url('entry/' . $entry['id'])) ?>">
                <i<?= $mood ? ' style="--mood: var(--mood-' . $mood . ')"' : '' ?>></i>
                <div style="min-width: 0">
                    <b><?= e(entry_when((string) $entry['created_at'])) ?></b>
                    <em><?= e(mb_substr((string) $entry['body'], 0, 140)) ?></em>
                </div>
                <?php if (!empty($entry['emotion_name'])): ?>
                    <span class="chip"><?= e(mb_strtolower((string) $entry['emotion_name'])) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="muted">Δεν έχεις γράψει ακόμα. <a href="<?= e(url('entry/editor')) ?>">Ξεκίνα την πρώτη σου σελίδα.</a></p>
    <?php endif; ?>
</section>
