window.api = (() => {
    const meta = name => document.querySelector(`meta[name="${name}"]`)?.content ?? '';
    const base = meta('app-url');

    const url = path => `${base}/${String(path).replace(/^\/+/, '')}`;

    async function request(path, { method = 'GET', body, signal } = {}) {
        const headers = { 'Accept': 'application/json', 'X-CSRF-Token': meta('csrf-token') };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        let response;
        try {
            response = await fetch(url(path), {
                method,
                headers,
                body: body !== undefined ? JSON.stringify(body) : undefined,
                credentials: 'same-origin',
                signal,
            });
        } catch (error) {
            if (error.name === 'AbortError') throw error;
            return { ok: false, status: 0, data: { error: 'Δεν υπάρχει σύνδεση. Δοκίμασε ξανά σε λίγο.' } };
        }

        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            data = null;
        }

        return { ok: response.ok && data?.success !== false, status: response.status, data: data ?? {} };
    }

    return {
        url,
        get: (path, options = {}) => request(path, { ...options, method: 'GET' }),
        post: (path, body, options = {}) => request(path, { ...options, method: 'POST', body }),
        patch: (path, body, options = {}) => request(path, { ...options, method: 'PATCH', body }),
        delete: (path, options = {}) => request(path, { ...options, method: 'DELETE' }),
    };
})();

window.mood = {
    level(score) {
        if (score === null || score === undefined || Number.isNaN(Number(score))) return 0;
        const s = Number(score);
        if (s >= .6) return 5;
        if (s >= .2) return 4;
        if (s > -.2) return 3;
        if (s > -.6) return 2;
        return 1;
    },
    label(level) {
        return ['', 'Πολύ δύσκολα', 'Δύσκολα', 'Ουδέτερα', 'Καλά', 'Πολύ καλά'][level] ?? '';
    },
};

window.support = {
    render(data) {
        const box = document.getElementById('support');
        if (!box) return;
        const lines = document.getElementById('support-lines');
        lines.replaceChildren();
        box.hidden = !data;
        if (!data) return;
        box.classList.toggle('support--crisis', data.level === 'crisis');
        document.getElementById('support-title').textContent = data.title;
        document.getElementById('support-text').textContent = data.message;
        for (const line of data.helplines || []) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.className = 'support__number';
            link.href = `tel:${line.number}`;
            link.textContent = line.number;
            const info = document.createElement('div');
            const name = document.createElement('b');
            name.textContent = line.name;
            const desc = document.createElement('span');
            desc.textContent = [line.available, line.description].filter(Boolean).join(' · ');
            info.append(name, desc);
            item.append(link, info);
            lines.append(item);
        }
    },
};
