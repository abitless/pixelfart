<?php
/**
 * Waar de games draaien, en hoe we ze afschermen.
 *
 * Games zijn eigen JavaScript. Ze draaien daarom op een eigen origin (bijv. https://play.pixelfart.nl),
 * los van WordPress: een game kan zo nooit bij de inlog-cookies of wp-admin.
 *
 * - Adres van de games: constante PIXELFART_GAMES_URL of optie pf_games_url (Instellingen › Pixelfart).
 *   Leeg = de uploadsmap op het eigen domein (werkt, maar zonder afscherming).
 * - Elke game krijgt bij installatie een Content-Security-Policy: niets laden of versturen buiten de eigen origin
 *   (geen trackers, geen externe fonts, geen formulieren).
 * - .htaccess (als de game-host naar dezelfde map wijst, zoals lokaal): op de game-host alleen de game-bestanden,
 *   geen PHP; op de hoofdhost geen game-HTML.
 * - _tools/capture.html op de game-origin maakt screenshots voor thumbnails, zodat een game nooit in wp-admin draait.
 */

defined( 'ABSPATH' ) || exit;

class PF_Hosting {

	const MARKER = 'Pixelfart game host';

	/** Wat een game mag. Alleen de eigen origin; inline scripts en styles zijn nodig voor losse HTML-bestanden. */
	const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' data: blob:; worker-src 'self' blob:; frame-src 'none'; form-action 'none'; base-uri 'none'";

	/** Wat de sandbox van de speler-iframe toestaat. Geen top-navigatie, pop-ups, formulieren of downloads. */
	const SANDBOX = 'allow-scripts allow-same-origin allow-pointer-lock allow-orientation-lock';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'guard_game_host' ], 0 );
		add_action( 'admin_init', [ __CLASS__, 'maybe_deploy' ] );
		add_action( 'update_option_pf_games_url', [ __CLASS__, 'deploy' ] );
		add_action( 'add_option_pf_games_url', [ __CLASS__, 'deploy' ] );
	}

	/* ---------- adres ---------- */

	/** Basis-URL van de gamemap, met slash aan het eind. */
	public static function base_url(): string {
		$url = defined( 'PIXELFART_GAMES_URL' ) ? PIXELFART_GAMES_URL : get_option( 'pf_games_url', '' );
		if ( $url ) {
			return trailingslashit( esc_url_raw( $url ) );
		}
		return trailingslashit( wp_upload_dir( null, false )['baseurl'] ) . 'pixelfart-games/';
	}

	public static function origin(): string {
		$p = wp_parse_url( self::base_url() );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	public static function host(): string {
		return strtolower( (string) wp_parse_url( self::base_url(), PHP_URL_HOST ) );
	}

	/** Draaien de games op een andere host dan de site? */
	public static function is_separate(): bool {
		return self::host() !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/** Extra vangnet naast .htaccess: WordPress beantwoordt geen verzoeken op de game-host. */
	public static function guard_game_host() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! self::is_separate() || empty( $_SERVER['HTTP_HOST'] ) ) {
			return;
		}
		$host = strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) );
		if ( $host === self::host() ) {
			status_header( 403 );
			nocache_headers();
			exit( 'Forbidden' );
		}
	}

	/* ---------- HTML van een game ---------- */

	/** Zet de CSP (en later het SDK) bovenin de game. Idempotent: een eerder blok wordt vervangen. */
	public static function prepare_html( string $html ): string {
		$html  = preg_replace( '#\s*<!-- pixelfart -->.*?<!-- /pixelfart -->#s', '', $html );
		$block = "\n<!-- pixelfart -->"
			. '<meta http-equiv="Content-Security-Policy" content="' . esc_attr( self::CSP ) . '">'
			. apply_filters( 'pixelfart_game_head', '' )
			. '<!-- /pixelfart -->';
		if ( preg_match( '#<head\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
			// Na <meta charset>, als die vlak na <head> staat.
			if ( preg_match( '#\G\s*<meta\s+charset=[^>]*>#i', $html, $c, 0, $at ) ) {
				$at += strlen( $c[0] );
			}
			return substr( $html, 0, $at ) . $block . substr( $html, $at );
		}
		return $block . "\n" . $html;
	}

	/* ---------- installeren op de server ---------- */

	public static function maybe_deploy() {
		if ( get_option( 'pf_hosting_version' ) !== PIXELFART_VERSION . '|' . self::base_url() ) {
			self::deploy();
		}
	}

	/** Hulpbestanden op de game-origin en .htaccess-regels bijwerken. */
	public static function deploy() {
		$tools = PF_Games::base_dir() . '_tools/';
		wp_mkdir_p( $tools );
		$capture = file_get_contents( PIXELFART_DIR . 'assets/capture.html' );
		$capture = str_replace( '__SITE_ORIGIN__', esc_js( self::site_origin() ), $capture );
		file_put_contents( $tools . 'capture.html', $capture );
		do_action( 'pixelfart_deploy', PF_Games::base_dir() );

		self::write_rules();
		update_option( 'pf_hosting_version', PIXELFART_VERSION . '|' . self::base_url(), false );
	}

	public static function site_origin(): string {
		$p = wp_parse_url( home_url() );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	/** Regels bovenaan de .htaccess van WordPress (vóór de WordPress-regels, anders komen die eerst). */
	public static function write_rules() {
		$file = ABSPATH . '.htaccess';
		if ( ! is_writable( $file ) ) {
			return;
		}
		$lines = [];
		if ( self::is_separate() ) {
			$host = preg_quote( self::host(), '' );
			$dir  = ltrim( wp_make_link_relative( trailingslashit( wp_upload_dir( null, false )['baseurl'] ) . 'pixelfart-games/' ), '/' );
			$dir  = preg_quote( $dir, '' );
			$lines = [
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'# Game-host: alleen de game-bestanden, nooit PHP of WordPress.',
				"RewriteCond %{HTTP_HOST} ^{$host}$ [NC]",
				"RewriteRule !^{$dir} - [F,L]",
				"RewriteCond %{HTTP_HOST} ^{$host}$ [NC]",
				'RewriteRule \.(php\d?|phtml|phar)$ - [F,L]',
				'# Hoofdsite: game-HTML alleen via de game-host.',
				"RewriteCond %{HTTP_HOST} !^{$host}$ [NC]",
				"RewriteRule ^{$dir}.*\.html?$ - [F,L]",
				'</IfModule>',
			];
		}
		$current = (string) file_get_contents( $file );
		if ( ! str_contains( $current, '# BEGIN ' . self::MARKER ) ) {
			file_put_contents( $file, '# BEGIN ' . self::MARKER . "\n# END " . self::MARKER . "\n\n" . $current );
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		insert_with_markers( $file, self::MARKER, $lines );
	}
}
