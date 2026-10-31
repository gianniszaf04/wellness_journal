window.sound = (() => {
    const $ = id => document.getElementById(id);
    const box = $('sound');
    const toggle = $('sound-toggle');
    if (!box || !toggle) return { pause() { } };

    const kind = box.dataset.sound;
    const volume = $('sound-volume');
    const status = $('sound-status');
    const breath = $('breath');
    const breathLabel = $('breath-label');
    const VOLUME_KEY = 'sound-volume';
    const FADE = .8;
    const XFADE = 4;
    const INHALE = 4;
    const EXHALE = 6;

    try {
        const saved = localStorage.getItem(VOLUME_KEY);
        if (saved !== null && !Number.isNaN(Number(saved))) volume.value = saved;
    } catch (e) {
    }

    let ctx = null;
    let master = null;
    let stopSound = null;
    let playing = false;
    const buffers = new Map();

    const level = () => Number(volume.value) ** 2;

    const curve = up => {
        const points = new Float32Array(64);
        for (let i = 0; i < points.length; i++) {
            const t = i / (points.length - 1) * Math.PI / 2;
            points[i] = up ? Math.sin(t) : Math.cos(t);
        }
        return points;
    };
    const FADE_IN = curve(true);
    const FADE_OUT = curve(false);

    const brownNoise = seconds => {
        const length = Math.floor(ctx.sampleRate * seconds);
        const overlap = Math.floor(ctx.sampleRate * .5);
        const buffer = ctx.createBuffer(2, length, ctx.sampleRate);
        const raw = new Float32Array(length + overlap);
        for (let c = 0; c < 2; c++) {
            let last = 0;
            for (let i = 0; i < raw.length; i++) {
                last = (last + .02 * (Math.random() * 2 - 1)) / 1.02;
                raw[i] = last * 3.5;
            }
            const data = buffer.getChannelData(c);
            for (let i = 0; i < length; i++) {
                if (i < overlap) {
                    const t = i / overlap * Math.PI / 2;
                    data[i] = raw[i] * Math.sin(t) + raw[length + i] * Math.cos(t);
                } else {
                    data[i] = raw[i];
                }
            }
        }
        return buffer;
    };

    const loopNoise = seconds => {
        const source = ctx.createBufferSource();
        source.buffer = brownNoise(seconds);
        source.loop = true;
        return source;
    };

    const load = async url => {
        if (!buffers.has(url)) {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            buffers.set(url, await ctx.decodeAudioData(await response.arrayBuffer()));
        }
        return buffers.get(url);
    };

    const drift = (buffer, out, min, max) => {
        const sources = new Set();
        let timer;
        const next = at => {
            const length = Math.min(buffer.duration, min + Math.random() * (max - min));
            const offset = Math.random() * (buffer.duration - length);
            const source = ctx.createBufferSource();
            const gain = ctx.createGain();
            source.buffer = buffer;
            gain.gain.value = 0;
            gain.gain.setValueCurveAtTime(FADE_IN, at, XFADE);
            gain.gain.setValueCurveAtTime(FADE_OUT, at + length - XFADE, XFADE);
            source.connect(gain).connect(out);
            source.onended = () => {
                sources.delete(source);
                gain.disconnect();
            };
            source.start(at, offset, length);
            sources.add(source);
            const following = at + length - XFADE;
            timer = setTimeout(() => next(following), Math.max(0, (following - ctx.currentTime - 2) * 1000));
        };
        next(ctx.currentTime + .05);
        return () => {
            clearTimeout(timer);
            sources.forEach(source => source.stop());
            sources.clear();
        };
    };

    const ramp = (param, now, values) => {
        param.cancelScheduledValues(now);
        param.setValueAtTime(param.value, now);
        values.forEach(([value, at]) => param.linearRampToValueAtTime(value, now + at));
    };

    const breathe = (phase, seconds = 0) => {
        if (!breath) return;
        breath.style.setProperty('--breath-time', `${seconds}s`);
        breath.classList.toggle('is-in', phase === 'in');
        breathLabel.textContent = phase === 'in' ? 'Εισπνοή' : phase === 'out' ? 'Εκπνοή' : 'Πάτα «Άκουσε» για να ξεκινήσεις';
    };

    const sounds = {
        soft(out) {
            const source = loopNoise(8);
            const filter = ctx.createBiquadFilter();
            const gain = ctx.createGain();
            filter.type = 'lowpass';
            filter.frequency.value = 900;
            gain.gain.value = .45;
            source.connect(filter).connect(gain).connect(out);
            source.start();
            return () => source.stop();
        },

        calm(out) {
            const source = loopNoise(8);
            const filter = ctx.createBiquadFilter();
            const gain = ctx.createGain();
            filter.type = 'lowpass';
            filter.frequency.value = 350;
            gain.gain.value = .08;
            source.connect(filter).connect(gain).connect(out);
            source.start();

            let timer;
            const cycle = () => {
                const now = ctx.currentTime;
                ramp(gain.gain, now, [[.55, INHALE], [.08, INHALE + EXHALE]]);
                ramp(filter.frequency, now, [[1400, INHALE], [350, INHALE + EXHALE]]);
                breathe('in', INHALE);
                timer = setTimeout(() => {
                    breathe('out', EXHALE);
                    timer = setTimeout(cycle, EXHALE * 1000);
                }, INHALE * 1000);
            };
            cycle();
            return () => {
                clearTimeout(timer);
                source.stop();
                if (!playing) breathe(null);
            };
        },

        async nature(out) {
            const [stream, wind] = await Promise.all([load(box.dataset.stream), load(box.dataset.wind)]);
            const water = ctx.createGain();
            const air = ctx.createGain();
            water.gain.value = .7;
            air.gain.value = .3;
            water.connect(out);
            air.connect(out);
            const stops = [drift(stream, water, 25, 50), drift(wind, air, 15, 30)];

            let timer;
            const sway = () => {
                air.gain.setTargetAtTime(.1 + Math.random() * .35, ctx.currentTime, 4);
                timer = setTimeout(sway, 8000 + Math.random() * 10000);
            };
            sway();
            return () => {
                clearTimeout(timer);
                stops.forEach(stop => stop());
            };
        },
    };

    const fade = target => {
        const now = ctx.currentTime;
        master.gain.cancelScheduledValues(now);
        master.gain.setValueAtTime(master.gain.value, now);
        master.gain.setTargetAtTime(target, now, FADE / 3);
    };

    const render = () => {
        toggle.textContent = playing ? 'Παύση' : 'Άκουσε';
        toggle.setAttribute('aria-pressed', String(playing));
    };

    async function play() {
        if (!ctx) {
            ctx = new (window.AudioContext || window.webkitAudioContext)();
            master = ctx.createGain();
            master.gain.value = 0;
            master.connect(ctx.destination);
        }
        const resumed = ctx.resume();
        playing = true;
        render();
        toggle.disabled = true;
        if (kind === 'nature' && buffers.size < 2) status.textContent = 'Φορτώνει…';

        try {
            await resumed;
            const stop = await sounds[kind](master);
            if (!playing) {
                stop();
                status.textContent = '';
                return;
            }
            stopSound = stop;
        } catch (error) {
            playing = false;
            render();
            status.textContent = 'Δεν μπόρεσα να ξεκινήσω τον ήχο. Δοκίμασε ξανά.';
            return;
        } finally {
            toggle.disabled = false;
        }

        status.textContent = '';
        fade(level());
    }

    function pause() {
        if (!playing) return;
        playing = false;
        render();
        fade(0);
        const stop = stopSound;
        stopSound = null;
        setTimeout(() => {
            stop?.();
            if (!playing) ctx.suspend();
        }, FADE * 1000 + 100);
    }

    toggle.addEventListener('click', () => (playing ? pause() : play()));

    volume.addEventListener('input', () => {
        if (playing && stopSound) master.gain.setTargetAtTime(level(), ctx.currentTime, .05);
        try {
            localStorage.setItem(VOLUME_KEY, volume.value);
        } catch (e) {
        }
    });

    return { pause };
})();
