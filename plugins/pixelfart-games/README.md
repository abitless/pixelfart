# Pixelfart Games

WordPress-plugin voor [Pixelfart](https://pixelfart.nl): browsergames als losse HTML-bestanden, met metadata (`game.json`),
pixelige thumbnails, speelteller, sterren, gemodereerde reacties, idee- en contactformulier en topscores aller tijden.
Hoort bij het thema `pixelfart`. Licentie: GPL-2.0-or-later (de games zelf: GPL-3.0-or-later + CC BY-SA 4.0).

## Opslag

```
wp-content/uploads/pixelfart-games/
  <CODE>/game.html          de game (fonts lokaal, CSP en SDK ingevoegd)
  <CODE>/game.json          statische metadata (wordt bij elke wijziging opnieuw geschreven)
  <CODE>/thumb.png          pixelige thumbnail 4:3
  <CODE>/versions/*.html    eerdere versies (terugzetten in WP-admin)
  _fonts/                   lokaal gezette Google Fonts
  _sdk/pixelfart.js         Pixelfart SDK (bron: skill/pixelfart-game/pixelfart.js)
  _tools/capture.html       maakt thumbnails op de game-origin
```

Topscores staan in de tabel `wp_pf_scores` (alleen 3 letters, score, datum; per game de beste 100).

## Beveiliging en privacy

- **Games op een eigen origin.** Een game is eigen JavaScript en draait daarom op een apart (sub)domein, zoals
  `https://play.pixelfart.nl`. Zo kan een game nooit bij de inlog-cookies of wp-admin. Stel het adres in onder
  Instellingen › Pixelfart, of in `wp-config.php`: `define( 'PIXELFART_GAMES_URL', 'https://play.pixelfart.nl/' );`
- **CSP in elke game.** Niets laden of versturen buiten de game-origin: geen trackers, geen externe scripts.
- **Sandbox** op de speler-iframe (geen pop-ups, formulieren, top-navigatie of downloads).
- **ALTCHA** (MIT, zelf gehost, zonder cookies) op idee-, contact-, reactie- en topscoreformulier; plus honeypot,
  linkfilter, WordPress-woordenlijst en limiet per bezoeker (gehasht IP-adres, alleen kortdurend in de cache).
- Bij reacties wordt geen IP-adres of browser opgeslagen.

## Live zetten op CyberPanel / OpenLiteSpeed

1. Maak in CyberPanel het subdomein `play.pixelfart.nl` aan, met SSL.
2. Laat de documentroot van dat subdomein naar de gamemap wijzen. De makkelijkste manier is een symlink:
   ```
   mv /home/pixelfart.nl/play.pixelfart.nl /home/pixelfart.nl/play.pixelfart.nl-leeg   # lege map van CyberPanel opzij
   ln -s /home/pixelfart.nl/public_html/wp-content/uploads/pixelfart-games /home/pixelfart.nl/play.pixelfart.nl
   ```
   (paden kunnen per server verschillen). Zet in de vHost van het subdomein *Follow Symbolic Link* aan.
3. Zet PHP uit voor het subdomein (de map bevat alleen HTML, JSON, PNG, CSS, JS en fonts). In de vHost-configuratie
   van het subdomein kun je daarnaast deze header toevoegen (context `/`, *Header Operations*):
   ```
   Content-Security-Policy: frame-ancestors https://pixelfart.nl
   ```
   Zo kunnen andere sites de games niet in hun eigen pagina tonen.
4. Zet `PIXELFART_GAMES_URL` in `wp-config.php` op `https://play.pixelfart.nl/` en draai:
   ```
   wp pixelfart reprocess   # hulpbestanden en .htaccess bijwerken, alle games opnieuw verwerken
   ```
5. Herstart OpenLiteSpeed na een wijziging in `.htaccess` (OLS leest die anders niet opnieuw in).

Lokaal (bitless-wpdistro) draait de game-host op `https://play.pixelfart.localtest.me` via `docker-compose.override.yml`;
de `.htaccess` laat op die host alleen de gamemap door.

## WP-CLI

```
wp pixelfart import <map>        games importeren uit */game.json + game.html + thumb.png
wp pixelfart update <bestand>    nieuwe versie van een game zetten (code/versie/changelog uit het manifest)
wp pixelfart reprocess           alle games opnieuw verwerken (fonts, CSP, SDK) en hulpbestanden bijwerken
wp pixelfart rebuild-json        alle game.json opnieuw schrijven
```

## Gameformat

Zie `skill/pixelfart-game/SKILL.md`. Die map is ook een skill voor Claude: in WP-admin onder **Games › Voor makers**
staat een downloadknop voor de zip.
