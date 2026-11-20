<?php
/**
 * Aankondigingen: een korte melding bovenaan de site ("Nieuwe game!", "Even onderhoud").
 *
 * - Los van de games, met een eigen menu in WP-admin.
 * - Er staat er hooguit één: de nieuwste gepubliceerde. Een nieuwe aankondiging vervangt de vorige meteen.
 * - Hij blijft DAYS dagen staan (per aankondiging aan te passen, maximaal MAX_DAYS) en verdwijnt dan vanzelf.
 * - Bezoekers kunnen hem wegklikken; dat onthoudt alleen hun eigen browser.
 * - Een belangrijke mededeling blijft staan tot we hem zelf weghalen, gaat voor op gewone aankondigingen
 *   en is niet weg te klikken.
 * - Bij een nieuwe game komt er vanzelf een aankondiging (één keer per game, niet bij `wp pixelfart import`).
 */

defined( 'ABSPATH' ) || exit;

class PF_Announcements {

	const CPT      = 'pf_aankondiging';
	const DAYS     = 7;
	const MAX_DAYS = 31;
	const NONCE    = 'pf_ann_save';

	/** Zet `wp pixelfart import` aan: geïmporteerde games zijn niet nieuw. */
	public static bool $quiet = false;

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'add_meta_boxes_' . self::CPT, [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post_' . self::CPT, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'transition_post_status', [ __CLASS__, 'changed' ], 10, 3 );
		add_action( 'wp_after_insert_post', [ __CLASS__, 'new_game' ], 10, 4 ); // na save_post: de beschrijving staat er dan al
		add_action( 'deleted_post', [ __CLASS__, 'deleted' ], 10, 2 );
		add_action( 'pf_ann_expire', [ __CLASS__, 'purge' ] );
		add_filter( 'manage_' . self::CPT . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
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
			'description'   => 'Korte melding bovenaan de site.',
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'show_in_rest'  => false, // korte tekst: de klassieke editor is genoeg
			'menu_position' => 25,
			'menu_icon'     => 'dashicons-megaphone',
			'supports'      => [ 'title', 'editor' ],
			'map_meta_cap'  => true,
		] );
	}

	/* ---------- welke staat er nu? ---------- */

	/** Belangrijke mededeling: blijft staan tot we hem weghalen en is niet weg te klikken. */
	public static function important( WP_Post $p ): bool {
		return (bool) get_post_meta( $p->ID, '_pf_important', true );
	}

	/** Tot wanneer (timestamp) deze aankondiging zichtbaar is. Een belangrijke mededeling: tot we hem weghalen. */
	public static function until( WP_Post $p ): int {
		if ( self::important( $p ) ) {
			return PHP_INT_MAX;
		}
		$days = (int) get_post_meta( $p->ID, '_pf_days', true ) ?: self::DAYS;
		return (int) get_post_timestamp( $p ) + min( self::MAX_DAYS, max( 1, $days ) ) * DAY_IN_SECONDS;
	}

	/**
	 * De aankondiging die nu bovenaan staat, of null.
	 * Eerst de nieuwste belangrijke mededeling; anders de nieuwste gewone, als die nog niet verlopen is.
	 */
	public static function current(): ?WP_Post {
		$q = [ 'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => false ];
		$p = get_posts( $q + [ 'meta_key' => '_pf_important', 'meta_value' => '1' ] );
		if ( $p ) {
			return $p[0];
		}
		$p = get_posts( $q + [ 'meta_query' => [ [ 'key' => '_pf_important', 'compare' => 'NOT EXISTS' ] ] ] );
		$p = $p[0] ?? null;
		return $p && self::until( $p ) > time() ? $p : null;
	}

	/** Tekst van de aankondiging: alleen eenvoudige opmaak en links. */
	public static function html( WP_Post $p ): string {
		return wp_kses( wpautop( $p->post_content ), [ 'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'a' => [ 'href' => true ] ] );
	}

	/* ---------- beheer ---------- */

	public static function meta_box() {
		add_meta_box( 'pf_ann', 'Zichtbaarheid', function ( WP_Post $p ) {
			wp_nonce_field( self::NONCE, '_pf_ann' );
			$days = (int) get_post_meta( $p->ID, '_pf_days', true ) ?: self::DAYS;
			printf(
				'<p><label><input type="checkbox" name="pf_important" value="1"%s> <strong>Belangrijke mededeling</strong></label><br><span class="description">Blijft staan tot je hem weghaalt (concept of prullenbak), gaat voor op andere aankondigingen en is niet weg te klikken.</span></p>',
				checked( self::important( $p ), true, false )
			);
			printf(
				'<p><label for="pf_days">Dagen zichtbaar</label><br><input type="number" id="pf_days" name="pf_days" value="%d" min="1" max="%d" style="width:80px"><br><span class="description">Telt niet bij een belangrijke mededeling.</span></p>',
				$days,
				self::MAX_DAYS
			);
			$game = (int) get_post_meta( $p->ID, '_pf_game', true );
			if ( $game && get_post( $game ) ) {
				printf( '<p>Vanzelf gemaakt bij de game <a href="%s">%s</a>.</p>', esc_url( (string) get_edit_post_link( $game ) ), esc_html( get_the_title( $game ) ) );
			}
			if ( 'publish' === $p->post_status ) {
				$until = self::until( $p );
				$cur   = self::current();
				if ( $cur && $cur->ID === $p->ID ) {
					echo self::important( $p )
						? '<p><strong>Staat nu bovenaan de site</strong>, tot je hem weghaalt.</p>'
						: sprintf( '<p><strong>Staat nu bovenaan de site</strong>, tot %s.</p>', esc_html( wp_date( 'j F Y, H:i', $until ) ) );
				} elseif ( $until <= time() ) {
					echo '<p>Verlopen: staat niet meer op de site.</p>';
				} elseif ( $cur && self::important( $cur ) && ! self::important( $p ) ) {
					echo '<p>Wacht: er staat nu een belangrijke mededeling. Haal je die weg, dan komt deze terug (als hij dan nog niet verlopen is).</p>';
				} else {
					echo '<p>Vervangen door een nieuwere aankondiging.</p>';
				}
			}
			echo '<p class="description">Er staat altijd hooguit één aankondiging op de site: de nieuwste, of de nieuwste belangrijke mededeling als die er is. Publiceer je een nieuwe, dan verdwijnt deze meteen. Houd het kort: een zin of twee, eventueel met een link.</p>';
		}, self::CPT, 'side', 'high' );
	}

	public static function save( int $id, WP_Post $post ) {
		if ( ! isset( $_POST['_pf_ann'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_pf_ann'] ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$days = min( self::MAX_DAYS, max( 1, absint( $_POST['pf_days'] ?? self::DAYS ) ) );
		update_post_meta( $id, '_pf_days', $days );
		if ( ! empty( $_POST['pf_important'] ) ) {
			update_post_meta( $id, '_pf_important', '1' );
		} else {
			delete_post_meta( $id, '_pf_important' );
		}
		if ( 'publish' === $post->post_status ) {
			self::schedule( $post );
			self::purge();
		}
	}

	/** Publiceren, terugzetten naar concept of weggooien: de site moet het meteen laten zien. */
	public static function changed( string $new, string $old, WP_Post $post ) {
		if ( self::CPT === $post->post_type && $new !== $old && ( 'publish' === $new || 'publish' === $old ) ) {
			if ( 'publish' === $new ) {
				self::schedule( $post ); // ook als een ingeplande aankondiging vanzelf verschijnt
			}
			self::purge();
		}
	}

	/** Gecachte pagina's verversen zodra de aankondiging verloopt. Een belangrijke mededeling verloopt niet. */
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
		$link = sprintf( '<a href="%s">Speel %s</a>', esc_url( get_permalink( $id ) ), esc_html( $post->post_title ) );
		// meta_input staat er al voordat changed() het verlopen inplant en de paginacache ververst
		$ann = wp_insert_post( [
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => 'Nieuwe game: ' . $post->post_title,
			'post_content' => ( $desc ? esc_html( $desc ) . "\n\n" : '' ) . $link,
			'post_author'  => (int) $post->post_author,
			'meta_input'   => [ '_pf_game' => $id, '_pf_days' => self::DAYS ],
		], true );
		if ( ! is_wp_error( $ann ) ) {
			update_post_meta( $id, '_pf_ann', $ann );
		}
	}

	/** De aankondiging staat op elke pagina: bij een wijziging de hele paginacache legen. */
	public static function purge() {
		do_action( 'litespeed_purge_all', 'Pixelfart: aankondiging gewijzigd' );
	}

	public static function columns( array $c ): array {
		return [ 'cb' => $c['cb'], 'title' => 'Aankondiging', 'pf_ann' => 'Op de site', 'date' => $c['date'] ];
	}

	public static function column( string $col, int $id ) {
		if ( 'pf_ann' !== $col ) {
			return;
		}
		$p   = get_post( $id );
		$cur = self::current();
		if ( $cur && $cur->ID === $id ) {
			echo self::important( $p )
				? '<strong style="color:#008a20">Ja</strong>, belangrijk: tot je hem weghaalt'
				: sprintf( '<strong style="color:#008a20">Ja</strong>, tot %s', esc_html( wp_date( 'j M, H:i', self::until( $p ) ) ) );
		} elseif ( 'publish' !== $p->post_status ) {
			echo '–';
		} elseif ( self::until( $p ) <= time() ) {
			echo 'Verlopen';
		} else {
			echo $cur && self::important( $cur ) && ! self::important( $p ) ? 'Wacht op belangrijke mededeling' : 'Vervangen';
		}
	}
}
