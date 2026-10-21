<div class="greet">
    <div>
        <p class="greet__date"><?= $all === 1 ? '1 σελίδα' : number_format($all, 0, ',', '.') . ' σελίδες' ?> στο ημερολόγιό σου</p>
        <h1>Το <span class="mark" style="--hl: var(--sky)">ιστορικό</span> σου</h1>
    </div>
    <a class="btn" href="<?= e(url('entry/editor')) ?>">Νέα σελίδα</a>
</div>

<?php if ($deleted): ?>
    <div class="flash flash--info" role="status">Η σελίδα διαγράφηκε.</div>
<?php endif; ?>

<?php if ($all > 0): ?>
    <form class="filters card" method="GET" action="<?= e(url('entry/history')) ?>" role="search">
        <span class="tape tape--left tape--sky" aria-hidden="true"></span>
        <div class="filters__search">
            <label class="visually-hidden" for="q">Αναζήτηση στις σελίδες σου</label>
            <input class="input" id="q" name="q" type="search" value="<?= e($search) ?>" placeholder="Ψάξε μια λέξη, ένα όνομα, μια στιγμή…">
            <button class="btn" type="submit">Αναζήτηση</button>
        </div>
        <div class="filters__moods" role="radiogroup" aria-label="Διάθεση">
            <label class="mood-pill">
                <input type="radio" name="mood" value="0" onchange="this.form.submit()"<?= $mood === 0 ? ' checked' : '' ?>>
                <span>Όλες</span>
            </label>
            <?php for ($m = 5; $m >= 1; $m--): ?>
                <label class="mood-pill">
                    <input type="radio" name="mood" value="<?= $m ?>" onchange="this.form.submit()"<?= $mood === $m ? ' checked' : '' ?>>
                    <span><i style="background: var(--mood-<?= $m ?>)"></i><?= e(mood_label($m)) ?></span>
                </label>
            <?php endfor; ?>
        </div>
    </form>
<?php endif; ?>

<?php if ($months): ?>
    <?php if ($search !== '' || $mood > 0): ?>
        <p class="muted results">
            <?= $total === 1 ? 'Βρέθηκε 1 σελίδα' : 'Βρέθηκαν ' . number_format($total, 0, ',', '.') . ' σελίδες' ?>
            · <a href="<?= e(url('entry/history')) ?>">καθαρισμός φίλτρων</a>
        </p>
    <?php endif; ?>

    <?php foreach ($months as $month): ?>
        <section class="month" aria-label="<?= e($month['label']) ?>">
            <h2 class="month__title"><?= e($month['label']) ?></h2>
            <div class="month__list">
                <?php foreach ($month['entries'] as $entry): ?>
                    <?php
                    $level = mood_level($entry['sentiment_score']);
                    $ts = strtotime((string) $entry['entry_date']);
                    ?>
                    <a class="day-card" href="<?= e(url('entry/' . $entry['id'])) ?>"<?= $level ? ' style="--mood: var(--mood-' . $level . ')"' : '' ?>>
                        <span class="day-card__date">
                            <b><?= date('j', $ts) ?></b>
                            <small><?= e(greek_weekday((int) date('w', $ts), true)) ?></small>
                        </span>
                        <span class="day-card__body">
                            <span class="day-card__meta">
                                <?= e(date('H:i', strtotime((string) $entry['created_at']))) ?>
                                <?php if ($level): ?> · <?= e(mood_label($level)) ?><?php endif; ?>
                            </span>
                            <span class="day-card__text"><?= e(mb_substr((string) $entry['body'], 0, 240)) ?></span>
                        </span>
                        <?php if (!empty($entry['emotion_name'])): ?>
                            <span class="chip"><?= e(mb_strtolower((string) $entry['emotion_name'])) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="Σελίδες ιστορικού">
            <?php if ($nextUrl): ?>
                <a class="btn btn--quiet" href="<?= e($nextUrl) ?>">← Παλαιότερες</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            <span class="muted"><?= $page ?> από <?= $pages ?></span>
            <?php if ($prevUrl): ?>
                <a class="btn btn--quiet" href="<?= e($prevUrl) ?>">Νεότερες →</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php elseif ($all > 0): ?>
    <div class="card empty">
        <h2>Δεν βρήκα σελίδες</h2>
        <p class="muted">Δοκίμασε άλλη λέξη ή άλλη διάθεση.</p>
        <a class="btn btn--quiet" href="<?= e(url('entry/history')) ?>">Δες όλες τις σελίδες</a>
    </div>
<?php else: ?>
    <div class="card empty">
        <span class="tape tape--mustard" aria-hidden="true"></span>
        <svg class="doodle" aria-hidden="true"><use href="#book"/></svg>
        <h2>Το ημερολόγιό σου είναι ακόμα άγραφο</h2>
        <p class="muted">Η πρώτη σελίδα είναι πάντα η πιο ωραία.</p>
        <a class="btn" href="<?= e(url('entry/editor')) ?>">Γράψε την πρώτη σου σελίδα</a>
    </div>
<?php endif; ?>
