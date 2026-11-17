---
name: pixelfart-game
description: Maak of verbeter een browsergame voor pixelfart.nl in het Pixelfart-format — één HTML-bestand met manifest, de Pixelfart SDK (schermvullend schalen, auto-pauze, topscores) en zonder externe bestanden. Gebruik deze skill bij elke nieuwe Pixelfart-game, bij een update van een bestaande game, en als iemand vraagt een game "klaar te maken voor Pixelfart".
---

# Pixelfart-game maken

Pixelfart is de gamesite van een vader en zoon. Elke game is **één los HTML-bestand** dat op de site in een
beveiligd venster (iframe) draait, ook schermvullend op telefoon, tablet en computer. Deze skill beschrijft het
format. Volg het precies: de site controleert het bij het uploaden.

In deze map:

- `pixelfart.js` — de SDK. Zet hem **naast** je game tijdens het maken en testen.
- `template.html` — een complete, kleine voorbeeldgame (Pixelvanger) die alles goed doet. Begin hiermee.

## Checklist (moet allemaal kloppen)

1. Eén `.html`-bestand. Alles zit erin: code, plaatjes (canvas-tekeningen, of kleine data-URI's), geluid (Web Audio).
   **Niets** laden van internet: geen CDN, geen externe plaatjes, geen `fetch` naar andere sites. De site blokkeert dat.
   Uitzondering: één Google Fonts-`<link>` mag; de site zet dat font automatisch lokaal.
2. In `<head>`, in deze volgorde: `<meta charset="utf-8">`, `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">`, `<title>`, `<script src="pixelfart.js"></script>`, het manifest.
3. Een **manifest** (zie hieronder) met minstens `title`, `code` en `version`.
4. Het spel past zich aan **elke schermgrootte** aan (ook staand/liggend en volledig scherm). Gebruik `Pixelfart.fit(canvas)`.
5. Werkt met **toetsenbord én aanraken** (en liefst muis). Op een telefoon is er geen toetsenbord.
6. De spel-loop **pauzeert** als het tabblad niet zichtbaar is: gebruik `Pixelfart.loop()`. Muziek stil in `Pixelfart.onPause()`.
7. Heeft de game een score? Roep **één keer per potje**, bij game over (of bij het halen van de finish), `Pixelfart.score(n)` aan.
8. Geen `alert()`, `confirm()`, `prompt()`, `window.open()`, links met `target="_blank"` of `top.location`: dat werkt niet in het beveiligde venster. Toon meldingen in de game zelf.
9. Geen cookies, geen analytics, geen tracking, geen advertenties. Eigen opslag (record, instellingen) mag met `Pixelfart.store`.
10. Bovenaan een licentie-opmerking (zie onderaan).

## Bestandsopbouw

```html
<!doctype html>
<!-- Pixelfart · Titel · © 2026 Naam · code: GPL-3.0-or-later · graphics & geluid: CC BY-SA 4.0 -->
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Titel</title>
<script src="pixelfart.js"></script>
<script type="application/json" id="pixelfart">
{ "title": "Titel", "code": "TITEL", "version": "1.0.0", "…": "…" }
</script>
<style>
  html, body { margin: 0; height: 100%; background: #000; overflow: hidden; touch-action: none; }
  body { display: grid; place-items: center; }
  canvas { image-rendering: pixelated; }
</style>
</head>
<body>
<canvas id="game" width="320" height="180"></canvas>
<script>
  const cv = document.getElementById('game');
  Pixelfart.fit(cv, { mode: 'integer' });
  Pixelfart.loop(update, { draw });
</script>
</body>
</html>
```

## Manifest

Een JSON-blok met `id="pixelfart"`. Het uploadformulier op de site vult zich hiermee.

| veld | verplicht | uitleg |
|---|---|---|
| `title` | ja | Naam van de game. |
| `code` | ja | Precies **5 hoofdletters** (A–Z), uniek, bijv. `PIZZA`. **Nooit veranderen** bij een update. |
| `version` | ja | `MAJOR.MINOR.PATCH`, bijv. `1.2.0`. Bij elke update ophogen: kleine fix → laatste cijfer, nieuwe dingen → middelste. |
| `description` | ja | Eén of twee zinnen, max. 240 tekens, voor kinderen begrijpelijk. |
| `categories` | ja | Uit: `Arcade`, `Puzzel`, `Retro`, `2 spelers`, `Snel`, `Actie`, `Rustig`, `Avontuur`, `Strategie`. |
| `age` | nee | `ALL` (standaard), `6+`, `8+`, `10+`, `12+` of `16+`. |
| `author` | nee | Wie de game maakte. |
| `changelog` | bij update | Wat is er nieuw in deze versie, in één zin. |
| `score` | nee | `{ "order": "desc", "label": "punten" }` — `desc` = hoger is beter, `asc` = lager is beter (bijv. tijd in seconden). Weglaten = geen topscorelijst. |

## De SDK (`pixelfart.js`)

| functie | wat het doet |
|---|---|
| `Pixelfart.fit(canvas, { mode })` | Schaalt een canvas met vaste resolutie zo groot mogelijk in het venster, met behoud van verhouding. `mode: 'integer'` = alleen hele vergrotingen (scherpste pixels), `'contain'` = zo groot mogelijk. Teken altijd op de vaste resolutie (`canvas.width/height`), niet op schermpixels. |
| `Pixelfart.loop(update, { fps, draw })` | Spel-loop. `update(dt)` krijgt seconden (max 0,1). Pauzeert vanzelf als het tabblad verborgen is of de site pauzeert (bijv. tijdens het invoeren van een topscore). `fps: 30` is genoeg voor rustige games en spaart stroom. Geeft `{ stop, start }` terug. |
| `Pixelfart.onPause(fn)` / `onResume(fn)` | Eigen dingen stilzetten/hervatten: muziek, timers. |
| `Pixelfart.score(n)` | Eindscore van een potje (geheel getal ≥ 0). De site vraagt de speler om 3 letters als de score de lijst haalt. |
| `Pixelfart.back()` | Terug naar de site (bijv. een knop "Menu verlaten" of de Escape-toets). |
| `Pixelfart.store.get(key, standaard)` / `.set(key, waarde)` | Opslag voor deze game (record, instellingen). |
| `Pixelfart.inSite` | `true` als de game op de site draait, `false` als je hem los opent. |
| `Pixelfart.paused` | `true` tijdens een pauze. |

Werk je met een game die al een eigen loop heeft? Zet dan in die loop bovenaan `if (Pixelfart.paused) return;`
en start hem opnieuw in `Pixelfart.onResume(...)`. Liever nog: vervang de loop door `Pixelfart.loop`.

### Schermvullend: zo doe je het

- Kies één vaste, lage resolutie (bijv. 160×120, 320×180 of 256×224) en teken alles daarop.
- `Pixelfart.fit(canvas, { mode: 'integer' })` maakt hem zo groot als het scherm toelaat.
- Knoppen en tekst (menu, score) teken je **op het canvas**, of zet je in een laag die met het canvas meeschaalt.
- Invoer omrekenen van schermpixels naar spelpixels:
  `const r = cv.getBoundingClientRect(); const x = (e.clientX - r.left) / r.width * cv.width;`
- Test in de browser met verschillende venstergroottes en in de apparaatmodus van de ontwikkeltools (telefoon staand en liggend).

### Aanraken

- Gebruik `pointerdown` / `pointermove` / `pointerup` (werkt voor muis en vinger). Zet `touch-action: none` op body.
- Voor bewegen: slepen, of grote knoppen op het canvas (links/rechts/actie), minstens 1/8 van de schermbreedte.
- Start/opnieuw: een tik op het scherm, niet alleen een toets.

### Topscores

- Roep `Pixelfart.score(n)` precies **één keer** aan als een potje eindigt. Niet elke frame, niet bij elk punt.
- Bij een spel met levels mag je ook na elk gehaald level de **totale** score sturen; de site bewaart per bezoek alleen de beste.
- Tijd als score: gebruik hele seconden (of tienden × 10) en `"order": "asc"` in het manifest.
- Valsspelen kan in een browser altijd; de site legt drempels (minimale speeltijd, maximum per game). Maak de score dus eerlijk en begrensd.

## Energie zuinig

- `Pixelfart.loop` met een passende `fps` (30 voor rustige games). Geen `setInterval` van 1 ms of eigen bezige lussen.
- Teken niet opnieuw als er niets verandert (bijv. in een menu of pauzescherm).
- Geen enorme data-URI's: houd het bestand klein (liefst < 500 kB). Teken pixel-art met code of kleine sprites.
- Web Audio pas starten na de eerste klik/toets, en stoppen in `onPause`.

## Updates van een bestaande game

1. Begin vanaf de **laatste versie** (vraag erom als je hem niet hebt). Houd de `code` hetzelfde.
2. Hoog `version` op en vul `changelog` in.
3. Verander niets aan de manier van score-melden zonder dat expliciet te zeggen: oude topscores moeten vergelijkbaar blijven.
4. Na het uploaden bewaart de site de vorige versie automatisch; terugzetten kan altijd.

## Lokaal testen

- Zet `pixelfart.js` in dezelfde map als de game en open het HTML-bestand in de browser.
- Controleer: schaalt mee bij het verkleinen van het venster? Werkt het met de muis als "vinger"? Pauzeert het als je
  van tabblad wisselt? Geen fouten in de console?

## Licentie

Code van Pixelfart-games is **GPL-3.0-or-later**, graphics en geluid zijn **CC BY-SA 4.0**. Zet bovenaan het bestand:

```html
<!-- Pixelfart · Titel · © 2026 Naam · code: GPL-3.0-or-later · graphics & geluid: CC BY-SA 4.0 -->
```

Gebruik geen code, plaatjes of geluiden van anderen tenzij de licentie dat toestaat (en vermeld ze dan in deze opmerking).
Maak geen namaak van bestaande, bekende games (namen, figuren, levels): bedenk iets eigens.

## Afleveren

Lever het HTML-bestand op. Uploaden kan in WordPress: **Games › Nieuwe game uploaden** (nieuw) of bij een bestaande game
een nieuw HTML-bestand kiezen. De site vult het formulier uit het manifest, maakt een thumbnail en meldt wat er nog
beter kan.

Voor je oplevert, loop de checklist bovenaan na en zeg kort wat je hebt gecontroleerd.
