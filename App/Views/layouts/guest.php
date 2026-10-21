<!doctype html>
<html lang="el">
<head>
    <?php partial('head', ['title' => $title ?? null]); ?>
</head>
<body class="layout-guest">
    <?php partial('icons'); ?>

    <main id="main" class="auth">
        <div class="blob blob--1" aria-hidden="true"></div>
        <div class="blob blob--2" aria-hidden="true"></div>
        <div class="blob blob--3" aria-hidden="true"></div>
        <div class="blob blob--4" aria-hidden="true"></div>

        <div class="card auth__card">
            <span class="tape tape--left tape--sage" aria-hidden="true"></span>
            <span class="tape tape--right" aria-hidden="true"></span>
            <?php partial('theme-toggle'); ?>
            <svg class="doodle" aria-hidden="true"><use href="#book"/></svg>
            <?= $content ?>
        </div>
    </main>

    <script src="<?= e(asset('js/theme.js')) ?>" defer></script>
    <?php foreach ($scripts ?? [] as $script): ?>
        <script src="<?= e(asset('js/' . $script)) ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
