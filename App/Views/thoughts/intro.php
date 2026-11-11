<div class="notebook-wrap notebook-wrap--narrow">
<?php partial('thought-nav'); ?>

<article class="card notebook-intro" aria-labelledby="intro-title">
    <span class="tape tape--sage" aria-hidden="true"></span>

    <h1 class="notebook-intro__title" id="intro-title">Οι έντονες στιγμές</h1>

    <p>Κάποιες στιγμές μας ταράζουν περισσότερο απ' όσο περιμέναμε. Συχνά δεν είναι μόνο αυτό που έγινε, αλλά και αυτό που
        είπαμε στον εαυτό μας εκείνη τη στιγμή, συχνά με τη φωνή των φόβων μας.</p>

    <p>Το μυαλό πέφτει μερικές φορές σε <mark class="notebook-mark">παγίδες σκέψης</mark>: βλέπει το χειρότερο, «διαβάζει»
        τι σκέφτονται οι άλλοι, λέει «πάντα» και «ποτέ». Τις κάνουμε όλοι.</p>

    <p>Σε αυτό το τετράδιο γράφεις μία τέτοια στιγμή και την κοιτάς από κοντά, με τις ερωτήσεις που χρησιμοποιούν οι
        θεραπευτές της γνωσιακής-συμπεριφορικής θεραπείας.</p>

    <section class="notebook-intro__example" aria-label="Πώς γράφεται μια σελίδα">
        <div class="notebook-intro__page">
            <p class="notebook__eyebrow">Εσύ, στη σελίδα</p>
            <p class="notebook-intro__question"><?= e(\App\Services\ThinkingTraps::FIRST_QUESTION) ?></p>
            <p class="notebook-intro__answer">Τα έκανα πάλι θάλασσα.</p>
        </div>
        <div class="notebook-intro__margin sticker">
            <p class="notebook__eyebrow">Στο περιθώριο</p>
            <p class="margin__text">Ένα μοντέλο θα σημειώσει στο περιθώριο τι ακούγεται στον τόνο της σκέψης σου. Κάνει
                λάθη· εσύ αποφασίζεις αν θα το κοιτάξεις.</p>
        </div>
    </section>

    <div class="notebook-intro__start">
        <a class="btn" href="<?= e(url('thoughts/new')) ?>">Άνοιξε το τετράδιο</a>
        <span class="muted">Θέλει περίπου 5 λεπτά.</span>
    </div>

    <p class="notebook-intro__note">Δεν αντικαθιστά έναν ειδικό. Αν κάτι σε πνίγει, οι γραμμές βοήθειας είναι πάντα στο
        τέλος της σελίδας.</p>
</article>
</div>

<?php partial('thought-helplines'); ?>
