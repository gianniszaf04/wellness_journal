<?php
use App\Services\ThinkingTraps;

$circle = 'M10 23 C 8 9, 90 5, 95 20 C 99 35, 22 41, 8 28 C 3 22, 22 11, 44 8';
?>
<?php partial('support'); ?>

<?php if ($patterns !== null): ?>
    <section class="card patterns" aria-labelledby="patterns-title">
        <h2 class="card__title" id="patterns-title">Τα μοτίβα σου</h2>
        <?php foreach ($patterns['traps'] as $trap): ?>
            <p>Από τις <?= (int) $patterns['pages'] ?> σελίδες σου, στις <?= (int) $trap['pages'] ?> εμφανίστηκε το <?= e($trap['quoted']) ?>.
                <?php if ($trap['emotions'] !== []): ?>
                    Συνήθως όταν νιώθεις <?= e(implode(' ή ', $trap['emotions'])) ?>.
                <?php endif; ?>
            </p>
        <?php endforeach; ?>
        <?php if ($patterns['intensity'] !== null): ?>
            <p>Μετά από μια σελίδα, η ένταση ήταν κατά μέσο όρο από <?= (int) $patterns['intensity']['before'] ?> σε <?= (int) $patterns['intensity']['after'] ?>.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="notebook-wrap">
<?php partial('thought-nav'); ?>

<article class="notebook" id="notebook" aria-label="Σελίδα τετραδίου"
    data-max-emotions="<?= (int) $limits['emotions'] ?>"
    data-min-words="<?= (int) $limits['words'] ?>">
    <span class="tape tape--left tape--sage" aria-hidden="true"></span>

    <section class="notebook__page" aria-labelledby="nb-moment-title">
        <header class="notebook__head">
            <p class="notebook__eyebrow" id="nb-date"><?= e($today) ?></p>
            <h2 class="notebook__title" id="nb-moment-title">Η στιγμή</h2>
        </header>

        <div class="notebook__field" data-part="event">
            <label class="notebook__q" for="nb-event">Τι έγινε;</label>
            <textarea class="notebook__lines" id="nb-event" rows="4" maxlength="<?= (int) $limits['event'] ?>" placeholder="Πού ήσουν, με ποιον, τι έγινε;"></textarea>
        </div>

        <fieldset class="notebook__field" data-part="emotions">
            <legend class="notebook__q">Τι ένιωσα;</legend>
            <div class="feelings">
                <?php foreach (ThinkingTraps::EMOTIONS as $name => $emotion): ?>
                    <button class="feeling" type="button" aria-pressed="false"
                        data-emotion="<?= e($name) ?>"
                        data-key="<?= e($emotion['key']) ?>"
                        data-with="<?= e($emotion['with']) ?>"
                        data-the="<?= e($emotion['the']) ?>"><?= e(mb_strtolower($name)) ?><svg class="feeling__circle" viewBox="0 0 100 44" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="<?= $circle ?>" pathLength="1"/></svg></button>
                <?php endforeach; ?>
            </div>
            <p class="notebook__hint">Έως τρία. Πόσο έντονα, από 0 έως 100;</p>
            <div class="levels" id="nb-before"></div>
        </fieldset>
    </section>

    <section class="notebook__page notebook__page--right" aria-labelledby="nb-thought-title">
        <header class="notebook__head">
            <p class="notebook__eyebrow">Τι είπα στον εαυτό μου</p>
            <h2 class="notebook__title" id="nb-thought-title">Η σκέψη</h2>
        </header>

        <div class="notebook__row">
            <div class="notebook__main">
                <div class="notebook__field is-future" data-part="thought">
                    <label class="notebook__q" for="nb-thought"><?= e(ThinkingTraps::FIRST_QUESTION) ?></label>
                    <textarea class="notebook__lines" id="nb-thought" rows="2" maxlength="<?= (int) $limits['thought'] ?>"></textarea>
                    <p class="notebook__hint">Γράψ' το όπως το είπες στον εαυτό σου. Π.χ. όχι «ένιωσα άγχος», αλλά «θα τα κάνω θάλασσα».</p>
                    <div class="notebook__dashes" aria-hidden="true"><i></i><i></i></div>
                </div>

                <div class="notebook__field is-future" data-part="mood" id="nb-mood-field"
                    <?php foreach (ThinkingTraps::MOOD_QUESTIONS as $key => $question): ?>
                        data-q-<?= e($key) ?>="<?= e($question) ?>"
                    <?php endforeach; ?>>
                    <label class="notebook__q" for="nb-mood" id="nb-mood-label">Μια δεύτερη ερώτηση, ανάλογα με το πιο έντονο συναίσθημα.</label>
                    <p class="notebook__hint" id="nb-mood-why"></p>
                    <textarea class="notebook__lines" id="nb-mood" rows="2" maxlength="<?= (int) $limits['thought'] ?>"></textarea>
                    <div class="notebook__dashes" aria-hidden="true"><i></i><i></i></div>
                </div>

                <div class="notebook__actions" id="nb-suggest-row" hidden>
                    <button class="btn" type="button" id="nb-suggest">Αυτή ήταν η σκέψη</button>
                </div>
            </div>

            <aside class="margin sticker" id="nb-margin" aria-label="Σημείωση στο περιθώριο" aria-live="polite" hidden></aside>
        </div>

        <div class="notebook__row notebook__row--reverse" id="nb-deep" hidden>
            <aside class="margin sticker" aria-label="Ερώτηση στο περιθώριο">
                <p class="margin__text" id="nb-feel"></p>
                <p class="margin__text" id="nb-trap-question"></p>
                <button class="margin__link" type="button" id="nb-reject">Δεν μου ταιριάζει</button>
            </aside>
            <div class="notebook__main">
                <textarea class="notebook__lines" id="nb-deep-answer" rows="5" maxlength="<?= (int) $limits['reframe'] ?>" aria-labelledby="nb-feel nb-trap-question"></textarea>
            </div>
        </div>

        <div class="notebook__field" data-part="friend" id="nb-friend-field" hidden>
            <label class="notebook__q" for="nb-friend"><?= e(ThinkingTraps::FRIEND_QUESTION) ?></label>
            <textarea class="notebook__lines" id="nb-friend" rows="3" maxlength="<?= (int) $limits['reframe'] ?>"></textarea>
        </div>

        <div class="notebook__field is-future" data-part="fair" id="nb-fair-field">
            <label class="notebook__q" for="nb-fair"><?= e(ThinkingTraps::FAIR_QUESTION) ?></label>
            <textarea class="notebook__lines" id="nb-fair" rows="3" maxlength="<?= (int) $limits['balanced'] ?>"></textarea>
            <div class="notebook__dashes" aria-hidden="true"><i></i><i></i></div>
        </div>

        <fieldset class="notebook__field is-future" data-part="after" id="nb-after-field">
            <legend class="notebook__q">Πόσο έντονα νιώθω τώρα;</legend>
            <div class="levels" id="nb-after"></div>
            <div class="notebook__dashes" aria-hidden="true"><i></i></div>
        </fieldset>

        <div class="notebook__close" id="nb-close" hidden>
            <p class="margin__text notebook__closing" id="nb-closing" aria-live="polite"></p>
            <div class="notebook__actions">
                <button class="btn" type="button" id="nb-save">Κλείσε τη σελίδα</button>
                <span class="notebook__hint">Θα μπει με ημερομηνία στις σελίδες σου.</span>
            </div>
        </div>

        <div class="notebook__closed" id="nb-closed" tabindex="-1" hidden>
            <p class="notebook__eyebrow" id="nb-closed-date"></p>
            <p class="margin__text" id="nb-closed-text"></p>
            <div class="notebook__actions">
                <a class="btn btn--quiet" href="<?= e(url('thoughts/pages')) ?>">Οι σελίδες σου</a>
                <a class="btn" href="<?= e(url('thoughts/new')) ?>">Νέα σελίδα</a>
            </div>
        </div>

        <p class="page__error" id="nb-error" role="alert" hidden></p>
    </section>
</article>
</div>

<?php partial('thought-helplines'); ?>
