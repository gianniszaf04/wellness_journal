(() => {
    const status = document.getElementById('theme-status');
    const toTheme = { light_theme: 'day', dark_theme: 'night' };

    const apply = value => {
        const root = document.documentElement;
        const next = toTheme[value] || (matchMedia('(prefers-color-scheme: dark)').matches ? 'night' : 'day');
        try {
            if (toTheme[value]) {
                localStorage.setItem('theme', next);
            } else {
                localStorage.removeItem('theme');
            }
        } catch (e) {
        }
        if (root.dataset.theme === next) return;
        const swap = () => { root.dataset.theme = next; };
        if (document.startViewTransition && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.startViewTransition(swap);
        } else {
            swap();
        }
    };

    document.querySelectorAll('input[name="theme"]').forEach(input => {
        input.addEventListener('change', async () => {
            apply(input.value);
            status.textContent = 'Αποθήκευση…';
            const result = await window.api.patch('api/profile', { theme: input.value });
            status.textContent = result.ok ? 'Αποθηκεύτηκε.' : (result.data.error || 'Δεν αποθηκεύτηκε. Δοκίμασε ξανά.');
        });
    });

    const dialog = document.getElementById('delete-account-dialog');
    document.getElementById('delete-account-btn')?.addEventListener('click', () => {
        dialog.showModal();
        document.getElementById('delete_password').focus();
    });
    dialog?.querySelector('[data-close]').addEventListener('click', () => dialog.close());
})();
