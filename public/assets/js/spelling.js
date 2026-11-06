(() => {
    const WAIT = 400;
    const ENDED_WORD = /([\p{L}\p{M}\p{N}'’-]+)([^\p{L}\p{M}\p{N}'’-]{1,3})$/u;
    const length = text => [...text].length;

    function attach(textarea, bar) {
        let timer = null;
        let controller = null;
        let quiet = false;

        function clear() {
            bar.replaceChildren();
            bar.classList.remove('is-open');
        }

        function stop() {
            clearTimeout(timer);
            if (controller) controller.abort();
            controller = null;
        }

        function caretAt() {
            return length(textarea.value.slice(0, textarea.selectionStart));
        }

        function button(label, className, onClick) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = className;
            btn.textContent = label;
            btn.addEventListener('click', onClick);
            return btn;
        }

        function show(item) {
            const word = document.createElement('span');
            word.className = 'suggest__word';
            word.textContent = `${item.word} →`;
            const keep = button('✓', 'suggest__keep', () => keepWord(item.word));
            keep.title = 'Είναι σωστό';
            keep.setAttribute('aria-label', `Το «${item.word}» είναι σωστό`);
            bar.replaceChildren(word, ...item.suggestions.map(s => button(s, 'suggest__choice', () => replace(item, s))), keep);
            bar.classList.add('is-open');
        }

        function replace(item, suggestion) {
            const chars = [...textarea.value];
            clear();
            if (chars.slice(item.start, item.end).join('') !== item.word) return;

            const caret = caretAt();
            const next = [...chars.slice(0, item.start), ...suggestion, ...chars.slice(item.end)];
            if (textarea.maxLength > 0 && next.join('').length > textarea.maxLength) return;
            const shift = length(suggestion) - (item.end - item.start);
            const position = next.slice(0, caret >= item.end ? caret + shift : caret).join('').length;

            textarea.value = next.join('');
            textarea.focus();
            textarea.setSelectionRange(position, position);
            quiet = true;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            quiet = false;
        }

        async function keepWord(word) {
            clear();
            textarea.focus();
            await window.api.post('api/words', { word });
        }

        async function check(text, wordEnd, caret) {
            controller = new AbortController();
            let result;
            try {
                result = await window.api.post('api/spelling', { text }, { signal: controller.signal });
            } catch (error) {
                return;
            }
            if (!result.ok || document.activeElement !== textarea || textarea.value !== text || caretAt() !== caret) return;
            const item = (result.data.data || []).find(entry => entry.end === wordEnd);
            if (item) show(item);
        }

        textarea.addEventListener('input', () => {
            stop();
            clear();
            if (quiet || textarea.selectionStart !== textarea.selectionEnd) return;

            const match = textarea.value.slice(0, textarea.selectionStart).match(ENDED_WORD);
            if (!match) return;

            const caret = caretAt();
            const wordEnd = caret - length(match[2]);
            timer = setTimeout(() => check(textarea.value, wordEnd, caret), WAIT);
        });

        textarea.addEventListener('keydown', event => {
            if (event.key === 'Escape' && bar.classList.contains('is-open')) {
                event.stopImmediatePropagation();
                clear();
            }
        });

        textarea.addEventListener('blur', () => setTimeout(() => {
            if (document.activeElement !== textarea) clear();
        }, 200));
        textarea.form?.addEventListener('submit', () => {
            stop();
            clear();
        });
        bar.addEventListener('mousedown', event => event.preventDefault());
    }

    document.querySelectorAll('[data-suggest-for]').forEach(bar => {
        const textarea = document.getElementById(bar.dataset.suggestFor);
        if (textarea) attach(textarea, bar);
    });
})();
