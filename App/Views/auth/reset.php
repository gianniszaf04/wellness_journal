<h1 class="auth__title">Όρισε νέο κωδικό</h1>
<p class="auth__sub">Γράψε τον κωδικό που στάλθηκε στο <strong><?= e($email) ?></strong></p>

<?php partial('flash'); ?>

<form class="form" method="POST" action="<?= e(url('password/reset')) ?>">
    <?= csrf_field() ?>
    <div class="field">
        <label for="code">Κωδικός από το email</label>
        <input class="input" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
            placeholder="123456" autocomplete="one-time-code" required autofocus>
    </div>
    <div class="field">
        <label for="password">Νέος κωδικός</label>
        <input class="input" id="password" name="password" type="password" placeholder="τουλάχιστον 8 χαρακτήρες"
            minlength="8" autocomplete="new-password" required>
    </div>
    <div class="field">
        <label for="password_confirmation">Επιβεβαίωση νέου κωδικού</label>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password"
            placeholder="ξανά ο νέος κωδικός" minlength="8" autocomplete="new-password" required>
    </div>
    <button class="btn btn--block" type="submit">Αλλαγή κωδικού</button>
</form>

<p class="auth__text">Ο κωδικός ισχύει <?= (int) $ttlMin ?> λεπτά. Δεν τον βρίσκεις; Κοίτα και στα ανεπιθύμητα.</p>

<div class="auth__actions">
    <form method="POST" action="<?= e(url('password/forgot')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <button class="btn btn--quiet btn--block" type="submit">Στείλε νέο κωδικό</button>
    </form>
</div>
