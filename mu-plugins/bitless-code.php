<?php
/**
 * Plugin Name: Bitless Code
 * Description: Eigen code voor deze website, plus de standaarden van Bitless: alles werkt zichzelf automatisch bij.
 * Author:      Bitless
 * Author URI:  https://bitless.nl
 * Update URI:  false
 * License:     GPL-2.0-or-later
 *
 * Dit is een must-use plugin: hij laadt altijd en kan in wp-admin niet uitgezet worden.
 * Zet kleine aanpassingen voor deze website onderaan dit bestand.
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Automatische updates --------------------------------------------
 * Bijgewerkt blijven is belangrijker dan het risico dat een update iets breekt:
 * daarom werkt alles zichzelf bij. WordPress zelf (kleine én grote versies),
 * plugins, thema's en vertalingen. Lokaal staat dit uit (AUTOMATIC_UPDATER_DISABLED
 * in wp-config.php), daar werk je bij met `make update`.
 */
if ( ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	add_filter( 'allow_minor_auto_core_updates', '__return_true' );
	add_filter( 'allow_major_auto_core_updates', '__return_true' );
	add_filter( 'allow_dev_auto_core_updates', '__return_false' ); // geen bèta's en nightly's
	add_filter( 'auto_update_plugin', '__return_true' );
	add_filter( 'auto_update_theme', '__return_true' );
	add_filter( 'auto_update_translation', '__return_true' );

	/*
	 * WordPress zet automatische updates uit als er een .git-map boven de plugins staat.
	 * Bij ons is wp-content een git-repo, maar alleen met onze eigen code: plugins en thema's
	 * van anderen staan in .gitignore. Updates kunnen dus niets van ons overschrijven.
	 */
	add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
}

/* ---------- Eigen plugins en thema's nooit "bijwerken" vanaf wordpress.org --
 * WordPress zoekt updates op naam. Staat er op wordpress.org toevallig een plugin of thema
 * met dezelfde mapnaam als iets wat we zelf maakten, dan zou een (auto-)update onze code
 * vervangen door die van een ander. Zet daarom in elke eigen plugin en elk eigen thema
 * (in de header van het hoofdbestand of style.css) de regel:  Update URI: false
 * Voor plugins regelt WordPress dat zelf; thema's sturen we hieronder niet mee bij de updatecheck.
 */
add_filter( 'http_request_args', function ( $args, $url ) {
	if ( ! str_contains( (string) $url, 'api.wordpress.org/themes/update-check' ) || empty( $args['body']['themes'] ) ) {
		return $args;
	}
	$themes = json_decode( (string) $args['body']['themes'], true );
	if ( ! is_array( $themes ) || empty( $themes['themes'] ) ) {
		return $args;
	}
	foreach ( array_keys( $themes['themes'] ) as $slug ) {
		$css = get_theme_root( $slug ) . '/' . $slug . '/style.css';
		$uri = is_readable( $css ) ? get_file_data( $css, [ 'uri' => 'Update URI' ] )['uri'] : '';
		if ( '' !== $uri && ! str_contains( $uri, 'wordpress.org' ) ) {
			unset( $themes['themes'][ $slug ] );
		}
	}
	$args['body']['themes'] = wp_json_encode( $themes );
	return $args;
}, 10, 2 );

/* ---------- Eigen code voor deze website ------------------------------------ */
