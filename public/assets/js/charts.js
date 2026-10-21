(() => {
    const NS = 'http://www.w3.org/2000/svg';
    const MONTHS = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μαΐ', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];
    const fmt = v => Number(v).toLocaleString('el-GR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    const day = iso => new Date(`${iso}T12:00:00`);
    const shortDate = iso => { const d = day(iso); return `${d.getDate()} ${MONTHS[d.getMonth()]}`; };

    function el(name, attrs = {}, parent) {
        const node = document.createElementNS(NS, name);
        for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
        if (parent) parent.append(node);
        return node;
    }

    function text(parent, x, y, value, cls, anchor = 'middle') {
        const t = el('text', { x, y, class: cls, 'text-anchor': anchor }, parent);
        t.textContent = value;
        return t;
    }

    function roundedTop(x, y, w, h, r) {
        r = Math.min(r, w / 2, h);
        return `M${x} ${y + h} V${y + r} Q${x} ${y} ${x + r} ${y} H${x + w - r} Q${x + w} ${y} ${x + w} ${y + r} V${y + h} Z`;
    }

    function roundedEnd(x, y, w, h, r) {
        r = Math.min(r, h / 2, w);
        return `M${x} ${y} H${x + w - r} Q${x + w} ${y} ${x + w} ${y + r} V${y + h - r} Q${x + w} ${y + h} ${x + w - r} ${y + h} H${x} Z`;
    }

    function tooltip(container) {
        const tip = document.createElement('div');
        tip.className = 'chart-tip';
        tip.hidden = true;
        container.append(tip);
        return {
            show(x, y, lead, rest) {
                tip.replaceChildren();
                const strong = document.createElement('b');
                strong.textContent = lead;
                tip.append(strong);
                for (const line of rest) {
                    const span = document.createElement('span');
                    span.textContent = line;
                    tip.append(span);
                }
                tip.hidden = false;
                const box = container.getBoundingClientRect();
                const w = tip.offsetWidth;
                const left = Math.min(Math.max(x - w / 2, 0), box.width - w);
                tip.style.left = `${left}px`;
                tip.style.top = `${Math.max(y - tip.offsetHeight - 12, 0)}px`;
            },
            hide() { tip.hidden = true; },
        };
    }

    function grid(svg, left, right, y, ticks) {
        for (const value of ticks) {
            const yy = y(value);
            el('line', { x1: left, x2: right, y1: yy, y2: yy, class: 'chart-grid' }, svg);
            text(svg, left - 8, yy + 4, String(value), 'chart-tick', 'end');
        }
    }

    function line(container, points, start, end) {
        const width = container.clientWidth;
        const height = 250;
        const m = { top: 18, right: 44, bottom: 30, left: 34 };
        const t0 = day(start).getTime();
        const t1 = Math.max(day(end).getTime(), t0 + 86400000);
        const x = iso => m.left + (day(iso).getTime() - t0) / (t1 - t0) * (width - m.left - m.right);
        const y = v => m.top + (1 - v / 10) * (height - m.top - m.bottom);

        const svg = el('svg', { width, height, viewBox: `0 0 ${width} ${height}` });
        grid(svg, m.left, width - m.right, y, [0, 5, 10]);

        const ticks = 4;
        for (let i = 0; i <= ticks; i++) {
            const t = new Date(t0 + (t1 - t0) * i / ticks);
            const iso = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
            text(svg, x(iso), height - 8, shortDate(iso), 'chart-tick', i === 0 ? 'start' : i === ticks ? 'end' : 'middle');
        }

        const coords = points.map(p => [x(p.date), y(p.value)]);
        if (coords.length > 1) {
            const path = coords.map(([cx, cy], i) => `${i ? 'L' : 'M'}${cx.toFixed(1)} ${cy.toFixed(1)}`).join(' ');
            el('path', { d: `${path} L${coords.at(-1)[0].toFixed(1)} ${y(0)} L${coords[0][0].toFixed(1)} ${y(0)} Z`, class: 'chart-area' }, svg);
            el('path', { d: path, class: 'chart-line' }, svg);
        }

        const cross = el('line', { y1: m.top, y2: y(0), class: 'chart-cross', visibility: 'hidden' }, svg);
        const pxPerDay = (width - m.left - m.right) / Math.max(1, (t1 - t0) / 86400000);
        const restR = i => (pxPerDay >= 10 || i === points.length - 1 ? 4.5 : 0);
        const dots = points.map((p, i) => el('circle', {
            cx: coords[i][0], cy: coords[i][1], r: restR(i), class: 'chart-dot', style: `fill: var(--mood-${p.level || 3})`,
        }, svg));

        const last = points.at(-1);
        text(svg, Math.min(coords.at(-1)[0] + 10, width - 4), coords.at(-1)[1] + 4, fmt(last.value), 'chart-value', coords.at(-1)[0] + 10 > width - 30 ? 'end' : 'start');

        const hit = el('rect', { x: m.left, y: 0, width: width - m.left - m.right, height, class: 'chart-hit', tabindex: 0 }, svg);
        hit.setAttribute('aria-label', 'Μετακίνησε με τα βελάκια για να δεις κάθε μέρα');
        container.replaceChildren(svg);
        const tip = tooltip(container);
        let active = -1;

        const select = i => {
            if (active >= 0) dots[active].setAttribute('r', restR(active));
            active = i;
            const p = points[i];
            const [cx, cy] = coords[i];
            dots[i].setAttribute('r', 6.5);
            cross.setAttribute('x1', cx);
            cross.setAttribute('x2', cx);
            cross.setAttribute('visibility', 'visible');
            tip.show(cx, cy, `${fmt(p.value)} · ${p.mood}`, [p.label, p.count === 1 ? '1 σελίδα' : `${p.count} σελίδες`]);
        };
        const clear = () => {
            if (active >= 0) dots[active].setAttribute('r', restR(active));
            active = -1;
            cross.setAttribute('visibility', 'hidden');
            tip.hide();
        };

        hit.addEventListener('pointermove', event => {
            const box = svg.getBoundingClientRect();
            const px = event.clientX - box.left;
            let best = 0;
            coords.forEach(([cx], i) => { if (Math.abs(cx - px) < Math.abs(coords[best][0] - px)) best = i; });
            select(best);
        });
        hit.addEventListener('pointerleave', clear);
        hit.addEventListener('blur', clear);
        hit.addEventListener('focus', () => select(points.length - 1));
        hit.addEventListener('keydown', event => {
            if (event.key === 'ArrowLeft') { event.preventDefault(); select(Math.max(0, active - 1)); }
            if (event.key === 'ArrowRight') { event.preventDefault(); select(Math.min(points.length - 1, active + 1)); }
        });
    }

    function columns(container, points) {
        const width = container.clientWidth;
        const height = 220;
        const m = { top: 24, right: 8, bottom: 28, left: 30 };
        const band = (width - m.left - m.right) / points.length;
        const barW = Math.min(24, band * .5);
        const y = v => m.top + (1 - v / 10) * (height - m.top - m.bottom);
        const best = points.reduce((b, p, i) => (p.value !== null && (b < 0 || p.value > points[b].value) ? i : b), -1);

        const svg = el('svg', { width, height, viewBox: `0 0 ${width} ${height}` });
        grid(svg, m.left, width - m.right, y, [0, 5, 10]);
        container.replaceChildren(svg);
        const tip = tooltip(container);

        points.forEach((p, i) => {
            const cx = m.left + band * i + band / 2;
            text(svg, cx, height - 8, p.label, 'chart-tick');
            if (p.value === null) {
                text(svg, cx, y(0) - 6, '–', 'chart-tick');
                return;
            }
            const top = y(p.value);
            const bar = el('path', { d: roundedTop(cx - barW / 2, top, barW, y(0) - top, 4), class: i === best ? 'chart-bar' : 'chart-bar chart-bar--muted' }, svg);
            text(svg, cx, top - 6, fmt(p.value), 'chart-value');
            const hit = el('rect', { x: cx - band / 2, y: m.top - 20, width: band, height: y(0) - m.top + 20, class: 'chart-hit', tabindex: 0 }, svg);
            hit.setAttribute('aria-label', `${p.name}: ${fmt(p.value)}`);
            const show = () => { bar.classList.add('is-hover'); tip.show(cx, top, fmt(p.value), [p.name, p.count === 1 ? 'από 1 μέρα' : `από ${p.count} μέρες`]); };
            const hide = () => { bar.classList.remove('is-hover'); tip.hide(); };
            hit.addEventListener('pointerenter', show);
            hit.addEventListener('pointerleave', hide);
            hit.addEventListener('focus', show);
            hit.addEventListener('blur', hide);
        });
    }

    function bars(container, points) {
        const width = container.clientWidth;
        const row = 34;
        const labelW = Math.min(130, width * .36);
        const valueW = 34;
        const height = points.length * row + 6;
        const max = Math.max(...points.map(p => p.value), 1);
        const total = points.reduce((s, p) => s + p.value, 0);
        const w = v => v / max * (width - labelW - valueW - 8);

        const svg = el('svg', { width, height, viewBox: `0 0 ${width} ${height}` });
        el('line', { x1: labelW, x2: labelW, y1: 0, y2: height, class: 'chart-grid' }, svg);
        container.replaceChildren(svg);
        const tip = tooltip(container);

        points.forEach((p, i) => {
            const cy = i * row + row / 2 + 3;
            text(svg, labelW - 10, cy + 5, p.label, 'chart-label', 'end');
            const bw = Math.max(w(p.value), 3);
            const bar = el('path', { d: roundedEnd(labelW, cy - 9, bw, 18, 4), class: 'chart-bar' }, svg);
            text(svg, labelW + bw + 8, cy + 5, String(p.value), 'chart-value', 'start');
            const hit = el('rect', { x: 0, y: cy - row / 2, width, height: row, class: 'chart-hit', tabindex: 0 }, svg);
            const pct = total ? Math.round(p.value / total * 100) : 0;
            hit.setAttribute('aria-label', `${p.label}: ${p.value} σελίδες`);
            const show = () => { bar.classList.add('is-hover'); tip.show(labelW + bw / 2, cy - 9, `${p.value} ${p.value === 1 ? 'σελίδα' : 'σελίδες'}`, [p.label, `${pct}% της περιόδου`]); };
            const hide = () => { bar.classList.remove('is-hover'); tip.hide(); };
            hit.addEventListener('pointerenter', show);
            hit.addEventListener('pointerleave', hide);
            hit.addEventListener('focus', show);
            hit.addEventListener('blur', hide);
        });
    }

    const renderers = { line, columns, bars };

    document.querySelectorAll('[data-chart]').forEach(container => {
        let points = [];
        try { points = JSON.parse(container.dataset.points || '[]'); } catch (e) { return; }
        if (!points.length) return;
        const render = () => renderers[container.dataset.chart](container, points, container.dataset.start, container.dataset.end);
        let lastWidth = 0;
        new ResizeObserver(() => {
            if (Math.abs(container.clientWidth - lastWidth) < 2) return;
            lastWidth = container.clientWidth;
            render();
        }).observe(container);
    });
})();
