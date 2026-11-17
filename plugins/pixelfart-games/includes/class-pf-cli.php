<?php
/**
 * WP-CLI: games in bulk importeren en game.json opnieuw schrijven.
 */

defined( 'ABSPATH' ) || exit;

class PF_CLI {

	/**
	 * Importeer games uit een map met per game een submap (game.json + game.html + thumb.png).
	 * Bestaande games (zelfde code) worden bijgewerkt.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : Map met submappen per game.
	 *
	 * [--status=<status>]
	 * : Status van nieuwe games.
	 * ---
	 * default: publish
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pixelfart import /tmp/games
	 */
	public function import( $args, $assoc ) {
		$dir   = trailingslashit( $args[0] );
		$files = glob( $dir . '*/game.json' );
		if ( ! $files ) {
			WP_CLI::error( "Geen */game.json gevonden in $dir" );
		}
		PF_Categories::seed_defaults();

		foreach ( $files as $json_file ) {
			$base = dirname( $json_file ) . '/';
			$meta = json_decode( file_get_contents( $json_file ), true );
			if ( ! is_array( $meta ) || empty( $meta['title'] ) ) {
				WP_CLI::warning( "Ongeldige JSON: $json_file" );
				continue;
			}
			$code = PF_Games::sanitize_code( $meta['code'] ?? '' );
			if ( strlen( $code ) !== 5 ) {
				$code = PF_Games::suggest_code( $meta['title'] );
			}

			$existing = get_posts( [ 'post_type' => PF_Games::CPT, 'post_status' => 'any', 'meta_key' => '_pf_code', 'meta_value' => $code, 'fields' => 'ids', 'posts_per_page' => 1 ] );
			$date     = ( $meta['published'] ?? '' ) ?: current_time( 'Y-m-d' );
			$postarr  = [
				'post_type'      => PF_Games::CPT,
				'post_title'     => $meta['title'],
				'post_status'    => $existing ? get_post_status( $existing[0] ) : $assoc['status'],
				'comment_status' => 'open',
				'post_date'      => $date . ' 12:00:00',
			];
			if ( $existing ) {
				$postarr['ID'] = $existing[0];
			}
			$id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( $meta['title'] . ': ' . $id->get_error_message() );
				continue;
			}

			update_post_meta( $id, '_pf_code', $code );
			update_post_meta( $id, '_pf_published', $date );
			update_post_meta( $id, '_pf_version', $meta['version'] ?? '1.0.0' );
			update_post_meta( $id, '_pf_description', $meta['description'] ?? '' );
			update_post_meta( $id, '_pf_author', $meta['author'] ?? '' );
			update_post_meta( $id, '_pf_age', in_array( $meta['age'] ?? 'ALL', PF_Games::AGES, true ) ? $meta['age'] : 'ALL' );

			$terms = [];
			foreach ( (array) ( $meta['categories'] ?? [] ) as $cat ) {
				$t = term_exists( $cat, PF_Categories::TAX ) ?: wp_insert_term( $cat, PF_Categories::TAX );
				if ( ! is_wp_error( $t ) ) {
					$terms[] = (int) $t['term_id'];
				}
			}
			wp_set_object_terms( $id, $terms, PF_Categories::TAX );

			$html_file = $base . ( $meta['file'] ?? 'game.html' );
			if ( file_exists( $html_file ) ) {
				foreach ( PF_Games::install_html( $id, file_get_contents( $html_file ) ) as $w ) {
					WP_CLI::warning( $meta['title'] . ': ' . $w );
				}
			} else {
				WP_CLI::warning( $meta['title'] . ': geen HTML gevonden' );
			}
			$thumb = $base . ( $meta['thumbnail'] ?? 'thumb.png' );
			if ( file_exists( $thumb ) ) {
				PF_Games::install_thumb( $id, file_get_contents( $thumb ) );
			}
			PF_Games::write_json( $id );
			WP_CLI::log( sprintf( '%s %s  %s', $existing ? 'bijgewerkt' : 'nieuw     ', $code, $meta['title'] ) );
		}
		WP_CLI::success( 'Klaar.' );
	}

	/**
	 * Schrijf game.json van alle games opnieuw.
	 *
	 * @subcommand rebuild-json
	 */
	public function rebuild_json() {
		$ids = get_posts( [ 'post_type' => PF_Games::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );
		foreach ( $ids as $id ) {
			PF_Games::write_json( $id );
		}
		WP_CLI::success( count( $ids ) . ' game.json-bestanden geschreven.' );
	}

	/**
	 * Verwerk de HTML van alle games opnieuw (lokale fonts, beveiligingsblok, SDK) en werk de hulpbestanden bij.
	 */
	public function reprocess() {
		PF_Hosting::deploy();
		$ids = get_posts( [ 'post_type' => PF_Games::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );
		foreach ( $ids as $id ) {
			$file = PF_Games::dir( (string) PF_Games::meta( $id, 'code' ) ) . 'game.html';
			if ( file_exists( $file ) ) {
				foreach ( PF_Games::install_html( $id, file_get_contents( $file ) ) as $w ) {
					WP_CLI::warning( $w );
				}
			}
		}
		WP_CLI::success( count( $ids ) . ' games opnieuw verwerkt.' );
	}
}
