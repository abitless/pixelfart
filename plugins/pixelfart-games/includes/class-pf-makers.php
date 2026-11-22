<?php
/**
 * Games › Voor makers: het format in het kort en de skill (één SKILL.md) om in Claude te laden.
 */

defined( 'ABSPATH' ) || exit;

class PF_Makers {

	const DIR = 'skill/pixelfart-game/';
	const SDK = 'sdk/';

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
		$md   = wp_nonce_url( admin_url( 'admin-post.php?action=pf_skill_file' ), 'pf_skill' );
		?>
		<div class="wrap">
			<h1>Voor makers</h1>
			<p style="max-width:720px">Games voor Pixelfart volgen een vast format: één los HTML-bestand met een manifest, zonder andere bestanden. Los geopend is de game gewoon speelbaar. Op de site zet Pixelfart er zelf de SDK en de pixelfonts bij: dan past de game zich aan elk scherm aan, pauzeert hij als niemand kijkt (scheelt stroom) en werken de topscores. Bij het uploaden controleert de site het format en vult het formulier zich vanzelf.</p>

			<h2>Skill voor Claude</h2>
			<p style="max-width:720px">De skill is één tekstbestand dat Claude het format uitlegt. Daarna maakt Claude elke nieuwe game, of update van een game, meteen goed: los speelbaar, met geluid dat uit kan en drie niveaus als je tegen de computer speelt.</p>
			<ul style="list-style:disc;padding-left:20px;max-width:720px">
				<li><strong>Claude-app of claude.ai:</strong> Instellingen › Mogelijkheden › Skills › Skill uploaden, en kies de zip (daarin zit alleen <code>SKILL.md</code>).</li>
				<li><strong>Claude Code:</strong> zet <code>SKILL.md</code> in <code>~/.claude/skills/pixelfart-game/</code>.</li>
				<li><strong>Makers zonder toegang tot de site:</strong> stuur ze <code>SKILL.md</code>. Ze testen de game op hun eigen computer (bovenin staat dan een gele testmelding) en sturen jullie het HTML-bestand. Dat upload je hier.</li>
				<li><strong>Andere AI of een mens:</strong> <code>SKILL.md</code> is gewoon leesbare tekst. Plak hem in het gesprek of lees hem zelf.</li>
				<li>Vraag daarna gewoon: <em>"Maak een Pixelfart-game waarin…"</em> of <em>"Maak deze game klaar voor Pixelfart"</em>.</li>
			</ul>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $md ); ?>">Download SKILL.md</a>
				<a class="button" href="<?php echo esc_url( $zip ); ?>">Download als zip (voor claude.ai)</a>
			</p>

			<h2>Pixelfonts</h2>
			<p style="max-width:720px">Games laden nooit iets van internet, ook geen fonts. Deze fonts staan op de site zelf. Een game gebruikt ze gewoon bij naam, met een noodfont erachter (bijv. <code>8px "Press Start 2P", monospace</code>); op de site laadt de SDK ze.</p>
			<?php $furl = PIXELFART_URL . self::SDK; ?>
			<style>
				<?php echo str_replace( 'url(fonts/', 'url(' . esc_url( $furl ) . 'fonts/', wp_strip_all_tags( (string) file_get_contents( PIXELFART_DIR . self::SDK . 'pixelfart-fonts.css' ) ) ); // phpcs:ignore ?>
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
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="SKILL.md"' );
		readfile( PIXELFART_DIR . self::DIR . 'SKILL.md' );
		exit;
	}

	/** Zip met alleen pixelfart-game/SKILL.md: zo wil claude.ai een skill hebben. */
	public static function zip() {
		self::guard();
		$tmp = wp_tempnam( 'pixelfart-game.zip' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			wp_die( 'Zip maken lukte niet.' );
		}
		$zip->addFile( PIXELFART_DIR . self::DIR . 'SKILL.md', 'pixelfart-game/SKILL.md' );
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
