<?php
/**
 * Google Fonts lokaal: haalt de CSS en woff2-bestanden één keer op (vanaf de server, dus zonder
 * bezoekersgegevens) en herschrijft de game zodat hij alleen nog lokale fonts laadt.
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

		$replace = function ( string $url ) use ( &$warnings ) {
			$local = self::ensure_local( html_entity_decode( $url ) );
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
