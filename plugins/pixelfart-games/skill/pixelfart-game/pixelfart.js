/*!
 * Pixelfart SDK 1.1 — klein hulpscript voor games op pixelfart.nl. Licentie: GPL-2.0-or-later.
 *
 * Zet naast je game (met pixelfart-fonts.css en de map fonts/) en laad bovenin <head>:
 *   <script src="pixelfart.js"></script>
 * Op de site wordt dit automatisch vervangen door de nieuwste versie.
 *
 *   Pixelfart.fit(canvas, { mode: 'integer' })   canvas schermvullend schalen, scherpe pixels
 *   Pixelfart.loop(update, { fps: 60 })          spel-loop; pauzeert vanzelf als je niet kijkt
 *   Pixelfart.onPause(fn) / onResume(fn)         eigen pauze (muziek stil, timer stop)
 *   Pixelfart.fonts(['Press Start 2P'])          pixelfonts laden (zelf gehost); geeft een Promise
 *   Pixelfart.text(ctx, 'SCORE', x, y)           haarscherpe pixeltekst op een canvas
 *   Pixelfart.score(1234)                        eindscore melden (één keer per potje, bij game over)
 *   Pixelfart.back()                             terug naar de site (bijv. vanuit je menu)
 *   Pixelfart.store.get(key) / .set(key, value)  eigen opslag (instellingen, eigen record)
 *   Pixelfart.inSite                             true als de game op de site draait
 */
(function () {
	'use strict';
	if (window.Pixelfart && window.Pixelfart.version) return;

	var inSite = window.parent !== window;

	// De Pixelfart-fonts staan naast dit script (lokaal én op de site). Nooit fonts van internet.
	var me = document.currentScript && document.currentScript.src;
	var fontsCss = Promise.resolve();
	if (me) {
		var css = document.createElement('link');
		css.rel = 'stylesheet';
		css.href = new URL('pixelfart-fonts.css', me).href;
		fontsCss = new Promise(function (ok) { css.onload = css.onerror = ok; });
		(document.head || document.documentElement).appendChild(css);
	}
	var textCache = new Map();
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
		version: '1.1.0',
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

		/**
		 * Laad één of meer Pixelfart-fonts voordat je ze op een canvas gebruikt (canvas wacht niet op fonts).
		 * Pixelfart.fonts(['Press Start 2P', 'bold 8px Silkscreen']).then(start)
		 */
		fonts: function (names) {
			if (!document.fonts) return Promise.resolve();
			names = [].concat(names || []);
			return fontsCss.then(function () { // eerst moet de lijst met fonts binnen zijn
				return Promise.all(names.map(function (n) {
					return document.fonts.load(/\d+px/.test(n) ? n : '16px "' + n + '"').catch(function () {});
				}));
			}).then(function () {});
		},

		/**
		 * Haarscherpe pixeltekst: zoals ctx.fillText, maar zonder wazige randjes.
		 * Gebruikt ctx.font, ctx.fillStyle, ctx.textAlign en ctx.textBaseline. Wordt onthouden, dus zuinig.
		 */
		text: function (ctx, str, x, y) {
			str = String(str);
			var key = ctx.font + '|' + ctx.fillStyle + '|' + str;
			var c = textCache.get(key);
			if (!c) {
				// Altijd links en op de grondlijn tekenen, op hele pixels: dan vallen pixelfonts precies op het raster.
				var g0 = document.createElement('canvas').getContext('2d');
				g0.font = ctx.font; g0.textBaseline = 'alphabetic';
				var m = g0.measureText(str);
				var asc = Math.ceil(Math.max(m.fontBoundingBoxAscent || 0, m.actualBoundingBoxAscent)) + 1;
				var desc = Math.ceil(Math.max(m.fontBoundingBoxDescent || 0, m.actualBoundingBoxDescent)) + 1;
				var cv = document.createElement('canvas');
				cv.width = Math.max(1, Math.ceil(m.width) + 4); cv.height = Math.max(1, asc + desc);
				var g = cv.getContext('2d', { willReadFrequently: true });
				g.font = ctx.font; g.fillStyle = ctx.fillStyle; g.textBaseline = 'alphabetic';
				g.fillText(str, 2, asc);
				var img = g.getImageData(0, 0, cv.width, cv.height), px = img.data;
				for (var i = 3; i < px.length; i += 4) px[i] = px[i] >= 128 ? 255 : 0; // half bedekt = pixel aan
				g.putImageData(img, 0, 0);
				c = { cv: cv, w: m.width, asc: asc, base: {} };
				if (textCache.size > 400) textCache.clear();
				// Alleen onthouden als het font echt geladen is (anders blijft het noodfont hangen).
				if (!document.fonts || document.fonts.check(ctx.font)) textCache.set(key, c);
			}
			// Uitlijning zoals ctx.textAlign / ctx.textBaseline.
			var al = ctx.textAlign, dx = al === 'center' ? c.w / 2 : (al === 'right' || al === 'end') ? c.w : 0;
			var bl = ctx.textBaseline;
			if (c.base[bl] === undefined) {
				var g1 = document.createElement('canvas').getContext('2d');
				g1.font = ctx.font; g1.textBaseline = 'alphabetic';
				var a1 = g1.measureText('H').actualBoundingBoxAscent;
				g1.textBaseline = bl;
				c.base[bl] = a1 - g1.measureText('H').actualBoundingBoxAscent; // grondlijn t.o.v. y
			}
			ctx.drawImage(c.cv, Math.round(x - dx) - 2, Math.round(y + c.base[bl]) - c.asc);
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
