<div class="greet">
    <div>
        <p class="greet__date"><?= e($todayLabel) ?></p>
        <h1>Πώς ήταν η <span class="mark" style="--hl: var(--sage)">μέρα</span> σου;</h1>
    </div>
</div>

<?php partial('support'); ?>

<div class="editor-grid">
    <form class="card page" id="entry-form" novalidate>
        <span class="tape tape--left tape--sage" aria-hidden="true"></span>
        <div class="page__head">
            <label class="page__date">
                <span>Ημερομηνία</span>
                <input class="input input--sm" type="date" id="entry-date" name="entry_date" value="<?= e($today) ?>" max="<?= e($today) ?>">
            </label>
            <span class="page__draft" id="draft-status" aria-live="polite"></span>
        </div>

        <label class="visually-hidden" for="entry-body">Η σελίδα σου</label>
        <textarea class="page__text" id="entry-body" name="body" maxlength="<?= (int) $maxLength ?>" placeholder="Γράψε ελεύθερα, όπως θα έγραφες σε ένα τετράδιο..." required></textarea>

        <div class="page__foot">
            <span class="page__count" id="char-count">0 / <?= number_format($maxLength, 0, ',', '.') ?></span>
            <div class="page__actions">
                <a class="btn btn--quiet" href="<?= e(url('dashboard')) ?>">Πίσω</a>
                <button class="btn" type="submit" id="save-btn">Αποθήκευση σελίδας</button>
            </div>
        </div>
        <p class="page__error" id="form-error" role="alert" hidden></p>
    </form>

    <div class="side">
        <section class="card live" aria-labelledby="live-title" aria-live="polite">
            <span class="tape tape--right tape--rose" aria-hidden="true"></span>
            <h2 class="card__title" id="live-title">Τι διαβάζω καθώς γράφεις</h2>

            <div class="live__meter" aria-hidden="true">
                <?php for ($m = 1; $m <= 5; $m++): ?>
                    <i data-level="<?= $m ?>" style="--mood: var(--mood-<?= $m ?>)"></i>
                <?php endfor; ?>
            </div>
            <p class="live__mood" id="live-mood">—</p>
            <p class="live__status muted" id="live-status">Ξεκίνα να γράφεις και θα σου πω τι διαβάζω.</p>

            <div class="live__emotions" id="live-emotions" hidden></div>
            <ul class="live__notes" id="live-notes" hidden></ul>
        </section>

        <section class="card" aria-labelledby="prompts-title">
            <h2 class="card__title" id="prompts-title">Ιδέες για να ξεκινήσεις</h2>
            <div class="prompts">
                <?php foreach ($prompts as $prompt): ?>
                    <button class="prompt" type="button" data-prompt="<?= e($prompt) ?>"><?= e($prompt) ?></button>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<section class="card saved" id="saved" hidden aria-labelledby="saved-title">
    <span class="tape tape--mustard" aria-hidden="true"></span>
    <i class="saved__mood" id="saved-mood" aria-hidden="true"></i>
    <h2 class="saved__title" id="saved-title">Η σελίδα σου αποθηκεύτηκε</h2>
    <p class="saved__summary muted" id="saved-summary"></p>
    <p class="saved__message" id="saved-message" hidden></p>
    <div class="saved__actions">
        <a class="btn" id="saved-view" href="<?= e(url('entry/history')) ?>">Δες τη σελίδα</a>
        <button class="btn btn--quiet" type="button" id="saved-again">Γράψε κι άλλη</button>
        <a class="btn btn--quiet" href="<?= e(url('dashboard')) ?>">Πίσω στην αρχική</a>
    </div>
</section>
