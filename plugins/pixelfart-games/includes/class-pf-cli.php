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
			// Topscores en versiegeschiedenis uit game.json (zoals export ze schrijft).
			$order = (string) ( $meta['score']['order'] ?? '' );
			update_post_meta( $id, '_pf_score_order', in_array( $order, [ 'desc', 'asc' ], true ) ? $order : '' );
			if ( ! empty( $meta['score']['label'] ) ) {
				update_post_meta( $id, '_pf_score_label', mb_substr( sanitize_text_field( $meta['score']['label'] ), 0, 20 ) );
			}
			if ( ! empty( $meta['score']['max'] ) ) {
				update_post_meta( $id, '_pf_score_max', absint( $meta['score']['max'] ) );
			}
			if ( ! empty( $meta['changelog'] ) && is_array( $meta['changelog'] ) ) {
				$log = [];
				foreach ( $meta['changelog'] as $e ) {
					if ( is_array( $e ) && ! empty( $e['version'] ) ) {
						$log[] = [
							'version' => sanitize_text_field( $e['version'] ),
							'date'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $e['date'] ?? '' ) ) ? $e['date'] : $date,
							'notes'   => sanitize_textarea_field( (string) ( $e['notes'] ?? '' ) ),
						];
					}
				}
				update_post_meta( $id, '_pf_changelog', $log );
			}

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
	 * Schrijf alle games naar een map, als bron voor de repository (en voor `wp pixelfart import`).
	 *
	 * Per game een submap met game.json, game.html en thumb.png. De HTML is de bron zoals hij geüpload werd:
	 * zonder het blok dat de site toevoegt (beveiliging en SDK) en met de oorspronkelijke Google Fonts-links
	 * in plaats van de lokale kopieën. Bij het importeren zet de site dat er allemaal weer zelf in.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : Doelmap, bijv. wp-content/games.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pixelfart export wp-content/games
	 */
	public function export( $args ) {
		$dir = trailingslashit( $args[0] );
		wp_mkdir_p( $dir );
		$ids = get_posts( [ 'post_type' => PF_Games::CPT, 'post_status' => [ 'publish', 'draft', 'pending', 'future', 'private' ], 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ] );
		$n   = 0;
		foreach ( $ids as $id ) {
			$code = (string) PF_Games::meta( $id, 'code' );
			$src  = PF_Games::dir( $code );
			if ( 5 !== strlen( $code ) || ! is_readable( $src . 'game.html' ) ) {
				WP_CLI::warning( get_the_title( $id ) . ': geen gamebestand, overgeslagen.' );
				continue;
			}
			PF_Games::write_json( $id );
			$out = $dir . $code . '/';
			wp_mkdir_p( $out );
			$html = (string) file_get_contents( $src . 'game.html' );
			$html = preg_replace( '#\s*<!-- pixelfart -->.*?<!-- /pixelfart -->#s', '', $html );
			$html = preg_replace_callback( '#\.\./_fonts/(gf-[0-9a-f]{12}\.css)#', fn( $m ) => PF_Fonts::source_url( $m[1] ) ?: $m[0], $html );
			file_put_contents( $out . 'game.html', $html );
			copy( $src . 'game.json', $out . 'game.json' );
			if ( is_readable( $src . 'thumb.png' ) ) {
				copy( $src . 'thumb.png', $out . 'thumb.png' );
			}
			if ( str_contains( $html, '../_fonts/' ) ) {
				WP_CLI::warning( "$code: verwijst nog naar een lokale fontkopie zonder bron." );
			}
			WP_CLI::log( "$code  " . get_the_title( $id ) );
			$n++;
		}
		WP_CLI::success( "$n games geschreven naar $dir" );
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

	/**
	 * Zet een nieuwe versie van een game online (zoals uploaden in WP-admin).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Het HTML-bestand.
	 *
	 * [--code=<code>]
	 * : Gamecode. Standaard uit het manifest in de HTML.
	 *
	 * [--note=<tekst>]
	 * : Wat is er nieuw? Standaard uit het manifest.
	 *
	 * [--version=<versie>]
	 * : Versie. Standaard uit het manifest, anders automatisch opgehoogd.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pixelfart update "Hete Pizza.html" --note="Snellere scooter"
	 */
	public function update( $args, $assoc ) {
		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Kan $file niet lezen." );
		}
		$html     = file_get_contents( $file );
		$manifest = PF_Format::manifest( $html ) ?? [];
		$code     = PF_Games::sanitize_code( $assoc['code'] ?? (string) ( $manifest['code'] ?? '' ) );
		$ids      = $code ? get_posts( [ 'post_type' => PF_Games::CPT, 'post_status' => 'any', 'meta_key' => '_pf_code', 'meta_value' => $code, 'fields' => 'ids', 'posts_per_page' => 1 ] ) : [];
		if ( ! $ids ) {
			WP_CLI::error( 'Geen game gevonden met code ' . ( $code ?: '(geen)' ) . '. Geef --code mee.' );
		}
		$id    = (int) $ids[0];
		$old   = (string) PF_Games::meta( $id, 'version', '1.0.0' );
		$notes = PF_Games::publish_html( $id, $html, (string) ( $assoc['note'] ?? '' ), $assoc['version'] ?? null, $old );
		foreach ( $notes as [ $level, $msg ] ) {
			'warning' === $level ? WP_CLI::warning( $msg ) : WP_CLI::log( "[$level] $msg" );
		}
		WP_CLI::success( "$code bijgewerkt." );
	}
}
