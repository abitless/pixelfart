<?php
/**
 * Het berichttype Game en de opslag op schijf:
 *
 *   uploads/pixelfart-games/<CODE>/game.html   de game (fonts lokaal)
 *   uploads/pixelfart-games/<CODE>/game.json   statische metadata
 *   uploads/pixelfart-games/<CODE>/thumb.png   pixelige thumbnail (4:3)
 *   uploads/pixelfart-games/<CODE>/social.png  afbeelding voor social media (1200x630, PF_SEO)
 *   uploads/pixelfart-games/_fonts/            vangnet: lokale kopie van andere fonts in oudere games
 *   uploads/pixelfart-games/_sdk/              SDK + Pixelfart-pixelfonts
 *
 * WordPress (het bericht + meta) is de bron; game.json wordt bij elke opslag opnieuw geschreven.
 */

defined( 'ABSPATH' ) || exit;

class PF_Games {

	const CPT  = 'pf_game';
	const AGES = [ 'ALL', '6+', '8+', '10+', '12+', '16+' ];

	/** Licenties van de games: code en graphics/geluid (staan in game.json en op de spelerpagina). */
	const LICENSE_CODE = 'GPL-3.0-or-later';
	const LICENSE_ART  = 'CC-BY-SA-4.0';

	/** Een game telt als NIEUW zolang hij korter dan dit aantal dagen online staat. */
	const NEW_DAYS = 14;

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'save_post_' . self::CPT, [ __CLASS__, 'after_save' ], 50, 2 );
		add_action( 'set_object_terms', [ __CLASS__, 'after_terms' ], 10, 4 );
		add_action( 'before_delete_post', [ __CLASS__, 'before_delete' ] );

		// Privacy: geen IP-adres of browser bij reacties opslaan.
		add_filter( 'pre_comment_user_ip', '__return_empty_string' );
		add_filter( 'pre_comment_user_agent', '__return_empty_string' );
	}

	public static function register() {
		register_post_type( self::CPT, [
			'labels'        => [
				'name'               => 'Games',
				'singular_name'      => 'Game',
				'add_new'            => 'Nieuwe game',
				'add_new_item'       => 'Nieuwe game uploaden',
				'edit_item'          => 'Game bewerken',
				'all_items'          => 'Alle games',
				'search_items'       => 'Zoek games',
				'not_found'          => 'Nog geen games',
				'not_found_in_trash' => 'Geen games in de prullenbak',
			],
			'public'        => true,
			'show_in_rest'  => false, // klassiek bewerkscherm met uploadformulier
			'menu_icon'     => 'dashicons-games',
			'menu_position' => 5,
			'supports'      => [ 'title', 'comments' ],
			'has_archive'   => false,
			'rewrite'       => [ 'slug' => 'spel', 'with_front' => false ],
		] );
	}

	/* ---------- paden ---------- */

	/** Map met de games. Constante PIXELFART_GAMES_DIR (bijv. de documentroot van play.pixelfart.nl), anders in uploads. */
	public static function base_dir(): string {
		if ( defined( 'PIXELFART_GAMES_DIR' ) && PIXELFART_GAMES_DIR ) {
			return trailingslashit( PIXELFART_GAMES_DIR );
		}
		return trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'pixelfart-games/';
	}

	public static function base_url(): string {
		return PF_Hosting::base_url();
	}

	public static function dir( string $code ): string {
		return self::base_dir() . $code . '/';
	}

	public static function url( string $code ): string {
		return self::base_url() . rawurlencode( $code ) . '/';
	}

	/* ---------- gamecode ---------- */

	public static function sanitize_code( string $code ): string {
		return substr( preg_replace( '/[^A-Z]/', '', strtoupper( remove_accents( $code ) ) ), 0, 5 );
	}

	public static function code_in_use( string $code, int $except = 0 ): bool {
		$q = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'meta_key'       => '_pf_code',
			'meta_value'     => $code,
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'exclude'        => $except ? [ $except ] : [],
		] );
		return ! empty( $q );
	}

	/** Stel een unieke code van 5 letters voor op basis van de titel. */
	public static function suggest_code( string $title, int $except = 0 ): string {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( remove_accents( $title ) ) );
		$words   = preg_split( '/\s+/', strtoupper( remove_accents( $title ) ) );
		$cands   = [ substr( $letters, 0, 5 ) ];
		if ( count( $words ) > 1 ) {
			$first   = preg_replace( '/[^A-Z]/', '', $words[0] );
			$rest    = preg_replace( '/[^A-Z]/', '', implode( '', array_slice( $words, 1 ) ) );
			$cands[] = substr( substr( $first, 0, 2 ) . $rest, 0, 5 );
			$cands[] = substr( substr( $first, 0, 1 ) . $rest, 0, 5 );
		}
		foreach ( $cands as $c ) {
			$c = str_pad( $c, 5, 'X' );
			if ( ! self::code_in_use( $c, $except ) ) {
				return $c;
			}
		}
		$base = substr( str_pad( $letters, 4, 'X' ), 0, 4 );
		foreach ( range( 'A', 'Z' ) as $l ) {
			if ( ! self::code_in_use( $base . $l, $except ) ) {
				return $base . $l;
			}
		}
		return substr( str_shuffle( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ), 0, 5 );
	}

	/* ---------- data ---------- */

	public static function meta( int $id, string $key, $default = '' ) {
		$v = get_post_meta( $id, '_pf_' . $key, true );
		return '' === $v || null === $v ? $default : $v;
	}

	/** Alles wat het thema over een game nodig heeft. */
	public static function get( $post ): ?array {
		$post = get_post( $post );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		$id        = $post->ID;
		$code      = (string) self::meta( $id, 'code' );
		$published = (string) self::meta( $id, 'published', get_the_date( 'Y-m-d', $post ) );
		$sum       = (int) self::meta( $id, 'rating_sum', 0 );
		$count     = (int) self::meta( $id, 'rating_count', 0 );
		$dir       = $code ? self::dir( $code ) : '';
		$thumb_v   = $dir && file_exists( $dir . 'thumb.png' ) ? filemtime( $dir . 'thumb.png' ) : 0;
		$game_v    = $dir && file_exists( $dir . 'game.html' ) ? filemtime( $dir . 'game.html' ) : 0;
		$cats      = PF_Categories::for_game( $id );

		return [
			'id'          => $id,
			'slug'        => $post->post_name,
			'title'       => get_the_title( $post ),
			'code'        => $code,
			'categories'  => $cats,
			'published'   => $published,
			'version'     => (string) self::meta( $id, 'version', '1.0.0' ),
			'description' => (string) self::meta( $id, 'description' ),
			'author'      => (string) self::meta( $id, 'author' ),
			'age'         => (string) self::meta( $id, 'age', 'ALL' ),
			'plays'       => (int) self::meta( $id, 'plays', 0 ),
			'rating_sum'  => $sum,
			'rating_cnt'  => $count,
			'rating'      => $count ? round( $sum / $count, 1 ) : 0,
			'comments'    => (int) get_comments_number( $id ),
			'is_new'      => strtotime( $published ) > strtotime( '-' . self::NEW_DAYS . ' days' ),
			'permalink'   => get_permalink( $post ),
			'game_url'    => $game_v ? self::url( $code ) . 'game.html?v=' . $game_v : '',
			'thumb_url'   => $thumb_v ? self::url( $code ) . 'thumb.png?v=' . $thumb_v : '',
			'color'       => $cats[0]['color'] ?? '#5dde7a',
			'score'       => [
				'order' => (string) self::meta( $id, 'score_order' ), // desc = hoger is beter, asc = lager is beter, '' = geen lijst
				'label' => (string) self::meta( $id, 'score_label', 'punten' ),
			],
			'changelog'   => (array) ( get_post_meta( $id, '_pf_changelog', true ) ?: [] ),
		];
	}

	/** Alle gepubliceerde games. */
	public static function all(): array {
		$posts = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		return array_values( array_filter( array_map( [ __CLASS__, 'get' ], $posts ) ) );
	}

	/** Statische metadata zoals in game.json. */
	public static function json_data( int $id ): array {
		$g = self::get( $id );
		return [
			'title'       => $g['title'],
			'code'        => $g['code'],
			'categories'  => wp_list_pluck( $g['categories'], 'name' ),
			'published'   => $g['published'],
			'version'     => $g['version'],
			'description' => $g['description'],
			'author'      => $g['author'],
			'age'         => $g['age'],
			'thumbnail'   => 'thumb.png',
			'social'      => file_exists( self::dir( $g['code'] ) . 'social.png' ) ? 'social.png' : null, // 1200x630 voor social media (PF_SEO)
			'file'        => 'game.html',
			'score'       => $g['score']['order'] ? $g['score'] : null,
			'license'     => [ 'code' => self::LICENSE_CODE, 'art' => self::LICENSE_ART ],
			'changelog'   => $g['changelog'],
		];
	}

	public static function write_json( int $id ): void {
		$code = (string) self::meta( $id, 'code' );
		if ( ! $code || ! is_dir( self::dir( $code ) ) ) {
			return;
		}
		file_put_contents(
			self::dir( $code ) . 'game.json',
			wp_json_encode( self::json_data( $id ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
		);
	}

	/* ---------- bestanden ---------- */

	/**
	 * Zet de game-HTML op zijn plek (met lokale fonts).
	 *
	 * @return string[] Waarschuwingen.
	 */
	public static function install_html( int $id, string $html ): array {
		$code = (string) self::meta( $id, 'code' );
		wp_mkdir_p( self::dir( $code ) );
		$warnings = [];
		$html     = PF_Hosting::prepare_html( PF_Format::strip_local_sdk( PF_Fonts::localize( $html, $warnings ) ) );
		file_put_contents( self::dir( $code ) . 'game.html', $html );
		return $warnings;
	}

	/**
	 * Zet (een nieuwe versie van) de game online: vorige versie bewaren, versie zo nodig ophogen,
	 * changelog bijwerken, HTML verwerken en het format controleren. Gebruikt door het formulier en WP-CLI.
	 *
	 * @param string      $old     Versie vóór deze wijziging.
	 * @param string|null $version Gewenste versie (null = uit het manifest, anders de oude).
	 * @return array<int, array{0:string,1:string}> Meldingen [ niveau, tekst ].
	 */
	public static function publish_html( int $id, string $html, string $note, ?string $version, string $old ): array {
		$notes     = [];
		$manifest  = PF_Format::manifest( $html ) ?? [];
		$code      = (string) self::meta( $id, 'code' );
		$is_update = file_exists( self::dir( $code ) . 'game.html' );
		$version   = $version ?: (string) ( $manifest['version'] ?? '' ) ?: $old;
		$note      = $note ?: sanitize_textarea_field( (string) ( $manifest['changelog'] ?? '' ) );

		if ( $is_update ) {
			if ( $version === $old ) {
				$version = self::bump( $old );
				$notes[] = [ 'info', sprintf( 'Versie automatisch opgehoogd naar %s.', $version ) ];
			}
			self::archive_current( $id, $old );
			$notes[] = [ 'info', sprintf( 'Vorige versie %s bewaard (terugzetten kan onder Versies).', $old ) ];
		}
		update_post_meta( $id, '_pf_version', $version );
		self::add_changelog( $id, $version, mb_substr( $note ?: ( $is_update ? 'Nieuwe versie.' : 'Eerste versie.' ), 0, 500 ) );

		// Scoreinstellingen uit het manifest als ze in WordPress nog leeg zijn.
		if ( ! self::meta( $id, 'score_order' ) && in_array( $manifest['score']['order'] ?? '', [ 'asc', 'desc' ], true ) ) {
			update_post_meta( $id, '_pf_score_order', $manifest['score']['order'] );
			update_post_meta( $id, '_pf_score_label', mb_substr( sanitize_text_field( (string) ( $manifest['score']['label'] ?? 'punten' ) ), 0, 20 ) ?: 'punten' );
		}

		foreach ( self::install_html( $id, $html ) as $w ) {
			$notes[] = [ 'warning', $w ];
		}
		foreach ( PF_Format::check( $html ) as [ $level, $msg ] ) {
			$notes[] = [ $level, 'Formatcheck: ' . $msg ];
		}
		$notes[] = [ 'success', sprintf( 'Game %s staat online als %s/game.html.', $version, $code ) ];
		self::write_json( $id );
		do_action( 'litespeed_purge_post', $id );
		if ( $is_update ) {
			do_action( 'pf_game_updated', $id, $version, $note ); // o.a. een aankondiging "Game-update: …"
		}
		return $notes;
	}

	/* ---------- versies ---------- */

	/** Map met eerdere versies: <CODE>/versions/<versie>.html */
	public static function versions_dir( string $code ): string {
		return self::dir( $code ) . 'versions/';
	}

	/** Eerdere versies, nieuwste eerst: [ bestandsnaam => versie ]. */
	public static function versions( string $code ): array {
		$files = glob( self::versions_dir( $code ) . '*.html' ) ?: [];
		usort( $files, fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );
		$out = [];
		foreach ( $files as $f ) {
			$out[ basename( $f ) ] = preg_replace( '/(-\d+)?\.html$/', '', basename( $f ) );
		}
		return $out;
	}

	/** Bewaar de huidige game.html als versions/<versie>.html (voordat hij vervangen wordt). */
	public static function archive_current( int $id, string $version ): ?string {
		$code = (string) self::meta( $id, 'code' );
		$file = self::dir( $code ) . 'game.html';
		if ( ! $code || ! file_exists( $file ) ) {
			return null;
		}
		wp_mkdir_p( self::versions_dir( $code ) );
		$name = sanitize_file_name( $version ?: 'onbekend' );
		$dest = self::versions_dir( $code ) . $name . '.html';
		if ( file_exists( $dest ) ) {
			$dest = self::versions_dir( $code ) . $name . '-' . time() . '.html';
		}
		copy( $file, $dest );
		return basename( $dest );
	}

	public static function add_changelog( int $id, string $version, string $notes ): void {
		$log = (array) ( get_post_meta( $id, '_pf_changelog', true ) ?: [] );
		array_unshift( $log, [ 'version' => $version, 'date' => current_time( 'Y-m-d' ), 'notes' => $notes ] );
		update_post_meta( $id, '_pf_changelog', array_slice( $log, 0, 50 ) );
	}

	/** 1.2.3 → 1.2.4 (of "1.0.0" als het geen versienummer is). */
	public static function bump( string $version ): string {
		if ( preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $version, $m ) ) {
			return $m[1] . '.' . $m[2] . '.' . ( $m[3] + 1 );
		}
		return '1.0.0';
	}

	/** Zet een thumbnail (PNG/JPG/WebP-bytes) om naar thumb.png. */
	public static function install_thumb( int $id, string $bytes ): bool {
		$code = (string) self::meta( $id, 'code' );
		wp_mkdir_p( self::dir( $code ) );
		$tmp = wp_tempnam( 'pf-thumb' );
		file_put_contents( $tmp, $bytes );
		$editor = wp_get_image_editor( $tmp );
		if ( is_wp_error( $editor ) ) {
			@unlink( $tmp );
			return false;
		}
		$size = $editor->get_size();
		// 4:3 bijsnijden vanuit het midden; grootte laten we zoals hij is (pixel-art schaalt het thema zelf).
		$w = $size['width'];
		$h = $size['height'];
		if ( abs( $w / $h - 4 / 3 ) > 0.01 ) {
			$tw = min( $w, (int) round( $h * 4 / 3 ) );
			$th = (int) round( $tw * 3 / 4 );
			$editor->crop( (int) ( ( $w - $tw ) / 2 ), (int) ( ( $h - $th ) / 2 ), $tw, $th );
		}
		$saved = $editor->save( self::dir( $code ) . 'thumb.png', 'image/png' );
		@unlink( $tmp );
		return ! is_wp_error( $saved );
	}

	/** Verplaats de map als de gamecode verandert. */
	public static function rename_code( int $id, string $old, string $new ): void {
		if ( $old && $old !== $new && is_dir( self::dir( $old ) ) && ! file_exists( self::dir( $new ) ) ) {
			rename( self::dir( $old ), self::dir( $new ) );
		}
	}

	/* ---------- hooks ---------- */

	public static function after_save( int $id, WP_Post $post ) {
		if ( wp_is_post_revision( $id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::write_json( $id );
	}

	public static function after_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( PF_Categories::TAX === $taxonomy && self::CPT === get_post_type( $object_id ) ) {
			self::write_json( (int) $object_id );
		}
	}

	/** Bij definitief verwijderen ook de map opruimen. */
	public static function before_delete( int $id ) {
		if ( self::CPT !== get_post_type( $id ) ) {
			return;
		}
		$code = (string) self::meta( $id, 'code' );
		if ( ! $code || ! is_dir( self::dir( $code ) ) ) {
			return;
		}
		foreach ( array_merge( glob( self::versions_dir( $code ) . '*' ) ?: [], glob( self::dir( $code ) . '*' ) ?: [] ) as $f ) {
			is_file( $f ) && unlink( $f );
		}
		@rmdir( self::versions_dir( $code ) );
		@rmdir( self::dir( $code ) );
	}
}
