<?php partial('brand-title', ['subtitle' => 'Φτιάξε το δικό σου ημερολόγιο.']); ?>
<?php partial('flash'); ?>

<form class="form" method="POST" action="<?= e(url('register')) ?>">
    <?= csrf_field() ?>
    <div class="field">
        <label for="name">Ονοματεπώνυμο</label>
        <input class="input" id="name" name="name" type="text" placeholder="πώς σε λένε" maxlength="70" autocomplete="name" required>
    </div>
    <div class="field">
        <label for="username">Username</label>
        <input class="input" id="username" name="username" type="text" placeholder="από 3 έως 50 χαρακτήρες" minlength="3" maxlength="50" autocomplete="username" required>
    </div>
    <div class="field">
        <label for="email">Email</label>
        <input class="input" id="email" name="email" type="email" placeholder="το email σου" maxlength="254" autocomplete="email" required>
    </div>
    <div class="field">
        <label for="password">Κωδικός</label>
        <input class="input" id="password" name="password" type="password" placeholder="τουλάχιστον <?= \App\Models\User::MIN_PASSWORD_LENGTH ?> χαρακτήρες" minlength="<?= \App\Models\User::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password" required>
    </div>
    <button class="btn btn--block" type="submit">Δημιουργία λογαριασμού</button>
</form>

<p class="auth__alt">Έχεις ήδη λογαριασμό; <a href="<?= e(url('login')) ?>">Σύνδεση</a></p>
