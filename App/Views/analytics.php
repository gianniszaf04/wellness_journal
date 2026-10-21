<?php
$num = static fn (?float $v): string => $v === null ? '–' : number_format($v, 1, ',', '');
$best = null;
foreach ($weekdays as $day) {
    if ($day['value'] !== null && ($best === null || $day['value'] > $best['value'])) {
        $best = $day;
    }
}
$emotionTotal = array_sum(array_column($emotions, 'value'));
?>
<div class="greet">
    <div>
        <p class="greet__date">πώς κυλάει ο καιρός σου</p>
        <h1>Τα <span class="mark" style="--hl: var(--mustard)">στατιστικά</span> σου</h1>
    </div>
</div>

<nav class="range" aria-label="Περίοδος">
    <?php foreach ($ranges as $value => $label): ?>
        <a class="range__item" href="<?= e(url('analytics') . ($value === 30 ? '' : '?days=' . $value)) ?>"<?= $value === $days ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$daily): ?>
    <div class="card empty">
        <span class="tape tape--mustard" aria-hidden="true"></span>
        <svg class="doodle" aria-hidden="true"><use href="#book"/></svg>
        <h2>Δεν έχω ακόμα αρκετές σελίδες για αυτή την περίοδο</h2>
        <p class="muted">Γράψε μερικές μέρες και εδώ θα δεις πώς αλλάζει η διάθεσή σου.</p>
        <a class="btn" href="<?= e(url('entry/editor')) ?>">Γράψε τη σημερινή σελίδα</a>
    </div>
<?php else: ?>
    <div class="kpis">
        <div class="card kpi">
            <span class="kpi__label">Σελίδες</span>
            <b class="kpi__value"><?= (int) $pages ?></b>
            <span class="kpi__note"><?= count($daily) === 1 ? 'σε 1 μέρα' : 'σε ' . count($daily) . ' μέρες' ?></span>
        </div>
        <div class="card kpi">
            <span class="kpi__label">Μέση διάθεση</span>
            <b class="kpi__value"><?= $num($average) ?><small>/10</small></b>
            <span class="kpi__note">
                <?php if ($delta === null): ?>
                    χωρίς σύγκριση με πριν
                <?php elseif ($delta == 0): ?>
                    ίδια με την προηγούμενη περίοδο
                <?php else: ?>
                    <span class="delta delta--<?= $delta > 0 ? 'up' : 'down' ?>"><i aria-hidden="true"><?= $delta > 0 ? '▲' : '▼' ?></i> <?= $delta > 0 ? '+' : '' ?><?= $num($delta) ?></span> από την προηγούμενη περίοδο
                <?php endif; ?>
            </span>
        </div>
        <div class="card kpi">
            <span class="kpi__label">Τρέχον σερί</span>
            <b class="kpi__value"><?= (int) $streak ?></b>
            <span class="kpi__note"><?= $streak === 1 ? 'μέρα στη σειρά' : 'μέρες στη σειρά' ?></span>
        </div>
        <div class="card kpi">
            <span class="kpi__label">Κυρίαρχο συναίσθημα</span>
            <b class="kpi__value kpi__value--word"><?= e($topEmotion ?? '–') ?></b>
            <span class="kpi__note"><?= $topEmotion !== null ? $emotions[0]['value'] . ' από ' . $emotionTotal . ' σελίδες' : 'χωρίς ανάλυση ακόμα' ?></span>
        </div>
    </div>

    <section class="card chart-card" aria-labelledby="trend-title">
        <span class="tape tape--left tape--sage" aria-hidden="true"></span>
        <h2 class="card__title" id="trend-title">Η διάθεσή σου μέρα με τη μέρα <small>0 = πολύ δύσκολα · 10 = πολύ καλά</small></h2>
        <div class="chart" data-chart="line" data-points="<?= e(json_encode($daily, JSON_UNESCAPED_UNICODE)) ?>" data-start="<?= e(date('Y-m-d', strtotime('-' . ($days - 1) . ' days'))) ?>" data-end="<?= e(date('Y-m-d')) ?>" role="img" aria-label="Γράφημα της διάθεσης ανά ημέρα"></div>
        <details class="table-view">
            <summary>Δες τα δεδομένα σε πίνακα</summary>
            <table>
                <thead><tr><th>Ημέρα</th><th>Διάθεση</th><th>Σελίδες</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($daily) as $d): ?>
                        <tr><td><?= e($d['label']) ?></td><td><?= $num($d['value']) ?> · <?= e($d['mood']) ?></td><td><?= (int) $d['count'] ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </section>

    <div class="dash-grid">
        <section class="card chart-card" aria-labelledby="weekday-title">
            <span class="tape tape--right tape--rose" aria-hidden="true"></span>
            <h2 class="card__title" id="weekday-title">Ποιες μέρες νιώθεις καλύτερα</h2>
            <?php if ($best): ?>
                <p class="chart-note">Συνήθως νιώθεις καλύτερα <?= $best['name'] === 'Σάββατο' ? 'το' : 'την' ?> <b><?= e($best['name']) ?></b>.</p>
            <?php endif; ?>
            <div class="chart" data-chart="columns" data-points="<?= e(json_encode($weekdays, JSON_UNESCAPED_UNICODE)) ?>" role="img" aria-label="Μέση διάθεση ανά ημέρα της εβδομάδας"></div>
            <details class="table-view">
                <summary>Δες τα δεδομένα σε πίνακα</summary>
                <table>
                    <thead><tr><th>Ημέρα</th><th>Μέση διάθεση</th><th>Μέρες</th></tr></thead>
                    <tbody>
                        <?php foreach ($weekdays as $d): ?>
                            <tr><td><?= e($d['name']) ?></td><td><?= $num($d['value']) ?></td><td><?= (int) $d['count'] ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </section>

        <section class="card chart-card" aria-labelledby="emotions-title">
            <span class="tape tape--left tape--mustard" aria-hidden="true"></span>
            <h2 class="card__title" id="emotions-title">Τα συναισθήματα που κυριάρχησαν</h2>
            <?php if ($emotions): ?>
                <div class="chart" data-chart="bars" data-points="<?= e(json_encode($emotions, JSON_UNESCAPED_UNICODE)) ?>" role="img" aria-label="Πόσες σελίδες είχε κάθε κυρίαρχο συναίσθημα"></div>
                <details class="table-view">
                    <summary>Δες τα δεδομένα σε πίνακα</summary>
                    <table>
                        <thead><tr><th>Συναίσθημα</th><th>Σελίδες</th><th>Ποσοστό</th></tr></thead>
                        <tbody>
                            <?php foreach ($emotions as $d): ?>
                                <tr><td><?= e($d['label']) ?></td><td><?= (int) $d['value'] ?></td><td><?= $emotionTotal ? round($d['value'] / $emotionTotal * 100) : 0 ?>%</td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php else: ?>
                <p class="muted">Οι σελίδες αυτής της περιόδου δεν έχουν ακόμα ανάλυση συναισθημάτων.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="card" aria-labelledby="entities-title">
        <span class="tape tape--right tape--sky" aria-hidden="true"></span>
        <h2 class="card__title" id="entities-title">Τι εμφανίζεται συχνά στις σελίδες σου <small>από όλες τις σελίδες</small></h2>
        <?php if ($entities): ?>
            <table class="entities">
                <thead><tr><th>Πρόσωπο ή θέμα</th><th>Αναφορές</th><th>Διάθεση όταν το αναφέρεις</th></tr></thead>
                <tbody>
                    <?php foreach ($entities as $entity): ?>
                        <tr>
                            <td><?= e($entity['name']) ?></td>
                            <td><?= (int) $entity['mentions'] ?></td>
                            <td>
                                <?php if ($entity['level']): ?>
                                    <i class="mood-dot" style="background: var(--mood-<?= $entity['level'] ?>)"></i>
                                    <?= e(mood_label($entity['level'])) ?><?= $entity['emotion'] ? ' · ' . e(mb_strtolower((string) $entity['emotion'])) : '' ?>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">Όταν γράφεις για ανθρώπους, μέρη ή δραστηριότητες, θα τα βλέπεις εδώ μαζί με το πώς νιώθεις γι' αυτά.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
