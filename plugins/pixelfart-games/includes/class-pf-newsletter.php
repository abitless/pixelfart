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
 * Aankondigen gaat vanzelf: publiceer je een nieuwe game (met HTML-bestand), dan gaat er na DELAY minuten een mail
 * naar alle abonnees. In die tijd kun je het nog tegenhouden (kader "Nieuwsbrief" bij de game). Eén keer per game,
 * niet bij `wp pixelfart import`. Versturen gaat in kleine porties via WP-Cron, één mail per ontvanger
 * (nooit een lijst in CC/BCC).
 *
 * Beheer: menu Nieuwsbrief in WP-admin (adressen zoeken en wissen).
 */

defined( 'ABSPATH' ) || exit;

class PF_Newsletter {

	const DB_VERSION = '1';
	const BATCH      = 25;
	const PENDING    = 7 * DAY_IN_SECONDS;
	const DELAY      = 10 * MINUTE_IN_SECONDS; // bedenktijd na publiceren
	const PER_PAGE   = 50;
	const PAGE       = 'pf-nieuwsbrief';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'init', [ __CLASS__, 'rewrite' ] );
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 20 ); // na de rewrite-regels
		add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ 'pf_nieuwsbrief' ] ) );
		add_filter( 'template_include', [ __CLASS__, 'template' ] );
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_pf_nb_delete', [ __CLASS__, 'admin_delete' ] );
		add_action( 'admin_post_pf_nb_bulk', [ __CLASS__, 'admin_bulk' ] );
		add_action( 'admin_post_pf_nb_announce', [ __CLASS__, 'admin_announce' ] );
		add_action( 'admin_post_pf_nb_cancel', [ __CLASS__, 'admin_cancel' ] );
		add_action( 'admin_post_pf_nb_test', [ __CLASS__, 'admin_test' ] );
		add_action( 'wp_after_insert_post', [ __CLASS__, 'auto_announce' ], 20, 4 ); // na save_post: HTML en beschrijving staan er dan
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
			. "Groetjes,\nJOZ & NAT van Pixelfart.nl\n"
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
			self::box( $p );
			$me = wp_get_current_user()->user_email;
			if ( $me && current_user_can( 'edit_post', $p->ID ) ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=pf_nb_test&id=' . $p->ID ), 'pf_nb_test_' . $p->ID );
				printf( '<hr><p><a class="button" href="%s" style="height:auto;min-height:0;padding:6px 12px;line-height:1.4;white-space:normal;text-align:center;overflow-wrap:anywhere">Stuur testmail naar<br><strong>%s</strong></a></p>', esc_url( $url ), esc_html( $me ) );
				echo '<p class="description">Precies de mail die abonnees krijgen, alleen naar jou. Telt niet als aankondiging. Sla eerst op als je net iets hebt veranderd.</p>';
			}
		}, PF_Games::CPT, 'side' );
	}

	/** Inhoud van het kader Nieuwsbrief (zonder de testknop). */
	private static function box( WP_Post $p ) {
		$sent   = (int) get_post_meta( $p->ID, '_pf_announced', true );
		$active = self::count();
		$job    = self::job( $p->ID );
		$n      = sprintf( '%d %s', $active, 1 === $active ? 'abonnee' : 'abonnees' );
		if ( $job && ! $job['after'] && $job['at'] > time() ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=pf_nb_cancel&id=' . $p->ID ), 'pf_nb_cancel_' . $p->ID );
			printf( '<p><strong>Mail gaat om %s</strong> naar %s.</p>', esc_html( wp_date( 'H:i', $job['at'] ) ), esc_html( $n ) );
			printf( '<p><a class="button" href="%s" onclick="return confirm(\'Deze aankondiging niet versturen?\')">Niet versturen</a></p>', esc_url( $url ) );
			return;
		}
		if ( $job ) {
			echo '<p><strong>Bezig met versturen</strong> (in porties van ' . (int) self::BATCH . ' per minuut).</p>';
			return;
		}
		if ( $sent ) {
			printf( '<p>Aangekondigd op %s.</p>', esc_html( wp_date( 'j F Y, H:i', $sent ) ) );
			return;
		}
		if ( 'publish' !== $p->post_status ) {
			printf( '<p>Publiceer je deze game, dan krijgen %s vanzelf een mail (%d minuten later; tot dan kun je het tegenhouden).</p>', esc_html( $n ), (int) ( self::DELAY / MINUTE_IN_SECONDS ) );
			return;
		}
		echo get_post_meta( $p->ID, '_pf_nb_skip', true ) ? '<p>Niet verstuurd (tegengehouden).</p>' : '<p>Nog niet aangekondigd.</p>';
		if ( $active ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=pf_nb_announce&id=' . $p->ID ), 'pf_nb_announce_' . $p->ID );
			printf( '<p><a class="button" href="%s" onclick="return confirm(\'Mail sturen naar %s?\')">Toch versturen</a></p>', esc_url( $url ), esc_attr( $n ) );
		}
	}

	/** Testmail naar de ingelogde beheerder, met een afmeldlink die nergens iets wist. */
	public static function admin_test() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'pf_nb_test_' . $id );
		$me = wp_get_current_user()->user_email;
		if ( ! current_user_can( 'edit_post', $id ) || PF_Games::CPT !== get_post_type( $id ) || ! $me ) {
			wp_die( 'Dit kan niet.' );
		}
		$game = PF_Games::get( $id );
		if ( 'publish' !== get_post_status( $id ) ) {
			$game['permalink'] = get_preview_post_link( $id ) ?: $game['permalink']; // concept: de link werkt pas na publiceren
		}
		[ $subject, $body ] = self::message( $game, add_query_arg( [ 'a' => 'afmelden', 'id' => 0, 't' => 'test' ], home_url( '/nieuwsbrief/' ) ) );
		$ok = wp_mail( $me, '[TEST] ' . $subject, $body );
		set_transient( 'pf_notes_' . get_current_user_id(), [ $ok ? [ 'success', "Testmail verstuurd naar $me." ] : [ 'error', "Testmail naar $me kon niet worden verstuurd." ] ], 60 );
		wp_safe_redirect( get_edit_post_link( $id, 'url' ) );
		exit;
	}

	/**
	 * Nieuwe game online: aankondiging inplannen. Eén keer per game, alleen met HTML-bestand (anders zodra dat er is),
	 * niet als we hem hebben tegengehouden en niet bij een import.
	 */
	public static function auto_announce( int $id, WP_Post $post, bool $update, ?WP_Post $before ) {
		if ( PF_Games::CPT !== $post->post_type || 'publish' !== $post->post_status || PF_Announcements::$quiet ) {
			return;
		}
		if ( get_post_meta( $id, '_pf_announced', true ) || get_post_meta( $id, '_pf_nb_skip', true ) ) {
			return;
		}
		$just = ! $before || 'publish' !== $before->post_status;
		if ( ! $just && ! get_post_meta( $id, '_pf_nb_wait', true ) ) {
			return; // stond al online vóór deze functie bestond: niet alsnog mailen
		}
		if ( ! file_exists( PF_Games::dir( (string) PF_Games::meta( $id, 'code' ) ) . 'game.html' ) ) {
			update_post_meta( $id, '_pf_nb_wait', 1 ); // nog geen game: mailen zodra het HTML-bestand er is
			return;
		}
		delete_post_meta( $id, '_pf_nb_wait' );
		update_post_meta( $id, '_pf_announced', time() + self::DELAY );
		self::queue( $id, time() + self::DELAY );
	}

	/** Deze game staat in de wachtrij (of null). */
	public static function job( int $game_id ): ?array {
		foreach ( (array) get_option( 'pf_nb_queue', [] ) as $job ) {
			if ( (int) $job['game'] === $game_id ) {
				return $job + [ 'at' => 0 ];
			}
		}
		return null;
	}

	public static function admin_announce() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'pf_nb_announce_' . $id );
		if ( ! current_user_can( 'publish_posts' ) || PF_Games::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			wp_die( 'Dit kan niet.' );
		}
		if ( ! get_post_meta( $id, '_pf_announced', true ) && ! self::job( $id ) ) {
			delete_post_meta( $id, '_pf_nb_skip' );
			update_post_meta( $id, '_pf_announced', time() );
			self::queue( $id );
		}
		wp_safe_redirect( get_edit_post_link( $id, 'url' ) );
		exit;
	}

	/** Ingeplande aankondiging tegenhouden (alleen zolang er nog niets verstuurd is). */
	public static function admin_cancel() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'pf_nb_cancel_' . $id );
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( 'Dit kan niet.' );
		}
		$queue = array_values( array_filter( (array) get_option( 'pf_nb_queue', [] ), fn( $j ) => ! ( (int) $j['game'] === $id && ! $j['after'] ) ) );
		update_option( 'pf_nb_queue', $queue, false );
		if ( ! self::job( $id ) ) {
			delete_post_meta( $id, '_pf_announced' );
			update_post_meta( $id, '_pf_nb_skip', 1 );
		}
		wp_safe_redirect( get_edit_post_link( $id, 'url' ) );
		exit;
	}

	/** @param int $at Niet eerder versturen dan dit tijdstip (0 = meteen). */
	public static function queue( int $game_id, int $at = 0 ) {
		$queue   = (array) get_option( 'pf_nb_queue', [] );
		$queue[] = [ 'game' => $game_id, 'after' => 0, 'at' => $at ];
		update_option( 'pf_nb_queue', $queue, false );
		$next = wp_next_scheduled( 'pf_nb_send' );
		if ( ! $next || $next > max( time(), $at ) ) {
			wp_clear_scheduled_hook( 'pf_nb_send' );
			wp_schedule_single_event( max( time(), $at ), 'pf_nb_send' );
		}
	}

	/** Onderwerp en tekst van de aankondiging (ook voor de testmail). @return array{0:string,1:string} */
	public static function message( array $game, string $unsub ): array {
		return [
			'Nieuwe game op Pixelfart: ' . $game['title'],
			"Hoi!\n\n"
			. 'Er staat een nieuwe game op Pixelfart: ' . $game['title'] . "\n\n"
			. ( $game['description'] ? $game['description'] . "\n\n" : '' )
			. 'Speel hem hier: ' . $game['permalink'] . "\n\n"
			. "Veel plezier!\nJOZ & NAT van Pixelfart.nl\n\n"
			. "--\nJe krijgt deze mail omdat je je hebt aangemeld op " . wp_parse_url( home_url(), PHP_URL_HOST ) . ".\n"
			. 'Afmelden: ' . $unsub . "\n",
		];
	}

	/** Eén portie mails versturen; plant zichzelf opnieuw tot de wachtrij leeg is. */
	public static function send_batch() {
		global $wpdb;
		$queue = (array) get_option( 'pf_nb_queue', [] );
		if ( ! $queue ) {
			return;
		}
		usort( $queue, fn( $x, $y ) => ( $x['at'] ?? 0 ) <=> ( $y['at'] ?? 0 ) ); // de eerstvolgende vooraan
		$job = $queue[0];
		if ( ( $job['at'] ?? 0 ) > time() ) { // nog in de bedenktijd
			update_option( 'pf_nb_queue', $queue, false );
			wp_schedule_single_event( (int) $job['at'], 'pf_nb_send' );
			return;
		}
		$game = 'publish' === get_post_status( (int) $job['game'] ) ? PF_Games::get( (int) $job['game'] ) : null; // offline gehaald? dan niet
		$rows = $game ? $wpdb->get_results( $wpdb->prepare( 'SELECT id, email FROM ' . self::table() . " WHERE status = 'active' AND id > %d ORDER BY id LIMIT %d", $job['after'], self::BATCH ), ARRAY_A ) : []; // phpcs:ignore

		foreach ( $rows as $row ) {
			[ $subject, $body ] = self::message( $game, self::unsubscribe_link( $row ) );
			wp_mail( $row['email'], $subject, $body );
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
		add_menu_page( 'Nieuwsbrief', 'Nieuwsbrief', 'manage_options', self::PAGE, [ __CLASS__, 'admin_page' ], 'dashicons-email-alt', 26 );
	}

	private static function page_url( array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	public static function admin_page() {
		global $wpdb;
		self::cleanup();
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$status = in_array( $_GET['status'] ?? '', [ 'active', 'pending' ], true ) ? $_GET['status'] : ''; // phpcs:ignore
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$where  = 'WHERE 1=1';
		$params = [];
		if ( '' !== $search ) {
			$where   .= ' AND email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( strtolower( $search ) ) . '%';
		}
		if ( $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		$t     = self::table();
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM $t $where", $params ) : "SELECT COUNT(*) FROM $t $where" ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, status, created, confirmed FROM $t $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] ) ), ARRAY_A ); // phpcs:ignore
		$pages = (int) ceil( $total / self::PER_PAGE );
		$all   = self::count() + self::count( 'pending' );
		$msg   = sanitize_key( $_GET['msg'] ?? '' ); // phpcs:ignore
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Nieuwsbrief</h1>
			<hr class="wp-header-end">
			<?php if ( 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo (int) ( $_GET['n'] ?? 0 ); // phpcs:ignore ?> adres(sen) gewist.</p></div>
			<?php endif; ?>
			<p style="max-width:760px">Mensen die een mail willen bij een nieuwe game. Die mail gaat vanzelf, <?php echo (int) ( self::DELAY / MINUTE_IN_SECONDS ); ?> minuten nadat je een nieuwe game publiceert (tegenhouden kan in het kader <em>Nieuwsbrief</em> bij de game). We bewaren alleen het e-mailadres. Wissen is definitief; afmelden via de link in een mail wist het adres ook.</p>
			<?php
			$queue = (array) get_option( 'pf_nb_queue', [] );
			foreach ( $queue as $job ) {
				$title = get_the_title( (int) $job['game'] );
				echo ( $job['at'] ?? 0 ) > time()
					? '<div class="notice notice-info inline"><p>Ingepland: aankondiging van <strong>' . esc_html( $title ) . '</strong> om ' . esc_html( wp_date( 'H:i', (int) $job['at'] ) ) . '.</p></div>'
					: '<div class="notice notice-info inline"><p>Bezig met versturen: <strong>' . esc_html( $title ) . '</strong>.</p></div>';
			}
			?>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( self::page_url() ); ?>" class="<?php echo $status ? '' : 'current'; ?>">Alle <span class="count">(<?php echo (int) $all; ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( self::page_url( [ 'status' => 'active' ] ) ); ?>" class="<?php echo 'active' === $status ? 'current' : ''; ?>">Bevestigd <span class="count">(<?php echo (int) self::count(); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( self::page_url( [ 'status' => 'pending' ] ) ); ?>" class="<?php echo 'pending' === $status ? 'current' : ''; ?>">Wacht op bevestiging <span class="count">(<?php echo (int) self::count( 'pending' ); ?>)</span></a></li>
			</ul>
			<form method="get" class="search-form" style="float:right;margin:8px 0">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( $status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><?php endif; ?>
				<label class="screen-reader-text" for="pf-nb-s">Zoek een adres</label>
				<input type="search" id="pf-nb-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Zoek een adres">
				<button class="button">Zoeken</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return this.querySelectorAll('input[name=&quot;ids[]&quot;]:checked').length ? confirm('De geselecteerde adressen definitief wissen?') : (alert('Selecteer eerst een of meer adressen.'), false)">
				<input type="hidden" name="action" value="pf_nb_bulk">
				<?php wp_nonce_field( 'pf_nb_bulk' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions"><button class="button">Geselecteerde wissen</button></div>
					<?php self::pagination( $paged, $pages, $total ); ?>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" onclick="document.querySelectorAll('input[name=&quot;ids[]&quot;]').forEach(c=>c.checked=this.checked)" aria-label="Alles selecteren"></td>
						<th>E-mail</th><th style="width:150px">Status</th><th style="width:120px">Aangemeld</th><th style="width:120px">Bevestigd</th>
					</tr></thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5"><?php echo $search ? 'Geen adressen gevonden.' : 'Nog niemand aangemeld.'; ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php $del = wp_nonce_url( admin_url( 'admin-post.php?action=pf_nb_delete&id=' . $r['id'] ), 'pf_nb_delete_' . $r['id'] ); ?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>" aria-label="<?php echo esc_attr( $r['email'] ); ?> selecteren"></th>
							<td><strong><?php echo esc_html( $r['email'] ); ?></strong>
								<div class="row-actions"><span class="trash"><a href="<?php echo esc_url( $del ); ?>" onclick="return confirm('<?php echo esc_js( $r['email'] ); ?> definitief wissen?')">Wissen</a></span></div></td>
							<td><?php echo 'active' === $r['status'] ? 'Bevestigd' : 'Wacht op bevestiging'; ?></td>
							<td><?php echo esc_html( wp_date( 'j M Y', strtotime( $r['created'] . ' UTC' ) ) ); ?></td>
							<td><?php echo $r['confirmed'] ? esc_html( wp_date( 'j M Y', strtotime( $r['confirmed'] . ' UTC' ) ) ) : '–'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="tablenav bottom"><?php self::pagination( $paged, $pages, $total ); ?></div>
			</form>
		</div>
		<?php
	}

	private static function pagination( int $paged, int $pages, int $total ) {
		echo '<div class="tablenav-pages"><span class="displaying-num">' . (int) $total . ' ' . ( 1 === $total ? 'adres' : 'adressen' ) . '</span>';
		if ( $pages > 1 ) {
			echo ' ' . paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages, 'prev_text' => '‹', 'next_text' => '›' ] ); // phpcs:ignore
		}
		echo '</div>';
	}

	private static function delete_ids( array $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore -- alleen gehele getallen
	}

	public static function admin_delete() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'pf_nb_delete_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Geen rechten.' );
		}
		$n = self::delete_ids( [ $id ] );
		wp_safe_redirect( self::page_url( [ 'msg' => 'deleted', 'n' => $n ] ) );
		exit;
	}

	public static function admin_bulk() {
		check_admin_referer( 'pf_nb_bulk' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Geen rechten.' );
		}
		$n = self::delete_ids( (array) ( $_POST['ids'] ?? [] ) );
		wp_safe_redirect( self::page_url( [ 'msg' => 'deleted', 'n' => $n ] ) );
		exit;
	}
}
