/*!
 * Pixelfart SDK 1.0 — klein hulpscript voor games op pixelfart.nl. Licentie: GPL-2.0-or-later.
 *
 * Zet naast je game en laad bovenin <head>:   <script src="pixelfart.js"></script>
 * Op de site wordt dit automatisch vervangen door de nieuwste versie.
 *
 *   Pixelfart.fit(canvas, { mode: 'integer' })   canvas schermvullend schalen, scherpe pixels
 *   Pixelfart.loop(update, { fps: 60 })          spel-loop; pauzeert vanzelf als je niet kijkt
 *   Pixelfart.onPause(fn) / onResume(fn)         eigen pauze (muziek stil, timer stop)
 *   Pixelfart.score(1234)                        eindscore melden (één keer per potje, bij game over)
 *   Pixelfart.back()                             terug naar de site (bijv. vanuit je menu)
 *   Pixelfart.store.get(key) / .set(key, value)  eigen opslag (instellingen, eigen record)
 *   Pixelfart.inSite                             true als de game op de site draait
 */
(function () {
	'use strict';
	if (window.Pixelfart && window.Pixelfart.version) return;

	var inSite = window.parent !== window;
	var paused = false;
	var pauseFns = [];
	var resumeFns = [];

	function send(type, data) {
		if (!inSite) return;
		var msg = { pixelfart: 1, type: type };
		for (var k in data) msg[k] = data[k];
		window.parent.postMessage(msg, '*'); // de site controleert zelf of het bericht van deze game komt
	}

	function setPaused(p) {
		if (p === paused) return;
		paused = p;
		(p ? pauseFns : resumeFns).forEach(function (fn) { try { fn(); } catch (e) { console.error(e); } });
	}
	document.addEventListener('visibilitychange', function () { setPaused(document.hidden); });
	window.addEventListener('message', function (e) {
		if (e.source !== window.parent || !e.data || e.data.pixelfart !== 1) return;
		if (e.data.type === 'pause') setPaused(true);
		if (e.data.type === 'resume') setPaused(document.hidden);
	});

	var Pixelfart = {
		version: '1.0.0',
		inSite: inSite,
		get paused() { return paused; },

		/**
		 * Schaal een canvas met vaste resolutie zo groot mogelijk in het venster, met behoud van verhouding.
		 * mode 'integer': alleen hele vergrotingen (2×, 3×…) voor de scherpste pixels; 'contain': zo groot mogelijk.
		 * Geeft een functie terug om opnieuw te schalen (bijv. na het wijzigen van canvas.width).
		 */
		fit: function (canvas, opts) {
			opts = opts || {};
			var mode = opts.mode || 'contain';
			var parent = opts.parent || null;
			canvas.style.imageRendering = 'pixelated';
			canvas.style.display = 'block';
			function apply() {
				var aw = parent ? parent.clientWidth : window.innerWidth;
				var ah = parent ? parent.clientHeight : window.innerHeight;
				var s = Math.min(aw / canvas.width, ah / canvas.height);
				if (mode === 'integer' && s >= 1) s = Math.floor(s);
				canvas.style.width = Math.floor(canvas.width * s) + 'px';
				canvas.style.height = Math.floor(canvas.height * s) + 'px';
			}
			window.addEventListener('resize', apply);
			if (window.ResizeObserver && parent) new ResizeObserver(apply).observe(parent);
			apply();
			return apply;
		},

		/**
		 * Spel-loop met vaste stapgrootte. update(dt) krijgt seconden; draw() is optioneel.
		 * Stopt vanzelf als het tabblad verborgen is of de site pauzeert: geen stroomverbruik voor niets.
		 * opts.fps: maximaal aantal beelden per seconde (30 is genoeg voor rustige games).
		 */
		loop: function (update, opts) {
			opts = opts || {};
			var minGap = 1000 / (opts.fps || 60) - 1;
			var last = 0, raf = 0, running = true;
			function frame(t) {
				raf = 0;
				if (!running || paused) return;
				raf = requestAnimationFrame(frame);
				if (last && t - last < minGap) return;
				var dt = last ? Math.min((t - last) / 1000, 0.1) : 0;
				last = t;
				update(dt);
				if (opts.draw) opts.draw();
			}
			function start() { if (!raf && running && !paused) { last = 0; raf = requestAnimationFrame(frame); } }
			resumeFns.push(start);
			start();
			return {
				stop: function () { running = false; if (raf) cancelAnimationFrame(raf); raf = 0; },
				start: function () { running = true; start(); },
			};
		},

		onPause: function (fn) { pauseFns.push(fn); },
		onResume: function (fn) { resumeFns.push(fn); },

		/** Eindscore van een potje. Alleen hele getallen ≥ 0. De site vraagt de speler om 3 letters. */
		score: function (value) {
			var n = Math.floor(Number(value));
			if (!isFinite(n) || n < 0) return;
			send('score', { score: n });
		},

		back: function () { send('back', {}); },
		ready: function () { send('ready', {}); },

		/** Opslag per game (localStorage met eigen voorvoegsel). Faalt stil in een privévenster. */
		store: {
			key: function (k) { return 'pf:' + (document.documentElement.dataset.pfCode || location.pathname) + ':' + k; },
			get: function (k, fallback) {
				try { var v = localStorage.getItem(this.key(k)); return v === null ? fallback : JSON.parse(v); } catch (e) { return fallback; }
			},
			set: function (k, v) { try { localStorage.setItem(this.key(k), JSON.stringify(v)); } catch (e) { /* vol of privé */ } },
		},
	};

	window.Pixelfart = Pixelfart;
	window.addEventListener('load', function () { send('ready', {}); });
})();
