<?php
$links = [
    'thoughts/new'   => 'Νέα σελίδα',
    'thoughts/pages' => 'Οι σελίδες σου',
    'thoughts/about' => 'Τι είναι αυτό;',
];
?>
<nav class="sticker notebook-nav" aria-label="Το τετράδιο">
    <?php foreach ($links as $path => $label): ?>
        <a href="<?= e(url($path)) ?>"<?= is_current_path($path) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
