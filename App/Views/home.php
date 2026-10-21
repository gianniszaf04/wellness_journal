<?php partial('brand-title'); ?>

<p class="auth__text">Γράψε πώς νιώθεις κάθε μέρα και δες πώς αλλάζει η διάθεσή σου μέσα στον μήνα.</p>

<div class="auth__actions">
    <?php if ($loggedIn): ?>
        <a class="btn btn--block" href="<?= e(url('dashboard')) ?>">Συνέχεια στο ημερολόγιο</a>
    <?php else: ?>
        <a class="btn btn--block" href="<?= e(url('login')) ?>">Σύνδεση</a>
        <a class="btn btn--quiet btn--block" href="<?= e(url('register')) ?>">Ξεκίνα το ημερολόγιό σου</a>
    <?php endif; ?>
</div>
