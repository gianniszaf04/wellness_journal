<h1 class="auth__title">Ημερολόγιο <span class="mark">Ευεξίας</span></h1>
<p class="auth__sub"><?= e($subtitle ?? 'Ένα ζεστό μέρος για τις σκέψεις σου.') ?></p>
<div class="auth__moods" aria-hidden="true">
    <?php for ($m = 5; $m >= 1; $m--): ?>
        <i style="background: var(--mood-<?= $m ?>)"></i>
    <?php endfor; ?>
</div>
