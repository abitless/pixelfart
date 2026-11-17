<?php
/**
 * Topscores aller tijden.
 *
 * - Een game meldt zijn eindscore met Pixelfart.score(n); de site vraagt de speler om 3 letters (arcadestijl).
 * - Geen persoonsgegevens: alleen 3 letters, de score en het tijdstip. Geen IP, geen account.
 * - Tegen valsspelen en spam (nooit waterdicht bij browsergames, wel drempels):
 *     · speelbewijs: bij het starten van een game geeft de server een ondertekend bewijs (game + tijd);
 *       een score telt pas als er minstens MIN_RUN seconden gespeeld is;
 *     · per bezoek telt per naam alleen de beste score (geen rij dubbele inzendingen);
 *     · plafond per game (instelbaar), ALTCHA-robotcheck, tempolimiet per bezoeker;
 *     · scheldwoordfilter op de letters; beheerders kunnen scores wissen (Games › Topscores).
 * - We bewaren per game alleen de beste KEEP scores: weinig data.
 */

defined( 'ABSPATH' ) || exit;

class PF_Scores {

	const DB_VERSION = '1';
	const KEEP       = 100;  // scores per game die we bewaren
	const SHOW       = 10;   // lengte van de lijst
	const MIN_RUN    = 8;    // seconden tussen start/vorige score en een nieuwe score
	const MAX_RUN    = 6 * HOUR_IN_SECONDS;

	/** Drieletterwoorden die we niet op de lijst willen (kinderen spelen mee). */
	const BLOCKED = [ 'ASS', 'FUK', 'FUC', 'FCK', 'KUT', 'SEX', 'DIK', 'PIK', 'PIS', 'KKK', 'NSB', 'SSS', 'HOE', 'TIT', 'CUM', 'GAY', 'NIG', 'NGR', 'WTF', 'STF', 'KLT', 'LUL', 'SUX', 'POO' ];

	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_install' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_pf_delete_score', [ __CLASS__, 'admin_delete' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'delete_for_game' ] );
		add_action( 'init', fn() => register_block_type( PIXELFART_DIR . 'blocks/highscores' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pf_scores';
	}

	public static function maybe_install() {
		if ( get_option( 'pf_scores_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			name char(3) NOT NULL,
			score bigint(20) NOT NULL,
			session char(16) NOT NULL DEFAULT '',
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY game_score (game_id, score),
			KEY game_session (game_id, session)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'pf_scores_db', self::DB_VERSION, true );
	}

	/* ---------- lezen ---------- */

	public static function enabled( array $g ): bool {
		return in_array( $g['score']['order'] ?? '', [ 'desc', 'asc' ], true );
	}

	/** Top-lijst van een game: [ [rank, name, score, date, id] … ]. */
	public static function top( int $game_id, int $limit = self::SHOW ): array {
		global $wpdb;
		$g = PF_Games::get( $game_id );
		if ( ! $g || ! self::enabled( $g ) ) {
			return [];
		}
		$dir  = 'asc' === $g['score']['order'] ? 'ASC' : 'DESC';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, name, score, created FROM ' . self::table() . " WHERE game_id = %d ORDER BY score $dir, id ASC LIMIT %d", // phpcs:ignore
			$game_id,
			$limit
		), ARRAY_A );
		$out = [];
		foreach ( $rows as $i => $r ) {
			$out[] = [ 'rank' => $i + 1, 'id' => (int) $r['id'], 'name' => $r['name'], 'score' => (int) $r['score'], 'date' => substr( $r['created'], 0, 10 ) ];
		}
		return $out;
	}

	/* ---------- speelbewijs ---------- */

	private static function key(): string {
		return hash_hmac( 'sha256', 'pf-run', wp_salt( 'nonce' ) );
	}

	/** Ondertekend bewijs: game, uitgiftetijd en een sessie-id per bezoek. */
	public static function run_token( int $game_id, string $session = '' ): string {
		$session = $session ?: bin2hex( random_bytes( 8 ) );
		$body    = $game_id . '.' . time() . '.' . $session;
		return $body . '.' . substr( hash_hmac( 'sha256', $body, self::key() ), 0, 32 );
	}

	/** @return array{game:int, time:int, session:string}|null */
	public static function read_token( string $token ): ?array {
		if ( ! preg_match( '/^(\d+)\.(\d+)\.([0-9a-f]{16})\.([0-9a-f]{32})$/', $token, $m ) ) {
			return null;
		}
		$body = "$m[1].$m[2].$m[3]";
		if ( ! hash_equals( substr( hash_hmac( 'sha256', $body, self::key() ), 0, 32 ), $m[4] ) ) {
			return null;
		}
		return [ 'game' => (int) $m[1], 'time' => (int) $m[2], 'session' => $m[3] ];
	}

	/* ---------- REST ---------- */

	public static function routes() {
		$id = [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ] ];
		register_rest_route( PF_Rest::NS, '/games/(?P<id>\d+)/scores', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_scores' ],
				'permission_callback' => '__return_true',
				'args'                => $id,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'post_score' ],
				'permission_callback' => '__return_true',
				'args'                => $id + [
					'name'   => [ 'required' => true, 'type' => 'string' ],
					'score'  => [ 'required' => true, 'type' => 'integer', 'minimum' => 0 ],
					'run'    => [ 'required' => true, 'type' => 'string' ],
					'altcha' => [ 'type' => 'string', 'default' => '' ],
				],
			],
		] );
	}

	public static function get_scores( WP_REST_Request $r ) {
		$id = PF_Rest::game( $r );
		return is_wp_error( $id ) ? $id : [ 'scores' => self::top( $id ) ];
	}

	public static function post_score( WP_REST_Request $r ) {
		$id = PF_Rest::game( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$g = PF_Games::get( $id );
		if ( ! self::enabled( $g ) ) {
			return new WP_Error( 'pf_no_scores', 'Deze game heeft geen topscorelijst.', [ 'status' => 400 ] );
		}
		$name  = strtoupper( (string) $r['name'] );
		$score = (int) $r['score'];
		$run   = self::read_token( (string) $r['run'] );
		$age   = $run ? time() - $run['time'] : 0;

		if ( ! preg_match( '/^[A-Z]{3}$/', $name ) ) {
			return new WP_Error( 'pf_name', 'Kies drie letters.', [ 'status' => 400 ] );
		}
		if ( in_array( $name, self::BLOCKED, true ) || wp_check_comment_disallowed_list( $name, '', '', '', '', '' ) ) {
			return new WP_Error( 'pf_name_blocked', 'Kies andere letters.', [ 'status' => 400 ] );
		}
		$max = (int) PF_Games::meta( $id, 'score_max', 0 );
		if ( $score > ( $max ?: 1000000000 ) ) {
			return new WP_Error( 'pf_score', 'Die score kunnen we niet plaatsen.', [ 'status' => 400 ] );
		}
		if ( ! $run || $run['game'] !== $id || $age > self::MAX_RUN ) {
			return new WP_Error( 'pf_run', 'Je speelsessie is verlopen. Herlaad de pagina en speel opnieuw.', [ 'status' => 400 ] );
		}
		if ( $age < self::MIN_RUN ) {
			return new WP_Error( 'pf_fast', 'Dat ging wel heel snel! Speel een potje en probeer het dan opnieuw.', [ 'status' => 400 ] );
		}
		$ok = PF_Altcha::verify( $r['altcha'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( PF_Rest::throttled( 'score', 5 ) ) {
			return new WP_Error( 'pf_slow', 'Even wachten, je score is net opgeslagen.', [ 'status' => 429 ] );
		}

		global $wpdb;
		$asc  = 'asc' === $g['score']['order'];
		$prev = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, score FROM ' . self::table() . ' WHERE game_id = %d AND session = %s AND name = %s LIMIT 1', // phpcs:ignore
			$id,
			$run['session'],
			$name
		), ARRAY_A );
		if ( $prev ) {
			// Zelfde bezoek, zelfde letters: alleen bijwerken als het beter is.
			$better = $asc ? $score < (int) $prev['score'] : $score > (int) $prev['score'];
			if ( $better ) {
				$wpdb->update( self::table(), [ 'score' => $score, 'created' => current_time( 'mysql', true ) ], [ 'id' => $prev['id'] ] );
			}
			$entry = (int) $prev['id'];
		} else {
			$wpdb->insert( self::table(), [
				'game_id' => $id,
				'name'    => $name,
				'score'   => $score,
				'session' => $run['session'],
				'created' => current_time( 'mysql', true ),
			] );
			$entry = (int) $wpdb->insert_id;
		}
		self::prune( $id, $asc );
		self::purge_cache( $id );

		$top = self::top( $id );
		return [
			'ok'     => true,
			'entry'  => $entry,
			'scores' => $top,
			'run'    => self::run_token( $id, $run['session'] ), // nieuw bewijs voor het volgende potje
		];
	}

	/** Alleen de beste KEEP scores per game bewaren. */
	private static function prune( int $id, bool $asc ) {
		global $wpdb;
		$dir    = $asc ? 'ASC' : 'DESC';
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE game_id = %d ORDER BY score $dir, id ASC LIMIT 1 OFFSET %d", $id, self::KEEP ) ); // phpcs:ignore
		if ( $cutoff ) {
			$keep = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE game_id = %d ORDER BY score $dir, id ASC LIMIT %d", $id, self::KEEP ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE game_id = %d AND id NOT IN (' . implode( ',', array_map( 'intval', $keep ) ) . ')', $id ) ); // phpcs:ignore
		}
	}

	/** Gecachte pagina's met deze lijst verversen (LiteSpeed). */
	public static function purge_cache( int $id ) {
		do_action( 'litespeed_purge_post', $id );
		do_action( 'litespeed_purge', 'pf_scores' );
	}

	public static function delete_for_game( int $id ) {
		if ( PF_Games::CPT === get_post_type( $id ) ) {
			global $wpdb;
			$wpdb->delete( self::table(), [ 'game_id' => $id ] );
		}
	}

	/* ---------- beheer ---------- */

	public static function admin_menu() {
		add_submenu_page( 'edit.php?post_type=' . PF_Games::CPT, 'Topscores', 'Topscores', 'moderate_comments', 'pf-scores', [ __CLASS__, 'admin_page' ] );
	}

	public static function admin_page() {
		echo '<div class="wrap"><h1>Topscores</h1><p>Per game de beste ' . (int) self::SHOW . '. Een score wissen haalt hem direct van de site. Er wordt niets anders bewaard dan drie letters, de score en de datum.</p>';
		foreach ( PF_Games::all() as $g ) {
			if ( ! self::enabled( $g ) ) {
				continue;
			}
			$top = self::top( $g['id'] );
			printf( '<h2>%s <small style="font-weight:400;color:#646970">%s · %s</small></h2>', esc_html( $g['title'] ), esc_html( $g['code'] ), 'asc' === $g['score']['order'] ? 'lager is beter' : 'hoger is beter' );
			if ( ! $top ) {
				echo '<p>Nog geen scores.</p>';
				continue;
			}
			echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>#</th><th>Naam</th><th>Score</th><th>Datum</th><th></th></tr></thead><tbody>';
			foreach ( $top as $s ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=pf_delete_score&id=' . $s['id'] ), 'pf_delete_score_' . $s['id'] );
				printf(
					'<tr><td>%d</td><td><code>%s</code></td><td>%s %s</td><td>%s</td><td><a href="%s" class="delete" style="color:#b32d2e">wissen</a></td></tr>',
					(int) $s['rank'],
					esc_html( $s['name'] ),
					esc_html( number_format_i18n( $s['score'] ) ),
					esc_html( $g['score']['label'] ),
					esc_html( wp_date( 'j M Y', strtotime( $s['date'] ) ) ),
					esc_url( $url )
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function admin_delete() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'pf_delete_score_' . $id );
		if ( ! current_user_can( 'moderate_comments' ) ) {
			wp_die( 'Geen rechten.' );
		}
		global $wpdb;
		$game = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT game_id FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		if ( $game ) {
			self::purge_cache( $game );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . PF_Games::CPT . '&page=pf-scores' ) );
		exit;
	}
}
