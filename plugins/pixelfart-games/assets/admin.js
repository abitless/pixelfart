/* Pixelfart Games: beheer. Gamecode voorstellen en automatische, pixelige thumbnails. */
(() => {
	const $ = (id) => document.getElementById(id);

	/* ---------- gamecode voorstellen op basis van de titel ---------- */
	const code = $('pf_code');
	const title = $('title');
	if (code && title && code.dataset.autosuggest) {
		let touched = false;
		code.addEventListener('input', () => { touched = true; code.value = code.value.toUpperCase().replace(/[^A-Z]/g, ''); });
		title.addEventListener('input', () => {
			if (touched) return;
			const letters = title.value.normalize('NFD').replace(/[^A-Za-z]/g, '').toUpperCase();
			code.value = letters.slice(0, 5);
		});
	}

	/* ---------- thumbnails ---------- */
	const W = 96, H = 72, SCALE = 4; // 96×72 pixels, 4× opgeschaald = 384×288

	/** Teken een bron (canvas/img) 4:3 bijgesneden op 96×72 en schaal hard op. */
	function pixelate(src, sw, sh) {
		const small = document.createElement('canvas');
		small.width = W; small.height = H;
		const sx = small.getContext('2d');
		sx.imageSmoothingEnabled = true;
		sx.imageSmoothingQuality = 'high';
		const r = Math.max(W / sw, H / sh);
		const dw = sw * r, dh = sh * r;
		sx.fillStyle = '#000';
		sx.fillRect(0, 0, W, H);
		sx.drawImage(src, (W - dw) / 2, (H - dh) / 2, dw, dh);
		// beperkt palet: elk kanaal naar 6 niveaus
		const img = sx.getImageData(0, 0, W, H);
		for (let i = 0; i < img.data.length; i += 4) {
			for (let c = 0; c < 3; c++) img.data[i + c] = Math.round(img.data[i + c] / 51) * 51;
		}
		sx.putImageData(img, 0, 0);
		const big = document.createElement('canvas');
		big.width = W * SCALE; big.height = H * SCALE;
		const bx = big.getContext('2d');
		bx.imageSmoothingEnabled = false;
		bx.drawImage(small, 0, 0, big.width, big.height);
		return big.toDataURL('image/png');
	}

	/** Plaatshouder als er geen canvas te vangen is: diagonale strepen + titel. */
	function placeholder() {
		const c = document.createElement('canvas');
		c.width = W; c.height = H;
		const x = c.getContext('2d');
		x.fillStyle = '#000'; x.fillRect(0, 0, W, H);
		x.fillStyle = '#1f5a2c';
		for (let i = -H; i < W; i += 8) {
			x.beginPath(); x.moveTo(i, H); x.lineTo(i + H, 0); x.lineTo(i + H + 4, 0); x.lineTo(i + 4, H); x.fill();
		}
		x.fillStyle = '#000'; x.fillRect(6, H / 2 - 9, W - 12, 18);
		x.fillStyle = '#f2f2f2'; x.font = '8px monospace'; x.textAlign = 'center'; x.textBaseline = 'middle';
		x.fillText((title && title.value || 'GAME').toUpperCase().slice(0, 14), W / 2, H / 2);
		return pixelate(c, W, H);
	}

	function setThumb(dataUrl) {
		$('pf_thumb_data').value = dataUrl;
		const img = $('pf_thumb_img');
		img.src = dataUrl; img.hidden = false;
		$('pf_thumb_empty').hidden = true;
	}

	/**
	 * Screenshot via de thumbnail-hulp op de game-origin (PFA.capture). De game draait daar, nooit in wp-admin.
	 * Geeft een PNG-data-URL van het grootste canvas terug, of null.
	 */
	function capture(payload, stage) {
		return new Promise((resolve) => {
			const id = Math.random().toString(36).slice(2);
			const frame = document.createElement('iframe');
			frame.title = 'Voorbeeld voor de thumbnail';
			frame.src = PFA.capture;
			let done = false;
			const finish = (v) => { if (done) return; done = true; removeEventListener('message', onMsg); clearTimeout(timer); resolve(v); };
			const onMsg = (e) => {
				if (e.origin !== PFA.origin || e.source !== frame.contentWindow || !e.data) return;
				if (e.data.type === 'pf-capture-ready') frame.contentWindow.postMessage(Object.assign({ type: 'pf-capture', id, wait: 3200 }, payload), PFA.origin);
				if (e.data.type === 'pf-capture-result' && e.data.id === id) finish(e.data.data || null);
			};
			const timer = setTimeout(() => finish(null), 15000);
			addEventListener('message', onMsg);
			stage.appendChild(frame);
		});
	}

	const loadImage = (src) => new Promise((ok, bad) => { const i = new Image(); i.onload = () => ok(i); i.onerror = bad; i.src = src; });

	const btn = $('pf_thumb_auto');
	const stage = $('pf_thumb_stage');

	btn && btn.addEventListener('click', async () => {
		const file = $('pf_html').files[0];
		const payload = file ? { html: await file.text() } : (btn.dataset.code ? { code: btn.dataset.code } : null);
		if (!payload) { alert('Kies eerst een HTML-bestand.'); return; }

		btn.disabled = true;
		btn.textContent = 'Game draait…';
		stage.hidden = false;
		stage.innerHTML = '';

		let data = null;
		const png = await capture(payload, stage);
		if (png) {
			try { const img = await loadImage(png); data = pixelate(img, img.naturalWidth, img.naturalHeight); } catch (e) { /* leeg */ }
		}
		stage.innerHTML = '';
		stage.hidden = true;
		setThumb(data || placeholder());
		if (!data) alert('Geen speelveld gevonden om vast te leggen; er staat nu een plaatshouder. Je kunt ook zelf een afbeelding uploaden.');

		btn.disabled = false;
		btn.textContent = 'Opnieuw';
	});

	/* Eigen upload: in de browser pixelig maken (vinkje), anders ongewijzigd laten uploaden. */
	const fileIn = $('pf_thumb_file');
	fileIn && fileIn.addEventListener('change', () => {
		const f = fileIn.files[0];
		if (!f || !$('pf_thumb_pixelate').checked) return;
		const img = new Image();
		img.onload = () => {
			setThumb(pixelate(img, img.naturalWidth, img.naturalHeight));
			fileIn.value = ''; // de pixelige versie gaat via pf_thumb_data mee
			URL.revokeObjectURL(img.src);
		};
		img.src = URL.createObjectURL(f);
	});
})();
