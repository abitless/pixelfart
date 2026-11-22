---
name: pixelfart-game
description: Maak of verbeter een browsergame voor pixelfart.nl in het Pixelfart-format — één los HTML-bestand in pixelstijl met manifest, zonder externe bestanden, dat los speelbaar is en op de site de platformfuncties gebruikt (schermvullend schalen, auto-pauze, topscores, pixelfonts). Gebruik deze skill bij elke nieuwe Pixelfart-game, bij een update van een bestaande game, en als iemand vraagt een game "klaar te maken voor Pixelfart".
---

# Pixelfart-game maken

Pixelfart is de gamesite van een vader en zoon. Elke game is **één los HTML-bestand** in **pixelstijl** dat op de
site in een beveiligd venster (iframe) draait, ook schermvullend op telefoon, tablet en computer. Deze skill
beschrijft het format. Volg het precies: de site controleert het bij het uploaden.

**Standalone, met platform erbij.** Een game heeft geen andere bestanden nodig: dubbelklik het HTML-bestand en hij
speelt. Draait hij op pixelfart.nl, dan zet de site zelf de Pixelfart SDK (`window.Pixelfart`) en de pixelfonts
klaar, vóór de code van de game. De game gebruikt de SDK als die er is, en anders een kleine ingebouwde vervanging
(de *platformbrug*, zie onder). Er zijn dus **geen bestanden om naast de game te zetten** en de game laadt zelf
nooit `pixelfart.js`.

## Checklist (moet allemaal kloppen)

1. Eén `.html`-bestand. Alles zit erin: code, plaatjes (canvas-tekeningen, of kleine data-URI's), geluid (Web Audio).
   **Nooit iets laden van internet**: geen CDN, geen libraries, geen externe plaatjes, geen `fetch` naar andere sites
   en **ook geen fonts** (geen Google Fonts). De site blokkeert dat. Fonts komen uit de Pixelfart-fonts (zie onder).
2. **Pixelstijl**: alles wat je ziet is opgebouwd uit zichtbare, scherpe pixels (zie *Pixelstijl*).
3. In `<head>`, in deze volgorde: `<meta charset="utf-8">`, `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">`, `<title>`, het manifest. **Geen** `<script src="pixelfart.js">`: de site zet de SDK er zelf in.
4. Een **manifest** (zie hieronder) met minstens `title`, `code` en `version`.
5. Bovenaan de code staat de **platformbrug** (`const PF = window.Pixelfart || …`, zie onder) en de game gebruikt
   daarna alleen `PF.…`. Zo werkt hij los én op de site.
6. Het spel past zich aan **elke schermgrootte** aan (ook staand/liggend en volledig scherm). Gebruik `PF.fit(canvas)`.
7. Werkt met **toetsenbord én aanraken** (en liefst muis). Op een telefoon is er geen toetsenbord.
8. De spel-loop **pauzeert** als het tabblad niet zichtbaar is: gebruik `PF.loop()`. Geluid stil in `PF.onPause()`.
9. Heeft de game een score? Roep **één keer per potje**, bij game over (of bij het halen van de finish), `PF.score(n)` aan.
10. Heeft de game geluid? Dan is het **altijd uit te zetten** (knop in beeld én toets `M`); standaard staat het aan
    (zie *Geluid*).
11. Kun je tegen de computer spelen? Dan zijn er standaard **drie niveaus**: Makkelijk, Gemiddeld en Moeilijk
    (zie *Tegen de computer*).
12. Geen `alert()`, `confirm()`, `prompt()`, `window.open()`, links met `target="_blank"` of `top.location`: dat werkt niet in het beveiligde venster. Toon meldingen in de game zelf.
13. Geen cookies, geen analytics, geen tracking, geen advertenties. Eigen opslag (record, instellingen) mag met `PF.store`.
14. Bovenaan een licentie-opmerking (zie onderaan).

## Bestandsopbouw

```html
<!doctype html>
<!-- Pixelfart · Titel · © 2026 Naam · code: GPL-3.0-or-later · graphics & geluid: CC BY-SA 4.0 -->
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Titel</title>
<script type="application/json" id="pixelfart">
{ "title": "Titel", "code": "TITEL", "version": "1.0.0", "…": "…" }
</script>
<style>
  html, body { margin: 0; height: 100%; background: #000; overflow: hidden; touch-action: none; user-select: none; }
  body { display: grid; place-items: center; }
  canvas { image-rendering: pixelated; }
</style>
</head>
<body>
<canvas id="game" width="320" height="180"></canvas>
<script>
// ---- Platformbrug (letterlijk overnemen) ----
// ...zie hieronder...
// ---- De game ----
const cv = document.getElementById('game');
const ctx = cv.getContext('2d');
ctx.imageSmoothingEnabled = false;
PF.fit(cv, { mode: 'integer' });
PF.fonts(['Press Start 2P']).then(() => PF.loop(update, { draw }));
</script>
</body>
</html>
```

## Platformbrug

Zet dit **letterlijk** bovenaan het script van de game. Op pixelfart.nl is `window.Pixelfart` er al (de echte SDK,
met pixelfonts, topscores en pauze vanuit de site). Los geopend neemt deze vervanging het over: de game schaalt,
pauzeert en onthoudt instellingen, alleen zonder topscorelijst en met een noodfont in plaats van de pixelfonts.

```js
// Pixelfart-platformbrug: op pixelfart.nl de echte SDK, los geopend deze eenvoudige vervanging.
const PF = window.Pixelfart || (() => {
  const onP = [], onR = [];
  document.addEventListener('visibilitychange', () => (document.hidden ? onP : onR).forEach((f) => f()));
  return {
    inSite: false,
    get paused() { return document.hidden; },
    fit(cv, o = {}) {
      const go = () => {
        let s = Math.min(innerWidth / cv.width, innerHeight / cv.height);
        if (o.mode === 'integer' && s >= 1) s = Math.floor(s);
        cv.style.width = Math.floor(cv.width * s) + 'px'; cv.style.height = Math.floor(cv.height * s) + 'px';
      };
      cv.style.imageRendering = 'pixelated'; addEventListener('resize', go); go(); return go;
    },
    loop(update, o = {}) {
      let last = 0, raf = 0, on = true; const gap = 1000 / (o.fps || 60) - 1;
      const frame = (t) => {
        raf = 0; if (!on || document.hidden) return; raf = requestAnimationFrame(frame);
        if (last && t - last < gap) return;
        const dt = last ? Math.min((t - last) / 1000, 0.1) : 0; last = t; update(dt); if (o.draw) o.draw();
      };
      const go = () => { if (!raf && on && !document.hidden) { last = 0; raf = requestAnimationFrame(frame); } };
      onR.push(go); go();
      return { stop() { on = false; cancelAnimationFrame(raf); raf = 0; }, start() { on = true; go(); } };
    },
    onPause: (f) => onP.push(f), onResume: (f) => onR.push(f),
    fonts: () => Promise.resolve(),
    text: (c, s, x, y) => c.fillText(s, Math.round(x), Math.round(y)),
    score() {}, back() {},
    store: {
      get(k, d) { try { const v = localStorage.getItem('pf:' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
      set(k, v) { try { localStorage.setItem('pf:' + k, JSON.stringify(v)); } catch (e) {} },
    },
  };
})();
```

Gebruik in de rest van de game alleen `PF.…` en nooit `Pixelfart.…` rechtstreeks. Geef bij fonts altijd een
noodfont mee, zodat de game los ook leesbaar is: `ctx.font = '8px "Press Start 2P", monospace'`.

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

## De platformfuncties (`PF`)

Op de site is `PF` de Pixelfart SDK; los geopend de platformbrug. De game merkt het verschil niet.

| functie | wat het doet |
|---|---|
| `PF.fit(canvas, { mode })` | Schaalt een canvas met vaste resolutie zo groot mogelijk in het venster, met behoud van verhouding. `mode: 'integer'` = alleen hele vergrotingen (scherpste pixels), `'contain'` = zo groot mogelijk. Teken altijd op de vaste resolutie (`canvas.width/height`), niet op schermpixels. |
| `PF.loop(update, { fps, draw })` | Spel-loop. `update(dt)` krijgt seconden (max 0,1). Pauzeert vanzelf als het tabblad verborgen is of de site pauzeert (bijv. tijdens het invoeren van een topscore). `fps: 30` is genoeg voor rustige games en spaart stroom. Geeft `{ stop, start }` terug. |
| `PF.onPause(fn)` / `onResume(fn)` | Eigen dingen stilzetten/hervatten: muziek, timers. |
| `PF.fonts([namen])` | Laadt Pixelfart-fonts en geeft een Promise. Nodig vóór je ze op een canvas gebruikt: `PF.fonts(['Press Start 2P']).then(start)`. |
| `PF.text(ctx, tekst, x, y)` | Zoals `ctx.fillText`, maar **haarscherp** (zonder wazige randjes). Gebruikt `ctx.font`, `fillStyle`, `textAlign` en `textBaseline`. Wordt onthouden, dus zuinig. Gebruik dit voor alle tekst op het canvas. |
| `PF.score(n)` | Eindscore van een potje (geheel getal ≥ 0). De site vraagt de speler om 3 letters als de score de lijst haalt. |
| `PF.back()` | Terug naar de site (bijv. een knop "Menu verlaten" of de Escape-toets). |
| `PF.store.get(key, standaard)` / `.set(key, waarde)` | Opslag voor deze game (record, instellingen). |
| `PF.inSite` | `true` als de game op de site draait, `false` als je hem los opent. Verberg bijv. "Terug naar de site" als dit `false` is. |
| `PF.paused` | `true` tijdens een pauze. |

Werk je met een game die al een eigen loop heeft? Zet dan in die loop bovenaan `if (PF.paused) return;`
en start hem opnieuw in `PF.onResume(...)`. Liever nog: vervang de loop door `PF.loop`.

Een bestaande game met `<script src="pixelfart.js">` in de head? Haal die regel weg en zet de platformbrug erin.
(De site haalt de regel ook zelf weg, maar los werkt de game dan niet.)

### Schermvullend: zo doe je het

- Kies één vaste, lage resolutie (bijv. 160×120, 320×180 of 256×224) en teken alles daarop.
- `PF.fit(canvas, { mode: 'integer' })` maakt hem zo groot als het scherm toelaat.
- Knoppen en tekst (menu, score) teken je **op het canvas**, of zet je in een laag die met het canvas meeschaalt.
- Invoer omrekenen van schermpixels naar spelpixels:
  `const r = cv.getBoundingClientRect(); const x = (e.clientX - r.left) / r.width * cv.width;`
- Test in de browser met verschillende venstergroottes en in de apparaatmodus van de ontwikkeltools (telefoon staand en liggend).

### Aanraken

- Gebruik `pointerdown` / `pointermove` / `pointerup` (werkt voor muis en vinger). Zet `touch-action: none` op body.
- Voor bewegen: slepen, of grote knoppen op het canvas (links/rechts/actie), minstens 1/8 van de schermbreedte.
- Start/opnieuw: een tik op het scherm, niet alleen een toets.

### Topscores

- Roep `PF.score(n)` precies **één keer** aan als een potje eindigt. Niet elke frame, niet bij elk punt.
- Bij een spel met levels mag je ook na elk gehaald level de **totale** score sturen; de site bewaart per bezoek alleen de beste.
- Tijd als score: gebruik hele seconden (of tienden × 10) en `"order": "asc"` in het manifest.
- Valsspelen kan in een browser altijd; de site legt drempels (minimale speeltijd, maximum per game). Maak de score dus eerlijk en begrensd.

## Pixelstijl

Pixelfart ziet eruit als een oude arcadehal: grote, scherpe pixels. Dit zijn de spelregels; binnen die regels mag je
alles zelf kiezen (eigen kleuren, eigen sfeer, eigen soort game).

**Moet**

- Teken op een **kleine vaste resolutie** en laat `PF.fit(canvas, { mode: 'integer' })` hem opblazen.
  Goede maten: `160×120` of `256×192` (4:3), `320×180` of `256×144` (breedbeeld), `256×224` (klassiek, bijna vierkant).
- **Alles op hele pixels**: posities afronden (`Math.round`) bij het tekenen, `ctx.imageSmoothingEnabled = false`.
- **Geen zachte randen**: geen `shadowBlur`, `filter: blur`, verlopen (gradients), doorzichtige gloed, afgeronde hoeken
  of vloeiende vectorvormen. Een cirkel of schuine lijn teken je als pixels (blokjes), niet met `ctx.arc`.
- **Tekst**: alleen de Pixelfart-fonts, met `PF.text()`. Geen systeemfonts (Arial, monospace), geen emoji.
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

Laad ze vóór het tekenen: `PF.fonts(['Press Start 2P', 'Tiny5']).then(start)`. Op de site staan ze klaar; los
geopend valt de game terug op het noodfont (daarom altijd `, monospace` erachter). Een font dat niet in deze lijst
staat kan niet: de site laadt niets van buiten. Mis je een font? Vraag het aan de beheerder van de site.

## Geluid

Maak geluid zelf met Web Audio, bijv. korte piepjes met een `square`- of `triangle`-oscillator. Dat past bij de
pixelstijl en het bestand blijft klein. Regels:

- **Standaard aan.** Geluid hoort bij het spel.
- **Altijd uit te zetten**, op elk moment (ook tijdens het spelen):
  - een **luidsprekerknop in beeld**, in pixelstijl (bijv. 8×8 of 16×16, een luidspreker met golfjes of met een kruis),
    in een hoek waar hij het spel niet in de weg zit en groot genoeg om met een vinger aan te tikken;
  - en de toets **`M`**.
- De keuze **onthouden** met `PF.store.set('muted', true/false)` en bij het starten teruglezen
  (`PF.store.get('muted', false)`).
- Uit = echt stil: een `GainNode` als hoofdvolume op 0, of geen geluiden meer starten. Ook geen muziek die
  "zachtjes" doorloopt.
- Start de `AudioContext` pas na de eerste klik, tik of toets (eerder mag een browser het niet), en zet hem stil in
  `PF.onPause()` (`audio.suspend()`) en weer aan in `PF.onResume()`.

## Tegen de computer

Vraagt iemand om de computer als tegenstander (of zit er een computerspeler in de game), bied dan standaard
**drie niveaus** aan:

1. **Makkelijk**: de computer maakt duidelijk fouten, denkt kort na of reageert traag. Een jong kind kan winnen.
2. **Gemiddeld**: speelt redelijk, maar mist soms iets.
3. **Moeilijk**: speelt zo goed als de game toelaat (wel eerlijk: hij ziet niets wat de speler niet ziet).

- Kies het niveau **in het startmenu**, voordat het potje begint, met toetsenbord én aanraken. Toon het gekozen niveau
  ook tijdens het spel (klein in de HUD) en op het eindscherm.
- Onthoud de laatste keuze met `PF.store`. De eerste keer staat **Makkelijk** geselecteerd.
- Maak het verschil echt merkbaar, bijv. met zoekdiepte, reactietijd, kans op een "foute" zet of nauwkeurigheid.
- Telt er een topscore mee? Zorg dat die eerlijk blijft tussen de niveaus, bijv. meer punten op een moeilijker
  niveau, of meld alleen scores op één vast niveau. Leg in het manifest (`changelog` of `description`) uit hoe.
- Heeft de game ook een stand voor 2 spelers op één apparaat? Dan staan in het menu: 1 speler (met de drie
  niveaus) en 2 spelers.

## Energie zuinig

- `PF.loop` met een passende `fps` (30 voor rustige games). Geen `setInterval` van 1 ms of eigen bezige lussen.
- Teken niet opnieuw als er niets verandert (bijv. in een menu of pauzescherm).
- Geen enorme data-URI's: houd het bestand klein (liefst < 500 kB). Teken pixel-art met code of kleine sprites.
- Web Audio pas starten na de eerste klik/toets, en stilzetten in `PF.onPause`.

## Updates van een bestaande game

1. Begin vanaf de **laatste versie** (vraag erom als je hem niet hebt). Houd de `code` hetzelfde.
2. Hoog `version` op en vul `changelog` in.
3. Verander niets aan de manier van score-melden zonder dat expliciet te zeggen: oude topscores moeten vergelijkbaar blijven.
4. Na het uploaden bewaart de site de vorige versie automatisch; terugzetten kan altijd.

## Lokaal testen

- Open het HTML-bestand gewoon in de browser: er hoeft niets naast. Je ziet dan het noodfont en er is geen
  topscorelijst; de rest werkt zoals op de site.
- Controleer: schaalt mee bij het verkleinen van het venster? Werkt het met de muis als "vinger"? Pauzeert het als je
  van tabblad wisselt? Gaat het geluid uit met de knop én met `M`, en blijft het uit na herladen? Geen fouten in de
  console, geen verzoeken naar internet (tabblad Netwerk)?
- Op de site (na uploaden) zie je de echte pixelfonts en werkt de topscorelijst.

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
