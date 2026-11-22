# Pixelfart

[Pixelfart](https://pixelfart.nl) is de gamesite van een vader en zoon: kleine pixelgames die je meteen in de browser speelt. Gratis, zonder reclame, zonder accounts en zonder trucjes om je langer te laten spelen.

Dit is de code van de site: het WordPress-thema, de plugin die de games, topscores, reacties en de pixelmuur regelt, en de games zelf. Alles is open source. Kijk gerust mee hoe het werkt, en leer er iets van.

## Voor ouders

Hoi! Ik ben Nathan, de vader van het vader-en-zoonduo achter Pixelfart. In de topscores heet ik NAT. Hieronder lees je wat ik doe, wat ik belangrijk vind en hoe je dat terugziet op de site. Handig als je wilt weten waar je kind eigenlijk speelt. Dezelfde tekst staat op de site onder [Voor ouders](https://pixelfart.nl/ouders/).

### Wat ik doe

Met mijn bedrijf [Bitless](https://bitless.nl) bouw en host ik websites. Pixelfart is iets anders: een hobbyproject met mijn zoon. Samen bedenken en bouwen we kleine pixelgames, gewoon omdat het leuk is. Bitless sponsort de hosting.

Omdat ik dagelijks zie hoe websites en apps gemaakt worden, weet ik ook welke keuzes erachter zitten. Over vier dingen heb ik een uitgesproken mening, en die zie je overal op Pixelfart terug.

### Big tech

Een groot deel van het internet draait op een handvol enorme techbedrijven. Die verdienen hun geld vooral met aandacht en gegevens. Ik vind dat kinderen daar niet het product van horen te zijn.

- **Geen Google:** geen Google Analytics, geen lettertypes van Google, geen reCAPTCHA. De lettertypes en de robotcheck ([ALTCHA](https://altcha.org)) draaien op onze eigen server.
- **Geen sociale media:** geen like-knoppen, geen ingesloten filmpjes, geen inloggen met een ander account.
- **In Nederland:** de site staat op servers in Nederland. Doneren gaat via Mollie, ook een Nederlands bedrijf.
- **Open source:** alle code staat hier. Iedereen kan meekijken hoe het werkt.

### Dark patterns

Dark patterns zijn slimme trucjes in apps en games die je verleiden om langer te blijven of meer te betalen dan je eigenlijk wilde. Denk aan eindeloos scrollen, een dagelijkse beloning die je kwijtraakt als je een dag overslaat, lootboxen, munten die je met echt geld koopt, of een timer die zegt dat je nú moet beslissen. Juist kinderen zijn daar gevoelig voor. Hier vind je ze niet:

- **Geen meldingen, geen streaks, geen dagelijkse beloningen.** Je speelt als je zin hebt, en stopt als je klaar bent.
- **Geen aankopen in de games:** geen munten, geen extra levens, geen lootboxen.
- **Doneren is nooit nodig** en we vragen er nooit om in een game. Op de [doneerpagina](https://pixelfart.nl/doneren/) staat standaard het kleinste bedrag en eenmalig aangevinkt, zie je hoeveel er naar de betaaldienst gaat, en stop je een maandelijkse donatie met één klik.
- **Eerst bekeken:** reacties, boodschappen en tekeningen op de pixelmuur bekijken we eerst voordat ze online komen.

### Privacy

Spelen moet zorgeloos kunnen, ook voor kinderen. Daarom verzamelen we zo weinig mogelijk.

- **Geen accounts, geen cookies, geen tracking, geen advertenties.**
- **Drie letters:** bij reacties, topscores en pixels gebruik je drie letters, geen echte naam.
- **Statistieken zonder profielen:** we willen wel weten hoeveel mensen er spelen en welke games populair zijn. Daarvoor gebruiken we [Cabin](https://withcabin.com): zonder cookies, zonder profielen en zonder je internetadres op te slaan. We zien alleen totalen.
- **Afgeschermde games:** games draaien in een afgeschermd venster op een eigen domein. Ze mogen niets van internet laden en kunnen niet bij de rest van de site.
- **Alles op een rij:** wat we wél bewaren en waarom, staat in de [privacyverklaring](https://pixelfart.nl/privacy/).

### Duurzaamheid

Ook een website kost stroom: op de server, onderweg over het internet en op je eigen apparaat. Hoe minder je verstuurt en rekent, hoe minder stroom. Pixelart helpt daar toevallig mooi bij.

- **Klein:** elke game is één klein bestand, zonder zware game-engine.
- **Pauze:** een game pauzeert vanzelf als je een ander tabblad opent of wegklikt. Dan rekent hij niet voor niets door.
- **Licht:** geen video's en geen trackers, die vaak het zwaarste deel van een webpagina zijn. Het telscriptje van Cabin is maar 2 kB.
- **Pas als het nodig is:** extra's laden pas als je ze gebruikt. De robotcheck bijvoorbeeld pas als je een formulier invult.
- **Groen:** de site draait op groene hosting in Nederland.

### Vragen?

Twijfel je ergens over, of zie je iets wat niet klopt met wat hier staat? Laat het me weten via de [contactpagina](https://pixelfart.nl/contact/). Ik lees alles zelf.

## Wat zit er in deze repository

Dit is de map `wp-content` van de WordPress-site. Alleen wat we zelf maken staat hier; plugins, thema's en vertalingen van anderen werken zichzelf bij en staan in `.gitignore`.

| Map | Wat |
|---|---|
| `themes/pixelfart/` | Het thema: pixelstijl, de grijpmachine als menu, de spelerpagina. |
| `plugins/pixelfart-games/` | Games, categorieën, topscores, reacties, ideeën en contact, nieuwsbrief, aankondigingen, doneren en de pixelmuur. Zie de README in die map. |
| `plugins/pixelfart-games/skill/` | Het Pixelfart-format (één `SKILL.md`) voor wie zelf een game maakt. |
| `plugins/pixelfart-games/sdk/` | De SDK en pixelfonts die de site bij elke game zet. |
| `mu-plugins/bitless-code.php` | Automatische updates voor WordPress, plugins en thema's. |
| `games/` | De broncode van alle games (zie hieronder). |

### Games

Elke game staat in `games/<CODE>/`: `game.html` (de game zelf, één bestand), `game.json` (titel, categorieën, versies, topscores) en `thumb.png`. Zet ze op een site met:

```bash
wp pixelfart import wp-content/games
```

En schrijf ze na wijzigingen in WP-admin weer terug naar de repository met:

```bash
wp pixelfart export wp-content/games
```

## Licenties

- **Thema en plugin:** GPL-2.0-or-later (zie de `LICENSE` in hun map).
- **Games:** GPL-3.0-or-later voor de code en CC BY-SA 4.0 voor de tekeningen (staat ook per game in `game.json`).
- **Lettertypes:** Press Start 2P, Pixelify Sans, VT323 en de andere pixelfonts vallen onder de SIL Open Font License 1.1 (de licentietekst staat naast de fonts).

## Iets gevonden?

Een fout, een veiligheidsprobleem of iets wat niet klopt met wat hierboven staat? Meld het via de [contactpagina](https://pixelfart.nl/contact/). Een veiligheidsprobleem liever niet als openbaar issue.
