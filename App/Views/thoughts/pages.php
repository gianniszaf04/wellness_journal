<div class="notebook-wrap notebook-wrap--narrow">
<?php partial('thought-nav'); ?>

<section class="card notebook-pages" aria-labelledby="pages-title">
    <span class="tape tape--left tape--sky" aria-hidden="true"></span>
    <h1 class="notebook__title" id="pages-title">Οι σελίδες σου</h1>

    <?php if ($pages === []): ?>
        <p class="muted">Δεν έχεις κλείσει ακόμα καμία σελίδα. <a href="<?= e(url('thoughts/new')) ?>">Άνοιξε το τετράδιο</a>.</p>
    <?php else: ?>
        <ol class="notebook-pages__list">
            <?php foreach ($pages as $page): ?>
                <?php $snippet = mb_strlen((string) $page['thought']) > 90 ? mb_substr((string) $page['thought'], 0, 90) . '…' : (string) $page['thought']; ?>
                <li>
                    <a class="notebook-pages__item" href="<?= e(url('thoughts/' . (int) $page['id'])) ?>">
                        <span class="notebook__eyebrow"><?= e(greek_date((string) $page['created_at'])) ?></span>
                        <span class="notebook-pages__thought">«<?= e($snippet) ?>»</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
</div>

<?php partial('thought-helplines'); ?>
