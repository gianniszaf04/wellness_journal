(() => {
    const root = document.getElementById('notebook');
    if (!root || root.classList.contains('notebook--read')) return;

    const $ = id => document.getElementById(id);
    const maxEmotions = Number(root.dataset.maxEmotions) || 3;
    const minWords = Number(root.dataset.minWords) || 3;
    const words = text => (text.match(/[\p{L}\p{N}]+/gu) || []).length;
    const feelings = [...root.querySelectorAll('.feeling')];
    const margin = $('nb-margin');
    const calm = matchMedia('(prefers-reduced-motion: reduce)');
    const state = { chosen: [], token: null, data: null, answers: [], response: null, path: null, trap: null, locked: false, busy: false };

    const error = $('nb-error');
    const fail = message => {
        error.textContent = message || '';
        error.hidden = !message;
    };

    const part = name => root.querySelector(`[data-part="${name}"]`);
    const show = (el, on = true) => { el.hidden = !on; };
    const activate = (el, on = true) => el.classList.toggle('is-future', !on);
    const feeling = name => feelings.find(btn => btn.dataset.emotion === name);
    const lcfirst = text => text.charAt(0).toLocaleLowerCase('el') + text.slice(1);
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function button(label, className, onClick) {
        const btn = element('button', className, label);
        btn.type = 'button';
        btn.addEventListener('click', () => {
            if (!state.busy) onClick();
        });
        return btn;
    }

    function mark(text) {
        return element('mark', 'notebook-mark', text);
    }

    function animate(el, name, ms) {
        el.classList.remove('is-in', 'is-out', 'is-revealed');
        if (calm.matches) return Promise.resolve();
        void el.offsetWidth;
        el.classList.add(name);
        return Promise.race([
            new Promise(resolve => el.addEventListener('animationend', resolve, { once: true })),
            pause(ms + 80),
        ]);
    }

    function focusOn(el, area = el) {
        el?.focus({ preventScroll: true });
        if (!area) return;
        const box = area.getBoundingClientRect();
        if (box.top < 0 || box.bottom > window.innerHeight) {
            area.scrollIntoView({
                behavior: calm.matches ? 'auto' : 'smooth',
                block: box.height > window.innerHeight ? 'start' : 'nearest',
            });
        }
    }

    function reveal(el, focus) {
        if (!el.hidden && !el.classList.contains('is-future')) return;
        show(el);
        activate(el);
        animate(el, 'is-revealed', 350);
        el.querySelectorAll('.sticker').forEach(sticker => animate(sticker, 'is-in', 340));
        if (focus) focusOn(focus, el);
    }

    async function stick(...nodes) {
        state.busy = true;
        if (!margin.hidden && margin.childElementCount) {
            await animate(margin, 'is-out', 180);
        }
        margin.replaceChildren(...nodes);
        margin.removeAttribute('aria-busy');
        show(margin);
        await animate(margin, 'is-in', 340);
        state.busy = false;
        focusOn(margin.querySelector('button'), margin);
    }

    async function unstick() {
        if (!margin.hidden) await animate(margin, 'is-out', 180);
        margin.classList.remove('is-out');
        margin.replaceChildren();
        margin.removeAttribute('aria-busy');
        show(margin, false);
    }

    function level(container, name, value, was) {
        const row = element('div', 'level');
        const id = `${container.id}-${feeling(name).dataset.key}-${state.chosen.indexOf(name)}`;
        const label = element('label', 'level__name', name.toLocaleLowerCase('el'));
        label.htmlFor = id;
        const range = element('input', 'level__range');
        Object.assign(range, { type: 'range', id, min: '0', max: '100', step: '1', value: String(value) });
        range.dataset.emotion = name;
        const output = element('output', 'level__value');
        const paint = () => {
            output.textContent = was === undefined ? range.value : `${range.value} · ήταν ${was}`;
        };
        paint();
        range.addEventListener('input', paint);
        row.append(label, range, output);
        container.append(row);
        return range;
    }

    function levels(container) {
        return Object.fromEntries([...container.querySelectorAll('input[type="range"]')]
            .map(range => [range.dataset.emotion, Number(range.value)]));
    }

    function strongest(values) {
        let best = null;
        for (const name of state.chosen) {
            if (best === null || values[name] > values[best]) best = name;
        }
        return best;
    }

    function renderBefore() {
        const container = $('nb-before');
        const previous = levels(container);
        container.replaceChildren();
        state.chosen.forEach(name => {
            level(container, name, previous[name] ?? 50).addEventListener('input', updateMood);
        });
    }

    function updateMood() {
        const field = $('nb-mood-field');
        const name = strongest(levels($('nb-before')));
        if (!name) {
            $('nb-mood-label').textContent = 'Μια δεύτερη ερώτηση, ανάλογα με το πιο έντονο συναίσθημα.';
            $('nb-mood-why').textContent = '';
            return;
        }
        const key = feeling(name).dataset.key;
        $('nb-mood-label').textContent = field.dataset[`q${key.charAt(0).toUpperCase()}${key.slice(1)}`];
        $('nb-mood-why').textContent = `Επειδή ${feeling(name).dataset.the} ήταν το πιο έντονο.`;
    }

    function updateRight() {
        if (state.locked || !$('nb-event').value.trim() || !state.chosen.length) return;
        reveal(part('thought'));
        reveal(part('mood'));
        reveal($('nb-suggest-row'));
    }

    feelings.forEach(btn => btn.addEventListener('click', () => {
        if (state.locked) return;
        const name = btn.dataset.emotion;
        const index = state.chosen.indexOf(name);
        if (index >= 0) {
            state.chosen.splice(index, 1);
        } else if (state.chosen.length < maxEmotions) {
            state.chosen.push(name);
        } else {
            return fail(`Έως ${maxEmotions} συναισθήματα.`);
        }
        fail('');
        btn.setAttribute('aria-pressed', String(index < 0));
        renderBefore();
        updateMood();
        updateRight();
    }));

    $('nb-event').addEventListener('input', updateRight);

    function lock() {
        state.locked = true;
        root.classList.add('is-locked');
        root.querySelectorAll('#nb-event, #nb-thought, #nb-mood').forEach(area => { area.readOnly = true; });
        feelings.forEach(btn => { btn.disabled = true; });
        $('nb-before').querySelectorAll('input').forEach(range => { range.disabled = true; });
    }

    function waiting() {
        const dots = element('p', 'sticker__dots');
        dots.setAttribute('aria-label', 'Η σημείωση γράφεται');
        dots.append(element('span'), element('span'), element('span'));
        return stick(dots).then(() => margin.setAttribute('aria-busy', 'true'));
    }

    $('nb-suggest').addEventListener('click', async () => {
        const event = $('nb-event').value.trim();
        const thought = $('nb-thought').value.trim();
        if (!event) return fail('Γράψε λίγα λόγια για το τι έγινε.');
        if (!state.chosen.length) return fail('Διάλεξε τουλάχιστον ένα συναίσθημα.');
        if (words(thought) < minWords) {
            focusOn($('nb-thought'));
            return fail('Μπορείς να τη γράψεις λίγο πιο ολόκληρη;');
        }

        fail('');
        const trigger = $('nb-suggest');
        trigger.disabled = true;
        const before = levels($('nb-before'));
        const [result] = await Promise.all([
            window.api.post('api/thought-records/suggest', {
                event,
                emotions: state.chosen.map(name => ({ emotion: name, before: before[name] })),
                thought,
                thought_mood: $('nb-mood').value.trim(),
            }),
            waiting().then(() => show($('nb-suggest-row'), false)),
        ]);
        trigger.disabled = false;
        if (!result.ok) {
            await unstick();
            show($('nb-suggest-row'));
            return fail(result.data.error || 'Κάτι πήγε στραβά. Δοκίμασε ξανά.');
        }

        lock();
        const data = result.data.data;
        if (data.crisis) return crisis(result.data.support);

        state.token = data.token;
        state.data = data;
        $('nb-feel').textContent = data.feel;
        if (data.mode === 'confident') return note(data.note);
        if (data.mode === 'offer') return offer();
        if (data.mode === 'ask') return ask(0);
        if (data.mode === 'none') return nothing();
        await unstick();
        friendPath();
    });

    async function crisis(support) {
        await unstick();
        window.support.render(support);
        [part('fair'), part('after')].forEach(el => show(el, false));
        closed('Η σελίδα σου κρατήθηκε. Πριν από οτιδήποτε άλλο, μίλησε με κάποιον από τις γραμμές βοήθειας.');
        $('support').scrollIntoView({ behavior: calm.matches ? 'auto' : 'smooth', block: 'start' });
    }

    function offer() {
        const sentence = element('p', 'margin__text', 'Δεν ξεχωρίζω κάτι συγκεκριμένο στον τόνο της σκέψης σου, αλλά μπορεί κάτι να μου ξεφεύγει.');
        const choices = element('div', 'margin__choices');
        choices.append(
            button('Ναι', 'btn', () => {
                state.response = 'yes';
                ask(0);
            }),
            button('Όχι τώρα', 'btn btn--quiet', () => {
                state.response = 'not_now';
                settle(...sentence.childNodes);
                closePath();
            }),
        );
        return stick(sentence, element('p', 'margin__text', 'Θέλεις να σε ρωτήσω ένα-δυο πράγματα;'), choices);
    }

    function ask(index) {
        const question = state.data.questions[index];
        const offered = state.data.mode === 'offer';
        const prompt = element('p', 'margin__question', question.ask);
        prompt.id = `nb-ask-${index}`;
        const group = element('div', 'margin__choices');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-labelledby', prompt.id);
        const answer = value => () => {
            state.answers.push(value);
            if (value === 'trap') return note(question);
            if (index + 1 < state.data.questions.length) return ask(index + 1);
            if (!offered) return nothing();
            settle('Δεν ξεχωρίζω κάποια συγκεκριμένη παγίδα στον τόνο της σκέψης σου.');
            friendPath();
        };
        group.append(
            ...question.options.map(option => button(option.label, 'btn btn--quiet margin__choice', answer(option.answer))),
            button('Δεν ξέρω', 'margin__link', answer('unknown')),
        );
        return stick(
            element('p', 'margin__text', index > 0 ? 'Μία ακόμα ερώτηση:' : (offered ? 'Πρώτη ερώτηση:' : 'Μια ερώτηση πριν σημειώσω κάτι:')),
            prompt,
            group,
        );
    }

    function note(trap) {
        const sentence = element('p', 'margin__text');
        sentence.append('Μου φαίνεται ότι ο τόνος της σκέψης σου μοιάζει με αυτό που λέμε ', mark(trap.quoted), `: ${lcfirst(trap.explanation)}`);
        const why = element('details', 'margin__why');
        why.append(
            element('summary', '', 'Γιατί το λέει αυτό;'),
            element('p', '', 'Το μοντέλο το καταλαβαίνει από τις λέξεις της σκέψης σου και μπορεί να κάνει λάθος. Αν δεν σου ταιριάζει, πάτησε «Όχι τώρα».'),
        );
        const choices = element('div', 'margin__choices');
        choices.append(
            button('Ναι, ας το κοιτάξουμε', 'btn', () => {
                state.response = 'yes';
                settle(mark(trap.quoted), '. Το κοιτάμε μαζί.');
                deepPath(trap);
            }),
            button('Όχι τώρα', 'btn btn--quiet', () => {
                state.response = 'not_now';
                settle(...sentence.childNodes);
                friendPath();
            }),
        );
        return stick(
            sentence,
            element('p', 'margin__text', 'Συμβαίνει σε όλους μας. Θέλεις να το κοιτάξουμε λίγο;'),
            choices,
            why,
        );
    }

    function nothing() {
        const sentence = element('p', 'margin__text', 'Δεν ξεχωρίζω κάποια συγκεκριμένη παγίδα στον τόνο της σκέψης σου. Θέλεις να την κοιτάξουμε λίγο ακόμα;');
        const choices = element('div', 'margin__choices');
        choices.append(
            button('Ναι', 'btn', () => {
                state.response = 'yes';
                settle(...sentence.childNodes);
                friendPath();
            }),
            button('Όχι τώρα', 'btn btn--quiet', () => {
                state.response = 'not_now';
                settle(...sentence.childNodes);
                closePath();
            }),
        );
        return stick(sentence, choices);
    }

    async function settle(...content) {
        const line = element('p', 'margin__text');
        line.append(...content);
        state.busy = true;
        await animate(margin, 'is-out', 180);
        margin.replaceChildren(line);
        await animate(margin, 'is-in', 340);
        state.busy = false;
    }

    function openAfter() {
        const container = $('nb-after');
        const before = levels($('nb-before'));
        container.replaceChildren();
        state.chosen.forEach(name => {
            level(container, name, before[name], before[name]).addEventListener('input', closing);
        });
        reveal(part('after'));
        reveal($('nb-close'));
        closing();
    }

    function closing() {
        const before = levels($('nb-before'));
        const after = levels($('nb-after'));
        const name = strongest(before);
        if (!name) return;
        const fell = after[name] < before[name];
        $('nb-closing').textContent = `Ξεκίνησες με ${feeling(name).dataset.with} στο ${before[name]} και τώρα είσαι στο ${after[name]}. `
            + (fell ? 'Μπράβο που έκατσες με τη σκέψη σου.' : 'Μερικές φορές τα συναισθήματα θέλουν χρόνο. Αυτό που έκανες μετράει.');
    }

    function deepPath(trap) {
        state.path = 'deep';
        state.trap = trap;
        $('nb-trap-question').textContent = trap.question;
        show(part('friend'), false);
        reveal($('nb-deep'), $('nb-deep-answer'));
        reveal(part('fair'));
        openAfter();
    }

    function friendPath() {
        state.path = 'friend';
        show($('nb-deep'), false);
        show(part('fair'), false);
        reveal(part('friend'), $('nb-friend'));
        openAfter();
    }

    function closePath() {
        state.path = null;
        show($('nb-deep'), false);
        show(part('fair'), false);
        show(part('friend'), false);
        openAfter();
        focusOn($('nb-after').querySelector('input'), part('after'));
    }

    $('nb-reject').addEventListener('click', () => {
        if (state.busy) return;
        state.response = 'rejected';
        settle(mark(state.trap.quoted), '.');
        $('nb-deep-answer').value = '';
        $('nb-fair').value = '';
        friendPath();
    });

    $('nb-save').addEventListener('click', async () => {
        const trigger = $('nb-save');
        trigger.disabled = true;
        const result = await window.api.post('api/thought-records', {
            token: state.token,
            answers: state.answers,
            response: state.response,
            reframe: state.path === 'deep' ? $('nb-deep-answer').value.trim() : (state.path === 'friend' ? $('nb-friend').value.trim() : ''),
            balanced_thought: state.path === 'deep' ? $('nb-fair').value.trim() : '',
            after: levels($('nb-after')),
        });
        trigger.disabled = false;
        if (!result.ok) return fail(result.data.error || 'Δεν αποθηκεύτηκε. Δοκίμασε ξανά.');

        const data = result.data.data;
        window.support.render(result.data.support);
        root.querySelectorAll('#nb-deep-answer, #nb-friend, #nb-fair').forEach(area => { area.readOnly = true; });
        $('nb-after').querySelectorAll('input').forEach(range => { range.disabled = true; });
        show($('nb-close'), false);
        closed(data.crisis
            ? 'Αυτό που έγραψες ακούγεται βαρύ. Πριν από οτιδήποτε άλλο, μίλησε με κάποιον από τις γραμμές βοήθειας.'
            : $('nb-closing').textContent);
        if (result.data.support) $('support').scrollIntoView({ behavior: calm.matches ? 'auto' : 'smooth', block: 'start' });
    });

    function closed(text) {
        fail('');
        root.classList.add('is-closed');
        $('nb-closed-date').textContent = `Έκλεισε · ${$('nb-date').textContent}`;
        $('nb-closed-text').textContent = text;
        reveal($('nb-closed'), $('nb-closed'));
    }
})();
