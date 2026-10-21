<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(isset($title) ? $title . ' · Ημερολόγιο Ευεξίας' : 'Ημερολόγιο Ευεξίας') ?></title>
<?php
$themePref = '';
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    try {
        $themePref = ['light_theme' => 'day', 'dark_theme' => 'night'][(new \App\Models\UserProfile())->getOrCreate((int) $_SESSION['user_id'])['theme_background'] ?? ''] ?? '';
    } catch (\Throwable $e) {
        $themePref = '';
    }
}
?>
<script>
    try {
        document.documentElement.dataset.theme = localStorage.getItem('theme')
            || <?= json_encode($themePref) ?>
            || (matchMedia('(prefers-color-scheme: dark)').matches ? 'night' : 'day');
    } catch (e) {
        document.documentElement.dataset.theme = <?= json_encode($themePref ?: 'day') ?>;
    }
</script>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="prefetch" href="<?= e(url('public/assets/img/grain-day.png')) ?>" as="image">
<link rel="prefetch" href="<?= e(url('public/assets/img/grain-night.png')) ?>" as="image">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/pages.css')) ?>">
