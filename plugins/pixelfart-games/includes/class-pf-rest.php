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
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'no_cache' ], 10, 3 );
		// Gameversie bij reacties in WP-admin (Reacties).
		add_filter( 'manage_edit-comments_columns', fn( $c ) => array_slice( $c, 0, 3, true ) + [ 'pf_version' => 'Gameversie' ] + $c );
		add_action( 'manage_comments_custom_column', function ( $col, $cid ) {
			if ( 'pf_version' === $col ) {
				echo esc_html( get_comment_meta( $cid, 'pf_version', true ) ?: '–' );
			}
		}, 10, 2 );
	}

	/**
	 * Onze endpoints zijn live gegevens (pixelmuur, betaalstatus, topscores): nooit in de paginacache.
	 * LiteSpeed Cache bewaart REST-antwoorden anders ook, en negeert daarbij Cache-Control: no-store.
	 */
	public static function no_cache( $res, $server, $req ) {
		if ( str_starts_with( (string) $req->get_route(), '/' . self::NS . '/' ) ) {
			do_action( 'litespeed_control_set_nocache', 'pixelfart: live gegevens' );
			if ( $res instanceof WP_REST_Response && empty( $res->get_headers()['Cache-Control'] ) ) {
				$res->header( 'Cache-Control', 'no-store' );
			}
		}
		return $res;
	}

	public static function routes() {
		$id =[ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ] ];

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
				'version' => [ 'type' => 'string', 'default' => '' ],
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
				'altcha'  => [ 'type' => 'string', 'default' => '' ],
				'version' => [ 'type' => 'string', 'default' => '' ],
			],
		] );
	}

	/* ---------- helpers ---------- */

	/**
	 * IP-adres van de bezoeker, alleen om te beperken (we slaan het nooit op).
	 * X-Forwarded-For telt alleen als het verzoek van een proxy op het eigen netwerk komt; dan nemen we het laatste
	 * adres (dat zet de proxy zelf). Anders kan een bot met een verzonnen header elke limiet omzeilen.
	 */
	public static function client_ip(): string {
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$public = filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( ! $public && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$hops = array_map( 'trim', explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$last = end( $hops );
			if ( filter_var( $last, FILTER_VALIDATE_IP ) ) {
				return $last;
			}
		}
		return $remote;
	}

	/** Tekst die op spam lijkt: te veel links, of woorden uit Instellingen › Discussie › Niet-toegestane reacties. */
	public static function spammy( string $name, string $text, int $max_links = 0 ): ?WP_Error {
		if ( preg_match_all( '#https?://|www\.|\[url#i', $text ) > $max_links ) {
			return new WP_Error( 'pf_links', 0 === $max_links ? 'Links zijn hier niet toegestaan.' : 'Er staan te veel links in je bericht.', [ 'status' => 400 ] );
		}
		if ( wp_check_comment_disallowed_list( $name, '', '', $text, '', '' ) ) {
			return new WP_Error( 'pf_words', 'Dit bericht kunnen we niet plaatsen.', [ 'status' => 400 ] );
		}
		return null;
	}

	public static function visitor_key( string $scope ): string {
		$ip = self::client_ip();
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

	public static function game( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( PF_Games::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return new WP_Error( 'pf_not_found', 'Game niet gevonden.', [ 'status' => 404 ] );
		}
		return $id;
	}

	/**
	 * De versie die de bezoeker speelde: wat de pagina meestuurt, als die versie echt bestaat (de game kan
	 * net bijgewerkt zijn terwijl iemand speelde). Anders de huidige versie.
	 */
	public static function played_version( int $id, string $sent ): string {
		$current = (string) PF_Games::meta( $id, 'version', '1.0.0' );
		if ( '' === $sent || $sent === $current ) {
			return $current;
		}
		$known = wp_list_pluck( (array) ( get_post_meta( $id, '_pf_changelog', true ) ?: [] ), 'version' );
		$known = array_merge( $known, array_values( PF_Games::versions( (string) PF_Games::meta( $id, 'code' ) ) ) );
		return in_array( $sent, $known, true ) ? $sent : $current;
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
		$out = [ 'plays' => $plays ];
		if ( PF_Scores::enabled( PF_Games::get( $id ) ) ) {
			$out['run'] = PF_Scores::run_token( $id ); // speelbewijs voor de topscorelijst
		}
		return $out;
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
		self::mail_rating( $id, $stars, $prev, $sum, $count, (string) $r['version'] );

		return [ 'avg' => $count ? round( $sum / $count, 1 ) : 0, 'count' => $count, 'mine' => $stars ];
	}

	/**
	 * Mail naar de beheerder bij elke stem, zodat je kunt zien of ratings goed binnenkomen.
	 * Verstuurd na het antwoord aan de browser, zodat de bezoeker niet op de mailserver wacht.
	 */
	public static function mail_rating( int $id, int $stars, int $prev, int $sum, int $count, string $version ) {
		$to = get_option( 'pf_mail_to' ) ?: get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$title = get_the_title( $id );
		$what  = $prev
			? sprintf( "Aangepast: van %d naar %d sterren.\nLet op: vanaf dit netwerk (zelfde IP-adres) was al eerder gestemd, dus het aantal stemmen blijft gelijk.", $prev, $stars )
			: sprintf( 'Nieuwe stem: %d %s.', $stars, 1 === $stars ? 'ster' : 'sterren' );
		$body  = sprintf(
			"%s\n\nGame: %s%s\nGemiddelde nu: %s uit %d %s (som %d)\nTijd: %s\n\n%s\n%s\n",
			$what,
			$title,
			$version ? ' (versie ' . self::played_version( $id, $version ) . ')' : '',
			$count ? number_format( $sum / $count, 1, ',', '' ) : '–',
			$count,
			1 === $count ? 'stem' : 'stemmen',
			$sum,
			wp_date( 'j F Y H:i:s' ),
			get_permalink( $id ),
			admin_url( 'post.php?post=' . $id . '&action=edit' )
		);
		$subject = sprintf( '[Pixelfart] %s: %s %d★', $title, $prev ? 'stem aangepast naar' : 'nieuwe stem', $stars );
		add_action( 'shutdown', function () use ( $to, $subject, $body ) {
			if ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			} elseif ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			wp_mail( $to, $subject, $body );
		} );
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
		$name = strtoupper( trim( (string) $r['name'] ) );
		$bad  = PF_Scores::initials_error( $name );
		if ( $bad ) {
			return $bad;
		}
		$text = mb_substr( sanitize_textarea_field( $r['text'] ), 0, 600 );
		if ( mb_strlen( trim( $text ) ) < 2 ) {
			return new WP_Error( 'pf_empty', 'Schrijf eerst iets.', [ 'status' => 400 ] );
		}
		$spam = self::spammy( $name, $text, 0 );
		if ( $spam ) {
			return $spam;
		}
		$ok = PF_Altcha::verify( $r['altcha'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
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
		// Op welke versie reageerde iemand? Handig bij een gemelde fout. De datum bewaart WordPress zelf.
		add_comment_meta( $cid, 'pf_version', self::played_version( $id, (string) $r['version'] ), true );
		wp_notify_moderator( $cid );
		return [ 'ok' => true, 'pending' => true ];
	}
}
