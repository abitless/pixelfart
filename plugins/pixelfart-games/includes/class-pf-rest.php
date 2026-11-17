<?php
/**
 * Publieke REST-endpoints voor de site: speelteller, sterren en reacties.
 *
 * Geen cookies en geen accounts. Tegen misbruik onthouden we kort een gehashte versie van het IP-adres
 * (met een geheime salt, alleen als tijdelijke transient), nooit het IP zelf.
 */

defined( 'ABSPATH' ) || exit;

class PF_Rest {

	const NS = 'pixelfart/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		$id = [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ] ];

		register_rest_route( self::NS, '/games/(?P<id>\d+)/play', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'play' ],
			'permission_callback' => '__return_true',
			'args'                => $id,
		] );
		register_rest_route( self::NS, '/games/(?P<id>\d+)/rate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rate' ],
			'permission_callback' => '__return_true',
			'args'                => $id + [
				'stars' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ],
			],
		] );
		register_rest_route( self::NS, '/games/(?P<id>\d+)/comments', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'comment' ],
			'permission_callback' => '__return_true',
			'args'                => $id + [
				'name' => [ 'type' => 'string', 'default' => '' ],
				'text' => [ 'required' => true, 'type' => 'string' ],
				'hp'   => [ 'type' => 'string', 'default' => '' ],
			],
		] );
	}

	/* ---------- helpers ---------- */

	public static function visitor_key( string $scope ): string {
		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
		$ip = trim( explode( ',', $ip )[0] );
		return 'pf_' . $scope . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 24 );
	}

	/** True als deze bezoeker deze actie binnen $seconds al deed (en registreert hem). */
	public static function throttled( string $scope, int $seconds ): bool {
		$key = self::visitor_key( $scope );
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, $seconds );
		return false;
	}

	private static function game( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( PF_Games::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return new WP_Error( 'pf_not_found', 'Game niet gevonden.', [ 'status' => 404 ] );
		}
		return $id;
	}

	/* ---------- endpoints ---------- */

	public static function play( WP_REST_Request $r ) {
		$id = self::game( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$plays = (int) PF_Games::meta( $id, 'plays', 0 );
		if ( ! self::throttled( 'play' . $id, 5 * MINUTE_IN_SECONDS ) ) {
			update_post_meta( $id, '_pf_plays', ++$plays );
		}
		return [ 'plays' => $plays ];
	}

	public static function rate( WP_REST_Request $r ) {
		$id = self::game( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$stars = (int) $r['stars'];
		$key   = self::visitor_key( 'rate' . $id );
		$prev  = (int) get_transient( $key );
		$sum   = (int) PF_Games::meta( $id, 'rating_sum', 0 );
		$count = (int) PF_Games::meta( $id, 'rating_count', 0 );

		if ( $prev ) {
			$sum += $stars - $prev; // stem aanpassen
		} else {
			$sum += $stars;
			$count++;
		}
		update_post_meta( $id, '_pf_rating_sum', $sum );
		update_post_meta( $id, '_pf_rating_count', $count );
		set_transient( $key, $stars, 30 * DAY_IN_SECONDS );

		return [ 'avg' => $count ? round( $sum / $count, 1 ) : 0, 'count' => $count, 'mine' => $stars ];
	}

	public static function comment( WP_REST_Request $r ) {
		$id = self::game( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( '' !== $r['hp'] ) {
			return [ 'ok' => true, 'pending' => true ]; // honeypot: stil negeren
		}
		if ( ! comments_open( $id ) ) {
			return new WP_Error( 'pf_closed', 'Reacties zijn gesloten voor deze game.', [ 'status' => 403 ] );
		}
		$name = mb_substr( sanitize_text_field( $r['name'] ), 0, 40 ) ?: 'Anoniem';
		$text = mb_substr( sanitize_textarea_field( $r['text'] ), 0, 600 );
		if ( mb_strlen( trim( $text ) ) < 2 ) {
			return new WP_Error( 'pf_empty', 'Schrijf eerst iets.', [ 'status' => 400 ] );
		}
		if ( self::throttled( 'comment', 30 ) ) {
			return new WP_Error( 'pf_slow', 'Rustig aan! Wacht even voor je weer reageert.', [ 'status' => 429 ] );
		}

		// Altijd eerst goedkeuren in WP-admin.
		$cid = wp_insert_comment( [
			'comment_post_ID'      => $id,
			'comment_author'       => $name,
			'comment_author_email' => '',
			'comment_author_url'   => '',
			'comment_author_IP'    => '',
			'comment_agent'        => '',
			'comment_content'      => $text,
			'comment_approved'     => 0,
			'comment_type'         => 'comment',
		] );
		if ( ! $cid ) {
			return new WP_Error( 'pf_fail', 'Opslaan mislukt.', [ 'status' => 500 ] );
		}
		wp_notify_moderator( $cid );
		return [ 'ok' => true, 'pending' => true ];
	}
}
