(() => {
    const $ = id => document.getElementById(id);
    const root = $('entry');
    if (!root) return;

    const id = root.dataset.id;
    const text = $('entry-text');
    const form = $('edit-form');
    const textarea = $('edit-body');
    const readActions = $('read-actions');
    const editActions = $('edit-actions');
    const status = $('edit-status');
    const error = $('form-error');
    const saveBtn = $('save-btn');

    function setEditing(editing) {
        form.hidden = !editing;
        text.hidden = editing;
        readActions.hidden = editing;
        editActions.hidden = !editing;
        error.hidden = true;
        if (editing) {
            textarea.focus();
            textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        }
    }

    function renderFeel(entry) {
        const level = window.mood.level(entry.sentiment_score);
        const circle = $('feel-mood');
        if (level) {
            circle.style.setProperty('--mood', `var(--mood-${level})`);
        } else {
            circle.style.removeProperty('--mood');
        }
        $('feel-label').textContent = level ? window.mood.label(level) : 'Χωρίς ανάλυση';

        const emotions = $('feel-emotions');
        emotions.replaceChildren();
        const chip = (name, main) => {
            const span = document.createElement('span');
            span.className = main ? 'chip chip--main' : 'chip';
            span.textContent = name.toLowerCase();
            emotions.append(span);
        };
        if (entry.emotion_name) chip(entry.emotion_name, true);
        for (const row of entry.emotions || []) {
            if (row.name !== entry.emotion_name) chip(row.name, false);
        }
        emotions.hidden = emotions.childElementCount === 0;

        const notes = $('feel-notes');
        notes.replaceChildren();
        const note = message => {
            const li = document.createElement('li');
            li.textContent = message;
            notes.append(li);
        };
        if (Number(entry.distortion_detected)) {
            const types = (entry.distortion_types || []).map(row => row.name).join(', ');
            note(types
                ? `Μια σκέψη που ίσως αξίζει να ξανακοιτάξεις: ${types.toLowerCase()}.`
                : 'Ίσως κάποια σκέψη εδώ αξίζει να την ξανακοιτάξεις με λίγη τρυφερότητα.');
        }
        if (Number(entry.irony_detected)) {
            note('Διάβασα λίγη ειρωνεία ή χιούμορ σε αυτή τη σελίδα.');
        }
        notes.hidden = notes.childElementCount === 0;

        const empty = $('feel-empty');
        if (empty) empty.hidden = level !== 0;
    }

    $('edit-btn').addEventListener('click', () => {
        status.textContent = '';
        setEditing(true);
    });

    $('cancel-btn').addEventListener('click', () => {
        textarea.value = text.textContent;
        setEditing(false);
    });

    textarea.addEventListener('keydown', event => {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            form.requestSubmit();
        }
        if (event.key === 'Escape') {
            $('cancel-btn').click();
        }
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const body = textarea.value.trim();
        if (body === '') {
            error.textContent = 'Η σελίδα δεν μπορεί να μείνει άδεια. Αν δεν τη θέλεις πια, μπορείς να τη διαγράψεις.';
            error.hidden = false;
            return;
        }

        saveBtn.disabled = true;
        saveBtn.textContent = 'Αποθήκευση…';
        const result = await window.api.patch(`api/entries/${id}`, { body });
        saveBtn.disabled = false;
        saveBtn.textContent = 'Αποθήκευση αλλαγών';

        if (!result.ok) {
            error.textContent = result.data.error || 'Οι αλλαγές δεν αποθηκεύτηκαν. Δοκίμασε ξανά.';
            error.hidden = false;
            return;
        }

        const entry = result.data.data;
        text.textContent = entry.body;
        textarea.value = entry.body;
        renderFeel(entry);
        window.support.render(result.data.support || null);
        setEditing(false);
        status.textContent = result.data.ai_available === false
            ? 'Οι αλλαγές αποθηκεύτηκαν, χωρίς νέα ανάλυση προς το παρόν.'
            : 'Οι αλλαγές αποθηκεύτηκαν και η ανάλυση ενημερώθηκε.';
    });

    const dialog = $('delete-dialog');
    $('delete-btn').addEventListener('click', () => dialog.showModal());

    $('delete-confirm').addEventListener('click', async event => {
        const btn = event.currentTarget;
        btn.disabled = true;
        btn.textContent = 'Διαγραφή…';
        const result = await window.api.delete(`api/entries/${id}`);
        if (result.ok) {
            window.location.href = window.api.url('entry/history?deleted=1');
            return;
        }
        btn.disabled = false;
        btn.textContent = 'Ναι, διαγραφή';
        dialog.close();
        error.textContent = result.data.error || 'Η σελίδα δεν διαγράφηκε. Δοκίμασε ξανά.';
        error.hidden = false;
    });
})();
