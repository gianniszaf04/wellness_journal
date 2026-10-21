<?php
$emotionNames = array_values(array_filter(
    array_map(static fn (array $row): string => (string) $row['name'], $entry['emotions'] ?? []),
    static fn (string $name): bool => $name !== (string) ($entry['emotion_name'] ?? '')
));
$distortions = array_map(static fn (array $row): string => (string) $row['name'], $entry['distortion_types'] ?? []);
$analysed = $entry['sentiment_score'] !== null;
?>
<a class="back-link" href="<?= e(url('entry/history')) ?>">← Πίσω στο ιστορικό</a>

<div class="greet">
    <div>
        <p class="greet__date">γράφτηκε στις <?= e(date('H:i', strtotime((string) $entry['created_at']))) ?><?php if (strtotime((string) $entry['updated_at']) > strtotime((string) $entry['created_at']) + 60): ?> · άλλαξε <?= e(entry_when((string) $entry['updated_at'])) ?><?php endif; ?></p>
        <h1><?= e(greek_date((string) $entry['entry_date'])) ?></h1>
    </div>
</div>

<?php partial('support'); ?>

<div class="editor-grid" id="entry" data-id="<?= (int) $entry['id'] ?>">
    <article class="card page">
        <span class="tape tape--left tape--sage" aria-hidden="true"></span>

        <div class="page__read" id="entry-text"><?= e((string) $entry['body']) ?></div>

        <form class="page__edit" id="edit-form" hidden>
            <label class="visually-hidden" for="edit-body">Επεξεργασία σελίδας</label>
            <textarea class="page__text" id="edit-body" maxlength="10000"><?= e((string) $entry['body']) ?></textarea>
        </form>

        <p class="page__error" id="form-error" role="alert" hidden></p>

        <div class="page__foot">
            <span class="page__count muted" id="edit-status" aria-live="polite"></span>
            <div class="page__actions" id="read-actions">
                <button class="btn btn--quiet btn--danger" type="button" id="delete-btn">Διαγραφή</button>
                <button class="btn" type="button" id="edit-btn">Επεξεργασία</button>
            </div>
            <div class="page__actions" id="edit-actions" hidden>
                <button class="btn btn--quiet" type="button" id="cancel-btn">Ακύρωση</button>
                <button class="btn" type="submit" form="edit-form" id="save-btn">Αποθήκευση αλλαγών</button>
            </div>
        </div>
    </article>

    <div class="side">
        <section class="card feel" aria-labelledby="feel-title">
            <span class="tape tape--right tape--rose" aria-hidden="true"></span>
            <h2 class="card__title" id="feel-title">Πώς ένιωσες</h2>

            <i class="feel__mood" id="feel-mood"<?= $mood ? ' style="--mood: var(--mood-' . $mood . ')"' : '' ?> aria-hidden="true"></i>
            <p class="feel__label" id="feel-label"><?= $mood ? e(mood_label($mood)) : 'Χωρίς ανάλυση' ?></p>

            <div class="live__emotions" id="feel-emotions"<?= empty($entry['emotion_name']) && !$emotionNames ? ' hidden' : '' ?>>
                <?php if (!empty($entry['emotion_name'])): ?>
                    <span class="chip chip--main"><?= e(mb_strtolower((string) $entry['emotion_name'])) ?></span>
                <?php endif; ?>
                <?php foreach ($emotionNames as $name): ?>
                    <span class="chip"><?= e(mb_strtolower($name)) ?></span>
                <?php endforeach; ?>
            </div>

            <ul class="live__notes" id="feel-notes"<?= !$entry['distortion_detected'] && !$entry['irony_detected'] ? ' hidden' : '' ?>>
                <?php if ($entry['distortion_detected']): ?>
                    <li><?= $distortions
                        ? 'Μια σκέψη που ίσως αξίζει να ξανακοιτάξεις: ' . e(mb_strtolower(implode(', ', $distortions))) . '.'
                        : 'Ίσως κάποια σκέψη εδώ αξίζει να την ξανακοιτάξεις με λίγη τρυφερότητα.' ?></li>
                <?php endif; ?>
                <?php if ($entry['irony_detected']): ?>
                    <li>Διάβασα λίγη ειρωνεία ή χιούμορ σε αυτή τη σελίδα.</li>
                <?php endif; ?>
            </ul>

            <?php if (!$analysed): ?>
                <p class="live__status muted" id="feel-empty">Αυτή η σελίδα δεν έχει αναλυθεί ακόμα. Αν την επεξεργαστείς, θα ξαναδοκιμάσω.</p>
            <?php endif; ?>
        </section>
    </div>
</div>

<dialog class="dialog card" id="delete-dialog" aria-labelledby="delete-title">
    <span class="tape tape--rose" aria-hidden="true"></span>
    <h2 id="delete-title">Να σβήσω αυτή τη σελίδα;</h2>
    <p class="muted">Η σελίδα και η ανάλυσή της θα διαγραφούν οριστικά. Δεν μπορεί να αναιρεθεί.</p>
    <form method="dialog" class="dialog__actions">
        <button class="btn btn--quiet" value="cancel">Όχι, κράτα τη</button>
        <button class="btn btn--danger-solid" type="button" id="delete-confirm">Ναι, διαγραφή</button>
    </form>
</dialog>
