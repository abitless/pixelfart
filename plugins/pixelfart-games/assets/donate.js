/* Pixelfart: doneren (blok "Doneren") en de pixelmuur (blok "Pixelmuur"), plus bedankt- en stopmeldingen.
 * Alleen geladen op pagina's met een van deze blokken. Geen animatielus: de muur tekent alleen als er iets verandert.
 */
(() => {
	'use strict';
	const $ = (s, el = document) => el.querySelector(s);
	const $$ = (s, el = document) => [...el.querySelectorAll(s)];
	const store = {
		get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
		set(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) { /* privé-venster */ } },
	};
	const euro = (c) => '€ ' + (c % 100 ? (c / 100).toFixed(2).replace('.', ',') : c / 100);
	const params = new URLSearchParams(location.search);
	const esc = (s) => String(s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

	async function api(path, body, method) {
		const res = await fetch(PFD.rest + path, body ? {
			method: method || 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body),
		} : { method: method || 'GET' });
		const data = await res.json().catch(() => ({}));
		if (!res.ok) {
			const err = new Error(data.message || 'Er ging iets mis. Probeer het zo nog eens.');
			err.data = data.data || {};
			throw err;
		}
		return data;
	}

	const feeText = (amount, monthly) => {
		const fee = monthly ? PFD.fee.month : PFD.fee.once;
		if (!fee || fee >= amount) return '';
		return `Hiervan gaat ongeveer ${euro(fee)} naar de betaaldienst${monthly ? ' (per keer)' : ''}. ${euro(amount - fee)} is voor Pixelfart.`;
	};

	/** Meldingsvak bovenaan het eerste doneerblok (bedankt, mislukt, stoppen). */
	function notice(prefer) {
		const host = $(prefer) || $('[data-donate-widget][data-here]') || $('[data-pixel-wall]') || $('.entry');
		if (!host) return null;
		let box = $('.pfd-notice');
		if (!box) {
			box = document.createElement('div');
			box.className = 'pfd-notice sent-box';
			box.setAttribute('role', 'status');
		}
		if (box.nextElementSibling !== host) host.before(box);
		return box;
	}

	/* ---------- Doneren ---------- */
	$$('[data-donate-widget]').forEach((w) => {
		const form = $('form', w);
		const here = w.hasAttribute('data-here');
		const btn = $('[data-submit]', form);
		const err = $('[data-error]', form);
		const emailRow = $('[data-email]', form);
		const read = () => ({
			amount: Number((form.bedrag.value || '1')) * 100,
			monthly: form.per.value === 'maand',
		});
		const sync = () => {
			const { amount, monthly } = read();
			btn.textContent = `DONEER ${euro(amount)}${monthly ? ' PER MAAND' : ''} ▶`;
			$('[data-fee]', form).textContent = feeText(amount, monthly);
			if (emailRow) emailRow.hidden = !monthly;
		};
		// Keuze meegenomen vanaf een andere pagina (?bedrag=5&per=maand).
		if (here) {
			const b = params.get('bedrag'), p = params.get('per');
			const rb = b && $(`input[name="bedrag"][value="${CSS.escape(b)}"]`, form);
			const rp = p && $(`input[name="per"][value="${CSS.escape(p)}"]`, form);
			if (rb) rb.checked = true;
			if (rp) rp.checked = true;
			if (rb || rp) requestAnimationFrame(() => { w.scrollIntoView({ block: 'center' }); btn.focus({ preventScroll: true }); });
		}
		form.addEventListener('change', sync);
		sync();
		if (!here) return; // elders: gewoon het formulier naar de doneerpagina laten gaan

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const { amount, monthly } = read();
			err.hidden = true;
			if (monthly && (!form.email.value.trim() || !form.email.checkValidity())) {
				err.textContent = 'Vul je e-mailadres in. Daar sturen we de link om te stoppen naartoe.';
				err.hidden = false;
				form.email.focus();
				return;
			}
			btn.disabled = true;
			try {
				const r = await api('donate', { amount, interval: monthly ? 'month' : 'once', email: monthly ? form.email.value.trim() : '', hp: form.hp.value });
				location.href = r.checkout;
			} catch (ex) {
				err.textContent = ex.message;
				err.hidden = false;
				btn.disabled = false;
			}
		});
	});

	/* ---------- Pixelmuur ---------- */
	const wall = $('[data-pixel-wall]');
	if (wall) initWall(wall);

	function initWall(root) {
		const S = 10; // één pf-pixel = 10×10 schermpixels
		const here = root.hasAttribute('data-here');
		const cv = $('[data-wall-canvas]', root);
		const g = cv.getContext('2d');
		const scroller = $('[data-wall-scroll]', root);
		const info = $('[data-wall-info]', root);
		const form = $('[data-wall-buy]', root);
		const pal = PFD.palette;
		let W = { cols: 48, rows: 24, px: [], d: [] };
		let grid = new Map(); // "x,y" → [kleur, wie]
		let sel = new Map();  // "x,y" → kleur (mijn keuze)
		let color = 3;        // roze
		let cursor = null;    // [x, y] voor het toetsenbord
		let focusDonor = -1;  // van wie zijn de pixels die je bekijkt
		let hover = null;

		try { const s = JSON.parse(store.get('pf_wall_sel') || '{}'); if (s.sel) sel = new Map(s.sel); if (Number.isInteger(s.color)) color = s.color; } catch (e) { /* oud */ }
		const save = () => store.set('pf_wall_sel', sel.size ? JSON.stringify({ sel: [...sel], color }) : null);

		const toCanvasY = (y) => (W.rows - 1 - y) * S; // y telt vanaf onder: zo groeit de muur naar boven

		function draw() {
			g.fillStyle = '#000';
			g.fillRect(0, 0, cv.width, cv.height);
			// lege vakjes: een stipje in het midden
			g.fillStyle = '#262626';
			for (let y = 0; y < W.rows; y++) for (let x = 0; x < W.cols; x++) g.fillRect(x * S + 4, toCanvasY(y) + 4, 2, 2);
			for (const [k, [c, who]] of grid) {
				const [x, y] = k.split(',').map(Number), cy = toCanvasY(y);
				if (who === -1) { // er wordt nu voor betaald
					g.fillStyle = '#3a3a3a';
					for (let i = 0; i < S; i += 2) g.fillRect(x * S + i, cy + i, 2, 2);
					continue;
				}
				g.fillStyle = who === -2 ? '#2a2a2a' : pal[c];
				g.fillRect(x * S, cy, S, S);
			}
			for (const [k, c] of sel) {
				const [x, y] = k.split(',').map(Number), cy = toCanvasY(y);
				g.fillStyle = pal[c];
				g.fillRect(x * S, cy, S, S);
				g.fillStyle = '#000'; // hoekje: deze zijn van jou (nog niet betaald)
				g.fillRect(x * S, cy, 3, 3);
				g.fillStyle = '#fff';
				g.fillRect(x * S, cy, 2, 2);
			}
			if (focusDonor >= 0) { // de rest wat donkerder, zodat je ziet wat bij elkaar hoort
				g.fillStyle = 'rgba(0,0,0,.65)';
				for (let y = 0; y < W.rows; y++) for (let x = 0; x < W.cols; x++) {
					const v = grid.get(x + ',' + y);
					if (!v || v[1] !== focusDonor) g.fillRect(x * S, toCanvasY(y), S, S);
				}
			}
			const ring = cursor || hover;
			if (ring) {
				g.strokeStyle = '#ffcf4a';
				g.lineWidth = 2;
				g.strokeRect(ring[0] * S + 1, toCanvasY(ring[1]) + 1, S - 2, S - 2);
			}
		}

		async function load(keepScroll) {
			const fromBottom = scroller.scrollHeight - scroller.scrollTop;
			W = await api('pixels');
			grid = new Map();
			for (let i = 0; i < W.px.length; i += 4) grid.set(W.px[i] + ',' + W.px[i + 1], [W.px[i + 2], W.px[i + 3]]);
			for (const k of [...sel.keys()]) {
				const [x, y] = k.split(',').map(Number);
				if (grid.has(k) || x >= W.cols || y >= W.rows) sel.delete(k); // intussen verkocht
			}
			cv.width = W.cols * S;
			cv.height = W.rows * S;
			cv.style.aspectRatio = `${W.cols} / ${W.rows}`;
			draw();
			scroller.scrollTop = keepScroll ? scroller.scrollHeight - fromBottom : scroller.scrollHeight; // onderaan beginnen
			update();
		}

		function cellAt(e) {
			const r = cv.getBoundingClientRect();
			const x = Math.floor((e.clientX - r.left) / r.width * W.cols);
			const row = Math.floor((e.clientY - r.top) / r.height * W.rows);
			if (x < 0 || x >= W.cols || row < 0 || row >= W.rows) return null;
			return [x, W.rows - 1 - row];
		}

		function showInfo(x, y) {
			const v = grid.get(x + ',' + y);
			if (!v) { info.hidden = true; focusDonor = -1; return; }
			const who = v[1];
			focusDonor = who >= 0 ? who : -1;
			if (who === -1) info.innerHTML = '<p>Voor deze pixel wordt nu betaald.</p>';
			else if (who === -2) info.innerHTML = '<p>Deze pixels zijn verborgen.</p>';
			else {
				const d = W.d[who];
				info.innerHTML = `<div class="wall-who"><span class="comment-name">${esc(d.n)}</span><span>${esc(d.t)}</span><span>${d.k} ${d.k === 1 ? 'pixel' : 'pixels'}</span></div>`
					+ (d.m ? `<p class="wall-says">${esc(d.m)}</p>` : d.w ? '<p class="fine">De boodschap wordt nog even gelezen.</p>' : '');
			}
			info.insertAdjacentHTML('beforeend', '<button type="button" class="wall-close" aria-label="Sluiten">×</button>');
			info.hidden = false;
		}
		info.addEventListener('click', (e) => { if (e.target.closest('.wall-close')) { info.hidden = true; focusDonor = -1; draw(); cv.focus(); } });

		/** Een vakje aanklikken: van iemand → laten zien wie; leeg → kiezen/kleuren/wissen. */
		function touch(x, y, mode) {
			const k = x + ',' + y;
			if (grid.has(k)) { if (!mode) showInfo(x, y); return null; }
			if (!here) return null;
			info.hidden = true; focusDonor = -1;
			const m = mode || (sel.get(k) === color ? 'erase' : 'paint');
			if (m === 'erase') sel.delete(k);
			else if (sel.size < PFD.max || sel.has(k)) sel.set(k, color);
			else flash(`Maximaal ${PFD.max} pixels per keer.`);
			return m;
		}

		let stroke = null;
		cv.addEventListener('pointerdown', (e) => {
			const c = cellAt(e);
			if (!c) return;
			cursor = null;
			stroke = touch(c[0], c[1]);
			if (stroke && e.pointerType !== 'touch') cv.setPointerCapture(e.pointerId); // slepen = tekenen (niet op touch: dan scroll je)
			else stroke = null;
			changed();
		});
		cv.addEventListener('pointermove', (e) => {
			const c = cellAt(e);
			if (stroke && c) { touch(c[0], c[1], stroke); changed(); return; }
			if (e.pointerType === 'mouse') {
				const same = c && hover && c[0] === hover[0] && c[1] === hover[1];
				if (!same) { hover = c; cv.style.cursor = c && (grid.has(c.join(',')) || here) ? 'pointer' : 'default'; draw(); }
			}
		});
		const end = () => { stroke = null; };
		cv.addEventListener('pointerup', end);
		cv.addEventListener('pointercancel', end);
		cv.addEventListener('pointerleave', () => { if (hover) { hover = null; draw(); } });

		// Toetsenbord: pijltjes verplaatsen het gele kader, spatie/enter kiest of laat zien wie.
		cv.addEventListener('keydown', (e) => {
			const dirs = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, 1], ArrowDown: [0, -1] };
			if (dirs[e.key]) {
				e.preventDefault(); // ook: het menu bovenaan niet laten verspringen
				cursor = cursor || [Math.floor(W.cols / 2), 0];
				cursor = [Math.max(0, Math.min(W.cols - 1, cursor[0] + dirs[e.key][0])), Math.max(0, Math.min(W.rows - 1, cursor[1] + dirs[e.key][1]))];
				const top = toCanvasY(cursor[1]) / cv.height * scroller.scrollHeight;
				if (top < scroller.scrollTop || top > scroller.scrollTop + scroller.clientHeight - 20) scroller.scrollTop = top - scroller.clientHeight / 2;
				draw();
			} else if ((e.key === ' ' || e.key === 'Enter') && cursor) {
				e.preventDefault();
				touch(cursor[0], cursor[1]);
				changed();
			} else if (e.key === 'Escape') {
				info.hidden = true; focusDonor = -1; cursor = null; draw();
			}
		});
		cv.addEventListener('blur', () => { if (cursor) { cursor = null; draw(); } });

		function changed() { save(); draw(); update(); }

		let flashT = 0;
		function flash(msg) {
			if (!form) return;
			const err = $('[data-error]', form);
			err.textContent = msg; err.hidden = false;
			clearTimeout(flashT);
			flashT = setTimeout(() => { err.hidden = true; }, 4000);
		}

		if (!here || !form) { load().catch(() => {}); return; }

		// Kleuren kiezen
		const palBox = $('[data-wall-palette]', root);
		pal.forEach((c, i) => {
			const b = document.createElement('button');
			b.type = 'button';
			b.className = 'swatch';
			b.style.setProperty('--c', c);
			b.setAttribute('role', 'radio');
			b.setAttribute('aria-label', 'Kleur ' + (i + 1));
			b.dataset.i = i;
			palBox.append(b);
		});
		const paintPal = () => $$('.swatch', palBox).forEach((b) => b.setAttribute('aria-checked', String(Number(b.dataset.i) === color)));
		palBox.addEventListener('click', (e) => {
			const b = e.target.closest('.swatch');
			if (!b) return;
			color = Number(b.dataset.i);
			paintPal();
			save();
		});
		paintPal();

		const count = $('[data-wall-count]', form);
		const btn = $('[data-submit]', form);
		const left = $('[data-msg-left]', form);
		function update() {
			const n = sel.size;
			form.hidden = !n;
			if (!n) return;
			const amount = n * PFD.price;
			count.textContent = `${n} ${n === 1 ? 'pixel' : 'pixels'} gekozen · ${euro(amount)}`;
			btn.textContent = `KOOP ${n === 1 ? 'PIXEL' : n + ' PIXELS'} VOOR ${euro(amount)} ▶`;
			$('[data-fee]', form).textContent = feeText(amount, false);
		}
		const nameIn = form.elements.namedItem('name'); // niet form.name: dat is de naam van het formulier zelf
		nameIn.value = store.get('pf_initials') || '';
		nameIn.addEventListener('input', () => { nameIn.value = nameIn.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3); });
		const msgLeft = () => { left.textContent = `(nog ${form.message.maxLength - form.message.value.length})`; };
		form.message.addEventListener('input', msgLeft);
		msgLeft();
		$('[data-clear]', form).addEventListener('click', () => { sel.clear(); changed(); cv.focus(); });

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const err = $('[data-error]', form);
			err.hidden = true;
			const name = nameIn.value.trim();
			if (name && name.length !== 3) { err.textContent = 'Kies drie letters (of laat het vakje leeg).'; err.hidden = false; nameIn.focus(); return; }
			btn.disabled = true;
			try {
				const pixels = [...sel].map(([k, c]) => [...k.split(',').map(Number), c]);
				const r = await api('pixels', { pixels, name, message: form.message.value.trim(), hp: form.hp.value });
				if (name) store.set('pf_initials', name);
				location.href = r.checkout;
			} catch (ex) {
				err.textContent = ex.message;
				err.hidden = false;
				btn.disabled = false;
				if (ex.data && ex.data.taken) load(true).catch(() => {}); // haalt de bezette pixels uit je keuze
			}
		});

		load().catch(() => { scroller.insertAdjacentHTML('afterend', '<p class="form-error">De pixelmuur kon niet geladen worden. Probeer het later nog eens.</p>'); });
	}

	/* ---------- Terug van het betalen: ?bedankt=… ---------- */
	const token = params.get('bedankt');
	if (token && /^[a-f0-9]{32}$/.test(token)) {
		const box = notice();
		if (box) {
			box.innerHTML = '<strong>EVEN KIJKEN…</strong><span>We vragen de betaling op.</span>';
			let tries = 0;
			const poll = async () => {
				let s;
				try { s = await api('donate/status?token=' + token); } catch (e) { box.innerHTML = `<strong>HMM</strong><span>${esc(e.message)}</span>`; return; }
				if (s.status === 'open' && ++tries < 30) { setTimeout(poll, 2000); return; }
				notice(s.kind === 'pixels' ? '[data-pixel-wall]' : '[data-donate-widget][data-here]').scrollIntoView({ block: 'center' }); // melding bij het juiste blok
				if (s.status === 'paid') {
					box.classList.remove('is-bad');
					if (s.kind === 'pixels') {
						store.set('pf_wall_sel', null);
						box.innerHTML = `<strong>BEDANKT!</strong><span>Je ${s.pixels === 1 ? 'pixel staat' : s.pixels + ' pixels staan'} op de muur. Klik erop om ze terug te zien.${s.moderated ? ' Je boodschap verschijnt zodra we hem gelezen hebben.' : ''}</span>`;
					} else {
						box.innerHTML = `<strong>BEDANKT!</strong><span>Je donatie van ${euro(s.amount)}${s.recurring ? ' per maand' : ''} is binnen. Super lief, daar kunnen we echt iets mee.${s.recurring ? ' Je krijgt een mail met een link om te stoppen, mocht je dat ooit willen.' : ''}</span>`;
					}
				} else if (s.status === 'open') {
					box.innerHTML = '<strong>NOG BEZIG</strong><span>Je betaling is nog niet afgerond. Kijk over een paar minuten nog eens, of probeer het opnieuw.</span>';
				} else {
					box.classList.add('is-bad');
					box.innerHTML = `<strong>NIET GELUKT</strong><span>${s.status === 'canceled' ? 'Je hebt de betaling geannuleerd.' : 'De betaling is niet gelukt.'} Er is niets afgeschreven.${s.kind === 'pixels' ? ' Je tekening staat nog klaar, je kunt het gewoon opnieuw proberen.' : ''}</span>`;
				}
			};
			poll();
		}
	}

	/* ---------- Maanddonatie stoppen: ?stoppen=… (link uit de bedankmail) ---------- */
	const stop = params.get('stoppen');
	if (stop && /^[a-f0-9]{32}$/.test(stop)) {
		const box = notice('[data-donate-widget][data-here]');
		if (box) {
			api('donate/stop?token=' + stop).then((s) => {
				box.innerHTML = `<strong>MAANDELIJKS STOPPEN?</strong><span>Je doneert nu ${euro(s.amount)} per maand aan Pixelfart. Wil je daarmee stoppen? Dat is helemaal oké.</span><div><button type="button" class="btn btn-pink">JA, STOP MIJN DONATIE</button></div>`;
				$('button', box).addEventListener('click', async (e) => {
					e.target.disabled = true;
					try {
						await api('donate/stop', { token: stop });
						box.innerHTML = '<strong>GESTOPT</strong><span>Je maandelijkse donatie is gestopt en we hebben je e-mailadres gewist. Bedankt voor je steun!</span>';
					} catch (ex) {
						box.insertAdjacentHTML('beforeend', `<span class="form-error">${esc(ex.message)}</span>`);
						e.target.disabled = false;
					}
				});
			}).catch((ex) => { box.innerHTML = `<strong>HMM</strong><span>${esc(ex.message)}</span>`; });
		}
	}
})();
