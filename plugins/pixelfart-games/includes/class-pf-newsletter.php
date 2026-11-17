<?php
/**
 * Nieuwsbrief: "mail me als er een nieuwe game is".
 *
 * Privacy en veiligheid:
 * - We bewaren alleen het e-mailadres, de status en twee data. Geen naam, geen IP, geen tracking in mails
 *   (platte tekst, geen plaatjes of tellers).
 * - Dubbele bevestiging (double opt-in): pas na een klik op de link in de mail staat iemand op de lijst.
 *   Onbevestigde adressen wissen we na een week.
 * - Afmelden kan altijd, via de link onderaan elke mail. Afmelden = wissen.
 * - Tegen misbruik (iemand anders aanmelden, mail-bom): ALTCHA, tempolimiet, en hooguit één bevestigingsmail
 *   per adres per dag. Het antwoord is altijd hetzelfde, zodat niemand kan uitzoeken wie er op de lijst staat.
 * - De bevestigingslink bevat een geheim token; we bewaren alleen de hash ervan. De afmeldlink is per adres vast
 *   (afgeleid met een geheime sleutel), zodat hij ook in oude mails blijft werken.
 *
 * Een aankondiging versturen is een bewuste actie in WP-admin (kader "Nieuwsbrief" bij een game),
 * in kleine porties via WP-Cron, één mail per ontvanger (nooit een lijst in CC/BCC).
 */

defined( 'ABSPATH' ) || exit;

class PF_Newsletter {

	const DB_VERSION = '1';
	const BATCH      = 25;
	const PENDING    = 7 * DAY_IN_SECONDS;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'init', [ __CLASS__, 'rewrite' ] );
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 20 ); // na de rewrite-regels
		add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ 'pf_nieuwsbrief' ] ) );
		add_filter( 'template_include', [ __CLASS__, 'template' ] );
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_pf_nb_delete', [ __CLASS__, 'admin_delete' ] );
		add_action( 'admin_post_pf_nb_announce', [ __CLASS__, 'admin_announce' ] );
		add_action( 'add_meta_boxes_' . PF_Games::CPT, [ __CLASS__, 'meta_box' ] );
		add_action( 'pf_nb_send', [ __CLASS__, 'send_batch' ] );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pf_subscribers';
	}

	public static function maybe_install() {
		if ( get_option( 'pf_nb_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'pending',
			token char(64) NOT NULL,
			created datetime NOT NULL,
			confirmed datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY token (token)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'pf_nb_db', self::DB_VERSION, true );
		self::rewrite();
		flush_rewrite_rules( false );
	}

	/* ---------- adressen en links ---------- */

	public static function rewrite() {
		add_rewrite_rule( '^nieuwsbrief/?$', 'index.php?pf_nieuwsbrief=1', 'top' );
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	public static function link( string $action, string $token ): string {
		return add_query_arg( [ 'a' => $action, 't' => $token ], home_url( '/nieuwsbrief/' ) );
	}

	/** Vaste afmeldlink voor een adres. */
	public static function unsubscribe_link( array $row ): string {
		return add_query_arg( [ 'a' => 'afmelden', 'id' => (int) $row['id'], 't' => self::unsub_token( $row ) ], home_url( '/nieuwsbrief/' ) );
	}

	private static function unsub_token( array $row ): string {
		return substr( hash_hmac( 'sha256', 'afmelden|' . $row['id'] . '|' . $row['email'], wp_salt( 'auth' ) ), 0, 32 );
	}

	private static function find( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', self::hash( $token ) ), ARRAY_A ) ?: null; // phpcs:ignore
	}

	public static function count( string $status = 'active' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', $status ) ); // phpcs:ignore
	}

	/** Onbevestigde aanmeldingen ouder dan een week wissen. */
	private static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status = 'pending' AND created < %s", gmdate( 'Y-m-d H:i:s', time() - self::PENDING ) ) ); // phpcs:ignore
	}

	/* ---------- aanmelden ---------- */

	public static function routes() {
		register_rest_route( PF_Rest::NS, '/subscribe', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'subscribe' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'email'  => [ 'required' => true, 'type' => 'string' ],
				'hp'     => [ 'type' => 'string', 'default' => '' ],
				'altcha' => [ 'type' => 'string', 'default' => '' ],
			],
		] );
	}

	public static function subscribe( WP_REST_Request $r ) {
		$done = [ 'ok' => true ]; // altijd hetzelfde antwoord
		if ( '' !== $r['hp'] ) {
			return $done;
		}
		$email = strtolower( sanitize_email( (string) $r['email'] ) );
		if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
			return new WP_Error( 'pf_email', 'Dat e-mailadres klopt niet helemaal.', [ 'status' => 400 ] );
		}
		$ok = PF_Altcha::verify( $r['altcha'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( PF_Rest::throttled( 'nb', 60 ) ) {
			return new WP_Error( 'pf_slow', 'Even wachten, je meldde net al iets aan.', [ 'status' => 429 ] );
		}

		global $wpdb;
		self::cleanup();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', $email ), ARRAY_A ); // phpcs:ignore
		if ( $row && ( 'active' === $row['status'] || strtotime( $row['created'] . ' UTC' ) > time() - DAY_IN_SECONDS ) ) {
			return $done; // staat er al op, of kreeg vandaag al een bevestigingsmail
		}

		$token = bin2hex( random_bytes( 16 ) );
		$data  = [ 'email' => $email, 'status' => 'pending', 'token' => self::hash( $token ), 'created' => current_time( 'mysql', true ), 'confirmed' => null ];
		$row ? $wpdb->update( self::table(), $data, [ 'id' => $row['id'] ] ) : $wpdb->insert( self::table(), $data );

		wp_mail(
			$email,
			'Bevestig je aanmelding bij Pixelfart',
			"Hoi!\n\n"
			. "Iemand (hopelijk jij) wil een mailtje krijgen als er een nieuwe game op Pixelfart staat.\n"
			. "Klopt dat? Klik dan op deze link om het te bevestigen:\n\n"
			. self::link( 'bevestig', $token ) . "\n\n"
			. "Was jij dit niet? Dan hoef je niets te doen. Zonder bevestiging sturen we niets en wissen we je adres binnen een week.\n\n"
			. "Groetjes,\nPixelfart (vader & zoon)\n"
		);
		return $done;
	}

	/* ---------- bevestigen en afmelden (pagina /nieuwsbrief/) ---------- */

	public static function template( $template ) {
		if ( ! get_query_var( 'pf_nieuwsbrief' ) ) {
			return $template;
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'nieuwsbrief' );
		header( 'X-Robots-Tag: noindex' );
		return PIXELFART_DIR . 'templates/nieuwsbrief.php';
	}

	/**
	 * Wat de pagina moet tonen. Een GET toont alleen een knop (mailscanners die links openen bevestigen of
	 * melden dus niets af); de knop doet een POST.
	 *
	 * @return array{state:string, email?:string, action?:string}
	 */
	public static function handle(): array {
		global $wpdb;
		$action = sanitize_key( wp_unslash( $_GET['a'] ?? '' ) );
		$token  = sanitize_key( wp_unslash( $_GET['t'] ?? '' ) );
		if ( 'afmelden' === $action ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $_GET['id'] ?? 0 ) ), ARRAY_A ); // phpcs:ignore
			$row = $row && hash_equals( self::unsub_token( $row ), $token ) ? $row : null;
		} else {
			$row = self::find( $token );
		}
		if ( ! in_array( $action, [ 'bevestig', 'afmelden' ], true ) || ! $row ) {
			return [ 'state' => 'onbekend' ];
		}
		$masked = self::mask( $row['email'] );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( 'bevestig' === $action && 'active' === $row['status'] ) {
				return [ 'state' => 'bevestigd', 'email' => $masked ];
			}
			return [ 'state' => 'vraag', 'action' => $action, 'email' => $masked ];
		}

		if ( 'afmelden' === $action ) {
			$wpdb->delete( self::table(), [ 'id' => $row['id'] ] ); // afmelden = alles wissen
			return [ 'state' => 'afgemeld', 'email' => $masked ];
		}
		$wpdb->update( self::table(), [ 'status' => 'active', 'confirmed' => current_time( 'mysql', true ) ], [ 'id' => $row['id'] ] );
		return [ 'state' => 'bevestigd', 'email' => $masked ];
	}

	/** n***@voorbeeld.nl */
	public static function mask( string $email ): string {
		[ $user, $domain ] = array_pad( explode( '@', $email, 2 ), 2, '' );
		return mb_substr( $user, 0, 1 ) . '***@' . $domain;
	}

	/* ---------- aankondigen ---------- */

	public static function meta_box( WP_Post $post ) {
		add_meta_box( 'pf_nb', 'Nieuwsbrief', function ( WP_Post $p ) {
			$sent   = get_post_meta( $p->ID, '_pf_announced', true );
			$active = self::count();
			if ( $sent ) {
				printf( '<p>Aangekondigd op %s.</p>', esc_html( wp_date( 'j F Y, H:i', (int) $sent ) ) );
				return;
			}
			if ( 'publish' !== $p->post_status ) {
				echo '<p>Na publiceren kun je deze game hier aankondigen.</p>';
				return;
			}
			printf( '<p>%d %s.</p>', $active, 1 === $active ? 'abonnee' : 'abonnees' );
			if ( $active ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=pf_nb_announce&id=' . $p->ID ), 'pf_nb_announce_' . $p->ID );
				printf( '<p><a class="button" href="%s" onclick="return confirm(\'Mail sturen naar %d %s?\')">Stuur aankondiging</a></p>', esc_url( $url ), $active, 1 === $active ? 'abonnee' : 'abonnees' );
				echo '<p class="description">Eén keer per game. Een korte mail in platte tekst met de titel, de beschrijving en een link.</p>';
			}
		}, PF_Games::CPT, 'side' );
	}

	public static function admin_announce() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'pf_nb_announce_' . $id );
		if ( ! current_user_can( 'publish_posts' ) || PF_Games::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			wp_die( 'Dit kan niet.' );
		}
		if ( ! get_post_meta( $id, '_pf_announced', true ) ) {
			update_post_meta( $id, '_pf_announced', time() );
			self::queue( $id );
		}
		wp_safe_redirect( get_edit_post_link( $id, 'url' ) );
		exit;
	}

	public static function queue( int $game_id ) {
		$queue   = (array) get_option( 'pf_nb_queue', [] );
		$queue[] = [ 'game' => $game_id, 'after' => 0 ];
		update_option( 'pf_nb_queue', $queue, false );
		if ( ! wp_next_scheduled( 'pf_nb_send' ) ) {
			wp_schedule_single_event( time(), 'pf_nb_send' );
		}
	}

	/** Eén portie mails versturen; plant zichzelf opnieuw tot de wachtrij leeg is. */
	public static function send_batch() {
		global $wpdb;
		$queue = (array) get_option( 'pf_nb_queue', [] );
		if ( ! $queue ) {
			return;
		}
		$job  = $queue[0];
		$game = PF_Games::get( (int) $job['game'] );
		$rows = $game ? $wpdb->get_results( $wpdb->prepare( 'SELECT id, email FROM ' . self::table() . " WHERE status = 'active' AND id > %d ORDER BY id LIMIT %d", $job['after'], self::BATCH ), ARRAY_A ) : []; // phpcs:ignore

		foreach ( $rows as $row ) {
			$unsub = self::unsubscribe_link( $row );
			wp_mail(
				$row['email'],
				'Nieuwe game op Pixelfart: ' . $game['title'],
				"Hoi!\n\n"
				. 'Er staat een nieuwe game op Pixelfart: ' . $game['title'] . "\n\n"
				. ( $game['description'] ? $game['description'] . "\n\n" : '' )
				. 'Speel hem hier: ' . $game['permalink'] . "\n\n"
				. "Veel plezier!\nPixelfart (vader & zoon)\n\n"
				. "--\nJe krijgt deze mail omdat je je hebt aangemeld op " . wp_parse_url( home_url(), PHP_URL_HOST ) . ".\n"
				. 'Afmelden: ' . $unsub . "\n"
			);
			// Geen List-Unsubscribe-header: PHP mail() codeert lange headers onleesbaar. De link staat in de mail.
			$job['after'] = (int) $row['id'];
		}

		if ( count( $rows ) < self::BATCH ) {
			array_shift( $queue ); // klaar met deze game
		} else {
			$queue[0] = $job;
		}
		update_option( 'pf_nb_queue', $queue, false );
		if ( $queue ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'pf_nb_send' );
		}
	}

	/* ---------- beheer ---------- */

	public static function admin_menu() {
		add_submenu_page( 'edit.php?post_type=' . PF_Games::CPT, 'Nieuwsbrief', 'Nieuwsbrief', 'manage_options', 'pf-newsletter', [ __CLASS__, 'admin_page' ] );
	}

	public static function admin_page() {
		global $wpdb;
		self::cleanup();
		$rows = $wpdb->get_results( 'SELECT id, email, status, created, confirmed FROM ' . self::table() . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore
		echo '<div class="wrap"><h1>Nieuwsbrief</h1>';
		printf( '<p><strong>%d</strong> bevestigd, %d wacht op bevestiging (wordt na een week gewist).</p>', self::count(), self::count( 'pending' ) );
		echo '<p style="max-width:720px">Mensen die een mail willen bij een nieuwe game. Een aankondiging stuur je vanuit een game (kader <em>Nieuwsbrief</em>). We bewaren alleen het e-mailadres; afmelden wist het adres meteen.</p>';
		if ( ! $rows ) {
			echo '<p>Nog niemand aangemeld.</p></div>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>E-mail</th><th>Status</th><th>Aangemeld</th><th>Bevestigd</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$del = wp_nonce_url( admin_url( 'admin-post.php?action=pf_nb_delete&id=' . $r['id'] ), 'pf_nb_delete_' . $r['id'] );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" style="color:#b32d2e" onclick="return confirm(\'Dit adres wissen?\')">wissen</a></td></tr>',
				esc_html( $r['email'] ),
				'active' === $r['status'] ? 'bevestigd' : 'wacht',
				esc_html( wp_date( 'j M Y', strtotime( $r['created'] . ' UTC' ) ) ),
				$r['confirmed'] ? esc_html( wp_date( 'j M Y', strtotime( $r['confirmed'] . ' UTC' ) ) ) : '–',
				esc_url( $del )
			);
		}
		echo '</tbody></table></div>';
	}

	public static function admin_delete() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'pf_nb_delete_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Geen rechten.' );
		}
		global $wpdb;
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . PF_Games::CPT . '&page=pf-newsletter' ) );
		exit;
	}
}
