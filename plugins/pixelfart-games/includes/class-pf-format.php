<?php
/**
 * Het Pixelfart-gameformat: manifest in de HTML, SDK, en een controle bij het uploaden.
 *
 * Een game is één HTML-bestand. Optioneel staat bovenin een manifest:
 *
 *   <script type="application/json" id="pixelfart">
 *   { "title": "Hete Pizza", "code": "PIZZA", "version": "1.1.0", "description": "…",
 *     "categories": ["Arcade", "Snel"], "age": "ALL", "author": "…",
 *     "changelog": "Snellere scooter in de garage.",
 *     "score": { "order": "desc", "label": "punten" } }
 *   </script>
 *
 * Het uploadformulier vult zich daarmee; wat je in WordPress invult wint. De volledige uitleg staat in
 * skill/pixelfart-game/SKILL.md (de skill waarmee Claude games in dit format maakt).
 */

defined( 'ABSPATH' ) || exit;

class PF_Format {

	const SDK_FILE = 'skill/pixelfart-game/pixelfart.js';

	public static function init() {
		add_filter( 'pixelfart_game_head', [ __CLASS__, 'sdk_tag' ] );
		add_action( 'pixelfart_deploy', [ __CLASS__, 'deploy_sdk' ] );
	}

	public static function sdk_version(): string {
		return substr( md5_file( PIXELFART_DIR . self::SDK_FILE ), 0, 8 );
	}

	/** De SDK en de pixelfonts komen mee op de game-origin: <base>/_sdk/. */
	public static function deploy_sdk( string $base_dir ) {
		$src = dirname( PIXELFART_DIR . self::SDK_FILE ) . '/';
		wp_mkdir_p( $base_dir . '_sdk/fonts' );
		copy( $src . 'pixelfart.js', $base_dir . '_sdk/pixelfart.js' );
		copy( $src . 'pixelfart-fonts.css', $base_dir . '_sdk/pixelfart-fonts.css' );
		foreach ( glob( $src . 'fonts/*.{woff2,txt}', GLOB_BRACE ) ?: [] as $f ) {
			copy( $f, $base_dir . '_sdk/fonts/' . basename( $f ) );
		}
	}

	public static function sdk_tag( string $head ): string {
		return $head . '<script src="../_sdk/pixelfart.js?v=' . esc_attr( self::sdk_version() ) . '"></script>';
	}

	/** Haal de eigen verwijzing naar pixelfart.js weg: de site zet de nieuwste versie bovenin. */
	public static function strip_local_sdk( string $html ): string {
		return preg_replace( '#<script\b[^>]*\bsrc=["\'](?:\./)?pixelfart(?:\.min)?\.js(?:\?[^"\']*)?["\'][^>]*>\s*</script>\s*#i', '', $html );
	}

	/** Manifest uit de HTML, of null. */
	public static function manifest( string $html ): ?array {
		if ( ! preg_match( '#<script\b[^>]*\bid=["\']pixelfart["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			return null;
		}
		$data = json_decode( trim( $m[1] ), true );
		return is_array( $data ) ? $data : [ '_invalid' => true ];
	}

	/**
	 * Controleer een game tegen het format. Blokkeert niets; geeft tips terug.
	 *
	 * @return array<int, array{0:string,1:string}> [ niveau (warning|info), bericht ]
	 */
	public static function check( string $html ): array {
		$out  = [];
		$code = preg_replace( '#<script\b[^>]*type=["\']application/json["\'][^>]*>.*?</script>#is', '', $html );

		$manifest = self::manifest( $html );
		if ( isset( $manifest['_invalid'] ) ) {
			$out[] = [ 'warning', 'Het manifest (<script id="pixelfart">) is geen geldige JSON en is genegeerd.' ];
		}
		if ( ! preg_match( '#<meta[^>]+name=["\']?viewport\b#i', $html ) ) {
			$out[] = [ 'warning', 'Geen <meta name="viewport">: op telefoons wordt de game piepklein.' ];
		}

		// Alles van buiten wordt door de beveiliging (CSP) geblokkeerd.
		$hosts = [];
		if ( preg_match_all( '#(?:src|href)\s*=\s*["\']\s*(?:https?:)?//([^/"\'\s]+)#i', $code, $m ) ) {
			$hosts = array_merge( $hosts, $m[1] );
		}
		if ( preg_match_all( '#(?:url\(|fetch\(|import\s*\(|from\s+)\s*["\']?(?:https?:)?//([^/"\')\s]+)#i', $code, $m ) ) {
			$hosts = array_merge( $hosts, $m[1] );
		}
		$hosts = array_diff( array_unique( array_map( 'strtolower', $hosts ) ), [ 'fonts.googleapis.com', 'fonts.gstatic.com', 'www.w3.org' ] );
		$other = PF_Fonts::foreign_families( $html );
		if ( $other ) {
			$out[] = [ 'warning', 'Font(s) ' . implode( ', ', $other ) . ' horen niet bij de Pixelfart-fonts. Kies een font uit de lijst in de skill (Press Start 2P, Silkscreen, Tiny5, VT323, Pixelify Sans, Jersey 10).' ];
		} elseif ( preg_match( '#fonts\.googleapis\.com#i', $html ) ) {
			$out[] = [ 'info', 'De Google Fonts-link is weggehaald: de site heeft deze fonts zelf. Laat de link voortaan weg.' ];
		}
		if ( $hosts ) {
			$out[] = [ 'warning', 'De game laadt iets van ' . implode( ', ', $hosts ) . '. Dat wordt geblokkeerd: alles moet in het HTML-bestand zelf zitten.' ];
		}

		if ( preg_match( '#\b(?:window\.)?(?:alert|confirm|prompt)\s*\(#', $code ) ) {
			$out[] = [ 'warning', 'alert(), confirm() of prompt() werken niet op de site. Toon meldingen in de game zelf.' ];
		}
		if ( preg_match( '#window\.open\s*\(|target=["\']_blank|top\.location|parent\.location#i', $code ) ) {
			$out[] = [ 'warning', 'Nieuwe vensters openen of de site wegnavigeren kan niet. Gebruik Pixelfart.back() om terug te gaan.' ];
		}
		$fits = preg_match( '#Pixelfart\.fit\s*\(|addEventListener\s*\(\s*["\']resize|onresize|ResizeObserver|100d?vh|100d?vw|100%\s*;\s*height\s*:\s*100%#i', $code );
		if ( ! $fits ) {
			$out[] = [ 'warning', 'De game lijkt zich niet aan te passen aan de schermgrootte. Volledig scherm blijft dan klein; gebruik Pixelfart.fit(canvas).' ];
		}
		if ( ! preg_match( '#Pixelfart\.(?:loop|onPause)\s*\(|visibilitychange#', $code ) && preg_match( '#requestAnimationFrame|setInterval#', $code ) ) {
			$out[] = [ 'info', 'Tip (energie): laat de game pauzeren als het tabblad niet zichtbaar is, bijv. met Pixelfart.loop() of Pixelfart.onPause().' ];
		}
		if ( preg_match( '#shadowBlur\s*=\s*[1-9]|create(?:Linear|Radial)Gradient|filter\s*[:=]\s*["\']?blur|border-radius\s*:\s*[1-9]#i', $code ) ) {
			$out[] = [ 'info', 'Tip (pixelstijl): de game gebruikt gloed, verlopen, blur of ronde hoeken. Pixelfart-games hebben harde, scherpe pixels.' ];
		}
		if ( ! preg_match( '#Pixelfart\.score\s*\(#', $code ) ) {
			$out[] = [ 'info', 'De game meldt geen score (Pixelfart.score). Er komt dan geen topscorelijst.' ];
		}
		return $out;
	}
}
