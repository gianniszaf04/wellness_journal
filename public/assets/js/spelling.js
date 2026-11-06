window.spelling = (() => {
    const $ = id => document.getElementById(id);
    const length = text => [...text].length;
    let entryId = null;
    let items = [];
    let onChange = null;

    function setStatus(text) {
        const status = $('spelling-status');
        if (status) status.textContent = text;
    }

    function setBusy(busy) {
        $('spelling')?.querySelectorAll('button').forEach(btn => { btn.disabled = busy; });
    }

    function button(label, className, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = className;
        btn.textContent = label;
        btn.addEventListener('click', onClick);
        return btn;
    }

    function draw() {
        const box = $('spelling');
        if (!box) return;
        const list = $('spelling-list');
        list.replaceChildren();
        for (const item of items) {
            const li = document.createElement('li');
            li.className = 'spelling__item';
            const word = document.createElement('span');
            word.className = 'spelling__word';
            word.textContent = item.word;
            const choices = document.createElement('div');
            choices.className = 'spelling__choices';
            for (const suggestion of item.suggestions) {
                choices.append(button(suggestion, 'spelling__choice', () => apply(item, suggestion)));
            }
            choices.append(button('Είναι σωστό', 'spelling__keep', () => keep(item.word)));
            li.append(word, choices);
            list.append(li);
        }
        box.hidden = items.length === 0;
    }

    function render(id, list, changed) {
        entryId = id;
        items = Array.isArray(list) ? list : [];
        onChange = changed || null;
        setStatus('');
        draw();
    }

    async function apply(item, replacement) {
        setBusy(true);
        setStatus('');
        const result = await window.api.post(`api/entries/${entryId}/spelling`, {
            word: item.word, start: item.start, end: item.end, replacement,
        });
        setBusy(false);

        if (!result.ok) {
            setStatus(result.data.error || 'Η λέξη δεν άλλαξε. Δοκίμασε ξανά.');
            return;
        }

        onChange?.(result.data);
        if (result.data.ai_available === false) {
            const shift = length(replacement) - (item.end - item.start);
            items = items
                .filter(other => other !== item)
                .map(other => other.start > item.start ? { ...other, start: other.start + shift, end: other.end + shift } : other);
        } else {
            items = Array.isArray(result.data.spelling) ? result.data.spelling : [];
        }
        draw();
        setStatus(`Έγινε «${replacement}».`);
    }

    async function keep(word) {
        setBusy(true);
        setStatus('');
        const result = await window.api.post('api/words', { word });
        setBusy(false);

        if (!result.ok) {
            setStatus(result.data.error || 'Δεν το κράτησα. Δοκίμασε ξανά.');
            return;
        }

        items = items.filter(item => item.word.toLowerCase() !== word.toLowerCase());
        draw();
        setStatus(`Κράτησα το «${word}», δεν θα σε ξαναρωτήσω.`);
    }

    document.addEventListener('DOMContentLoaded', () => {
        $('spelling-later')?.addEventListener('click', () => render(null, []));
    });

    return { render };
})();
