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
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			err.hidden = true; submit.disabled = true;
			try {
				await api('messages', {
					kind: 'idee',
					type: $('.idea-types [aria-pressed=true]', form).dataset.type,
					text: form.text.value, name: form.name.value, hp: form.hp.value,
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
		$$('input,textarea', contact).forEach((el) => el.addEventListener('input', () => { err.hidden = true; }));
		contact.addEventListener('submit', async (e) => {
			e.preventDefault();
			const f = contact;
			if (!f.name.value.trim() || !f.text.value.trim()) return fail('Vul je naam en een bericht in.');
			if (!/^\S+@\S+\.\S+$/.test(f.email.value)) return fail('Dat e-mailadres klopt niet helemaal.');
			btn.disabled = true;
			try {
				await api('messages', { kind: 'contact', name: f.name.value, email: f.email.value, text: f.text.value, hp: f.hp.value });
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

		// speelteller
		api(`games/${id}/play`).then((r) => { const el = $('[data-plays]', player); if (el && r.plays) el.textContent = r.plays.toLocaleString('nl-NL'); }).catch(() => {});

		// focus naar de game zodat toetsen meteen werken
		const focusGame = () => { try { frame && frame.contentWindow.focus(); } catch (e) { /* */ } };
		frame && frame.addEventListener('load', focusGame);

		// games kunnen 'pixelfart:back' sturen om terug te gaan
		addEventListener('message', (e) => { if (frame && e.source === frame.contentWindow && e.data === 'pixelfart:back') location.href = back.href; });

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
			cf.name.value = store.get('pf_name') || '';
			cf.text.addEventListener('input', () => { btn.disabled = !cf.text.value.trim(); ok.hidden = true; err.hidden = true; });
			cf.addEventListener('submit', async (e) => {
				e.preventDefault();
				btn.disabled = true; err.hidden = true;
				try {
					await api(`games/${id}/comments`, { name: cf.name.value, text: cf.text.value, hp: cf.hp.value });
					store.set('pf_name', cf.name.value.trim());
					cf.text.value = '';
					ok.hidden = false;
				} catch (x) { err.textContent = x.message; err.hidden = false; btn.disabled = false; }
			});
		}
	}
})();
