<?php
/**
 * Aankondigingen en nieuws.
 *
 * Elke aankondiging staat op de nieuwspagina (/nieuws/), de nieuwste bovenaan, tien per pagina.
 * Na ARCHIVE_MONTHS maanden gaat hij in het archief: dan staat hij niet meer op de site.
 *
 * Soorten (eigen kleur op de nieuwspagina):
 * - Handmatig ("Even onderhoud"): gemaakt in WP-admin. Blijft DAYS dagen bovenaan de homepage (per aankondiging
 *   aan te passen, maximaal MAX_DAYS). Bezoekers kunnen hem wegklikken; dat onthoudt alleen hun eigen browser.
 * - Belangrijk: een handmatige die blijft staan tot we hem zelf weghalen en niet weg te klikken is.
 * - Nieuwe game / game-update: komen vanzelf. Op de homepage als één regel, hooguit AUTO_DAYS dagen,
 *   met een link naar de game en "Lees meer" naar het volledige bericht op de nieuwspagina.
 *   Niet bij `wp pixelfart import`.
 *
 * Op de homepage staat er hooguit één: eerst een belangrijke, dan een handmatige (die gaat altijd voor),
 * dan de nieuwste automatische, maar alleen als er daarna geen handmatige meer is gekomen.
 */

defined( 'ABSPATH' ) || exit;

class PF_Announcements {

	const CPT            = 'pf_aankondiging';
	const ARCHIVED       = 'pf_archief';
	const DAYS           = 7;
	const MAX_DAYS       = 31;
	const AUTO_DAYS      = 2;
	const ARCHIVE_MONTHS = 6;
	const PER_PAGE       = 10;
	const PAGE_SLUG      = 'nieuws';
	const PAGE_VAR       = 'pagina';
	const NONCE          = 'pf_ann_save';

	/** Soort => label (op de nieuwspagina en in WP-admin). */
	const TYPES = [
		'important' => 'Belangrijk',
		'manual'    => 'Mededeling',
		'game'      => 'Nieuwe game',
		'update'    => 'Game-update',
	];

	/** Zet `wp pixelfart import` aan: geïmporteerde games zijn niet nieuw. */
	public static bool $quiet = false;

	/** Games die in dit verzoek online gaan: die krijgen "Nieuwe game", geen "Game-update". */
	private static array $going_live = [];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'init', [ __CLASS__, 'schedule_archive' ], 40 );
		add_action( 'add_meta_boxes_' . self::CPT, [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post_' . self::CPT, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'transition_post_status', [ __CLASS__, 'changed' ], 10, 3 );
		add_action( 'wp_after_insert_post', [ __CLASS__, 'new_game' ], 10, 4 ); // na save_post: de beschrijving staat er dan al
		add_action( 'pf_game_updated', [ __CLASS__, 'game_updated' ], 10, 3 );
		add_action( 'deleted_post', [ __CLASS__, 'deleted' ], 10, 2 );
		add_action( 'pf_ann_expire', [ __CLASS__, 'purge' ] );
		add_action( 'pf_news_archive', [ __CLASS__, 'archive' ] );
		add_filter( 'manage_' . self::CPT . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		add_filter( 'display_post_states', [ __CLASS__, 'states' ], 10, 2 );
	}

	public static function register() {
		register_post_type( self::CPT, [
			'labels'        => [
				'name'               => 'Aankondigingen',
				'singular_name'      => 'Aankondiging',
				'menu_name'          => 'Aankondigingen',
				'all_items'          => 'Alle aankondigingen',
				'add_new'            => 'Nieuwe aankondiging',
				'add_new_item'       => 'Nieuwe aankondiging',
				'edit_item'          => 'Aankondiging bewerken',
				'not_found'          => 'Nog geen aankondigingen',
				'not_found_in_trash' => 'Geen aankondigingen in de prullenbak',
			],
			'description'   => 'Nieuws op de nieuwspagina, en een korte melding bovenaan de homepage.',
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'show_in_rest'  => false, // korte tekst: de klassieke editor is genoeg
			'menu_position' => 25,
			'menu_icon'     => 'dashicons-megaphone',
			'supports'      => [ 'title', 'editor' ],
			'map_meta_cap'  => true,
		] );
		register_post_status( self::ARCHIVED, [
			'label'                     => 'Gearchiveerd',
			'label_count'               => _n_noop( 'Gearchiveerd <span class="count">(%s)</span>', 'Gearchiveerd <span class="count">(%s)</span>' ),
			'public'                    => false,
			'internal'                  => false,
			'protected'                 => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
		] );
	}

	/* ---------- soort en zichtbaarheid ---------- */

	/** Belangrijke mededeling: blijft staan tot we hem weghalen en is niet weg te klikken. */
	public static function important( WP_Post $p ): bool {
		return (bool) get_post_meta( $p->ID, '_pf_important', true );
	}

	/** important, manual, game of update. Oude automatische aankondigingen (van vóór de soorten) hebben alleen _pf_game. */
	public static function type( WP_Post $p ): string {
		$t = (string) get_post_meta( $p->ID, '_pf_type', true );
		if ( 'game' === $t || 'update' === $t ) {
			return $t;
		}
		if ( ! $t && get_post_meta( $p->ID, '_pf_game', true ) ) {
			return 'game';
		}
		return self::important( $p ) ? 'important' : 'manual';
	}

	public static function is_auto( WP_Post $p ): bool {
		return in_array( self::type( $p ), [ 'game', 'update' ], true );
	}

	/** Tot wanneer (timestamp) deze aankondiging op de homepage staat. Een belangrijke mededeling: tot we hem weghalen. */
	public static function until( WP_Post $p ): int {
		if ( self::is_auto( $p ) ) {
			return (int) get_post_timestamp( $p ) + self::AUTO_DAYS * DAY_IN_SECONDS;
		}
		if ( self::important( $p ) ) {
			return PHP_INT_MAX;
		}
		$days = (int) get_post_meta( $p->ID, '_pf_days', true ) ?: self::DAYS;
		return (int) get_post_timestamp( $p ) + min( self::MAX_DAYS, max( 1, $days ) ) * DAY_IN_SECONDS;
	}

	private static function newest( array $extra ): ?WP_Post {
		$p = get_posts( $extra + [ 'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => false ] );
		return $p[0] ?? null;
	}

	/** meta_query voor automatische (true) of handmatige (false) aankondigingen. */
	private static function auto_query( bool $auto ): array {
		return $auto
			? [ 'relation' => 'OR', [ 'key' => '_pf_type', 'value' => [ 'game', 'update' ], 'compare' => 'IN' ], [ 'relation' => 'AND', [ 'key' => '_pf_type', 'compare' => 'NOT EXISTS' ], [ 'key' => '_pf_game', 'compare' => 'EXISTS' ] ] ]
			: [ 'relation' => 'AND', [ 'relation' => 'OR', [ 'key' => '_pf_type', 'compare' => 'NOT EXISTS' ], [ 'key' => '_pf_type', 'value' => [ 'game', 'update' ], 'compare' => 'NOT IN' ] ], [ 'relation' => 'OR', [ 'key' => '_pf_type', 'compare' => 'EXISTS' ], [ 'key' => '_pf_game', 'compare' => 'NOT EXISTS' ] ] ];
	}

	/**
	 * De aankondiging die nu bovenaan de homepage staat, of null.
	 * 1. de nieuwste belangrijke mededeling;
	 * 2. anders de nieuwste handmatige, als die nog niet verlopen is (handmatig gaat voor);
	 * 3. anders de nieuwste automatische, als die nog geen AUTO_DAYS dagen oud is en er daarna geen handmatige kwam.
	 */
	public static function current(): ?WP_Post {
		$p = self::newest( [ 'meta_key' => '_pf_important', 'meta_value' => '1' ] );
		if ( $p ) {
			return $p;
		}
		$manual = self::newest( [ 'meta_query' => self::auto_query( false ) ] );
		if ( $manual && self::until( $manual ) > time() ) {
			return $manual;
		}
		$auto = self::newest( [ 'meta_query' => self::auto_query( true ) ] );
		if ( ! $auto || self::until( $auto ) <= time() ) {
			return null;
		}
		return $manual && get_post_timestamp( $manual ) > get_post_timestamp( $auto ) ? null : $auto;
	}

	/** Tekst van de aankondiging: alleen eenvoudige opmaak en links. */
	public static function html( WP_Post $p ): string {
		return wp_kses( wpautop( $p->post_content ), [ 'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'a' => [ 'href' => true ] ] );
	}

	/** De game bij een automatische aankondiging (alleen als die online staat). */
	public static function game( WP_Post $p ): ?WP_Post {
		$g = get_post( (int) get_post_meta( $p->ID, '_pf_game', true ) );
		return $g && 'publish' === $g->post_status ? $g : null;
	}

	/* ---------- nieuwspagina ---------- */

	/** Alles wat op de nieuwspagina hoort: gepubliceerd en jonger dan ARCHIVE_MONTHS maanden (ook als het archiveren nog niet gedraaid heeft). */
	private static function news_args(): array {
		return [
			'post_type'        => self::CPT,
			'post_status'      => 'publish',
			'orderby'          => 'date',
			'order'            => 'DESC',
			'date_query'       => [ [ 'after' => self::ARCHIVE_MONTHS . ' months ago', 'inclusive' => true ] ],
			'suppress_filters' => false,
		];
	}

	/** @return array{0: WP_Post[], 1: int} De berichten op pagina $page en het aantal pagina's. */
	public static function news( int $page ): array {
		$q = new WP_Query( self::news_args() + [ 'posts_per_page' => self::PER_PAGE, 'paged' => max( 1, $page ), 'no_found_rows' => false ] );
		return [ $q->posts, (int) $q->max_num_pages ];
	}

	/** Tijdstip (GMT) van het nieuwste bericht, voor het rode bolletje in het menu. 0 = geen nieuws. */
	public static function latest(): int {
		$p = get_posts( self::news_args() + [ 'numberposts' => 1 ] );
		return $p ? (int) get_post_timestamp( $p[0] ) : 0;
	}

	public static function page_url( int $page = 1 ): string {
		$p   = get_page_by_path( self::PAGE_SLUG );
		$url = $p ? get_permalink( $p ) : home_url( '/' . self::PAGE_SLUG . '/' );
		return $page > 1 ? add_query_arg( self::PAGE_VAR, $page, $url ) : $url;
	}

	/** Link naar het bericht op de nieuwspagina (op de juiste pagina). */
	public static function news_url( WP_Post $p ): string {
		$newer = ( new WP_Query( array_merge( self::news_args(), [
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'date_query'     => [ [ 'after' => self::ARCHIVE_MONTHS . ' months ago', 'inclusive' => true ], [ 'after' => $p->post_date, 'inclusive' => false ] ],
		] ) ) )->found_posts;
		return self::page_url( intdiv( (int) $newer, self::PER_PAGE ) + 1 ) . '#nieuws-' . $p->ID;
	}

	/* ---------- archief ---------- */

	public static function schedule_archive() {
		if ( ! wp_next_scheduled( 'pf_news_archive' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pf_news_archive' );
		}
	}

	/** Ouder dan ARCHIVE_MONTHS maanden: naar het archief (niet meer op de site). Een belangrijke mededeling blijft tot we hem weghalen. */
	public static function archive() {
		$ids = get_posts( [
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'date_query'  => [ [ 'before' => self::ARCHIVE_MONTHS . ' months ago' ] ],
			'meta_query'  => [ [ 'key' => '_pf_important', 'compare' => 'NOT EXISTS' ] ],
		] );
		foreach ( $ids as $id ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => self::ARCHIVED ] );
		}
	}

	/* ---------- beheer ---------- */

	public static function meta_box() {
		add_meta_box( 'pf_ann', 'Zichtbaarheid', function ( WP_Post $p ) {
			wp_nonce_field( self::NONCE, '_pf_ann' );
			$auto = self::is_auto( $p );
			if ( $auto ) {
				printf( '<p><strong>%s</strong>, vanzelf gemaakt. Staat %d dagen als één regel bovenaan de homepage, met een link naar het volledige bericht op de nieuwspagina. Een handmatige aankondiging gaat voor.</p>', esc_html( self::TYPES[ self::type( $p ) ] ), (int) self::AUTO_DAYS );
			} else {
				$days = (int) get_post_meta( $p->ID, '_pf_days', true ) ?: self::DAYS;
				printf(
					'<p><label><input type="checkbox" name="pf_important" value="1"%s> <strong>Belangrijke mededeling</strong></label><br><span class="description">Blijft bovenaan staan tot je hem weghaalt (concept of prullenbak), gaat voor op andere aankondigingen en is niet weg te klikken.</span></p>',
					checked( self::important( $p ), true, false )
				);
				printf(
					'<p><label for="pf_days">Dagen bovenaan de homepage</label><br><input type="number" id="pf_days" name="pf_days" value="%d" min="1" max="%d" style="width:80px"><br><span class="description">Telt niet bij een belangrijke mededeling.</span></p>',
					$days,
					self::MAX_DAYS
				);
			}
			$game = (int) get_post_meta( $p->ID, '_pf_game', true );
			if ( $game && get_post( $game ) ) {
				printf( '<p>Hoort bij de game <a href="%s">%s</a>.</p>', esc_url( (string) get_edit_post_link( $game ) ), esc_html( get_the_title( $game ) ) );
			}
			if ( 'publish' === $p->post_status ) {
				echo '<p>' . self::status_text( $p ) . '</p>'; // phpcs:ignore -- eigen opmaak
				printf( '<p><a href="%s" target="_blank">Bekijk op de nieuwspagina</a></p>', esc_url( self::news_url( $p ) ) );
			} elseif ( self::ARCHIVED === $p->post_status ) {
				echo '<p>Gearchiveerd: ouder dan ' . (int) self::ARCHIVE_MONTHS . ' maanden, staat niet meer op de site.</p>';
			}
			echo '<p class="description">Elke aankondiging staat op de nieuwspagina, ' . (int) self::ARCHIVE_MONTHS . ' maanden lang. Bovenaan de homepage staat er altijd hooguit één. Houd het kort: een zin of twee, eventueel met een link.</p>';
		}, self::CPT, 'side', 'high' );
	}

	/** Staat hij bovenaan de homepage, en zo niet, waarom niet? */
	private static function status_text( WP_Post $p ): string {
		$cur = self::current();
		if ( $cur && $cur->ID === $p->ID ) {
			return self::important( $p )
				? '<strong>Staat nu bovenaan de homepage</strong>, tot je hem weghaalt.'
				: sprintf( '<strong>Staat nu bovenaan de homepage</strong>, tot %s.', esc_html( wp_date( 'j F Y, H:i', self::until( $p ) ) ) );
		}
		if ( self::until( $p ) <= time() ) {
			return 'Niet meer bovenaan de homepage (verlopen), wel op de nieuwspagina.';
		}
		if ( $cur && ! self::is_auto( $cur ) && self::is_auto( $p ) ) {
			return 'Niet bovenaan de homepage: een handmatige aankondiging gaat voor. Wel op de nieuwspagina.';
		}
		if ( $cur && self::important( $cur ) ) {
			return 'Wacht: er staat nu een belangrijke mededeling. Haal je die weg, dan komt deze terug (als hij dan nog niet verlopen is).';
		}
		return 'Bovenaan de homepage vervangen door een nieuwere aankondiging. Wel op de nieuwspagina.';
	}

	public static function save( int $id, WP_Post $post ) {
		if ( ! isset( $_POST['_pf_ann'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_pf_ann'] ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		if ( ! self::is_auto( $post ) ) {
			$days = min( self::MAX_DAYS, max( 1, absint( $_POST['pf_days'] ?? self::DAYS ) ) );
			update_post_meta( $id, '_pf_days', $days );
			if ( ! empty( $_POST['pf_important'] ) ) {
				update_post_meta( $id, '_pf_important', '1' );
			} else {
				delete_post_meta( $id, '_pf_important' );
			}
		}
		if ( 'publish' === $post->post_status ) {
			self::schedule( $post );
			self::purge();
		}
	}

	/** Publiceren, terugzetten naar concept, archiveren of weggooien: de site moet het meteen laten zien. */
	public static function changed( string $new, string $old, WP_Post $post ) {
		if ( PF_Games::CPT === $post->post_type && 'publish' === $new && 'publish' !== $old ) {
			self::$going_live[ $post->ID ] = true; // transition_post_status komt vóór save_post (waar de HTML wordt verwerkt)
		}
		if ( self::CPT === $post->post_type && $new !== $old && ( 'publish' === $new || 'publish' === $old ) ) {
			if ( 'publish' === $new ) {
				self::schedule( $post ); // ook als een ingeplande aankondiging vanzelf verschijnt
			}
			self::purge();
		}
	}

	/** Gecachte pagina's verversen zodra de aankondiging van de homepage verdwijnt. Een belangrijke mededeling verloopt niet. */
	private static function schedule( WP_Post $p ) {
		wp_clear_scheduled_hook( 'pf_ann_expire', [ $p->ID ] );
		$until = self::until( $p );
		if ( $until > time() && PHP_INT_MAX !== $until ) {
			wp_schedule_single_event( $until + 60, 'pf_ann_expire', [ $p->ID ] );
		}
	}

	public static function deleted( int $id, $post = null ) {
		if ( $post instanceof WP_Post && self::CPT === $post->post_type ) {
			wp_clear_scheduled_hook( 'pf_ann_expire', [ $id ] );
			self::purge();
		}
	}

	/**
	 * Nieuwe game gepubliceerd: meteen een aankondiging erbij. Eén keer per game; daarna kun je hem
	 * gewoon aanpassen of weggooien. Ook bij een ingeplande game die vanzelf verschijnt.
	 */
	public static function new_game( int $id, WP_Post $post, bool $update, ?WP_Post $before ) {
		if ( PF_Games::CPT !== $post->post_type || 'publish' !== $post->post_status || ( $before && 'publish' === $before->post_status ) ) {
			return;
		}
		if ( self::$quiet || get_post_meta( $id, '_pf_ann', true ) ) {
			return;
		}
		update_post_meta( $id, '_pf_ann', 1 ); // eerst: nooit twee keer, ook niet als hieronder iets misgaat
		$desc = trim( (string) PF_Games::meta( $id, 'description' ) );
		$ann  = self::insert_auto( 'game', $post, 'Nieuwe game: ' . $post->post_title, $desc ? esc_html( $desc ) : '' );
		if ( $ann ) {
			update_post_meta( $id, '_pf_ann', $ann );
		}
	}

	/** Nieuwe versie van een game die al online staat (formulier of `wp pixelfart update`). */
	public static function game_updated( int $id, string $version, string $note ) {
		$post = get_post( $id );
		if ( self::$quiet || ! $post || PF_Games::CPT !== $post->post_type || 'publish' !== $post->post_status || isset( self::$going_live[ $id ] ) ) {
			return;
		}
		$text = sprintf( '<strong>Versie %s</strong>', esc_html( $version ) ) . ( '' !== trim( $note ) ? "\n" . esc_html( trim( $note ) ) : '' );
		self::insert_auto( 'update', $post, 'Game-update: ' . $post->post_title, $text, [ '_pf_version' => $version ] );
	}

	/** Automatische aankondiging bij een game, met "Speel …" eronder. */
	private static function insert_auto( string $type, WP_Post $game, string $title, string $text, array $meta = [] ): int {
		$link = sprintf( '<a href="%s">Speel %s</a>', esc_url( get_permalink( $game ) ), esc_html( $game->post_title ) );
		// meta_input staat er al voordat changed() het verlopen inplant en de paginacache ververst
		$ann = wp_insert_post( [
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => ( '' !== $text ? $text . "\n\n" : '' ) . $link,
			'post_author'  => (int) $game->post_author,
			'meta_input'   => [ '_pf_type' => $type, '_pf_game' => $game->ID ] + $meta,
		], true );
		return is_wp_error( $ann ) ? 0 : (int) $ann;
	}

	/** Bij een wijziging de hele paginacache legen (homepage, nieuwspagina, het bolletje in het menu op elke pagina). */
	public static function purge() {
		do_action( 'litespeed_purge_all', 'Pixelfart: aankondiging gewijzigd' );
	}

	public static function columns( array $c ): array {
		return [ 'cb' => $c['cb'], 'title' => 'Aankondiging', 'pf_type' => 'Soort', 'pf_ann' => 'Bovenaan de homepage', 'date' => $c['date'] ];
	}

	public static function column( string $col, int $id ) {
		$p = get_post( $id );
		if ( 'pf_type' === $col ) {
			echo esc_html( self::TYPES[ self::type( $p ) ] );
			return;
		}
		if ( 'pf_ann' !== $col ) {
			return;
		}
		$cur = self::current();
		if ( $cur && $cur->ID === $id ) {
			echo self::important( $p )
				? '<strong style="color:#008a20">Ja</strong>, tot je hem weghaalt'
				: sprintf( '<strong style="color:#008a20">Ja</strong>, tot %s', esc_html( wp_date( 'j M, H:i', self::until( $p ) ) ) );
		} elseif ( 'publish' !== $p->post_status ) {
			echo '–';
		} elseif ( self::until( $p ) <= time() ) {
			echo 'Verlopen';
		} elseif ( $cur && self::important( $cur ) ) {
			echo 'Wacht op belangrijke mededeling';
		} else {
			echo $cur && ! self::is_auto( $cur ) && self::is_auto( $p ) ? 'Handmatige gaat voor' : 'Vervangen';
		}
	}

	/** "Gearchiveerd" achter de titel in de lijst. */
	public static function states( array $states, WP_Post $p ): array {
		if ( self::ARCHIVED === $p->post_status ) {
			$states[ self::ARCHIVED ] = 'Gearchiveerd';
		}
		return $states;
	}
}
