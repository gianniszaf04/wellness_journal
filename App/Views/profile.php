<?php
$name = trim((string) $user['name']);
$initial = mb_strtoupper(mb_substr($name !== '' ? $name : (string) $user['username'], 0, 1));
$themes = [
    'default_theme' => ['Αυτόματα', 'όπως ο υπολογιστής σου'],
    'light_theme'   => ['Μέρα', 'πάντα φωτεινό'],
    'dark_theme'    => ['Νύχτα', 'πάντα σκοτεινό'],
];
?>
<div class="me">
    <div class="me__avatar" aria-hidden="true"><?= e($initial) ?></div>
    <div>
        <h1><?= e($name !== '' ? $name : (string) $user['username']) ?></h1>
        <p class="muted">@<?= e((string) $user['username']) ?> · γράφεις από <?= e($memberSince) ?> · <?= $pages === 1 ? '1 σελίδα' : number_format($pages, 0, ',', '.') . ' σελίδες' ?></p>
    </div>
</div>

<div class="dash-grid">
    <div class="side">
        <section class="card" aria-labelledby="details-title">
            <span class="tape tape--left tape--sage" aria-hidden="true"></span>
            <h2 class="card__title" id="details-title">Τα στοιχεία σου</h2>
            <dl class="details">
                <dt>Ονοματεπώνυμο</dt><dd><?= e($name) ?></dd>
                <dt>Username</dt><dd><?= e((string) $user['username']) ?></dd>
                <dt>Email</dt><dd><?= e((string) $user['email']) ?></dd>
            </dl>
        </section>

        <section class="card" aria-labelledby="look-title">
            <span class="tape tape--right tape--rose" aria-hidden="true"></span>
            <h2 class="card__title" id="look-title">Εμφάνιση</h2>
            <p class="muted">Ποιο θέμα θέλεις όταν ανοίγεις το ημερολόγιο, σε κάθε συσκευή.</p>
            <div class="theme-choices" role="radiogroup" aria-labelledby="look-title">
                <?php foreach ($themes as $value => [$label, $hint]): ?>
                    <label class="theme-choice theme-choice--<?= e($value) ?>">
                        <input type="radio" name="theme" value="<?= e($value) ?>"<?= $theme === $value ? ' checked' : '' ?>>
                        <span class="theme-choice__swatch" aria-hidden="true"></span>
                        <b><?= e($label) ?></b>
                        <small><?= e($hint) ?></small>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="form-status" id="theme-status" role="status"></p>
        </section>

        <section class="card" aria-labelledby="data-title">
            <span class="tape tape--left tape--sky" aria-hidden="true"></span>
            <h2 class="card__title" id="data-title">Τα δεδομένα σου</h2>
            <p class="muted">Κατέβασε όλες τις σελίδες σου και την ανάλυσή τους σε ένα αρχείο JSON. Είναι δικά σου.</p>
            <a class="btn btn--quiet" href="<?= e(url('account/export')) ?>" download>Λήψη των δεδομένων μου</a>
        </section>
    </div>

    <div class="side">
        <section class="card" aria-labelledby="password-title">
            <span class="tape tape--right tape--mustard" aria-hidden="true"></span>
            <h2 class="card__title" id="password-title">Αλλαγή κωδικού</h2>
            <?php if ($passwordError !== null): ?>
                <div class="flash flash--error" role="alert"><?= e($passwordError) ?></div>
            <?php elseif ($passwordSuccess !== null): ?>
                <div class="flash flash--success" role="status"><?= e($passwordSuccess) ?></div>
            <?php endif; ?>
            <form class="form" method="POST" action="<?= e(url('account/password')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="current_password">Τρέχων κωδικός</label>
                    <input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                </div>
                <div class="field">
                    <label for="new_password">Νέος κωδικός</label>
                    <input class="input" id="new_password" name="new_password" type="password" minlength="<?= \App\Models\User::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password" required>
                    <span class="field__hint">Τουλάχιστον <?= \App\Models\User::MIN_PASSWORD_LENGTH ?> χαρακτήρες.</span>
                </div>
                <div class="field">
                    <label for="new_password_confirmation">Ξανά ο νέος κωδικός</label>
                    <input class="input" id="new_password_confirmation" name="new_password_confirmation" type="password" autocomplete="new-password" required>
                </div>
                <p class="field__hint">Μετά την αλλαγή θα αποσυνδεθείς από όλες τις άλλες συσκευές.</p>
                <button class="btn" type="submit">Αλλαγή κωδικού</button>
            </form>
        </section>

        <section class="card danger-zone" aria-labelledby="delete-title">
            <h2 class="card__title" id="delete-title">Διαγραφή λογαριασμού</h2>
            <?php if ($deleteError !== null): ?>
                <div class="flash flash--error" role="alert"><?= e($deleteError) ?></div>
            <?php endif; ?>
            <ul class="danger-zone__list">
                <li>Θα αποσυνδεθείς αμέσως.</li>
                <li>Μετά από <b><?= \App\Models\User::DELETION_GRACE_DAYS ?> μέρες</b> ο λογαριασμός και <b>όλες οι σελίδες σου</b> θα σβηστούν οριστικά.</li>
                <li>Ως τότε μπορείς να τον επαναφέρεις, συνδεόμενος ξανά.</li>
            </ul>
            <button class="btn btn--quiet btn--danger" type="button" id="delete-account-btn">Διαγραφή λογαριασμού</button>
        </section>
    </div>
</div>

<dialog class="dialog card" id="delete-account-dialog" aria-labelledby="delete-account-title">
    <span class="tape tape--rose" aria-hidden="true"></span>
    <h2 id="delete-account-title">Σίγουρα θέλεις να φύγεις;</h2>
    <p class="muted">Γράψε τον κωδικό σου για επιβεβαίωση. Έχεις <?= \App\Models\User::DELETION_GRACE_DAYS ?> μέρες για να αλλάξεις γνώμη.</p>
    <form class="form" method="POST" action="<?= e(url('account/delete')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label class="visually-hidden" for="delete_password">Κωδικός</label>
            <input class="input" id="delete_password" name="password" type="password" placeholder="ο κωδικός σου" autocomplete="current-password" required>
        </div>
        <div class="dialog__actions">
            <button class="btn btn--quiet" type="button" data-close>Όχι, μένω</button>
            <button class="btn btn--danger-solid" type="submit">Διαγραφή λογαριασμού</button>
        </div>
    </form>
</dialog>
