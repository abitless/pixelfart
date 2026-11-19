# Games

De broncode van alle games op [Pixelfart](https://pixelfart.nl). Elke map is één game, genoemd naar de gamecode:

- `game.html`: de game zelf, één los HTML-bestand in het [Pixelfart-format](../plugins/pixelfart-games/skill/pixelfart-game/SKILL.md).
- `game.json`: titel, categorieën, versie en wat er per versie veranderde, topscore-instellingen en de licentie.
- `thumb.png`: het plaatje op de homepage.

## Bijwerken

Deze map is een kopie van wat er op de site staat. Na een nieuwe game of versie in WP-admin schrijf je hem terug met:

```bash
wp pixelfart export wp-content/games
```

Op een nieuwe of andere site zet je alle games erop met:

```bash
wp pixelfart import wp-content/games
```

De site voegt bij het installeren zelf het beveiligingsblok en de SDK toe, en zet Google Fonts om naar lokale kopieën. In deze map staat de HTML daarom zonder die toevoegingen.

## Niet via de website

Deze bestanden zijn alleen bron. De website weigert ze (403): games draaien alleen op de aparte game-host, afgeschermd van de rest van de site.

## Licentie

Code: GPL-3.0-or-later. Tekeningen en geluid: CC BY-SA 4.0. Zie ook `license` in elke `game.json`.
