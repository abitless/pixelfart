<?php
/**
 * Plugin Name: Pixelfart Games
 * Description: Games als losse HTML-bestanden met metadata (game.json), thumbnail, speelteller, sterren, reacties en het idee/contactformulier.
 * Version:     1.2.0
 * Author:      Bitless
 * Text Domain: pixelfart
 * Requires PHP: 8.2
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

const PIXELFART_VERSION = '1.2.0';
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
