---
name: pixelfart-game
description: Maak of verbeter een browsergame voor pixelfart.nl in het Pixelfart-format — één HTML-bestand in pixelstijl met manifest, de Pixelfart SDK (schermvullend schalen, auto-pauze, topscores, zelf gehoste pixelfonts) en zonder externe bestanden. Gebruik deze skill bij elke nieuwe Pixelfart-game, bij een update van een bestaande game, en als iemand vraagt een game "klaar te maken voor Pixelfart".
---

# Pixelfart-game maken

Pixelfart is de gamesite van een vader en zoon. Elke game is **één los HTML-bestand** in **pixelstijl** dat op de
site in een beveiligd venster (iframe) draait, ook schermvullend op telefoon, tablet en computer. Deze skill
beschrijft het format. Volg het precies: de site controleert het bij het uploaden.

In deze map:

- `pixelfart.js` — de SDK. Zet hem **naast** je game tijdens het maken en testen.
- `pixelfart-fonts.css` en de map `fonts/` — de Pixelfart-pixelfonts. Ook **naast** je game zetten; de SDK laadt ze zelf.
- `template.html` — een complete, kleine voorbeeldgame (Pixelvanger) die alles goed doet. Begin hiermee.

## Checklist (moet allemaal kloppen)

1. Eén `.html`-bestand. Alles zit erin: code, plaatjes (canvas-tekeningen, of kleine data-URI's), geluid (Web Audio).
   **Nooit iets laden van internet**: geen CDN, geen libraries, geen externe plaatjes, geen `fetch` naar andere sites
   en **ook geen fonts** (geen Google Fonts). De site blokkeert dat. Fonts komen uit de Pixelfart-fonts (zie onder).
2. **Pixelstijl**: alles wat je ziet is opgebouwd uit zichtbare, scherpe pixels (zie *Pixelstijl*).
3. In `<head>`, in deze volgorde: `<meta charset="utf-8">`, `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">`, `<title>`, `<script src="pixelfart.js"></script>`, het manifest.
4. Een **manifest** (zie hieronder) met minstens `title`, `code` en `version`.
5. Het spel past zich aan **elke schermgrootte** aan (ook staand/liggend en volledig scherm). Gebruik `Pixelfart.fit(canvas)`.
6. Werkt met **toetsenbord én aanraken** (en liefst muis). Op een telefoon is er geen toetsenbord.
7. De spel-loop **pauzeert** als het tabblad niet zichtbaar is: gebruik `Pixelfart.loop()`. Muziek stil in `Pixelfart.onPause()`.
8. Heeft de game een score? Roep **één keer per potje**, bij game over (of bij het halen van de finish), `Pixelfart.score(n)` aan.
9. Geen `alert()`, `confirm()`, `prompt()`, `window.open()`, links met `target="_blank"` of `top.location`: dat werkt niet in het beveiligde venster. Toon meldingen in de game zelf.
10. Geen cookies, geen analytics, geen tracking, geen advertenties. Eigen opslag (record, instellingen) mag met `Pixelfart.store`.
11. Bovenaan een licentie-opmerking (zie onderaan).

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
| `Pixelfart.fonts([namen])` | Laadt Pixelfart-fonts en geeft een Promise. Nodig vóór je ze op een canvas gebruikt: `Pixelfart.fonts(['Press Start 2P']).then(start)`. |
| `Pixelfart.text(ctx, tekst, x, y)` | Zoals `ctx.fillText`, maar **haarscherp** (zonder wazige randjes). Gebruikt `ctx.font`, `fillStyle`, `textAlign` en `textBaseline`. Wordt onthouden, dus zuinig. Gebruik dit voor alle tekst op het canvas. |
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

## Pixelstijl

Pixelfart ziet eruit als een oude arcadehal: grote, scherpe pixels. Dit zijn de spelregels; binnen die regels mag je
alles zelf kiezen (eigen kleuren, eigen sfeer, eigen soort game).

**Moet**

- Teken op een **kleine vaste resolutie** en laat `Pixelfart.fit(canvas, { mode: 'integer' })` hem opblazen.
  Goede maten: `160×120` of `256×192` (4:3), `320×180` of `256×144` (breedbeeld), `256×224` (klassiek, bijna vierkant).
- **Alles op hele pixels**: posities afronden (`Math.round`) bij het tekenen, `ctx.imageSmoothingEnabled = false`.
- **Geen zachte randen**: geen `shadowBlur`, `filter: blur`, verlopen (gradients), doorzichtige gloed, afgeronde hoeken
  of vloeiende vectorvormen. Een cirkel of schuine lijn teken je als pixels (blokjes), niet met `ctx.arc`.
- **Tekst**: alleen de Pixelfart-fonts, met `Pixelfart.text()`. Geen systeemfonts (Arial, monospace), geen emoji.
- **Knoppen, menu's en meldingen** horen ook in pixelstijl: getekend op het canvas, of HTML met een Pixelfart-font,
  harde randen en `border-radius: 0`.
- Sprites op een vast raster, bijv. 8×8, 16×16 of 32×32 pixels. Draaien in stappen van 90° of vooraf getekende standen,
  niet vrij draaien (dat geeft wazige pixels). Groter maken alleen met hele factoren (2×, 3×).

**Kleuren**

Kies per game een **beperkt palet**: liefst 8 tot 16 kleuren, maximaal 32. Begin met het Pixelfart-palet (de kleuren
van de site); eigen kleuren, of lichtere en donkerdere tinten van deze kleuren, mogen gerust.

| naam | kleur | gebruik op de site |
|---|---|---|
| zwart | `#000000` | achtergrond |
| wit | `#f2f2f2` | tekst, randen |
| lichtgrijs | `#bdbdbd` | rustige tekst |
| grijs | `#8a8a8a` | hints, uitgeschakeld |
| lijngrijs | `#6a6a6a` | lijnen, kaders |
| donkergrijs | `#2a2a2a` | vlakken, schaduw |
| groen | `#5dde7a` | start, goed, actie |
| blauw | `#6ec9ff` | info, water, lucht |
| geel | `#ffcf4a` | titels, sterren, munten |
| roze | `#ff5c8a` | gevaar, levens, game over |
| paars | `#c9a6ff` | magie, bonus |
| oranje | `#ff9a4a` | vuur, waarschuwing |
| mint | `#9be3d0` | extra accent |

Een zwarte achtergrond past het best bij de site, maar is geen regel: een blauwe lucht of groen gras mag natuurlijk.

**Pixelfonts**

Deze fonts staan op de site zelf (en in de map `fonts/`). Gebruik ze bij naam, bijv. `ctx.font = '8px "Press Start 2P"'`.
Op de genoemde maten vallen de letters precies op het pixelraster.

| font | karakter | scherpe maten |
|---|---|---|
| `Press Start 2P` | klassiek arcade, breed en duidelijk; titels, score | 8, 16, 24 px |
| `Silkscreen` (ook `bold`) | klein en strak; HUD, menu's | 8, 16, 24 px |
| `Tiny5` | heel klein en smal; veel tekst op weinig ruimte | 8, 16 px |
| `VT323` | oude computer/terminal; langere teksten, verhalen | 16 px of groter |
| `Pixelify Sans` (400–700) | vriendelijk en wat ronder; uitleg, dialogen | 16 px of groter |
| `Jersey 10` | stoer scorebord-font; grote titels, cijfers | 19 px of groter |

Laad ze vóór het tekenen: `Pixelfart.fonts(['Press Start 2P', 'Tiny5']).then(start)`. Een font dat niet in deze lijst
staat kan niet: de site laadt niets van buiten. Mis je een font? Vraag het aan de beheerder van de site.

**Geluid** (als je geluid gebruikt): maak het zelf met Web Audio, bijv. korte piepjes met een `square`- of
`triangle`-oscillator. Dat past bij de pixelstijl en het bestand blijft klein.

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

- Zet `pixelfart.js`, `pixelfart-fonts.css` en de map `fonts/` in dezelfde map als de game en open het HTML-bestand
  in de browser.
- Controleer: schaalt mee bij het verkleinen van het venster? Werkt het met de muis als "vinger"? Pauzeert het als je
  van tabblad wisselt? Is alle tekst scherp en in een Pixelfart-font? Geen fouten in de console, geen verzoeken naar
  internet (tabblad Netwerk)?

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
