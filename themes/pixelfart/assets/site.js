/* Pixelfart: grijpmachine-menu, games filteren/sorteren, geluksmachine, idee/contact, speler. */
(() => {
	'use strict';
	const $ = (s, el = document) => el.querySelector(s);
	const $$ = (s, el = document) => [...el.querySelectorAll(s)];
	const wait = (ms) => new Promise((r) => setTimeout(r, ms));
	const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
	const store = {
		get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
		set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* privé-venster */ } },
	};
	const avgFmt = (v) => Number(v).toFixed(1).replace('.', ',');

	async function api(path, body) {
		const res = await fetch(PF.rest + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body || {}),
		});
		const data = await res.json().catch(() => ({}));
		if (!res.ok) throw new Error(data.message || 'Er ging iets mis. Probeer het zo nog eens.');
		return data;
	}

	/* ---------- robotcheck (ALTCHA, open source, zonder cookies) ----------
	 * Pas geladen als iemand een formulier aanraakt: wie niets invult, laadt niets (scheelt data en stroom).
	 * De browser rekent een kleine puzzel uit; de server controleert die met één handtekening.
	 */
	let altchaLib = null;
	const loadAltcha = () => altchaLib || (altchaLib = (async () => {
		const css = document.createElement('link');
		css.rel = 'stylesheet'; css.href = PF.altcha.css;
		document.head.append(css);
		await import(PF.altcha.js);
		const A = window.$altcha;
		A.algorithms.set('SHA-256', () => new Worker(PF.altcha.worker));
		A.i18n.set('nl', {
			ariaLinkLabel: 'ALTCHA (opent een nieuwe pagina)', enterCode: 'Code', enterCodeAria: 'Voer de code in',
			error: 'Check mislukt. Probeer opnieuw.', expired: 'Verlopen. Klik opnieuw.',
			footer: '<a href="https://altcha.org/" target="_blank" rel="noopener">ALTCHA</a>',
			getAudioChallenge: 'Code laten voorlezen', label: 'Ik ben geen robot', loading: 'Laden…', reload: 'Opnieuw',
			verify: 'Check', verificationRequired: 'Vink eerst het vakje aan.', verified: 'Geen robot ✓',
			verifying: 'Even rekenen…', waitAlert: 'Even rekenen… een moment.', cancel: 'Annuleer', enterCodeFromImage: 'Typ de code over.',
		});
	})());

	/** Robotcheck voor één formulier. token() geeft de payload voor de server (en rekent zo nodig eerst). */
	function robot(form) {
		const slot = $('[data-robot]', form);
		let widget = null;
		const mount = async () => {
			if (widget || !slot) return;
			await loadAltcha();
			widget = document.createElement('altcha-widget');
			widget.setAttribute('challenge', PF.rest + 'altcha');
			widget.setAttribute('language', 'nl');
			widget.setAttribute('auto', 'onload');
			widget.setAttribute('name', 'altcha');
			widget.setAttribute('configuration', JSON.stringify({ hideLogo: true, humanInteractionSignature: false, minDuration: 300 }));
			slot.append(widget);
		};
		form.addEventListener('focusin', mount, { once: true });
		return {
			async token() {
				await mount();
				if (!widget.verify) await new Promise((r) => widget.addEventListener('load', r, { once: true }));
				if (widget.getState() !== 'verified') await widget.verify();
				const input = $('input[name=altcha]', form);
				const value = input ? input.value : '';
				// Elke oplossing is eenmalig; bij een volgende keer versturen rekent hij opnieuw.
				widget.reset();
				return value;
			},
		};
	}

	/* ---------- grijpmachine-menu ---------- */
	const nav = $('.claw-nav');
	if (nav) {
		let busy = false;
		const go = async (i, href) => {
			if (busy) return;
			busy = true;
			const same = String(i) === getComputedStyle(nav).getPropertyValue('--sel').trim() && !nav.dataset.phase;
			if (reduced || same) { location.href = href; return; }
			nav.style.setProperty('--sel', i);
			nav.dataset.phase = 'release';
			$$('.claw-menu a', nav).forEach((a) => a.classList.toggle('is-active', a.dataset.i === String(i)));
			await wait(420); nav.dataset.phase = 'drop';
			await wait(220); nav.dataset.phase = 'grab';
			await wait(140); nav.dataset.phase = 'lift';
			await wait(160);
			location.href = href;
		};
		const intercept = (a, i) => a.addEventListener('click', (e) => {
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
			e.preventDefault();
			go(i, a.href);
		});
		$$('.claw-menu a', nav).forEach((a) => intercept(a, +a.dataset.i));
		const logo = $('.logo');
		logo && intercept(logo, 0);
		// pijltjes links/rechts bewegen de grijper (niet tijdens typen)
		addEventListener('keydown', (e) => {
			if (!['ArrowLeft', 'ArrowRight'].includes(e.key) || e.target.closest('input,textarea,select,dialog,[contenteditable]')) return;
			const links = $$('.claw-menu a', nav);
			const cur = Math.max(0, links.findIndex((a) => a.classList.contains('is-active')));
			const next = (cur + (e.key === 'ArrowRight' ? 1 : 2)) % 3;
			e.preventDefault();
			go(next, links[next].href);
		});
	}

	/* ---------- homepage: filteren, sorteren, pagina's ---------- */
	const home = $('[data-home]');
	let currentList = () => [];
	let clearWinner = () => {};
	if (home) {
		const PAGE = 6;
		const grid = $('[data-grid]', home);
		const items = $$('li', grid);
		const pager = $('[data-pager]', home);
		const tagLinks = $$('.tag-btn', home);
		const state = { tag: home.dataset.tag || '', sort: 'pop', pg: 0 };
		const cmp = {
			pop: (a, b) => b.dataset.plays - a.dataset.plays || a.dataset.title.localeCompare(b.dataset.title, 'nl'),
			rating: (a, b) => b.dataset.rating - a.dataset.rating || b.dataset.plays - a.dataset.plays,
			new: (a, b) => b.dataset.pub.localeCompare(a.dataset.pub) || a.dataset.title.localeCompare(b.dataset.title, 'nl'),
			az: (a, b) => a.dataset.title.localeCompare(b.dataset.title, 'nl'),
		};
		const filtered = () => items
			.filter((li) => !state.tag || li.dataset.tags.split(' ').includes(state.tag))
			.sort(cmp[state.sort]);
		currentList = filtered;

		const render = () => {
			const list = filtered();
			const pages = Math.max(1, Math.ceil(list.length / PAGE));
			state.pg = Math.min(state.pg, pages - 1);
			items.forEach((li) => { li.hidden = true; });
			list.forEach((li, i) => {
				grid.appendChild(li);
				li.hidden = i < state.pg * PAGE || i >= (state.pg + 1) * PAGE;
			});
			const active = tagLinks.find((a) => a.dataset.tag === state.tag);
			tagLinks.forEach((a) => a.classList.toggle('is-active', a === active));
			$('[data-heading]', home).textContent = state.tag && active ? active.dataset.name.toUpperCase() : 'ALLE GAMES';
			$('[data-count]', home).textContent = list.length;
			$('[data-empty]', home).hidden = list.length > 0;
			$$('.sort-btn', home).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.sort === state.sort)));

			pager.hidden = pages < 2;
			pager.innerHTML = '';
			if (pages > 1) {
				const btn = (label, cls, onClick, extra = {}) => {
					const b = document.createElement('button');
					b.type = 'button'; b.className = cls; b.textContent = label; b.onclick = onClick;
					Object.entries(extra).forEach(([k, v]) => b.setAttribute(k, v));
					pager.appendChild(b);
					return b;
				};
				btn('◀', 'pg-arrow', () => setPage(state.pg - 1), { 'aria-label': 'Vorige pagina' }).disabled = state.pg === 0;
				for (let i = 0; i < pages; i++) {
					btn(String(i + 1), 'pg-n', () => setPage(i), i === state.pg ? { 'aria-current': 'page', 'aria-label': `Pagina ${i + 1}` } : { 'aria-label': `Pagina ${i + 1}` });
				}
				btn('▶', 'pg-arrow', () => setPage(state.pg + 1), { 'aria-label': 'Volgende pagina' }).disabled = state.pg === pages - 1;
			}
		};
		const setPage = (i) => {
			state.pg = i; render();
			const top = home.querySelector('.list-head').getBoundingClientRect().top;
			if (top < 0) home.querySelector('.list-head').scrollIntoView({ behavior: reduced ? 'auto' : 'smooth' });
		};

		tagLinks.forEach((a) => a.addEventListener('click', (e) => {
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
			e.preventDefault();
			state.tag = a.dataset.tag; state.pg = 0;
			history.replaceState(null, '', a.href);
			clearWinner();
			render();
		}));
		$$('.sort-btn', home).forEach((b) => b.addEventListener('click', () => { state.sort = b.dataset.sort; state.pg = 0; render(); }));
		render();
	}

	/* ---------- geluksmachine ---------- */
	const bandit = $('[data-bandit]');
	if (bandit) {
		const rows = $$('.reel-row', bandit);
		const play = $('[data-play]', bandit);
		const hint = $('[data-hint]', bandit);
		let spinning = false;
		let pool = null;

		const show = (list, idx) => {
			const L = list.length;
			rows.forEach((row, k) => {
				const li = L ? list[(((idx + k - 1) % L) + L) % L] : null;
				row.querySelector('b').textContent = li ? li.dataset.title : '—';
				row.querySelector('i').style.setProperty('--c', li ? li.dataset.color : '#333');
			});
		};
		clearWinner = () => {
			pool = null;
			bandit.classList.remove('has-result');
			play.hidden = true; hint.hidden = false; hint.textContent = 'trek aan de arm!';
			$$('.game-grid li.is-winner').forEach((li) => li.classList.remove('is-winner'));
			show(currentList(), 0);
		};
		const spin = () => {
			if (spinning) return;
			pool = currentList();
			const L = pool.length;
			if (!L) return;
			clearWinner();
			pool = currentList();
			spinning = true;
			const target = Math.floor(Math.random() * L), N = 26;
			bandit.classList.add('is-spinning', 'pulled');
			hint.textContent = 'draaien…';
			setTimeout(() => bandit.classList.remove('pulled'), 320);
			let t = 0;
			const tick = () => {
				t++;
				bandit.classList.toggle('tick', t % 2 === 1);
				show(pool, (((target - (N - t)) % L) + L) % L);
				if (t < N) setTimeout(tick, 45 + Math.pow(t / N, 3) * 340);
				else {
					spinning = false;
					const win = pool[target];
					bandit.classList.remove('is-spinning', 'tick');
					bandit.classList.add('has-result');
					win.classList.add('is-winner');
					play.href = win.dataset.url;
					play.hidden = false; hint.hidden = true;
					play.focus({ preventScroll: true });
				}
			};
			setTimeout(tick, 180);
		};
		$$('[data-spin]', bandit).forEach((b) => b.addEventListener('click', spin));
		show(currentList(), 0);
	}

	/* ---------- idee-lampje ---------- */
	const modal = $('#idea-modal');
	const ideaBtn = $('[data-idea]');
	if (modal && ideaBtn) {
		const form = $('form', modal);
		const sent = $('[data-sent]', modal);
		const submit = $('[type=submit]', form);
		const err = $('[data-error]', form);
		let count = 0;
		const lit = () => ideaBtn.classList.toggle('is-lit', modal.open || count > 0);
		const open = () => { sent.hidden = true; form.hidden = false; modal.showModal(); lit(); $('textarea', form).focus(); };
		const close = () => { modal.close(); };
		ideaBtn.addEventListener('click', open);
		modal.addEventListener('close', lit);
		modal.addEventListener('click', (e) => { if (e.target === modal || e.target.closest('[data-close]')) close(); });
		$$('.idea-types button', form).forEach((b) => b.addEventListener('click', () => {
			$$('.idea-types button', form).forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
		}));
		form.text.addEventListener('input', () => { submit.disabled = !form.text.value.trim(); });
		const check = robot(form);
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			err.hidden = true; submit.disabled = true;
			try {
				await api('messages', {
					kind: 'idee',
					type: $('.idea-types [aria-pressed=true]', form).dataset.type,
					text: form.text.value, name: form.name.value, hp: form.hp.value,
					altcha: await check.token(),
				});
				count++;
				form.text.value = '';
				form.hidden = true; sent.hidden = false;
				$('[data-idea-hint]').textContent = `${count}× bedankt!`;
				lit();
			} catch (x) {
				err.textContent = x.message; err.hidden = false; submit.disabled = false;
			}
		});
	}

	/* ---------- contactformulier ---------- */
	const contact = $('form[data-form=contact]');
	if (contact) {
		const err = $('[data-error]', contact);
		const sent = $('[data-sent]');
		const btn = $('[type=submit]', contact);
		const fail = (msg) => { err.textContent = msg; err.hidden = false; };
		const check = robot(contact);
		$$('input,textarea', contact).forEach((el) => el.addEventListener('input', () => { err.hidden = true; }));
		contact.addEventListener('submit', async (e) => {
			e.preventDefault();
			const f = contact;
			if (!f.name.value.trim() || !f.text.value.trim()) return fail('Vul je naam en een bericht in.');
			if (!/^\S+@\S+\.\S+$/.test(f.email.value)) return fail('Dat e-mailadres klopt niet helemaal.');
			btn.disabled = true;
			try {
				await api('messages', { kind: 'contact', name: f.name.value, email: f.email.value, text: f.text.value, hp: f.hp.value, altcha: await check.token() });
				$('[data-sent-name]').textContent = f.name.value.trim();
				contact.hidden = true; sent.hidden = false;
				f.text.value = '';
			} catch (x) { fail(x.message); }
			btn.disabled = false;
		});
		$('[data-again]').addEventListener('click', () => { sent.hidden = true; contact.hidden = false; contact.text.focus(); });
	}

	/* ---------- speler ---------- */
	const player = $('[data-player]');
	if (player) {
		const id = player.dataset.id;
		const frame = $('[data-frame]', player);
		const stage = $('[data-stage]', player);
		const panel = $('[data-panel]', player);
		const toggle = $('[data-panel-toggle]', player);
		const back = $('[data-back]', player);

		// speelteller (en speelbewijs voor de topscorelijst)
		let run = null;
		api(`games/${id}/play`).then((r) => {
			const el = $('[data-plays]', player);
			if (el && r.plays) el.textContent = r.plays.toLocaleString('nl-NL');
			run = r.run || null;
		}).catch(() => {});

		// focus naar de game zodat toetsen meteen werken
		const focusGame = () => { try { frame && frame.contentWindow.focus(); } catch (e) { /* */ } };
		frame && frame.addEventListener('load', focusGame);

		// berichten van de game (Pixelfart SDK): alleen van onze eigen iframe
		const tell = (type) => { try { frame.contentWindow.postMessage({ pixelfart: 1, type }, PF.games || '*'); } catch (e) { /* */ } };
		addEventListener('message', (e) => {
			if (!frame || e.source !== frame.contentWindow || (PF.games && e.origin !== PF.games)) return;
			const d = e.data;
			if (d === 'pixelfart:back' || (d && d.pixelfart === 1 && d.type === 'back')) location.href = back.href;
			if (d && d.pixelfart === 1 && d.type === 'score') onScore(d.score);
		});

		// topscores aller tijden
		const hs = player.dataset.scores ? JSON.parse(player.dataset.scores) : null;
		const entry = $('[data-hs-entry]', player);
		let onScore = () => {};
		if (hs && entry) {
			const AZ = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
			const list = $('[data-hiscores]', player);
			const slots = $$('.hs-letter', entry);
			const err = $('[data-error]', entry);
			const save = $('[type=submit]', entry);
			const check = robot(entry);
			const better = (a, b) => (hs.order === 'asc' ? a < b : a > b);
			const qualifies = (n) => hs.top.length < 10 || better(n, hs.top[hs.top.length - 1].score);
			const rankFor = (n) => 1 + hs.top.filter((s) => !better(n, s.score)).length;
			let pending = null;

			const render = (mark) => {
				list.innerHTML = '';
				hs.top.forEach((s) => {
					const li = document.createElement('li');
					if (s.id === mark) li.className = 'is-new';
					li.innerHTML = '<span class="hs-rank"></span><span class="hs-name"></span><span class="hs-score"></span>';
					li.children[0].textContent = s.rank; li.children[1].textContent = s.name; li.children[2].textContent = s.score.toLocaleString('nl-NL');
					list.append(li);
				});
				$('[data-hs-empty]', player).hidden = hs.top.length > 0;
			};
			const shift = (i, d) => {
				const cur = AZ.indexOf(slots[i].value || 'A');
				slots[i].value = AZ[(cur + d + 26) % 26];
			};
			slots.forEach((inp, i) => {
				inp.addEventListener('keydown', (e) => {
					if (e.key === 'ArrowUp') { shift(i, -1); e.preventDefault(); }
					else if (e.key === 'ArrowDown') { shift(i, 1); e.preventDefault(); }
					else if (e.key === 'ArrowLeft' && i > 0) { slots[i - 1].focus(); e.preventDefault(); }
					else if ((e.key === 'ArrowRight' || e.key === ' ') && i < 2) { slots[i + 1].focus(); e.preventDefault(); }
					else if (e.key === 'Escape') { close(); }
				});
				inp.addEventListener('input', () => {
					const ch = inp.value.toUpperCase().replace(/[^A-Z]/g, '').slice(-1);
					inp.value = ch || 'A';
					if (ch && i < 2) slots[i + 1].focus();
				});
				inp.addEventListener('focus', () => inp.select());
				$('[data-up]', inp.parentElement).addEventListener('click', () => { shift(i, -1); inp.focus(); });
				$('[data-down]', inp.parentElement).addEventListener('click', () => { shift(i, 1); inp.focus(); });
			});
			const close = () => { entry.hidden = true; pending = null; tell('resume'); focusGame(); };
			$('[data-hs-skip]', entry).addEventListener('click', close);

			onScore = (n) => {
				if (!run || !entry.hidden || !Number.isInteger(n) || n < 0 || !qualifies(n)) return;
				pending = n;
				const saved = (store.get('pf_initials') || 'AAA').padEnd(3, 'A');
				slots.forEach((s, i) => { s.value = saved[i]; });
				$('[data-hs-score]', entry).textContent = n.toLocaleString('nl-NL');
				$('[data-hs-rank]', entry).textContent = rankFor(n);
				err.hidden = true; save.disabled = false;
				entry.hidden = false;
				tell('pause');
				slots[0].focus();
			};

			entry.addEventListener('submit', async (e) => {
				e.preventDefault();
				const name = slots.map((s) => s.value).join('');
				if (!/^[A-Z]{3}$/.test(name)) { err.textContent = 'Kies drie letters.'; err.hidden = false; return; }
				save.disabled = true; err.hidden = true;
				try {
					const r = await api(`games/${id}/scores`, { name, score: pending, run, altcha: await check.token() });
					run = r.run; hs.top = r.scores;
					store.set('pf_initials', name);
					render(r.entry);
					close();
				} catch (x) { err.textContent = x.message; err.hidden = false; save.disabled = false; }
			});
		}

		// zijpaneel
		const setPanel = (open) => {
			panel.hidden = !open;
			toggle.setAttribute('aria-expanded', String(open));
			toggle.textContent = open ? 'VERBERG ▸' : '◂ INFO';
			if (!open) focusGame();
		};
		setPanel(!matchMedia('(max-width: 760px)').matches);
		toggle.addEventListener('click', () => setPanel(panel.hidden));

		// volledig scherm
		const fsBtn = $('[data-fs]', player);
		const fsExit = $('[data-fs-exit]', player);
		const canFs = !!(stage.requestFullscreen || stage.webkitRequestFullscreen);
		fsBtn.addEventListener('click', () => {
			if (canFs) {
				(stage.requestFullscreen || stage.webkitRequestFullscreen).call(stage);
			} else {
				player.classList.add('is-pseudo-fs'); fsExit.hidden = false; focusGame();
			}
		});
		fsExit.addEventListener('click', () => { player.classList.remove('is-pseudo-fs'); fsExit.hidden = true; });
		document.addEventListener('fullscreenchange', focusGame);

		// sterren
		const stars = $$('[data-star]', player);
		const note = $('[data-rate-note]', player);
		const key = `pf_rate_${id}`;
		let mine = +store.get(key) || 0;
		const paint = (n) => stars.forEach((s) => s.classList.toggle('on', +s.dataset.star <= n));
		const noteFor = (n, avg) => `Jij gaf ${n} ${n === 1 ? 'ster' : 'sterren'}.${avg ? ` Gemiddeld ${avgFmt(avg)}.` : ''}`;
		paint(mine);
		if (mine) note.textContent = noteFor(mine);
		stars.forEach((s) => {
			s.addEventListener('mouseenter', () => paint(+s.dataset.star));
			s.addEventListener('click', async () => {
				const n = +s.dataset.star;
				mine = n; paint(n); store.set(key, n);
				try {
					const r = await api(`games/${id}/rate`, { stars: n });
					$('[data-avg]', player).textContent = avgFmt(r.avg);
					$('[data-cnt]', player).textContent = r.count;
					note.textContent = noteFor(n, r.avg);
				} catch (x) { note.textContent = x.message; }
			});
		});
		$('[data-stars]', player).addEventListener('mouseleave', () => paint(mine));

		// reacties
		const cf = $('[data-comment-form]', player);
		if (cf) {
			const btn = $('[type=submit]', cf);
			const err = $('[data-error]', cf);
			const ok = $('[data-ok]', cf);
			// Zelfde drie letters als bij de topscores.
			cf.name.value = store.get('pf_initials') || '';
			cf.name.addEventListener('input', () => { cf.name.value = cf.name.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3); });
			const check = robot(cf);
			cf.text.addEventListener('input', () => { btn.disabled = !cf.text.value.trim(); ok.hidden = true; err.hidden = true; });
			cf.addEventListener('submit', async (e) => {
				e.preventDefault();
				err.hidden = true;
				if (!/^[A-Z]{3}$/.test(cf.name.value)) { err.textContent = 'Kies drie letters, net als bij de topscores.'; err.hidden = false; cf.name.focus(); return; }
				btn.disabled = true;
				try {
					await api(`games/${id}/comments`, { name: cf.name.value, text: cf.text.value, hp: cf.hp.value, version: player.dataset.version, altcha: await check.token() });
					store.set('pf_initials', cf.name.value);
					cf.text.value = '';
					ok.hidden = false;
				} catch (x) { err.textContent = x.message; err.hidden = false; btn.disabled = false; }
			});
		}
	}
})();
