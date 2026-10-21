<!doctype html>
<html lang="el">
<head>
    <?php partial('head', ['title' => $title ?? null]); ?>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-url" content="<?= e(rtrim(url(), '/')) ?>">
</head>
<body class="layout-app">
    <a class="skip-link" href="#main">Μετάβαση στο περιεχόμενο</a>
    <?php partial('icons'); ?>
    <?php partial('nav'); ?>

    <main id="main" class="dash">
        <?php partial('flash'); ?>
        <?= $content ?>
    </main>

    <script src="<?= e(asset('js/theme.js')) ?>" defer></script>
    <?php foreach ($scripts ?? [] as $script): ?>
        <script src="<?= e(asset('js/' . $script)) ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
