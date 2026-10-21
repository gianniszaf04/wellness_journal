(() => {
    const $ = id => document.getElementById(id);
    const form = $('entry-form');
    if (!form) return;

    const body = $('entry-body');
    const date = $('entry-date');
    const grid = document.querySelector('.editor-grid');
    const saveBtn = $('save-btn');
    const formError = $('form-error');
    const count = $('char-count');
    const draftStatus = $('draft-status');
    const maxLength = Number(body.maxLength) || 10000;
    const DRAFT_KEY = 'entry-draft';
    const MIN_LIVE = 20;

    const storage = {
        get() {
            try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch (e) { return null; }
        },
        set(value) {
            try { localStorage.setItem(DRAFT_KEY, JSON.stringify(value)); return true; } catch (e) { return false; }
        },
        clear() {
            try { localStorage.removeItem(DRAFT_KEY); } catch (e) { }
        },
    };

    const debounce = (fn, ms) => {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    };

    const formatDate = value => {
        const d = new Date(`${value}T12:00:00`);
        return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat('el-GR', { weekday: 'long', day: 'numeric', month: 'long' }).format(d);
    };

    function updateCount() {
        const n = body.value.length;
        count.textContent = `${n.toLocaleString('el-GR')} / ${maxLength.toLocaleString('el-GR')}`;
        count.classList.toggle('is-near', n > maxLength * .9);
    }

    const saveDraft = debounce(() => {
        if (body.value.trim() === '') {
            storage.clear();
            draftStatus.textContent = '';
            return;
        }
        if (storage.set({ body: body.value, date: date.value })) {
            draftStatus.textContent = 'Το πρόχειρο κρατήθηκε';
        }
    }, 700);

    const support = window.support;

    const live = {
        meter: document.querySelectorAll('.live__meter i'),
        mood: $('live-mood'),
        status: $('live-status'),
        emotions: $('live-emotions'),
        notes: $('live-notes'),
        controller: null,

        setStatus(text, loading = false) {
            this.status.textContent = text;
            this.status.classList.toggle('is-loading', loading);
        },

        reset(text) {
            this.meter.forEach(dot => dot.classList.remove('is-active'));
            this.mood.textContent = '—';
            this.emotions.hidden = true;
            this.notes.hidden = true;
            this.setStatus(text);
        },

        show(data) {
            const level = window.mood.level(data.sentiment_score);
            this.meter.forEach(dot => dot.classList.toggle('is-active', Number(dot.dataset.level) === level));
            this.mood.textContent = level ? window.mood.label(level) : '—';

            this.emotions.replaceChildren();
            const names = [];
            if (data.emotion) {
                const chip = document.createElement('span');
                chip.className = 'chip chip--main';
                const pct = data.emotion.confidence !== null ? ` · ${Math.round(data.emotion.confidence * 100)}%` : '';
                chip.textContent = `${data.emotion.name.toLowerCase()}${pct}`;
                this.emotions.append(chip);
                names.push(data.emotion.name);
            }
            for (const name of data.emotions || []) {
                if (names.includes(name)) continue;
                const chip = document.createElement('span');
                chip.className = 'chip';
                chip.textContent = name.toLowerCase();
                this.emotions.append(chip);
            }
            this.emotions.hidden = this.emotions.childElementCount === 0;

            this.notes.replaceChildren();
            const note = text => {
                const li = document.createElement('li');
                li.textContent = text;
                this.notes.append(li);
            };
            if (data.distortion?.detected) {
                const types = (data.distortion_types || []).join(', ');
                note(types
                    ? `Μια σκέψη που ίσως αξίζει να ξανακοιτάξεις: ${types.toLowerCase()}.`
                    : 'Ίσως κάποια σκέψη εδώ αξίζει να την ξανακοιτάξεις με λίγη τρυφερότητα.');
            }
            if (data.irony?.detected) {
                note('Διαβάζω λίγη ειρωνεία ή χιούμορ. Τα λαμβάνω υπόψη.');
            }
            this.notes.hidden = this.notes.childElementCount === 0;

            this.setStatus('Αλλάζει καθώς γράφεις.');
        },

        async analyze() {
            const text = body.value.trim();
            if (this.controller) this.controller.abort();

            if (text === '') {
                this.reset('Ξεκίνα να γράφεις και θα σου πω τι διαβάζω.');
                support.render(null);
                return;
            }

            this.controller = new AbortController();
            if (text.length >= MIN_LIVE) this.setStatus('Διαβάζω…', true);

            let result;
            try {
                result = await window.api.post('api/sentiment/live', { text }, { signal: this.controller.signal });
            } catch (error) {
                return;
            }

            if (result.status === 429) {
                this.setStatus('Γράφεις γρήγορα! Θα ξανακοιτάξω σε λίγο.');
                return;
            }
            if (!result.ok) {
                this.setStatus('Δεν μπόρεσα να διαβάσω το κείμενο τώρα.');
                return;
            }

            support.render(result.data.support || null);

            if (result.data.data) {
                this.show(result.data.data);
            } else if (result.data.ai_available === false) {
                this.reset('Η ανάλυση δεν είναι διαθέσιμη αυτή τη στιγμή. Η σελίδα σου θα αποθηκευτεί κανονικά.');
            } else if (text.length < MIN_LIVE) {
                this.reset('Γράψε λίγες λέξεις ακόμα…');
            }
        },
    };

    const analyzeSoon = debounce(() => live.analyze(), 1200);

    body.addEventListener('input', () => {
        formError.hidden = true;
        updateCount();
        saveDraft();
        analyzeSoon();
    });
    date.addEventListener('change', saveDraft);

    document.querySelectorAll('[data-prompt]').forEach(btn => {
        btn.addEventListener('click', () => {
            const before = body.value.trimEnd();
            const insert = `${before ? '\n\n' : ''}${btn.dataset.prompt}\n`;
            body.value = before + insert;
            body.focus();
            body.setSelectionRange(body.value.length, body.value.length);
            body.dispatchEvent(new Event('input'));
        });
    });

    body.addEventListener('keydown', event => {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    const saved = {
        box: $('saved'),
        show(entry, message) {
            const level = window.mood.level(entry.sentiment_score);
            $('saved-mood').style.setProperty('--mood', level ? `var(--mood-${level})` : 'var(--dashed)');
            $('saved-summary').textContent = [
                formatDate(entry.entry_date),
                level ? window.mood.label(level) : null,
                entry.emotion_name ? entry.emotion_name.toLowerCase() : null,
            ].filter(Boolean).join(' · ');
            const text = $('saved-message');
            text.textContent = message?.message_text || '';
            text.hidden = !message?.message_text;
            $('saved-view').href = window.api.url(`entry/${entry.id}`);
            grid.hidden = true;
            this.box.hidden = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        hide() {
            this.box.hidden = true;
            grid.hidden = false;
        },
    };

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const text = body.value.trim();
        if (text === '') {
            formError.textContent = 'Γράψε κάτι πριν αποθηκεύσεις τη σελίδα.';
            formError.hidden = false;
            body.focus();
            return;
        }

        saveBtn.disabled = true;
        saveBtn.textContent = 'Αποθήκευση…';
        formError.hidden = true;

        const result = await window.api.post('api/entries', { body: text, entry_date: date.value });

        saveBtn.disabled = false;
        saveBtn.textContent = 'Αποθήκευση σελίδας';

        if (!result.ok) {
            formError.textContent = result.data.error || 'Η σελίδα δεν αποθηκεύτηκε. Δοκίμασε ξανά.';
            formError.hidden = false;
            return;
        }

        storage.clear();
        draftStatus.textContent = '';
        support.render(result.data.support || null);
        saved.show(result.data.data, result.data.message);
    });

    $('saved-again').addEventListener('click', () => {
        body.value = '';
        updateCount();
        live.reset('Ξεκίνα να γράφεις και θα σου πω τι διαβάζω.');
        support.render(null);
        saved.hide();
        body.focus();
    });

    const draft = storage.get();
    if (draft?.body && body.value === '') {
        body.value = draft.body;
        if (draft.date && draft.date <= date.max) date.value = draft.date;
        draftStatus.textContent = 'Συνεχίζεις από το πρόχειρό σου';
        live.analyze();
    }
    updateCount();
    body.focus({ preventScroll: true });
})();
