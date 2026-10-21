<?php partial('brand-title'); ?>
<?php partial('flash'); ?>

<form class="form" method="POST" action="<?= e(url('login')) ?>">
    <?= csrf_field() ?>
    <div class="field">
        <label for="email">Email</label>
        <input class="input" id="email" name="email" type="email" placeholder="το email σου" autocomplete="email" required>
    </div>
    <div class="field">
        <label for="password">Κωδικός</label>
        <input class="input" id="password" name="password" type="password" placeholder="ο κωδικός σου" autocomplete="current-password" required>
    </div>
    <button class="btn btn--block" type="submit">Σύνδεση</button>
</form>

<p class="auth__alt">Πρώτη φορά εδώ; <a href="<?= e(url('register')) ?>">Ξεκίνα το ημερολόγιό σου</a></p>
