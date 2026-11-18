<?php
/**
 * Beheer › Donaties: overzicht, alle donaties (boodschappen goedkeuren, pixels verbergen, maanddonaties stoppen),
 * de pixelmuur (rijen erbij) en de instellingen (Mollie-sleutel, doneerpagina).
 */

defined( 'ABSPATH' ) || exit;

class PF_Donations_Admin {

	const SLUG = 'pf-donaties';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'settings' ] );
		add_action( 'admin_post_pf_donation', [ __CLASS__, 'action' ] );
		add_action( 'admin_post_pf_wall_grow', [ __CLASS__, 'grow' ] );
	}

	public static function menu() {
		global $wpdb;
		$wait  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PF_Donations::table() . " WHERE status = 'paid' AND msg_status = 'pending'" ); // phpcs:ignore
		$badge = $wait ? ' <span class="awaiting-mod count-' . $wait . '"><span class="pending-count">' . $wait . '</span></span>' : '';
		add_menu_page( 'Donaties', 'Donaties' . $badge, self::CAP, self::SLUG, [ __CLASS__, 'render' ], 'dashicons-heart', 26 );
	}

	public static function settings() {
		$g = self::SLUG;
		register_setting( $g, 'pf_mollie_key', [ 'type' => 'string', 'sanitize_callback' => [ __CLASS__, 'clean_key' ] ] );
		register_setting( $g, 'pf_donate_page', [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
		register_setting( $g, 'pf_wall_moderate', [ 'type' => 'integer', 'sanitize_callback' => fn( $v ) => $v ? 1 : 0 ] );
		register_setting( $g, 'pf_wall_autogrow', [ 'type' => 'integer', 'sanitize_callback' => fn( $v ) => $v ? 1 : 0 ] );
		register_setting( $g, 'pf_fee_once', [ 'type' => 'integer', 'sanitize_callback' => fn( $v ) => min( 200, absint( $v ) ) ] );
		register_setting( $g, 'pf_fee_month', [ 'type' => 'integer', 'sanitize_callback' => fn( $v ) => min( 200, absint( $v ) ) ] );
	}

	/** Lege invoer = sleutel niet wijzigen (we tonen hem nooit terug). "-" = wissen. */
	public static function clean_key( $v ): string {
		$v = trim( (string) $v );
		if ( '-' === $v ) {
			return '';
		}
		if ( '' === $v ) {
			return (string) get_option( 'pf_mollie_key', '' );
		}
		if ( ! preg_match( '/^(live|test)_[A-Za-z0-9]{20,}$/', $v ) ) {
			add_settings_error( 'pf_mollie_key', 'pf_key', 'Dat lijkt geen Mollie API-sleutel (begint met live_ of test_). De oude sleutel is bewaard.' );
			return (string) get_option( 'pf_mollie_key', '' );
		}
		return $v;
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	private static function action_url( string $do, int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pf_donation&do=' . $do . '&id=' . $id ), 'pf_donation_' . $id );
	}

	/* ---------- acties ---------- */

	public static function action() {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'pf_donation_' . $id ) ) {
			wp_die( 'Geen toegang.' );
		}
		global $wpdb;
		$row = PF_Donations::row( 'id', $id );
		$do  = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore
		$msg = 'ok';
		if ( $row ) {
			switch ( $do ) {
				case 'approve':
					$wpdb->update( PF_Donations::table(), [ 'msg_status' => 'approved' ], [ 'id' => $id ] );
					break;
				case 'reject':
					$wpdb->update( PF_Donations::table(), [ 'msg_status' => 'hidden' ], [ 'id' => $id ] );
					break;
				case 'hide':
				case 'show':
					$wpdb->update( PF_Donations::table(), [ 'hidden' => 'hide' === $do ? 1 : 0 ], [ 'id' => $id ] );
					break;
				case 'free':
					$wpdb->delete( PF_Donations::pixel_table(), [ 'donation_id' => $id ] );
					$wpdb->update( PF_Donations::table(), [ 'hidden' => 1 ], [ 'id' => $id ] );
					break;
				case 'sync':
					PF_Donations::sync( $row );
					break;
				case 'stop':
					$ok  = PF_Donations::stop( $row );
					$msg = is_wp_error( $ok ) ? 'stopfail' : 'stopped';
					break;
			}
		}
		wp_safe_redirect( add_query_arg( 'pf_msg', $msg, wp_get_referer() ?: self::url() ) );
		exit;
	}

	public static function grow() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'pf_wall_grow' ) ) {
			wp_die( 'Geen toegang.' );
		}
		$n = max( 1, min( 48, absint( $_POST['rows'] ?? 4 ) ) ); // phpcs:ignore
		update_option( 'pf_wall_rows', PF_Donations::rows() + $n );
		wp_safe_redirect( self::url( [ 'tab' => 'muur', 'pf_msg' => 'grown' ] ) );
		exit;
	}

	/* ---------- pagina ---------- */

	public static function render() {
		$tab  = sanitize_key( $_GET['tab'] ?? 'overzicht' ); // phpcs:ignore
		$tabs = [ 'overzicht' => 'Overzicht', 'lijst' => 'Alle donaties', 'muur' => 'Pixelmuur', 'instellingen' => 'Instellingen' ];
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overzicht';
		echo '<div class="wrap pf-don"><h1>Donaties</h1>';
		self::mode_notice();
		$notes = [
			'stopped'  => 'De maandelijkse donatie is gestopt. Het e-mailadres is gewist.',
			'stopfail' => 'Stoppen bij Mollie lukte niet. Probeer het nog eens of stop hem in het Mollie-dashboard.',
			'grown'    => 'Er zijn rijen bijgekomen bovenaan de muur.',
		];
		$m = sanitize_key( $_GET['pf_msg'] ?? '' ); // phpcs:ignore
		if ( isset( $notes[ $m ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'stopfail' === $m ? 'error' : 'success', esc_html( $notes[ $m ] ) );
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $k === $tab ? ' nav-tab-active' : '', esc_url( self::url( [ 'tab' => $k ] ) ), esc_html( $label ) );
		}
		echo '</nav>';
		self::{ 'tab_' . $tab }();
		echo '<style>.pf-don .cards{display:flex;flex-wrap:wrap;gap:16px;margin:20px 0}.pf-don .card{margin:0;min-width:170px;max-width:none}.pf-don .card b{display:block;font-size:24px;line-height:1.3}.pf-don td .row-actions{left:0}.pf-don .msg{max-width:340px}.pf-don .st-paid{color:#008a20}.pf-don .st-open{color:#996800}.pf-don .st-failed,.pf-don .st-canceled,.pf-don .st-expired{color:#8c8f94}.pf-don canvas{image-rendering:pixelated;background:#000;max-width:100%;height:auto}</style>';
		echo '</div>';
	}

	private static function mode_notice() {
		$mode = PF_Donations::mode();
		if ( 'test' === $mode ) {
			echo '<div class="notice notice-warning"><p><strong>Testmodus:</strong> er is nog geen Mollie-sleutel. Op deze lokale site kun je alles uitproberen met nepbetalingen.</p></div>';
		} elseif ( 'off' === $mode ) {
			echo '<div class="notice notice-error"><p><strong>Doneren staat uit:</strong> vul onder Instellingen een Mollie API-sleutel in.</p></div>';
		} elseif ( PF_Mollie::is_test() ) {
			echo '<div class="notice notice-info"><p><strong>Mollie-testsleutel:</strong> betalingen gaan via Mollies testomgeving. Er wordt niet echt betaald.</p></div>';
		}
	}

	private static function tab_overzicht() {
		global $wpdb;
		$t     = PF_Donations::table();
		$month = gmdate( 'Y-m-01 00:00:00' );
		$sum   = fn( string $where ) => (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM $t WHERE status = 'paid' $where" ); // phpcs:ignore
		$subs  = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM $t WHERE sub_status = 'active'" ); // phpcs:ignore
		$used  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PF_Donations::pixel_table() ); // phpcs:ignore
		$cap   = PF_Donations::PIXEL_COLS * PF_Donations::rows();
		$cards = [
			'Totaal ontvangen'   => PF_Donations::euro( $sum( '' ) ),
			'Deze maand'         => PF_Donations::euro( $sum( $wpdb->prepare( 'AND paid >= %s', $month ) ) ),
			'Maandelijks'        => sprintf( '%d × · %s', $subs->n, PF_Donations::euro( (int) $subs->s ) ),
			'Pixels op de muur'  => sprintf( '%d / %d', $used, $cap ),
		];
		echo '<div class="cards">';
		foreach ( $cards as $k => $v ) {
			printf( '<div class="card"><span>%s</span><b>%s</b></div>', esc_html( $k ), esc_html( $v ) );
		}
		echo '</div><p class="description">Bedragen zijn wat donateurs betaalden, vóór de kosten van Mollie. Voor je boekhouding: het Mollie-dashboard heeft de officiële overzichten.</p>';

		$wait = $wpdb->get_results( "SELECT * FROM $t WHERE status = 'paid' AND msg_status = 'pending' ORDER BY id DESC", ARRAY_A ); // phpcs:ignore
		echo '<h2>Boodschappen die wachten</h2>';
		if ( ! $wait ) {
			echo '<p>Niets te lezen. 👍</p>';
			return;
		}
		self::table_rows( $wait );
	}

	private static function tab_lijst() {
		global $wpdb;
		$t      = PF_Donations::table();
		$filter = sanitize_key( $_GET['soort'] ?? '' ); // phpcs:ignore
		$wheres = [
			''         => "status <> 'open' OR created > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)",
			'pixels'   => "kind = 'pixels' AND status = 'paid'",
			'maand'    => 'recurring = 1 AND parent_id = 0',
			'betaald'  => "status = 'paid'",
		];
		$where  = $wheres[ $filter ] ?? $wheres[''];
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$per    = 50;
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" ); // phpcs:ignore
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore

		echo '<ul class="subsubsub" style="float:none;margin:12px 0">';
		$labels = [ '' => 'Alles', 'betaald' => 'Betaald', 'pixels' => 'Pixels', 'maand' => 'Maandelijks' ];
		$i      = 0;
		foreach ( $labels as $k => $l ) {
			printf( '<li>%s<a href="%s"%s>%s</a></li>', $i++ ? ' | ' : '', esc_url( self::url( [ 'tab' => 'lijst', 'soort' => $k ] ) ), $k === $filter ? ' class="current"' : '', esc_html( $l ) );
		}
		echo '</ul>';
		if ( ! $rows ) {
			echo '<p>Nog geen donaties.</p>';
			return;
		}
		self::table_rows( $rows );
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p>' . paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ] ) . '</p>'; // phpcs:ignore
		}
	}

	private static function table_rows( array $rows ) {
		$status = [ 'paid' => 'Betaald', 'open' => 'Open', 'failed' => 'Mislukt', 'canceled' => 'Geannuleerd', 'expired' => 'Verlopen' ];
		echo '<table class="widefat striped"><thead><tr><th>Datum</th><th>Wat</th><th>Bedrag</th><th>Status</th><th>Naam</th><th>Boodschap</th><th>Acties</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$id   = (int) $r['id'];
			$what = 'pixels' === $r['kind'] ? sprintf( '%d pixels', $r['pixels'] ) : ( $r['recurring'] ? ( $r['parent_id'] ? 'Maandelijks (afschrijving)' : 'Maandelijks' ) : 'Donatie' );
			if ( $r['recurring'] && ! $r['parent_id'] && $r['sub_status'] ) {
				$what .= ' · ' . ( [ 'active' => 'loopt', 'canceled' => 'gestopt', 'failed' => 'niet gestart' ][ $r['sub_status'] ] ?? $r['sub_status'] );
			}
			if ( $r['cover'] ) {
				$what .= ' · incl. kosten';
			}
			if ( $r['hidden'] ) {
				$what .= ' · verborgen';
			}
			if ( $r['conflict'] ) {
				$what .= ' · <strong title="Betaald na het verlopen van de reservering; een deel van de pixels was al verkocht.">botsing</strong>';
			}
			$acts = [];
			if ( 'pending' === $r['msg_status'] || 'hidden' === $r['msg_status'] ) {
				$acts[] = sprintf( '<a href="%s">Boodschap tonen</a>', esc_url( self::action_url( 'approve', $id ) ) );
			}
			if ( 'pending' === $r['msg_status'] || 'approved' === $r['msg_status'] ) {
				$acts[] = sprintf( '<a href="%s">Boodschap weigeren</a>', esc_url( self::action_url( 'reject', $id ) ) );
			}
			if ( 'pixels' === $r['kind'] && 'paid' === $r['status'] ) {
				$acts[] = $r['hidden']
					? sprintf( '<a href="%s">Pixels weer tonen</a>', esc_url( self::action_url( 'show', $id ) ) )
					: sprintf( '<a href="%s">Pixels verbergen</a>', esc_url( self::action_url( 'hide', $id ) ) );
				$acts[] = sprintf( '<a href="%s" onclick="return confirm(\'Pixels weghalen en weer te koop zetten? Denk aan terugbetalen via Mollie.\')">Weghalen</a>', esc_url( self::action_url( 'free', $id ) ) );
			}
			if ( 'open' === $r['status'] && $r['mollie_id'] ) {
				$acts[] = sprintf( '<a href="%s">Status ophalen</a>', esc_url( self::action_url( 'sync', $id ) ) );
			}
			if ( 'active' === $r['sub_status'] ) {
				$acts[] = sprintf( '<a href="%s" onclick="return confirm(\'Deze maandelijkse donatie stoppen?\')" style="color:#b32d2e">Stop maandelijks</a>', esc_url( self::action_url( 'stop', $id ) ) );
			}
			$msg = '' === $r['message'] ? '–' : esc_html( $r['message'] ) . ( 'pending' === $r['msg_status'] ? ' <em>(wacht)</em>' : ( 'hidden' === $r['msg_status'] ? ' <em>(geweigerd)</em>' : '' ) );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td class="st-%s">%s</td><td><code>%s</code></td><td class="msg">%s</td><td>%s</td></tr>',
				esc_html( wp_date( 'j M Y H:i', strtotime( ( $r['paid'] ?: $r['created'] ) . ' UTC' ) ) ),
				wp_kses( $what, [ 'strong' => [ 'title' => true ] ] ),
				esc_html( PF_Donations::euro( (int) $r['amount'] ) ),
				esc_attr( $r['status'] ),
				esc_html( $status[ $r['status'] ] ?? $r['status'] ),
				esc_html( $r['name'] ?: '–' ),
				$msg, // phpcs:ignore -- hierboven ge-escaped
				implode( ' · ', $acts ) ?: '–' // phpcs:ignore -- hierboven ge-escaped
			);
		}
		echo '</tbody></table>';
	}

	private static function tab_muur() {
		$w = PF_Donations::wall();
		printf( '<p>De muur is %d pf-pixels breed en %d rijen hoog (%d × %d schermpixels). Nieuwe rijen komen er bovenaan bij; wat er al staat, schuift niet.</p>', $w['cols'], $w['rows'], $w['cols'] * 10, $w['rows'] * 10 );
		printf( '<canvas id="pf-wall-admin" width="%d" height="%d"></canvas>', $w['cols'] * 10, $w['rows'] * 10 );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0">
			<input type="hidden" name="action" value="pf_wall_grow">
			<?php wp_nonce_field( 'pf_wall_grow' ); ?>
			<label>Rijen erbij bovenaan: <input type="number" name="rows" value="4" min="1" max="48" style="width:70px"></label>
			<?php submit_button( 'Muur uitbreiden', 'secondary', 'submit', false ); ?>
		</form>
		<p class="description">Verborgen pixels zijn grijs. Arceringen zijn pixels waarvoor nu betaald wordt.</p>
		<script>
		( () => {
			const w = <?php echo wp_json_encode( $w ); ?>, pal = <?php echo wp_json_encode( PF_Donations::PALETTE ); ?>;
			const g = document.getElementById( 'pf-wall-admin' ).getContext( '2d' );
			for ( let i = 0; i < w.px.length; i += 4 ) {
				const [ x, y, c, d ] = w.px.slice( i, i + 4 );
				g.fillStyle = d === -2 ? '#3a3a3a' : pal[ c ];
				g.globalAlpha = d === -1 ? .4 : 1;
				g.fillRect( x * 10, ( w.rows - 1 - y ) * 10, 10, 10 );
			}
		} )();
		</script>
		<?php
	}

	private static function tab_instellingen() {
		settings_errors( 'pf_mollie_key' );
		$locked = defined( 'PIXELFART_MOLLIE_KEY' );
		$key    = PF_Mollie::key();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::SLUG ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="pf_mollie_key">Mollie API-sleutel</label></th><td>
					<input type="password" class="regular-text code" id="pf_mollie_key" name="pf_mollie_key" value="" autocomplete="off" <?php disabled( $locked ); ?>
						placeholder="<?php echo esc_attr( $key ? substr( $key, 0, 5 ) . '…' . substr( $key, -4 ) . ' (ingesteld)' : 'live_… of test_…' ); ?>">
					<p class="description">
						<?php if ( $locked ) : ?>
							Vastgezet met <code>PIXELFART_MOLLIE_KEY</code> in wp-config.php.
						<?php else : ?>
							Te vinden in het Mollie-dashboard onder Developers › API-sleutels. Leeg laten = niet wijzigen, <code>-</code> = wissen. Liever nog: zet hem als <code>PIXELFART_MOLLIE_KEY</code> in wp-config.php, dan staat hij niet in de database.
						<?php endif; ?>
						Begin met de <code>test_</code>-sleutel om alles uit te proberen.
					</p>
				</td></tr>
				<tr><th><label for="pf_donate_page">Doneerpagina</label></th><td>
					<?php wp_dropdown_pages( [ 'name' => 'pf_donate_page', 'id' => 'pf_donate_page', 'selected' => PF_Donations::page_id(), 'show_option_none' => '— kies —', 'option_none_value' => 0 ] ); ?>
					<p class="description">Hier wordt betaald. De blokken "Doneerknop" en "Doneren" elders op de site sturen bezoekers hierheen.</p>
				</td></tr>
				<tr><th>Boodschappen</th><td>
					<label><input type="checkbox" name="pf_wall_moderate" value="1" <?php checked( PF_Donations::moderate() ); ?>> Eerst zelf lezen en goedkeuren (aanbevolen: er spelen kinderen mee)</label>
				</td></tr>
				<tr><th>Muur</th><td>
					<label><input type="checkbox" name="pf_wall_autogrow" value="1" <?php checked( (bool) get_option( 'pf_wall_autogrow', 1 ) ); ?>> Vanzelf 4 rijen erbij als de muur voor 85% vol is</label>
				</td></tr>
				<tr><th>Kosten tonen</th><td>
					<label>Eenmalig <input type="number" name="pf_fee_once" value="<?php echo esc_attr( PF_Donations::fee( false ) ); ?>" min="0" max="200" style="width:70px"> cent</label>
					&nbsp; <label>Maandelijks <input type="number" name="pf_fee_month" value="<?php echo esc_attr( PF_Donations::fee( true ) ); ?>" min="0" max="200" style="width:70px"> cent</label>
					<p class="description">Wat Mollie per betaling inhoudt. Op de site staat eerlijk hoeveel er van een donatie overblijft. 0 = niet tonen.</p>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
		$hook = rest_url( PF_Rest::NS . '/mollie' );
		printf( '<p class="description">Webhook (stelt de site zelf in bij elke betaling): <code>%s</code></p>', esc_html( $hook ) );
	}
}
