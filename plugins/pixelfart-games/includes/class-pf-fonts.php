<?php
/**
 * Fonts in games. Een game laadt nooit iets van internet.
 *
 * - De Pixelfart-fonts (LIBRARY) staan zelf gehost in _sdk/fonts en worden door pixelfart.js geladen.
 *   Een Google Fonts-link naar alleen deze fonts wordt bij het uploaden gewoon weggehaald.
 * - Vangnet voor oudere games met een ander font: de server haalt de CSS en woff2-bestanden één keer op
 *   (zonder bezoekersgegevens) en herschrijft de game naar die lokale kopie. De formatcheck waarschuwt dan.
 */

defined( 'ABSPATH' ) || exit;

class PF_Fonts {

	const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

	/**
	 * Vervang Google Fonts-links in een game door lokale kopieën.
	 *
	 * @param string $html     De game-HTML.
	 * @param array  $warnings Gevuld met meldingen als een font niet opgehaald kon worden.
	 * @return string          De herschreven HTML.
	 */
	public static function localize( string $html, array &$warnings = [] ): string {
		// preconnect/dns-prefetch naar Google weghalen.
		$html = preg_replace( '#<link[^>]+href=["\']https://fonts\.(googleapis|gstatic)\.com/?["\'][^>]*>\s*#i', '', $html );

		// Eerder lokaal gezette kopieën terugvertalen naar de oorspronkelijke URL, zodat ze opnieuw beoordeeld worden.
		$html = preg_replace_callback( '#\.\./_fonts/(gf-[0-9a-f]{12}\.css)#', fn( $m ) => self::source_url( $m[1] ) ?: $m[0], $html );

		// Links en @imports met alleen Pixelfart-fonts: weg ermee, de SDK laadt ze lokaal.
		$only_ours = fn( $url ) => ! array_diff( self::url_families( $url ), array_keys( self::LIBRARY ) );
		$html      = preg_replace_callback( '#<link\b[^>]*href=["\'](https://fonts\.googleapis\.com/css2?\?[^"\']+)["\'][^>]*>\s*#i', fn( $m ) => $only_ours( $m[1] ) ? '' : $m[0], $html );
		$html      = preg_replace_callback( '#@import\s+url\(\s*["\']?(https://fonts\.googleapis\.com/css2?\?[^"\')]+)["\']?\s*\)\s*;?#i', fn( $m ) => $only_ours( $m[1] ) ? '' : $m[0], $html );

		$replace = function ( string $url ) use ( &$warnings ) {
			$url   = self::without_library( html_entity_decode( $url ) ); // Pixelfart-fonts komen uit de SDK
			$local = self::ensure_local( $url );
			if ( ! $local ) {
				$warnings[] = 'Font kon niet lokaal worden gezet: ' . $url;
				return $url;
			}
			return '../_fonts/' . $local;
		};

		// <link href="https://fonts.googleapis.com/css...">
		$html = preg_replace_callback(
			'#(https://fonts\.googleapis\.com/css2?\?[^"\')\s]+)#i',
			fn( $m ) => $replace( $m[1] ),
			$html
		);
		return $html;
	}

	/** De zelf gehoste pixelfonts (OFL-1.1), met waar ze goed in zijn. Staan ook in de skill. */
	const LIBRARY = [
		'Press Start 2P' => 'klassiek arcade, scherp op 8, 16, 24 px',
		'Silkscreen'     => 'klein en strak (ook bold), scherp op 8, 16, 24 px',
		'Tiny5'          => 'heel klein, veel tekst op weinig ruimte, scherp op 8, 16 px',
		'VT323'          => 'oude terminal, prettig voor langere tekst, vanaf 16 px',
		'Pixelify Sans'  => 'vriendelijk en rond (400–700), vanaf 16 px',
		'Jersey 10'      => 'stoer scorebord-font, voor titels vanaf 19 px',
	];

	/** Families in een Google Fonts-URL, bijv. ['Press Start 2P', 'Silkscreen']. */
	public static function url_families( string $url ): array {
		$q = html_entity_decode( (string) wp_parse_url( $url, PHP_URL_QUERY ) );
		preg_match_all( '#(?:^|&)family=([^&]+)#', $q, $m );
		$out = [];
		foreach ( $m[1] as $f ) {
			foreach ( explode( '|', urldecode( $f ) ) as $part ) { // oude API: family=A|B
				$out[] = trim( str_replace( '+', ' ', explode( ':', $part )[0] ) );
			}
		}
		return array_unique( array_filter( $out ) );
	}

	/** Google Fonts-URL zonder de Pixelfart-fonts (die levert de SDK al). */
	public static function without_library( string $url ): string {
		[ $base, $q ] = array_pad( explode( '?', $url, 2 ), 2, '' );
		$keep = array_filter( explode( '&', $q ), function ( $part ) {
			if ( ! str_starts_with( $part, 'family=' ) ) {
				return true;
			}
			$fam = self::url_families( 'x?' . $part );
			return ! $fam || array_diff( $fam, array_keys( self::LIBRARY ) );
		} );
		return $base . '?' . implode( '&', $keep );
	}

	/** De oorspronkelijke Google Fonts-URL van een lokale kopie (staat in de eerste regel). */
	public static function source_url( string $name ): ?string {
		$file = PF_Games::base_dir() . '_fonts/' . $name;
		$head = is_readable( $file ) ? (string) fgets( fopen( $file, 'r' ) ) : '';
		return preg_match( '#Lokaal gezet van (https://fonts\.googleapis\.com/\S+)#', $head, $m ) ? $m[1] : null;
	}

	/** Fonts die een game van Google wil laden en die niet bij de Pixelfart-fonts horen. */
	public static function foreign_families( string $html ): array {
		preg_match_all( '#https://fonts\.googleapis\.com/css2?\?[^"\')\s]+#i', $html, $m );
		$all = [];
		foreach ( $m[0] as $url ) {
			$all = array_merge( $all, self::url_families( $url ) );
		}
		return array_values( array_diff( array_unique( $all ), array_keys( self::LIBRARY ) ) );
	}

	/** Pad (relatief aan _fonts/) van de lokale CSS voor deze Google Fonts-URL, of null bij een fout. */
	public static function ensure_local( string $url ): ?string {
		$dir  = PF_Games::base_dir() . '_fonts/';
		$name = 'gf-' . substr( md5( $url ), 0, 12 ) . '.css';
		if ( file_exists( $dir . $name ) ) {
			return $name;
		}
		wp_mkdir_p( $dir . 'files' );

		$res = wp_remote_get( $url, [ 'user-agent' => self::UA, 'timeout' => 20 ] );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$css = wp_remote_retrieve_body( $res );

		$ok  = true;
		$css = preg_replace_callback( '#url\((https://fonts\.gstatic\.com/[^)]+)\)#', function ( $m ) use ( $dir, &$ok ) {
			$path = wp_parse_url( $m[1], PHP_URL_PATH );
			$file = sanitize_file_name( trim( str_replace( '/', '-', $path ), '-' ) );
			if ( ! file_exists( $dir . 'files/' . $file ) ) {
				$r = wp_remote_get( $m[1], [ 'user-agent' => self::UA, 'timeout' => 20 ] );
				if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
					$ok = false;
					return $m[0];
				}
				file_put_contents( $dir . 'files/' . $file, wp_remote_retrieve_body( $r ) );
			}
			return 'url(files/' . $file . ')';
		}, $css );

		if ( ! $ok ) {
			return null;
		}
		file_put_contents( $dir . $name, "/* Lokaal gezet van " . esc_url_raw( $url ) . " */\n" . $css );
		return $name;
	}
}
