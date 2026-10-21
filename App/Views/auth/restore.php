<h1 class="auth__title">Ο λογαριασμός σου θα διαγραφεί</h1>
<p class="auth__sub">Θέλεις να τον επαναφέρεις;</p>

<p class="auth__text">Ζήτησες διαγραφή του λογαριασμού σου. Θα σβηστεί οριστικά, μαζί με όλες τις σελίδες σου,
    στις <strong><?= e(greek_date($deletionDate, false)) ?></strong>.</p>

<div class="auth__actions">
    <form method="POST" action="<?= e(url('account/restore')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn--block" type="submit">Επαναφορά λογαριασμού</button>
    </form>
    <form method="POST" action="<?= e(url('account/restore/cancel')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn--quiet btn--block" type="submit">Όχι, συνέχισε τη διαγραφή</button>
    </form>
</div>
