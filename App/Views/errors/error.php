<p class="error-code">Σφάλμα <?= (int) $status ?></p>
<h1 class="auth__title"><?= e($heading) ?></h1>
<p class="auth__sub"><?= e($message) ?></p>

<div class="auth__actions">
    <?php if ($status === 403 || $status === 429): ?>
        <button class="btn btn--block" type="button" onclick="history.back()">Πίσω στην προηγούμενη σελίδα</button>
        <a class="btn btn--quiet btn--block" href="<?= e(url('/')) ?>">Στην αρχική</a>
    <?php else: ?>
        <a class="btn btn--block" href="<?= e(url('/')) ?>">Στην αρχική</a>
        <button class="btn btn--quiet btn--block" type="button" onclick="history.back()">Πίσω στην προηγούμενη σελίδα</button>
    <?php endif; ?>
</div>
