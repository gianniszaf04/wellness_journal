<?php foreach (['error' => 'alert', 'info' => 'status', 'success' => 'status'] as $type => $role): ?>
    <?php if (($message = flash($type)) !== null): ?>
        <div class="flash flash--<?= $type ?>" role="<?= $role ?>"><?= e($message) ?></div>
    <?php endif; ?>
<?php endforeach; ?>
