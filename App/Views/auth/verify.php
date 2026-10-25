<h1 class="auth__title">Επιβεβαίωσε το email σου</h1>
<p class="auth__sub">Σου στείλαμε έναν 6ψήφιο κωδικό στο <strong><?= e($email) ?></strong></p>

<?php partial('flash'); ?>

<form class="form" method="POST" action="<?= e(url('verify-email')) ?>">
    <?= csrf_field() ?>
    <div class="field">
        <label for="code">Κωδικός</label>
        <input class="input" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
            placeholder="123456" autocomplete="one-time-code" required autofocus>
    </div>
    <button class="btn btn--block" type="submit">Επιβεβαίωση</button>
</form>

<p class="auth__text">Ο κωδικός ισχύει <?= (int) $ttlMin ?> λεπτά. Δεν τον βρίσκεις; Κοίτα και στα ανεπιθύμητα.</p>

<div class="auth__actions">
    <form method="POST" action="<?= e(url('verify-email/resend')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn--quiet btn--block" type="submit">Στείλε νέο κωδικό</button>
    </form>
</div>
