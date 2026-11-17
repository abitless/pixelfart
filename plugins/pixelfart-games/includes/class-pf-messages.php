<?php
/**
 * Berichten van bezoekers: ideeën (het lampje) en het contactformulier.
 * Komen binnen onder Games › Berichten en worden gemaild.
 */

defined( 'ABSPATH' ) || exit;

class PF_Messages {

	const CPT   = 'pf_bericht';
	const TYPES = [ 'Nieuw spel', 'Verbetering', 'Iets anders' ];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_filter( 'manage_' . self::CPT . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		add_action( 'add_meta_boxes_' . self::CPT, [ __CLASS__, 'meta_box' ] );
	}

	public static function register() {
		register_block_type( PIXELFART_DIR . 'blocks/contact-form' );
		register_post_type( self::CPT, [
			'labels'          => [
				'name'          => 'Berichten',
				'singular_name' => 'Bericht',
				'all_items'     => 'Berichten',
				'edit_item'     => 'Bericht',
				'not_found'     => 'Nog geen berichten',
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'edit.php?post_type=' . PF_Games::CPT,
			'supports'        => [ 'title', 'editor' ],
			'capability_type' => 'post',
			'capabilities'    => [ 'create_posts' => 'do_not_allow' ],
			'map_meta_cap'    => true,
		] );
	}

	public static function routes() {
		register_rest_route( PF_Rest::NS, '/messages', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'kind'  => [ 'required' => true, 'enum' => [ 'idee', 'contact' ] ],
				'type'  => [ 'type' => 'string', 'default' => '' ],
				'name'  => [ 'type' => 'string', 'default' => '' ],
				'email' => [ 'type' => 'string', 'default' => '' ],
				'text'  => [ 'required' => true, 'type' => 'string' ],
				'hp'    => [ 'type' => 'string', 'default' => '' ],
				'altcha' => [ 'type' => 'string', 'default' => '' ],
			],
		] );
	}

	public static function create( WP_REST_Request $r ) {
		if ( '' !== $r['hp'] ) {
			return [ 'ok' => true ];
		}
		$kind  = $r['kind'];
		$name  = mb_substr( sanitize_text_field( $r['name'] ), 0, 60 );
		$email = sanitize_email( $r['email'] );
		$text  = mb_substr( sanitize_textarea_field( $r['text'] ), 0, 3000 );
		$type  = in_array( $r['type'], self::TYPES, true ) ? $r['type'] : '';

		if ( mb_strlen( trim( $text ) ) < 3 ) {
			return new WP_Error( 'pf_empty', 'Schrijf eerst een bericht.', [ 'status' => 400 ] );
		}
		if ( 'contact' === $kind ) {
			if ( '' === $name ) {
				return new WP_Error( 'pf_name', 'Vul je naam en een bericht in.', [ 'status' => 400 ] );
			}
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'pf_email', 'Dat e-mailadres klopt niet helemaal.', [ 'status' => 400 ] );
			}
		}
		$spam = PF_Rest::spammy( $name, $text, 3 );
		if ( $spam ) {
			return $spam;
		}
		$ok = PF_Altcha::verify( $r['altcha'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( PF_Rest::throttled( 'msg', 20 ) ) {
			return new WP_Error( 'pf_slow', 'Even wachten, je stuurde net al iets.', [ 'status' => 429 ] );
		}

		$label = 'idee' === $kind ? 'Idee' . ( $type ? ": $type" : '' ) : 'Contact';
		$title = sprintf( '%s — %s', $label, $name ?: 'anoniem' );
		$id    = wp_insert_post( [
			'post_type'    => self::CPT,
			'post_status'  => 'private',
			'post_title'   => $title,
			'post_content' => $text,
			'meta_input'   => [ '_pf_kind' => $kind, '_pf_type' => $type, '_pf_name' => $name, '_pf_email' => $email ],
		] );

		$to      = get_option( 'pf_mail_to' ) ?: get_option( 'admin_email' );
		$headers = is_email( $email ) ? [ 'Reply-To: ' . ( $name ? "$name <$email>" : $email ) ] : [];
		wp_mail(
			$to,
			'[Pixelfart] ' . $title,
			$text . "\n\n—\n" . ( $email ? "E-mail: $email\n" : '' ) . admin_url( 'post.php?post=' . $id . '&action=edit' ),
			$headers
		);
		return [ 'ok' => true ];
	}

	public static function columns( array $c ): array {
		return [ 'cb' => $c['cb'], 'title' => 'Bericht', 'pf_email' => 'E-mail', 'date' => $c['date'] ];
	}

	public static function column( string $col, int $id ) {
		if ( 'pf_email' === $col ) {
			$e = get_post_meta( $id, '_pf_email', true );
			echo $e ? '<a href="mailto:' . esc_attr( $e ) . '">' . esc_html( $e ) . '</a>' : '–';
		}
	}

	public static function meta_box() {
		add_meta_box( 'pf_msg', 'Afzender', function ( WP_Post $p ) {
			$e = get_post_meta( $p->ID, '_pf_email', true );
			printf(
				'<p>Naam: <strong>%s</strong><br>E-mail: %s<br>Soort: %s</p>',
				esc_html( get_post_meta( $p->ID, '_pf_name', true ) ?: 'anoniem' ),
				$e ? '<a href="mailto:' . esc_attr( $e ) . '">' . esc_html( $e ) . '</a>' : '–',
				esc_html( trim( get_post_meta( $p->ID, '_pf_kind', true ) . ' ' . get_post_meta( $p->ID, '_pf_type', true ) ) )
			);
		}, self::CPT, 'side' );
	}
}
