(() => {
    const preferred = document.querySelector('meta[name="theme-pref"]')?.content || '';
    try {
        document.documentElement.dataset.theme = localStorage.getItem('theme')
            || preferred
            || (matchMedia('(prefers-color-scheme: dark)').matches ? 'night' : 'day');
    } catch (e) {
        document.documentElement.dataset.theme = preferred || 'day';
    }
})();
