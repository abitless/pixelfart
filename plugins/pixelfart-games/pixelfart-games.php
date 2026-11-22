<?php
/**
 * Plugin Name: Pixelfart Games
 * Description: Games als losse HTML-bestanden met metadata (game.json), thumbnail, speelteller, sterren, reacties, het idee/contactformulier, donaties en de pixelmuur.
 * Version:     1.8.1
 * Author:      Bitless
 * Text Domain: pixelfart
 * Requires PHP: 8.2
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  false
 */

defined( 'ABSPATH' ) || exit;

const PIXELFART_SOURCE_URL = 'https://github.com/abitless/pixelfart'; // standaard voor de link "Open source"
const PIXELFART_VERSION = '1.8.1';
define( 'PIXELFART_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXELFART_URL', plugin_dir_url( __FILE__ ) );

require_once PIXELFART_DIR . 'includes/class-pf-categories.php';
require_once PIXELFART_DIR . 'includes/class-pf-fonts.php';
require_once PIXELFART_DIR . 'includes/class-pf-games.php';
require_once PIXELFART_DIR . 'includes/class-pf-hosting.php';
require_once PIXELFART_DIR . 'includes/class-pf-format.php';
require_once PIXELFART_DIR . 'includes/class-pf-settings.php';
require_once PIXELFART_DIR . 'includes/class-pf-admin.php';
require_once PIXELFART_DIR . 'includes/class-pf-rest.php';
require_once PIXELFART_DIR . 'includes/class-pf-altcha.php';
require_once PIXELFART_DIR . 'includes/class-pf-scores.php';
require_once PIXELFART_DIR . 'includes/class-pf-makers.php';
require_once PIXELFART_DIR . 'includes/class-pf-messages.php';
require_once PIXELFART_DIR . 'includes/class-pf-announcements.php';
require_once PIXELFART_DIR . 'includes/class-pf-newsletter.php';
require_once PIXELFART_DIR . 'includes/class-pf-mollie.php';
require_once PIXELFART_DIR . 'includes/class-pf-donations.php';
require_once PIXELFART_DIR . 'includes/class-pf-donations-admin.php';
require_once PIXELFART_DIR . 'includes/class-pf-pages.php';
require_once PIXELFART_DIR . 'includes/class-pf-mail.php';

PF_Games::init();
PF_Hosting::init();
PF_Format::init();
PF_Settings::init();
PF_Categories::init();
PF_Admin::init();
PF_Rest::init();
PF_Altcha::init();
PF_Scores::init();
PF_Makers::init();
PF_Messages::init();
PF_Announcements::init();
PF_Newsletter::init();
PF_Donations::init();
PF_Pages::init();
PF_Mail::init();
if ( is_admin() ) {
	PF_Donations_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PIXELFART_DIR . 'includes/class-pf-cli.php';
	WP_CLI::add_command( 'pixelfart', 'PF_CLI' );
}

register_activation_hook( __FILE__, function () {
	PF_Games::register();
	PF_Categories::register();
	PF_Categories::seed_defaults();
	flush_rewrite_rules();
} );
