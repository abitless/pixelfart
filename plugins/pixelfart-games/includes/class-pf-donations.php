<?php
/**
 * Donaties en de pixelmuur.
 *
 * - Doneren: 1, 2, 5 of 10 euro, eenmalig of per maand (blokken "Doneerknop" en "Doneren").
 * - Pixelmuur: pf-pixels van 10×10 schermpixels voor € 1 per stuk, met drie letters en een korte boodschap.
 *   De muur is PIXEL_COLS breed en groeit naar boven: y telt vanaf de onderste rij, dus nieuwe rijen
 *   verschuiven niets aan wat er al staat.
 * - Afhandeling via Mollie (Nederlands, iDEAL | Wero). Zonder sleutel op een lokale/ontwikkelsite: testmodus
 *   met nepbetalingen. Op een echte site zonder sleutel staat doneren uit.
 *
 * Privacy: we bewaren bedrag, datum, en (bij pixels) drie letters, boodschap en de pixels. Alleen bij een
 * maandelijkse donatie een e-mailadres, voor de stoplink; dat wissen we zodra de donatie stopt.
 * Bankgegevens komen nooit bij ons: die blijven bij Mollie.
 */

defined( 'ABSPATH' ) || exit;

class PF_Donations {

	const DB_VERSION  = '1';
	const AMOUNTS     = [ 100, 200, 500, 1000 ]; // centen
	const PIXEL_PRICE = 100;                     // centen per pf-pixel
	const PIXEL_COLS  = 48;                      // breedte van de muur in pf-pixels (480 schermpixels)
	const START_ROWS  = 24;
	const MAX_PIXELS  = 100;                     // per keer
	const RESERVE     = 30 * MINUTE_IN_SECONDS;  // zo lang blijven pixels vast tijdens het betalen
	const MSG_MAX     = 140;

	/** Kleuren van de muur (index = wat we opslaan). Zelfde tinten als de site, plus een paar extra. */
	const PALETTE = [ '#f2f2f2', '#bdbdbd', '#6a6a6a', '#ff5c8a', '#ff4d4d', '#ff9a3c', '#ffcf4a', '#5dde7a', '#1f8a3c', '#6ec9ff', '#2c5bd6', '#b07cff', '#a0643c', '#ffc8a8' ];

	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_install' ] );
		add_action( 'init', [ __CLASS__, 'register_blocks' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'template_redirect', [ __CLASS__, 'test_checkout' ] );
		add_action( 'pf_donations_cleanup', [ __CLASS__, 'cleanup' ] );
		if ( ! wp_next_scheduled( 'pf_donations_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'pf_donations_cleanup' );
		}
	}

	/* ---------- opslag ---------- */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pf_donations';
	}

	public static function pixel_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pf_pixels';
	}

	public static function maybe_install() {
		if ( get_option( 'pf_donations_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL,
			kind varchar(8) NOT NULL DEFAULT 'gift',
			recurring tinyint(1) NOT NULL DEFAULT 0,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount int(10) unsigned NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'open',
			mollie_id varchar(40) NOT NULL DEFAULT '',
			customer_id varchar(40) NOT NULL DEFAULT '',
			sub_id varchar(40) NOT NULL DEFAULT '',
			sub_status varchar(12) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			stop_hash char(64) NOT NULL DEFAULT '',
			name char(3) NOT NULL DEFAULT '',
			message varchar(200) NOT NULL DEFAULT '',
			msg_status varchar(10) NOT NULL DEFAULT '',
			pixels smallint(5) unsigned NOT NULL DEFAULT 0,
			pixel_data mediumtext NULL,
			hidden tinyint(1) NOT NULL DEFAULT 0,
			conflict tinyint(1) NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			paid datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY mollie_id (mollie_id),
			KEY sub_id (sub_id),
			KEY status_created (status, created)
		) $cc;" );
		dbDelta( 'CREATE TABLE ' . self::pixel_table() . " (
			x smallint(5) unsigned NOT NULL,
			y smallint(5) unsigned NOT NULL,
			color tinyint(3) unsigned NOT NULL,
			donation_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (x, y),
			KEY donation_id (donation_id)
		) $cc;" );
		add_option( 'pf_wall_rows', self::START_ROWS );
		update_option( 'pf_donations_db', self::DB_VERSION, true );
	}

	public static function row( string $field, $value ): ?array {
		global $wpdb;
		$col = in_array( $field, [ 'id', 'token', 'mollie_id', 'sub_id', 'stop_hash' ], true ) ? $field : 'id';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE $col = %s LIMIT 1", (string) $value ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	private static function update( int $id, array $data, array $where_extra = [] ): int {
		global $wpdb;
		return (int) $wpdb->update( self::table(), $data, [ 'id' => $id ] + $where_extra );
	}

	/* ---------- instellingen ---------- */

	/** 'mollie' (echt of Mollie-test), 'test' (nepbetalingen, alleen lokaal) of 'off'. */
	public static function mode(): string {
		if ( PF_Mollie::configured() ) {
			return 'mollie';
		}
		return in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ? 'test' : 'off';
	}

	public static function page_id(): int {
		return (int) get_option( 'pf_donate_page', 0 );
	}

	public static function page_url(): string {
		$id = self::page_id();
		return $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : home_url( '/doneren/' );
	}

	public static function rows(): int {
		return max( 4, (int) get_option( 'pf_wall_rows', self::START_ROWS ) );
	}

	public static function moderate(): bool {
		return (bool) get_option( 'pf_wall_moderate', 1 );
	}

	/** Wat de betaalprovider ongeveer inhoudt (centen), om eerlijk te kunnen laten zien. */
	public static function fee( bool $monthly ): int {
		return (int) get_option( $monthly ? 'pf_fee_month' : 'pf_fee_once', $monthly ? 35 : 32 );
	}

	public static function euro( int $cents ): string {
		return '€ ' . ( 0 === $cents % 100 ? (string) intdiv( $cents, 100 ) : number_format( $cents / 100, 2, ',', '.' ) );
	}

	/* ---------- blokken ---------- */

	public static function register_blocks() {
		wp_register_script( 'pixelfart-donate', PIXELFART_URL . 'assets/donate.js', [], PIXELFART_VERSION, [ 'strategy' => 'defer', 'in_footer' => true ] );
		wp_localize_script( 'pixelfart-donate', 'PFD', [
			'rest'    => esc_url_raw( rest_url( PF_Rest::NS . '/' ) ),
			'page'    => self::page_url(),
			'mode'    => self::mode(),
			'palette' => self::PALETTE,
			'max'     => self::MAX_PIXELS,
			'price'   => self::PIXEL_PRICE,
			'fee'     => [ 'once' => self::fee( false ), 'month' => self::fee( true ) ],
		] );
		foreach ( [ 'donate-button', 'donate-widget', 'pixel-wall' ] as $b ) {
			register_block_type( PIXELFART_DIR . 'blocks/' . $b );
		}
	}

	/** Staat dit blok op de doneerpagina zelf (dan handelen we daar af) of ergens anders (dan linken we erheen)? */
	public static function on_donate_page(): bool {
		$id = self::page_id();
		return $id ? is_page( $id ) : is_page( 'doneren' );
	}

	/* ---------- REST ---------- */

	public static function routes() {
		$ns = PF_Rest::NS;
		register_rest_route( $ns, '/donate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_donate' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'amount'   => [ 'required' => true, 'type' => 'integer', 'enum' => self::AMOUNTS ],
				'interval' => [ 'type' => 'string', 'enum' => [ 'once', 'month' ], 'default' => 'once' ],
				'email'    => [ 'type' => 'string', 'default' => '' ],
				'hp'       => [ 'type' => 'string', 'default' => '' ],
			],
		] );
		register_rest_route( $ns, '/pixels', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_wall' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_buy' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'pixels'  => [ 'required' => true, 'type' => 'array' ],
					'name'    => [ 'type' => 'string', 'default' => '' ],
					'message' => [ 'type' => 'string', 'default' => '' ],
					'hp'      => [ 'type' => 'string', 'default' => '' ],
				],
			],
		] );
		register_rest_route( $ns, '/donate/status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_status' ],
			'permission_callback' => '__return_true',
			'args'                => [ 'token' => [ 'required' => true, 'type' => 'string' ] ],
		] );
		register_rest_route( $ns, '/donate/stop', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ __CLASS__, 'rest_stop' ],
			'permission_callback' => '__return_true',
			'args'                => [ 'token' => [ 'required' => true, 'type' => 'string' ] ],
		] );
		register_rest_route( $ns, '/mollie', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_webhook' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function rest_donate( WP_REST_Request $r ) {
		if ( '' !== $r['hp'] ) {
			return new WP_Error( 'pf_bot', 'Er ging iets mis.', [ 'status' => 400 ] );
		}
		$monthly = 'month' === $r['interval'];
		$email   = '';
		if ( $monthly ) {
			$email = sanitize_email( (string) $r['email'] );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'pf_email', 'Vul een e-mailadres in. Daar sturen we de link om te stoppen naartoe.', [ 'status' => 400 ] );
			}
		}
		$busy = self::busy();
		if ( $busy ) {
			return $busy;
		}
		return self::start( [
			'kind'      => 'gift',
			'recurring' => $monthly ? 1 : 0,
			'amount'    => (int) $r['amount'],
			'email'     => $email,
		] );
	}

	public static function rest_buy( WP_REST_Request $r ) {
		if ( '' !== $r['hp'] ) {
			return new WP_Error( 'pf_bot', 'Er ging iets mis.', [ 'status' => 400 ] );
		}
		$px = self::clean_pixels( (array) $r['pixels'] );
		if ( is_wp_error( $px ) ) {
			return $px;
		}
		$name = strtoupper( trim( (string) $r['name'] ) );
		if ( '' !== $name ) {
			$bad = PF_Scores::initials_error( $name );
			if ( $bad ) {
				return $bad;
			}
		}
		$msg = trim( preg_replace( '/\s+/', ' ', sanitize_textarea_field( (string) $r['message'] ) ) );
		$msg = mb_substr( $msg, 0, self::MSG_MAX );
		if ( '' !== $msg ) {
			$spam = PF_Rest::spammy( $name, $msg, 0 );
			if ( $spam ) {
				return $spam;
			}
		}
		$busy = self::busy();
		if ( $busy ) {
			return $busy;
		}
		return self::start( [
			'kind'       => 'pixels',
			'amount'     => count( $px ) * self::PIXEL_PRICE,
			'name'       => $name,
			'message'    => $msg,
			'msg_status' => '' === $msg ? '' : ( self::moderate() ? 'pending' : 'approved' ),
			'pixels'     => count( $px ),
			'pixel_data' => wp_json_encode( $px ),
		], $px );
	}

	/** Doneren uit, of te snel achter elkaar? */
	private static function busy(): ?WP_Error {
		if ( 'off' === self::mode() ) {
			return new WP_Error( 'pf_off', 'Doneren kan op dit moment nog niet. Kom later terug!', [ 'status' => 503 ] );
		}
		if ( PF_Rest::throttled( 'donate', 5 ) ) {
			return new WP_Error( 'pf_slow', 'Even wachten, je startte net al een betaling.', [ 'status' => 429 ] );
		}
		return null;
	}

	/** Controleer de gekozen pixels: [[x, y, kleur], …], binnen de muur, geen dubbele. */
	private static function clean_pixels( array $in ) {
		$cols = self::PIXEL_COLS;
		$rows = self::rows();
		$out  = [];
		foreach ( $in as $p ) {
			if ( ! is_array( $p ) || 3 !== count( $p ) ) {
				return new WP_Error( 'pf_px', 'Er klopt iets niet met de pixels.', [ 'status' => 400 ] );
			}
			[ $x, $y, $c ] = array_map( 'intval', array_values( $p ) );
			if ( $x < 0 || $x >= $cols || $y < 0 || $y >= $rows || $c < 0 || $c >= count( self::PALETTE ) ) {
				return new WP_Error( 'pf_px', 'Er klopt iets niet met de pixels.', [ 'status' => 400 ] );
			}
			$out[ "$x,$y" ] = [ $x, $y, $c ];
		}
		$out = array_values( $out );
		if ( ! $out ) {
			return new WP_Error( 'pf_px_none', 'Kies eerst een of meer pixels.', [ 'status' => 400 ] );
		}
		if ( count( $out ) > self::MAX_PIXELS ) {
			return new WP_Error( 'pf_px_max', sprintf( 'Maximaal %d pixels per keer.', self::MAX_PIXELS ), [ 'status' => 400 ] );
		}
		return $out;
	}

	/**
	 * Pixels vastzetten voor een donatie. Lukt alles of niets (de primaire sleutel x,y voorkomt dubbel verkopen).
	 *
	 * @return array Bezette pixels als [x, y]-paren (leeg = gelukt).
	 */
	private static function place( int $donation_id, array $px ): array {
		global $wpdb;
		$vals = [];
		foreach ( $px as [ $x, $y, $c ] ) {
			$vals[] = $wpdb->prepare( '(%d,%d,%d,%d)', $x, $y, $c, $donation_id );
		}
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->query( 'INSERT INTO ' . self::pixel_table() . ' (x, y, color, donation_id) VALUES ' . implode( ',', $vals ) ); // phpcs:ignore
		$wpdb->suppress_errors( $suppress );
		if ( false !== $ok ) {
			return [];
		}
		$taken = [];
		$where = implode( ' OR ', array_map( fn( $p ) => $wpdb->prepare( '(x = %d AND y = %d)', $p[0], $p[1] ), $px ) );
		foreach ( $wpdb->get_results( 'SELECT x, y FROM ' . self::pixel_table() . " WHERE donation_id <> $donation_id AND ($where)", ARRAY_N ) as $t ) { // phpcs:ignore
			$taken[] = [ (int) $t[0], (int) $t[1] ];
		}
		return $taken ?: [ [ -1, -1 ] ];
	}

	private static function release( int $donation_id ) {
		global $wpdb;
		$wpdb->delete( self::pixel_table(), [ 'donation_id' => $donation_id ] );
	}

	/** Donatie opslaan en een betaling starten. Geeft de betaallink terug. */
	private static function start( array $d, array $px = [] ) {
		global $wpdb;
		self::release_stale();
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->insert( self::table(), $d + [ 'token' => $token, 'status' => 'open', 'created' => current_time( 'mysql', true ) ] );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'pf_fail', 'Opslaan mislukt. Probeer het nog eens.', [ 'status' => 500 ] );
		}
		if ( $px ) {
			$taken = self::place( $id, $px );
			if ( $taken ) {
				$wpdb->delete( self::table(), [ 'id' => $id ] );
				return new WP_Error( 'pf_px_taken', 'Iemand was je net voor: een paar pixels zijn al bezet. Kies andere.', [ 'status' => 409, 'taken' => $taken ] );
			}
		}

		$checkout = self::checkout( array_merge( $d, [ 'id' => $id, 'token' => $token ] ) );
		if ( is_wp_error( $checkout ) ) {
			self::release( $id );
			$wpdb->delete( self::table(), [ 'id' => $id ] );
			return $checkout;
		}
		return [ 'checkout' => $checkout, 'token' => $token ];
	}

	private static function description( array $d ): string {
		if ( 'pixels' === $d['kind'] ) {
			return sprintf( 'Pixelfart: %d %s', $d['pixels'], 1 === (int) $d['pixels'] ? 'pixel' : 'pixels' );
		}
		return ! empty( $d['recurring'] ) ? 'Pixelfart: maandelijkse donatie' : 'Pixelfart: donatie';
	}

	public static function return_url( string $token ): string {
		return add_query_arg( 'bedankt', $token, self::page_url() );
	}

	/** Mollie kan lokale adressen niet bereiken; dan halen we de status op als de bezoeker terugkomt. */
	private static function webhook_url(): ?string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( preg_match( '/(^localhost$|\.local$|\.test$|\.localtest\.me$|^127\.)/', $host ) ) {
			return null;
		}
		return rest_url( PF_Rest::NS . '/mollie' );
	}

	/** @return string|WP_Error */
	private static function checkout( array $d ) {
		if ( 'test' === self::mode() ) {
			return add_query_arg( 'pf_testbetaling', $d['token'], home_url( '/' ) );
		}
		$body = array_filter( [
			'amount'      => PF_Mollie::money( (int) $d['amount'] ),
			'description' => self::description( $d ),
			'redirectUrl' => self::return_url( $d['token'] ),
			'webhookUrl'  => self::webhook_url(),
			'metadata'    => [ 'token' => $d['token'] ],
			'locale'      => 'nl_NL',
		] );

		if ( ! empty( $d['recurring'] ) ) {
			$customer = PF_Mollie::create_customer( $d['email'] );
			if ( is_wp_error( $customer ) ) {
				return $customer;
			}
			$body['sequenceType'] = 'first'; // eerste maand + machtiging voor de volgende maanden
			$payment = PF_Mollie::create_customer_payment( $customer['id'], $body );
			self::update( (int) $d['id'], [ 'customer_id' => $customer['id'] ] );
		} else {
			$payment = PF_Mollie::create_payment( $body );
		}
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		self::update( (int) $d['id'], [ 'mollie_id' => $payment['id'] ] );
		return (string) ( $payment['_links']['checkout']['href'] ?? '' ) ?: new WP_Error( 'pf_mollie', 'Geen betaallink ontvangen.', [ 'status' => 502 ] );
	}

	/* ---------- status ---------- */

	private static function map_status( string $s ): string {
		return match ( $s ) {
			'paid'     => 'paid',
			'failed'   => 'failed',
			'canceled' => 'canceled',
			'expired'  => 'expired',
			default    => 'open',
		};
	}

	/** Status bij Mollie opvragen en verwerken (bij terugkomst, webhook of opruimen). */
	public static function sync( array $row ): array {
		if ( 'open' !== $row['status'] || ! $row['mollie_id'] || 'mollie' !== self::mode() ) {
			return $row;
		}
		$p = PF_Mollie::get_payment( $row['mollie_id'] );
		if ( is_wp_error( $p ) ) {
			return $row;
		}
		self::set_status( $row, self::map_status( (string) $p['status'] ) );
		return self::row( 'id', $row['id'] );
	}

	/**
	 * Nieuwe status vastleggen. De UPDATE … WHERE status = 'open' zorgt dat de vervolgstappen
	 * (pixels, mails, abonnement) maar één keer gebeuren, ook als webhook en terugkomst tegelijk binnenkomen.
	 */
	public static function set_status( array $row, string $status ) {
		if ( 'open' === $status || $status === $row['status'] ) {
			return;
		}
		$data = [ 'status' => $status ];
		if ( 'paid' === $status ) {
			$data['paid'] = current_time( 'mysql', true );
		}
		if ( ! self::update( (int) $row['id'], $data, [ 'status' => 'open' ] ) ) {
			return; // iemand anders was ons voor
		}
		$row = array_merge( $row, $data );

		if ( 'paid' !== $status ) {
			self::release( (int) $row['id'] );
			return;
		}
		if ( 'pixels' === $row['kind'] ) {
			self::ensure_pixels( $row );
			self::maybe_grow();
			self::notify_admin( $row );
		}
		if ( $row['recurring'] && ! $row['parent_id'] ) {
			self::start_subscription( $row );
		}
		do_action( 'litespeed_purge', 'pf_wall' );
	}

	/** Na een late betaling kan de reservering al verlopen zijn: zet de pixels dan alsnog (of meld een botsing). */
	private static function ensure_pixels( array $row ) {
		global $wpdb;
		$have = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::pixel_table() . ' WHERE donation_id = %d', $row['id'] ) ); // phpcs:ignore
		$px   = json_decode( (string) $row['pixel_data'], true ) ?: [];
		if ( $have >= count( $px ) ) {
			return;
		}
		self::release( (int) $row['id'] );
		if ( self::place( (int) $row['id'], $px ) ) {
			// Een deel is intussen verkocht: plaats wat nog vrij is en laat de beheerder het weten.
			foreach ( $px as $p ) {
				self::place( (int) $row['id'], [ $p ] );
			}
			self::update( (int) $row['id'], [ 'conflict' => 1 ] );
		}
	}

	/** Bijna vol? Dan komen er vanzelf rijen bij (bovenaan). */
	private static function maybe_grow() {
		if ( ! get_option( 'pf_wall_autogrow', 1 ) ) {
			return;
		}
		global $wpdb;
		$used = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::pixel_table() ); // phpcs:ignore
		if ( $used >= 0.85 * self::PIXEL_COLS * self::rows() ) {
			update_option( 'pf_wall_rows', self::rows() + 4 );
		}
	}

	private static function start_subscription( array $row ) {
		$stop = bin2hex( random_bytes( 16 ) );
		if ( 'test' === self::mode() ) {
			$sub = [ 'id' => 'test_sub_' . substr( $row['token'], 0, 12 ) ];
		} else {
			$sub = PF_Mollie::create_subscription( $row['customer_id'], array_filter( [
				'amount'      => PF_Mollie::money( (int) $row['amount'] ),
				'interval'    => '1 month',
				'startDate'   => gmdate( 'Y-m-d', strtotime( '+1 month' ) ),
				'description' => 'Pixelfart: maandelijkse donatie',
				'webhookUrl'  => self::webhook_url(),
				'metadata'    => [ 'parent' => $row['token'] ],
			] ) );
			if ( is_wp_error( $sub ) ) {
				error_log( 'Pixelfart: abonnement starten mislukt voor donatie ' . $row['id'] ); // phpcs:ignore
				self::update( (int) $row['id'], [ 'sub_status' => 'failed' ] );
				return;
			}
		}
		self::update( (int) $row['id'], [ 'sub_id' => $sub['id'], 'sub_status' => 'active', 'stop_hash' => self::hash( $stop ) ] );

		$url = add_query_arg( 'stoppen', $stop, self::page_url() );
		wp_mail(
			$row['email'],
			'Bedankt voor je maandelijkse donatie aan Pixelfart',
			sprintf(
				"Hoi!\n\nWat leuk dat je Pixelfart steunt met %s per maand. Daar kunnen we de site mee draaiende houden en nieuwe games maken.\n\nDe volgende keer schrijven we het bedrag af rond %s. Wil je stoppen? Dat kan altijd, met één klik:\n%s\n\nBewaar deze mail dus even. Je e-mailadres gebruiken we alleen hiervoor, en we wissen het zodra je stopt.\n\nGroetjes,\nvader & zoon van Pixelfart",
				self::euro( (int) $row['amount'] ),
				wp_date( 'j F', strtotime( '+1 month' ) ),
				$url
			)
		);
		self::notify_admin( $row );
	}

	private static function hash( string $s ): string {
		return hash_hmac( 'sha256', $s, wp_salt( 'auth' ) );
	}

	private static function notify_admin( array $row ) {
		$to = get_option( 'pf_mail_to' ) ?: get_option( 'admin_email' );
		if ( 'pixels' === $row['kind'] ) {
			$subject = sprintf( '[Pixelfart] %d pixels gekocht', $row['pixels'] );
			$body    = sprintf( "%s kocht %d pixels (%s).\n", $row['name'] ?: 'Iemand', $row['pixels'], self::euro( (int) $row['amount'] ) );
			if ( 'pending' === $row['msg_status'] ) {
				$body .= "\nBoodschap (wacht op goedkeuring):\n" . $row['message'] . "\n";
			}
		} else {
			$subject = '[Pixelfart] Nieuwe maandelijkse donatie';
			$body    = sprintf( "Iemand doneert vanaf nu %s per maand.\n", self::euro( (int) $row['amount'] ) );
		}
		wp_mail( $to, $subject, $body . "\n" . admin_url( 'admin.php?page=pf-donaties' ) );
	}

	public static function rest_status( WP_REST_Request $r ) {
		$row = preg_match( '/^[a-f0-9]{32}$/', (string) $r['token'] ) ? self::row( 'token', $r['token'] ) : null;
		if ( ! $row ) {
			return new WP_Error( 'pf_not_found', 'Deze betaling kennen we niet.', [ 'status' => 404 ] );
		}
		// Niet vaker dan eens per 3 seconden bij Mollie navragen.
		if ( 'open' === $row['status'] && ! get_transient( 'pf_sync_' . $row['id'] ) ) {
			set_transient( 'pf_sync_' . $row['id'], 1, 3 );
			$row = self::sync( $row );
		}
		return [
			'status'    => $row['status'],
			'kind'      => $row['kind'],
			'amount'    => (int) $row['amount'],
			'recurring' => (bool) $row['recurring'],
			'pixels'    => (int) $row['pixels'],
			'moderated' => 'pending' === $row['msg_status'],
		];
	}

	/** Mollie meldt een statuswijziging. We vertrouwen alleen wat we daarna zelf bij Mollie opvragen. */
	public static function rest_webhook( WP_REST_Request $r ) {
		$id = (string) $r->get_param( 'id' );
		if ( 'mollie' !== self::mode() || ! preg_match( '/^tr_[A-Za-z0-9]+$/', $id ) ) {
			return new WP_REST_Response( null, 200 );
		}
		$row = self::row( 'mollie_id', $id );
		if ( $row ) {
			self::sync( $row );
			return new WP_REST_Response( null, 200 );
		}
		// Onbekend: een maandelijkse afschrijving van een lopende donatie.
		$p = PF_Mollie::get_payment( $id );
		if ( is_wp_error( $p ) || empty( $p['subscriptionId'] ) ) {
			return new WP_REST_Response( null, 200 );
		}
		$parent = self::row( 'sub_id', (string) $p['subscriptionId'] );
		if ( $parent ) {
			global $wpdb;
			$status = self::map_status( (string) $p['status'] );
			$wpdb->insert( self::table(), [
				'token'     => bin2hex( random_bytes( 16 ) ),
				'kind'      => 'gift',
				'recurring' => 1,
				'parent_id' => (int) $parent['id'],
				'amount'    => (int) round( (float) $p['amount']['value'] * 100 ),
				'status'    => $status,
				'mollie_id' => $id,
				'created'   => current_time( 'mysql', true ),
				'paid'      => 'paid' === $status ? current_time( 'mysql', true ) : null,
			] );
		}
		return new WP_REST_Response( null, 200 );
	}

	/** Maandelijkse donatie stoppen via de link uit de mail. GET = alleen kijken, POST = stoppen. */
	public static function rest_stop( WP_REST_Request $r ) {
		$token = (string) $r['token'];
		$row   = preg_match( '/^[a-f0-9]{32}$/', $token ) ? self::row( 'stop_hash', self::hash( $token ) ) : null;
		if ( ! $row ) {
			return new WP_Error( 'pf_not_found', 'Deze link werkt niet (meer). Misschien is de donatie al gestopt?', [ 'status' => 404 ] );
		}
		if ( 'POST' === $r->get_method() ) {
			$ok = self::stop( $row );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			return [ 'stopped' => true, 'amount' => (int) $row['amount'] ];
		}
		return [ 'stopped' => false, 'amount' => (int) $row['amount'] ];
	}

	/** Maandelijkse donatie stoppen (ook vanuit het beheer). Wist het e-mailadres en de klant bij Mollie. */
	public static function stop( array $row ) {
		if ( 'active' === $row['sub_status'] && 'mollie' === self::mode() && ! str_starts_with( $row['sub_id'], 'test_' ) ) {
			$res = PF_Mollie::cancel_subscription( $row['customer_id'], $row['sub_id'] );
			if ( is_wp_error( $res ) && 404 !== ( $res->get_error_data()['mollie'] ?? 0 ) ) {
				return new WP_Error( 'pf_stop', 'Stoppen lukte even niet. Probeer het zo nog eens, of stuur ons een bericht.', [ 'status' => 502 ] );
			}
			PF_Mollie::delete_customer( $row['customer_id'] );
		}
		self::update( (int) $row['id'], [ 'sub_status' => 'canceled', 'email' => '', 'stop_hash' => '' ] );
		return true;
	}

	/* ---------- de muur ---------- */

	/** Open betalingen ouder dan RESERVE: pixels weer vrijgeven (de betaling blijft open voor als hij nog binnenkomt). */
	public static function release_stale() {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', time() - self::RESERVE );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore
			'DELETE p FROM ' . self::pixel_table() . ' p JOIN ' . self::table() . " d ON d.id = p.donation_id WHERE d.status = 'open' AND d.created < %s",
			$before
		) );
	}

	/** Elk uur: verlopen betalingen nalopen en heel oude open betalingen sluiten. */
	public static function cleanup() {
		global $wpdb;
		self::release_stale();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'open' AND created < %s LIMIT 50", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$row = self::sync( $row );
			if ( 'open' === $row['status'] && strtotime( $row['created'] . ' UTC' ) < time() - 14 * DAY_IN_SECONDS ) {
				self::set_status( $row, 'expired' );
			}
		}
	}

	/**
	 * De muur voor de browser, zo compact mogelijk:
	 * px = [x, y, kleur, wie, …] met wie = index in d, -1 = gereserveerd (wordt betaald), -2 = verborgen.
	 */
	public static function wall(): array {
		global $wpdb;
		self::release_stale();
		$rows  = $wpdb->get_results(
			'SELECT p.x, p.y, p.color, d.id, d.status, d.hidden, d.name, d.message, d.msg_status, d.pixels, d.paid FROM ' . self::pixel_table() . ' p JOIN ' . self::table() . ' d ON d.id = p.donation_id ORDER BY d.id', // phpcs:ignore
			ARRAY_A
		);
		$px    = [];
		$donor = [];
		$index = [];
		foreach ( $rows as $r ) {
			if ( 'paid' !== $r['status'] ) {
				$who = -1;
			} elseif ( $r['hidden'] ) {
				$who = -2;
			} else {
				if ( ! isset( $index[ $r['id'] ] ) ) {
					$index[ $r['id'] ] = count( $donor );
					$donor[]           = [
						'n' => $r['name'] ?: '???',
						'm' => 'approved' === $r['msg_status'] ? $r['message'] : '',
						'w' => 'pending' === $r['msg_status'],
						't' => wp_date( 'j M Y', strtotime( $r['paid'] . ' UTC' ) ),
						'k' => (int) $r['pixels'],
					];
				}
				$who = $index[ $r['id'] ];
			}
			array_push( $px, (int) $r['x'], (int) $r['y'], (int) $r['color'], $who );
		}
		return [ 'cols' => self::PIXEL_COLS, 'rows' => self::rows(), 'px' => $px, 'd' => $donor ];
	}

	public static function rest_wall() {
		$res = new WP_REST_Response( self::wall() );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/* ---------- testmodus (alleen lokaal, zonder Mollie-sleutel) ---------- */

	public static function test_checkout() {
		if ( empty( $_GET['pf_testbetaling'] ) || 'test' !== self::mode() ) { // phpcs:ignore
			return;
		}
		$row = self::row( 'token', sanitize_key( wp_unslash( $_GET['pf_testbetaling'] ) ) ); // phpcs:ignore
		if ( ! $row ) {
			wp_die( 'Onbekende testbetaling.' );
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['uitkomst'] ) ) { // phpcs:ignore
			$s = sanitize_key( wp_unslash( $_POST['uitkomst'] ) ); // phpcs:ignore
			if ( in_array( $s, [ 'paid', 'failed', 'canceled' ], true ) ) {
				self::set_status( $row, $s );
			}
			wp_safe_redirect( self::return_url( $row['token'] ) );
			exit;
		}
		nocache_headers();
		$what = self::description( $row ) . ' · ' . self::euro( (int) $row['amount'] ) . ( $row['recurring'] ? ' per maand' : '' );
		?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Testbetaling</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#000;color:#f2f2f2;font:20px ui-monospace,monospace}main{max-width:420px;padding:24px;box-shadow:0 0 0 4px #ffcf4a}h1{font-size:18px;color:#ffcf4a;margin:0 0 12px}p{margin:0 0 20px;line-height:1.3}form{display:flex;flex-wrap:wrap;gap:12px}button{font:inherit;font-size:16px;border:0;padding:12px 16px;cursor:pointer}.ok{background:#5dde7a}.no{background:#ff5c8a}.x{background:#6a6a6a;color:#fff}</style>
</head><body><main>
<h1>TESTBETALING</h1>
<p><?php echo esc_html( $what ); ?></p>
<p>Dit is de testmodus: er is nog geen Mollie-sleutel ingesteld en dit is een lokale site. Er wordt niet echt betaald.</p>
<form method="post"><button class="ok" name="uitkomst" value="paid">BETAALD</button><button class="no" name="uitkomst" value="failed">MISLUKT</button><button class="x" name="uitkomst" value="canceled">ANNULEREN</button></form>
</main></body></html>
		<?php
		exit;
	}
}
