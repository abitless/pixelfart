<?php
/**
 * Games › Voor makers: het format in het kort en de skill (SKILL.md + SDK + voorbeeld) als zip om in Claude te laden.
 */

defined( 'ABSPATH' ) || exit;

class PF_Makers {

	const DIR = 'skill/pixelfart-game/';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_pf_skill_zip', [ __CLASS__, 'zip' ] );
		add_action( 'admin_post_pf_skill_file', [ __CLASS__, 'file' ] );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . PF_Games::CPT, 'Voor makers', 'Voor makers', 'edit_posts', 'pf-makers', [ __CLASS__, 'page' ] );
	}

	public static function page() {
		$zip  = wp_nonce_url( admin_url( 'admin-post.php?action=pf_skill_zip' ), 'pf_skill' );
		$file = fn( $f ) => wp_nonce_url( admin_url( 'admin-post.php?action=pf_skill_file&f=' . $f ), 'pf_skill' );
		?>
		<div class="wrap">
			<h1>Voor makers</h1>
			<p style="max-width:720px">Games voor Pixelfart volgen een vast format: één HTML-bestand met een manifest en de Pixelfart SDK. Dan past de game zich aan elk scherm aan, pauzeert hij als niemand kijkt (scheelt stroom) en werken de topscores. Bij het uploaden controleert de site het format en vult het formulier zich vanzelf.</p>

			<h2>Skill voor Claude</h2>
			<p style="max-width:720px">De skill legt Claude het format uit. Daarna maakt Claude elke nieuwe game, of update van een game, meteen goed.</p>
			<ul style="list-style:disc;padding-left:20px;max-width:720px">
				<li><strong>Claude-app of claude.ai:</strong> Instellingen › Mogelijkheden › Skills › Skill uploaden, en kies de zip.</li>
				<li><strong>Claude Code:</strong> pak de zip uit in <code>~/.claude/skills/</code> (dan staat er <code>~/.claude/skills/pixelfart-game/SKILL.md</code>).</li>
				<li>Vraag daarna gewoon: <em>"Maak een Pixelfart-game waarin…"</em> of <em>"Maak deze game klaar voor Pixelfart"</em>.</li>
			</ul>
			<p><a class="button button-primary" href="<?php echo esc_url( $zip ); ?>">Download skill (pixelfart-game.zip)</a></p>

			<h2>Losse bestanden</h2>
			<ul style="list-style:disc;padding-left:20px">
				<li><a href="<?php echo esc_url( $file( 'SKILL.md' ) ); ?>">SKILL.md</a>: de volledige uitleg van het format</li>
				<li><a href="<?php echo esc_url( $file( 'pixelfart.js' ) ); ?>">pixelfart.js</a>: de SDK (zet naast je game tijdens het testen)</li>
				<li><a href="<?php echo esc_url( $file( 'template.html' ) ); ?>">template.html</a>: voorbeeldgame Pixelvanger</li>
			</ul>

			<h2>Pixelfonts</h2>
			<p style="max-width:720px">Games laden nooit iets van internet, ook geen fonts. Deze fonts staan op de site zelf en zitten in de skill. Een game gebruikt ze gewoon bij naam; <code>pixelfart.js</code> regelt de rest.</p>
			<?php $furl = PIXELFART_URL . self::DIR; ?>
			<style>
				<?php echo str_replace( 'url(fonts/', 'url(' . esc_url( $furl ) . 'fonts/', wp_strip_all_tags( (string) file_get_contents( PIXELFART_DIR . self::DIR . 'pixelfart-fonts.css' ) ) ); // phpcs:ignore ?>
				.pf-fonts td { vertical-align: middle; }
				.pf-fonts .sample { background: #000; color: #f2f2f2; padding: 10px 14px; -webkit-font-smoothing: none; white-space: nowrap; }
			</style>
			<table class="widefat striped pf-fonts" style="max-width:900px">
				<tbody>
				<?php foreach ( PF_Fonts::LIBRARY as $name => $use ) : ?>
					<tr>
						<td style="width:150px"><strong><?php echo esc_html( $name ); ?></strong></td>
						<td class="sample" style="font-family:'<?php echo esc_attr( $name ); ?>';font-size:<?php echo in_array( $name, [ 'VT323', 'Pixelify Sans', 'Jersey 10' ], true ) ? 24 : 16; ?>px">PIXELFART 0123 Speel!</td>
						<td><?php echo esc_html( $use ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>Alle fonts: SIL Open Font License 1.1.</p>

			<h2>Een update uploaden</h2>
			<ol style="padding-left:20px;max-width:720px">
				<li>Open de game onder <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PF_Games::CPT ) ); ?>">Alle games</a> en kies het nieuwe HTML-bestand.</li>
				<li>Versie en "Wat is er nieuw?" komen uit het manifest. Staan ze er niet in, dan wordt de versie vanzelf opgehoogd.</li>
				<li>De vorige versie wordt bewaard (kader <em>Versies</em>) en is met één klik terug te zetten.</li>
			</ol>
			<p>Of via de commandline: <code>wp pixelfart update "Mijn game.html"</code></p>

			<h2>Licentie</h2>
			<p>Code van de games: <strong>GPL-3.0-or-later</strong>. Graphics en geluid: <strong>CC BY-SA 4.0</strong>. Thema en plugin: GPL-2.0-or-later.</p>
		</div>
		<?php
	}

	private static function guard() {
		check_admin_referer( 'pf_skill' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Geen rechten.' );
		}
	}

	public static function file() {
		self::guard();
		$f = sanitize_file_name( wp_unslash( $_GET['f'] ?? '' ) );
		if ( ! in_array( $f, [ 'SKILL.md', 'pixelfart.js', 'template.html' ], true ) ) {
			wp_die( 'Onbekend bestand.' );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $f . '"' );
		readfile( PIXELFART_DIR . self::DIR . $f );
		exit;
	}

	public static function zip() {
		self::guard();
		$tmp = wp_tempnam( 'pixelfart-game.zip' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			wp_die( 'Zip maken lukte niet.' );
		}
		foreach ( [ 'SKILL.md', 'pixelfart.js', 'pixelfart-fonts.css', 'template.html' ] as $f ) {
			$zip->addFile( PIXELFART_DIR . self::DIR . $f, 'pixelfart-game/' . $f );
		}
		foreach ( glob( PIXELFART_DIR . self::DIR . 'fonts/*.{woff2,txt}', GLOB_BRACE ) ?: [] as $f ) {
			$zip->addFile( $f, 'pixelfart-game/fonts/' . basename( $f ) );
		}
		$zip->close();
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="pixelfart-game.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp );
		@unlink( $tmp );
		exit;
	}
}
