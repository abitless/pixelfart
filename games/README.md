# Games

De broncode van alle games op [Pixelfart](https://pixelfart.nl). Elke map is één game, genoemd naar de gamecode:

- `game.html`: de game zelf, één los HTML-bestand in het [Pixelfart-format](../plugins/pixelfart-games/skill/pixelfart-game/SKILL.md).
- `game.json`: titel, categorieën, versie en wat er per versie veranderde, topscore-instellingen en de licentie.
- `thumb.png`: het plaatje op de homepage.

## Bijwerken

Deze map is de bron van wat er live staat. Een game aanpassen gaat zo:

1. Wijzig `<CODE>/game.html`, hoog `version` op en vul `changelog` in het manifest in.
2. Zet hem live: `games/bin/game deploy <CODE>` (eventueel met `--note "…"` voor een andere tekst in de
   aankondiging). Het script:
   - stopt als live intussen gewijzigd is (bijv. via WP-admin geüpload), zodat je niets overschrijft;
   - controleert dat de versie hoger is dan live;
   - uploadt met `wp pixelfart update` (de site bewaart de vorige versie en maakt een aankondiging);
   - schrijft de live stand terug (ook `game.json` en `thumb.png`), en commit en pusht.

Toch iets via WP-admin geüpload, of een nieuwe game toegevoegd? Haal de live stand op met
`games/bin/game pull` (dat is `wp pixelfart export` op de server, terug in deze map, gecommit en gepusht).
`games/bin/game status` laat per game zien wat er verschilt.

Een nieuwe game komt de eerste keer via WP-admin online (Games › Nieuwe game uploaden: categorieën,
thumbnail); daarna `pull`, en vanaf dan gaat hij via `deploy`.

Op een nieuwe of andere site zet je alle games erop met:

```bash
wp pixelfart import wp-content/games
```

De site voegt bij het installeren zelf het beveiligingsblok en de SDK toe, en zet Google Fonts om naar lokale kopieën. In deze map staat de HTML daarom zonder die toevoegingen.

## Niet via de website

Deze bestanden zijn alleen bron. De website weigert ze (403): games draaien alleen op de aparte game-host, afgeschermd van de rest van de site.

## Licentie

Code: GPL-3.0-or-later. Tekeningen en geluid: CC BY-SA 4.0. Zie ook `license` in elke `game.json`.
