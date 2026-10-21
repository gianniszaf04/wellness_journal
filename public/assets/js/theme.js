document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
        const root = document.documentElement;
        const next = root.dataset.theme === 'night' ? 'day' : 'night';
        const apply = () => {
            root.dataset.theme = next;
        };

        const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (document.startViewTransition && !reduceMotion) {
            document.startViewTransition(apply);
        } else {
            apply();
        }

        try {
            localStorage.setItem('theme', next);
        } catch (e) {
        }
    });
});
