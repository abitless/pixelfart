<?php
/**
 * Instellingen › Pixelfart: adres van de games, ontvanger van berichten, link naar de broncode.
 */

defined( 'ABSPATH' ) || exit;

class PF_Settings {

	const PAGE = 'pixelfart';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
	}

	public static function menu() {
		add_options_page( 'Pixelfart', 'Pixelfart', 'manage_options', self::PAGE, [ __CLASS__, 'render' ] );
	}

	public static function register() {
		register_setting( self::PAGE, 'pf_games_url', [ 'type' => 'string', 'sanitize_callback' => [ __CLASS__, 'sanitize_games_url' ], 'default' => '' ] );
		register_setting( self::PAGE, 'pf_mail_to', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => '' ] );
		register_setting( self::PAGE, 'pf_source_url', [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ] );

		add_settings_section( 'pf_main', '', '__return_false', self::PAGE );
		add_settings_field( 'pf_games_url', 'Adres van de games', [ __CLASS__, 'field_games_url' ], self::PAGE, 'pf_main', [ 'label_for' => 'pf_games_url' ] );
		add_settings_field( 'pf_mail_to', 'Berichten mailen naar', [ __CLASS__, 'field_mail_to' ], self::PAGE, 'pf_main', [ 'label_for' => 'pf_mail_to' ] );
		add_settings_field( 'pf_source_url', 'Link "Open source"', [ __CLASS__, 'field_source_url' ], self::PAGE, 'pf_main', [ 'label_for' => 'pf_source_url' ] );
	}

	public static function sanitize_games_url( $url ): string {
		$url = esc_url_raw( trim( (string) $url ), [ 'https', 'http' ] );
		return $url ? trailingslashit( $url ) : '';
	}

	public static function field_games_url() {
		$locked = defined( 'PIXELFART_GAMES_URL' );
		printf(
			'<input type="url" class="regular-text code" id="pf_games_url" name="pf_games_url" value="%s" placeholder="https://play.pixelfart.nl/" %s>',
			esc_attr( $locked ? PIXELFART_GAMES_URL : get_option( 'pf_games_url' ) ),
			$locked ? 'disabled' : ''
		);
		echo '<p class="description">';
		if ( $locked ) {
			echo 'Vastgezet met <code>PIXELFART_GAMES_URL</code> in wp-config.php. ';
		}
		printf(
			'Een eigen (sub)domein dat de map <code>%s</code> serveert. Leeg = via de uploadsmap van deze site.',
			esc_html( PF_Games::base_dir() )
		);
		echo '</p>';
		if ( ! PF_Hosting::is_separate() ) {
			echo '<p class="description" style="color:#b32d2e"><strong>Let op:</strong> de games draaien nu op hetzelfde domein als WordPress. Een game kan dan bij een ingelogde beheerder in wp-admin komen. Gebruik bij voorkeur een eigen subdomein.</p>';
		}
	}

	public static function field_mail_to() {
		printf(
			'<input type="email" class="regular-text" id="pf_mail_to" name="pf_mail_to" value="%s" placeholder="%s"><p class="description">Ideeën en contactberichten. Leeg = het beheerdersadres.</p>',
			esc_attr( get_option( 'pf_mail_to' ) ),
			esc_attr( get_option( 'admin_email' ) )
		);
	}

	public static function field_source_url() {
		printf(
			'<input type="url" class="regular-text code" id="pf_source_url" name="pf_source_url" value="%s" placeholder="https://github.com/…"><p class="description">Waar de broncode staat (link in de footer).</p>',
			esc_attr( get_option( 'pf_source_url' ) )
		);
	}

	public static function render() {
		?>
		<div class="wrap">
			<h1>Pixelfart</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
