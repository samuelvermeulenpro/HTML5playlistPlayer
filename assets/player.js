(() => {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const TRACKS = JSON.parse($('tracks-data').textContent || '[]');
    const audio = $('audio');
    const items = Array.from(document.querySelectorAll('#playlist .track'));
    const ui = {
        cover: $('nowCover'), title: $('nowTitle'), artist: $('nowArtist'), album: $('nowAlbum'),
        seek: $('seek'), cur: $('curTime'), dur: $('durTime'),
        play: $('playBtn'), prev: $('prevBtn'), next: $('nextBtn'),
        shuffle: $('shuffleBtn'), repeat: $('repeatBtn'),
        mute: $('muteBtn'), volume: $('volume'),
        filter: $('filter'), info: $('listInfo'), noMatch: $('noMatch'),
    };
    const baseTitle = document.title;
    const STORE_KEY = 'player:' + location.pathname;

    let current = -1;
    let order = TRACKS.map((_, i) => i);   // ordre de lecture (aléatoire ou non)
    let shuffle = false;
    let repeat = 'off';                     // off | all | one
    let seeking = false;
    let pendingSeek = 0;

    /* ---------- Utilitaires ---------- */
    const fmt = (s) => {
        if (!isFinite(s) || s < 0) s = 0;
        s = Math.floor(s);
        const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = String(s % 60).padStart(2, '0');
        return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
    };
    const setFill = (range) => {
        const max = +range.max || 0;
        range.style.setProperty('--p', max ? (range.value / max * 100) + '%' : '0%');
    };
    const store = {
        get() { try { return JSON.parse(localStorage.getItem(STORE_KEY)) || {}; } catch { return {}; } },
        set(patch) { try { localStorage.setItem(STORE_KEY, JSON.stringify({ ...store.get(), ...patch })); } catch {} },
    };
    const isVisible = (i) => !items[i].hidden;

    /* ---------- Chargement / lecture ---------- */
    function load(i, autoplay = true, startAt = 0) {
        if (i < 0 || i >= TRACKS.length) return;
        const t = TRACKS[i];
        if (current >= 0) items[current].classList.remove('active');
        current = i;
        const li = items[i];
        li.classList.add('active');
        li.classList.remove('error');
        li.scrollIntoView({ block: 'nearest', behavior: autoplay ? 'smooth' : 'auto' });

        pendingSeek = startAt;
        audio.src = t.src;
        ui.seek.max = t.duration || 0;
        ui.seek.value = 0;
        setFill(ui.seek);
        ui.cur.textContent = fmt(0);
        ui.dur.textContent = fmt(t.duration);

        ui.cover.innerHTML = '';
        if (t.cover) {
            const img = new Image();
            img.src = t.cover; img.alt = '';
            ui.cover.appendChild(img);
        } else {
            ui.cover.innerHTML = '<svg class="ph" aria-hidden="true"><use href="#i-note"/></svg>';
        }
        ui.title.textContent = t.title;
        ui.artist.textContent = t.artist || 'Artiste non renseigné';
        ui.album.textContent = t.album;

        updateMediaSession(t);
        store.set({ id: t.id, time: startAt });
        if (autoplay) play();
    }

    function play() {
        if (current < 0) { if (TRACKS.length) load(order[0]); return; }
        const p = audio.play();
        if (p && p.catch) p.catch(() => { /* lecture bloquée par le navigateur : l'utilisateur cliquera */ });
    }
    const toggle = () => (audio.paused ? play() : audio.pause());

    /** Index suivant dans l'ordre de lecture, en sautant les pistes filtrées. */
    function step(dir, wrap) {
        if (!TRACKS.length) return -1;
        let pos = order.indexOf(current);
        for (let n = 0; n < order.length; n++) {
            pos += dir;
            if (pos >= order.length || pos < 0) {
                if (!wrap) return -1;
                pos = (pos + order.length) % order.length;
            }
            if (isVisible(order[pos])) return order[pos];
        }
        return -1;
    }
    function next(auto = false) {
        const i = step(1, repeat === 'all' || !auto);
        if (i >= 0) load(i);
    }
    function prev() {
        if (audio.currentTime > 3) { audio.currentTime = 0; return; }
        const i = step(-1, true);
        if (i >= 0) load(i);
    }

    /* ---------- Aléatoire / répétition ---------- */
    function setShuffle(on) {
        shuffle = on;
        ui.shuffle.setAttribute('aria-pressed', String(on));
        order = TRACKS.map((_, i) => i);
        if (on) {
            for (let i = order.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [order[i], order[j]] = [order[j], order[i]];
            }
            // la piste en cours devient la première de la nouvelle file
            if (current >= 0) { order.splice(order.indexOf(current), 1); order.unshift(current); }
        }
        store.set({ shuffle: on });
    }
    function setRepeat(mode) {
        repeat = mode;
        ui.repeat.dataset.mode = mode;
        ui.repeat.title = 'Répéter : ' + { off: 'non', all: 'toute la liste', one: 'cette piste' }[mode];
        audio.loop = mode === 'one';
        store.set({ repeat: mode });
    }

    /* ---------- Volume ---------- */
    function setVolume(v) {
        audio.volume = Math.min(1, Math.max(0, v));
        ui.volume.value = audio.volume;
        setFill(ui.volume);
        store.set({ volume: audio.volume });
    }
    function renderMute() {
        const off = audio.muted || audio.volume === 0;
        ui.mute.querySelector('use').setAttribute('href', off ? '#i-mute' : '#i-vol');
    }

    /* ---------- Filtre ---------- */
    const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const haystack = TRACKS.map((t) => norm(`${t.title} ${t.artist} ${t.album}`));
    const defaultInfo = ui.info.textContent;
    function applyFilter() {
        const q = norm(ui.filter.value.trim());
        let shown = 0;
        items.forEach((li, i) => {
            const ok = !q || haystack[i].includes(q);
            li.hidden = !ok;
            if (ok) shown++;
        });
        ui.noMatch.hidden = shown > 0 || !TRACKS.length;
        ui.info.textContent = q ? `${shown} / ${TRACKS.length} pistes` : defaultInfo;
    }

    /* ---------- Media Session (écran verrouillé, touches multimédia) ---------- */
    function updateMediaSession(t) {
        if (!('mediaSession' in navigator)) return;
        const artwork = t.cover ? [{ src: new URL(t.cover, location.href).href, sizes: '512x512' }] : [];
        navigator.mediaSession.metadata = new MediaMetadata({ title: t.title, artist: t.artist, album: t.album, artwork });
    }
    if ('mediaSession' in navigator) {
        const ms = navigator.mediaSession;
        const set = (a, fn) => { try { ms.setActionHandler(a, fn); } catch {} };
        set('play', play);
        set('pause', () => audio.pause());
        set('previoustrack', prev);
        set('nexttrack', () => next());
        set('seekbackward', (d) => { audio.currentTime = Math.max(0, audio.currentTime - (d.seekOffset || 10)); });
        set('seekforward', (d) => { audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + (d.seekOffset || 10)); });
        set('seekto', (d) => { audio.currentTime = d.seekTime; });
    }

    /* ---------- Événements audio ---------- */
    audio.addEventListener('loadedmetadata', () => {
        if (isFinite(audio.duration)) {
            ui.seek.max = audio.duration;
            ui.dur.textContent = fmt(audio.duration);
        }
        if (pendingSeek > 0 && pendingSeek < audio.duration) audio.currentTime = pendingSeek;
        pendingSeek = 0;
    });
    audio.addEventListener('timeupdate', () => {
        if (seeking) return;
        ui.seek.value = audio.currentTime;
        setFill(ui.seek);
        ui.cur.textContent = fmt(audio.currentTime);
    });
    audio.addEventListener('play', () => {
        ui.play.querySelector('use').setAttribute('href', '#i-pause');
        ui.play.setAttribute('aria-label', 'Pause');
        document.body.classList.add('is-playing');
        if (current >= 0) document.title = '▶ ' + TRACKS[current].title + ' — ' + baseTitle;
    });
    audio.addEventListener('pause', () => {
        ui.play.querySelector('use').setAttribute('href', '#i-play');
        ui.play.setAttribute('aria-label', 'Lecture');
        document.body.classList.remove('is-playing');
        document.title = baseTitle;
        store.set({ time: audio.currentTime });
    });
    audio.addEventListener('ended', () => next(true));
    audio.addEventListener('error', () => {
        if (current < 0 || !audio.src) return;
        items[current].classList.add('error');
        ui.artist.textContent = 'Fichier illisible';
        if (items.every((li) => li.classList.contains('error'))) return; // tout est illisible : on s'arrête
        setTimeout(() => next(true), 1200);
    });
    audio.addEventListener('volumechange', renderMute);

    // Sauvegarde régulière de la position (pour reprendre après rechargement)
    let lastSave = 0;
    audio.addEventListener('timeupdate', () => {
        const now = Date.now();
        if (now - lastSave > 5000) { lastSave = now; store.set({ time: audio.currentTime }); }
    });
    window.addEventListener('pagehide', () => store.set({ time: audio.currentTime }));

    /* ---------- Contrôles ---------- */
    ui.play.addEventListener('click', toggle);
    ui.prev.addEventListener('click', prev);
    ui.next.addEventListener('click', () => next());
    ui.shuffle.addEventListener('click', () => setShuffle(!shuffle));
    ui.repeat.addEventListener('click', () => setRepeat({ off: 'all', all: 'one', one: 'off' }[repeat]));
    ui.mute.addEventListener('click', () => { audio.muted = !audio.muted; });
    ui.volume.addEventListener('input', () => { audio.muted = false; setVolume(+ui.volume.value); });

    ui.seek.addEventListener('input', () => {
        seeking = true;
        setFill(ui.seek);
        ui.cur.textContent = fmt(+ui.seek.value);
    });
    ui.seek.addEventListener('change', () => {
        if (current < 0) { ui.seek.value = 0; setFill(ui.seek); seeking = false; return; }
        audio.currentTime = +ui.seek.value;
        seeking = false;
    });

    // Un seul écouteur pour toute la playlist (délégation)
    $('playlist').addEventListener('click', (e) => {
        const li = e.target.closest('.track');
        if (!li) return;
        const i = +li.dataset.i;
        if (i === current) toggle(); else load(i);
    });

    let filterTimer;
    ui.filter.addEventListener('input', () => { clearTimeout(filterTimer); filterTimer = setTimeout(applyFilter, 120); });

    // Raccourcis clavier
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        const tag = e.target.tagName;
        if (tag === 'INPUT' && e.target.type !== 'range') {
            if (e.key === 'Escape') { ui.filter.value = ''; applyFilter(); ui.filter.blur(); }
            return;
        }
        switch (e.key) {
            case ' ': case 'k': case 'K':
                if (tag === 'BUTTON' && e.key === ' ') return; // laisse le bouton gérer
                e.preventDefault(); toggle(); break;
            case 'ArrowRight': case 'l': case 'L':
                if (tag === 'INPUT') return;
                audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 10); break;
            case 'ArrowLeft': case 'j': case 'J':
                if (tag === 'INPUT') return;
                audio.currentTime = Math.max(0, audio.currentTime - 10); break;
            case 'n': case 'N': next(); break;
            case 'p': case 'P': prev(); break;
            case 'm': case 'M': audio.muted = !audio.muted; break;
            case '/': e.preventDefault(); ui.filter.focus(); break;
        }
    });

    /* ---------- Démarrage : on reprend là où on s'était arrêté ---------- */
    const saved = store.get();
    setVolume(typeof saved.volume === 'number' ? saved.volume : 1);
    setRepeat(['off', 'all', 'one'].includes(saved.repeat) ? saved.repeat : 'off');
    if (TRACKS.length) {
        let start = TRACKS.findIndex((t) => t.id === saved.id);
        const resume = start >= 0 ? (saved.time || 0) : 0;
        if (start < 0) start = 0;
        load(start, false, resume);
        if (saved.shuffle) setShuffle(true);
        if (resume > 0) {
            ui.seek.value = resume; setFill(ui.seek); ui.cur.textContent = fmt(resume);
        }
    }
    renderMute();
})();
