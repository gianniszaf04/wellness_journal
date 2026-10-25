<h1 class="auth__title">Ξέχασες τον κωδικό σου;</h1>
<p class="auth__sub">Γράψε το email σου και θα σου στείλουμε έναν 6ψήφιο κωδικό για να ορίσεις νέο.</p>

<?php partial('flash'); ?>

<form class="form" method="POST" action="<?= e(url('password/forgot')) ?>">
    <?= csrf_field() ?>
    <div class="field">
        <label for="email">Email</label>
        <input class="input" id="email" name="email" type="email" placeholder="το email σου" autocomplete="email" required autofocus>
    </div>
    <button class="btn btn--block" type="submit">Στείλε κωδικό</button>
</form>

<p class="auth__alt">Τον θυμήθηκες; <a href="<?= e(url('login')) ?>">Σύνδεση</a></p>
