<?php
$links = [
    'dashboard'     => 'Αρχική',
    'entry/editor'  => 'Νέα σελίδα',
    'entry/history' => 'Ιστορικό',
    'analytics'     => 'Στατιστικά',
    'profile'       => 'Προφίλ',
];
$username = (string) ($_SESSION['username'] ?? '');
$initial = $username !== '' ? mb_strtoupper(mb_substr($username, 0, 1)) : '·';
?>
<header class="topbar">
    <a class="brand" href="<?= e(url('dashboard')) ?>">
        <svg aria-hidden="true"><use href="#book"/></svg>
        Ημερολόγιο Ευεξίας
    </a>

    <nav class="nav" aria-label="Κύριο μενού">
        <?php foreach ($links as $path => $label): ?>
            <a href="<?= e(url($path)) ?>"<?= is_current_path($path) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="topbar__tools">
        <?php partial('theme-toggle'); ?>
        <a class="avatar" href="<?= e(url('profile')) ?>" title="Προφίλ"><?= e($initial) ?></a>
        <form method="POST" action="<?= e(url('logout')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--quiet btn--sm" type="submit">Έξοδος</button>
        </form>
    </div>
</header>
