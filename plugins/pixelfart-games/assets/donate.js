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

	const feeOf = (monthly) => (monthly ? PFD.fee.month : PFD.fee.once);
	/** Uitleg onder de knop: eerlijk wat er naar de betaaldienst gaat, of dat jij die kosten betaalt. */
	const feeText = (amount, monthly, cover) => {
		const fee = feeOf(monthly);
		if (!fee || fee >= amount) return '';
		if (cover) return `Je betaalt ${euro(amount + fee)}: zo gaat de hele ${euro(amount)} naar Pixelfart. Lief!`;
		return `Hiervan gaat ongeveer ${euro(fee)} naar de betaaldienst${monthly ? ' (per keer)' : ''}. ${euro(amount - fee)} is voor Pixelfart.`;
	};
	const coverLabel = (form, monthly) => {
		const l = $('[data-cover-label]', form);
		if (l) l.textContent = `Ik betaal de kosten van de betaaldienst ook (${euro(feeOf(monthly))}${monthly ? ' per keer' : ''})`;
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
			cover: !!(form.kosten && form.kosten.checked),
		});
		const sync = () => {
			const { amount, monthly, cover } = read();
			btn.textContent = `DONEER ${euro(amount + (cover ? feeOf(monthly) : 0))}${monthly ? ' PER MAAND' : ''} ▶`;
			$('[data-fee]', form).textContent = feeText(amount, monthly, cover);
			coverLabel(form, monthly);
			if (emailRow) emailRow.hidden = !monthly;
		};
		// Keuze meegenomen vanaf een andere pagina (?bedrag=5&per=maand).
		if (here) {
			const b = params.get('bedrag'), p = params.get('per');
			const rb = b && $(`input[name="bedrag"][value="${CSS.escape(b)}"]`, form);
			const rp = p && $(`input[name="per"][value="${CSS.escape(p)}"]`, form);
			if (rb) rb.checked = true;
			if (rp) rp.checked = true;
			if (form.kosten && params.get('kosten') === '1') form.kosten.checked = true;
			if (rb || rp) requestAnimationFrame(() => { w.scrollIntoView({ block: 'center' }); btn.focus({ preventScroll: true }); });
		}
		form.addEventListener('change', sync);
		sync();
		if (!here) return; // elders: gewoon het formulier naar de doneerpagina laten gaan

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const { amount, monthly, cover } = read();
			err.hidden = true;
			if (monthly && (!form.email.value.trim() || !form.email.checkValidity())) {
				err.textContent = 'Vul je e-mailadres in. Daar sturen we de link om te stoppen naartoe.';
				err.hidden = false;
				form.email.focus();
				return;
			}
			btn.disabled = true;
			try {
				const r = await api('donate', { amount, interval: monthly ? 'month' : 'once', email: monthly ? form.email.value.trim() : '', cover, hp: form.hp.value });
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
	let wallRetry = null; // gezet door de muur: tekening terugzetten na een mislukte betaling
	if (wall) initWall(wall);

	function initWall(root) {
		// Eén pf-pixel is 10×10 punten op de muur; op het scherm schalen we dat mee met de ruimte,
		// altijd met een heel aantal schermpixels per vakje, zodat de muur scherp blijft.
		const MAX_W = 960;   // breder dan dit wordt de muur niet (20 px per vakje)
		const SHOW_ROWS = 32; // zoveel rijen zie je tegelijk; de rest scrollt
		let S = 10, u = 1;    // S = schermpixels per vakje (in canvaspixels), u = S / 10 voor de details
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
		const saved = new Map(sel); // zoals hij was voor het betalen, voor als de betaling mislukt

		const toCanvasY = (y) => (W.rows - 1 - y) * S; // y telt vanaf onder: zo groeit de muur naar boven

		function draw() {
			g.fillStyle = '#000';
			g.fillRect(0, 0, cv.width, cv.height);
			// lege vakjes: een stipje in het midden
			g.fillStyle = '#262626';
			const dot = Math.max(1, 2 * u), mid = (S - dot) / 2 | 0;
			for (let y = 0; y < W.rows; y++) for (let x = 0; x < W.cols; x++) g.fillRect(x * S + mid, toCanvasY(y) + mid, dot, dot);
			for (const [k, [c, who]] of grid) {
				const [x, y] = k.split(',').map(Number), cy = toCanvasY(y);
				if (who === -1) { // er wordt nu voor betaald
					g.fillStyle = '#3a3a3a';
					for (let i = 0; i < 10; i += 2) g.fillRect(x * S + i * u, cy + i * u, 2 * u, 2 * u);
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
				g.fillRect(x * S, cy, 3 * u, 3 * u);
				g.fillStyle = '#fff';
				g.fillRect(x * S, cy, 2 * u, 2 * u);
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
				g.lineWidth = 2 * u;
				g.strokeRect(ring[0] * S + u, toCanvasY(ring[1]) + u, S - 2 * u, S - 2 * u);
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
			fit();
			draw();
			scroller.scrollTop = keepScroll ? scroller.scrollHeight - fromBottom : scroller.scrollHeight; // onderaan beginnen
			update();
		}

		/** Hoe groot kan de muur? Zo breed als de pagina (niet alleen de tekstkolom), tot MAX_W. */
		function fit() {
			const dpr = window.devicePixelRatio || 1;
			const main = root.closest('.site-main') || document.body;
			const cs = getComputedStyle(main);
			const room = main.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
			const frame = getComputedStyle(scroller.parentElement);
			const chrome = parseFloat(frame.paddingLeft) + parseFloat(frame.paddingRight) + 2 * 4 + 16; // rand van het kader + scrollbalk
			const css = Math.max(W.cols * 4, Math.min(MAX_W, room - chrome));
			const s = Math.max(4, Math.floor(css * dpr / W.cols)); // hele schermpixels per vakje
			if (s === S && cv.width === W.cols * S && cv.height === W.rows * S) return;
			S = s; u = Math.max(1, Math.round(S / 10));
			cv.width = W.cols * S;
			cv.height = W.rows * S;
			cv.style.width = (cv.width / dpr) + 'px';
			cv.style.aspectRatio = `${W.cols} / ${W.rows}`;
			scroller.style.maxHeight = (SHOW_ROWS * S / dpr + 8) + 'px';
			draw();
		}
		let fitT = 0;
		window.addEventListener('resize', () => { clearTimeout(fitT); fitT = setTimeout(fit, 100); });

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

		const reset = $('[data-wall-reset]', root);
		const count = $('[data-wall-count]', form);
		const btn = $('[data-submit]', form);
		const left = $('[data-msg-left]', form);
		function update() {
			const n = sel.size;
			form.hidden = !n;
			reset.disabled = !n;
			if (!n) return;
			const amount = n * PFD.price;
			const cover = !!(form.kosten && form.kosten.checked);
			count.textContent = `${n} ${n === 1 ? 'pixel' : 'pixels'} gekozen · ${euro(amount)}`;
			btn.textContent = `KOOP ${n === 1 ? 'PIXEL' : n + ' PIXELS'} VOOR ${euro(amount + (cover ? feeOf(false) : 0))} ▶`;
			$('[data-fee]', form).textContent = feeText(amount, false, cover);
			coverLabel(form, false);
		}
		const nameIn = form.elements.namedItem('name'); // niet form.name: dat is de naam van het formulier zelf
		nameIn.value = store.get('pf_initials') || '';
		nameIn.addEventListener('input', () => { nameIn.value = nameIn.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3); });
		const msgLeft = () => { left.textContent = `(nog ${form.message.maxLength - form.message.value.length})`; };
		form.message.value = store.get('pf_wall_msg') || ''; // terug van een mislukte betaling
		form.message.addEventListener('input', msgLeft);
		msgLeft();
		if (form.kosten) form.kosten.addEventListener('change', update);
		// Reset: je eigen (nog niet betaalde) tekening weghalen. Gekochte pixels blijven natuurlijk staan.
		reset.addEventListener('click', () => { sel.clear(); changed(); cv.focus(); });

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const err = $('[data-error]', form);
			err.hidden = true;
			const name = nameIn.value.trim();
			if (name && name.length !== 3) { err.textContent = 'Kies drie letters (of laat het vakje leeg).'; err.hidden = false; nameIn.focus(); return; }
			btn.disabled = true;
			try {
				const pixels = [...sel].map(([k, c]) => [...k.split(',').map(Number), c]);
				const r = await api('pixels', { pixels, name, message: form.message.value.trim(), cover: !!(form.kosten && form.kosten.checked), hp: form.hp.value });
				if (name) store.set('pf_initials', name);
				store.set('pf_wall_msg', form.message.value.trim() || null);
				location.href = r.checkout;
			} catch (ex) {
				err.textContent = ex.message;
				err.hidden = false;
				btn.disabled = false;
				if (ex.data && ex.data.taken) load(true).catch(() => {}); // haalt de bezette pixels uit je keuze
			}
		});

		/** Na een mislukte betaling zijn de pixels weer vrij: muur opnieuw laden en de tekening terugzetten. */
		wallRetry = async () => {
			await first; // eerst de gewone eerste lading afwachten, anders gooit die de tekening er weer af
			if (saved.size) sel = new Map(saved);
			await load(true); // haalt alleen pixels weg die intussen echt van iemand anders zijn
			save();
			return sel.size;
		};

		const first = load().catch(() => { scroller.insertAdjacentHTML('afterend', '<p class="form-error">De pixelmuur kon niet geladen worden. Probeer het later nog eens.</p>'); });
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
						store.set('pf_wall_msg', null);
						box.innerHTML = `<strong>BEDANKT!</strong><span>Je ${s.pixels === 1 ? 'pixel staat' : s.pixels + ' pixels staan'} op de muur. Klik erop om ze terug te zien.${s.moderated ? ' Je boodschap verschijnt zodra we hem gelezen hebben.' : ''}</span>`;
					} else {
						box.innerHTML = `<strong>BEDANKT!</strong><span>Je donatie van ${euro(s.amount)}${s.recurring ? ' per maand' : ''} is binnen. Super lief, daar kunnen we echt iets mee.${s.recurring ? ' Je krijgt een mail met een link om te stoppen, mocht je dat ooit willen.' : ''}</span>`;
					}
				} else if (s.status === 'open') {
					box.innerHTML = '<strong>NOG BEZIG</strong><span>Je betaling is nog niet afgerond. Kijk over een paar minuten nog eens, of probeer het opnieuw.</span>';
				} else {
					box.classList.add('is-bad');
					const why = (s.status === 'canceled' ? 'Je hebt de betaling geannuleerd.' : s.status === 'expired' ? 'De betaling is verlopen.' : 'De betaling is niet gelukt.') + ' Er is niets afgeschreven.';
					box.innerHTML = `<strong>NIET GELUKT</strong><span>${why}</span>`;
					if (s.kind === 'pixels' && wallRetry) {
						const n = await wallRetry().catch(() => 0);
						const buy = $('[data-wall-buy]');
						if (n && buy && !buy.hidden) {
							box.insertAdjacentHTML('beforeend', `<span>Je tekening staat weer op de muur${n < s.pixels ? ' (op de pixels die nog vrij zijn)' : ''}. Probeer het gewoon nog eens.</span><div><button type="button" class="btn btn-pink" data-retry>OPNIEUW BETALEN ▼</button></div>`);
							$('[data-retry]', box).addEventListener('click', () => {
								const btn = $('[data-submit]', buy);
								btn.scrollIntoView({ block: 'center', behavior: 'smooth' });
								btn.focus({ preventScroll: true });
							});
						} else {
							box.insertAdjacentHTML('beforeend', '<span>Je pixels zijn intussen door iemand anders gekocht. Kies gewoon nieuwe vakjes.</span>');
						}
					}
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
